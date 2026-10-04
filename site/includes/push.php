<?php
/* =====================================================================
   Notifications push (Web Push, sans aucune bibliothèque).

   Un navigateur qui accepte les notifications confie au site un
   « abonnement » : une adresse chez SON service de notification (Google,
   Mozilla, Apple, Microsoft) et deux clés publiques. Pour prévenir, le
   serveur POSTe un message CHIFFRÉ à cette adresse ; le service le remet
   au navigateur, même fermé, et le service worker (sw.js) l'affiche.

   Trois normes, toutes écrites ici avec ce que PHP fournit (openssl, hash) :
     - RFC 8291 + RFC 8188 : le chiffrement du message (« aes128gcm ») :
       l'échange de clés ECDH, la dérivation HKDF, AES-128-GCM ;
     - RFC 8292 (VAPID) : le site s'identifie auprès du service par un jeton
       JWT signé ES256, avec sa propre paire de clés (VAPID_PUBLIC et
       VAPID_PRIVATE du .env, à générer une fois : outils/vapid.php) ;
     - RFC 8030 : la requête HTTP elle-même.

   Ce fichier est chargé par api.php, parametres.php ET purger.php : comme
   nouveautes.php, il ne charge ni fonctions.php (en-têtes HTTP, session) ni
   rien qui en dépende — config.php suffit.

   Le chiffrement est testé contre l'exemple de la RFC 8291 (annexe A) :
   tests/cas/push_test.php. Un service de notification réel, lui, ne peut pas
   être joué par un test.
   ===================================================================== */

declare(strict_types=1);

/* --- Les services de notification connus --------------------------------

   L'adresse d'un abonnement est fournie par le NAVIGATEUR de l'utilisateur,
   et le serveur y POSTe. Sans filtre, quelqu'un enregistrerait
   « https://intranet-de-l-hebergeur/… » et ferait appeler ce qu'il veut par
   le serveur (SSRF). Seules les adresses des vrais services sont donc
   acceptées : https, port 443, et l'un de ces hôtes.

   Un service qui apparaîtrait demain (un nouveau navigateur) y sera ajouté. */
const PUSH_HOTES_EXACTS = [
    'fcm.googleapis.com',                 // Chrome, Edge, Brave, Opera, Samsung Internet, Android
    'updates.push.services.mozilla.com',  // Firefox
];
const PUSH_HOTES_SUFFIXES = [
    '.push.apple.com',                    // Safari, iPhone, iPad, Mac
    '.notify.windows.com',                // Edge (Windows Notification Service)
];

/**
 * Les options de openssl_pkey_new() pour une clé EC P-256.
 *
 * Sous Windows (XAMPP), OpenSSL ne trouve pas son fichier de configuration et
 * refuse de générer la moindre clé — « CONF_load : no such file » — tant qu'on
 * ne le lui indique pas. On le cherche à côté de PHP. Sous Linux (OVH), il est
 * au bon endroit, et cette fonction rend les options seules.
 */
function push_options_cle(): array
{
    $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
    $env = getenv('OPENSSL_CONF');
    if ($env !== false && $env !== '' && is_readable($env)) {
        return $options;
    }
    foreach (['/extras/ssl/openssl.cnf', '/../apache/conf/openssl.cnf'] as $relatif) {
        $chemin = dirname(PHP_BINARY) . $relatif;
        if (is_readable($chemin)) {
            return $options + ['config' => $chemin];
        }
    }
    return $options;
}

/** Octets → base64url, sans remplissage (le format des clés de Web Push et de VAPID). */
function push_b64u(string $octets): string
{
    return rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');
}

/** base64url → octets, ou null si ce n'en est pas. Accepte un remplissage « = » éventuel. */
function push_b64u_decode(string $texte): ?string
{
    $texte = rtrim($texte, '=');
    if ($texte === '' || preg_match('/^[A-Za-z0-9_-]+\z/', $texte) !== 1) {
        return null;
    }
    $octets = base64_decode(strtr($texte, '-_', '+/'), true);
    return $octets === false ? null : $octets;
}

/* ---------------------------------------------------------------------
   Les adresses et les clés d'un abonnement
   --------------------------------------------------------------------- */

/**
 * L'adresse d'un abonnement est-elle celle d'un service de notification connu ?
 * (Voir PUSH_HOTES_EXACTS : c'est ce qui empêche le serveur d'appeler n'importe quoi.)
 */
function push_endpoint_valide(string $url): bool
{
    if ($url === '' || strlen($url) > 1000 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
        return false;
    }
    $p = parse_url($url);
    if (!is_array($p) || ($p['scheme'] ?? '') !== 'https'
        || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])
        || (int) ($p['port'] ?? 443) !== 443) {
        return false;
    }
    $hote = strtolower((string) ($p['host'] ?? ''));
    if ($hote === '' || preg_match('/^[a-z0-9.-]+\z/', $hote) !== 1 || !isset($p['path'])) {
        return false;
    }
    if (in_array($hote, PUSH_HOTES_EXACTS, true)) {
        return true;
    }
    foreach (PUSH_HOTES_SUFFIXES as $suffixe) {
        if (str_ends_with($hote, $suffixe) && strlen($hote) > strlen($suffixe)) {
            return true;
        }
    }
    return false;
}

/** « https://hôte » d'une adresse : l'audience du jeton VAPID. */
function push_audience(string $endpoint): string
{
    $p = parse_url($endpoint);
    return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
}

/**
 * Une clé publique P-256 (65 octets : 0x04, x, y) au format PEM, ou null si ce
 * n'est pas un point valide de la courbe : OpenSSL le refuse à la lecture.
 */
function push_pem_publique(string $point): ?string
{
    if (strlen($point) !== 65 || $point[0] !== "\x04") {
        return null;
    }
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem) === false ? null : $pem;
}

/**
 * Une clé privée P-256 (32 octets) et sa publique (65) au format PEM, que
 * openssl sait lire : la structure ECPrivateKey de la RFC 5915.
 */
function push_pem_privee(string $d, string $point): ?string
{
    if (strlen($d) !== 32 || strlen($point) !== 65) {
        return null;
    }
    $der = hex2bin('30770201010420') . $d
         . hex2bin('a00a06082a8648ce3d030107a144034200') . $point;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

/**
 * Les deux clés d'un abonnement sont-elles bien formées ? p256dh : un point de
 * la courbe, auth : 16 octets. Rend [p256dh en octets, auth en octets], ou null.
 *
 * @return array{0: string, 1: string}|null
 */
function push_cles_valides(string $p256dh, string $auth): ?array
{
    $point = push_b64u_decode($p256dh);
    $secret = push_b64u_decode($auth);
    if ($point === null || $secret === null || strlen($secret) !== 16 || push_pem_publique($point) === null) {
        return null;
    }
    return [$point, $secret];
}

/* ---------------------------------------------------------------------
   Le chiffrement du message (RFC 8291, « aes128gcm »)
   --------------------------------------------------------------------- */

/**
 * Chiffre $charge pour un navigateur, et rend le corps de la requête
 * (en-tête de la RFC 8188, puis le texte chiffré), ou null si la charge est
 * trop longue ou si une clé ne se lit pas.
 *
 * $ua_public : la clé publique du navigateur (65 octets) ; $auth : son secret
 * d'authentification (16 octets).
 *
 * $sel et $ephemere ne servent qu'aux tests (l'exemple de la RFC fixe l'un et
 * l'autre) : par défaut, un sel de 16 octets au hasard et une paire de clés
 * éphémère NEUVE à chaque message — elle ne sert qu'une fois, et c'est ce qui
 * donne au message sa confidentialité persistante.
 * $ephemere : ['prive' => 32 octets, 'public' => 65 octets].
 *
 * Le détail (RFC 8291 §3.4) :
 *   secret   = ECDH(clé éphémère, clé du navigateur)
 *   IKM      = HKDF(secret, sel = auth, info = « WebPush: info\0 » ua_public as_public)
 *   CEK      = HKDF(IKM, sel = sel, info = « Content-Encoding: aes128gcm\0 »), 16 octets
 *   NONCE    = HKDF(IKM, sel = sel, info = « Content-Encoding: nonce\0 »), 12 octets
 *   chiffré  = AES-128-GCM(CEK, NONCE, charge ‖ 0x02) ‖ étiquette de 16 octets
 * (0x02 : le délimiteur du dernier — ici l'unique — enregistrement.)
 */
function push_chiffrer(string $charge, string $ua_public, string $auth, ?string $sel = null, ?array $ephemere = null): ?string
{
    /* Un seul enregistrement de 4096 octets : 16 d'étiquette et 1 de
       délimiteur en moins. On reste bien en dessous (les messages d'ici font
       moins d'un kilo-octet). */
    if (strlen($charge) > 3800 || strlen($auth) !== 16) {
        return null;
    }
    $pem_ua = push_pem_publique($ua_public);
    if ($pem_ua === null) {
        return null;
    }

    if ($ephemere === null) {
        $cle = openssl_pkey_new(push_options_cle());
        $d   = $cle === false ? false : openssl_pkey_get_details($cle);
        if ($d === false || !isset($d['ec']['x'], $d['ec']['y'], $d['ec']['d'])) {
            return null;
        }
        $ephemere = [
            'prive'  => str_pad($d['ec']['d'], 32, "\0", STR_PAD_LEFT),
            'public' => "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT),
        ];
    }
    $pem_eph = push_pem_privee($ephemere['prive'], $ephemere['public']);
    $secret  = $pem_eph === null ? false
        : openssl_pkey_derive(openssl_pkey_get_public($pem_ua), openssl_pkey_get_private($pem_eph));
    if ($secret === false) {
        return null;
    }

    $sel ??= random_bytes(16);
    $ikm   = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $ua_public . $ephemere['public'], $auth);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sel);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sel);

    $etiquette = '';
    $chiffre = openssl_encrypt($charge . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $etiquette, '', 16);
    if ($chiffre === false) {
        return null;
    }

    // En-tête RFC 8188 : sel (16), taille d'enregistrement (4, grand-boutiste), longueur de l'identifiant (1), l'identifiant (la clé éphémère).
    return $sel . pack('N', 4096) . chr(65) . $ephemere['public'] . $chiffre . $etiquette;
}

/* ---------------------------------------------------------------------
   L'identité du site auprès du service (VAPID, RFC 8292)
   --------------------------------------------------------------------- */

/**
 * Une signature ECDSA au format DER (ce que rend openssl_sign) en signature
 * « brute » r ‖ s de $taille octets chacun : le format de JWT (ES256).
 */
function push_der_vers_raw(string $der, int $taille = 32): ?string
{
    if (strlen($der) < 8 || $der[0] !== "\x30") {
        return null;
    }
    $o = 2;
    if ((ord($der[1]) & 0x80) !== 0) {      // longueur sur plusieurs octets
        $o = 2 + (ord($der[1]) & 0x7f);
    }

    $parts = [];
    for ($i = 0; $i < 2; $i++) {
        if (!isset($der[$o + 1]) || $der[$o] !== "\x02") {
            return null;
        }
        $longueur = ord($der[$o + 1]);
        $entier   = substr($der, $o + 2, $longueur);
        if (strlen($entier) !== $longueur) {
            return null;
        }
        $entier = ltrim($entier, "\0");     // l'octet de signe que DER ajoute devant un entier à bit de poids fort
        if (strlen($entier) > $taille) {
            return null;
        }
        $parts[] = str_pad($entier, $taille, "\0", STR_PAD_LEFT);
        $o += 2 + $longueur;
    }
    return $parts[0] . $parts[1];
}

/**
 * Le jeton VAPID d'un envoi : un JWT signé ES256 qui dit à quel service il est
 * destiné ($audience), jusqu'à quand il vaut ($expire, 24 h au plus d'après la
 * RFC) et qui contacter en cas de problème ($sujet : « mailto:… » ou https).
 *
 * $prive, $public : les clés VAPID en base64url (32 et 65 octets décodés).
 */
function push_jwt(string $audience, int $expire, string $sujet, string $prive, string $public): ?string
{
    $d     = push_b64u_decode($prive);
    $point = push_b64u_decode($public);
    $pem   = ($d === null || $point === null) ? null : push_pem_privee($d, $point);
    if ($pem === null) {
        return null;
    }

    $corps = push_b64u('{"typ":"JWT","alg":"ES256"}') . '.'
           . push_b64u((string) json_encode(['aud' => $audience, 'exp' => $expire, 'sub' => $sujet], JSON_UNESCAPED_SLASHES));
    $der = '';
    if (!openssl_sign($corps, $der, $pem, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    $brut = push_der_vers_raw($der);
    return $brut === null ? null : $corps . '.' . push_b64u($brut);
}

/** La notification push est-elle configurée ? Les deux clés VAPID du .env, bien formées. */
function push_actif(): bool
{
    $public = push_b64u_decode(VAPID_PUBLIC);
    $prive  = push_b64u_decode(VAPID_PRIVATE);
    return $public !== null && strlen($public) === 65 && $public[0] === "\x04"
        && $prive !== null && strlen($prive) === 32;
}

/** Le contact que le service peut joindre en cas de problème : ADMIN_EMAIL, sinon l'adresse du site. */
function push_sujet(): string
{
    return filter_var(ADMIN_EMAIL, FILTER_VALIDATE_EMAIL) ? 'mailto:' . ADMIN_EMAIL : APP_URL;
}

/* ---------------------------------------------------------------------
   Les messages
   --------------------------------------------------------------------- */

/** Un titre raccourci à $max caractères (UTF-8), sans couper un caractère en deux. */
function push_court(string $texte, int $max): string
{
    $texte = trim((string) preg_replace('/\s+/u', ' ', $texte));
    return mb_strimwidth($texte, 0, $max, '…', 'UTF-8');
}

/**
 * La notification des nouveaux tomes d'UN compte : une seule, quel que soit le
 * nombre de séries. $tomes : ['titre' => …, 'tome' => le tome à emprunter].
 *
 * Du texte brut : le service worker le passe à showNotification(), qui
 * n'interprète aucun HTML — un titre n'y devient jamais une balise.
 * 'tag' fait remplacer, au lieu d'empiler, la notification précédente restée
 * à l'écran. 'url' : où mène le clic, relatif au site.
 *
 * @return array{titre: string, corps: string, url: string, tag: string}
 */
function push_message_nouveaux_tomes(array $tomes): array
{
    $n = count($tomes);
    $lignes = [];
    foreach (array_slice($tomes, 0, 4) as $t) {
        $lignes[] = '« ' . push_court((string) ($t['titre'] ?? ''), 60) . ' » : tome ' . (int) ($t['tome'] ?? 0);
    }
    if ($n > 4) {
        $lignes[] = '… et ' . ($n - 4) . ' autre' . ($n - 4 > 1 ? 's' : '');
    }
    return [
        'titre' => $n === 1 ? 'Nouveau tome disponible' : $n . ' nouveaux tomes disponibles',
        'corps' => implode("\n", $lignes),
        'url'   => 'index.php',
        'tag'   => 'nouveaux-tomes',
    ];
}

/** Le message du bouton « Tester » des Paramètres. */
function push_message_test(): array
{
    return [
        'titre' => 'Notifications activées',
        'corps' => 'Vous serez prévenu ici dès qu\'un nouveau tome paraît.',
        'url'   => 'parametres.php',
        'tag'   => 'test',
    ];
}

/* ---------------------------------------------------------------------
   L'envoi
   --------------------------------------------------------------------- */

/**
 * Envoie $message à UN abonnement ($abonnement : endpoint, p256dh, auth).
 *
 *   'ok'     : le service a accepté le message (il le remettra) ;
 *   'expire' : le service ne connaît plus cet abonnement (404, 410) — le
 *              navigateur l'a révoqué : l'appelant l'efface ;
 *   'echec'  : tout le reste (service en panne, trop de requêtes, clé
 *              illisible, adresse refusée) — on réessaiera au passage suivant.
 *
 * $verifier_hote : l'adresse doit être celle d'un service connu. Faux
 * seulement pour les essais contre un faux service local : jamais en
 * production, où tout vient du navigateur d'un utilisateur.
 *
 * Aucune redirection n'est suivie, et le délai est borné (PUSH_TIMEOUT) :
 * l'envoi immobilise un processus PHP.
 */
function push_envoyer(array $abonnement, array $message, bool $verifier_hote = true): string
{
    $endpoint = (string) ($abonnement['endpoint'] ?? '');
    $cles     = push_cles_valides((string) ($abonnement['p256dh'] ?? ''), (string) ($abonnement['auth'] ?? ''));
    if ($cles === null || ($verifier_hote && !push_endpoint_valide($endpoint)) || !push_actif()) {
        return 'echec';
    }

    $charge = (string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $corps  = push_chiffrer($charge, $cles[0], $cles[1]);
    $jwt    = push_jwt(push_audience($endpoint), time() + 12 * 3600, push_sujet(), VAPID_PRIVATE, VAPID_PUBLIC);
    if ($corps === null || $jwt === null) {
        return 'echec';
    }

    $ch = curl_init($endpoint);
    if ($ch === false) {
        return 'echec';
    }
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $corps,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => PUSH_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => min(3, PUSH_TIMEOUT),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Authorization: vapid t=' . $jwt . ', k=' . VAPID_PUBLIC,
            'TTL: ' . PUSH_TTL,
            'Urgency: normal',
            // Un message encore en attente (appareil éteint) est REMPLACÉ par le suivant, pas empilé.
            'Topic: ' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($message['tag'] ?? 'message')),
        ],
    ]);
    curl_exec($ch);
    $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erreur = curl_error($ch);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        return 'ok';
    }
    if ($code === 404 || $code === 410) {
        return 'expire';
    }
    error_log('push: ' . parse_url($endpoint, PHP_URL_HOST) . ' a répondu ' . $code
            . ($erreur !== '' ? ' (' . $erreur . ')' : ''));
    return 'echec';
}

/* ---------------------------------------------------------------------
   Les appareils d'un compte (base de données)
   --------------------------------------------------------------------- */

/** Le nombre d'appareils qui reçoivent les notifications de ce compte. */
function push_compter(PDO $pdo, int $utilisateur_id): int
{
    $req = $pdo->prepare('SELECT COUNT(*) FROM abonnement_push WHERE utilisateur_id = ?');
    $req->execute([$utilisateur_id]);
    return (int) $req->fetchColumn();
}

/** Cet appareil (cette adresse) reçoit-il les notifications de CE compte ? */
function push_appartient(PDO $pdo, int $utilisateur_id, string $endpoint): bool
{
    $req = $pdo->prepare('SELECT 1 FROM abonnement_push WHERE endpoint_hash = ? AND utilisateur_id = ?');
    $req->execute([hash('sha256', $endpoint), $utilisateur_id]);
    return $req->fetchColumn() !== false;
}

/**
 * Enregistre un appareil pour ce compte.
 *
 *   'ok'      : enregistré, ou déjà là (les clés sont rafraîchies) ;
 *   'invalide': adresse d'un service inconnu, ou clés mal formées ;
 *   'plein'   : le compte a déjà $max appareils.
 *
 * Un navigateur n'appartient qu'à UN compte (index UNIQUE sur l'empreinte de
 * l'adresse) : celui qui l'active en dernier le reprend. Sans cela, quelqu'un
 * qui se connecte sur le poste d'un autre recevrait ses notifications — ou
 * l'inverse.
 *
 * La limite s'applique dans l'INSERT lui-même, comme celle des séries : un
 * contrôle préalable ne résiste pas à deux requêtes simultanées.
 */
function push_enregistrer(PDO $pdo, int $utilisateur_id, string $endpoint, string $p256dh, string $auth, int $max): string
{
    if (!push_endpoint_valide($endpoint) || push_cles_valides($p256dh, $auth) === null) {
        return 'invalide';
    }
    $hash = hash('sha256', $endpoint);

    $maj = $pdo->prepare(
        'UPDATE abonnement_push SET utilisateur_id = ?, endpoint = ?, p256dh = ?, auth = ? WHERE endpoint_hash = ?'
    );
    $maj->execute([$utilisateur_id, $endpoint, $p256dh, $auth, $hash]);
    $existe = $pdo->prepare('SELECT 1 FROM abonnement_push WHERE endpoint_hash = ?');
    $existe->execute([$hash]);
    if ($existe->fetchColumn() !== false) {
        return 'ok';
    }

    try {
        $ins = $pdo->prepare(
            'INSERT INTO abonnement_push (utilisateur_id, endpoint_hash, endpoint, p256dh, auth)
             SELECT ?, ?, ?, ?, ?
               FROM DUAL
              WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM abonnement_push WHERE utilisateur_id = ?) AS c) < ?'
        );
        $ins->execute([$utilisateur_id, $hash, $endpoint, $p256dh, $auth, $utilisateur_id, $max]);
    } catch (PDOException $e) {
        // Deux enregistrements simultanés du même appareil : l'autre a gagné, c'est fait.
        if ($e->getCode() === '23000') {
            return 'ok';
        }
        throw $e;
    }
    return $ins->rowCount() === 1 ? 'ok' : 'plein';
}

/** Retire un appareil de ce compte. true s'il y en avait un. */
function push_retirer(PDO $pdo, int $utilisateur_id, string $endpoint): bool
{
    $req = $pdo->prepare('DELETE FROM abonnement_push WHERE endpoint_hash = ? AND utilisateur_id = ?');
    $req->execute([hash('sha256', $endpoint), $utilisateur_id]);
    return $req->rowCount() > 0;
}

/**
 * Envoie $message à tous les appareils d'un compte. Efface ceux que le service
 * déclare périmés (un navigateur qui a révoqué la permission, une
 * réinstallation) : ils ne se rétabliraient jamais, et on y retenterait à
 * chaque passage.
 *
 * @return array{envoyes: int, expires: int, echecs: int}
 */
function push_envoyer_a_compte(PDO $pdo, int $utilisateur_id, array $message): array
{
    $bilan = ['envoyes' => 0, 'expires' => 0, 'echecs' => 0];
    $req = $pdo->prepare('SELECT id, endpoint, p256dh, auth FROM abonnement_push WHERE utilisateur_id = ?');
    $req->execute([$utilisateur_id]);

    foreach ($req->fetchAll() as $appareil) {
        $etat = push_envoyer($appareil, $message);
        if ($etat === 'ok') {
            $bilan['envoyes']++;
            $pdo->prepare('UPDATE abonnement_push SET envoye_le = NOW() WHERE id = ?')->execute([(int) $appareil['id']]);
        } elseif ($etat === 'expire') {
            $bilan['expires']++;
            $pdo->prepare('DELETE FROM abonnement_push WHERE id = ?')->execute([(int) $appareil['id']]);
        } else {
            $bilan['echecs']++;
        }
    }
    return $bilan;
}
