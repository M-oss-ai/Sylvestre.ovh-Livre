# CLAUDE.md

Journal des dernières actions faites avec Claude Code sur ce dépôt.
Pas un guide d'architecture (l'ancien a été supprimé volontairement,
voir le commit « Delete CLAUDE.md ») — juste ce qui a changé récemment
et pourquoi, pour reprendre le fil sans tout redemander.

## Recherche de couverture — tome recherché selon le statut

**Fichier** : `js/app.js` (`chercherCouverture()`)

Avant, la recherche demandait systématiquement « tome actuel + 1 »,
même pour une série **terminée** ou **abandonnée** — qui ne sera plus
empruntée plus loin que son tome actuel. Corrigé : le tome envoyé à
l'API dépend maintenant du statut du formulaire (`f-status`) :

- `termine` / `abandon` → le **tome réel** (`tome_actuel`)
- `cours` / `envie` → **`tome_actuel + 1`** (le prochain à emprunter)

## Recherche de couverture — deux protections, et une seule était bonne

**Fichiers** : `includes/couvertures.php`, `api.php`,
`includes/config.php`, `livre.sql`, `purger.php`

Vérification faite dans la documentation de MangaDex : la limite est
d'**environ 5 requêtes par seconde et par adresse IP**, et l'escalade
en cas de dépassement est `429` → blocage IP temporaire → blocage
complet de durée non publiée.

Deux constats ont suivi :

1. **Une recherche coûte 1 + `COUVERTURE_MAX_SERIES` appels** (un pour
   `/manga`, un par série candidate pour `/cover`), soit 5 par défaut.
   Mesuré : ~1,5 s pour trois appels. Deux personnes qui cherchent en
   même temps dépassent donc la limite sans avoir rien fait d'anormal.
2. **L'ancien frein ne protégeait pas ce qu'il prétendait protéger.**
   `limiteur_echec('couverture', …)` était appelée sans clé, donc
   indexée sur `ip_client()` — l'IP du **visiteur**. Elle plafonnait
   chaque visiteur séparément et ne bornait à aucun moment le débit
   total sortant du serveur. Le commentaire affirmait l'inverse.

Il y a désormais deux dispositifs, et ils ne font pas le même travail.

### Ce qui protège le serveur : une file d'attente

`mangadex_attendre_son_tour()` espace les appels sortants de
`COUVERTURE_ESPACEMENT` millisecondes (250 par défaut, soit 4 appels/s),
tous visiteurs confondus, via un `flock()` sur un fichier témoin placé
dans `sys_get_temp_dir()`. **Rien n'est compté, personne n'est
sanctionné** : les appels attendent leur tour.

L'attente est **bornée** par `COUVERTURE_FILE_MAX` (2 s) : au-delà, la
recherche répond « réessayez dans N secondes » plutôt que de retenir un
processus PHP — denrée rare sur un mutualisé, et la seule ressource que
cette borne protège.

`mangadex_get()` lit aussi l'en-tête `X-RateLimit-Retry-After` des
réponses 429 et le transforme en délai affichable. Auparavant tout code
≠ 200 devenait un « recherche indisponible » indifférencié, et
l'utilisateur réessayait aussitôt — le chemin le plus direct vers le
blocage complet de l'hébergement.

### Ce qui encadre chaque compte : une règle annoncée

`couverture_quota()` et `couverture_consommer()` : **30 recherches par
tranche de 2 minutes**, 120 pour le forfait `illimite`. Fenêtre **fixe**
et non glissante, pour que la règle soit vérifiable de tête.

Dépasser n'est pas une faute : aucune escalade, aucun compteur de
récidive, l'attente vaut exactement le temps restant avant la tranche
suivante. C'est ce qui distingue ce quota de `tentative_ip`, qui
enregistre des **échecs** de mot de passe et double la peine à chaque
récidive — une mécanique qui n'a rien à faire sur l'usage normal d'une
fonctionnalité.

Le forfait `illimite` a un plafond lui aussi, simplement plus haut :
sans plafond du tout, une page laissée à boucler occuperait la file
toute la journée et en priverait les autres comptes.

### Ce que cela remplace

`COUVERTURE_MAX` et `COUVERTURE_BLOCAGE` n'existent plus, ainsi que
`couverture_recherche_plafonnee()`. Nouvelles clés `.env` :
`COUVERTURE_ESPACEMENT`, `COUVERTURE_FILE_MAX`, `COUVERTURE_QUOTA`,
`COUVERTURE_FENETRE`, `COUVERTURE_QUOTA_ILLIMITE`.

## Tests

**Fichiers** : `tests/cas/couvertures_titre_test.php`,
`couvertures_reglages_test.php`, `couvertures_rythme_test.php` (nouveau)

Le nouveau fichier a **son propre processus** avec une cadence
raccourcie (`COUVERTURE_ESPACEMENT=120`, `COUVERTURE_FILE_MAX=300`) :
il attend réellement, et une suite qui dort deux secondes par cas ne
serait plus lancée. Il vérifie que l'attente existe pour de bon — sans
quoi la protection serait décorative — et qu'elle reste bornée.

`couverture_consommer()` n'est pas testée : elle exige une base, comme
tout ce que liste `tests/LISEZMOI.md` sous « non couvert, faute de base
de données ». Le **barème** (`couverture_quota`), lui, est une fonction
pure et il est testé.

Suite complète : `php tests/lancer.php` → **356 tests**, tout passe.

Le changement côté JS (`js/app.js`) n'a pas de test : le projet n'a ni
Node.js ni harnais de test JS, et `tests/LISEZMOI.md` liste déjà tout
le JS comme hors périmètre.

## Cron silencieux, puis 500 opaque

**Fichiers** : `purger.php`, `includes/config.php`

Symptôme : cron réglé sur une exécution par heure, 48 h sans le moindre
rapport. Deux défauts distincts, tous deux de la famille « échouer sans
le dire ».

**1. Un refus se déclarait en réussite.** `purger.php` exige le jeton
`X-Cron-Token` dès qu'il voit le moindre contexte HTTP — et un simple
`HTTP_HOST` laissé par un enrobage CGI suffit, ce que font plusieurs
hébergeurs. Le script répondait « Not found » puis `exit('Not found')`,
qui retourne le code **0**. L'hébergeur réglé sur « envoyer uniquement
en cas d'erreur » ne voyait donc rien à signaler.

Désormais : navigateur (`REQUEST_METHOD` présent) → 404 muet inchangé ;
tâche planifiée → message sur STDERR, trace au journal, **code 1**.

**2. Un 500 ne disait pas pourquoi.** `erreur_fatale()` n'affiche jamais
le détail technique — c'est voulu pour un visiteur. Mais l'administrateur
devait alors aller lire les journaux de l'hébergeur pour diagnostiquer,
ce qui n'est pas toujours à portée de main.

Désormais, un appel porteur du bon `CRON_TOKEN` reçoit le détail
(message, fichier, ligne) en plus de la page. `cron_appelant_authentifie()`
refuse tout si le jeton attendu est **vide** — sans quoi un `.env`
incomplet exposerait les erreurs internes au premier venu.

Les trois décisions vivent dans `config.php` et non dans `purger.php`,
qui s'exécute dès qu'on l'inclut et serait intestable — et non dans
`fonctions.php`, que `purger.php` ne charge jamais (il enverrait des
en-têtes et démarrerait une session). Un premier jet les avait mises là :
la suite passait au vert pendant que `purger.php` plantait sur une
fonction indéfinie, ce qu'a révélé une exécution réelle sous `php-cgi`.

`cron_acces_test.php` couvre les trois règles.

## Titres des vignettes tranchés en deux sur téléphone

**Fichier** : `css/style.css`

`.cover-results` est une grille avec `max-height` et `overflow-y: auto`.
Par défaut une grille aligne ses lignes en `stretch`, si bien qu'elles
étaient dimensionnées **contre la hauteur du conteneur** et non contre
leur contenu : chaque vignette devenait plus courte que ce qu'elle
portait, et son `overflow: hidden` tranchait le titre en deux. Invisible
sur un écran large, où la place ne manque jamais.

Corrigé par `align-content: start` et `align-items: start`. Et sur
téléphone, `max-height: none` : 300 px n'y montrent qu'une ligne et
demie de vignettes, et deux zones de défilement imbriquées sont pénibles
au pouce — on ne garde que celle de la modale.

Reproduit et vérifié dans le navigateur à 375 px avant et après, en
chargeant la vraie feuille de style ; contrôlé aussi à 1024 px pour
s'assurer que le cadre de 300 px y reste.
## À faire au déploiement

- **Migration SQL obligatoire** : la table `recherche_couverture`
  (bloc 3 de `livre.sql`, rejouable). Sans elle, toute recherche de
  couverture tombe en erreur.
- Nouvelles clés dans le `.env` de production (valeurs par défaut
  raisonnables si elles sont absentes).
- `ASSETS_VERSION` à incrémenter dès que `js/` ou `css/` change.
