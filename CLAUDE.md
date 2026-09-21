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
php tests/lancer.php              # toute la suite (357 tests, 32 fichiers de cas)
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

`ADD COLUMN IF NOT EXISTS` est une **extension MariaDB** — ce qui couvre
OVH, mais pas un MySQL d'Oracle.

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

**Les grilles CSS étirent leurs lignes par défaut.** Avec un `max-height`
sur le conteneur, les lignes sont dimensionnées contre la hauteur
disponible et non contre leur contenu : l'élément devient plus court que
ce qu'il porte, et son `overflow: hidden` tranche le texte. Invisible sur
un écran large. D'où `align-content: start` sur `.cover-results`.

**MangaDex** : les endpoints de lecture ne demandent ni compte ni clé,
mais l'API n'envoie pas d'en-tête CORS — d'où l'appel depuis le serveur.
Limite d'environ 5 requêtes par seconde et par IP, et une recherche en
coûte 1 + `COUVERTURE_MAX_SERIES`.
