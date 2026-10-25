<?php
/* =====================================================================
   API JSON v1 — appelée de SERVEUR à serveur, par jeton.

   Ce n'est pas api.php. api.php est le point d'entrée du navigateur : il
   vit de la session et du jeton CSRF. Celui-ci n'a ni l'un ni l'autre —
   un jeton personnel, envoyé dans « Authorization: Bearer … », identifie
   le compte. Pas de cookie, pas de session, pas de CORS.

   Règles appliquées à CHAQUE requête, dans cet ordre :
     1. HTTPS (sauf boucle locale) ;
     2. jeton valide, sinon 401 — et chaque jeton refusé compte contre
        l'adresse IP (limiteur d'échecs) ;
     3. frein par jeton (API_REQUETES_MAX / API_FENETRE) ;
     4. la route, puis les entrées validées strictement ;
     5. toutes les requêtes SQL filtrent sur utilisateur_id et sont
        préparées.
   Aucune suppression de série n'est exposée.

   Les fonctions « pures » (route, validation, jeton, pagination) sont
   séparées de celles qui parlent à la base ou répondent : ce sont les
   premières que tests/cas/api_v1_pur_test.php couvre.
   ===================================================================== */

declare(strict_types=1);

const API_PREFIXE_JETON = 'lbm_';
const API_CORPS_MAX     = 16384;   // octets : une série tient dans 1 Ko
const API_JETONS_MAX    = 5;       // jetons ACTIFS par compte
const API_PAGE_DEFAUT   = 50;
const API_PAGE_MAX      = 100;
const API_CHAMPS_SERIE  = ['titre', 'auteur', 'tome_lu', 'statut', 'favori'];
const API_COLONNES      = 'id, titre, auteur, tome_actuel, statut, couverture, mangadex_id, favori, cree_le, maj_le';

/* ---------------------------------------------------------------------
   1. Jeton
   --------------------------------------------------------------------- */

/** Un jeton neuf : 256 bits aléatoires, préfixés pour être reconnaissables. */
function api_jeton_generer(): string
{
    return API_PREFIXE_JETON . bin2hex(random_bytes(32));
}

/** Ce qu'on stocke : l'empreinte, jamais le jeton. */
function api_jeton_hash(string $jeton): string
{
    return hash('sha256', $jeton);
}

/** La forme d'un jeton que nous avons émis — écarte le bruit avant la base. */
function api_jeton_format_valide(string $jeton): bool
{
    return preg_match('/^' . API_PREFIXE_JETON . '[0-9a-f]{64}$/', $jeton) === 1;
}

/**
 * Le jeton présenté dans « Authorization: Bearer … », ou null.
 *
 * Chez OVH (PHP en FPM/CGI), Apache ne transmet pas toujours l'en-tête à
 * PHP : api/.htaccess le recopie alors dans REDIRECT_HTTP_AUTHORIZATION.
 * Les deux sont lues.
 */
function api_jeton_extraire(array $serveur): ?string
{
    $entete = (string) ($serveur['HTTP_AUTHORIZATION'] ?? $serveur['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer ([^\s]{1,200})$/i', trim($entete), $m) !== 1) {
        return null;
    }
    return $m[1];
}

/**
 * La requête est-elle arrivée en HTTPS ? config.php a déjà normalisé
 * X-Forwarded-Proto. Seule exception : la boucle locale (développement,
 * tests) — REMOTE_ADDR ne se falsifie pas, contrairement à Host.
 */
function api_https_ok(array $serveur): bool
{
    if (!empty($serveur['HTTPS']) && strtolower((string) $serveur['HTTPS']) !== 'off') {
        return true;
    }
    return in_array((string) ($serveur['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
}

/**
 * Crée un jeton pour ce compte. Retourne ['id' => …, 'jeton' => …] — la
 * seule fois où le jeton existe en clair — ou null si le compte a déjà
 * API_JETONS_MAX jetons actifs.
 *
 * Le plafond est appliqué dans l'INSERT lui-même (voir CLAUDE.md, « Les
 * quotas s'appliquent dans l'INSERT »).
 */
function api_jeton_creer(PDO $pdo, int $utilisateur_id, string $nom): ?array
{
    $jeton = api_jeton_generer();
    $req = $pdo->prepare(
        'INSERT INTO jeton_api (utilisateur_id, jeton_hash, nom)
         SELECT ?, ?, ?
           FROM DUAL
          WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM jeton_api
                                 WHERE utilisateur_id = ? AND revoque_le IS NULL) AS c) < ?'
    );
    $req->execute([$utilisateur_id, api_jeton_hash($jeton), $nom, $utilisateur_id, API_JETONS_MAX]);
    if ($req->rowCount() === 0) {
        return null;
    }
    return ['id' => (int) $pdo->lastInsertId(), 'jeton' => $jeton];
}

/**
 * Retrouve le compte d'un jeton. Retourne null si le jeton est inconnu,
 * sinon la ligne du compte avec 'etat' : 'ok', 'revoque' ou 'expire'.
 */
function api_jeton_chercher(PDO $pdo, string $jeton): ?array
{
    $req = $pdo->prepare(
        'SELECT j.id AS jeton_id,
                (j.revoque_le IS NOT NULL) AS revoque,
                (j.expire IS NOT NULL AND j.expire <= NOW()) AS expire,
                u.id, u.identifiant, u.email, u.forfait, u.adulte_confirme, u.filtre_sensible
           FROM jeton_api j
           JOIN utilisateur u ON u.id = j.utilisateur_id
          WHERE j.jeton_hash = ?'
    );
    $req->execute([api_jeton_hash($jeton)]);
    $l = $req->fetch();
    if (!$l) {
        return null;
    }
    $l['etat'] = (int) $l['revoque'] === 1 ? 'revoque' : ((int) $l['expire'] === 1 ? 'expire' : 'ok');
    unset($l['revoque'], $l['expire']);
    return $l;
}

/**
 * Compte une requête pour ce jeton et rend l'état de la tranche :
 * ['essais' => n, 'reste' => secondes avant la fin de la tranche].
 *
 * Une seule instruction : deux requêtes simultanées liraient sinon le
 * même compteur (voir couverture_consommer()). L'ordre des affectations
 * compte — « essais » est calculé AVANT que « fenetre_fin » change.
 */
function api_requete_compter(PDO $pdo, int $jeton_id, int $fenetre): array
{
    $pdo->prepare(
        'UPDATE jeton_api
            SET essais        = IF(fenetre_fin IS NULL OR fenetre_fin <= NOW(), 1, essais + 1),
                fenetre_fin   = IF(fenetre_fin IS NULL OR fenetre_fin <= NOW(),
                                   NOW() + INTERVAL ? SECOND, fenetre_fin),
                dernier_usage = NOW()
          WHERE id = ?'
    )->execute([$fenetre, $jeton_id]);

    $req = $pdo->prepare(
        'SELECT essais, GREATEST(1, TIMESTAMPDIFF(SECOND, NOW(), fenetre_fin)) AS reste
           FROM jeton_api WHERE id = ?'
    );
    $req->execute([$jeton_id]);
    $l = $req->fetch();
    return ['essais' => (int) ($l['essais'] ?? 0), 'reste' => (int) ($l['reste'] ?? $fenetre)];
}

/* ---------------------------------------------------------------------
   2. Route
   --------------------------------------------------------------------- */

/**
 * Quelle route pour cette méthode et ce chemin (relatif à api/, donc
 * « v1/series/12 ») ?
 *
 * Retourne ['route' => nom, 'id' => int|null] ; ou ['erreur' => 404]
 * pour un chemin inconnu ; ou ['erreur' => 405, 'permises' => [...]]
 * quand le chemin existe mais pas pour cette méthode.
 */
function api_route(string $methode, string $chemin): array
{
    $chemin = trim($chemin, '/');
    $id     = '([1-9][0-9]{0,9})';

    $table = [
        ['#^v1/moi$#',                                     null,  ['GET' => 'moi']],
        ['#^v1/series$#',                                  null,  ['GET' => 'series.lister', 'POST' => 'series.creer']],
        ['#^v1/series/' . $id . '$#',                      1,     ['GET' => 'series.lire', 'PATCH' => 'series.modifier']],
        ['#^v1/series/' . $id . '/couvertures/recherche$#', 1,    ['POST' => 'couvertures.chercher']],
        ['#^v1/series/' . $id . '/couverture$#',           1,     ['PUT' => 'couvertures.choisir']],
    ];

    foreach ($table as [$motif, $groupe, $methodes]) {
        if (preg_match($motif, $chemin, $m) !== 1) {
            continue;
        }
        $methode = strtoupper($methode);
        if (!isset($methodes[$methode])) {
            return ['erreur' => 405, 'permises' => array_keys($methodes)];
        }
        return ['route' => $methodes[$methode], 'id' => $groupe ? (int) $m[1] : null];
    }
    return ['erreur' => 404];
}

/* ---------------------------------------------------------------------
   3. Entrées
   --------------------------------------------------------------------- */

/**
 * Décode le corps d'une requête.
 *
 * Retourne ['corps' => array|null, 'erreur' => null|[http, code, message]].
 * Un corps vide donne null quand $obligatoire vaut false, une erreur sinon.
 * Un corps non vide doit être un OBJET JSON (pas un tableau, pas un
 * scalaire) et déclarer application/json.
 */
function api_corps_decoder(string $brut, string $type_contenu, bool $obligatoire): array
{
    if (strlen($brut) > API_CORPS_MAX) {
        return ['corps' => null, 'erreur' => [413, 'corps_trop_gros',
            'Le corps de la requête dépasse ' . API_CORPS_MAX . ' octets.']];
    }
    if (trim($brut) === '') {
        return $obligatoire
            ? ['corps' => null, 'erreur' => [400, 'corps_requis', 'Un corps JSON est requis.']]
            : ['corps' => null, 'erreur' => null];
    }
    if (stripos($type_contenu, 'application/json') !== 0) {
        return ['corps' => null, 'erreur' => [415, 'type_non_supporte',
            'Content-Type doit être application/json.']];
    }
    $corps = json_decode($brut, true, 8);
    if (!is_array($corps) || ($corps !== [] && array_is_list($corps))) {
        return ['corps' => null, 'erreur' => [400, 'json_invalide',
            'Le corps doit être un objet JSON valide.']];
    }
    return ['corps' => $corps, 'erreur' => null];
}

/** Une chaîne d'une ligne, bornée à $max SANS troncature silencieuse. */
function api_texte_strict(mixed $valeur, int $max, ?string &$erreur): string
{
    if (!is_string($valeur)) {
        $erreur = 'Doit être une chaîne de caractères.';
        return '';
    }
    $t = texte($valeur, $max + 1);
    if (mb_strlen($t, 'UTF-8') > $max) {
        $erreur = 'Au plus ' . $max . ' caractères.';
        return '';
    }
    return $t;
}

/**
 * Valide le corps d'une création ou d'une modification de série.
 *
 * Retourne [données, erreurs]. « données » porte les NOMS DE COLONNES
 * (tome_lu → tome_actuel, favori → 0/1), prêtes pour le SQL ; « erreurs »
 * est rangée par champ. Strict : un champ inconnu, un mauvais type ou une
 * valeur hors borne est refusé, jamais corrigé en silence (le site, lui,
 * corrige : une API appelée par un programme doit dire qu'il se trompe).
 */
function api_valider_serie(array $corps, bool $creation): array
{
    $donnees = [];
    $erreurs = [];

    foreach (array_keys($corps) as $cle) {
        if (!in_array((string) $cle, API_CHAMPS_SERIE, true)) {
            $erreurs[(string) $cle] = 'Champ inconnu.';
        }
    }

    if (array_key_exists('titre', $corps)) {
        $err = null;
        $t = api_texte_strict($corps['titre'], 190, $err);
        if ($err === null && $t === '') {
            $err = 'Le titre est obligatoire.';
        }
        if ($err === null) {
            $donnees['titre'] = $t;
        } else {
            $erreurs['titre'] = $err;
        }
    } elseif ($creation) {
        $erreurs['titre'] = 'Le titre est obligatoire.';
    }

    if (array_key_exists('auteur', $corps)) {
        $err = null;
        $a = api_texte_strict($corps['auteur'], 190, $err);
        if ($err === null) {
            $donnees['auteur'] = $a;
        } else {
            $erreurs['auteur'] = $err;
        }
    }

    if (array_key_exists('tome_lu', $corps)) {
        $t = $corps['tome_lu'];
        if (!is_int($t) || $t < 0 || $t > TOME_MAX) {
            $erreurs['tome_lu'] = 'Doit être un entier entre 0 et ' . TOME_MAX . '.';
        } else {
            $donnees['tome_actuel'] = $t;
        }
    }

    if (array_key_exists('statut', $corps)) {
        $s = $corps['statut'];
        if (!is_string($s) || !isset(STATUTS[$s])) {
            $erreurs['statut'] = 'Doit être l\'une de : ' . implode(', ', array_keys(STATUTS)) . '.';
        } else {
            $donnees['statut'] = $s;
        }
    }

    if (array_key_exists('favori', $corps)) {
        if (!is_bool($corps['favori'])) {
            $erreurs['favori'] = 'Doit être true ou false.';
        } else {
            $donnees['favori'] = $corps['favori'] ? 1 : 0;
        }
    }

    return [$donnees, $erreurs];
}

/**
 * Validation du corps de la recherche de couverture : {"tome": n} facultatif.
 * Retourne [tome|null, erreurs].
 */
function api_valider_recherche(array $corps): array
{
    $erreurs = [];
    $tome    = null;
    foreach (array_keys($corps) as $cle) {
        if ((string) $cle !== 'tome') {
            $erreurs[(string) $cle] = 'Champ inconnu.';
        }
    }
    if (array_key_exists('tome', $corps)) {
        if (!is_int($corps['tome']) || $corps['tome'] < 1 || $corps['tome'] > TOME_MAX) {
            $erreurs['tome'] = 'Doit être un entier entre 1 et ' . TOME_MAX . '.';
        } else {
            $tome = $corps['tome'];
        }
    }
    return [$tome, $erreurs];
}

/**
 * L'adresse d'une couverture que l'API accepte d'enregistrer : un fichier
 * d'image de uploads.mangadex.org/covers/<uuid>/, rien d'autre. Ni
 * paramètre, ni fragment, ni remontée de dossier — l'API choisit parmi
 * les propositions de la Recherche auto, elle n'est pas un moyen de faire
 * afficher n'importe quelle adresse sur la fiche.
 */
function api_couverture_url_acceptee(mixed $url): bool
{
    if (!is_string($url) || strlen($url) > 500) {
        return false;
    }
    return preg_match(
        '#^https://uploads\.mangadex\.org/covers/'
        . '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'
        . '/[A-Za-z0-9._-]{1,120}\.(jpg|jpeg|png|webp)$#',
        $url
    ) === 1 && !str_contains($url, '..');
}

/**
 * Filtres et pagination d'une liste, à partir de la chaîne de requête.
 *
 * Retourne ['where' => SQL, 'params' => [...], 'limite' => n, 'decalage' => n,
 * 'erreurs' => [champ => message]]. Le SQL ne contient que des « ? » : rien
 * de ce que le client envoie n'y est concaténé.
 */
function api_liste_parametres(array $get): array
{
    $where   = [];
    $params  = [];
    $erreurs = [];

    if (isset($get['q'])) {
        if (!is_string($get['q']) || mb_strlen($get['q'], 'UTF-8') > 190) {
            $erreurs['q'] = 'Doit être une chaîne de 190 caractères au plus.';
        } elseif (($q = texte($get['q'], 190)) !== '') {
            $motif   = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(titre LIKE ? OR auteur LIKE ?)';
            array_push($params, $motif, $motif);
        }
    }

    if (isset($get['statut'])) {
        if (!is_string($get['statut']) || !isset(STATUTS[$get['statut']])) {
            $erreurs['statut'] = 'Doit être l\'une de : ' . implode(', ', array_keys(STATUTS)) . '.';
        } else {
            $where[]  = 'statut = ?';
            $params[] = $get['statut'];
        }
    }

    if (isset($get['favori'])) {
        $v = $get['favori'];
        if ($v === '1' || $v === 'true') {
            $where[] = 'favori = 1';
        } elseif ($v === '0' || $v === 'false') {
            $where[] = 'favori = 0';
        } else {
            $erreurs['favori'] = 'Doit valoir true, false, 1 ou 0.';
        }
    }

    $limite = API_PAGE_DEFAUT;
    if (isset($get['limite'])) {
        $l = is_string($get['limite']) && ctype_digit($get['limite']) ? (int) $get['limite'] : 0;
        if ($l < 1 || $l > API_PAGE_MAX) {
            $erreurs['limite'] = 'Doit être un entier entre 1 et ' . API_PAGE_MAX . '.';
        } else {
            $limite = $l;
        }
    }

    $decalage = 0;
    if (isset($get['decalage'])) {
        if (!is_string($get['decalage']) || !ctype_digit($get['decalage']) || strlen($get['decalage']) > 7) {
            $erreurs['decalage'] = 'Doit être un entier positif ou nul.';
        } else {
            $decalage = (int) $get['decalage'];
        }
    }

    return [
        'where'    => $where ? ' AND ' . implode(' AND ', $where) : '',
        'params'   => $params,
        'limite'   => $limite,
        'decalage' => $decalage,
        'erreurs'  => $erreurs,
    ];
}

/* ---------------------------------------------------------------------
   4. Sortie
   --------------------------------------------------------------------- */

/** Une date de la base, en ISO 8601 avec décalage horaire. */
function api_date(?string $date): ?string
{
    $t = $date !== null && $date !== '' ? strtotime($date) : false;
    return $t === false ? null : date('c', $t);
}

/** Une série telle que l'API la montre. $s vient de API_COLONNES. */
function api_serie_json(array $s): array
{
    $couverture = url_image_sure((string) ($s['couverture'] ?? ''));
    $lien       = (string) ($s['mangadex_id'] ?? '');

    return [
        'id'             => (int) $s['id'],
        'titre'          => (string) $s['titre'],
        'auteur'         => (string) $s['auteur'],
        'tome_lu'        => (int) $s['tome_actuel'],
        'tome_suivant'   => (int) $s['tome_actuel'] + 1,
        'statut'         => (string) $s['statut'],
        'statut_libelle' => STATUTS[$s['statut']] ?? (string) $s['statut'],
        'favori'         => (int) $s['favori'] === 1,
        'couverture'     => [
            'url'         => $couverture === '' ? null
                : (str_starts_with($couverture, 'uploads/') ? APP_URL . '/' . $couverture : $couverture),
            'source'      => type_image($couverture),
            'mangadex_id' => $lien === '' ? null : $lien,
        ],
        'cree_le'        => api_date($s['cree_le'] ?? null),
        'modifie_le'     => api_date($s['maj_le'] ?? null),
    ];
}

/**
 * Une erreur JSON, sans détail interne :
 *   {"erreur": {"code": "...", "message": "...", "champs": {...}, "attente": 12}}
 * « code » est stable (c'est lui que le programme teste), « message » est
 * pour un humain. Retry-After accompagne tout 429.
 */
function api_erreur(int $http, string $code, string $message, array $champs = [], ?int $attente = null): never
{
    $erreur = ['code' => $code, 'message' => $message];
    if ($champs) {
        $erreur['champs'] = $champs;
    }
    if ($attente !== null) {
        $erreur['attente'] = $attente;
        header('Retry-After: ' . $attente);
    }
    if ($http === 401) {
        header('WWW-Authenticate: Bearer realm="Ma Bibliotheque Manga"');
    }
    reponse_json(['erreur' => $erreur], $http);
}

/* ---------------------------------------------------------------------
   5. Traitement d'une requête
   --------------------------------------------------------------------- */

/** Chemin relatif à api/ : « /Livre/api/v1/series?x=1 » → « v1/series ». */
function api_chemin(string $uri, string $script): string
{
    $chemin = (string) parse_url($uri, PHP_URL_PATH);
    $base   = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($base !== '' && str_starts_with($chemin, $base . '/')) {
        $chemin = substr($chemin, strlen($base));
    }
    return trim(rawurldecode($chemin), '/');
}

function api_serie_du_compte(PDO $pdo, int $utilisateur_id, int $id): array
{
    $req = $pdo->prepare('SELECT ' . API_COLONNES . ' FROM serie WHERE id = ? AND utilisateur_id = ?');
    $req->execute([$id, $utilisateur_id]);
    $s = $req->fetch();
    if (!$s) {
        api_erreur(404, 'serie_introuvable', 'Série introuvable.');
    }
    return $s;
}

function api_corps_lire(bool $obligatoire): array
{
    $brut = (string) file_get_contents('php://input', false, null, 0, API_CORPS_MAX + 1);
    $r = api_corps_decoder($brut, (string) ($_SERVER['CONTENT_TYPE'] ?? ''), $obligatoire);
    if ($r['erreur'] !== null) {
        api_erreur(...$r['erreur']);
    }
    return $r['corps'] ?? [];
}

function api_servir(): void
{
    global $pdo;

    /* Une exception (base qui lâche, bogue) ne doit jamais montrer son
       texte : il peut contenir une requête SQL ou un chemin. */
    set_exception_handler(static function (Throwable $e): void {
        error_log('[api] ' . get_class($e) . ' : ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        if (!headers_sent()) {
            api_erreur(500, 'erreur_interne', 'Une erreur technique est survenue.');
        }
    });

    $serveur = $_SERVER;
    if (!isset($serveur['HTTP_AUTHORIZATION']) && function_exists('getallheaders')) {
        foreach (getallheaders() ?: [] as $nom => $valeur) {
            if (strcasecmp((string) $nom, 'Authorization') === 0) {
                $serveur['HTTP_AUTHORIZATION'] = (string) $valeur;
            }
        }
    }

    /* 1. HTTPS. Pas de redirection : le jeton serait déjà parti en clair.
          Un jeton envoyé ainsi doit être considéré comme compromis. */
    if (!api_https_ok($serveur)) {
        api_erreur(403, 'https_requis', 'HTTPS est obligatoire. Si un jeton a été envoyé en clair, révoquez-le.');
    }

    /* 2. Le jeton. Le blocage par IP est lu AVANT toute autre chose. */
    $bloque = limiteur_bloque_depuis('api_jeton');
    if ($bloque > 0) {
        api_erreur(429, 'trop_d_echecs', 'Trop de jetons refusés. Réessayez plus tard.', [], $bloque);
    }

    $jeton = api_jeton_extraire($serveur);
    $moi   = ($jeton !== null && api_jeton_format_valide($jeton)) ? api_jeton_chercher($pdo, $jeton) : null;

    if ($moi === null || $moi['etat'] === 'revoque') {
        limiteur_echec('api_jeton', API_ECHECS_MAX, API_ECHECS_BLOCAGE);
        journal_securite('api_jeton_refuse', ['raison' => $jeton === null ? 'absent' : ($moi === null ? 'inconnu' : 'revoque')]);
        api_erreur(401, 'jeton_invalide', 'Jeton absent ou invalide.');
    }
    if ($moi['etat'] === 'expire') {
        api_erreur(401, 'jeton_expire', 'Ce jeton a expiré.');
    }

    $mon_id = (int) $moi['id'];

    /* 3. Frein par jeton. Une requête refusée compte aussi : sans cela,
          insister ne coûterait rien. */
    $tranche = api_requete_compter($pdo, (int) $moi['jeton_id'], API_FENETRE);
    header('X-RateLimit-Limit: ' . API_REQUETES_MAX);
    header('X-RateLimit-Remaining: ' . max(0, API_REQUETES_MAX - $tranche['essais']));
    header('X-RateLimit-Reset: ' . $tranche['reste']);
    if ($tranche['essais'] > API_REQUETES_MAX) {
        api_erreur(429, 'trop_de_requetes',
            'Limite de ' . API_REQUETES_MAX . ' requêtes par ' . API_FENETRE . ' secondes atteinte.',
            [], $tranche['reste']);
    }

    /* 4. La route. */
    $route = api_route(
        (string) ($serveur['REQUEST_METHOD'] ?? 'GET'),
        api_chemin((string) ($serveur['REQUEST_URI'] ?? ''), (string) ($serveur['SCRIPT_NAME'] ?? ''))
    );
    if (isset($route['erreur'])) {
        if ($route['erreur'] === 405) {
            header('Allow: ' . implode(', ', $route['permises']));
            api_erreur(405, 'methode_non_autorisee', 'Méthode non autorisée pour cette adresse.');
        }
        api_erreur(404, 'route_inconnue', 'Adresse inconnue.');
    }

    switch ($route['route']) {
        case 'moi':                  api_h_moi($pdo, $moi);
        case 'series.lister':        api_h_lister($pdo, $mon_id);
        case 'series.lire':          reponse_json(['serie' => api_serie_json(api_serie_du_compte($pdo, $mon_id, $route['id']))]);
        case 'series.creer':         api_h_creer($pdo, $moi);
        case 'series.modifier':      api_h_modifier($pdo, $mon_id, $route['id']);
        case 'couvertures.chercher': api_h_chercher($pdo, $moi, $route['id']);
        case 'couvertures.choisir':  api_h_choisir($pdo, $mon_id, $route['id']);
    }
    api_erreur(404, 'route_inconnue', 'Adresse inconnue.');
}

/* ---------------------------------------------------------------------
   6. Les routes
   --------------------------------------------------------------------- */

function api_h_moi(PDO $pdo, array $moi): never
{
    $req = $pdo->prepare('SELECT COUNT(*) FROM serie WHERE utilisateur_id = ?');
    $req->execute([(int) $moi['id']]);
    $illimite = $moi['forfait'] === 'illimite';

    reponse_json(['compte' => [
        'identifiant'   => $moi['identifiant'],
        'forfait'       => $moi['forfait'],
        'series'        => (int) $req->fetchColumn(),
        'series_max'    => $illimite ? null : MAX_SERIES_PAR_UTILISATEUR,
        'tome_max'      => TOME_MAX,
        'statuts'       => STATUTS,
        'requetes'      => ['max' => API_REQUETES_MAX, 'fenetre' => API_FENETRE],
        'recherches_couverture' => couverture_quota($moi) > 0
            ? ['max' => couverture_quota($moi), 'fenetre' => COUVERTURE_FENETRE]
            : null,
    ]]);
}

function api_h_lister(PDO $pdo, int $mon_id): never
{
    $p = api_liste_parametres($_GET);
    if ($p['erreurs']) {
        api_erreur(422, 'validation', 'Paramètres invalides.', $p['erreurs']);
    }

    $req = $pdo->prepare('SELECT COUNT(*) FROM serie WHERE utilisateur_id = ?' . $p['where']);
    $req->execute(array_merge([$mon_id], $p['params']));
    $total = (int) $req->fetchColumn();

    /* LIMIT et OFFSET sont des entiers déjà validés par api_liste_parametres() ;
       ils ne passent pas par un « ? » parce que PDO les enverrait en chaînes. */
    $req = $pdo->prepare(
        'SELECT ' . API_COLONNES . ' FROM serie WHERE utilisateur_id = ?' . $p['where']
        . ' ORDER BY maj_le DESC, id DESC LIMIT ' . (int) $p['limite'] . ' OFFSET ' . (int) $p['decalage']
    );
    $req->execute(array_merge([$mon_id], $p['params']));

    reponse_json([
        'series'   => array_map('api_serie_json', $req->fetchAll()),
        'total'    => $total,
        'limite'   => $p['limite'],
        'decalage' => $p['decalage'],
    ]);
}

function api_h_creer(PDO $pdo, array $moi): never
{
    $mon_id = (int) $moi['id'];
    [$d, $erreurs] = api_valider_serie(api_corps_lire(true), true);
    if ($erreurs) {
        api_erreur(422, 'validation', 'Données invalides.', $erreurs);
    }
    $d += ['auteur' => '', 'tome_actuel' => 0, 'statut' => 'cours', 'favori' => 0];

    /* Le quota est appliqué PAR LA BASE, dans l'INSERT (voir api.php). */
    $req = $pdo->prepare(
        'INSERT INTO serie (utilisateur_id, titre, auteur, tome_actuel, statut, favori)
         SELECT ?, ?, ?, ?, ?, ?
           FROM DUAL
          WHERE ? = 1
             OR (SELECT n FROM (SELECT COUNT(*) AS n FROM serie WHERE utilisateur_id = ?) AS c) < ?'
    );
    $req->execute([
        $mon_id, $d['titre'], $d['auteur'], $d['tome_actuel'], $d['statut'], $d['favori'],
        $moi['forfait'] === 'illimite' ? 1 : 0, $mon_id, MAX_SERIES_PAR_UTILISATEUR,
    ]);
    if ($req->rowCount() === 0) {
        api_erreur(422, 'quota_series', 'Limite de ' . MAX_SERIES_PAR_UTILISATEUR . ' séries par compte atteinte.');
    }

    $id = (int) $pdo->lastInsertId();
    header('Location: ' . APP_URL . '/api/v1/series/' . $id);
    reponse_json(['serie' => api_serie_json(api_serie_du_compte($pdo, $mon_id, $id))], 201);
}

function api_h_modifier(PDO $pdo, int $mon_id, int $id): never
{
    api_serie_du_compte($pdo, $mon_id, $id);

    [$d, $erreurs] = api_valider_serie(api_corps_lire(true), false);
    if (!$erreurs && !$d) {
        $erreurs['_'] = 'Aucun champ à modifier.';
    }
    if ($erreurs) {
        api_erreur(422, 'validation', 'Données invalides.', $erreurs);
    }

    /* Les noms de colonnes viennent de api_valider_serie(), jamais du client. */
    $sql = implode(', ', array_map(static fn (string $c) => $c . ' = ?', array_keys($d)));
    $pdo->prepare('UPDATE serie SET ' . $sql . ' WHERE id = ? AND utilisateur_id = ?')
        ->execute(array_merge(array_values($d), [$id, $mon_id]));

    reponse_json(['serie' => api_serie_json(api_serie_du_compte($pdo, $mon_id, $id))]);
}

/**
 * La Recherche auto, pour le tome suivant. Même règle que le site
 * (couverture_tome_vise) : une série en cours ou à commencer se regarde en
 * avant, une série terminée ou abandonnée montre le tome qu'on en a.
 * « tome » dans le corps remplace ce calcul.
 */
function api_h_chercher(PDO $pdo, array $moi, int $id): never
{
    $mon_id = (int) $moi['id'];
    $s = api_serie_du_compte($pdo, $mon_id, $id);

    [$tome, $erreurs] = api_valider_recherche(api_corps_lire(false));
    if ($erreurs) {
        api_erreur(422, 'validation', 'Données invalides.', $erreurs);
    }
    $tome ??= couverture_tome_vise((string) $s['statut'], (int) $s['tome_actuel']);
    $tome   = min($tome, TOME_MAX);

    /* Le quota est celui du COMPTE, partagé avec le site (voir api.php,
       couverture.chercher) : une seule règle, annoncée. */
    $quota   = couverture_quota($moi);
    $attente = $quota > 0 ? couverture_consommer($mon_id, $quota, COUVERTURE_FENETRE) : 0;
    if ($attente > 0) {
        api_erreur(429, 'quota_recherche',
            'Limite atteinte : ' . $quota . ' recherches par ' . couverture_tranche_lisible(COUVERTURE_FENETRE) . '.',
            [], $attente);
    }

    $resultats = chercher_couvertures((string) $s['titre'], $tome, couverture_adulte_autorise($moi));

    $retard = mangadex_attente_suggeree();
    if ($resultats === [] && $retard > 0) {
        if ($quota > 0) {
            couverture_rendre($mon_id);   // l'appel n'est pas parti : rien à décompter
        }
        api_erreur(429, 'service_occupe', 'Trop de recherches en cours sur le site.', [], $retard);
    }

    reponse_json([
        'serie_id'     => $id,
        'tome'         => $tome,
        'propositions' => array_map(static fn (array $r) => [
            'serie'       => $r['serie'],
            'mangadex_id' => $r['id'],
            'tome'        => $r['tome'],
            'exacte'      => $r['exact'],
            'url'         => $r['url'],
        ], $resultats),
        'recherches'   => $quota > 0 ? [
            'restantes' => couverture_restantes($mon_id, $quota),
            'max'       => $quota,
            'fenetre'   => COUVERTURE_FENETRE,
        ] : null,
        'filtre_actif' => COUVERTURE_CONTENU_ADULTE && !couverture_adulte_autorise($moi),
    ]);
}

/**
 * Enregistre la proposition choisie. Le lien MangaDex se DÉDUIT de l'URL
 * (mangadex_id_depuis_url) comme partout ailleurs : rien à transmettre à
 * côté, rien qui puisse diverger.
 */
function api_h_choisir(PDO $pdo, int $mon_id, int $id): never
{
    $s = api_serie_du_compte($pdo, $mon_id, $id);

    $corps = api_corps_lire(true);
    $erreurs = [];
    foreach (array_keys($corps) as $cle) {
        if ((string) $cle !== 'url') {
            $erreurs[(string) $cle] = 'Champ inconnu.';
        }
    }
    if (!api_couverture_url_acceptee($corps['url'] ?? null)) {
        $erreurs['url'] = 'Doit être l\'adresse d\'une couverture de uploads.mangadex.org, telle que proposée par la recherche.';
    }
    if ($erreurs) {
        api_erreur(422, 'validation', 'Données invalides.', $erreurs);
    }

    $url = (string) $corps['url'];
    $pdo->prepare('UPDATE serie SET couverture = ?, mangadex_id = ? WHERE id = ? AND utilisateur_id = ?')
        ->execute([$url, mangadex_id_depuis_url($url), $id, $mon_id]);

    // Une image envoyée jadis par fichier n'a plus de ligne pour la référencer.
    $ancienne = (string) $s['couverture'];
    if ($ancienne !== '' && $ancienne !== $url) {
        supprimer_image_locale($ancienne);
    }

    reponse_json(['serie' => api_serie_json(api_serie_du_compte($pdo, $mon_id, $id))]);
}
