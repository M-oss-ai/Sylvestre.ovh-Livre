<?php
/* =====================================================================
   Fonctions communes : sécurité, session, échappement HTML, CSRF,
   authentification, gestion des images.
   Ce fichier est inclus en tout premier par chaque page.
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/images.php';
require_once __DIR__ . '/mailer.php';

/* ---------------------------------------------------------------------
   1. En-têtes de sécurité
   --------------------------------------------------------------------- */

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('X-XSS-Protection: 0'); // filtre obsolète et dangereux, la CSP fait le travail
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');

/* Aucune page n'est publique : « private » interdit tout cache partagé
   (proxy d'entreprise, cache FAI), « no-cache » impose une revalidation.

   C'est le réglage des pages sans compte (connexion, mentions…). Celles
   qui montrent un compte passent à « no-store » (voir exiger_connexion) :
   sur un ordinateur partagé, « Précédent » réaffichait le profil depuis
   le cache même après la déconnexion ou la suppression du compte. L'API
   garde « no-store » elle aussi (voir reponse_json). */
header('Cache-Control: private, no-cache, must-revalidate');

/* Content-Security-Policy : aucun script ni style « inline » n'est autorisé.
   Même si une injection HTML passait à travers les mailles, le navigateur
   refuserait d'exécuter le script injecté.
   - img-src https: → les couvertures peuvent venir d'un site externe
   - img-src blob: → l'aperçu d'un fichier tout juste choisi (avant envoi)
     est affiché via URL.createObjectURL(), qui produit une URL blob:
   - connect-src 'self' uniquement : la recherche automatique de
     couverture passe désormais par notre propre api.php, qui interroge
     MangaDex depuis le serveur. Le navigateur ne s'adresse plus à aucun
     tiers, et l'adresse IP du visiteur n'est plus communiquée. */
header(
    "Content-Security-Policy: "
    . "default-src 'self'; "
    . "img-src 'self' data: blob: https:; "
    . "style-src 'self'; "
    . "script-src 'self'; "
    . "connect-src 'self'; "
    . "form-action 'self'; "
    . "base-uri 'none'; "
    . "object-src 'none'; "
    . "frame-ancestors 'none'"
);

/* ---------------------------------------------------------------------
   2. Session
   --------------------------------------------------------------------- */

/**
 * Délai pendant lequel un jeton d'appareil qui vient d'être remplacé
 * reste accepté. Sans ce sursis, deux requêtes simultanées (deux onglets,
 * un préchargement) se disputent le jeton : la première le consomme, la
 * seconde ne le trouve plus et déconnecte l'utilisateur.
 *
 * ⚠️ Déclarée ICI et pas à côté de la fonction qui s'en sert : un « const »
 * au niveau du fichier n'existe qu'une fois la ligne exécutée, alors que
 * les fonctions, elles, sont utilisables avant leur définition. Déclarée
 * plus bas, elle était encore inconnue au moment de l'appel ci-dessous —
 * et toute reconnexion automatique finissait en erreur 500.
 */
const REMEMBER_SURSIS = 60; // secondes

/** Refus d'une adresse d'image (voir url_image_refusee), partout le même. */
const MESSAGE_URL_IMAGE_REFUSEE = "Seules les adresses d'image en https:// sont acceptées.";

if (session_status() === PHP_SESSION_NONE) {
    /* use_strict_mode : PHP refuse un identifiant de session qu'il n'a pas
       lui-même émis. Sans ça, un attaquant peut déposer un cookie de
       session de son choix sur le navigateur de la victime (via un
       sous-domaine, un point d'accès Wi-Fi…) puis réutiliser cet
       identifiant une fois la victime connectée. PHP laisse cette option
       à 0 par défaut. */
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    /* PHP envoie ses propres en-têtes de cache au session_start(), et ils
       ÉCRASENT ceux posés plus haut : le « Cache-Control » de ce fichier
       n'arrivait jamais au navigateur. On coupe ce mécanisme pour garder
       la main (voir l'en-tête plus haut, réémis juste après). */
    session_cache_limiter('');

    session_set_cookie_params([
        'lifetime' => SESSION_DUREE,
        'path'     => '/',
        'httponly' => true,   // inaccessible au JavaScript → vol de session impossible via XSS
        'samesite' => 'Lax',  // le cookie n'est pas envoyé depuis un autre site → anti-CSRF
        'secure'   => !empty($_SERVER['HTTPS']), // normalisé dans config.php (X-Forwarded-Proto)
    ]);
    session_name('LIVRE_SESSION');
    session_start();

    // Réémis après session_start(), par sécurité : c'est le seul endroit
    // où PHP pourrait encore vouloir dire son mot sur le cache.
    header('Cache-Control: private, no-cache, must-revalidate');
    header_remove('Pragma');
    header_remove('Expires');
}

verifier_session_persistante();

/* ---------------------------------------------------------------------
   3. Échappement — la protection contre les injections HTML / XSS
   --------------------------------------------------------------------- */

/**
 * e() = « echo sûr ». Toute donnée venant de l'utilisateur ou de la base
 * passe par ici avant d'être écrite dans du HTML.
 * ENT_QUOTES neutralise aussi bien " que ' (attributs), ENT_SUBSTITUTE
 * évite qu'un octet UTF-8 invalide ne vide la chaîne silencieusement.
 */
function e(?string $valeur): string
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Nettoie une saisie d'une seule ligne : les blancs de mise en forme
    (tabulation, retours à la ligne) deviennent un espace, les autres
    caractères de contrôle disparaissent, puis trim et longueur bornée. */
function texte(mixed $valeur, int $max = 190): string
{
    $s = is_string($valeur) ? $valeur : '';

    /* preg_replace() avec le drapeau /u renvoie null — et non la chaîne —
       si l'entrée n'est pas de l'UTF-8 valide. Le « ?? '' » qui suit
       transformerait alors un titre légitime en chaîne vide, sans rien
       signaler. On répare l'encodage d'abord : mb_convert_encoding()
       remplace les octets invalides au lieu de tout jeter. */
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }

    /* Tabulation, retour chariot et saut de ligne : remplacés par UN
       espace, pas supprimés. Tous les champs qui passent par ici sont sur
       une seule ligne (titre, auteur, prénom, nom, identifiant, adresse) ;
       un texte collé depuis deux lignes doit donner « Ligne1 Ligne2 » et
       non « Ligne1Ligne2 », qui soude deux mots sans prévenir.

       Les laisser passer, comme c'était le cas, n'ouvrait aucune faille —
       journal_securite() neutralise déjà les retours à la ligne, et
       envoyer_email_smtp() refuse un destinataire ou un sujet qui en
       contient. Mais un titre de série pouvait en contenir, alors que la
       fonction annonce le contraire, et personne n'aurait eu de raison
       d'aller vérifier. */
    $s = preg_replace('/[\x09\x0A\x0D]+/u', ' ', $s) ?? '';

    /* Les autres caractères de contrôle n'ont, eux, aucune représentation
       à l'écran : un octet nul ou un échappement ANSI glissé dans un titre
       finit en base, puis dans le journal, puis dans un e-mail. */
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? '';

    $s = trim($s);
    if (mb_strlen($s, 'UTF-8') > $max) {
        $s = mb_substr($s, 0, $max, 'UTF-8');
    }
    return $s;
}

/* ---------------------------------------------------------------------
   3bis. Politique de mot de passe
   --------------------------------------------------------------------- */

/** Rappel affiché sous les champs « nouveau mot de passe ». Construit à
    partir de MDP_MIN : le texte ne peut donc pas mentir sur la règle
    réellement appliquée, quoi qu'on mette dans le .env. */
define('MDP_REGLE', MDP_MIN . ' caractères minimum, avec au moins une majuscule, une minuscule, '
                  . 'un chiffre et un caractère spécial. Les espaces ne comptent pour rien : '
                  . 'ni dans la longueur, ni comme caractère spécial.');

/**
 * Vérifie qu'un mot de passe respecte la politique du site et retourne la
 * liste des manquements — tableau vide = accepté.
 *
 * Une seule définition pour les quatre endroits qui créent ou changent un
 * mot de passe (inscription, paramètres avec et sans JavaScript,
 * réinitialisation). Auparavant chacun avait sa propre copie des règles,
 * et une correction faite à un endroit n'atteignait pas les autres.
 *
 * Les classes de caractères sont testées en Unicode (\p{Lu} plutôt que
 * [A-Z]) : « É » compte comme une majuscule, « à » comme une minuscule.
 * Refuser les accents à des utilisateurs francophones n'aurait aucun sens.
 */
function valider_mot_de_passe(string $mdp, string $identifiant = ''): array
{
    // preg_match avec /u renvoie false sur de l'UTF-8 invalide : sans ce
    // garde-fou, toutes les vérifications ci-dessous passeraient à côté.
    if (!mb_check_encoding($mdp, 'UTF-8')) {
        return ['Le mot de passe contient des caractères non reconnus.'];
    }

    $erreurs = [];

    /* Le minimum se compte SANS les espaces. Sinon « Aa1! » suivi de
       quatre espaces fait huit caractères et passe la règle, alors qu'il
       n'y a que quatre caractères utiles : le bourrage à l'espace vide le
       « 8 caractères minimum » de son sens. Le maximum, lui, compte tout —
       c'est une borne technique (coût du hachage), pas une exigence. */
    $utiles = mb_strlen(preg_replace('/[\p{Z}\s]/u', '', $mdp) ?? '', 'UTF-8');

    if ($utiles < MDP_MIN) {
        $erreurs[] = 'Le mot de passe doit faire au moins ' . MDP_MIN
                   . ' caractères, espaces non comptés.';
    }
    if (mb_strlen($mdp, 'UTF-8') > MDP_MAX) {
        $erreurs[] = 'Le mot de passe est trop long (' . MDP_MAX . ' caractères maximum).';
    }

    /* Espaces en début ou en fin : refusés, pas retirés en silence.
       Un clavier de téléphone en ajoute un après l'autocomplétion, un
       copier-coller depuis un tableur aussi, et certains gestionnaires
       de mots de passe les rognent à l'enregistrement mais pas à la
       saisie. L'utilisateur se retrouve alors avec un mot de passe qu'il
       ne parvient plus à retaper, sans comprendre pourquoi. Le refuser
       maintenant, c'est une seconde de gêne ; le retirer en douce, c'est
       enregistrer autre chose que ce qui a été tapé. */
    if ($mdp !== '' && preg_match('/^[\p{Z}\s]|[\p{Z}\s]$/u', $mdp)) {
        $erreurs[] = 'Le mot de passe ne doit pas commencer ni se terminer par un espace.';
    }
    if (!preg_match('/\p{Lu}/u', $mdp)) {
        $erreurs[] = 'Le mot de passe doit contenir au moins une majuscule.';
    }
    if (!preg_match('/\p{Ll}/u', $mdp)) {
        $erreurs[] = 'Le mot de passe doit contenir au moins une minuscule.';
    }
    if (!preg_match('/\p{Nd}/u', $mdp)) {
        $erreurs[] = 'Le mot de passe doit contenir au moins un chiffre.';
    }
    /* « Caractère spécial » = ni lettre (\p{L}), ni chiffre (\p{N}), ni
       espace. Les espaces sont exclus en deux temps : \s couvre l'espace
       ordinaire, la tabulation et les retours à la ligne ; \p{Z} couvre
       les espaces Unicode — insécable, cadratin, idéographique — qui sont
       invisibles à l'écran et se glissent facilement dans un copier-coller.
       Sans \p{Z}, coller un espace insécable suffirait à satisfaire la
       règle sans qu'aucun caractère spécial ne soit réellement saisi. */
    if (!preg_match('/[^\p{L}\p{N}\p{Z}\s]/u', $mdp)) {
        $erreurs[] = 'Le mot de passe doit contenir au moins un caractère spécial '
                   . "(par exemple ! ? * - _ #) — l'espace ne compte pas.";
    }

    /* Un mot de passe bâti sur l'identifiant est la première chose que
       teste quiconque s'en prend à un compte précis : « lecteur92 » donne
       « Lecteur92! », qui coche pourtant toutes les cases ci-dessus. */
    $identifiant = trim($identifiant);
    if ($identifiant !== '' && mb_strlen($identifiant, 'UTF-8') >= 3
        && mb_stripos($mdp, $identifiant, 0, 'UTF-8') !== false) {
        $erreurs[] = "Le mot de passe ne doit pas contenir votre identifiant.";
    }

    return $erreurs;
}

/* ---------------------------------------------------------------------
   4. Jeton CSRF
   --------------------------------------------------------------------- */

function jeton_csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valide(mixed $jeton): bool
{
    return is_string($jeton)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $jeton);
}

/** Bloque net toute requête POST sans jeton valide. */
/**
 * Le corps de la requête a-t-il été jeté par PHP parce qu'il dépassait
 * « post_max_size » ?
 *
 * Dans ce cas PHP vide $_POST ET $_FILES, mais laisse la requête arriver.
 * Le contrôle CSRF échoue alors faute de jeton, et l'utilisateur qui a
 * simplement choisi un fichier trop gros lit « jeton de sécurité
 * invalide » : un message sans rapport, qu'aucun rechargement de page ne
 * corrigera, et qui le laisse sans solution.
 */
function post_trop_gros(): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !empty($_POST) || !empty($_FILES)) {
        return false;
    }
    $max = ini_octets((string) ini_get('post_max_size'));
    return $max > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $max;
}

/** Message expliquant ce refus, partagé par les formulaires et l'API. */
function message_post_trop_gros(): string
{
    return "L'envoi est trop volumineux (" . taille_lisible(ini_octets((string) ini_get('post_max_size')))
         . ' au total). Choisissez une image plus légère : ' . taille_lisible(IMAGE_TAILLE_MAX) . ' maximum.';
}

function exiger_csrf(): void
{
    if (post_trop_gros()) {
        http_response_code(413);
        exit(message_post_trop_gros());
    }
    if (!csrf_valide($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Requête refusée (jeton de sécurité invalide). Rechargez la page.');
    }
}

/* ---------------------------------------------------------------------
   4bis. Limiteur de tentatives par IP (connexion, inscription…)
   Stocké en base (table tentative_ip), donc impossible à contourner en
   effaçant les cookies/la session — contrairement à un compteur en
   session, qui repart à zéro à chaque nouvelle session.
   --------------------------------------------------------------------- */

/* ip_client() vit dans config.php, à côté de IP_ENTETE dont elle
   dépend : purger.php a besoin d'elle sans charger tout ce fichier. */

/**
 * Consigne un évènement de sécurité dans le journal de l'hébergeur.
 *
 * Sans cette trace, le jour où un compte est compromis, il n'y a
 * strictement aucun moyen de reconstituer ce qui s'est passé. On note
 * quoi, qui et d'où — jamais le mot de passe ni le jeton concerné.
 */
function journal_securite(string $evenement, array $details = []): void
{
    $morceaux = ['ip=' . ip_client()];

    /* Signal d'alarme : PHP voit une adresse de relais interne, et aucun
       en-tête n'est configuré pour retrouver celle du visiteur. Tous les
       visiteurs partagent alors UN SEUL compteur de tentatives — cinq
       mauvais mots de passe bloquent la connexion pour tout le site.
       Noté ici plutôt qu'à chaque requête : les évènements de sécurité
       sont rares, et c'est ce journal qu'on lit le jour où ça cloche. */
    if (IP_ENTETE === '' && ip_interne((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
        $morceaux[] = 'ATTENTION=adresse_interne__renseignez_IP_ENTETE_dans_le_.env';
    }

    foreach ($details as $cle => $valeur) {
        $morceaux[] = $cle . '=' . str_replace(["\r", "\n"], ' ', (string) $valeur);
    }
    error_log('[securite] ' . $evenement . ' ' . implode(' ', $morceaux));
}

/* LIMITEUR_FENETRE, LIMITEUR_BLOCAGE_MAX et LIMITEUR_OUBLI sont définies
   dans config.php, à partir du .env. */

/**
 * Secondes restantes avant déblocage pour $action, ou 0 si non bloqué.
 *
 * $cle permet de compter sur autre chose que l'IP — en pratique sur un
 * COMPTE (« compte:42 »). Voir limiteur_echec() pour la raison.
 */
function limiteur_bloque_depuis(string $action, ?string $cle = null): int
{
    global $pdo;
    $req = $pdo->prepare('SELECT bloque_jusqu FROM tentative_ip WHERE ip = ? AND action = ?');
    $req->execute([$cle ?? ip_client(), $action]);
    $jusqu = $req->fetchColumn();
    if (!$jusqu) {
        return 0;
    }
    $reste = strtotime((string) $jusqu) - time();
    return $reste > 0 ? $reste : 0;
}

/**
 * Enregistre une tentative échouée ; bloque $action pour cette IP après
 * $max_essais. Un échec plus ancien que LIMITEUR_FENETRE ne compte plus
 * (fenêtre glissante) — sans ça, les vieux échecs traîneraient sans fin.
 *
 * $duree_base est la durée du PREMIER blocage, pas la fenêtre de comptage :
 * confondre les deux rendait le limiteur contournable en espaçant les
 * tentatives (voir LIMITEUR_FENETRE).
 *
 * La durée DOUBLE à chaque blocage successif (1 min, 2, 4, 8… plafonnée à
 * 1 h). Un blocage fixe de 60 s laissait 7 200 essais par jour et par IP,
 * soit une attaque par dictionnaire parfaitement viable ; avec le doublement,
 * une IP acharnée s'auto-exclut en quelques minutes, tandis qu'un
 * utilisateur qui se trompe une fois ne subit toujours qu'une minute.
 *
 * $cle remplace l'IP comme unité de comptage, ce qui permet de compter
 * aussi par compte (« compte:42 ») : un limiteur purement par IP ne voit
 * rien d'une attaque distribuée, une tentative par machine sur mille
 * machines ne déclenchant aucun compteur.
 *
 * Aucune remise à zéro sur un succès, même sur le compte visé : quiconque
 * possède un compte valide pourrait sinon se fabriquer un reset à volonté
 * (un échec exprès sur son propre compte juste avant d'y réussir) pour
 * effacer des tentatives faites au même moment sur un AUTRE compte depuis
 * la même IP. Seule la fenêtre glissante ci-dessus fait redescendre le
 * compteur, avec le temps.
 */
function limiteur_echec(string $action, int $max_essais, int $duree_base, ?string $cle = null): void
{
    global $pdo;
    $ip = $cle ?? ip_client();

    /* La fenêtre de comptage ne peut pas être plus courte que la durée du
       blocage : le compteur s'effacerait pendant que l'utilisateur purge
       sa peine, et il repartirait de zéro au déblocage. */
    $fenetre = max($duree_base, LIMITEUR_FENETRE);

    /* Le comptage se fait entièrement en SQL, en deux instructions
       atomiques. L'ancienne version lisait la ligne en PHP, incrémentait,
       puis réécrivait : deux requêtes simultanées lisaient la même valeur
       et écrivaient le même résultat, si bien que deux tentatives n'en
       comptaient qu'une. Il suffisait de paralléliser pour diviser le
       compteur — exactement ce que fait un outil d'attaque. */

    // 1. Incrément, avec remise à zéro si la dernière tentative est vieille.
    $req = $pdo->prepare(
        'INSERT INTO tentative_ip (ip, action, essais, blocages, bloque_jusqu, maj_le)
         VALUES (?, ?, 1, 0, NULL, NOW())
         ON DUPLICATE KEY UPDATE
           essais   = IF(maj_le < NOW() - INTERVAL ? SECOND, 1, essais + 1),
           blocages = IF(maj_le < NOW() - INTERVAL ? SECOND, 0, blocages),
           maj_le   = NOW()'
    );
    $req->execute([$ip, $action, $fenetre, LIMITEUR_OUBLI]);

    /* 2. Si le seuil est atteint, on bloque — durée doublée à chaque
          récidive, plafonnée. La condition « essais >= ? » est évaluée
          par MySQL sur la valeur à jour : deux requêtes concurrentes ne
          peuvent pas déclencher deux blocages pour un seul dépassement. */
    /* ⚠️ L'ordre des affectations compte : MySQL les évalue de gauche à
       droite, et une colonne déjà affectée est lue avec sa NOUVELLE
       valeur. « bloque_jusqu » doit donc être calculé AVANT l'incrément
       de « blocages », sinon le premier blocage dure déjà deux fois la
       durée de base (120 s au lieu de 60) et toute la progression est
       décalée d'un cran. */
    $req = $pdo->prepare(
        'UPDATE tentative_ip
            SET bloque_jusqu = NOW() + INTERVAL CAST(LEAST(? * POW(2, blocages), ?) AS SIGNED) SECOND,
                blocages     = blocages + 1,
                essais       = 0
          WHERE ip = ? AND action = ? AND essais >= ?'
    );
    $req->execute([$duree_base, LIMITEUR_BLOCAGE_MAX, $ip, $action, $max_essais]);
}

/* ---------------------------------------------------------------------
   4ter. Confirmation par mot de passe (actions sensibles)
   --------------------------------------------------------------------- */

/**
 * Vérifie le mot de passe du compte en comptant les échecs.
 *
 * À utiliser partout où l'on redemande le mot de passe (changement
 * d'e-mail, suppression du compte, vidage). Deux raisons, et la seconde
 * compte autant que la première :
 *   - sans limite, une session volée permet de deviner le mot de passe
 *     par essais successifs, sans aucune trace ;
 *   - password_verify() est VOLONTAIREMENT lent (~100 ms). Appelé sans
 *     frein, il devient une arme contre le serveur lui-même : quelques
 *     centaines de requêtes parallèles saturent le processeur d'un
 *     hébergement mutualisé.
 *
 * $attente reçoit le nombre de secondes restantes quand c'est un blocage
 * qui fait échouer la vérification, pour pouvoir le dire à l'utilisateur.
 */
function verifier_mot_de_passe_limite(int $utilisateur_id, string $mdp, ?int &$attente = null): bool
{
    $attente = limiteur_bloque_depuis('mdp_confirmation');
    if ($attente > 0) {
        return false;   // bloqué : on n'appelle même pas password_verify
    }
    if (mot_de_passe_correct($utilisateur_id, $mdp)) {
        return true;
    }
    limiteur_echec('mdp_confirmation', MDP_CONFIRM_MAX, MDP_CONFIRM_BLOCAGE);
    journal_securite('confirmation_mdp_echouee', ['utilisateur' => $utilisateur_id]);
    return false;
}

/* ---------------------------------------------------------------------
   5. Authentification
   --------------------------------------------------------------------- */

/* Ces libellés s'affichent dans la pastille posée sur la couverture,
   large d'une poignée de caractères. « À commencer / envie » y tenait
   sur TROIS lignes et recouvrait l'image — d'où des libellés courts,
   d'un seul mot quand c'est possible. La clé, elle, ne change jamais :
   c'est la valeur stockée en base. */
/* Les quatre provenances possibles d'une couverture.

   La classification vit ICI, en PHP, et voyage jusqu'au navigateur
   dans un attribut « data-image » : le JavaScript n'a pas à
   redécouvrir ce qu'une URL veut dire, et la règle ne peut pas
   diverger entre les deux. */
const IMAGES_TYPES = [
    'aucune'   => 'Pas d\'image',
    'mangadex' => 'MangaDex',
    'importee' => 'Importée',
    'lien'     => 'Lien',
];

/**
 * D'où vient cette couverture ? Retourne une clé d'IMAGES_TYPES.
 *
 * Le test MangaDex est un simple préfixe d'hôte, et non la
 * vérification complète de mangadex_id_depuis_url() : ici on CLASSE
 * pour un filtre, on n'autorise rien. Et couvertures.php, qui porte
 * l'autre fonction, n'est pas chargé par les pages.
 */
function type_image(string $couverture): string
{
    $c = trim($couverture);
    if ($c === '') {
        return 'aucune';
    }
    if (stripos($c, 'https://uploads.mangadex.org/covers/') === 0) {
        return 'mangadex';
    }
    if (stripos($c, 'uploads/') === 0) {
        return 'importee';
    }
    return 'lien';
}

const STATUTS = [
    'cours'   => 'En cours',
    'envie'   => 'Envie',
    'termine' => 'Terminée',
    'abandon' => 'Abandonnée',
];

/** Retourne l'utilisateur connecté (relu en base à chaque requête), ou null. */
function utilisateur_actuel(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }

    global $pdo;
    $id = $_SESSION['utilisateur_id'] ?? null;
    if (!is_int($id) && !ctype_digit((string) $id)) {
        return $cache = null;
    }

    $req = $pdo->prepare(
        'SELECT id, identifiant, email, email_verifie, prenom, nom, photo, forfait,
                session_version, adulte_confirme, filtre_sensible, cree_le
           FROM utilisateur WHERE id = ?'
    );
    $req->execute([(int) $id]);
    $u = $req->fetch();

    if (!$u) {                       // compte supprimé entre-temps
        unset($_SESSION['utilisateur_id']);
        return $cache = null;
    }

    /* Le mot de passe a-t-il changé depuis l'ouverture de cette session ?
       session_version est incrémenté à chaque changement de mot de passe.
       C'est ce qui déconnecte réellement les AUTRES appareils : sans cette
       comparaison, une session ouverte ailleurs (poste partagé, attaquant)
       resterait valide indéfiniment malgré le changement. */
    if ((int) ($_SESSION['session_version'] ?? -1) !== (int) $u['session_version']) {
        $_SESSION = [];
        session_destroy();
        return $cache = null;
    }

    return $cache = $u;
}

/**
 * Pages HTML : redirige vers la connexion si nécessaire.
 *
 * Une page qui montre un compte n'est jamais gardée par le navigateur
 * (« no-store ») : après une déconnexion ou une suppression de compte,
 * « Précédent » la redemande donc au serveur, qui renvoie vers la
 * connexion. Avec « no-cache », elle revenait du cache, identifiant et
 * adresse e-mail compris. Le cache de navigation arrière (bfcache), qui
 * peut garder la page en mémoire malgré tout, est traité côté navigateur
 * (voir « pageshow » dans js/commun.js).
 */
function exiger_connexion(): array
{
    $u = utilisateur_actuel();
    if (!$u) {
        header('Location: connexion.php');
        exit;
    }
    header('Cache-Control: no-store, private');
    return $u;
}

/** API JSON : répond 401 si non connecté. */
function exiger_connexion_api(): array
{
    $u = utilisateur_actuel();
    if (!$u) {
        reponse_json(['ok' => false, 'erreur' => 'Session expirée. Reconnectez-vous.'], 401);
    }
    return $u;
}

/**
 * Valide les champs d'identité d'un profil et vérifie que l'identifiant
 * n'est pas déjà pris. Retourne la liste des erreurs (vide = correct).
 *
 * Une seule définition pour api.php (enregistrement AJAX) et
 * parametres.php (POST classique, sans JavaScript). Ces deux chemins
 * avaient chacun leur copie des règles : une correction faite d'un côté
 * n'atteignait pas l'autre, et rien ne le signalait.
 *
 * Les erreurs sont rangées par champ (« identifiant », « email ») : le
 * message s'affiche sous le champ fautif, pas dans une liste à part.
 */
function valider_profil(string $identifiant, string $email, int $utilisateur_id): array
{
    global $pdo;
    $erreurs = [];

    if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $identifiant)) {
        $erreurs['identifiant'] = "L'identifiant doit faire 3 à 30 caractères (lettres, chiffres, . _ -).";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreurs['email'] = "L'adresse e-mail n'est pas valide.";
    }
    if ($erreurs) {
        return $erreurs;   // inutile d'interroger la base sur une saisie invalide
    }

    $req = $pdo->prepare('SELECT id FROM utilisateur WHERE identifiant = ? AND id <> ?');
    $req->execute([$identifiant, $utilisateur_id]);
    if ($req->fetch()) {
        $erreurs['identifiant'] = 'Cet identifiant est déjà utilisé.';
    }
    return $erreurs;
}

/* ---------------------------------------------------------------------
   Erreurs rattachées à leur champ (formulaires envoyés en POST classique)

   $erreurs est rangé par champ : ['email' => 'message', …], dans l'ordre
   du formulaire. Un message s'affiche sous SON champ, qui le désigne
   (aria-describedby) et se déclare invalide (aria-invalid) ; le premier
   champ fautif reçoit le focus. Une liste en haut de la page ne disait
   ni quel champ corriger, ni où il se trouvait.

   Un champ peut porter plusieurs messages (les règles du mot de passe) :
   la valeur est alors une liste.
   --------------------------------------------------------------------- */

/**
 * Attributs à poser sur le champ $id, pour la clé $champ de $erreurs.
 * $aide : id d'un texte d'aide déjà présent, que le champ garde.
 */
function champ_aria(array $erreurs, string $champ, string $id, string $aide = ''): string
{
    $en_erreur = isset($erreurs[$champ]);
    $decrit    = array_filter([$en_erreur ? $id . '-erreur' : '', $aide]);

    $attributs = $decrit ? ' aria-describedby="' . e(implode(' ', $decrit)) . '"' : '';
    if ($en_erreur) {
        $attributs .= ' aria-invalid="true"';
        // Le premier champ fautif seulement : deux « autofocus » dans une
        // page, et c'est le navigateur qui choisit.
        if (array_key_first($erreurs) === $champ) {
            $attributs .= ' autofocus';
        }
    }
    return $attributs;
}

/** Le message à poser juste sous le champ $id, ou rien. */
function champ_erreur(array $erreurs, string $champ, string $id): string
{
    if (!isset($erreurs[$champ])) {
        return '';
    }
    $lignes = array_map('e', array_map('strval', (array) $erreurs[$champ]));
    return '<p class="erreur-champ" id="' . e($id) . '-erreur">' . implode('<br>', $lignes) . '</p>';
}

/**
 * Une adresse d'image a-t-elle été saisie… puis refusée ?
 *
 * url_image_sure() rend une chaîne vide dans les deux cas, et c'est ce
 * qui faisait disparaître une adresse en http:// sans un mot : la série
 * s'enregistrait « ✅ », sans l'image. Les appelants répondent désormais
 * par une erreur sur le champ.
 */
function url_image_refusee(mixed $saisie): bool
{
    $saisie = is_string($saisie) ? trim($saisie) : '';
    return $saisie !== '' && url_image_sure($saisie) === '';
}

/** L'adresse est-elle libre pour ce compte ? */
function email_disponible(string $email, int $utilisateur_id): bool
{
    global $pdo;
    $req = $pdo->prepare('SELECT id FROM utilisateur WHERE email = ? AND id <> ?');
    $req->execute([$email, $utilisateur_id]);
    return !$req->fetch();
}

/**
 * Le changement d'adresse qui attend sa confirmation, s'il y en a un :
 * ['adresse' => …, 'expire' => « 27/09/2026 à 14 h 05 »], sinon null.
 *
 * Sans lui, les Paramètres réaffichaient l'ancienne adresse sans rien
 * dire de la demande en cours : on ne savait plus laquelle comptait, et
 * l'on redemandait — mot de passe et e-mails à la clé.
 */
function changement_email_en_attente(int $utilisateur_id): ?array
{
    global $pdo;
    $req = $pdo->prepare(
        "SELECT donnee, expire FROM jeton_action
          WHERE utilisateur_id = ? AND type = 'changement_email' AND expire > NOW()
          ORDER BY id DESC LIMIT 1"
    );
    $req->execute([$utilisateur_id]);
    $j = $req->fetch();
    if (!$j || (string) $j['donnee'] === '') {
        return null;
    }
    return [
        'adresse' => (string) $j['donnee'],
        'expire'  => date('d/m/Y à H \h i', (int) strtotime((string) $j['expire'])),
    ];
}

/**
 * Choisit la photo à enregistrer selon ce que le formulaire a envoyé.
 * Priorité : fichier envoyé > URL saisie > image retirée > image actuelle.
 * Partagée elle aussi entre api.php et parametres.php.
 */
function photo_depuis_formulaire(?string $fichier, string $ancienne): string
{
    if ($fichier !== null) {
        return $fichier;
    }
    $url_saisie = url_image_sure($_POST['photo_url'] ?? '');
    if ($url_saisie !== '') {
        return $url_saisie;
    }
    if (($_POST['photo_retiree'] ?? '0') === '1') {
        return '';
    }
    return url_image_sure($ancienne);
}

/**
 * Vérifie le mot de passe du compte connecté. Exigé avant toute opération
 * irréversible ou qui déplacerait le contrôle du compte (changement
 * d'adresse e-mail, suppression du compte, vidage de la bibliothèque) :
 * une session volée ne doit pas suffire à faire ces choses-là.
 *
 * ⚠️ Appeler verifier_mot_de_passe_limite() plutôt que cette fonction :
 * celle-ci ne compte pas les échecs.
 */
function mot_de_passe_correct(int $utilisateur_id, string $mot_de_passe): bool
{
    global $pdo;
    if ($mot_de_passe === '') {
        return false;
    }
    $req = $pdo->prepare('SELECT mot_de_passe FROM utilisateur WHERE id = ?');
    $req->execute([$utilisateur_id]);
    $hash = (string) $req->fetchColumn();
    return $hash !== '' && password_verify($mot_de_passe, $hash);
}

function connecter(int $id): void
{
    session_regenerate_id(true);     // empêche la fixation de session
    $_SESSION['utilisateur_id'] = $id;
    $_SESSION['csrf'] = bin2hex(random_bytes(32));

    global $pdo;
    $req = $pdo->prepare('SELECT forfait, session_version FROM utilisateur WHERE id = ?');
    $req->execute([$id]);
    $u = $req->fetch() ?: ['forfait' => 'standard', 'session_version' => 0];

    $_SESSION['session_version'] = (int) $u['session_version'];

    if ($u['forfait'] === 'illimite') {
        // Forfait illimité : pas de limite de session, une seule connexion
        // suffit par appareil (jeton longue durée, voir session_persistante).
        creer_session_persistante($id);
    }
}

/**
 * Invalide toutes les sessions du compte (y compris celles des autres
 * appareils), et annule un changement d'adresse en attente.
 *
 * Appelée quand le mot de passe change ou est réinitialisé : c'est la
 * réponse de qui reprend la main sur son compte. Un changement d'adresse
 * demandé par l'attaquant y survivait — il n'avait plus qu'à le
 * confirmer depuis sa propre boîte pour tout reprendre.
 *
 * Les liens « blocage_email » ne sont PAS touchés : l'attaquant, qui a le
 * mot de passe, pourrait sinon effacer celui de sa victime en changeant
 * simplement le mot de passe.
 */
function invalider_sessions(int $utilisateur_id): void
{
    global $pdo;
    $pdo->prepare('UPDATE utilisateur SET session_version = session_version + 1 WHERE id = ?')
        ->execute([$utilisateur_id]);
    $pdo->prepare('DELETE FROM session_persistante WHERE utilisateur_id = ?')
        ->execute([$utilisateur_id]);
    $pdo->prepare("DELETE FROM jeton_action WHERE utilisateur_id = ? AND type = 'changement_email'")
        ->execute([$utilisateur_id]);
}

/** Paramètres du cookie de connexion persistante (hors « lifetime », variable). */
function cookie_persistant_params(int $duree): array
{
    return [
        'expires'  => time() + $duree,
        'path'     => '/',
        'httponly' => true,
        'secure'   => !empty($_SERVER['HTTPS']),
        'samesite' => 'Lax',
    ];
}

/** Dépose un nouveau jeton de connexion persistante pour cet appareil (forfait illimité). */
function creer_session_persistante(int $utilisateur_id): void
{
    global $pdo;
    $jeton = bin2hex(random_bytes(32));
    $expire = date('Y-m-d H:i:s', time() + REMEMBER_DUREE_VIP);

    $pdo->prepare('INSERT INTO session_persistante (utilisateur_id, jeton_hash, expire) VALUES (?, ?, ?)')
        ->execute([$utilisateur_id, hash('sha256', $jeton), $expire]);

    setcookie('LIVRE_REMEMBER', $jeton, cookie_persistant_params(REMEMBER_DUREE_VIP));

    /* Plafond d'appareils : au-delà, les plus anciens jetons partent.
       Chaque ligne est un accès valide un an.

       ⚠️ Deux conditions « remplace_le IS NULL », et elles ne font pas la
       même chose.

       Dans la sous-requête : seuls les jetons ACTIFS occupent une place.
       Sans elle, les jetons que la rotation vient de remplacer comptaient
       eux aussi — or il s'en crée un à chaque reconnexion automatique, et
       purger.php ne les efface qu'une fois par jour. Les 30 emplacements
       en valaient donc environ 15, et un appareil peu utilisé se faisait
       éjecter au bout de quelques jours au lieu d'un an.

       Dans le DELETE : un jeton remplacé ne doit JAMAIS être supprimé
       ici. Il vit encore REMEMBER_SURSIS secondes, le temps qu'une requête
       parallèle (second onglet, préchargement) qui utilisait l'ancien
       cookie ne se retrouve pas déconnectée. C'est purger.php qui les
       ramasse, une fois le sursis écoulé.

       La dérivée « AS recents » est obligatoire (MySQL refuse de lire la
       table qu'il modifie), et MAX_APPAREILS est interpolé car un LIMIT
       n'accepte pas partout un paramètre lié : le cast (int) le sécurise. */
    $garde = (int) MAX_APPAREILS;
    $pdo->prepare(
        "DELETE FROM session_persistante
          WHERE utilisateur_id = ?
            AND remplace_le IS NULL
            AND id NOT IN (
              SELECT id FROM (
                SELECT id FROM session_persistante
                 WHERE utilisateur_id = ?
                   AND remplace_le IS NULL
                 ORDER BY cree_le DESC, id DESC
                 LIMIT {$garde}
              ) AS recents
            )"
    )->execute([$utilisateur_id, $utilisateur_id]);
}

/**
 * Si aucune session active mais qu'un jeton de connexion persistante
 * valide est présent (cookie LIVRE_REMEMBER, forfait illimité), reconnecte
 * automatiquement l'appareil. Le jeton tourne à chaque connexion
 * automatique (limite la fenêtre de rejeu si le cookie fuitait), l'ancien
 * restant toléré REMEMBER_SURSIS secondes.
 * Appelée juste après session_start(), avant toute lecture de $_SESSION.
 */
function verifier_session_persistante(): void
{
    if (!empty($_SESSION['utilisateur_id'])) {
        return;
    }
    $jeton = (string) ($_COOKIE['LIVRE_REMEMBER'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $jeton)) {
        return;
    }

    global $pdo;
    // REMEMBER_SURSIS est une constante du code, jamais une saisie : son
    // interpolation ici est sûre, et elle évite un paramètre lié dans un
    // INTERVAL, que tous les pilotes MySQL ne digèrent pas.
    $sursis = (int) REMEMBER_SURSIS;
    $req = $pdo->prepare(
        "SELECT sp.id, sp.utilisateur_id, sp.remplace_le
           FROM session_persistante sp
           JOIN utilisateur u ON u.id = sp.utilisateur_id
          WHERE sp.jeton_hash = ?
            AND sp.expire > NOW()
            AND (sp.remplace_le IS NULL OR sp.remplace_le > NOW() - INTERVAL {$sursis} SECOND)
            AND u.forfait = 'illimite'"
    );
    $req->execute([hash('sha256', $jeton)]);
    $ligne = $req->fetch();

    if (!$ligne) {
        // Jeton invalide/expiré/forfait rétrogradé : on nettoie le cookie
        // pour ne pas retenter à chaque requête.
        setcookie('LIVRE_REMEMBER', '', cookie_persistant_params(-3600));
        return;
    }

    if ($ligne['remplace_le'] !== null) {
        /* Jeton déjà remplacé, mais encore dans son sursis : une requête
           parallèle vient de faire la rotation et a posé le nouveau
           cookie. On ouvre la session sans refaire de rotation ni
           réécrire le cookie — sinon les deux requêtes se marchent dessus. */
        session_regenerate_id(true);
        $_SESSION['utilisateur_id'] = (int) $ligne['utilisateur_id'];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $req = $pdo->prepare('SELECT session_version FROM utilisateur WHERE id = ?');
        $req->execute([(int) $ligne['utilisateur_id']]);
        $_SESSION['session_version'] = (int) $req->fetchColumn();
        return;
    }

    /* Rotation : l'ancien jeton n'est pas supprimé tout de suite, il est
       marqué « remplacé ». purger.php efface ensuite les lignes dont le
       sursis est écoulé. */
    $pdo->prepare('UPDATE session_persistante SET remplace_le = NOW() WHERE id = ?')
        ->execute([(int) $ligne['id']]);

    connecter((int) $ligne['utilisateur_id']);
}

/** Révoque le jeton de connexion persistante de CET appareil (déconnexion explicite). */
function revoquer_session_persistante(): void
{
    $jeton = (string) ($_COOKIE['LIVRE_REMEMBER'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $jeton)) {
        global $pdo;
        $pdo->prepare('DELETE FROM session_persistante WHERE jeton_hash = ?')->execute([hash('sha256', $jeton)]);
    }
    if (isset($_COOKIE['LIVRE_REMEMBER'])) {
        setcookie('LIVRE_REMEMBER', '', cookie_persistant_params(-3600));
    }
}

/** Initiales affichées dans l'avatar quand aucune photo n'est définie. */
function initiales(array $u): string
{
    $p = trim((string) ($u['prenom'] ?? ''));
    $n = trim((string) ($u['nom'] ?? ''));
    if ($p !== '' || $n !== '') {
        return mb_strtoupper(mb_substr($p, 0, 1, 'UTF-8') . mb_substr($n, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return mb_strtoupper(mb_substr((string) ($u['identifiant'] ?? ''), 0, 2, 'UTF-8'), 'UTF-8');
}

/* ---------------------------------------------------------------------
   6. Réponses JSON (API)
   --------------------------------------------------------------------- */

function reponse_json(array $donnees, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    // Les réponses de l'API ne sont jamais réaffichées par un « Précédent » :
    // elles peuvent garder le réglage strict, contrairement aux pages HTML
    // (voir le commentaire de l'en-tête Cache-Control plus haut).
    header('Cache-Control: no-store, private');
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------------------------------------------------------------------
   7. Images : URL filtrées + envoi de fichiers
   --------------------------------------------------------------------- */

/**
 * N'accepte qu'une URL https ou un fichier de notre dossier uploads/.
 * Tout le reste (javascript:, data:, vbscript:, chemin ../) est rejeté :
 * c'est ce qui empêche qu'une « couverture » devienne un vecteur XSS.
 *
 * http:// est refusé, et pas seulement par principe : la Content-Security-
 * Policy n'autorise que « https: » pour les images. Une couverture en
 * http:// était acceptée par le serveur puis bloquée par le navigateur —
 * l'utilisateur voyait une image cassée sans comprendre pourquoi.
 */
function url_image_sure(?string $url): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    if (preg_match('#^uploads/[A-Za-z0-9_-]+\.(jpg|jpeg|png|gif|webp)$#i', $url)) {
        return $url;
    }

    if (mb_strlen($url, 'UTF-8') > 500) {
        return '';
    }
    if (!preg_match('#^https://#i', $url)) {
        return '';
    }
    $parties = parse_url($url);
    if (!$parties || empty($parties['host'])) {
        return '';
    }
    return $url;
}

/**
 * Enregistre une image envoyée par formulaire dans uploads/ et retourne
 * son chemin relatif, ou null si aucun fichier / fichier refusé.
 * Le contenu est redimensionné et ré-encodé (voir includes/images.php).
 */
function enregistrer_image(string $champ, ?string &$erreur = null): ?string
{
    if (empty($_FILES[$champ]) || ($_FILES[$champ]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$champ];

    /* Un message par cause réelle. « L'envoi de l'image a échoué »
       couvrait les six codes d'erreur de PHP d'un seul coup : l'utilisateur
       dont le fichier était trop lourd n'avait aucun moyen de le deviner,
       et l'administrateur ne voyait pas passer les pannes serveur. */
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $erreur = match ((int) $f['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'Image trop lourde (' . taille_lisible(IMAGE_TAILLE_MAX) . ' maximum).',
            UPLOAD_ERR_PARTIAL =>
                "L'envoi s'est interrompu avant la fin. Vérifiez votre connexion et réessayez.",
            default =>
                "L'image n'a pas pu être reçue par le serveur. Réessayez dans quelques instants.",
        };
        // Les codes restants (pas de dossier temporaire, écriture refusée,
        // extension PHP qui bloque) sont des pannes de l'hébergement :
        // l'utilisateur n'y peut rien, mais l'administrateur doit le savoir.
        if (!in_array((int) $f['error'],
            [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL], true)) {
            error_log('enregistrer_image: echec PHP code ' . (int) $f['error']);
        }
        return null;
    }
    if ($f['size'] > IMAGE_TAILLE_MAX) {
        $erreur = 'Image trop lourde (' . taille_lisible(IMAGE_TAILLE_MAX) . ' maximum).';
        return null;
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        $erreur = 'Fichier invalide.';
        return null;
    }

    $brut = @file_get_contents($f['tmp_name']);
    if ($brut === false) {
        $erreur = "L'image n'a pas pu être lue.";
        return null;
    }

    $image = traiter_image($brut, $erreur);
    if ($image === null) {
        return null;
    }

    return ecrire_image($image['contenu'], $image['ext'], $erreur);
}

/**
 * Recrée sur disque une image encodée en data URI base64 (utilisée par
 * l'import d'une sauvegarde, pour transférer les images qui avaient été
 * envoyées comme fichier — les liens https n'ont, eux, pas besoin de ça).
 * Retourne le chemin relatif ('uploads/xxx.ext') ou null si invalide.
 */
function enregistrer_image_depuis_donnees(string $donneeUri): ?string
{
    if (!preg_match('#^data:image/(jpeg|jpg|png|gif|webp);base64,([a-zA-Z0-9+/=]+)$#', $donneeUri, $m)) {
        return null;
    }
    // Un base64 de plus de 4/3 × la taille max ne peut pas décoder en une
    // image acceptable : on refuse avant de décoder, pour ne pas allouer
    // inutilement la mémoire d'un fichier volontairement énorme.
    if (strlen($m[2]) > (int) (IMAGE_TAILLE_MAX * 1.4)) {
        return null;
    }
    $brut = base64_decode($m[2], true);
    if ($brut === false || $brut === '') {
        return null;
    }

    $erreur = null;
    $image = traiter_image($brut, $erreur);
    if ($image === null) {
        return null;
    }

    return ecrire_image($image['contenu'], $image['ext']);
}

/**
 * Supprime un fichier de uploads/ (et rien d'autre) — sauf si une autre
 * série ou un autre profil le référence encore (une même image peut être
 * partagée depuis enregistrer_image/enregistrer_image_depuis_donnees).
 * Appeler cette fonction seulement APRÈS avoir retiré/modifié en base la
 * ligne qui pointait vers ce chemin, sinon elle se trouvera elle-même.
 */
function supprimer_image_locale(?string $chemin): void
{
    supprimer_images_locales([(string) $chemin]);
}

/**
 * Version groupée de supprimer_image_locale() : deux requêtes au total,
 * au lieu de deux PAR image. « Vider la bibliothèque » appelait l'ancienne
 * version dans une boucle, soit 300 requêtes pour 150 séries — chacune
 * étant, avant l'ajout de idx_serie_couverture, un parcours complet de table.
 */
function supprimer_images_locales(array $chemins): void
{
    global $pdo;

    $candidats = [];
    foreach ($chemins as $chemin) {
        $chemin = (string) $chemin;
        if (preg_match('#^uploads/[A-Za-z0-9_-]+\.(jpg|jpeg|png|gif|webp)$#i', $chemin)) {
            $candidats[$chemin] = true;
        }
    }
    $candidats = array_keys($candidats);
    if (!$candidats) {
        return;
    }

    $trous = implode(',', array_fill(0, count($candidats), '?'));

    $req = $pdo->prepare("SELECT DISTINCT couverture FROM serie WHERE couverture IN ($trous)");
    $req->execute($candidats);
    $encore = array_column($req->fetchAll(), 'couverture');

    $req = $pdo->prepare("SELECT DISTINCT photo FROM utilisateur WHERE photo IN ($trous)");
    $req->execute($candidats);
    $encore = array_merge($encore, array_column($req->fetchAll(), 'photo'));

    foreach (array_diff($candidats, $encore) as $chemin) {
        $absolu = CHEMIN_RACINE . '/' . $chemin;
        if (is_file($absolu)) {
            @unlink($absolu);
        }
    }
}

/* ---------------------------------------------------------------------
   8. Ressources statiques : URL avec empreinte pour le cache
   --------------------------------------------------------------------- */

/**
 * Ajoute un numéro de version à l'URL d'une ressource (style.css?v=…).
 * Le .htaccess demande aux navigateurs de garder css/ et js/ pendant un an ;
 * sans ce suffixe, une correction déployée ne parviendrait jamais aux
 * visiteurs qui ont déjà la page en cache.
 *
 * Deux modes :
 *   - ASSETS_VERSION renseigné (production) : aucun accès au disque.
 *   - ASSETS_VERSION vide (développement) : la date de modification du
 *     fichier, pratique car on n'a rien à penser en codant.
 *
 * Ce choix n'est pas cosmétique. Chez OVH, l'espace des hébergements
 * mutualisés est monté en NFS : chaque appel à filemtime() est un
 * aller-retour réseau, et il y en avait jusqu'à trois par page affichée.
 */
function actif(string $chemin): string
{
    if (ASSETS_VERSION !== '') {
        return $chemin . '?v=' . ASSETS_VERSION;
    }

    // Développement : une seule interrogation du disque par fichier et
    // par requête, même si la page appelle actif() plusieurs fois.
    static $cache = [];
    if (!isset($cache[$chemin])) {
        $absolu = CHEMIN_RACINE . '/' . ltrim($chemin, '/');
        $cache[$chemin] = is_file($absolu) ? (string) filemtime($absolu) : '0';
    }
    return $chemin . '?v=' . $cache[$chemin];
}
