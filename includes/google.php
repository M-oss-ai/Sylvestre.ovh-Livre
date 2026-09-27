<?php
/* =====================================================================
   Connexion avec Google — OpenID Connect, « flux par code » + PKCE.

   Le parcours, en quatre temps (google.php les enchaîne) :

     1. « Continuer avec Google » mène à google.php, qui tire trois
        secrets à usage unique (état, nonce, vérificateur PKCE), les
        range dans la session, et renvoie le navigateur chez Google.
     2. Google authentifie la personne — mot de passe, double facteur,
        choix du compte : tout cela se passe chez lui, le site ne voit
        jamais le mot de passe Google.
     3. Google renvoie le navigateur sur google.php avec un CODE à usage
        unique et l'état. L'état doit être celui de la session : sinon,
        quelqu'un pourrait faire atterrir sa victime connectée sur SON
        compte (« login CSRF »).
     4. Le serveur échange ce code contre un « id_token » en appelant
        Google directement, avec le secret du site et le vérificateur
        PKCE. Le jeton dit QUI s'est connecté : un identifiant stable
        (« sub ») et une adresse e-mail que Google a vérifiée.

   Pourquoi la signature du jeton n'est pas vérifiée : il arrive par un
   appel HTTPS direct du serveur à Google, jamais par le navigateur. La
   spécification OpenID Connect (Core, § 3.1.3.7, point 6) admet alors
   la validation TLS du serveur de Google à la place de la signature —
   c'est ce qui évite d'embarquer une bibliothèque cryptographique dans
   un projet sans dépendances. Émetteur, destinataire, expiration et
   nonce restent vérifiés (google_verifier_revendications).

   Les fonctions ci-dessous sont pures, sauf google_echanger_code() :
   c'est ce qui permet de les tester sans réseau (tests/cas/google_test.php).
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const GOOGLE_AUTORISATION = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_JETON        = 'https://oauth2.googleapis.com/token';
const GOOGLE_EMETTEURS    = ['https://accounts.google.com', 'accounts.google.com'];

/** Le temps laissé pour aller-retour chez Google, en secondes. */
const GOOGLE_PARCOURS_MAX = 600;

/** La connexion Google est-elle configurée ? Sinon, elle n'existe pas. */
function google_actif(): bool
{
    return GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '';
}

/** L'adresse de retour déclarée dans la console Google. */
function google_redirection(): string
{
    return APP_URL . '/google.php';
}

function base64url(string $octets): string
{
    return rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');
}

function base64url_decoder(string $texte): ?string
{
    if ($texte === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $texte)) {
        return null;
    }
    $brut = base64_decode(strtr($texte, '-_', '+/') . str_repeat('=', (4 - strlen($texte) % 4) % 4), true);
    return $brut === false ? null : $brut;
}

/** Le « défi » PKCE : l'empreinte du vérificateur, que Google garde. */
function pkce_defi(string $verificateur): string
{
    return base64url(hash('sha256', $verificateur, true));
}

/**
 * L'adresse où envoyer le navigateur. « select_account » : Google
 * demande toujours quel compte utiliser — sur un appareil partagé, on
 * ne se retrouve pas connecté avec le compte de quelqu'un d'autre.
 */
function google_url_autorisation(string $etat, string $nonce, string $defi, string $client_id, string $redirection): string
{
    return GOOGLE_AUTORISATION . '?' . http_build_query([
        'client_id'             => $client_id,
        'redirect_uri'          => $redirection,
        'response_type'         => 'code',
        'scope'                 => 'openid email profile',
        'state'                 => $etat,
        'nonce'                 => $nonce,
        'code_challenge'        => $defi,
        'code_challenge_method' => 'S256',
        'prompt'                => 'select_account',
    ], '', '&', PHP_QUERY_RFC3986);
}

/** Les revendications d'un id_token (sa partie centrale), ou null. */
function google_lire_id_token(string $jwt): ?array
{
    $parties = explode('.', $jwt);
    if (count($parties) !== 3) {
        return null;
    }
    $json = base64url_decoder($parties[1]);
    $revendications = $json === null ? null : json_decode($json, true);
    return is_array($revendications) ? $revendications : null;
}

/**
 * Vérifie ce que dit le jeton. Rend '' s'il est acceptable, sinon la
 * raison du refus (pour le journal, jamais pour l'écran).
 *
 * Tolérance de 5 minutes sur les horloges : celle d'un hébergement
 * mutualisé n'est pas toujours à l'heure.
 */
function google_verifier_revendications(array $c, string $client_id, string $nonce, int $maintenant): string
{
    if (!in_array($c['iss'] ?? null, GOOGLE_EMETTEURS, true)) {
        return 'émetteur inattendu';
    }
    $aud = $c['aud'] ?? null;
    $destinataires = is_array($aud) ? $aud : [$aud];
    if ($client_id === '' || !in_array($client_id, $destinataires, true)) {
        return 'jeton destiné à une autre application';
    }
    if (is_array($aud) && count($aud) > 1 && ($c['azp'] ?? null) !== $client_id) {
        return 'jeton destiné à une autre application';
    }
    if (!is_int($c['exp'] ?? null) || $c['exp'] < $maintenant - 300) {
        return 'jeton expiré';
    }
    if (is_int($c['iat'] ?? null) && $c['iat'] > $maintenant + 300) {
        return 'jeton daté du futur';
    }
    if (!is_string($c['nonce'] ?? null) || $nonce === '' || !hash_equals($nonce, $c['nonce'])) {
        return 'nonce différent';
    }
    $sub = $c['sub'] ?? null;
    if (!is_string($sub) || $sub === '' || strlen($sub) > 255) {
        return 'identifiant Google absent';
    }
    if (!is_string($c['email'] ?? null) || !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
        return 'adresse absente';
    }
    // Une adresse non vérifiée par Google ne prouve rien : on ne s'en sert pas.
    if (($c['email_verified'] ?? false) !== true && ($c['email_verified'] ?? '') !== 'true') {
        return 'adresse non vérifiée par Google';
    }
    return '';
}

/**
 * Ce que google.php doit faire de ce compte Google.
 *
 *   $moi       le compte connecté sur le site, ou null
 *   $par_sub   le compte déjà relié à ce compte Google, ou null
 *   $par_email le compte qui porte l'adresse Google, ou null
 *
 * Deux sortes de comptes, qui ne se mélangent pas : un compte créé avec
 * une adresse e-mail ne se relie jamais à Google, et un compte créé avec
 * Google ne s'en délie jamais. D'où ces seules décisions :
 *
 *   « confirmer »     connecté, et c'est bien le compte Google de ce
 *                     compte : la personne vient de prouver son identité
 *   « refus_autre »   connecté, mais avec un autre compte Google
 *   « connecter »     compte créé avec ce compte Google : on l'ouvre
 *   « mot_de_passe »  le même, mais il a défini un mot de passe : on le
 *                     demande d'abord (google-mot-de-passe.php)
 *   « refus_adresse » l'adresse appartient déjà à un compte (créé avec
 *                     une adresse e-mail et confirmé, ou avec un autre
 *                     compte Google) : on ne relie rien
 *   « creer »         personne : on propose de créer le compte
 *
 * Un compte e-mail JAMAIS confirmé ne bloque pas l'adresse : n'importe qui
 * a pu le créer avec l'adresse d'un autre. Google vient de prouver à qui
 * elle appartient ; ce compte jamais activé cède la place à la création
 * (voir google-inscription.php).
 */
function google_decision(?array $moi, ?array $par_sub, ?array $par_email): string
{
    if ($moi !== null) {
        return $par_sub !== null && (int) $par_sub['id'] === (int) $moi['id'] ? 'confirmer' : 'refus_autre';
    }
    if ($par_sub !== null) {
        return (int) ($par_sub['a_mdp'] ?? 0) === 1 ? 'mot_de_passe' : 'connecter';
    }
    if ($par_email !== null
        && ((int) ($par_email['email_verifie'] ?? 0) === 1 || (string) ($par_email['google_sub'] ?? '') !== '')) {
        return 'refus_adresse';
    }
    return 'creer';
}

/**
 * Le compte qui attend son mot de passe après Google, ou 0.
 *
 * google.php range l'étape dans la session quand Google a confirmé un
 * compte qui a aussi un mot de passe ; google-mot-de-passe.php la lit.
 * Elle ne vaut que GOOGLE_PARCOURS_MAX secondes : au-delà, c'est une
 * session oubliée ouverte, et il faut repasser par Google.
 */
function google_etape_mdp(mixed $etape, int $maintenant): int
{
    if (!is_array($etape) || (int) ($etape['le'] ?? 0) < $maintenant - GOOGLE_PARCOURS_MAX) {
        return 0;
    }
    return max(0, (int) ($etape['id'] ?? 0));
}

/**
 * L'identifiant PROPOSÉ à la création d'un compte Google, tiré de
 * l'adresse : « Marie.Dupont+manga@gmail.com » → « Marie.Dupont ». La
 * personne le garde ou le change (google-inscription.php). Même règle
 * que l'inscription (3 à 30 caractères : lettres, chiffres, . _ -).
 */
function identifiant_depuis_google(string $email): string
{
    $local = explode('@', $email)[0];
    $local = explode('+', $local)[0];
    // Les accents perdent leur diacritique plutôt que de disparaître.
    $ascii = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $local) : $local;
    $propre = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '', $ascii), '._-');
    $propre = substr($propre, 0, 24);
    return strlen($propre) >= 3 ? $propre : 'lecteur';
}

/**
 * Échange le code contre les jetons, en appelant Google depuis le
 * serveur. Rend la réponse décodée, ou null (l'erreur va au journal).
 */
function google_echanger_code(string $code, string $verificateur): ?array
{
    $ch = curl_init(GOOGLE_JETON);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $code,
            'client_id'     => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri'  => google_redirection(),
            'grant_type'    => 'authorization_code',
            'code_verifier' => $verificateur,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        // Jamais désactivées : c'est la vérification TLS qui garantit que
        // le jeton vient bien de Google (voir l'en-tête du fichier).
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $reponse = curl_exec($ch);
    $code_http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erreur = curl_error($ch);
    curl_close($ch);

    $donnees = is_string($reponse) ? json_decode($reponse, true) : null;
    if ($code_http !== 200 || !is_array($donnees)) {
        error_log('google: échange du code refusé (' . $code_http
            . ($erreur !== '' ? ', ' . $erreur : '')
            . (is_array($donnees) && isset($donnees['error']) ? ', ' . (string) $donnees['error'] : '') . ')');
        return null;
    }
    return $donnees;
}

/** Le bouton « Continuer avec Google », ou rien si Google n'est pas configuré. */
function bouton_google(string $texte = 'Continuer avec Google', string $retour = ''): string
{
    if (!google_actif()) {
        return '';
    }
    $cible = 'google.php' . ($retour !== '' ? '?retour=' . rawurlencode($retour) : '');
    // Le « G » aux couleurs de Google, dessiné (attributs de présentation,
    // que la CSP accepte — ce n'est pas un style en ligne).
    return '<a class="btn btn-google full" href="' . htmlspecialchars($cible, ENT_QUOTES) . '">'
        . '<svg class="google-g" viewBox="0 0 48 48" aria-hidden="true" focusable="false">'
        . '<path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>'
        . '<path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>'
        . '<path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>'
        . '<path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>'
        . '</svg><span>' . htmlspecialchars($texte, ENT_QUOTES) . '</span></a>';
}
