<?php
/* =====================================================================
   includes/push.php — les notifications push, sans bibliothèque.

   Ce qui se prouve sans service de notification :

     - le CHIFFREMENT, contre l'exemple de la RFC 8291 (annexe A) : mêmes
       clés, même sel, le même corps octet pour octet — c'est ce qui assure
       qu'un vrai service le déchiffrera ;
     - un aller-retour avec un déchiffrement écrit à part (côté navigateur) ;
     - la signature VAPID, relue avec la clé publique, comme le ferait le service ;
     - les adresses acceptées (c'est la garde contre le SSRF), les clés, les
       messages ;
     - le câblage, lu dans les sources.

   Ce qu'un test ne joue pas : un vrai service (Google, Mozilla, Apple), qui
   n'accepterait de toute façon pas un message de test.

   Les clés VAPID de ce fichier sont celles de la paire de l'exemple de la RFC 8291,
   PUBLIQUE depuis 2016 : elles n'ont aucune valeur.
   ===================================================================== */

declare(strict_types=1);

putenv('VAPID_PUBLIC=BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
putenv('VAPID_PRIVATE=yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');

require __DIR__ . '/../lanceur.php';
require_once CHEMIN_SITE . '/includes/push.php';
require_once __DIR__ . '/../processus.php';

function source(string $chemin): string
{
    $racine = $chemin === 'livre.sql' ? CHEMIN_PROJET : CHEMIN_SITE;
    // Fins de ligne normalisées : le dépôt est en LF, le répertoire de travail en CRLF.
    return str_replace("\r\n", "\n", (string) file_get_contents($racine . '/' . $chemin));
}

/** Le corps d'une fonction, de sa signature à la suivante : de quoi y chercher un motif. */
function corps_de(string $source, string $fonction): string
{
    $debut = strpos($source, 'function ' . $fonction . '(');
    if ($debut === false) {
        return '';
    }
    $fin = strpos($source, "\nfunction ", $debut + 1);
    return substr($source, $debut, $fin === false ? null : $fin - $debut);
}

/** Octets d'un texte base64url (échoue le test s'il n'en est pas un). */
function octets(string $b64u): string
{
    $o = push_b64u_decode($b64u);
    if ($o === null) {
        throw new RuntimeException('base64url invalide dans le test : ' . $b64u);
    }
    return $o;
}

/** Une paire de clés P-256 neuve : ['prive' => 32 octets, 'public' => 65 octets]. */
function paire_de_cles(): array
{
    $cle = openssl_pkey_new(push_options_cle());
    $d   = openssl_pkey_get_details($cle);
    return [
        'prive'  => str_pad($d['ec']['d'], 32, "\0", STR_PAD_LEFT),
        'public' => "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT),
    ];
}

/**
 * Le côté NAVIGATEUR : ce qu'il fait d'un corps reçu (RFC 8291 §3.4). Écrit à part
 * du chiffrement, pour qu'un aller-retour ne soit pas qu'une symétrie.
 */
function dechiffrer_comme_un_navigateur(string $corps, array $ua, string $auth): ?string
{
    $sel       = substr($corps, 0, 16);
    $taille    = unpack('N', substr($corps, 16, 4))[1];
    $longueur  = ord($corps[20]);
    $as_public = substr($corps, 21, $longueur);
    $reste     = substr($corps, 21 + $longueur);
    if ($taille !== 4096 || $longueur !== 65) {
        return null;
    }

    $secret = openssl_pkey_derive(
        openssl_pkey_get_public(push_pem_publique($as_public)),
        openssl_pkey_get_private(push_pem_privee($ua['prive'], $ua['public']))
    );
    $ikm   = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $ua['public'] . $as_public, $auth);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sel);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sel);

    $clair = openssl_decrypt(substr($reste, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($reste, -16));
    if ($clair === false) {
        return null;
    }
    // Le dernier octet est le délimiteur 0x02 (RFC 8188), éventuellement précédé de zéros de remplissage.
    return rtrim($clair, "\0") !== '' && str_ends_with(rtrim($clair, "\0"), "\x02") ? substr(rtrim($clair, "\0"), 0, -1) : null;
}

/** Une signature « brute » r‖s en DER, pour openssl_verify (l'inverse de push_der_vers_raw). */
function raw_vers_der(string $raw): string
{
    $entier = static function (string $n): string {
        $n = ltrim($n, "\0");
        if ($n === '' || (ord($n[0]) & 0x80)) {
            $n = "\0" . $n;
        }
        return "\x02" . chr(strlen($n)) . $n;
    };
    $corps = $entier(substr($raw, 0, 32)) . $entier(substr($raw, 32, 32));
    return "\x30" . chr(strlen($corps)) . $corps;
}

const PORTEE_ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

groupe('push_chiffrer() — l\'exemple de la RFC 8291, annexe A');

test('mêmes clés, même sel : le même corps, octet pour octet', function () {
    $corps = push_chiffrer(
        'When I grow up, I want to be a watermelon',
        octets('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
        octets('BTBZMqHH6r4Tts7J_aSIgg'),
        octets('DGv6ra1nlYgDCS1FRnbzlw'),
        [
            'prive'  => octets('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'),
            'public' => octets('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8'),
        ]
    );
    vrai($corps !== null, 'un corps est rendu');
    egale(
        'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
        push_b64u((string) $corps),
        'identique à l\'exemple de la RFC : tout service le déchiffrera'
    );
});

groupe('push_chiffrer() — un aller-retour avec un déchiffrement à part');

test('un message chiffré pour un navigateur se déchiffre avec SA clé privée', function () {
    $ua = paire_de_cles();
    $auth = random_bytes(16);
    $message = '{"titre":"Nouveau tome disponible","corps":"« Berserk » : tome 44"}';
    $corps = push_chiffrer($message, $ua['public'], $auth);
    vrai($corps !== null, 'chiffré');
    egale($message, dechiffrer_comme_un_navigateur((string) $corps, $ua, $auth), 'et lu tel quel, accents compris');
});

test('l\'en-tête est celui de la RFC 8188 : sel, taille d\'enregistrement, clé éphémère', function () {
    $ua = paire_de_cles();
    $corps = (string) push_chiffrer('x', $ua['public'], random_bytes(16));
    egale(4096, unpack('N', substr($corps, 16, 4))[1], 'enregistrement de 4096 octets');
    egale(65, ord($corps[20]), 'l\'identifiant est la clé éphémère : 65 octets');
    egale("\x04", $corps[21], 'un point non compressé');
    // 16 (sel) + 4 + 1 + 65 + (1 octet de charge + 1 délimiteur) + 16 (étiquette GCM)
    egale(16 + 4 + 1 + 65 + 2 + 16, strlen($corps), 'la taille attendue pour une charge d\'un octet');
});

test('chaque message a son sel et sa clé éphémère : deux envois ne se ressemblent pas', function () {
    $ua = paire_de_cles();
    $auth = random_bytes(16);
    $a = (string) push_chiffrer('le même message', $ua['public'], $auth);
    $b = (string) push_chiffrer('le même message', $ua['public'], $auth);
    differe($a, $b, 'un autre sel, une autre clé : le service ne peut pas reconnaître un message répété');
    differe(substr($a, 0, 16), substr($b, 0, 16), 'le sel diffère');
    differe(substr($a, 21, 65), substr($b, 21, 65), 'la clé éphémère diffère');
});

test('un autre navigateur ne peut pas lire le message', function () {
    $moi = paire_de_cles();
    $autre = paire_de_cles();
    $auth = random_bytes(16);
    $corps = (string) push_chiffrer('secret', $moi['public'], $auth);
    estNul(dechiffrer_comme_un_navigateur($corps, $autre, $auth), 'une autre clé privée : l\'étiquette GCM refuse');
    estNul(dechiffrer_comme_un_navigateur($corps, $moi, random_bytes(16)), 'un autre secret d\'authentification aussi');
});

test('un corps modifié est refusé à la lecture', function () {
    $ua = paire_de_cles();
    $auth = random_bytes(16);
    $corps = (string) push_chiffrer('intact', $ua['public'], $auth);
    $corps[strlen($corps) - 20] = $corps[strlen($corps) - 20] ^ "\x01";
    estNul(dechiffrer_comme_un_navigateur($corps, $ua, $auth), 'un bit change : l\'étiquette ne correspond plus');
});

test('une charge trop longue, une clé qui n\'en est pas une, un secret mal formé : null', function () {
    $ua = paire_de_cles();
    $auth = random_bytes(16);
    estNul(push_chiffrer(str_repeat('x', 3801), $ua['public'], $auth), 'au-delà de ce qui tient dans un enregistrement');
    vrai(push_chiffrer(str_repeat('x', 3800), $ua['public'], $auth) !== null, 'la limite elle-même passe');
    estNul(push_chiffrer('x', str_repeat("\x04", 65), $auth), 'un point qui n\'est pas sur la courbe');
    estNul(push_chiffrer('x', "\x02" . str_repeat('a', 32), $auth), 'une clé compressée (33 octets)');
    estNul(push_chiffrer('x', $ua['public'], 'trop court'), 'un secret d\'authentification qui n\'a pas 16 octets');
    estNul(push_chiffrer('x', '', $auth), 'une clé vide');
});

groupe('push_b64u() et push_b64u_decode()');

test('un aller-retour sur toutes les longueurs, sans remplissage', function () {
    for ($n = 1; $n <= 70; $n++) {
        $o = random_bytes($n);
        $t = push_b64u($o);
        sans('=', $t, 'pas de remplissage, longueur ' . $n);
        sans('+', $t, 'pas de +');
        sans('/', $t, 'pas de /');
        egale($o, push_b64u_decode($t), 'aller-retour, longueur ' . $n);
    }
});

test('ce qui n\'est pas du base64url est refusé', function () {
    foreach (['', 'ab+c', 'ab/c', 'a b', 'é', "ab\n", '='] as $mauvais) {
        estNul(push_b64u_decode($mauvais), var_export($mauvais, true));
    }
    egale("\x01\x02\x03", push_b64u_decode('AQID=='), 'un remplissage « = » éventuel est toléré');
});

groupe('push_endpoint_valide() — la garde contre le SSRF');

test('les services de notification connus sont acceptés', function () {
    foreach ([
        'https://fcm.googleapis.com/fcm/send/abc',                 // Chrome, Edge, Brave, Opera, Samsung
        'https://fcm.googleapis.com/wp/xyz',
        'https://updates.push.services.mozilla.com/wpush/v2/abc',  // Firefox
        'https://web.push.apple.com/QGB-abc',                      // Safari, iPhone
        'https://api.sandbox.push.apple.com/3/device/abc',
        'https://wns2-par02p.notify.windows.com/w/?token=abc',     // Edge (WNS)
    ] as $url) {
        vrai(push_endpoint_valide($url), $url);
    }
});

test('tout le reste est refusé : le serveur n\'appelle pas ce qu\'un utilisateur lui désigne', function () {
    foreach ([
        'http://fcm.googleapis.com/fcm/send/abc'            => 'pas de https',
        'https://127.0.0.1/x'                               => 'adresse de la machine',
        'https://localhost/x'                               => 'localhost',
        'https://[::1]/x'                                   => 'IPv6',
        'https://169.254.169.254/latest/meta-data/'         => 'métadonnées d\'un hébergeur',
        'https://10.0.0.5/x'                                => 'réseau interne',
        'https://intranet/x'                                => 'nom interne',
        'https://exemple.test/x'                            => 'un site quelconque',
        'https://fcm.googleapis.com.hameconnage.test/x'     => 'le nom du service en préfixe',
        'https://hameconnage-fcm.googleapis.com.test/x'     => 'idem',
        'https://notfcm.googleapis.com/x'                   => 'un autre sous-domaine de Google',
        'https://evilpush.apple.com/x'                      => 'suffixe sans le point',
        'https://push.apple.com/x'                          => 'le suffixe seul, sans sous-domaine',
        'https://notify.windows.com/x'                      => 'idem',
        'https://user:mdp@fcm.googleapis.com/x'             => 'des identifiants dans l\'adresse',
        'https://fcm.googleapis.com:8443/x'                 => 'un autre port',
        'https://fcm.googleapis.com'                        => 'sans chemin',
        'https://fcm.googleapis.com/x#fragment'             => 'un fragment',
        'file:///etc/passwd'                                => 'un fichier',
        'javascript:alert(1)'                               => 'javascript',
        'ftp://fcm.googleapis.com/x'                        => 'un autre protocole',
        ''                                                  => 'vide',
        "https://fcm.googleapis.com/x\r\nHost: evil.test"   => 'un retour à la ligne',
        'https://fcm.googleapis.com/x y'                    => 'un espace',
        'https://' . str_repeat('a', 1000) . '.push.apple.com/x' => 'trop longue',
    ] as $url => $pourquoi) {
        faux(push_endpoint_valide((string) $url), $pourquoi);
    }
});

test('la casse de l\'hôte n\'y change rien', function () {
    vrai(push_endpoint_valide('https://FCM.GoogleAPIs.com/fcm/send/abc'), 'les noms d\'hôte ne distinguent pas la casse');
});

test('push_audience() : le schéma et l\'hôte, rien d\'autre', function () {
    egale('https://fcm.googleapis.com', push_audience('https://fcm.googleapis.com/fcm/send/abc?x=1'), 'sans chemin ni requête');
    egale('https://web.push.apple.com', push_audience('https://web.push.apple.com/QGB-abc'), 'Apple');
});

groupe('Les clés d\'un abonnement');

test('push_pem_publique() ne lit que des points de la courbe', function () {
    $ua = paire_de_cles();
    vrai(push_pem_publique($ua['public']) !== null, 'un vrai point');
    estNul(push_pem_publique(str_repeat("\x04", 65)), 'hors de la courbe');
    estNul(push_pem_publique("\x03" . substr($ua['public'], 1)), 'sans le préfixe 0x04');
    estNul(push_pem_publique(substr($ua['public'], 0, 64)), 'trop court');
    estNul(push_pem_publique(''), 'vide');
});

test('push_cles_valides() : un point, et un secret de 16 octets', function () {
    $ua = paire_de_cles();
    $r = push_cles_valides(push_b64u($ua['public']), push_b64u(random_bytes(16)));
    vrai($r !== null && $r[0] === $ua['public'] && strlen($r[1]) === 16, 'les deux pièces, en octets');
    estNul(push_cles_valides(push_b64u($ua['public']), push_b64u(random_bytes(15))), 'secret trop court');
    estNul(push_cles_valides(push_b64u($ua['public']), push_b64u(random_bytes(17))), 'secret trop long');
    estNul(push_cles_valides(push_b64u(str_repeat("\x04", 65)), push_b64u(random_bytes(16))), 'point hors de la courbe');
    estNul(push_cles_valides('pas du base64url !', push_b64u(random_bytes(16))), 'p256dh illisible');
    estNul(push_cles_valides(push_b64u($ua['public']), ''), 'secret vide');
});

test('push_pem_privee() : 32 et 65 octets, rien d\'autre', function () {
    $k = paire_de_cles();
    vrai(push_pem_privee($k['prive'], $k['public']) !== null, 'une clé complète');
    estNul(push_pem_privee('court', $k['public']), 'privée trop courte');
    estNul(push_pem_privee($k['prive'], 'court'), 'publique trop courte');
    vrai(openssl_pkey_get_private((string) push_pem_privee($k['prive'], $k['public'])) !== false, 'openssl la relit');
});

groupe('VAPID — le jeton qui identifie le site');

test('push_der_vers_raw() : r‖s, chacun sur 32 octets, avec ou sans l\'octet de signe', function () {
    // r commence par un bit à 1 : DER ajoute un 0x00 devant (33 octets). s est court : DER l\'écrit sur moins de 32 octets.
    $r = "\xff" . str_repeat("\x11", 31);
    $s = "\x00\x00" . str_repeat("\x22", 30);
    $der = raw_vers_der($r . $s);
    egale($r . $s, push_der_vers_raw($der), 'l\'aller-retour avec un entier court et un entier à bit de poids fort');
    egale(64, strlen((string) push_der_vers_raw($der)), '64 octets, toujours');
});

test('push_der_vers_raw() refuse ce qui n\'est pas une signature', function () {
    estNul(push_der_vers_raw(''), 'vide');
    estNul(push_der_vers_raw('pas du DER du tout'), 'du texte');
    estNul(push_der_vers_raw("\x30\x06\x02\x01\x01\x02\x01"), 'tronquée');
    estNul(push_der_vers_raw("\x30\x45\x02\x21" . str_repeat("\x01", 33) . "\x02\x20" . str_repeat("\x01", 32)), 'un entier de 33 octets sans octet de signe');
});

test('push_jwt() : trois parties, les bonnes revendications, et une signature que la clé publique relit', function () {
    $exp = time() + 3600;
    $jwt = push_jwt('https://fcm.googleapis.com', $exp, 'mailto:admin@exemple.test', VAPID_PRIVATE, VAPID_PUBLIC);
    vrai($jwt !== null, 'un jeton');
    $parties = explode('.', (string) $jwt);
    egale(3, count($parties), 'en-tête, revendications, signature');

    egale(['typ' => 'JWT', 'alg' => 'ES256'], json_decode((string) push_b64u_decode($parties[0]), true), 'l\'en-tête : ES256');
    egale(['aud' => 'https://fcm.googleapis.com', 'exp' => $exp, 'sub' => 'mailto:admin@exemple.test'],
        json_decode((string) push_b64u_decode($parties[1]), true), 'audience, expiration, contact');

    $signature = (string) push_b64u_decode($parties[2]);
    egale(64, strlen($signature), 'la signature « brute » de 64 octets (ES256), pas du DER');
    egale(1, openssl_verify($parties[0] . '.' . $parties[1], raw_vers_der($signature),
        (string) push_pem_publique(octets(VAPID_PUBLIC)), OPENSSL_ALGO_SHA256),
        'le service la vérifie avec la clé publique du site');
});

test('le jeton ne vaut que pour SON audience : une autre signature ne vérifie pas', function () {
    $jwt = (string) push_jwt('https://fcm.googleapis.com', time() + 3600, 'mailto:a@b.test', VAPID_PRIVATE, VAPID_PUBLIC);
    [$h, $c, $s] = explode('.', $jwt);
    $falsifie = push_b64u((string) json_encode(['aud' => 'https://web.push.apple.com', 'exp' => time() + 3600, 'sub' => 'mailto:a@b.test']));
    egale(0, openssl_verify($h . '.' . $falsifie, raw_vers_der((string) push_b64u_decode($s)),
        (string) push_pem_publique(octets(VAPID_PUBLIC)), OPENSSL_ALGO_SHA256), 'changer l\'audience invalide la signature');
});

test('une clé mal formée : null, jamais un jeton non signé', function () {
    estNul(push_jwt('https://x', time() + 60, 'mailto:a@b.test', 'pas une clé', VAPID_PUBLIC), 'privée illisible');
    estNul(push_jwt('https://x', time() + 60, 'mailto:a@b.test', VAPID_PRIVATE, 'pas une clé'), 'publique illisible');
    estNul(push_jwt('https://x', time() + 60, 'mailto:a@b.test', push_b64u(random_bytes(10)), VAPID_PUBLIC), 'privée trop courte');
});

test('push_actif() : les deux clés du .env, bien formées', function () {
    vrai(push_actif(), 'les clés de ce fichier sont valides');
    $b = executer_php(CHEMIN_PROJET . '/tests/outils/afficher-push-actif.php', [], ['VAPID_PUBLIC' => '', 'VAPID_PRIVATE' => '']);
    contient('ACTIF=non', $b['sortie'], 'sans clés : inactif');
    $b = executer_php(CHEMIN_PROJET . '/tests/outils/afficher-push-actif.php', [], ['VAPID_PUBLIC' => 'abc', 'VAPID_PRIVATE' => 'def']);
    contient('ACTIF=non', $b['sortie'], 'des clés qui n\'en sont pas : inactif aussi');
    $b = executer_php(CHEMIN_PROJET . '/tests/outils/afficher-push-actif.php', [], [
        'VAPID_PUBLIC' => VAPID_PUBLIC, 'VAPID_PRIVATE' => '']);
    contient('ACTIF=non', $b['sortie'], 'la clé privée manque : inactif');
});

test('push_sujet() : un contact que le service peut joindre', function () {
    $sujet = push_sujet();
    vrai(str_starts_with($sujet, 'mailto:') || str_starts_with($sujet, 'http'), 'mailto: ou une adresse du site');
});

groupe('Les messages');

test('un seul nouveau tome : le titre de la série et le numéro', function () {
    $m = push_message_nouveaux_tomes([['titre' => 'Berserk', 'tome' => 44]]);
    egale('Nouveau tome disponible', $m['titre'], 'au singulier');
    egale('« Berserk » : tome 44', $m['corps'], 'la série et le tome à emprunter');
    egale('index.php', $m['url'], 'le clic mène à la bibliothèque');
    egale('nouveaux-tomes', $m['tag'], 'un tag : le message suivant remplace celui-ci');
});

test('plusieurs séries : UNE notification, qui les liste', function () {
    $m = push_message_nouveaux_tomes([
        ['titre' => 'Berserk', 'tome' => 44], ['titre' => 'One Piece', 'tome' => 115], ['titre' => 'Vagabond', 'tome' => 38],
    ]);
    egale('3 nouveaux tomes disponibles', $m['titre'], 'le compte');
    egale("« Berserk » : tome 44\n« One Piece » : tome 115\n« Vagabond » : tome 38", $m['corps'], 'une ligne par série');
});

test('au-delà de quatre séries, le reste est résumé', function () {
    $tomes = [];
    for ($i = 1; $i <= 6; $i++) {
        $tomes[] = ['titre' => 'Série ' . $i, 'tome' => $i];
    }
    $m = push_message_nouveaux_tomes($tomes);
    egale('6 nouveaux tomes disponibles', $m['titre'], 'le compte total');
    egale(5, count(explode("\n", $m['corps'])), 'quatre séries et une ligne de reste');
    contient('… et 2 autres', $m['corps'], 'le reste, au pluriel');
    contient('… et 1 autre', push_message_nouveaux_tomes(array_slice(array_map(
        static fn ($i) => ['titre' => 'S' . $i, 'tome' => $i], range(1, 5)), 0, 5))['corps'], 'un seul de reste : au singulier');
});

test('un titre démesuré ou sur plusieurs lignes est ramené à une ligne courte', function () {
    $m = push_message_nouveaux_tomes([['titre' => "Une\r\nsérie\n\nsur plusieurs lignes " . str_repeat('très long ', 20), 'tome' => 2]]);
    sans("\n", $m['corps'], 'une seule ligne');
    vrai(mb_strlen($m['corps'], 'UTF-8') < 90, 'bien plus court que le titre d\'origine : ' . mb_strlen($m['corps'], 'UTF-8'));
    contient('…', $m['corps'], 'la coupure se voit');
});

test('un titre n\'est jamais interprété : du texte, tel quel', function () {
    $m = push_message_nouveaux_tomes([['titre' => '<script>alert(1)</script>', 'tome' => 1]]);
    contient('<script>alert(1)</script>', $m['corps'], 'repris tel quel (le worker passe à showNotification : aucun HTML)');
});

test('une entrée sans titre ni tome ne casse rien', function () {
    $m = push_message_nouveaux_tomes([[]]);
    egale('«  » : tome 0', $m['corps'], 'une ligne vide plutôt qu\'une erreur');
});

test('le message d\'essai des Paramètres', function () {
    $m = push_message_test();
    egale('Notifications activées', $m['titre'], 'le titre');
    egale('parametres.php', $m['url'], 'on reste dans les Paramètres');
    differe(push_message_nouveaux_tomes([['titre' => 'x', 'tome' => 1]])['tag'], $m['tag'], 'un autre tag : l\'essai ne remplace pas une vraie annonce');
});

test('la charge JSON d\'un message tient largement dans un enregistrement', function () {
    $tomes = [];
    for ($i = 1; $i <= 50; $i++) {
        $tomes[] = ['titre' => str_repeat('é', 200), 'tome' => $i];
    }
    $charge = json_encode(push_message_nouveaux_tomes($tomes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    vrai(strlen((string) $charge) < 1500, 'même 50 séries aux titres démesurés : ' . strlen((string) $charge) . ' octets');
});

groupe('push_envoyer() — ce qu\'il refuse avant tout appel réseau');

test('une adresse inconnue, des clés mal formées : « echec », sans appel', function () {
    $ua = paire_de_cles();
    $bon = ['endpoint' => PORTEE_ENDPOINT, 'p256dh' => push_b64u($ua['public']), 'auth' => push_b64u(random_bytes(16))];
    egale('echec', push_envoyer(['endpoint' => 'https://127.0.0.1/x'] + $bon, push_message_test()), 'adresse hors des services connus');
    egale('echec', push_envoyer(['p256dh' => 'abc'] + $bon, push_message_test()), 'clé publique illisible');
    egale('echec', push_envoyer(['auth' => 'abc'] + $bon, push_message_test()), 'secret illisible');
    egale('echec', push_envoyer([], push_message_test()), 'abonnement vide');
});

groupe('outils/vapid.php — le générateur de clés');

test('il écrit deux lignes VAPID_ que push_actif() accepte, et la paire est cohérente', function () {
    $r = executer_php(CHEMIN_PROJET . '/outils/vapid.php');
    egale(0, $r['code'], 'le script réussit');
    preg_match('/^VAPID_PUBLIC=(\S+)$/m', $r['sortie'], $pub);
    preg_match('/^VAPID_PRIVATE=(\S+)$/m', $r['sortie'], $pri);
    vrai(isset($pub[1], $pri[1]), 'les deux lignes sont là');

    egale(65, strlen(octets($pub[1])), 'la publique : 65 octets');
    egale(32, strlen(octets($pri[1])), 'la privée : 32 octets');
    // La paire est cohérente si une signature faite avec l'une se vérifie avec l'autre.
    $jwt = (string) push_jwt('https://fcm.googleapis.com', time() + 60, 'mailto:a@b.test', $pri[1], $pub[1]);
    [$h, $c, $s] = explode('.', $jwt);
    egale(1, openssl_verify($h . '.' . $c, raw_vers_der((string) push_b64u_decode($s)),
        (string) push_pem_publique(octets($pub[1])), OPENSSL_ALGO_SHA256), 'la clé publique relit ce que la privée signe');
});

test('deux appels donnent deux paires différentes', function () {
    $a = executer_php(CHEMIN_PROJET . '/outils/vapid.php')['sortie'];
    $b = executer_php(CHEMIN_PROJET . '/outils/vapid.php')['sortie'];
    differe($a, $b, 'une paire neuve à chaque fois');
});

test('hors de la ligne de commande, il ne répond rien', function () {
    contient('PHP_SAPI !== \'cli\'', source_racine('outils/vapid.php'), 'refuse un appel web');
});

/** Un fichier de la racine du dépôt (hors de site/). */
function source_racine(string $chemin): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_PROJET . '/' . $chemin));
}

groupe('Le service worker et le manifeste');

test('manifest.webmanifest est du JSON valide, installable, avec des icônes qui existent', function () {
    $m = json_decode(source('manifest.webmanifest'), true);
    vrai(is_array($m), 'du JSON');
    egale('standalone', $m['display'] ?? null, 'ouvert comme une application : ce qu\'iOS exige pour les notifications');
    egale('index.php', $m['start_url'] ?? null, 'démarre sur la bibliothèque');
    vrai(!empty($m['name']) && !empty($m['short_name']), 'un nom et un nom court');
    foreach ($m['icons'] ?? [] as $icone) {
        $fichier = CHEMIN_SITE . '/' . $icone['src'];
        vrai(is_file($fichier), $icone['src'] . ' existe');
        $taille = getimagesize($fichier);
        egale($icone['sizes'], $taille[0] . 'x' . $taille[1], $icone['src'] . ' a la taille annoncée');
        egale('image/png', $taille['mime'], $icone['src'] . ' est un PNG');
    }
    vrai(count($m['icons'] ?? []) >= 2, 'au moins les deux tailles 192 et 512');
});

test('l\'icône d\'écran d\'accueil d\'un iPhone existe, en 180 px', function () {
    $taille = getimagesize(CHEMIN_SITE . '/img/icone-apple.png');
    egale([180, 180], [$taille[0], $taille[1]], '180 x 180');
});

test('sw.js : reçoit les messages, affiche TOUJOURS une notification, ne met rien en cache', function () {
    $sw = source('sw.js');
    contient('addEventListener("push"', $sw, 'il écoute les messages');
    contient('addEventListener("notificationclick"', $sw, 'et les clics');
    contient('showNotification(', $sw, 'il affiche');
    sans('addEventListener("fetch"', $sw, 'aucune interception des requêtes : le site reste ce qu\'il est');
    sans('caches.', $sw, 'aucun cache');
    sans('importScripts', $sw, 'aucune dépendance');
    sans('innerHTML', $sw, 'jamais de HTML');
});

test('les pages portent le manifeste et l\'icône, et les Paramètres chargent push.js AVANT settings.js', function () {
    foreach (['index.php', 'parametres.php'] as $page) {
        $p = source($page);
        contient('<link rel="manifest" href="manifest.webmanifest">', $p, $page . ' : le manifeste');
        contient('<link rel="apple-touch-icon" href="img/icone-apple.png">', $p, $page . ' : l\'icône');
    }
    $p = source('parametres.php');
    vrai(strpos($p, "actif('js/push.js')") < strpos($p, "actif('js/settings.js')"), 'settings.js lit window.Push au chargement : push.js d\'abord');
});

test('.htaccess : le manifeste a son type, le service worker n\'a jamais de cache', function () {
    $h = source('.htaccess');
    contient('AddType application/manifest+json .webmanifest', $h, 'le type du manifeste');
    contient('<FilesMatch "^sw\.js$">', $h, 'une règle pour sw.js');
    $regle = substr($h, (int) strpos($h, '<FilesMatch "^sw'), 300);
    contient('ExpiresActive Off', $regle, 'pas d\'expiration d\'un an');
    contient('Cache-Control "no-cache"', $regle, 'revérifié à chaque visite');
});

test('js/settings.js : userVisibleOnly, la clé du site, et jamais d\'abonnement fait tout seul', function () {
    $js = source('js/settings.js');
    contient('userVisibleOnly: true', $js, 'chaque message montre une notification : exigé par les navigateurs');
    contient('applicationServerKey: PN.cleServeur($push.dataset.cle)', $js, 'la clé VAPID du site');
    contient('register("sw.js", { updateViaCache: "none" })', $js, 'le worker est revérifié à chaque visite');
    // L'abonnement n'est créé que dans activerPush(), lui-même appelé par le « change » de l'interrupteur.
    egale(1, substr_count($js, 'pushManager.subscribe('), 'une seule création d\'abonnement');
    $debut = (int) strpos($js, 'async function activerPush()');
    $fin   = (int) strpos($js, 'async function desactiverPush()');
    $subscribe = (int) strpos($js, 'pushManager.subscribe(');
    vrai($subscribe > $debut && $subscribe < $fin, 'et elle est dans activerPush()');
    contient('await (veut ? activerPush() : desactiverPush())', $js, 'appelée seulement par le « change » de la personne');
    contient('Notification.requestPermission()', $js, 'l\'autorisation est demandée');
    vrai(strpos($js, 'Notification.requestPermission()') < strpos($js, 'pushManager.getSubscription()', $debut),
        'D\'ABORD, dans le geste de la personne (Safari refuse sinon)');
});

groupe('Câblage serveur');

test('api.php : les actions push, le compte de la session, le serveur reste maître', function () {
    $api = source('api.php');
    foreach (['push.etat', 'push.abonner', 'push.desabonner', 'push.tester'] as $action) {
        contient("case '" . $action . "': {", $api, $action . ' existe');
    }
    contient('push_enregistrer(' . "\n" . '            $pdo, $mon_id,', $api, 'l\'appareil est rangé au compte DE LA SESSION');
    sans("\$_POST['utilisateur", $api, 'aucun compte lu dans le POST');
    contient('PUSH_MAX_APPAREILS', $api, 'le plafond d\'appareils');
    contient("\$_SESSION['push_test_le']", $api, 'un essai à la fois : 20 secondes entre deux');
    $bloc = substr($api, (int) strpos($api, "case 'push.tester': {"), 1400);
    vrai(strpos($bloc, 'session_write_close();') < strpos($bloc, 'push_envoyer_a_compte('), 'la session est libérée AVANT d\'attendre les services');
});

test('push_enregistrer() : la limite est dans l\'INSERT, l\'adresse est vérifiée, un navigateur n\'a qu\'un compte', function () {
    $c = corps_de(source('includes/push.php'), 'push_enregistrer');
    contient('push_endpoint_valide($endpoint)', $c, 'l\'adresse est vérifiée AVANT d\'être rangée');
    contient('SELECT n FROM (SELECT COUNT(*) AS n FROM abonnement_push WHERE utilisateur_id = ?) AS c) < ?', $c,
        'le plafond s\'applique dans l\'INSERT lui-même : deux requêtes simultanées ne le dépassent pas');
    contient('WHERE endpoint_hash = ?', $c, 'une même adresse : mise à jour, pas de doublon');
    contient('SET utilisateur_id = ?', $c, 'le dernier compte qui l\'active reprend le navigateur');
});

test('push_envoyer() : pas de redirection, délai borné, adresse vérifiée, jeton à usage court', function () {
    $c = corps_de(source('includes/push.php'), 'push_envoyer');
    contient('CURLOPT_FOLLOWLOCATION => false', $c, 'aucune redirection suivie');
    contient('CURLOPT_TIMEOUT        => PUSH_TIMEOUT', $c, 'un délai');
    contient('$verifier_hote && !push_endpoint_valide($endpoint)', $c, 'l\'adresse est revérifiée à l\'envoi');
    contient('time() + 12 * 3600', $c, 'le jeton VAPID vaut 12 h : sous le maximum de 24 h de la RFC');
    contient("'Content-Encoding: aes128gcm'", $c, 'le chiffrement annoncé');
    contient("'TTL: ' . PUSH_TTL", $c, 'la durée de garde');
    contient("\$code === 404 || \$code === 410", $c, 'un appareil périmé est reconnu');
});

test('push_envoyer_a_compte() : efface les appareils périmés, note les envois réussis', function () {
    $c = corps_de(source('includes/push.php'), 'push_envoyer_a_compte');
    contient('DELETE FROM abonnement_push WHERE id = ?', $c, 'un appareil périmé ne se rétablira jamais');
    contient('SET envoye_le = NOW()', $c, 'le dernier envoi accepté');
});

test('push.php : ne charge ni fonctions.php, ni rien qui en dépende (le cron l\'inclut)', function () {
    $src = preg_replace('#/\*.*?\*/#s', '', source('includes/push.php'));
    sans('require', (string) $src, 'aucun require : config.php suffit');
    sans('session_', (string) $src, 'aucune session');
    sans('header(', (string) $src, 'aucun en-tête HTTP');
});

test('purger.php : une notification par compte, jamais d\'e-mail, jamais à un compte bloqué', function () {
    $cron = source('purger.php');
    contient("require_once __DIR__ . '/includes/push.php';", $cron, 'le cron charge push.php');
    sans("require_once __DIR__ . '/includes/fonctions.php'", $cron, 'et toujours pas fonctions.php');
    contient('push_envoyer_a_compte($pdo, $utilisateur_id, push_message_nouveaux_tomes($tomes))', $cron, 'une notification par compte');
    contient("=== 'bloque'", $cron, 'jamais à un compte bloqué');
    contient('if (push_actif())', $cron, 'rien ne part sans clés VAPID');
    sans('avertir_nouveaux_tomes', $cron, 'plus d\'e-mail pour les nouveaux tomes');
});

test('plus aucune trace de l\'e-mail des nouveaux tomes', function () {
    foreach (['includes/mailer.php', 'api.php', 'purger.php', 'parametres.php', 'js/settings.js', 'includes/fonctions.php'] as $f) {
        $src = source($f);
        sans('avis_nouveaux_tomes', $src, $f);
        sans('notif_tomes', $src, $f . ' : la colonne n\'est plus lue ni écrite');
        sans('compte.notifications', $src, $f);
    }
});

test('parametres.php : la carte n\'existe que si le push est configuré et le compte pas bloqué', function () {
    $p = source('parametres.php');
    $carte = (int) strpos($p, 'id="notifications"');
    contient('<?php if (!$bloque && push_actif()): ?>', substr($p, max(0, $carte - 80), 80), 'les deux conditions');
    contient('data-cle="<?= e(VAPID_PUBLIC) ?>"', $p, 'la clé PUBLIQUE, jamais la privée');
    sans('VAPID_PRIVATE', $p, 'la clé privée n\'apparaît dans aucune page');
    contient('disabled', substr($p, (int) strpos($p, 'id="push-actif"'), 120), 'l\'interrupteur attend que le script sache ce que le navigateur permet');
});

test('la clé privée n\'est jamais écrite dans une page, une réponse d\'API ni un journal', function () {
    foreach (['index.php', 'parametres.php', 'api.php', 'js/settings.js', 'js/push.js', 'sw.js'] as $f) {
        sans('VAPID_PRIVATE', source($f), $f);
    }
    $push = preg_replace('#/\*.*?\*/#s', '', source('includes/push.php'));
    contient('VAPID_PRIVATE', (string) $push, 'elle ne sert qu\'à signer, dans push_envoyer()');
    sans('error_log(' . "'" . 'push: ' . "' . VAPID", (string) $push, 'jamais journalisée');
});

test('livre.sql : migration 14, rejouable, l\'appareil disparaît avec le compte', function () {
    $sql = source('livre.sql');
    $m14 = substr($sql, (int) strpos($sql, '--  14. Notifications push'));
    vrai($m14 !== '', 'la migration 14 existe');
    contient('CREATE TABLE IF NOT EXISTS `abonnement_push`', $m14, 'rejouable : « IF NOT EXISTS »');
    contient('UNIQUE KEY `uk_abonnement_push_hash` (`endpoint_hash`)', $m14, 'un navigateur n\'appartient qu\'à un compte');
    contient('ON DELETE CASCADE', $m14, 'les appareils disparaissent avec le compte');
    sans('DROP COLUMN `notif_tomes`;', substr($m14, (int) strpos($m14, 'CREATE TABLE')), 'aucune colonne n\'est supprimée : aucune donnée perdue');
    sans('ADD COLUMN IF NOT EXISTS', $m14, 'jamais cette extension MariaDB');
    contient('`endpoint`       VARCHAR(1000) NOT NULL', $m14, 'l\'adresse tient dans la colonne (push_endpoint_valide : 1000 au plus)');
    sans('`notif_tomes`  TINYINT', $sql, 'le schéma neuf n\'a plus la colonne d\'e-mail');
});

test('les clés VAPID, le plafond d\'appareils, le TTL et le délai sont dans le .env', function () {
    $cfg = source('includes/config.php');
    foreach (['VAPID_PUBLIC', 'VAPID_PRIVATE', 'PUSH_MAX_APPAREILS', 'PUSH_TTL', 'PUSH_TIMEOUT'] as $c) {
        contient("define('" . $c . "'", $cfg, $c . ' est une constante de config.php');
    }
    $env = source_racine('.env.example');
    foreach (['VAPID_PUBLIC', 'VAPID_PRIVATE', 'PUSH_MAX_APPAREILS'] as $c) {
        contient($c, $env, $c . ' est expliqué dans .env.example');
    }
    sans('VAPID_PRIVATE=y', $env, 'et aucune VRAIE clé n\'est dans l\'exemple');
});
