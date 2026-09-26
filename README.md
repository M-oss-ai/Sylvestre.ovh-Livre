# Ma Bibliothèque Manga

Suivi personnel de collections de mangas. Chaque compte a sa propre
bibliothèque : tome en cours, statut de lecture, et la couverture du
tome qui l'intéresse — le prochain à emprunter pour une série en cours,
le tome atteint pour une série terminée (voir « Recherche de
couverture »).

PHP 8.1 minimum (8.2 recommandé) + MySQL/MariaDB. Aucune dépendance :
pas de Composer, pas de framework.

> **L'envoi d'e-mails est obligatoire.** L'adresse doit être confirmée
> avant la première connexion : sans SMTP fonctionnel, personne ne peut
> créer de compte. Testez-le avant d'ouvrir le site.

---

## Installation en local

1. Démarrez **Apache** et **MySQL**.
2. Activez **GD** dans `php.ini` (ligne `;extension=gd`, retirez le `;`)
   puis redémarrez Apache. Sans GD, les images sont stockées à leur
   taille d'origine : l'application marche, mais elle est bien plus lourde.
3. phpMyAdmin → onglet **SQL** → collez `livre.sql` → **Exécuter**.
4. Copiez `.env.example` en `.env` et adaptez les valeurs.
5. Ouvrez <http://localhost/Livre/> et créez votre compte.

`livre.sql` peut être rejoué sur une base existante : tout est en
`CREATE TABLE IF NOT EXISTS`. Sauvegardez d'abord.

---

## Mise en ligne chez OVH

| # | À faire | Pourquoi |
|---|---|---|
| 1 | `.ovhconfig` : `app.engine.version=8.2`, `environment=production` | Fixe la version de PHP |
| 2 | Placez `.env` **au-dessus de `www/`** | Hors de portée du web même si un `.htaccess` est ignoré. `config.php` le cherche là en premier |
| 3 | Renseignez **`APP_URL`** (`https://votredomaine.fr`, sans `/` final) | Les liens de confirmation en dépendent |
| 4 | Changez **tous** les mots de passe (base, SMTP) | Ceux du développement ne doivent jamais servir |
| 5 | Générez **`CRON_TOKEN`** et **`UPLOAD_SECRET`** (chaînes aléatoires) | Voir `.env.example` |
| 6 | Remplissez les **`LEGAL_*`** | Mentions légales obligatoires (LCEN) |
| 7 | Incrémentez **`ASSETS_VERSION`** | Sans ça, une correction ne parvient pas aux visiteurs qui ont le site en cache |
| 8 | Programmez la **purge quotidienne** (voir ci-dessous) | Trois tables grossissent sinon indéfiniment |

### Purge quotidienne

Manager OVH → Hébergements → Tâches planifiées, une fois par jour :

```bash
php purger.php
```

En HTTP, le jeton passe par un en-tête (jamais dans l'URL, qui atterrit
dans les journaux d'accès) :

```bash
curl -H "X-Cron-Token: VOTRE_CRON_TOKEN" https://votredomaine.fr/purger.php
```

Elle supprime les jetons expirés, les compteurs de tentatives périmés,
les images orphelines, et rejoue les e-mails qui n'étaient pas partis.

Elle envoie un **rapport d'activité** à `ADMIN_EMAIL` : nombre de
comptes et de séries, nouveautés de la période, tentatives de connexion
échouées, e-mails bloqués, taille de la base et des images, et le
détail du ménage effectué.

**Le cron reste quotidien, le rapport peut s'espacer.** Deux réglages
du `.env`, en heures :

| Variable | Rôle | Exemple |
|---|---|---|
| `CRON_HEURES` | L'espacement réglé chez l'hébergeur | `24` (défaut) |
| `RAPPORT_HEURES` | Tous les combien d'heures le rapport part. `0` = à chaque passage (défaut) | `168` = chaque semaine |

Espacer le cron lui-même serait une fausse bonne idée : c'est aussi lui
qui rejoue les e-mails bloqués (confirmation d'inscription, mot de passe
oublié). Avec un passage hebdomadaire, un utilisateur pourrait attendre
son lien jusqu'à une semaine, et il aurait peut-être expiré entre-temps.

Le serveur retient la date du dernier rapport envoyé (table
`rapport_cron`). Le suivant part au premier passage où `RAPPORT_HEURES`
sont écoulées, avec une marge d'une demi-période de cron. Un cron que
l'hébergeur lance « dans l'heure », tantôt à 3 h 02, tantôt à 3 h 58, ne
saute ni ne double donc aucun rapport. C'est à ça que sert
`CRON_HEURES`, et c'est pourquoi `RAPPORT_HEURES` ne peut pas lui être
inférieur.

Le rapport couvre le temps **réellement écoulé** depuis le précédent, et
l'affiche ainsi :

| Écoulé | Affiché |
|---|---|
| 1 à 47 heures | `depuis 1 heure`, `depuis 47 heures` |
| 48 heures et plus | `depuis 2 jours`, `depuis 7 j et 5 heures` |

**Une anomalie n'attend pas l'échéance** (e-mail perdu, file d'envoi
bloquée) : le rapport part tout de suite, avec `[ANOMALIE]` dans le
sujet, sans décaler le suivant. Un rapport dû mais qui n'a pas pu partir
(SMTP en panne) est retenté au passage suivant.

Plafond de 720 heures (30 jours) : la purge efface au-delà les compteurs
de tentatives de connexion, et la rubrique sécurité ne couvrirait plus
la période annoncée.

Ce rapport part en envoi **direct**, sans passer par la file de
rattrapage : un rapport est périssable, le suivant arrive au passage
suivant. L'empiler ferait grossir la file d'un message par exécution
le jour où le SMTP tombe, en noyant les e-mails d'utilisateurs
qu'elle doit justement rejouer.

Il est aussi écrit sur la sortie standard. **Réglez l'envoi du journal
OVH sur « uniquement en cas d'erreur »** : vous disposez ainsi d'un
second canal, indépendant du SMTP de l'application, qui vous atteint
même le jour où celui-ci est en panne — c'est-à-dire précisément
quand le rapport ne peut pas vous parvenir autrement.

Le script se termine avec un code de retour non nul, et écrit sur la
sortie d'erreur, uniquement quand quelque chose mérite votre attention :

- des e-mails ont été définitivement abandonnés après plusieurs essais ;
- la file n'est toujours pas vide après le passage, ce qui signifie que
  le serveur SMTP refuse — donc plus aucune inscription ni
  réinitialisation n'aboutit ;
- le script n'a pas pu s'exécuter du tout, typiquement parce que la base
  de données est injoignable. Il se terminait auparavant en **succès**
  dans ce cas, après avoir écrit une page d'erreur HTML dans le journal
  de la tâche planifiée : une panne totale de base de données était donc
  précisément le seul incident dont le réglage « uniquement en cas
  d'erreur » ne vous prévenait jamais.

Le silence devient alors une information : tout va bien. Sans ce code de
retour, le réglage « uniquement en cas d'erreur » ne produirait jamais
aucun message, et une file bloquée pourrait grossir des semaines sans
que personne ne le sache.

Le silence ne dit en revanche pas si la tâche s'est bien exécutée : pour
ça, le manager OVH affiche la date du dernier passage de chaque tâche
planifiée.

### Vérifications une fois en ligne

```bash
curl -I https://votredomaine.fr/livre.sql        # doit répondre 404
curl -I https://votredomaine.fr/.env             # doit répondre 403 ou 404
curl -sI https://votredomaine.fr/js/app.js | grep -i 'content-encoding\|cache-control'
```

La dernière doit montrer `gzip` et `max-age=31536000`. Sinon, Apache sert
vos `.js` sous un type que le `.htaccess` ne couvre pas.

Vérifiez aussi que **GD et WebP** sont actifs : sans eux, les images sont
stockées telles quelles et le gain de poids disparaît.

---

## Configuration

Tout se règle dans `.env`, documenté dans `.env.example`. Les plus
importantes :

| Variable | Rôle |
|---|---|
| `APP_URL` | Adresse publique. Sert à fabriquer les liens des e-mails |
| `DB_*` · `SMTP_*` | Base de données et compte d'envoi |
| `CRON_TOKEN` | Jeton exigé par `purger.php` en HTTP |
| `CRON_HEURES` · `RAPPORT_HEURES` | Espacement du cron réglé chez l'hébergeur, et tous les combien d'heures part son rapport (24 et 168 : cron quotidien, rapport hebdomadaire) |
| `UPLOAD_SECRET` | Sel des noms de fichiers envoyés |
| `MAX_UTILISATEURS` · `MAX_SERIES_PAR_UTILISATEUR` | Quotas |
| `MAX_APPAREILS` | Appareils mémorisés par compte illimité |
| `ASSETS_VERSION` | Version des URL `css/` et `js/`. À incrémenter au déploiement |
| `COUVERTURE_CANDIDATS` · `COUVERTURE_MAX_SERIES` | Séries examinées / proposées à la recherche de couverture |
| `COUVERTURE_QUOTA` · `COUVERTURE_FENETRE` | Recherches autorisées par compte et par tranche |
| `COUVERTURE_ESPACEMENT` · `COUVERTURE_FILE_MAX` | Cadence des appels sortants et attente tolérée |
| `COUVERTURE_CONTENU_ADULTE` | Autorise les séries classées « erotica ». Bloqué par défaut |
| `LEGAL_*` | Mentions légales |

---

## Favoris et filtres

L'étoile de chaque carte pose ou retire un favori. Elle vit dans la
rangée d'actions et non sur la couverture : sur téléphone la carte
passe à l'horizontale et sa vignette ne fait plus que 70 px, où
l'étoile se cognait à la pastille de statut.

Les filtres **se cumulent** et **survivent au rechargement** :

| Filtre | Valeurs |
|---|---|
| Statut | En cours · Envie · Terminée · Abandonnée |
| Favoris | oui / non |
| Type d'image | Pas d'image · MangaDex · Importée · Lien |

Cocher deux statuts montre les deux ; les familles se croisent
(« terminées **et** sans image »). « Toutes » n'est pas un filtre de
plus, c'est la remise à zéro des statuts.

Le type d'image est classé côté serveur par `type_image()` et voyage
dans un attribut `data-image` : le navigateur n'a pas à redécouvrir
ce qu'une URL veut dire, et la règle ne peut pas diverger entre les
deux. Ce panneau est replié par défaut — c'est un filtre qu'on sort
pour faire le ménage, pas un réglage du quotidien ; une pastille sur
le bouton signale qu'il est actif alors qu'il est replié.

L'état est gardé dans le `localStorage` du navigateur, sous
`livre.filtres`. Il peut être indisponible — navigation privée,
stockage bloqué — et la page s'affiche alors sans filtre, ce qui est
le bon défaut.

### Une série qu'on modifie ne disparaît pas sous vos yeux

Retirer une série des favoris pendant que le filtre « Favoris » est
actif, ou changer son image pendant que le filtre « Image » exclut la
nouvelle — sans précaution, la carte se serait effacée au moment même
où on vient de la toucher.

Une série créée ou modifiée reçoit donc une **exception**, par
catégorie de filtre (statut, favoris, image — jamais la recherche) :
elle reste visible même si elle ne correspond plus, jusqu'à ce qu'on
retouche cette catégorie précise. « Retouche » veut dire n'importe quel
clic dans la catégorie, pas forcément le bouton exact qui l'exclut —
cliquer sur « Abandonnée » revérifie aussi une exception née d'un
passage à « En cours ». C'est la règle la plus simple des deux
possibles : l'autre (ne réagir qu'à la valeur exacte) demanderait de
suivre, par carte, ce qu'elle portait au moment de l'exception, pour un
résultat plus difficile à deviner.

Une série tout juste **créée** reçoit la même exception sur les trois
catégories : elle apparaît toujours, quels que soient les filtres actifs.

---

## Recherche de couverture

Le bouton **Recherche auto** de la fiche d'une série interroge
[MangaDex](https://api.mangadex.org) et propose la couverture **d'un
tome précis**, pas celle de la série.

L'appel part du serveur et non du navigateur : MangaDex n'envoie pas
d'en-tête `Access-Control-Allow-Origin`, un appel direct serait refusé.
Le détour a deux avantages — la Content-Security-Policy reste en
`connect-src 'self'`, et l'adresse IP du visiteur n'est pas communiquée
à un tiers. Les endpoints de lecture ne demandent **ni compte ni clé**.

### La série est liée, puis se suit toute seule

Choisir une couverture ne fait pas que poser une image : la série
MangaDex correspondante est **enregistrée** (`serie.mangadex_id`). Dès
lors, avancer ou reculer d'un tome va chercher la couverture du nouveau
tome — **une seule requête**, sans rien rechercher ni redeviner.

C'est ce qui change tout : la recherche redevient un geste ponctuel, au
lieu d'être à refaire à chaque tome.

Le lien n'est pas transmis par le formulaire, il se **déduit de l'URL de
l'image** (`mangadex_id_depuis_url()`). Conséquence directe, et voulue :

| Vous choisissez | Le lien |
|---|---|
| une couverture proposée par la recherche | **enregistré** |
| un fichier depuis votre appareil | **coupé** |
| une URL d'un autre site | **coupé** |
| « Retirer l'image » | **coupé** |
| **🔓 Délier de MangaDex** | **coupé**, image conservée |

Les deux ne peuvent donc pas se contredire, et votre image n'est jamais
écrasée par une mise à jour automatique.

Le bouton **🔓 Délier de MangaDex** couvre le cas qui manquait : garder
l'image affichée sans qu'elle continue à suivre les tomes — une édition
particulière qu'on a choisie à la main, par exemple. Il rapatrie la
couverture en local (même traitement que n'importe quelle image
envoyée : redimension, ré-encodage WebP), ce qui lui fait perdre son URL
MangaDex — et le lien disparaît alors de lui-même, par la même règle que
tout le reste de cette section. Il n'apparaît, comme son voisin, que sur
une série déjà liée.

Le rafraîchissement automatique **n'accepte que la couverture du tome
exact**. Si ce tome n'en a pas — fréquent au-delà des premiers — l'image
en place est conservée plutôt que remplacée par une vignette trompeuse.
Le bouton **🖼️ Image MangaDex**, lui, accepte n'importe quelle
couverture de la série : il n'apparaît que sur une série liée, et sert
précisément à cela.

### Quel tome est cherché

Le tome dépend du **statut** de la série : une série terminée ne sera
plus empruntée plus loin que le tome qu'on en a.

| Statut | Tome cherché | Pourquoi |
|---|---|---|
| **En cours** · **Envie** | `tome_actuel + 1` | le prochain à emprunter |
| **Terminée** · **Abandonnée** | `tome_actuel` | le tome réel, celui qu'on possède |

Le tome cherché ne descend jamais sous 1 : une série terminée dont le
tome vaut 0 fait chercher le tome 1, faute de tome 0 chez qui que ce
soit.

Le statut lu est celui du **formulaire ouvert**, pas celui enregistré :
changer le statut puis lancer la recherche cherche bien le tome
correspondant au nouveau statut.

La même règle s'applique au rafraîchissement automatique d'une série
liée — à ceci près qu'il part, lui, de ce qui est en base
(`couverture_tome_vise()`).

### Quelle série est proposée

MangaDex classe par sa propre pertinence, qui place volontiers les
dérivés avant l'original : chercher « Shingeki no Kyojin » renvoyait
130 résultats dont les 25 premiers étaient tous des doujinshi, et la
vraie série n'était même pas candidate. Trois règles corrigent cela.

1. **Les doujinshi et les oneshots sont écartés.** Ce sont des
   publications amateur ou des récits isolés : ils portent le titre de
   la série dont ils s'inspirent, et n'ont pas de tomes à emprunter.
2. **`COUVERTURE_CANDIDATS` séries sont examinées** (25 par défaut) pour
   n'en retenir que `COUVERTURE_MAX_SERIES` (4) après classement.
   Élargir ce premier appel ne coûte **aucune requête supplémentaire** :
   c'est le même appel avec une limite plus haute. Seuls les appels de
   couvertures qui suivent se paient à l'unité.
3. **Le titre tapé est comparé à toutes les écritures connues** de
   chaque série — titre principal, traductions, titres alternatifs — et
   non au seul titre affiché. MangaDex affiche « Attack on Titan » là où
   l'on a tapé « Shingeki no Kyojin ».

L'écart de titre, terme dominant du classement :

| Écart | Condition |
|---|---|
| **0** | un des titres de la série **est** exactement ce qui a été tapé |
| **4** | un des titres contient la recherche, ou l'inverse — quatre caractères minimum, sans quoi un titre alternatif de trois lettres rapprocherait n'importe quoi |
| **10** | aucun rapport |

La comparaison ignore la casse, les accents, les ligatures et la
ponctuation : « Detective Conan » retrouve « Détective Conan ».

S'ajoutent **+3** quand le tome demandé n'a pas été trouvé, et un
dixième de point par rang pour conserver l'ordre de MangaDex entre
ex æquo.

### Ce que montre le résultat

Chaque vignette porte le nom de la série et, en dessous :

- **« Tome N »** — c'est bien la couverture du tome demandé ;
- **« Série »** — le tome n'existe pas chez MangaDex, la couverture de
  la série est proposée en repli. La vignette est alors grisée et
  bordée, pour qu'on sache ce qu'on choisit avant de cliquer.

Entre plusieurs couvertures du même tome, la langue est préférée dans
l'ordre français, anglais, japonais.

### Ce qui borne les appels

MangaDex tolère environ **5 requêtes par seconde et par adresse IP** —
celle de l'hébergement, partagée par tous les visiteurs. Une recherche
coûte 1 + `COUVERTURE_MAX_SERIES` appels, si bien que deux personnes
simultanées suffisent à dépasser la limite. Deux dispositifs, qui ne
font pas le même travail :

- **Une file d'attente** espace les appels sortants de
  `COUVERTURE_ESPACEMENT` millisecondes, tous visiteurs confondus. Rien
  n'est compté au nom de personne. L'attente est bornée par
  `COUVERTURE_FILE_MAX` : au-delà, la recherche répond « réessayez dans
  N secondes » plutôt que de retenir un processus PHP.
- **Un quota par compte**, annoncé et sans escalade :
  `COUVERTURE_QUOTA` recherches par tranche de `COUVERTURE_FENETRE`
  secondes (davantage pour le forfait illimité). Dépasser n'entraîne
  aucune sanction — seulement l'attente de la tranche suivante, et une
  recherche qui n'est pas partie est rendue.

Le rafraîchissement d'une série liée **ne consomme pas ce quota**. Un
quota répartit une ressource coûteuse ; il s'agit ici d'un appel
unique, souvent déclenché en avançant simplement d'un tome. Le faire
payer au tarif d'une recherche bloquerait la recherche manuelle de
quelqu'un qui ne fait que rattraper sa série. La file d'attente, elle,
s'applique : c'est elle qui protège l'adresse du serveur.

### Contenu sensible

`COUVERTURE_CONTENU_ADULTE` est à `0` par défaut : seules les séries
classées `safe` et `suggestive` par MangaDex sont proposées. Conséquence
assumée — certaines séries deviennent introuvables, Berserk par exemple,
que MangaDex classe `erotica` pour de la nudité et non pour sa violence.

Même à `1`, rien ne change tant que l'utilisateur n'a pas lui-même
déclaré sa majorité **et** levé le filtre depuis ses paramètres. Le
classement `pornographic` n'est jamais proposé, quelle que soit la
configuration.
---

## Les fichiers

| Fichier | Rôle |
|---|---|
| `livre.sql` | Schéma complet, rejouable (migration 4 : `serie.mangadex_id`) |
| `.env` · `.env.example` | Configuration / exemple commenté |
| `.user.ini` | Réglages PHP (erreurs, sessions, envois) |
| `includes/config.php` | `.env`, constantes, connexion PDO, erreurs |
| `includes/fonctions.php` | En-têtes, session, `e()`, CSRF, limiteur, comptes |
| `includes/images.php` | Validation, redimension, ré-encodage |
| `includes/mailer.php` | SMTP, file de rattrapage, jetons |
| `includes/carte.php` | Gabarit d'une carte — **le seul endroit** où ce HTML est écrit |
| `index.php` | La bibliothèque : grille, recherche, filtres, modales |
| `api.php` | Actions AJAX + export JSON |
| `inscription.php` · `connexion.php` · `deconnexion.php` | Comptes |
| `mot-de-passe-oublie.php` · `reinitialiser-mot-de-passe.php` · `verifier-email.php` | Récupération et confirmation |
| `parametres.php` | Profil · Sécurité · Forfait · Données |
| `mentions-legales.php` | Mentions légales et confidentialité |
| `purger.php` | Entretien de la base, lancé par le cron |
| `js/*.js` · `css/style.css` | Navigateur |
| `uploads/` | Images envoyées (exécution de code interdite) |
| `tests/` | Tests unitaires — voir `tests/LISEZMOI.md` |

---

## Tests

```bash
php tests/lancer.php
```

Sans dépendance, comme le reste : le lanceur tient en deux fichiers. Le
script se termine avec un code de retour non nul en cas d'échec.

Pour n'exécuter qu'une partie des fichiers, passez un fragment de leur
nom — `php tests/lancer.php mot_de_passe`.

**MySQL doit tourner**, même si aucun de ces tests n'interroge la base :
`includes/config.php` ouvre une connexion PDO dès son inclusion, et il
n'y a pas moyen de charger les fonctions du projet sans elle. La
connexion est ensuite laissée de côté — les tests pointent sur
`information_schema`, jamais sur la base du site, et l'envoi d'e-mails
est coupé pour la durée des tests.

Le périmètre est celui des **fonctions pures** : politique de mot de
passe, échappement HTML, filtrage des URL d'images, traitement des
images envoyées (y compris la rotation EXIF et le refus des bombes de
décompression), identification du client derrière un répartiteur,
journal de sécurité, jeton CSRF, gabarit des cartes, et les planchers de
toutes les constantes du `.env`.

Ce qui demande la base de données — limiteur anti force brute, jetons,
sessions persistantes, file d'e-mails, cloisonnement par compte — n'est
pas couvert. `tests/LISEZMOI.md` en donne la liste exacte.

---

## Comment c'est protégé

**Injections HTML / XSS** — tout ce qui sort vers le HTML passe par `e()`
(`htmlspecialchars`). Côté navigateur, rien n'est injecté en `innerHTML`
sauf le HTML produit par `carte.php`, déjà échappé. Une **CSP** interdit
tout script ou style inline. Les URL d'images sont filtrées : seuls
`https://` et `uploads/…` passent.

**Injections SQL** — requêtes préparées PDO partout, avec
`EMULATE_PREPARES = false`.

**Mots de passe** — `password_hash`/`password_verify` (bcrypt). 8
caractères minimum avec majuscule, minuscule, chiffre et caractère
spécial, sans contenir l'identifiant. Les espaces ne comptent ni dans la
longueur ni comme caractère spécial.

**Force brute** — deux compteurs en base, sur une fenêtre glissante de
15 minutes (`LIMITEUR_FENETRE`), dont la durée de blocage double à chaque
récidive (1 min → 1 h). La fenêtre de comptage est volontairement
découplée de la durée de blocage : tant qu'elles étaient confondues, il
suffisait d'espacer ses tentatives d'une minute pour n'être jamais
bloqué. Deux compteurs :

- **par IP**, pour l'attaquant unique qui essaie beaucoup ;
- **par compte**, pour l'attaque distribuée qu'un compteur par IP ne voit
  pas (une tentative par machine, mille machines).

En IPv6, le compteur porte sur le **/64** : un bloc de 18 milliards de
milliards d'adresses ne doit pas valoir autant de visiteurs distincts.

Le temps de réponse est identique qu'un compte existe ou non : sur un
identifiant inconnu, on brûle quand même un tour de bcrypt.

**Sessions** — `session_regenerate_id` à la connexion, `use_strict_mode`
contre la fixation, cookie `HttpOnly` + `SameSite=Lax` + `Secure` (y
compris derrière le répartiteur OVH, via `X-Forwarded-Proto`). Changer de
mot de passe fait tomber toutes les autres sessions (`session_version`).

**CSRF** — un jeton de session est exigé sur chaque POST (403 sinon), y
compris la déconnexion. Aucune requête GET ne modifie de données : même
les liens reçus par e-mail affichent un bouton de confirmation, qui seul
valide — les antivirus de messagerie visitent les URL avant leur
destinataire et consommaient le jeton à sa place.

**Actions sensibles** — changer d'identifiant ou d'adresse e-mail, vider
la bibliothèque ou supprimer le compte exigent le mot de passe : une
session volée ne suffit pas. Cette vérification est elle aussi limitée
(5 essais), sans quoi elle offrirait à la fois un moyen de deviner le mot
de passe et un moyen de saturer le processeur (`password_verify` est
volontairement lent).

Une nouvelle adresse n'est appliquée qu'après confirmation par lien ;
l'ancienne reste active jusque-là, et **elle est prévenue** — comme elle
l'est de tout changement de mot de passe et de la suppression du compte.
C'est le seul signal qu'a le titulaire légitime quand quelqu'un d'autre a
son mot de passe.

**Confidentialité des adresses** — l'inscription répond la même chose que
l'adresse soit déjà enregistrée ou non, et ne connecte jamais
directement. Si l'adresse est prise, son propriétaire en est informé par
e-mail et rien n'est créé. L'identifiant, lui, fait l'objet d'un message
explicite : il faut bien pouvoir en choisir un autre, et il ne révèle
aucune adresse.

**Envois d'e-mails** — **aucun plafond applicatif.** Le seul frein est le
limiteur par IP des pages qui déclenchent un envoi (inscription, mot de
passe oublié, renvoi de confirmation) : une attaque distribuée le
contourne par construction. La limite d'envoi journalière de la boîte OVH
est donc la seule borne réelle, et une fois atteinte plus rien ne part —
ni inscription, ni réinitialisation, ni avis de sécurité. Surveillez le
journal d'erreurs de l'hébergement. Un envoi raté part en file et le cron
le rejoue.

**Liens des e-mails** — l'adresse vient de `APP_URL`, **jamais** de
l'en-tête `Host`, qui est choisi par le client. Sinon, un attaquant peut
demander une réinitialisation pour un tiers en falsifiant `Host` : la
victime reçoit un e-mail authentique dont le lien pointe chez lui.

**Jetons** — confirmation, réinitialisation et connexion par appareil ne
sont jamais stockés en clair : seule leur empreinte sha256 va en base. Un
compte illimité mémorise au plus `MAX_APPAREILS` appareils ; au-delà, le
plus ancien est révoqué.

**Envois de fichiers** — le type réel est lu dans le contenu
(`getimagesize`), pas dans le nom : un script PHP renommé en `.jpg` est
refusé. 3 Mo et 40 mégapixels maximum (une image minuscule peut décrire
20 000 × 20 000 px et saturer la mémoire). Les images sont ré-encodées en
WebP 600 px, ce qui supprime au passage les métadonnées EXIF — donc la
position GPS d'une photo prise au téléphone. Le nom de fichier est une
empreinte **salée** du contenu, et l'exécution est coupée dans `uploads/`.

**Cloisonnement** — chaque requête filtre sur `utilisateur_id` : un compte
qui demande la série d'un autre reçoit un 404.

**Quotas** — appliqués par la base, dans l'insertion elle-même. Un
`COUNT` suivi d'un `INSERT` se fait doubler par deux requêtes simultanées.

**Journal de sécurité** — connexions, changements et réinitialisations de
mot de passe, demandes de changement d'adresse, vidage, suppression :
chaque évènement part dans le journal de l'hébergeur, préfixé
`[securite]`, avec l'IP et le compte — jamais le mot de passe ni le jeton.
Chez OVH : Hébergements → Statistiques et logs.

---

## Performance

- Images ré-encodées à l'envoi : environ **12× plus légères**
  (343 Ko → 28 Ko pour une couverture de 1500 px).
- Compression et cache d'un an sur `css/`, `js/` et `uploads/`, avec
  `?v=ASSETS_VERSION` pour l'invalidation. Les types `text/javascript`
  **et** `application/javascript` sont déclarés : Apache 2.4 récent sert
  les `.js` sous le premier, et ne déclarer que le second laissait le
  JavaScript non compressé et mis en cache une heure au lieu d'un an.
- `ASSETS_VERSION` renseigné évite un `filemtime()` par ressource et par
  page — l'espace des mutualisés OVH est monté en NFS, où chaque accès au
  disque est un aller-retour réseau. Même raison pour le `.env`, lu à un
  seul emplacement par requête.
- Pages HTML en `private, no-cache` plutôt que `no-store` : le cache de
  navigation arrière reste actif, et un « Précédent » ne relance pas tout
  le PHP.
- `content-visibility` sur les cartes : le navigateur ignore celles qui
  sont hors écran. Le fondu d'apparition est réservé aux cartes que le JS
  vient d'insérer, sinon il se rejoue à chaque passage devant l'écran.
- Recherche débouncée, chaînes normalisées mises en cache, distance de
  Levenshtein court-circuitée quand les longueurs sont trop éloignées.
- Index couvrant `(utilisateur_id, maj_le)` pour l'affichage, et index sur
  `couverture` / `photo` pour le nettoyage des images.
- L'envoi SMTP est borné à 5 s. Au-delà, le message part en file : un
  serveur de messagerie lent ne doit pas immobiliser les quelques
  processus PHP d'un hébergement mutualisé.

---

## Limites connues

- **Pas de pagination.** La bibliothèque est rendue entièrement côté
  serveur, parce que la recherche est entièrement côté navigateur. C'est
  sans conséquence jusqu'à quelques centaines de séries ; au-delà, il
  faudrait déplacer la recherche côté serveur.
- **Pas d'écran de gestion des appareils.** Le nombre de jetons est
  plafonné, mais on ne peut pas révoquer un appareil précis — seulement
  tous, en changeant de mot de passe.
- **Passage au forfait illimité manuel**, en base :
  `UPDATE utilisateur SET forfait = 'illimite' WHERE identifiant = '...';`
