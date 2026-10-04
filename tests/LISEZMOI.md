# Tests unitaires

Aucune dépendance : pas de Composer, pas de PHPUnit — le même parti pris
que le reste du projet. Le lanceur tient en deux fichiers.

```bash
php tests/lancer.php
```

Un seul groupe de fichiers, par exemple ceux qui parlent du mot de passe :

```bash
php tests/lancer.php mot_de_passe
```

Un seul fichier, directement (pratique pour déboguer) :

```bash
php tests/cas/carte_test.php
```

Le JavaScript seul (voir « Le JavaScript » plus bas) :

```bash
php tests/lancer.php javascript
```

Sous Windows, si `php` n'est pas dans le `PATH` :

```bash
C:\xampp\php\php.exe tests\lancer.php
```

Le script se termine avec un code de retour **0** si tout passe, **1**
sinon : de quoi le brancher un jour sur un crochet Git ou une
intégration continue.

---

## Ce qu'il faut avant de lancer

**MySQL doit tourner**, même si aucun de ces tests n'interroge la base.

`includes/config.php` ouvre une connexion PDO dès son inclusion, et une
connexion ratée y appelle `erreur_fatale()`, qui coupe le processus. Il
n'y a donc pas moyen de charger les fonctions du projet sans que la
connexion aboutisse. Si MySQL est arrêté, les tests le disent
explicitement au lieu d'afficher une page d'erreur HTML.

La connexion est ouverte, puis **jamais utilisée** : voir les garde-fous
ci-dessous.

---

## Les garde-fous

`tests/amorce.php` place le projet dans un état de test **avant** de le
charger. Trois choses ne peuvent pas arriver pendant un test :

| Garde-fou | Comment |
|---|---|
| Toucher à vos données | `DB_NAME` pointe sur `information_schema`. Les tables de l'application sont hors de portée : une écriture échouerait bruyamment |
| Envoyer un e-mail | `SMTP_HOST`, `SMTP_USER` et `SMTP_PASSWORD` sont vidés. `envoyer_email_smtp()` refuse de partir dès que l'un des trois manque |
| Polluer votre installation | Le journal et les sessions vont dans le dossier temporaire du système ; les quelques tests qui écrivent dans `uploads/` suppriment leur fichier derrière eux |

Cela repose sur une propriété de `charger_env()` : **une variable
d'environnement déjà définie n'est jamais écrasée par le `.env`**. Ce que
l'amorce pose par `putenv()` l'emporte donc sur votre configuration — et
ce qu'un fichier de cas pose avant d'inclure l'amorce l'emporte à son
tour.

---

## Un fichier de cas = un processus

Chaque fichier de `tests/cas/` est exécuté dans son **propre processus**.
Ce n'est pas de la prudence excessive : c'est ce qui rend plusieurs
scénarios vérifiables du tout.

- Les réglages du `.env` deviennent des **constantes** (`MDP_MIN`,
  `IP_ENTETE`, `ASSETS_VERSION`…). Une constante ne se redéfinit pas :
  vérifier qu'une `LIMITEUR_FENETRE` de 10 est bien relevée à 60 exige un
  processus dont l'environnement porte `LIMITEUR_FENETRE=10` dès le départ.
- `ip_client()` garde son résultat dans un `static` : le premier appel le
  fige pour toute la durée du processus. D'où un fichier par scénario
  d'adresse.
- `erreur_fatale()`, `reponse_json()` et `exiger_csrf()` se terminent par
  `exit`. Elles sont appelées par les scripts de `tests/outils/`, dont la
  sortie est ensuite examinée.

C'est aussi ce qui garantit qu'un test ne peut pas en polluer un autre
par une session, une globale ou un `$_SERVER` oublié.

---

## Un plantage ne passe jamais pour un succès

C'est la propriété la plus importante du lanceur, et elle n'est pas
gratuite : un fichier de tests qui s'effondre rend naturellement « 0 test,
0 échec », ce qui ressemble en tous points à un fichier sain.

Quatre filets, chacun pour un mode de défaillance différent :

| Ce qui arrive | Ce qui le repère |
|---|---|
| Une assertion cède | `test()` l'intercepte et la compte |
| Une exception non rattrapée | `lanceur.php` reprend la main sur le `set_exception_handler` de `config.php`, qui sinon afficherait une page d'erreur et couperait le processus en silence |
| Une erreur fatale de PHP | `error_get_last()`, relu à la fin du script |
| Le fichier s'arrête **proprement** mais avant sa dernière ligne — une fonction du projet appelée depuis un test a fait `exit` | Le drapeau posé par `executer-cas.php` une fois le fichier déroulé en entier |

À quoi s'ajoutent, côté `lancer.php` : l'absence totale de compte rendu
(le processus est mort avant même de charger le lanceur — base
injoignable, erreur de syntaxe) et un code de retour non nul alors que le
compte rendu n'annonce aucun échec.

Le dernier cas mérite un mot : c'est le seul qui ne laisse aucune trace.
Ni erreur, ni exception, juste des tests qui disparaissent sans que le
total ne le dise. C'est pour lui seul que `lancer.php` passe par
`executer-cas.php` au lieu d'appeler le fichier de cas directement.

Lancé à la main (`php tests/cas/x_test.php`), un fichier de cas n'a
personne pour poser ce drapeau : ce contrôle-là est alors désactivé, les
trois autres restent.

---

## Écrire un test

```php
<?php
declare(strict_types=1);

// Toute configuration particulière se pose ICI, avant l'amorce.
putenv('ASSETS_VERSION=42');

require __DIR__ . '/../lanceur.php';

groupe('actif() — ASSETS_VERSION renseigné');

test('le numéro de version est ajouté tel quel', function () {
    egale('css/style.css?v=42', actif('css/style.css'), 'la version vient du .env');
});
```

Le fichier doit s'appeler `tests/cas/quelquechose_test.php` pour être
découvert.

### Les assertions

| Assertion | Vérifie |
|---|---|
| `egale($attendu, $obtenu, $description)` | Égalité **stricte** (`===`) |
| `differe($interdit, $obtenu, $description)` | La valeur n'est pas celle-là |
| `vrai($valeur, $description)` · `faux(…)` | Exactement `true` / `false` |
| `contient($aiguille, $botte, $description)` | Sous-chaîne présente |
| `sans($aiguille, $botte, $description)` | Sous-chaîne absente — la forme des tests d'échappement |
| `motif($regex, $sujet, $description)` | Correspondance |
| `estNul($valeur, $description)` | Exactement `null` |
| `vide($tableau, $description)` | Tableau vide — « aucune erreur » |
| `leve($classe, $fn, $description)` | L'appel lève cette exception |

La description n'est pas décorative : c'est elle qui s'affiche quand
l'assertion cède, avec le fichier et la ligne.

---

## Le JavaScript

Pas de Node, pas plus que de Composer : le JavaScript se teste là où il
tourne, dans un navigateur. `tests/js/banc.html` charge le code du site
tel qu'il est servi (`js/commun.js`, `js/app.js`), puis les fichiers de
`tests/js/cas/`, et écrit son compte rendu dans la page.

- **Avec le reste** : `php tests/lancer.php` ouvre la page dans Edge,
  Chrome ou Chromium, sans fenêtre (`--headless --dump-dom`), avec un
  profil jetable et une limite de temps, puis relit le compte rendu.
  La variable `NAVIGATEUR` désigne un autre navigateur. Sans navigateur
  trouvé, les tests JavaScript sont annoncés **non lancés** — en tête et
  en fin de rapport — sans faire échouer la suite.
- **À la main** : ouvrir `tests/js/banc.html` d'un double-clic. Pas par
  le serveur web : `tests/` y est interdit, et c'est voulu.

Les assertions ont les mêmes noms qu'en PHP (`egale`, `vrai`, `faux`,
`contient`, `sans`, `estNul`, `differe`), et `terrain(html)` pose le HTML
dont un test a besoin dans un conteneur vidé avant chaque test.

```js
groupe("Lib.urlImageAcceptee()");

test("une adresse en http:// est refusée", () => {
  faux(Lib.urlImageAcceptee("http://exemple.test/a.png"), "http");
});
```

Un fichier de cas doit figurer dans `banc.html` : `lancer.php` refuse
d'en oublier un (`[OUBLIÉ]`). Les mêmes filets qu'en PHP valent ici — un
fichier chargé qui n'exécute aucun test (`[VIDE]` : introuvable, erreur
de syntaxe), une erreur hors de tout test, y compris au chargement du
code testé (`[ERREUR HORS TEST]`), un banc qui ne rend pas de compte
rendu ou qui dépasse le temps imparti (`[INTERROMPU]`).

Les fichiers de cas partagent la même page : deux constantes globales
du même nom dans deux fichiers s'y heurteraient.

**Ce qui se teste ainsi** : les fonctions qui ne dépendent que de leurs
arguments, et celles qui travaillent sur un bout de DOM qu'on peut leur
fabriquer. D'où `window.Bibliotheque` en tête d'`app.js` : la logique
pure de la page (carte voisine au clavier, suivi du défilement, textes
des quotas), séparée de son branchement. `app.js` ne branche rien hors
de la bibliothèque, ce qui permet au banc de le charger.

Une assertion qui échoue interrompt **son** test, et lui seul : les
suivantes porteraient sur un état déjà faux. Les autres tests du fichier
continuent.

### Utilitaires disponibles

- `journal_test()` — le contenu du journal (`error_log`) depuis le début
  du fichier ou le dernier vidage. C'est ainsi que sont vérifiés
  `journal_securite()` et les messages destinés à l'administrateur.
- `vider_journal_test()` — repart d'un journal vide.
- `executer_php($script, $arguments)` — lance un script en
  sous-processus et rend `['sortie' => …, 'code' => …]`.
- `CHEMIN_PROJET` — la racine du dépôt (`livre.sql`, `tests/`, `.env`).
- `CHEMIN_SITE` — `site/`, ce qui monte sur le serveur : les pages, `includes/`,
  `js/`, `css/`, `uploads/`. C'est là que les tests cherchent les fonctions et
  les sources, et que ceux qui écrivent des images les suppriment.

---

## Ce qui est couvert, et ce qui ne l'est pas

Le périmètre est celui des **fonctions pures** : rien qui exige la base
de données ni le réseau.

**Couvert** — `charger_env`, `env`, `ip_client`, `ip_interne`,
`ini_octets`, `plafond_pixels`, `taille_lisible`, `erreur_fatale`, `e`,
`texte`, `valider_mot_de_passe`, `jeton_csrf`, `csrf_valide`,
`exiger_csrf`, `post_trop_gros`, `message_post_trop_gros`,
`journal_securite`, `url_image_sure`, `type_image` (la provenance
d'une couverture, pour le filtre avancé), `photo_depuis_formulaire`,
`url_image_refusee` (une adresse saisie puis refusée, à distinguer de
« rien de saisi »), `champ_aria` et `champ_erreur` (l'erreur d'un
formulaire rattachée à son champ),
`initiales`, `cookie_persistant_params`, `actif`, `reponse_json`,
`cron_en_ligne_de_commande`, `cron_refus_navigateur`,
`cron_appelant_authentifie`, `cron_reglages`, `rapport_du`,
`rapport_fenetre_minutes` et `duree_lisible` (le rapport du cron : quand
il part, sur quelle période, et comment il l'affiche),
`enregistrer_image`, `enregistrer_image_depuis_donnees`, `gif_anime`,
`corriger_orientation`, `traiter_image`, `ecrire_image`, `url_publique`,
`envoyer_email`, `envoyer_email_smtp` (validation), `avertir_compte_supprime`
(câblage), `acces_compte` et les `avis_*` (un e-mail ne parle que du mot de
passe qui existe), `composer_message`, `message_mime`, `corps_html` et
`mots_encodes` (l'e-mail tel qu'il part : texte et HTML, liens vers le
site seulement, en-têtes encodés), `import_serie` et `import_complement`
(une ligne de sauvegarde, et ce que l'import rend à une série déjà
présente), `google_url_autorisation`, `pkce_defi`, `base64url`, `google_lire_id_token`, `google_verifier_revendications`, `google_decision` (comptes e-mail et Google séparés, mot de passe après Google), `google_etape_mdp`, `google_attente` et `google_attente_resume` (la saisie gardée pendant l'aller-retour chez Google), `forme_identifiant`, `forme_email`, `identifiant_depuis_google`, `confirmation_recente` et `confirmation_en_cours` (une identité prouvée : un compte, une action, une fois — e-mail et Google), `google_page_action`, `message_reconnexion_google`, `bouton_google` (la connexion avec Google, sauf l'échange du code qui appelle Google), `flash` et `flash_prendre` (le message qui ne revient pas au rechargement), `duree_cookie_lisible` et les mentions légales rendues selon `SESSION_DUREE` / `REMEMBER_DUREE_VIP`, `titre_normalise`, `mangadex_titre`,
`couverture_quota` et `couverture_quota_illimite` (0 ou réglage absent : aucun quota), `secondes_lisibles` (une durée annoncée : la tranche des recherches, le temps pour confirmer une action),
`mangadex_id_depuis_url` (le lien vers MangaDex, et les hôtes sosies
qu'il refuse), `couverture_tome_vise`, `mangadex_image_locale` (son
garde-fou avant tout réseau, seul le téléchargement lui-même ne l'est
pas — voir ci-dessous),
`mangadex_attente_suggeree`, `mangadex_attendre_son_tour` (la file
d'attente des appels sortants), `carte_html`, les
planchers de toutes les constantes — y compris `COUVERTURE_*` et le
défaut restrictif de `COUVERTURE_CONTENU_ADULTE` —, et les contrôles de forme qui
précèdent une requête (`valider_profil`, `jeton_action_valide`,
`verifier_session_persistante`, `supprimer_images_locales`).

**Couvert, clés d'accès** — `cle_acces_test.php` joue l'AUTHENTIFICATEUR :
vraies clés OpenSSL (EC P-256 et RSA 2048), vrais objets CBOR, vraies
signatures, puis demande au code de les accepter — et de refuser chaque
altération. `cbor_lire` / `cbor_decoder` (entiers, textes, tables, et tout
ce qu'il refuse : longueurs indéfinies, étiquettes, flottants, clés en
double, imbrication, tailles annoncées), `cle_lire_donnees_auth`,
`cle_cose_vers_pem` (courbe, longueur, point hors courbe, RSA trop court),
`cle_verifier_creation`, `cle_verifier_connexion` (défi, origine, adresse du
site, présence, signature d'une autre clé, message modifié, compteur),
`cle_compteur_valide`, `cle_rp_id`, `cle_origine`, `cle_defi_valide`,
`cle_defi_creer` / `cle_defi_prendre` (à usage unique), `cle_options_*`,
`cle_identifiant_utilisateur` (opaque), `cle_nom`, `bouton_cle_acces`,
`avis_cle_acces_ajoutee`, les réglages (`CLE_ACCES_*` : défauts, planchers,
plafonds). Côté navigateur : `CleAcces` (base64url, conversions des options
et des réponses, messages d'erreur).

**Couvert par lecture des sources** — `cle_acces_sources_test.php` : la table
`cle_acces` de `livre.sql`, `compte.cle_creer` dans les actions sensibles,
les trois actions d'`api.php` (confirmation exigée puis consommée, défi
pris d'abord, cloisonnement), `connexion-cle.php` (défi consommé, échecs
comptés à part, session par `connecter()`), l'ordre des scripts des
Paramètres, `cle_effacer_toutes()` à la réinitialisation, et que la demande
en arrière-plan de `js/cle-acces.js` ne relance pas en cas d'échec.
`tactile_test.php` : la règle
`* { touch-action: manipulation }` de `css/style.css` (le pincement reste
possible), le fait que chaque page HTML charge cette feuille ET
`js/double-appui.js` (un clic posé sur le document, qui ne fait rien : c'est
lui qui empêche Safari de zoomer au double-appui) et ne bloque pas le zoom. Un
garde-fou, pas une mesure du navigateur : que le double-appui ne zoome plus
ne se constate que sur un iPhone.

Le même fichier garde **tirer la page vers le bas pour l'actualiser** (`js/commun.js`) : le geste n'est branché
qu'en application installée sur écran tactile (jamais dans un onglet : double rechargement), l'écouteur du
mouvement est non passif et n'annule qu'un tirage reconnu, il ne part ni d'un champ, ni d'une fenêtre, ni d'une
zone défilée, le rond se pose en CSSOM, et `style.css` désactive le geste natif en mode installé. Les décisions
pures — `Lib.tirage` (course, plafond, seuil, valeurs absurdes) et `Lib.tirageDirection` (en attente, vers le
bas, vers le haut, de côté) — sont dans `tests/js/cas/commun_test.js`. Le branchement lui-même (écouteurs,
`location.reload()`) ne se teste pas dans le banc, qui n'est pas une application installée : il a été essayé à
la main avec de faux évènements tactiles, jamais sur un vrai téléphone.

**Couvert, administration** — `admin_test.php`, `mailer_admin_test.php`,
`tests/js/cas/admin_test.js`. Les décisions PURES de `includes/admin.php` :
`est_admin` (seul `1` ouvre la page, colonne absente = personne), un compte bloqué
ET administrateur, `admin_forfait_valide` (liste fermée, suit l'`ENUM`),
`admin_raison` (CRLF, lignes vides, caractères de contrôle, UTF-8 invalide) et
`admin_raison_erreur` (500 caractères, comptés en caractères), qui peut faire quoi à
qui (pas ses propres droits, pas sa propre suppression d'ici, pas un
administrateur), les dates « en jour », `admin_ligne`, `admin_pour_acteur`,
`admin_totaux`, les phrases de la page, la photo du compte (l'adresse affichée est celle que
`url_image_sure()` accepte, une adresse refusée — `http://`, `../`, `javascript:` — n'est
jamais affichée mais le compte reste « avec photo »). Les messages (`avis_compte_bloque`,
`avis_forfait_illimite`, `avis_forfait_standard`, `avis_suppression_a_confirmer`,
`avis_compte_supprime_par_admin`) : ce qu'ils disent, et que le motif d'un blocage
ou un identifiant ne devient jamais un lien ni une balise. Par lecture des sources :
chaque `case 'admin.*'` d'`api.php` est dans `ACTIONS_ADMIN` et inversement, la
garde passe après le CSRF et avant le switch, `utilisateur_actuel()` relit la
colonne, `admin.supprimer` n'efface rien et nettoie son jeton si l'e-mail ne part
pas, la page est fermée aux non-administrateurs avant tout accès à la base, la
suppression ne s'exécute qu'en POST sur une cible relue, le schéma neuf et la
migration 11 (rejouable, sans `IF NOT EXISTS`). Côté navigateur : `Admin.correspond`
(les mots qui tiennent lieu de filtres), `Admin.trier` (stable, à égalité l'ancienneté),
`Admin.sensApres`, `Admin.totaux`, `Admin.lireLigne` et les phrases des fenêtres.
Ce que fait vraiment la page (menus, fenêtres, e-mails réellement envoyés) ne se
lit pas dans les sources : il se vérifie en HTTP sur un site de test (voir
CLAUDE.md), et **un e-mail réel ne se vérifie qu'avec un vrai SMTP**.

**Couvert, réglages modifiables** — `reglages_test.php`, et ce que fait
`tests/outils/afficher-constantes-reglages.php` en sous-processus. La LISTE : ce qui y
est (les quinze freins en entier, les durées, les quotas, la couverture), ce qui n'y est
PAS (images et import, secrets, `ADMIN_EMAIL`, mots de passe…), les bornes validées par
l'utilisateur et les quatre défauts qui restent leur propre plafond. Qu'elle colle à
`config.php` : chaque clé est une constante lue par `env()`, son défaut annoncé est le
défaut du code, et **les bornes de la page tiennent dans celles du code** (le
sous-processus impose à chaque réglage sa borne basse, puis haute, et compare à la
constante). Les décisions pures : `reglage_valider` (entiers seuls, unités converties en
valeur NATIVE, menus, clés hors liste), `reglages_filtrer` (ce que la base porte, ramené
dans ses bornes ou ignoré), les unités (`reglage_vers_saisie`, `reglage_plage_texte`),
`reglage_source`, `reglage_vue` (ce qui est saisi, et « en vigueur » quand le code en
déduit autre chose), `env()` contre `env_brut()`. Le branchement : la base s'ouvre avant
la première constante réglable, `reglages.php` ne dépend de rien, une table absente se
tait, une autre panne est consignée, la migration 12. La mise en page de la carte : l'introduction sous le titre
et avant le premier groupe (avec sa marge), le titre de chaque groupe en vrai bouton `aria-expanded` relié à sa liste,
le chevron (bas ouvert, droite replié), la marge portée par le groupe et non par la liste, les lignes alternées
sans filet, et le branchement du repli (`localStorage` toujours dans un `try`) ; côté JavaScript,
`Admin.groupesReplies` et `Admin.basculerGroupe`. Ce qui change réellement à la
requête suivante (quota de séries, durée du cookie de session, inscriptions fermées,
freins) se vérifie en HTTP sur un site de test (CLAUDE.md, « Déploiement »).

**Couvert, politique de mot de passe réglable** — `mdp_regles_test.php` (en sous-processus, via
`tests/outils/valider-mdp.php`), `config_planchers_test.php`, `reglages_test.php`, `tests/js/cas/mdp_test.js`.
`MDP_MIN` de 1 à 200 (le plancher de 8 est levé : 0 et les négatifs donnent 1, 5000 donne 200, `MDP_MAX` reste
au-dessus) ; chaque classe (`MDP_MAJ`, `MDP_MINUSCULE`, `MDP_CHIFFRE`, `MDP_SPE`) se désactive SEULE et les trois
autres restent exigées ; tout désactivé, seule la longueur compte, et les espaces de bord, l'identifiant, le maximum
restent refusés ; **seul « 0 » desserre** (vide, « non », « false », « 2 » : exigée) ; le message cite le vrai minimum ;
`attributs_regles_mdp()` dit ce que le serveur applique, et les quatre pages l'appellent. Côté JavaScript :
`ReglesMdp.manques` avec chaque classe désactivée, `exigee` et `options` (un attribut absent laisse la règle). Dans
la liste : le groupe « Mots de passe », ses menus 0 / 1, `MDP_MAX` toujours absent.

**Couvert, la fiche d'une série** — `fiche_fond_test.php`, par lecture de `js/app.js` : un clic sur le fond sombre ne
la ferme pas (aucun écouteur), « ✕ », « Annuler » et Échap la ferment toujours, la question « Abandonner ? » est
inchangée. Non couvert (le branchement n'est pas joignable) : essayé à la main dans le navigateur.

**Couvert, structure du dépôt** — `structure_test.php`, par lecture des
sources : `site/` ne contient que ce qui doit monter (ni `tests/`, ni `.md`,
ni `.sql`, ni `.env`, ni dossier caché — le `.htaccess` de `site/` ne les
bloque plus, seule leur absence les garde hors ligne), chaque `require` et
chaque ressource chargée par `actif()` trouve son fichier, `config.php` prend
`site/` pour racine et cherche le `.env` au-dessus, `.gitignore` ignore
`site/uploads/*` sauf son `.htaccess`, les `.htaccess` (fichiers « point »,
`includes/`, exécution interdite dans `uploads/`, et celui de la racine pour
le développement local). Ce que les règles font réellement chez Apache ne se
lit pas dans les sources : il se vérifie avec un Apache jetable (voir
CLAUDE.md, « Déploiement »).

**Couvert, forfait « bloqué »** — `forfait_bloque_test.php` :
`compte_bloque` (seul `'bloque'` bloque : ni `''`, ni une autre casse, ni une
clé absente), `action_bloquee` (ce que l'utilisateur a demandé, ce qui s'y
ajoute, et ce qui reste ouvert : export, compte, sécurité, sortie),
`message_compte_bloque`, `carte_html($s, true)` (aucun bouton ni
`data-action`, la lecture et les attributs des filtres intacts, l'étoile fixe,
l'échappement). Et, par lecture des sources : **chaque `case` d'`api.php` est
classé** (bloqué, ou libre pour une raison dite — une action ajoutée sans
décision fait échouer le test), la garde passe après le CSRF et avant le
switch, les pages cessent de proposer ce qui est refusé, `livre.sql` porte la
valeur et sa migration rejouable, le rapport du cron compte les comptes
bloqués.

**Couvert, nouveaux tomes** — `nouveautes_test.php`, `carte_a_venir_test.php`,
`push_test.php`, `tests/js/cas/push_test.js`, `tests/js/cas/sw_test.js`, et les planchers/plafonds de
`NOUVEAUTE_*` et `PUSH_*`
(`config_planchers_test.php`, `cle_acces_plafonds_test.php` ; `NOUVEAUTE_MAX_VISITE` et
`_MAX_CRON` n'ont ni plancher ni plafond : 0 = sans limite, et `nouveautes_limite_sql()`). Les décisions
PURES de `includes/nouveautes.php` : `fin_de_serie` (terminée, à venir, en
route, inconnu — le dernier volume DÉCLARÉ compte autant que la dernière
couverture), `nouveaute_evaluer` (annoncer, apprendre sans annoncer, ne jamais
reculer), les messages, `nouveautes_par_compte`. La lecture des réponses de
MangaDex, sur des réponses fabriquées : `mangadex_dernier_tome_depuis` (volumes
décimaux, comparés comme des nombres, préférence de langue, plafond),
`mangadex_statut_depuis`, `mangadex_id_valide`. `serie_a_venir` (le statut « En attente », et rien d'autre),
`serie_fin_etiquette` (« Tome N en attente », « En pause au tome N », « Se termine au tome N »,
« Arrêtée au tome N », même au tome 2 et pour tout statut ; « MangaDex s'arrête au tome X » quand MangaDex est en
retard sur le lecteur ; l'état sans numéro quand aucun tome n'est connu ; rien quand on ne sait pas), `serie_au_bout`, `statut_apres_avance` (« → » au-delà de MangaDex), `statut_apres_recul` (« ← » depuis « En attente » ou
« Terminée » : « En cours » seulement en quittant le dernier tome connu), `publication_connue`,
la mention dans `carte_html`, son « ⓘ » (`serie_fin_info` : seules les quatre mentions sans numéro de tome
ont une explication, courte (≤ 100 caractères), qui dit qui parle, les autres n'en ont pas ni de « ⓘ » ; le texte
de la bulle dans `data-aide`, aucune explication dans la carte, la bulle unique de `index.php` ; `Bibliotheque.placerBulle` :
à droite, au-dessus, au-dessous, jamais hors de la fenêtre, jamais NaN ; ses fermetures et son maintien quand le relevé
refait une autre carte, lus dans les sources de `js/app.js` ; un clic sur la bulle qui ne la ferme pas, qu'il finisse
dedans ou dehors (copier-coller : `gesteParti`, `relatedTarget`, `cursor: text`) ; le balisage accessible ; le
câblage dans `js/app.js`), le compteur du filtre, la couleur, les
migrations 15 et 16, la
carte INCHANGÉE dans tous les autres cas.
Celui qui n'est pas au bout des tomes n'est jamais prévenu. Et, par lecture des sources, ce qu'un essai réel avait
fait voir : chaque colonne que le relevé lit est dans sa requête, `maj_le =
maj_le` pour tout ce qui n'est pas une modification, les écritures gardées
contre un changement en cours d'appel, la session libérée avant d'attendre
MangaDex, le cron sans `fonctions.php`, la migration 13 rejouable.

**Couvert, bibliothèque par pages** — `series_pages_test.php` (bornes de `SERIES_PAGES_MAX` face à un
`.env` hostile, branchement `index.php` / `js/app.js` / administration / `.env.example`) et
`Bibliotheque.pagesSeries()` dans `tests/js/cas/bibliotheque_test.js` (taille de page, reste, pluriel,
nombres absurdes). Le branchement sur la page (recherche, filtres, « Afficher plus » à l'écran) se vérifie
à la main : le banc ne charge pas la grille.

**Couvert, le total et le tri** — `carte_tri_test.php` et `Bibliotheque.triValide()`, `comparerTri()`, `statistiques()` dans
`tests/js/cas/bibliotheque_test.js`. `serie_restants()` (le plus haut tome connu moins le tome lu ; `null` quand on ne
sait pas — pas liée, ou lue au-delà de MangaDex —, jamais 0 ni négatif), `serie_restants_texte()`, `statistiques_series()` et
son miroir JavaScript sur les mêmes cas (0 et 1 au singulier), `data-restants` et « il en reste N » dans `carte_html()`,
les valeurs du menu `#tri` de `index.php` comparées à `Bibliotheque.TRIS`. Côté navigateur : les cinq tris sur des
séries à jour, inconnues, ex aequo ; **l'inconnu passe toujours après, dans les deux sens** ; l'antisymétrie. Le branchement
(menu, mémoire, recherche combinée, total qui bouge au « → ») s'est vérifié à la main dans le navigateur.

**Couvert, notifications push** — `push_test.php`, `tests/js/cas/push_test.js`,
`tests/js/cas/sw_test.js`. Le chiffrement contre l'exemple de la RFC 8291
(octet pour octet), un aller-retour avec un déchiffrement écrit à part, un message
modifié ou lu avec une autre clé refusé ; la signature VAPID relue avec la clé publique ;
la garde contre le SSRF (`push_endpoint_valide` : une trentaine d'adresses refusées, dont
les métadonnées d'un hébergeur et les noms qui ressemblent à un service) ; les clés d'un
abonnement ; les messages ; `outils/vapid.php` (la paire est cohérente). Côté navigateur : le
diagnostic (iPhone hors de l'écran d'accueil, autorisation refusée…), les conversions de clés, et
le service worker lui-même, joué avec de faux évènements. Par lecture des sources : la clé privée
n'est écrite nulle part, un seul `subscribe()` dans `activerPush()`, le plafond dans l'`INSERT`.

**Non couvert, notifications push** — un vrai service de notification et un vrai navigateur
qui reçoit un vrai message ; `push_enregistrer`, `push_retirer`, `push_envoyer_a_compte`,
qui écrivent en base (vérifiés avec une base jetable et un faux service) ; le branchement de
`settings.js` (service worker, autorisation, abonnement), qui se vérifie à la main — le
navigateur intégré ne sait enregistrer aucun service worker.

**Non couvert, faute de réseau** — `mangadex_get`, `mangadex_dernier_tome`,
`mangadex_statut_serie`, `chercher_couvertures` et le téléchargement fait par
`mangadex_image_locale`, qui interrogent tous MangaDex. De même
`nouveautes_fin_de_serie`, `nouveautes_verifier_serie` et `nouveautes_verifier` :
elles lisent MangaDex ET écrivent en base (à vérifier avec une base jetable et
le vrai service, comme décrit dans CLAUDE.md). Le classement des résultats de
recherche, lui, repose sur `titre_normalise`, qui est testé : c'est la
partie qui décide quelle série remonte en tête.

**Non couvert, faute de base de données** — le limiteur anti force brute
(`limiteur_echec`, `limiteur_bloque_depuis`, le doublement des durées, la
fenêtre glissante), les sessions persistantes (`creer_session_persistante`,
la rotation des jetons, le plafond d'appareils), les jetons d'action
(`generer_jeton_action`, `consommer_jeton_action`), les comptes
(`utilisateur_actuel`, `connecter`, `invalider_sessions`, `cle_ajouter`, `cle_trouver`, `cle_supprimer` (la table `cle_acces`),
`email_disponible`, `changement_email_en_attente`), `couverture_consommer`,
`couverture_rendre` et `couverture_restantes` (le quota de recherche par
compte : le barème est testé, le comptage non), la lecture de
`compter_series` (son comptage, `compter_lignes`, est testé), l'import d'une sauvegarde (ce qui distingue une série déjà présente), le cloisonnement par
`utilisateur_id` d'`api.php` et le contenu chiffré du rapport de
`purger.php` et la date de son dernier envoi (`rapport_etat`,
`rapport_noter_envoi` : ses requêtes. Quand il part et sur quelle
période, lui, est testé).

Ces fonctions-là demandent une base de test dédiée, remise à zéro entre
chaque test — c'est un autre chantier, et il vaut la peine : c'est là que
se trouve le gros de la logique de sécurité.

**Couvert, côté navigateur** — `Lib.erreurChamp`, `effacerErreur`,
`effacerErreurs` et `erreursSurChamps` (l'erreur sous son champ, et
effacée dès qu'on le corrige), `Lib.urlImageAcceptee`, `Lib.dureeToast`,
`Bibliotheque.voisine` (les flèches dans la grille),
`Bibliotheque.suiviDefilement` (les filtres qui s'effacent et reviennent,
rebonds et recalages du navigateur compris), `Bibliotheque.annonceQuota`
et `texteQuotaRecherche`, `Bibliotheque.ficheModifiee` et `signatureCouverture` (Échap ne jette plus une fiche modifiée), `Bibliotheque.toucheSuppression` (Suppr sur la carte sélectionnée, pas un raccourci) et `champDeSaisie` (dans la fiche, les champs où la touche efface du texte au lieu de supprimer la série), `Bibliotheque.panneauApres`, `panneauMemorise` et `aucunFiltre` (le groupe de filtres déplié, un seul à la fois, sa mémoire d'avant comprise, et le moment où « Toutes » s'allume), `Bibliotheque.bordsDefilement` (de quel côté une rangée de filtres a de la suite, pour le fondu ; le `ResizeObserver` et le `mask-image` qui l'appliquent se vérifient à la main), `Lib.piegerFocus` (l'œil du mot de passe n'est pas un arrêt), `Lib.cleFiltres` et `oublierFiltres` (les filtres par compte), `Parametres.texteSuppressionCompte`, `ReglesMdp.manques` et `brancher` (les règles du mot de passe non respectées, et quand les dire — mêmes messages que `valider_mot_de_passe`).

**Non couvert, côté navigateur** — le vrai `navigator.credentials` et les
gestionnaires de mots de passe (Bitwarden, 1Password, iCloud…) : seul un
authentificateur simulé a servi. Le branchement sur la page : les
écouteurs d'`app.js` et de `settings.js`, les appels à l'API, les
modales, le contrôle de session au retour arrière. Ils demandent une
vraie bibliothèque et un compte connecté : ils se vérifient à la main.
