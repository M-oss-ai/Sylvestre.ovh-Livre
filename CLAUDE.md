# CLAUDE.md

Guide de travail pour Claude Code sur ce dépôt. Ce qui suit est ce qu'on
ne devine pas en lisant un seul fichier : les commandes, la structure,
et surtout les règles tacites — celles dont la violation ne casse rien
tout de suite.

## Le projet

« Ma Bibliothèque Manga » : suivi de collection (séries, tome en cours,
statut, couverture). Interface en français, hébergée chez OVH mutualisé
sur `livre.sylvestre.ovh`.

PHP 8.1 minimum (8.2 en production), MySQL/MariaDB. **Aucune
dépendance** : pas de Composer, pas de framework, pas de PHPUnit, pas
d'étape de compilation. Ce n'est pas un accident, c'est le parti pris du
projet — un hébergement mutualisé n'offre ni terminal ni gestionnaire de
paquets, et le code doit rester déployable par simple copie de fichiers.

## Commandes

```bash
php tests/lancer.php              # toute la suite (409 tests, 34 fichiers de cas)
php tests/lancer.php mot_de_passe # les fichiers dont le nom contient ce motif
php tests/cas/carte_test.php      # un seul fichier, pratique pour déboguer
php -l fichier.php                # lint (il n'y a pas d'autre vérificateur)
php purger.php                    # la tâche planifiée, à la main
```

Le lanceur sort avec le code **0** si tout passe, **1** sinon.

**MySQL doit tourner**, même si aucun test n'interroge la base :
`includes/config.php` ouvre une connexion PDO dès son inclusion, et une
connexion ratée appelle `erreur_fatale()`, qui coupe le processus.

Sous Windows sans `php` dans le `PATH` : `C:\xampp\php\php.exe`.

## Structure

### Le noyau

| Fichier | Rôle |
|---|---|
| `includes/config.php` | **Le seul endroit** où le `.env` devient des constantes. Ouvre aussi la connexion PDO (`$pdo` global), et porte `erreur_fatale()`, `ip_client()`, `taille_lisible()` et les règles d'accès du cron |
| `includes/fonctions.php` | Bibliothèque partagée **des pages** : CSRF, sessions, limiteur, `STATUTS`, `actif()`. Envoie les en-têtes de sécurité et démarre la session **dès l'inclusion** |
| `includes/mailer.php` | Envoi SMTP direct + file de rattrapage (`mail_file`) |
| `includes/images.php` | Chaîne GD : type déduit du contenu, ré-encodage WebP, nom = empreinte salée |
| `includes/carte.php` | Le HTML d'une carte de série |
| `includes/couvertures.php` | Client MangaDex. **Inclus par `api.php` seul** — un test qui s'en sert doit le demander explicitement |

### Les points d'entrée

`index.php` (la bibliothèque), `connexion.php`, `inscription.php`,
`parametres.php`, `mot-de-passe-oublie.php`,
`reinitialiser-mot-de-passe.php`, `verifier-email.php`,
`deconnexion.php`, `mentions-legales.php`.

`api.php` est le **point d'entrée AJAX unique** : un `switch` sur
`$_POST['action']`. Chaque branche vérifie le CSRF et cloisonne par
`utilisateur_id`.

`purger.php` est la tâche planifiée : ménage des tables, rattrapage des
e-mails, et rapport d'activité envoyé à `ADMIN_EMAIL`.

### Le client

`js/commun.js` (socle partagé), `js/app.js` (bibliothèque),
`js/auth.js`, `js/settings.js`, `js/delai.js` (comptes à rebours des
attentes). `css/style.css` pour tout le style.

## Règles tacites

**Tout réglage passe par le `.env`, jamais en dur ailleurs.** Et chaque
constante de `config.php` est bornée par un plancher ou un plafond : un
`.env` mal rempli ne doit jamais pouvoir *supprimer* une protection.
`tests/cas/config_planchers_test.php` et `couvertures_reglages_test.php`
imposent des valeurs absurdes et vérifient qu'elles sont relevées.

**La CSP interdit le JavaScript et le CSS en ligne.** Pas de `onclick=`,
pas de `<style>`, pas de `style="…"` posé depuis PHP. Les données
destinées au JS passent par des attributs `data-` sur `<body>`.

**Les quotas s'appliquent dans l'`INSERT` lui-même**
(`INSERT … SELECT … FROM DUAL WHERE (SELECT COUNT(*)…) < ?`). Un
contrôle préalable en PHP ne résiste pas à deux requêtes simultanées ;
quand il y en a un, c'est une optimisation, jamais le garde-fou.

**Ce que le navigateur doit savoir d'une série passe par `data-`.**
`carte.php` pose `data-statut`, `data-image`, `data-favori`,
`data-mangadex` : le JavaScript filtre sur des attributs, il ne
réinterprète jamais une URL ni un statut. `type_image()` classe les
couvertures une seule fois, en PHP, et elle est testée.

**Les libellés de `STATUTS` restent courts** (≤ 12 caractères, testé) :
ils s'affichent dans la pastille posée sur la couverture.

**`purger.php` ne charge que `config.php` et `mailer.php`.** Jamais
`fonctions.php`, qui enverrait des en-têtes HTTP et démarrerait une
session — ce qu'une tâche planifiée n'a pas à faire. Toute fonction dont
le cron a besoin va donc dans `config.php`.

**Le dépôt stocke en LF, le répertoire de travail est en CRLF**
(`core.autocrlf=true`). Un script qui modifie un fichier doit normaliser
en entrée et restituer les fins de ligne d'origine, sinon le diff devient
illisible.

**Les messages de commit sont sans accents**, par convention du dépôt.

## Les trois freins, et pourquoi ils ne se ressemblent pas

Les confondre a déjà coûté cher. Ils ne protègent pas les mêmes choses.

| Mécanisme | Protège | Comportement |
|---|---|---|
| `limiteur_echec` / `tentative_ip` | les **comptes**, contre la force brute | Compte des **échecs**, double la peine à chaque récidive |
| `mangadex_attendre_son_tour()` | l'**adresse IP du serveur**, face à MangaDex | File d'attente (`flock`, 250 ms). Ne compte personne, ne sanctionne personne |
| `couverture_quota()` / `couverture_consommer()` | l'**équité entre comptes** | Règle fixe et annoncée : 30 recherches / 2 min (120 en forfait `illimite`). Aucune escalade |

Le limiteur à peine doublante convient à des mots de passe essayés au
hasard. L'appliquer à l'usage normal d'une fonctionnalité revient à
punir quelqu'un qui s'en sert autant qu'elle le permet — ne pas
recommencer.

## Base de données

Sept tables : `utilisateur`, `serie`, `jeton_action`,
`session_persistante`, `tentative_ip`, `mail_file`,
`recherche_couverture`.

`livre.sql` est **entièrement rejouable**. Pour mettre à jour une base
existante, on rejoue le fichier **en entier** en retirant seulement les
deux instructions `CREATE DATABASE` et `USE` du début (signalées dans le
fichier) : chez OVH la base est créée depuis le manager et le `USE`
échouerait, entraînant tout le reste avec lui.

Ne jamais fournir une liste partielle de migrations : c'est ainsi que
`mail_file` a été oubliée sur le serveur, et le cron plantait en 500 à
chaque passage.

**Ne pas écrire `ADD COLUMN IF NOT EXISTS`**, même si c'est plus court :
c'est une extension MariaDB, et elle échoue sur MySQL d'Oracle comme
sur les MariaDB antérieures à la 10.0.2 — la base de production en a
fait l'expérience. Le motif portable est dans `livre.sql` : interroger
`information_schema`, puis ne construire l'`ALTER` que si la colonne
manque, et exécuter `DO 0` sinon.

## Déploiement

Copie de fichiers par FTP, rien d'autre. Dans l'ordre :

1. **Le SQL d'abord**, si le schéma a bougé.
2. Les fichiers modifiés — et **tous ceux dont ils dépendent**. Les
   pannes du projet ont presque toutes été des envois partiels.
3. **`ASSETS_VERSION` à incrémenter** dans le `.env` dès qu'un fichier de
   `js/` ou `css/` change. `actif()` s'en sert pour casser le cache ;
   sans l'incrément le correctif reste invisible, et on le croit raté.

**Ne montent jamais sur le serveur** : `tests/` (ses fichiers de cas sont
du PHP, qu'Apache exécuterait à la demande de n'importe quel visiteur) et
`.env`. Deux règles `.htaccess` interdisent `tests/` par précaution, à la
racine et dans le dossier lui-même.

## Tests

Lanceur maison, **un processus par fichier de cas** — c'est ce qui permet
à un fichier de faire `putenv()` avant de charger `lanceur.php`, et donc
de tester une constante dans un autre état.

L'amorce neutralise l'environnement : `DB_NAME` pointe sur
`information_schema` (les tables du projet sont hors de portée) et les
réglages SMTP sont vidés (rien ne part).

Le périmètre est celui des **fonctions pures** : rien qui exige la base
ou le réseau. `tests/LISEZMOI.md` tient la liste de ce qui est couvert,
de ce qui ne l'est pas, et pourquoi. Le JavaScript est hors périmètre :
le projet n'a pas Node.

Une fonction difficile à tester est souvent une fonction mal placée : les
règles d'accès du cron ont été extraites de `purger.php` vers
`config.php` pour cette raison précise.

## Pièges connus

**Un clic réel sur une `<label>` distribue DEUX évènements « click »,**
pas un. D'abord un sur l'étiquette elle-même, à un moment où le focus a
DÉJÀ quitté le champ associé (`document.activeElement` y est donc déjà
vide — inutile de le comparer à ce stade) ; ensuite un second,
synthétique, ciblant directement le champ, qui le refocalise. Vérifié
en instrumentant un vrai tap, pas seulement lu dans la spec : un
`console.log` naïf sur `document.activeElement` au premier évènement
aurait fait croire que le champ était encore focalisé.

Conséquence pour `js/commun.js` (évasion de champ, voir plus bas) :
détecter « l'utilisateur vient de cliquer sur l'étiquette du champ
déjà focalisé » ne peut pas se fier à `activeElement` au moment du
clic — il faut mémoriser QUI vient de perdre le focus via `focusout`
(qui se déclenche sur l'ANCIEN élément, avant ce manège), puis
comparer l'étiquette cliquée à cette mémoire.

**Une tâche planifiée refusée doit sortir avec un code non nul.**
`exit('Not found')` retourne **0**, donc une réussite. Un hébergeur réglé
sur « envoyer uniquement en cas d'erreur » ne dit alors jamais rien : la
purge peut ne pas tourner pendant des semaines dans le silence complet.

**Diagnostiquer un 500 en production.** `erreur_fatale()` montre le
détail technique (message, fichier, ligne) à l'appelant qui porte le bon
`CRON_TOKEN`, et à lui seul. `curl.exe -H "X-Cron-Token: …"` affiche le
corps de la réponse, là où `Invoke-RestMethod` lève une exception et
cache justement ce qu'on cherche. `purger.php?ip` diagnostique l'adresse
vue par PHP sans rien purger.

**L'encodage à l'envoi.** Transférer les fichiers en **binaire**. Coller
du texte dans l'éditeur ANSI de WinSCP corrompt l'UTF-8 — et le piège est
que du contenu correct y *paraît* faux.

**Un champ focalisé ne se quitte pas tout seul sur téléphone.** Ni un
tap ailleurs, ni un glissement de la page ne fermaient le clavier
virtuel — et cliquer sur l'ÉTIQUETTE du champ focalisé (« Titre * »)
le refocalisait silencieusement (comportement natif du navigateur),
donnant l'impression que rien ne se passait. `js/commun.js` y répond,
sans wiring par page (auto-actif partout où `commun.js` est chargé) :
tap hors du champ actif → `blur()` ; tap sur SA PROPRE étiquette →
interception (voir le piège ci-dessus) ; `touchmove` → `blur()`
(« touchmove » et non « scroll » : le navigateur fait défiler la page
tout seul pour garder un champ visible au-dessus du clavier quand il
s'ouvre, un `scroll` générique s'y serait donc déclenché à l'instant
même où l'on vient de toucher le champ).

Mais **tout glissement n'est pas un défilement.** La première version
fermait le champ au moindre `touchmove` — y compris l'appui long suivi
d'un glissement qui SÉLECTIONNE du texte, et le défilement interne d'un
textarea : sélectionner devenait impossible. Le glissement ne ferme le
champ que si les trois conditions tiennent : le geste a commencé HORS
du champ (décidé au `touchstart`, une fois pour tout le geste), il
dépasse `GLISSEMENT_MIN_PX`, et aucun texte n'est sélectionné (les
poignées de sélection débordent sous la ligne, hors de la boîte du
champ).

**Le clavier ne réduit que la zone visible, pas la mise en page.** La
modale garde donc toute sa hauteur et son bas passe sous le clavier. Sous
« URL de l'image », il ne reste presque rien à faire défiler (41 px
mesurés pour 190 nécessaires). Toucher ce champ laissait le clavier
par-dessus. Le navigateur ne le remontait qu'à la première lettre tapée :
il décale alors tout l'écran, recours qu'il n'emploie pas au simple
toucher. `garderVisible()` (`js/commun.js`, écrans tactiles seulement)
fait défiler les conteneurs du champ en annulant tout défilement inutile,
pour ne pas faire bouger la liste derrière la modale. S'il manque encore
de la place, il prête du rembourrage bas au conteneur (en CSSOM, que la
CSP accepte) et le rend quand le clavier se ferme. Il n'agit que si le
champ est réellement caché.

Pour tester ce genre de code dans le navigateur intégré, un `.focus()`
lancé par script ne déclenche **ni `focusin` ni `focusout`** tant que la
page n'a pas le focus système (`document.hasFocus()` à `false`). Un
banc qui s'en contente rate tout le code branché sur ces évènements. Il
faut focaliser par un vrai clic. Le clavier se simule en remplaçant
`window.visualViewport` (attribut `[Replaceable]`) par un objet dont on
réduit `height` avant d'envoyer `resize`.

**Les grilles CSS étirent leurs lignes par défaut.** Avec un `max-height`
sur le conteneur, les lignes sont dimensionnées contre la hauteur
disponible et non contre leur contenu : l'élément devient plus court que
ce qu'il porte, et son `overflow: hidden` tranche le texte. Invisible sur
un écran large. D'où `align-content: start` sur `.cover-results`.

**Un rapprochement symétrique a besoin d'un plancher de longueur.** La
recherche de la bibliothèque (`correspondPrepare`, `js/commun.js`)
acceptait `qm.startsWith(m)` — l'utilisateur tape plus que le mot
stocké, « Berserker » pour trouver « Berserk ». Sans longueur minimale,
un mot d'UNE lettre du titre suffisait : « À toi d'être un héros ! »
contient le mot « a », donc toute recherche commençant par un « a »
remontait cette série. Les titres français en sont pleins — « à »,
« d », « l ». Quatre caractères minimum de ce côté-là ; le sens inverse
(`m.startsWith(qm)`) reste libre, c'est une recherche par préfixe et
elle est voulue.
**MangaDex** : les endpoints de lecture ne demandent ni compte ni clé,
mais l'API n'envoie pas d'en-tête CORS — d'où l'appel depuis le serveur.
Limite d'environ 5 requêtes par seconde et par IP, et une recherche en
coûte 1 + `COUVERTURE_MAX_SERIES`.

**Se délier reste possible sans perdre l'image.** Le bouton
« 🔓 Délier de MangaDex » rapatrie la couverture affichée en local
(`mangadex_image_locale()`, même pipeline que les fichiers envoyés) :
elle perd ainsi son URL MangaDex, et le lien disparaît par la même
règle que le reste — rien de dédié. `couvertures.php` déclare donc un
`require_once` explicite vers `images.php` : il en dépend désormais
réellement, et ne plus se fier à l'ordre de chargement d'un appelant
est exactement la leçon de la section cron ci-dessus.

**Une carte créée ou modifiée ne disparaît pas d'un filtre actif à
l'instant même.** `poserCarte()` (js/app.js) lui accorde une
exception par catégorie (`_exceptions`, statut/favoris/image — jamais
la recherche). Elle s'efface au premier clic dans la catégorie
concernée, quel que soit le bouton précis — pas seulement celui qui
l'exclurait. Décision volontaire (l'utilisateur a choisi cette règle
entre deux, voir l'historique) : suivre la valeur exacte portée au
moment de l'exception aurait été plus fidèle, mais plus fragile et
moins devinable.

**Une série est LIÉE, pas recherchée à chaque fois.** Choisir une
couverture enregistre `serie.mangadex_id` ; avancer d'un tome coûte
alors une seule requête au lieu d'une recherche entière. Le lien se
déduit de l'URL de l'image (`mangadex_id_depuis_url()`) et n'est jamais
transmis à côté : choisir une image d'une autre source le coupe donc
tout seul, sans code dédié et sans que les deux puissent diverger.

**Sa pertinence n'est pas la nôtre.** Chercher « Shingeki no Kyojin »
remontait 130 résultats dont les 25 premiers étaient TOUS des
doujinshi : la vraie série n'était même pas candidate. D'où trois
règles, dans `chercher_couvertures()` :

- écarter les étiquettes de format « Doujinshi » et « Oneshot »
  (`MANGADEX_FORMATS_EXCLUS`) — elles portent le titre de la série
  dont elles s'inspirent et n'ont pas de tomes à emprunter ;
- demander `COUVERTURE_CANDIDATS` séries et n'en retenir que
  `COUVERTURE_MAX_SERIES` après notre propre classement. Élargir ce
  premier appel ne coûte **aucune requête de plus** — seuls les
  `/cover` qui suivent se paient à l'unité ;
- comparer la recherche à **tous** les titres connus
  (`couverture_titres_connus()`), pas au seul titre affiché :
  l'utilisateur tape l'écriture qu'il connaît, et MangaDex affiche
  « Attack on Titan » là où il a tapé « Shingeki no Kyojin ».
