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

## Instructions
à chaques taches terminée : 
- résumé des solutions et des résultats réelles
- fait les test unitaire des fonctions que tu crée
- fait les testes unitaires `php tests/lancer.php`
- commit avec un petit message
- push, intègre aussi mes propres modifications sauf en cas d'erreurs
- dit moi "x tests fait x erreurs, je commite, je push"
- dit moi quelles fichier mettre dans le serveur et quelle mofifications du .env
- quand tu modifie la bdd donne moi le sql pour modifier la base sans perdre mes données

## Commandes

```bash
php tests/lancer.php              # toute la suite (886 tests : 751 PHP en 52 fichiers, 135 JavaScript)
php tests/lancer.php mot_de_passe # les fichiers dont le nom contient ce motif
php tests/lancer.php javascript   # le JavaScript seul, dans Edge ou Chrome sans fenêtre
php tests/cas/carte_test.php      # un seul fichier, pratique pour déboguer
php -l fichier.php                # lint (il n'y a pas d'autre vérificateur)
php purger.php                    # la tâche planifiée, à la main
```

Le lanceur sort avec le code **0** si tout passe, **1** sinon.

**MySQL doit tourner**, même si aucun test n'interroge la base :
`includes/config.php` ouvre une connexion PDO dès son inclusion, et une
connexion ratée appelle `erreur_fatale()`, qui coupe le processus.

Sous Windows sans `php` dans le `PATH` : `C:\xampp\php\php.exe`.

Si le port 3306 est pris par un autre serveur MySQL (un service
« MySQL80 » l'occupait un jour, et refusait `root` sans mot de passe),
**ne pas y toucher** : lancer une instance jetable de la base de XAMPP sur
un autre port, hors du projet, et la désigner par l'environnement (le
`.env` n'écrase jamais une variable déjà posée) :

```bash
mysql_install_db.exe --datadir=<dossier jetable>
mysqld.exe --no-defaults --datadir=<dossier jetable> --port=3399 --skip-grant-tables
DB_HOST='127.0.0.1;port=3399' php tests/lancer.php     # le « ;port= » passe tel quel dans le DSN
```

Pour essayer le site lui-même : `php -S 127.0.0.1:8099 -t site` avec
`DB_HOST`, `DB_NAME`, `APP_URL=http://localhost:8099` et `SMTP_HOST=` (vide :
aucun e-mail ne part) dans l'environnement, après avoir rejoué `livre.sql`.
Sous XAMPP, le site est à `http://localhost/Livre/site/` (c'est l'`APP_URL`
du `.env` local) ; l'adresse nue `http://localhost/Livre/` y redirige.

## Structure

### Le dépôt : `site/` monte sur le serveur, le reste reste ici

| Où | Quoi |
|---|---|
| `site/` | **Tout ce qui monte, tel quel** : les pages, `includes/`, `js/`, `css/`, `.htaccess`, `.user.ini`, et `uploads/` (dont seul le `.htaccess` est au dépôt : les images restent sur le serveur) |
| racine | `livre.sql`, `README.md`, `CLAUDE.md`, `.env`, `.env.example`, `.gitignore`, `.ovhconfig`, `tests/`, et un `.htaccess` **de développement local** (hors de `site/` : il ne monte jamais) |

**Les chemins de ce fichier, sans autre précision, sont ceux de `site/`**
(`includes/config.php` est `site/includes/config.php`). Dans le code :
`CHEMIN_RACINE` (défini par `config.php`) est `site/` — c'est là que vit
`uploads/` — et, dans les tests, `CHEMIN_SITE` en est le même dossier, vu de
l'amorce, tandis que `CHEMIN_PROJET` est la racine du dépôt.

### Le noyau

| Fichier | Rôle |
|---|---|
| `includes/config.php` | **Le seul endroit** où le `.env` devient des constantes. Ouvre aussi la connexion PDO (`$pdo` global), et porte `erreur_fatale()`, `ip_client()`, `taille_lisible()` et les règles d'accès du cron |
| `includes/fonctions.php` | Bibliothèque partagée **des pages** : CSRF, sessions, limiteur, `STATUTS`, `actif()`. Envoie les en-têtes de sécurité et démarre la session **dès l'inclusion** |
| `includes/mailer.php` | Envoi SMTP direct, une seule tentative (pas de file : un envoi raté est perdu, l'appelant le dit). Chaque e-mail part en texte ET en HTML (`composer_message()`) |
| `includes/images.php` | Chaîne GD : type déduit du contenu, ré-encodage WebP, nom = empreinte salée |
| `includes/carte.php` | Le HTML d'une carte de série |
| `includes/google.php` | « Continuer avec Google » (OpenID Connect) : l'adresse de départ, la lecture et la vérification du jeton, `google_decision()`. Pur, sauf `google_echanger_code()` |
| `includes/cle_acces.php` | Clés d'accès (WebAuthn) : lecture du CBOR, clé publique COSE → PEM, vérification d'une création et d'une connexion, défi, options. Pur, sauf la session (`cle_defi_*`) et la base (dernière section). Aucune bibliothèque |
| `includes/reglages.php` | Les réglages modifiables depuis l'administration : la liste FERMÉE (`REGLAGES`, avec bornes, unités, défauts), la validation d'une saisie, le filtrage de ce que porte la table `reglage`. Chargé par `config.php` avant toute constante, donc **aucune dépendance** (ni constante, ni `env()`) |
| `includes/admin.php` | La page d'administration : la liste des comptes (`admin_utilisateurs`), les décisions pures (ce qui est permis, la forme du motif d'un blocage, les dates, les chiffres), et les écritures (`admin_changer_forfait`, `admin_changer_droits`, `admin_supprimer_compte`). Inclus par `admin.php` et `api.php`, jamais par `fonctions.php` |
| `includes/couvertures.php` | Client MangaDex. Inclus par `api.php`, et par `index.php` / `parametres.php` pour **annoncer** le quota de recherche — jamais par `fonctions.php` : un test qui s'en sert doit le demander explicitement |

### Les points d'entrée

`index.php` (la bibliothèque), `connexion.php`, `inscription.php`,
`parametres.php`, `mot-de-passe-oublie.php`,
`reinitialiser-mot-de-passe.php`, `verifier-email.php`,
`deconnexion.php`, `mentions-legales.php`, `google.php` (départ vers
Google et retour : ouvre le compte, ou mène à la création),
`connexion-cle.php` (l'appel JSON de la connexion par clé d'accès : défi, puis vérification),
`google-inscription.php` (création d'un compte Google : identifiant,
mot de passe facultatif), `google-mot-de-passe.php` (seconde étape
d'une connexion Google, quand le compte a un mot de passe), `admin.php`
(l'administration : les comptes, leurs forfaits, les blocages — réservée aux
comptes `admin`, voir « Règles tacites »).

`api.php` est le **point d'entrée AJAX unique** : un `switch` sur
`$_POST['action']`. Chaque branche vérifie le CSRF et cloisonne par
`utilisateur_id`.

`purger.php` est la tâche planifiée : ménage des tables et rapport d'activité envoyé à `ADMIN_EMAIL`.

### Le client

`js/commun.js` (socle partagé), `js/app.js` (bibliothèque),
`js/auth.js`, `js/settings.js`, `js/cle-acces.js` (clés d'accès : conversions pures
`window.CleAcces`, testées, et le bouton de la page de connexion), `js/mdp.js` (règles du mot de passe
pendant la saisie), `js/delai.js` (comptes à rebours des
attentes), `js/double-appui.js` (un clic posé sur le document, qui ne fait rien :
chargé par TOUTES les pages, voir « Règles tacites »), `js/admin.js` (la page
d'administration : recherche, tri, fenêtres de confirmation ; sa logique pure est
`window.Admin`, testée). `css/style.css` pour tout le style.

`app.js` commence par `window.Bibliotheque` : sa logique pure (carte
voisine au clavier, suivi du défilement, textes des quotas), testée par
`tests/js`. Le reste du fichier la branche sur la page, et ne branche
rien hors de la bibliothèque — c'est ce qui permet au banc de le charger.

## Règles tacites

**Tout réglage passe par le `.env`, jamais en dur ailleurs** — sauf les
trente de `REGLAGES` que l'administrateur peut surcharger depuis `admin.php`
(voir plus bas). Et chaque constante de `config.php` est bornée par un plancher
ou un plafond : un `.env` mal rempli ne doit jamais pouvoir *supprimer* une
protection.
`tests/cas/config_planchers_test.php` et `couvertures_reglages_test.php`
imposent des valeurs absurdes et vérifient qu'elles sont relevées.
**Seule exception : les quotas de recherche** (`COUVERTURE_QUOTA`,
`COUVERTURE_QUOTA_ILLIMITE`), où 0 ou une ligne absente veut dire « sans
limite ». Ils répartissent l'usage entre comptes, ils ne protègent pas le
serveur — la file d'attente vers MangaDex s'en charge, et elle garde son
plancher.

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
le cron a besoin va donc dans `config.php`. `mailer.php` compris : il
compose le rapport que le cron envoie, et ne peut appeler ni `e()` ni
rien d'autre de `fonctions.php` (d'où son propre `htmlspecialchars`).

**Dans un e-mail, seules les adresses du site deviennent des liens.**
`corps_html()` ne fait un lien que de ce qui commence par `APP_URL` ;
tout le reste est échappé, même s'il a l'air d'une adresse. Le texte
d'un avis de sécurité porte des données choisies par d'autres (un
identifiant, une adresse masquée) : elles ne doivent jamais devenir un
lien cliquable dans un message authentique du site.

**Le dépôt stocke en LF, le répertoire de travail est en CRLF**
(`core.autocrlf=true`). Un script qui modifie un fichier doit normaliser
en entrée et restituer les fins de ligne d'origine, sinon le diff devient
illisible.

**Les messages de commit sont sans accents**, par convention du dépôt.

**Une erreur de formulaire va sous SON champ, jamais dans une
notification.** Convention d'identifiant partagée : le message porte
l'id `<id du champ>-erreur`. En PHP, les erreurs sont rangées par champ
et rendues par `champ_aria()` / `champ_erreur()` ; l'API nomme le champ
(`champ`, ou `erreurs` pour plusieurs) et `Lib.erreurChamp()` le pose.
`Lib.toast()` ne sert plus qu'aux confirmations.

**Un e-mail ne parle que du mot de passe qui existe.** Un compte Google
peut ne pas en avoir : chaque avis se compose selon `acces_compte()`
(« email », « google », « google_mdp »), passé par l'appelant depuis
`$moi['google_sub']` et `sans_mot_de_passe` AVANT l'action. Les textes sont
dans les fonctions pures `avis_*()` de `mailer.php`, testées. « Mot de
passe oublié » n'envoie aucun lien à un compte Google sans mot de passe :
en définir un demande de se reconnecter avec Google.

**« Mot de passe oublié » s'ouvre aussi connecté.** La fenêtre de
confirmation des Paramètres y mène (sous « Mot de passe actuel ») :
`mot-de-passe-oublie.php` pré-remplit alors l'identifiant du compte (on
s'y désigne par identifiant OU adresse, le lien part toujours à l'adresse
enregistrée), et
`reinitialiser-mot-de-passe.php` accepte le lien dans le navigateur où
l'on est connecté — le jeton prouve l'accès à l'adresse, et
`invalider_sessions()` ferme la session en cours. Les rediriger vers
l'accueil rendait le lien inutilisable depuis là.

**Aucune consigne d'avance dans un formulaire.** Pas de « * », pas de
« obligatoire » : un champ requis laissé vide le dit à l'envoi
(`MESSAGE_CHAMP_OBLIGATOIRE`, via `forme_identifiant()` /
`forme_email()` / `valider_mot_de_passe()`). Le mot de passe ne cite que
les règles NON respectées : `valider_mot_de_passe()` côté serveur, et
`js/mdp.js` (son miroir, mêmes messages) en quittant le champ puis
pendant la frappe — tout champ `data-regles-mdp`. Une règle ajoutée d'un
côté s'ajoute de l'autre, tests compris. Ce qui concerne le lien de
confirmation se dit à l'étape suivante, quand il est parti.

**Le site ne connaît ni prénom ni nom** (retirés, migration 8) : un
compte, c'est un identifiant et une adresse. L'avatar par défaut prend
les deux premières lettres de l'identifiant (`initiales()`).

**La grille ne compte qu'un arrêt de tabulation.** `carte.php` pose
`tabindex="-1"` sur tout ce qui est focalisable dans une carte, et
`js/app.js` rend le sien à la carte active (`FOCUSABLES_CARTE`). Un
nouveau bouton de carte qui oublierait l'un ou l'autre ramènerait des
centaines d'arrêts.

**La carte sélectionnée, c'est celle qui a le focus** — aucun état tenu à
côté. Sur ordinateur (`pointer: fine`, même condition dans `js/app.js` et
`css/style.css`), un clic hors de ses boutons la sélectionne en
focalisant sa couverture ; un second clic, ou un clic ailleurs, la
désélectionne (le navigateur retire le focus, on ne le rend pas). Suppr —
ou Retour arrière, la touche « delete » d'un Mac (`toucheSuppression()`) —
ouvre la même confirmation que la fiche, focus sur « Annuler » ; après
suppression, la sélection passe à la voisine. Le cadre, c'est
`.card:focus-within`. `demanderSuppression()` reçoit l'élément où rendre
le focus si l'on annule : le lire dans `activeElement` donnait `<body>`,
parce que `commun.js` sort d'un champ au moindre clic ailleurs et que
Safari ne focalise pas le bouton cliqué.

Suppr fait de même dans la fiche d'une série existante (demande de
l'utilisateur), hors d'un champ où la touche efface du texte
(`champDeSaisie()`). Sur ordinateur, cette fiche s'ouvre donc sur son
`<h2>` (`tabindex="-1"`, sans cadre), plus dans le Titre : le curseur
dans le champ rendait Suppr inopérant à l'ouverture. Une nouvelle série
garde le Titre.

**Les quatre filtres d'image portent leur nombre, comme les statuts.**
`compter_series()` lit `statut`, `favori` et `couverture` en UNE requête
et range chaque série avec `type_image()` (`compter_lignes()`, pure,
testée) : refaire le classement en SQL le ferait diverger du filtre. Les
clés sont `image-<type>` et les identifiants `count-image-<type>`, remis
à jour par `majCompteurs()` avec le `compte` que chaque réponse d'`api.php`
renvoie. L'ordre des boutons est celui d'`IMAGES_TYPES` (MangaDex, Lien,
Importée, Pas d'image), que `index.php` parcourt, comme il parcourt
`STATUTS` pour les statuts.

**Les filtres tiennent sur UNE rangée au repos : « Toutes », « Statut ▾ »,
« ★ Favoris », « Image ▾ »** (demande de l'utilisateur : la barre était
trop longue). Statut et Image sont des GROUPES repliés, un seul ouvert à
la fois (`panneauApres()`, mémorisé par compte sous `panneau` ;
`panneauMemorise()` relit aussi l'ancien `imageOuvert`). « Favoris » reste
un interrupteur direct. **« Toutes » remet à zéro les trois genres de
filtres** (statut, favori, image) et ne s'allume que quand plus rien n'est
posé (`aucunFiltre()`) : il reste visible groupes repliés, c'est le seul
moyen de tout effacer d'un geste. **« Toutes » referme aussi le groupe resté
ouvert** (demande de l'utilisateur : cliquer « Toutes » ferme les
sous-filtres) : `panneauApres(ouvert, "toutes")` rend `""`. « Favoris » ne le
referme PAS, ni les pastilles DANS un groupe (les statuts se cumulent) : la
première version fermait aussi sur « Favoris », ce que l'utilisateur n'avait
pas demandé. Un groupe replié dit ce qu'il porte par
une pastille dorée en surimpression (`.nb-actifs`) — en surimpression et
non en rangée : élargissant « Statut » de 30 px, elle poussait « Image »
hors de l'écran. Les statuts gardent leurs ids `count-<statut>` et leurs
`data-filter` ; les chips passent de la rangée du haut au groupe.

**Chaque rangée de filtres tient sur UNE ligne et coulisse sur le côté**
(demande de l'utilisateur : c'est ce qui garde la barre courte ; « Favoris »
compte plus qu'« Image », donc c'est « Image », dernier, que le bord coupe
— le mot « Favoris » reste entier sur téléphone). La barre de défilement
est masquée, et un bouton ENTIÈREMENT hors de l'écran ne laisse rien
deviner de son existence : « Pas d'image » est tout entier caché à 375 px
(495 px pour 351). D'où un **fondu de 28 px sur le bord qui a de la
suite** : `bordsDefilement()` (pure, testée) décide, `majBords()` pose
`.bord-gauche` / `.bord-droite` au défilement et à chaque changement de
taille (`ResizeObserver` sur la rangée ET ses boutons : un groupe qui
s'ouvre, un nombre qui change de largeur), et `style.css` en fait un
`mask-image`. La rangée du haut déborde de 30 px à 375 px (48 à 360 px) :
sous 480 px ses boutons sont resserrés pour réduire ce peu. `#filters`
garde 8 px de rembourrage haut (et une marge négative qui les rend) : le
défilement (`overflow-x`) rognerait sinon la pastille `.nb-actifs`, posée
au-dessus du bouton.

**L'œil du mot de passe est hors de la tabulation** (demande de
l'utilisateur) : `tabindex="-1"` sur chaque `.toggle-password`, pour
qu'un seul Tab mène du mot de passe au champ suivant. Tout nouvel œil le
porte, et `piegerFocus()` (`js/commun.js`) ignore les `tabindex="-1"` :
sinon l'œil comptait comme dernier arrêt d'une fenêtre, et Tab s'en
échappait.

**Le double-appui ne zoome sur AUCUNE page** (demande de l'utilisateur).
`* { touch-action: manipulation; }` dans `css/style.css`, sur TOUS les
éléments et non sur une liste : le comportement d'un élément est
l'intersection du sien et de celui de ses ancêtres, mais seulement
jusqu'au premier cadre qui défile — une liste de boutons laissait zoomer
sur le fond de page, un texte, une zone multiligne, et tout ce qui est
dans une fenêtre ou une rangée de filtres qui défile. Le pincement reste
permis, exprès : c'est le recours de qui lit mal. Ne jamais y ajouter
`user-scalable=no` ni `maximum-scale` dans le viewport, ni un
`touch-action: none` / `pan-*` seul (`tests/cas/tactile_test.php` le
refuse). Toute nouvelle page HTML charge `css/style.css`, sinon elle zoome.

**La règle CSS ne suffisait pas, et la vraie cause est un gestionnaire de
clic.** L'utilisateur a redemandé la même chose, la règle étant déjà en ligne :
le double-appui zoomait encore à côté des champs de `connexion.php`,
`inscription.php` et `mentions-legales.php`, mais plus sur `index.php` ni
`parametres.php`. La différence n'est pas le CSS (le même partout) : ces deux-là
chargent `commun.js`, qui pose un `click` sur `document` (quitter un champ en
touchant ailleurs). Depuis iOS 13, Safari ne zoome pas au double-appui là où la
page réagit déjà au clic — il le livre tout de suite et n'attend plus de second
appui —, et un gestionnaire sur `document` fait réagir TOUTE la page (WebKit,
bug 205158 ; changement 250780). Les autres pages n'en avaient aucun.
`js/double-appui.js` en pose un, **qui ne fait rien** : c'est sa présence qui
compte, ne pas le retirer ni le « nettoyer ». **Chaque page le charge**
(`tests/cas/tactile_test.php` le vérifie) — une nouvelle page qui l'oublie
zoomerait à côté de ses champs. Une première version annulait le second appui
au `touchend` et rejouait son clic : un détour inutile, retiré.
**Non vérifié sur un iPhone** : le diagnostic repose sur le comportement
documenté de WebKit et sur ce que l'utilisateur observe, pas sur une mesure.

**Les filtres se collent sous la barre du haut, à sa hauteur mesurée.**
`js/app.js` pose `--hauteur-topbar` (et `--hauteur-filtres`, dont se
sert la marge de défilement des cartes). `.barre-filtres` reste le
PREMIER élément de `<main class="bibliotheque">`, qui n'a pas de
rembourrage haut : c'est ce qui la fait coller dès le premier pixel, et
ce qui permet au script de la dire « collée » dès que `scrollY > 0`.

**Un message après redirection passe par `flash()`, jamais par l'URL.**
`?mdp=1` réaffichait « Mot de passe modifié » à chaque rechargement.
`flash()` le range dans la session, `flash_prendre()` le lit une fois.
La suppression du compte s'en sert aussi : `api.php` vide la session et
la renouvelle (`session_regenerate_id`) au lieu de la détruire, pour que
`connexion.php` puisse dire que c'est fait.

**Chaque action à risque prouve l'identité, dans la MÊME fenêtre pour un
compte e-mail et un compte Google** (choix de l'utilisateur, qui trouvait
les deux cartes trop différentes) : changer d'identifiant ou d'adresse,
définir ou changer le mot de passe, vider, supprimer le compte — la liste
fermée `ACTIONS_SENSIBLES`. Rien ne se voit sur la page au repos (pas de
bouton Google, pas de champ mot de passe) : cliquer « Enregistrer » (ou
« Vider »/« Supprimer ») ouvre une fenêtre à deux phases (`js/settings.js`,
`#confirm-phase-identite` puis `#confirm-phase-action`) :
1. **Identité** — un compte e-mail retape son mot de passe et clique
   « Confirmer mon identité » : l'action `compte.authentifier` (api.php)
   le vérifie et NOTE une confirmation, sans rien faire d'autre. Un compte
   Google se reconnecte (`google.php?action=…`), son mot de passe suit
   s'il en a un (`google-mot-de-passe.php`) : une vraie navigation, qui
   revient sur la page — la fenêtre s'y rouvre d'elle-même, déjà à la
   phase suivante (`BOUTON_ACTION` pour Vider et Supprimer).
   **La saisie ne se perd pas dans l'aller-retour** (demande de
   l'utilisateur) : pour le Profil et le mot de passe, le lien Google
   envoie d'abord la saisie à api.php (`preparer=1`), qui la vérifie et la
   range dans `$_SESSION['google_attente']` — une empreinte, jamais le mot
   de passe. Au retour, parametres.php la passe (résumée, `data-en-attente`)
   à la fenêtre, qui propose « Enregistrer ces modifications ? » ; le
   bouton rappelle l'action avec `en_attente=1`. Voir `google_attente()`.
   Revers assumé : un mot de passe ainsi défini ne passe pas par le POST
   classique, le gestionnaire de mots de passe ne propose pas de le garder.
2. **Action** — `CONFIRMATION_DUREE` secondes pour cliquer le
   bouton final, qui fait le travail réel. La fin du délai vaut
   « Annuler » (choix de l'utilisateur) : la fenêtre se ferme, et la
   confirmation comme la saisie en attente s'effacent.

La confirmation vaut pour UN compte, UNE action et UNE fois
(`confirmation_en_cours()`, `oublier_confirmation()` dès l'action faite) ;
se connecter n'en donne aucune, et « Annuler » l'efface
(`compte.annuler_confirmation`, ou le formulaire `annuler_confirmation`
des Paramètres — qui ne reste, en HTML, que pour « Supprimer le mot de
passe », seule section encore propre à Google : ajouter ou retirer un mot
de passe n'a pas d'équivalent côté e-mail, rien à y unifier).
`verifier_mot_de_passe_limite($id, $mdp, $attente, $action)` porte tout
ça : elle regarde d'abord `confirmation_recente()` (peu importe qui l'a
obtenue), et NOTE elle-même une confirmation quand un compte e-mail
retape son mot de passe avec succès — c'est ce qui fait marcher, sans
JavaScript, les formulaires restés dans le HTML (Profil, Sécurité) : ils
gardent leur champ ou leur bouton Google, JavaScript les cache et les
remplace par la fenêtre. Toute nouvelle action sensible passe par elle
avec sa clé de `ACTIONS_SENSIBLES`, jamais par `password_verify()`
directement — et doit consommer la confirmation une fois faite.

**La fenêtre qui efface ne donne jamais le focus à son bouton** : une
touche Entrée suffisait à tout supprimer. Le bouton final reste grisé
tant que l'identité n'a pas été confirmée.

**Le mot de passe d'un compte Google est une seconde clé, jamais une
porte.** Facultatif (`mot_de_passe` vaut `''` sans lui), il est demandé
APRÈS Google à chaque connexion : `google_decision()` rend alors
« mot_de_passe », google.php range le compte dans
`$_SESSION['google_mdp']` (sans rien ouvrir) et `google-mot-de-passe.php`
le demande, avec les mêmes freins que `connexion.php`. Le formulaire
« adresse e-mail » refuse un compte Google même au bon mot de passe (il
le renvoie vers Google) : sinon le mot de passe seul contournerait Google.
Il se supprime dans Paramètres › Sécurité (`avertir_mot_de_passe_supprime()`).

**Comptes e-mail et comptes Google ne se relient pas** (choix de
l'utilisateur) : `google_decision()` refuse l'adresse d'un compte e-mail
confirmé, et il n'y a ni « associer » ni « dissocier ». Un compte créé
avec Google l'est par `google-inscription.php` : google.php range
l'identité confirmée dans `$_SESSION['google_inscription']`, la personne
choisit son identifiant (et un mot de passe facultatif), et l'INSERT
suit. Un compte e-mail JAMAIS confirmé portant l'adresse est supprimé
à ce moment-là : il n'a jamais pu être ouvert.

**Connexion et inscription commencent par le choix de la méthode**
(Google ou adresse e-mail, `?avec=email`), avant tout formulaire. Sans
Google configuré, ce choix n'a pas lieu d'être : le formulaire vient
directement.

**Une clé d'accès (passkey) ouvre le compte à elle seule, et se pose comme
n'importe quelle action à risque** (demande de l'utilisateur : se connecter
sans rien taper, avec son gestionnaire de mots de passe). Pour un compte
e-mail comme pour un compte Google : `compte.cle_creer` est dans
`ACTIONS_SENSIBLES`, donc la même fenêtre en deux phases, et l'identité
prouvée ne sert qu'à CETTE action, une fois (`cle.creer` la consomme).
Google + mot de passe reste « une seconde clé, jamais une porte » pour le
MOT DE PASSE ; la clé d'accès, elle, est une porte à part, dont la pose a
exigé la preuve complète — d'où aussi un e-mail d'avis à l'ajout
(`avis_cle_acces_ajoutee()`). Retirer une clé n'ouvre rien : une
confirmation en place, sans identité.

- **Le serveur reste maître.** Défi de 32 octets à usage unique dans
  `$_SESSION['cle_defi']` (usage, compte, `CLE_ACCES_DEFI_DUREE`), pris —
  donc consommé — AVANT toute vérification, réussite ou non. Adresse du site
  et origine lues dans `APP_URL` (`cle_rp_id()`, `cle_origine()`), jamais
  dans l'en-tête Host. Signature vérifiée par `openssl_verify` (ES256 et
  RS256 ; pas d'EdDSA : OpenSSL ne le fait pas). Compteur de signatures :
  il ne recule pas, sauf `0` puis `0` (clés synchronisées). L'attestation
  n'est pas vérifiée, on demande `none` : on enregistre une clé, on ne
  juge pas l'appareil.
- **`userVerification: preferred`, exprès.** L'exiger ferait redemander le
  code du gestionnaire à chaque connexion, ce que la demande voulait éviter.
- **Le compte vient de la clé retrouvée en base** (`cle_trouver()`), jamais
  d'un champ du navigateur ; l'identifiant d'utilisateur qu'il renvoie ne
  fait que recouper. Une même clé ne sert pas deux comptes (index unique
  sur l'empreinte).
- **Les échecs se comptent sous « connexion_cle »**, ni avec « connexion »
  (une clé périmée ne doit pas bloquer le mot de passe) ni par compte (on
  ne verrouille pas un compte de l'extérieur : une signature ne se devine pas).
- **« Mot de passe oublié » retire TOUTES les clés** : une clé posée par un
  intrus (il lui a fallu le mot de passe, ou Google) survivrait sinon à la
  reprise en main. La page de réinitialisation le dit.
- **La demande en arrière-plan de `js/cle-acces.js` reste silencieuse.**
  Au chargement de la page de connexion, le script demande au navigateur de
  proposer les clés dans le champ identifiant (`mediation: "conditional"`).
  Son échec — aucune clé à proposer, liste fermée — ne dit rien et ne
  relance rien : la première version relançait, et un échec immédiat
  bouclait en harcelant le gestionnaire de mots de passe de l'utilisateur.
  Seule une clé CHOISIE puis refusée par le serveur affiche un message.
- **`js/cle-acces.js` se charge AVANT `js/settings.js`** (qui lit
  `window.CleAcces` au chargement), et le défi de création est tiré dès que
  la fenêtre passe à la phase « action » (`PRECHARGER`) : certains
  navigateurs n'ouvrent le gestionnaire que dans le geste du clic.
- **Essayer cela dans le navigateur intégré** : remplacer
  `navigator.credentials.create` / `get` par un faux (WebCrypto : clé ECDSA,
  objet CBOR à la main, signature convertie en DER) — le vrai appelle le
  gestionnaire de l'utilisateur. Le chargement de la page de connexion fait
  déjà UNE demande réelle en arrière-plan : n'en provoquer qu'une, jamais
  en boucle.
- **Non vérifié ici** : un vrai gestionnaire de mots de passe (Bitwarden,
  1Password, Proton Pass…) et iOS/Safari. Seuls un authentificateur simulé
  (tests PHP, HTTP complet, faux navigateur) l'ont été.

**Le forfait « bloqué » est la consultation seule** (demande de l'utilisateur).
`utilisateur.forfait` vaut `'bloque'` (à poser À LA MAIN en base, comme
« illimite » : `UPDATE utilisateur SET forfait = 'bloque' WHERE identifiant =
'…'`). Le compte se connecte, lit, cherche, filtre, **exporte**, gère son
compte (mot de passe, clés d'accès) et peut le **supprimer** : ce sont des
droits sur ses propres données. Il ne peut plus : créer ni modifier une série
(`serie.enregistrer`), mettre en favori, changer de tome (→ et ←), importer un
`.json` — ce que l'utilisateur a demandé — ni, par cohérence, supprimer une
série, vider la bibliothèque, ni toucher à MangaDex (`couverture.chercher`,
`.rafraichir`, `.delier` : quota et appels du serveur, ou image réécrite).
- **Le serveur est le seul juge.** `api.php` refuse (403, `bloque: true`) toute
  action de `ACTIONS_BLOQUEES` (`includes/fonctions.php`) AVANT le switch, juste
  après le CSRF ; `forfait` est relu à chaque requête, donc un blocage posé
  pendant une session prend effet à l'appel suivant. Les pages cessent aussi
  de proposer ce que le serveur refuse (`carte_html($s, true)` : ni bouton ni
  couverture cliquable, l'étoile reste, fixe ; « + » caché ; bandeau ;
  Importer et Vider désactivés dans Paramètres) — politesse, pas barrière.
- **Toute action d'`api.php` est CLASSÉE.** `tests/cas/forfait_bloque_test.php`
  lit les `case` et exige que chacun soit dans `ACTIONS_BLOQUEES` ou dans
  `ACTIONS_LIBRES_DU_BLOQUE` (le test, avec la raison). Une action ajoutée sans
  décision fait échouer le test : sinon une écriture oubliée resterait ouverte.
- **L'`ENUM` doit connaître « bloque » AVANT l'`UPDATE`** (migration 10 de
  `livre.sql`). Hors mode strict, une valeur absente de l'ENUM est rangée en
  `''` sans erreur : le compte, loin d'être bloqué, resterait libre. Et `''`
  ne bloque personne (`compte_bloque()` ne reconnaît que `'bloque'`).
- **Assumé** : un compte bloqué peut supprimer son compte et en ouvrir un
  autre avec une autre adresse — rien n'en empêche, le forfait part avec la
  ligne. Il n'y a pas de liste d'adresses interdites.

**L'administration, c'est une page, une colonne et trois actions** (demande de
l'utilisateur). `utilisateur.admin` ouvre `admin.php` : une colonne À PART du
forfait, parce qu'on peut être bloqué ET administrateur (le forfait dit ce qu'on
fait de sa propre bibliothèque, `admin` ce qu'on fait des autres comptes). Le
premier se pose à la main (`UPDATE utilisateur SET admin = 1 WHERE identifiant =
'…'`), les suivants depuis la page.
- **Le serveur est le seul juge, comme pour le forfait.** `api.php` refuse (403)
  toute action de `ACTIONS_ADMIN` à un compte qui n'est pas `admin`, AVANT le
  switch et après le CSRF ; la colonne est relue à chaque requête, donc un droit
  retiré s'éteint à l'appel suivant, sans attendre la fin de la session. La page,
  elle, répond **404** (`exiger_admin()`) : « interdit » confirmerait qu'elle existe.
  Ces actions sont dans `ACTIONS_LIBRES_DU_BLOQUE` (un administrateur bloqué garde
  la page) ; `tests/cas/admin_test.php` exige que chaque `case 'admin.*'` soit dans
  `ACTIONS_ADMIN` et inversement.
- **Changer le forfait envoie un e-mail** (`avis_forfait()`, textes dans
  `mailer.php`, tous des fonctions pures). Bloquer EXIGE une raison
  (`admin_raison_erreur()`), rangée dans `raison_blocage` avec la date
  (`bloque_le`), dite dans l'e-mail ET sur la bibliothèque et les Paramètres de la
  personne ; tout autre forfait l'efface. « Illimité » est un message pour des amis
  (la dynastie sylvestrique) : le ton est voulu. **Pas d'e-mail à une adresse
  jamais confirmée** (personne n'a prouvé qu'elle est à la bonne personne), et un
  envoi raté ne défait pas le forfait : il se DIT (`mail` : `envoye`, `echec`,
  `non_confirme`). Le forfait est relu dans la transaction qui l'écrit : hors mode
  strict, une valeur absente de l'`ENUM` serait rangée en `''` sans erreur.
- **Nommer ou révoquer un administrateur : jamais soi-même**
  (`admin_refus_droits()`), si bien qu'il en reste toujours un. Aucune
  confirmation d'identité ni e-mail d'avis pour l'instant : une session
  d'administrateur volée peut nommer quelqu'un — voir ce qui est proposé.
- **Supprimer un compte passe par ADMIN_EMAIL.** `admin.supprimer` n'efface RIEN :
  il crée un jeton `suppression_admin` (`ADMIN_SUPPRESSION_DUREE`, 1 h par défaut)
  et envoie le lien `admin.php?supprimer=…` à `ADMIN_EMAIL` ; sans envoi, le jeton
  est retiré. Le lien ouvre une page de confirmation, et **l'effacement n'a lieu
  qu'en POST** avec le jeton CSRF : un GET qui supprimait serait déclenché par
  n'importe quel antivirus de messagerie qui visite les adresses des courriers. La
  cible est relue avant d'être effacée (elle a pu devenir administrateur entre-temps).
  Pas son propre compte (par ses Paramètres), pas un administrateur (d'abord lui
  retirer ses droits). Le titulaire est prévenu APRÈS, s'il a une adresse confirmée.
- **La page ne fabrique aucun HTML à partir de données** : `js/admin.js` met à jour
  les lignes déjà rendues par PHP (`textContent`, attributs `data-`), et chaque
  valeur sort de PHP par `e()`. Un identifiant est du texte choisi par quelqu'un
  d'autre.

**Trente réglages se changent depuis `admin.php`, et la base prime sur le
`.env`** (demande de l'utilisateur : « les limites et les durées, pas de mot de
passe ni de connexion »). La table `reglage` ne porte que l'ÉCART : une ligne par
réglage changé, et « ↩ .env » l'efface. `config.php` ouvre la base AVANT ses
constantes (la connexion PDO est montée juste après `ADMIN_EMAIL`, qu'`erreur_fatale()`
affiche), lit la table, et `env()` rend la valeur de la base s'il y en a une,
sinon `env_brut()` (le `.env`). **Aucune constante n'a donc changé de forme** : la
valeur de la base passe par le MÊME `max(…, min(…))` que si elle venait du `.env`.
- **La liste est fermée et chaque clé a ses bornes** (`REGLAGES`, dans
  `includes/reglages.php`) : seules ces clés sont lues dans la base, une ligne
  tapée à la main en SQL est ramenée dans l'intervalle (ou ignorée si ce n'est pas
  un entier), et `reglage_valider()` refuse tout le reste à la saisie. Les bornes de
  la page sont TOUJOURS dans celles du code : `tests/cas/reglages_test.php` impose à
  chaque réglage sa borne basse puis haute dans un sous-processus et compare à ce que
  `config.php` en tire — une borne de la page que le code relèverait ferait croire à
  l'administrateur qu'il a réglé 5 et obtenir 8.
- **Ne sont PAS dans la liste, exprès** : les secrets et l'accès à la base (`DB_*`,
  `SMTP_*`, `CRON_TOKEN`, `UPLOAD_SECRET`, `GOOGLE_*`), `APP_URL`, **`ADMIN_EMAIL`**
  (la seconde clé de la suppression d'un compte : modifiable depuis la page, un intrus
  ne serait plus arrêté), les mots de passe (`MDP_MIN`, `MDP_MAX`), `CONFIRMATION_DUREE`
  et `CLE_ACCES_DEFI_DUREE`, ce qui protège l'IP du serveur face à MangaDex
  (`COUVERTURE_ESPACEMENT`, `_FILE_MAX`, `_TIMEOUT`), `IP_*`, `ASSETS_VERSION`, les
  images et l'import (retirés à la demande de l'utilisateur), les `LEGAL_*`. Un test
  le garde.
- **Les freins : 3 à 20 tentatives, 60 à 600 s** (demande de l'utilisateur) — sauf
  quatre dont le DÉFAUT dépasse 600 s : `LIMITEUR_FENETRE` (900), `LIMITEUR_BLOCAGE_MAX`
  (3 600), `LIMITEUR_OUBLI` (86 400, que `config.php` relève d'ailleurs à 3 600 au
  minimum) et `MDP_OUBLIE_BLOCAGE` (900). Les plafonner à 600 les aurait fait BAISSER
  sans que personne l'ait demandé (et `LIMITEUR_OUBLI` n'aurait pu descendre sous son
  propre plancher) : leur défaut est leur plafond. On peut les resserrer, pas les
  desserrer au-delà d'aujourd'hui.
- **`CRON_HEURES` : 1 à 168 h, `.env` compris** (demande de l'utilisateur ; il montait
  à 720). `RAPPORT_HEURES` garde 720. **Les bornes des autres réglages ne touchent
  que la page** : le `.env`, lui, garde les planchers qu'il avait.
- **Deux jeux de bornes, ne pas les confondre** : celles de `REGLAGES` valent pour ce
  qu'on saisit et ce qu'on relit de la base ; celles de `config.php` valent pour le
  `.env` ET pour ce qui vient de la base (la valeur y repasse). Quand le code déduit
  une valeur d'une autre (un quota « illimité » ne passe jamais sous le quota
  ordinaire, un rapport ne part pas plus souvent que le cron ne passe), la page montre
  ce qui a été SAISI et dit « En vigueur : X » (`reglage_vue()`).
- **Une table absente n'est pas une erreur** (`reglages_lire()`) : migration 12 pas
  rejouée, ou base « vide » des tests — le `.env` gouverne seul, sans bruit. Toute
  autre panne est consignée et ne coupe rien.
- **Pris en compte à la requête SUIVANTE, pour tout le monde** (config.php relit la
  table à chaque requête : une requête de plus, sans cache). `SESSION_DUREE` et
  `REMEMBER_DUREE_VIP` ne touchent que les connexions suivantes ; `CRON_HEURES` ne
  change pas l'espacement réglé chez OVH, seulement ce que le rapport en croit.
- **Les actions `admin.reglage` et `admin.reglage_retablir`** sont dans `ACTIONS_ADMIN`
  comme les autres. L'erreur d'une saisie va SOUS sa case (`champ: 'valeur'`) ;
  « ↩ .env » recharge la page — seule `config.php` sait quelle valeur est en vigueur —
  et le message attend le rechargement (`sessionStorage`) : un flash serait en haut
  de la page, loin des réglages.

**La signature du jeton Google n'est pas vérifiée, et c'est voulu** : il
arrive par un appel HTTPS direct du serveur à Google, jamais par le
navigateur (OpenID Connect Core § 3.1.3.7). Ne jamais désactiver
`CURLOPT_SSL_VERIFYPEER` dans `google_echanger_code()`, ni accepter un
`id_token` venu d'ailleurs : ce serait alors une faille.

**Les filtres mémorisés sont par compte** : `Lib.cleFiltres(id)`, avec
l'id posé en `data-compte` sur `<body>`. Une clé commune faisait ouvrir
à l'un sa bibliothèque sous le filtre laissé par l'autre.

**Une page qui montre un compte est en `no-store`** (posé par
`exiger_connexion()`) et porte `data-prive="1"` sur `<body>` : c'est ce
qui l'empêche de revenir du cache par « Précédent » après une
déconnexion (voir « pageshow » dans `js/commun.js`).

## Les trois freins, et pourquoi ils ne se ressemblent pas

Les confondre a déjà coûté cher. Ils ne protègent pas les mêmes choses.

| Mécanisme | Protège | Comportement |
|---|---|---|
| `limiteur_echec` / `tentative_ip` | les **comptes**, contre la force brute | Compte des **échecs**, double la peine à chaque récidive |
| `mangadex_attendre_son_tour()` | l'**adresse IP du serveur**, face à MangaDex | File d'attente (`flock`, 250 ms). Ne compte personne, ne sanctionne personne |
| `couverture_quota()` / `couverture_consommer()` | l'**équité entre comptes** | Règle fixe et annoncée : `COUVERTURE_QUOTA` recherches / 2 min (30), `COUVERTURE_QUOTA_ILLIMITE` en forfait `illimite` (120). **0 ou ligne absente = sans limite** : rien n'est décompté ni annoncé. Aucune escalade |

Le limiteur à peine doublante convient à des mots de passe essayés au
hasard. L'appliquer à l'usage normal d'une fonctionnalité revient à
punir quelqu'un qui s'en sert autant qu'elle le permet — ne pas
recommencer.

## Base de données

Neuf tables (`utilisateur.google_sub` relie un compte Google, `cle_acces` garde les clés
publiques des clés d'accès — jamais une clé privée) : `utilisateur`, `serie`, `jeton_action`,
`session_persistante`, `tentative_ip`, `cle_acces`,
`recherche_couverture`, `rapport_cron` (une seule ligne : la date du
dernier rapport du cron), `reglage` (une ligne par réglage changé depuis `admin.php`,
migration 12 : le `.env` reste la valeur de départ). `utilisateur.forfait` : `standard`, `illimite` ou
`bloque` (consultation seule, voir « Règles tacites »). `utilisateur.admin`
(administrateur, indépendant du forfait), `raison_blocage` et `bloque_le` (le motif
et la date d'un blocage) : migration 11. **`utilisateur_actuel()` les lit à chaque
requête : rejouer `livre.sql` AVANT d'envoyer le code**, sans quoi toutes les pages
tombent sur « colonne inconnue ». La table `reglage`, elle, n'est pas obligatoire : sans
elle, le `.env` gouverne seul et la carte « Réglages » dit de rejouer `livre.sql`.

`livre.sql` est **entièrement rejouable**. Pour mettre à jour une base
existante, on rejoue le fichier **en entier** en retirant seulement les
deux instructions `CREATE DATABASE` et `USE` du début (signalées dans le
fichier) : chez OVH la base est créée depuis le manager et le `USE`
échouerait, entraînant tout le reste avec lui.

**Hors mode strict, une valeur absente d'un `ENUM` ne fait aucune erreur** :
MySQL range `''`. Le lien « bloquer le changement d'adresse » est ainsi
parti mort en production (`blocage_email` inconnu de la base). Depuis,
`generer_jeton_action()` relit le type écrit et lève une exception s'il
diffère.

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

Copie de fichiers par FTP, rien d'autre. **Ce qui monte, c'est le contenu de
`site/`, sans `uploads/`** (les images des utilisateurs restent sur le
serveur), dans `www/`. Dans l'ordre :

1. **Le SQL d'abord**, si le schéma a bougé.
2. Les fichiers modifiés — et **tous ceux dont ils dépendent**. Les
   pannes du projet ont presque toutes été des envois partiels : envoyer
   tout `site/` règle la question.
3. **`ASSETS_VERSION` à incrémenter** dans le `.env` dès qu'un fichier de
   `js/` ou `css/` change. `actif()` s'en sert pour casser le cache ;
   sans l'incrément le correctif reste invisible, et on le croit raté.

**Il n'y a plus de ménage à faire après l'envoi** : `tests/`, `.git/`,
`*.md`, `livre.sql`, `.env`, `.env.example` et `.gitignore` sont à la racine
du dépôt, hors de `site/`, donc jamais envoyés. `tests/cas/structure_test.php`
refuse tout fichier de développement dans `site/` (un `.md`, un `.env`, un
dossier `tests` ou `.git`…) : c'est lui, et non plus le `.htaccess`, qui
garantit que rien d'inutile n'arrive en ligne.

**Le `.env` n'est plus une exception à surveiller.** Il vit AU-DESSUS de
`site/` — la racine du dépôt en local, au-dessus de `www/` chez OVH —
et `config.php` le cherche là en premier : un envoi de `site/` ne peut pas
écraser celui du serveur (base `localhost` contre base de production : le
site perdrait sa base à l'instant même). Ne jamais en poser un dans `site/`.
`.ovhconfig`, resté à la racine, ne monte pas non plus : il est posé une fois
sur le serveur, à renvoyer à part s'il change.

**Le `.htaccess` de `site/` est allégé** : il ne protège plus que ce qui
monte (fichiers « point », `includes/`, listing, HTTPS, cache, compression).
Il ne bloque donc **plus** `tests/`, `*.md` ni `*.sql`. Un reste d'un ancien
envoi complet (surtout `tests/`, dont les fichiers sont du PHP exécutable,
mais aussi `livre.sql`, `README.md`, `CLAUDE.md`) redevient lisible dès que ce
`.htaccess` est en ligne : **le supprimer du serveur avant, ou avec, le premier
envoi du nouveau dossier**, et le vérifier (`curl -I …/livre.sql` doit rendre
404).

**En local, XAMPP sert tout `htdocs/Livre`**, pas seulement `site/`. Le
`.htaccess` de la racine du dépôt (jamais envoyé) rend tout le reste
introuvable — `.env`, `tests/`, `.git/`, `livre.sql` — et renvoie l'adresse nue
vers `site/`. Sa cible de redirection est `%{REQUEST_URI}site/` : une cible
relative serait préfixée du chemin DISQUE du dossier, et la redirection partirait
vers `http://hôte/C:/xampp/…`.

Vérifié avec l'Apache de XAMPP, en instance jetable (`httpd.exe -f <conf>`
sur un autre port, `AllowOverride All`, `mod_rewrite`, `headers`, `expires`,
`deflate`, `alias`) : demander chaque élément par `curl` et comparer le code
de réponse. `includes/` répond 403 (le `Require` de son `.htaccess` passe
avant le `RedirectMatch`), `.env` et `tests/` 404, `uploads/*.php` 403, et
`app.js` sort en `gzip` avec `max-age=31536000`.

## Tests

Lanceur maison, **un processus par fichier de cas** — c'est ce qui permet
à un fichier de faire `putenv()` avant de charger `lanceur.php`, et donc
de tester une constante dans un autre état.

L'amorce neutralise l'environnement : `DB_NAME` pointe sur
`information_schema` (les tables du projet sont hors de portée) et les
réglages SMTP sont vidés (rien ne part). Elle charge le projet depuis
`site/` (`CHEMIN_SITE`) ; `CHEMIN_PROJET`, la racine du dépôt, ne sert qu'à
ce qui n'est pas en ligne (`livre.sql`, `tests/`). Un test qui lit une source
ou écrit dans `uploads/` passe par `CHEMIN_SITE`.

Le périmètre est celui des **fonctions pures** : rien qui exige la base
ou le réseau. `tests/LISEZMOI.md` tient la liste de ce qui est couvert,
de ce qui ne l'est pas, et pourquoi.

Le JavaScript se teste **dans un navigateur**, pas avec Node (que le
projet n'a pas) : `tests/js/banc.html` charge `commun.js` et `app.js`
tels quels (depuis `site/js/`), puis les fichiers de `tests/js/cas/`. `lancer.php` l'ouvre
dans Edge ou Chrome sans fenêtre et relit le compte rendu. **Un nouveau
fichier de cas s'ajoute à `banc.html`**, sinon `lancer.php` échoue
(`[OUBLIÉ]`). Le branchement sur la page (écouteurs, appels à l'API,
modales) reste hors de portée : il se vérifie à la main.

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
virtuel — et cliquer sur l'ÉTIQUETTE du champ focalisé (« Titre »)
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
réduit `height` avant d'envoyer `resize`. De même, un défilement doux
(`behavior: "smooth"`) ne progresse pas tant que la page est masquée
(`document.visibilityState` à `hidden`) : on croirait qu'il n'a pas eu
lieu. `montrerResultats()` (`js/app.js`, la fiche qui descend jusqu'aux
couvertures trouvées) se vérifie donc en faisant répondre
`window.matchMedia` « réduire les animations », ce qui défile d'un bond.

**Les grilles CSS étirent leurs lignes par défaut.** Avec un `max-height`
sur le conteneur, les lignes sont dimensionnées contre la hauteur
disponible et non contre leur contenu : l'élément devient plus court que
ce qu'il porte, et son `overflow: hidden` tranche le texte. Invisible sur
un écran large. D'où `align-content: start` sur `.cover-results`.

**`scrollY` ne bouge pas que sous le doigt.** Quand une hauteur change
au-dessus de ce qu'on regarde, Chrome et Firefox recalent le défilement
d'autant (« scroll anchoring ») pour que le contenu ne saute pas. Or les
cartes sont en `content-visibility: auto` avec une taille estimée
(340 px) qui n'est pas la vraie (451 px sur un écran de 1024) : en
remontant une page rechargée en cours de liste, chaque rangée dessinée
pour la première fois décale `scrollY` de +111 px, alors qu'on remonte.
Déplier un groupe de filtres (« Statut ▾ », « Image ▾ ») fait de même. Lu comme un geste, ce recalage cachait
les filtres au moment précis où on les voulait. `suivreDefilement()`
(`js/app.js`) ignore donc le mouvement de toute image où la hauteur de
`<main>` ou de la barre a changé.

**`content-visibility: auto` et WebKit.** Sur iPhone (tous les
navigateurs d'iOS sont WebKit), une carte insérée par le script restait
parfois un cadre vide : son contenu, jugé hors de l'écran à l'insertion,
n'était plus jamais dessiné — « la série a disparu ». `poserCarte()`
pose donc `.card-posee`, qui rend ces cartes en `content-visibility:
visible`. Les cartes du chargement gardent `auto`, c'est là qu'est le
gain. Non reproduit dans Chromium : à vérifier sur un vrai iPhone.

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
