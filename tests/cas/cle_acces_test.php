<?php
/* =====================================================================
   Clés d'accès (WebAuthn) — tout ce qui se décide sans navigateur ni base.

   Le test joue le rôle de l'AUTHENTIFICATEUR : il fabrique de vraies clés
   avec OpenSSL, écrit de vrais objets CBOR, signe comme le ferait un
   gestionnaire de mots de passe, puis demande à includes/cle_acces.php de
   les accepter — et de refuser chacune des altérations. La table cle_acces
   (cle_ajouter, cle_trouver…) n'est pas testée ici : elle exige la base.
   ===================================================================== */

declare(strict_types=1);

putenv('CLE_ACCES_MAX=');          // vides : ce sont les valeurs par défaut qu'on veut lire
putenv('CLE_ACCES_DEFI_DUREE=');

require __DIR__ . '/../lanceur.php';
require_once CHEMIN_PROJET . '/includes/cle_acces.php';

const RP     = 'livre.exemple.test';
const ORIGIN = 'https://livre.exemple.test';
const DEFI   = 'defi-de-test-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

/* ---------------------------------------------------------------------
   L'authentificateur simulé : un petit écrivain CBOR et un signataire
   --------------------------------------------------------------------- */

function cb_tete(int $majeur, int $valeur): string
{
    if ($valeur < 24) {
        return chr(($majeur << 5) | $valeur);
    }
    if ($valeur < 256) {
        return chr(($majeur << 5) | 24) . chr($valeur);
    }
    if ($valeur < 65536) {
        return chr(($majeur << 5) | 25) . pack('n', $valeur);
    }
    if ($valeur < 4294967296) {
        return chr(($majeur << 5) | 26) . pack('N', $valeur);
    }
    return chr(($majeur << 5) | 27) . pack('J', $valeur);
}
function cb_entier(int $n): string { return $n >= 0 ? cb_tete(0, $n) : cb_tete(1, -1 - $n); }
function cb_octets(string $s): string { return cb_tete(2, strlen($s)) . $s; }
function cb_texte(string $s): string { return cb_tete(3, strlen($s)) . $s; }
function cb_liste(string ...$el): string { return cb_tete(4, count($el)) . implode('', $el); }
/** @param array<int, array{0: string, 1: string}> $paires clés et valeurs DÉJÀ encodées */
function cb_table(array $paires): string
{
    return cb_tete(5, count($paires)) . implode('', array_map(static fn ($p) => $p[0] . $p[1], $paires));
}

/**
 * Où OpenSSL trouve sa configuration pour FABRIQUER une clé (jamais pour en
 * vérifier une). Sous Windows, PHP ne la trouve pas toujours seul : le test
 * la cherche aux endroits habituels. Le site, lui, ne fabrique aucune clé.
 */
function config_openssl(): array
{
    foreach ([getenv('OPENSSL_CONF') ?: '', dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
              'C:/xampp/apache/conf/openssl.cnf', '/etc/ssl/openssl.cnf'] as $chemin) {
        if ($chemin !== '' && is_file($chemin)) {
            return ['config' => $chemin];
        }
    }
    return [];
}

/** Une paire de clés EC P-256 : [clé privée, x, y]. */
function cle_ec(): array
{
    $cle = openssl_pkey_new(config_openssl() + ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if ($cle === false) {
        throw new RuntimeException('OpenSSL ne fabrique pas de clé EC : ' . openssl_error_string());
    }
    $ec = openssl_pkey_get_details($cle)['ec'];
    return [$cle, str_pad($ec['x'], 32, "\0", STR_PAD_LEFT), str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)];
}

function cose_ec(string $x, string $y, int $algo = -7, int $courbe = 1): string
{
    return cb_table([
        [cb_entier(1), cb_entier(2)], [cb_entier(3), cb_entier($algo)],
        [cb_entier(-1), cb_entier($courbe)], [cb_entier(-2), cb_octets($x)], [cb_entier(-3), cb_octets($y)],
    ]);
}

/** Une paire de clés RSA 2048 : [clé privée, n, e]. */
function cle_rsa(): array
{
    $cle = openssl_pkey_new(config_openssl() + ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    if ($cle === false) {
        throw new RuntimeException('OpenSSL ne fabrique pas de clé RSA : ' . openssl_error_string());
    }
    $rsa = openssl_pkey_get_details($cle)['rsa'];
    return [$cle, $rsa['n'], $rsa['e']];
}

function cose_rsa(string $n, string $e): string
{
    return cb_table([
        [cb_entier(1), cb_entier(3)], [cb_entier(3), cb_entier(-257)],
        [cb_entier(-1), cb_octets($n)], [cb_entier(-2), cb_octets($e)],
    ]);
}

/** Les « authenticator data » : avec une clé jointe (création) ou sans (connexion). */
function donnees_auth(string $rp = RP, int $drapeaux = 0x01, int $compteur = 0, ?string $id = null, ?string $cose = null): string
{
    $d = hash('sha256', $rp, true) . chr($drapeaux) . pack('N', $compteur);
    if ($id !== null) {
        $d .= str_repeat("\0", 16) . pack('n', strlen($id)) . $id . $cose;
    }
    return $d;
}

function client_data(string $type, string $defi = DEFI, string $origine = ORIGIN, array $en_plus = []): string
{
    return (string) json_encode(array_merge(
        ['type' => $type, 'challenge' => $defi, 'origin' => $origine, 'crossOrigin' => false], $en_plus
    ));
}

function attestation(string $auth): string
{
    return cb_table([
        [cb_texte('fmt'), cb_texte('none')], [cb_texte('attStmt'), cb_table([])],
        [cb_texte('authData'), cb_octets($auth)],
    ]);
}

function signer($cle_privee, string $auth, string $client): string
{
    openssl_sign($auth . hash('sha256', $client, true), $signature, $cle_privee, OPENSSL_ALGO_SHA256);
    return $signature;
}

function refuse_cbor(string $octets, string $description): void
{
    leve(CleInvalide::class, static fn () => cbor_lire($octets), $description);
}

/* ---------------------------------------------------------------------
   1. CBOR
   --------------------------------------------------------------------- */
groupe('cbor_lire — ce que WebAuthn emploie');

test('les entiers, de 0 à 2^32, positifs et négatifs', function () {
    egale(0, cbor_lire(cb_entier(0)), 'zéro');
    egale(23, cbor_lire(cb_entier(23)), 'le plus grand entier sur un octet de tête');
    egale(24, cbor_lire(cb_entier(24)), 'premier entier sur deux octets');
    egale(255, cbor_lire(cb_entier(255)), '255');
    egale(256, cbor_lire(cb_entier(256)), '256 (deux octets)');
    egale(65536, cbor_lire(cb_entier(65536)), '65536 (quatre octets)');
    egale(4294967296, cbor_lire(cb_entier(4294967296)), '2^32 (huit octets)');
    egale(-1, cbor_lire(cb_entier(-1)), '-1');
    egale(-7, cbor_lire(cb_entier(-7)), '-7 : ES256');
    egale(-257, cbor_lire(cb_entier(-257)), '-257 : RS256');
});

test('les chaînes, les listes et les tables imbriquées', function () {
    egale("\x01\x02", cbor_lire(cb_octets("\x01\x02")), 'une chaîne d\'octets');
    egale('fmt', cbor_lire(cb_texte('fmt')), 'un texte');
    egale('', cbor_lire(cb_octets('')), 'une chaîne vide');
    egale([1, 'a', [2]], cbor_lire(cb_liste(cb_entier(1), cb_texte('a'), cb_liste(cb_entier(2)))), 'une liste imbriquée');
    egale(
        ['fmt' => 'none', -1 => 'x', 'dedans' => [5 => true]],
        cbor_lire(cb_table([
            [cb_texte('fmt'), cb_texte('none')], [cb_entier(-1), cb_texte('x')],
            [cb_texte('dedans'), cb_table([[cb_entier(5), "\xf5"]])],
        ])),
        'une table à clés entières et textuelles'
    );
});

test('vrai, faux et nul', function () {
    egale(true, cbor_lire("\xf5"), 'vrai');
    egale(false, cbor_lire("\xf4"), 'faux');
    estNul(cbor_lire("\xf6"), 'nul');
});

test('cbor_decoder avance la position et laisse la suite', function () {
    $octets = cb_entier(300) . cb_texte('suite');
    $pos = 0;
    egale(300, cbor_decoder($octets, $pos), 'le premier élément');
    egale(3, $pos, 'la position suit le premier élément');
    egale('suite', cbor_decoder($octets, $pos), 'le second élément');
    egale(strlen($octets), $pos, 'tout est lu');
});

test('les données mal formées sont refusées', function () {
    refuse_cbor('', 'rien du tout');
    refuse_cbor(substr(cb_octets('abcdef'), 0, 4), 'une chaîne tronquée');
    refuse_cbor("\x19\x01", 'un entier dont les octets manquent');
    refuse_cbor(cb_entier(1) . "\x00", 'des octets en trop après l\'élément');
    refuse_cbor("\x5f\x41a\xff", 'une longueur indéfinie');
    refuse_cbor("\xc1\x01", 'une étiquette');
    refuse_cbor("\xfb\x3f\xf0\x00\x00\x00\x00\x00\x00", 'un flottant');
    refuse_cbor("\x5a\xff\xff\xff\xff", 'une chaîne qui annonce 4 Go');
    refuse_cbor("\x9b\x7f\xff\xff\xff\xff\xff\xff\xff", 'une liste qui annonce 2^63 éléments');
    refuse_cbor("\x1b\xff\xff\xff\xff\xff\xff\xff\xff", 'un entier au-delà de ce que PHP porte');
    refuse_cbor(cb_table([[cb_entier(1), cb_entier(1)], [cb_entier(1), cb_entier(2)]]), 'une clé de table en double');
    refuse_cbor("\xa1\x80\x00", 'une liste en guise de clé de table');
});

test('l\'imbrication est limitée', function () {
    $ok = str_repeat("\x81", CLE_CBOR_PROFONDEUR) . "\x00";
    vrai(is_array(cbor_lire($ok)), 'la profondeur permise passe');
    refuse_cbor(str_repeat("\x81", CLE_CBOR_PROFONDEUR + 1) . "\x00", 'un cran de plus est refusé');
});

test('une donnée énorme est refusée avant d\'être lue', function () {
    refuse_cbor(cb_octets(str_repeat('a', CLE_CBOR_TAILLE_MAX)), 'plus de ' . CLE_CBOR_TAILLE_MAX . ' octets');
});

/* ---------------------------------------------------------------------
   2. Données d'authentification
   --------------------------------------------------------------------- */
groupe('cle_lire_donnees_auth');

test('une connexion : 37 octets, pas de clé', function () {
    $a = cle_lire_donnees_auth(donnees_auth(RP, 0x05, 7));
    egale(hash('sha256', RP, true), $a['rp_hash'], 'l\'empreinte de l\'adresse du site');
    vrai($a['presence'], 'présence');
    vrai($a['verifiee'], 'vérification de la personne');
    egale(7, $a['compteur'], 'le compteur');
    estNul($a['identifiant'], 'pas d\'identifiant');
    estNul($a['cle'], 'pas de clé');
});

test('une création : l\'identifiant et la clé publique', function () {
    [, $x, $y] = cle_ec();
    $a = cle_lire_donnees_auth(donnees_auth(RP, 0x41, 0, 'identifiant-1234', cose_ec($x, $y)));
    egale('identifiant-1234', $a['identifiant'], 'l\'identifiant de la clé');
    egale($x, $a['cle'][-2], 'la coordonnée x');
    faux($a['verifiee'], 'sans vérification de la personne');
});

test('des extensions à la suite sont lues sans servir', function () {
    [, $x, $y] = cle_ec();
    $d = donnees_auth(RP, 0xC1, 0, 'id', cose_ec($x, $y)) . cb_table([[cb_texte('credProtect'), cb_entier(2)]]);
    $a = cle_lire_donnees_auth($d);
    egale('id', $a['identifiant'], 'la clé est lue malgré les extensions');
});

test('les données mal formées sont refusées', function () {
    [, $x, $y] = cle_ec();
    $cose = cose_ec($x, $y);
    leve(CleInvalide::class, fn () => cle_lire_donnees_auth(substr(donnees_auth(), 0, 36)), 'moins de 37 octets');
    leve(CleInvalide::class, fn () => cle_lire_donnees_auth(donnees_auth(RP, 0x01) . 'x'), 'des octets en trop');
    leve(CleInvalide::class, fn () => cle_lire_donnees_auth(donnees_auth(RP, 0x41, 0, str_repeat('a', CLE_ID_MAX + 1), $cose)), 'un identifiant trop long');
    leve(CleInvalide::class, fn () => cle_lire_donnees_auth(donnees_auth(RP, 0x41, 0, '', $cose)), 'un identifiant vide');
    leve(CleInvalide::class, fn () => cle_lire_donnees_auth(substr(donnees_auth(RP, 0x41, 0, 'id', $cose), 0, -5)), 'une clé tronquée');
    leve(CleInvalide::class, fn () => cle_lire_donnees_auth(donnees_auth(RP, 0x41, 0, 'id', cb_entier(3))), 'une clé qui n\'est pas une table');
});

/* ---------------------------------------------------------------------
   3. Clé COSE → PEM
   --------------------------------------------------------------------- */
groupe('cle_cose_vers_pem');

test('ES256 : le PEM est lu par OpenSSL et vérifie une signature de la clé privée', function () {
    [$privee, $x, $y] = cle_ec();
    $pem = cle_cose_vers_pem(cbor_lire(cose_ec($x, $y)));
    contient('BEGIN PUBLIC KEY', $pem, 'un PEM');
    $detail = openssl_pkey_get_details(openssl_pkey_get_public($pem));
    egale(OPENSSL_KEYTYPE_EC, $detail['type'], 'c\'est une clé EC');
    egale('prime256v1', $detail['ec']['curve_name'], 'sur la courbe P-256');
    openssl_sign('message', $signature, $privee, OPENSSL_ALGO_SHA256);
    egale(1, openssl_verify('message', $signature, $pem, OPENSSL_ALGO_SHA256), 'la signature se vérifie');
});

test('RS256 : le PEM est lu par OpenSSL et vérifie une signature de la clé privée', function () {
    [$privee, $n, $e] = cle_rsa();
    $pem = cle_cose_vers_pem(cbor_lire(cose_rsa($n, $e)));
    $detail = openssl_pkey_get_details(openssl_pkey_get_public($pem));
    egale(OPENSSL_KEYTYPE_RSA, $detail['type'], 'c\'est une clé RSA');
    egale(2048, $detail['bits'], 'de 2048 bits');
    openssl_sign('message', $signature, $privee, OPENSSL_ALGO_SHA256);
    egale(1, openssl_verify('message', $signature, $pem, OPENSSL_ALGO_SHA256), 'la signature se vérifie');
});

test('les clés qu\'on ne sait pas ou ne veut pas vérifier sont refusées', function () {
    [, $x, $y] = cle_ec();
    [, $n, $e] = cle_rsa();
    $refus = fn (string $cose, string $quoi) => leve(CleInvalide::class, fn () => cle_cose_vers_pem(cbor_lire($cose)), $quoi);
    $refus(cose_ec($x, $y, -7, 2), 'une autre courbe que P-256');
    $refus(cose_ec(substr($x, 1), $y), 'une coordonnée trop courte');
    $refus(cose_ec($x, $y, -8), 'un autre algorithme (EdDSA)');
    $refus(cose_ec(str_repeat("\x01", 32), str_repeat("\x01", 32)), 'un point hors de la courbe');
    $refus(cose_rsa(substr($n, 0, 128), $e), 'une clé RSA de 1024 bits');
    $refus(cose_rsa($n, ''), 'un exposant vide');
    $refus(cb_table([[cb_entier(1), cb_entier(1)], [cb_entier(3), cb_entier(-8)]]), 'un type de clé inconnu (OKP)');
    leve(CleInvalide::class, fn () => cle_cose_vers_pem([]), 'une clé vide');
});

/* ---------------------------------------------------------------------
   4. Création
   --------------------------------------------------------------------- */
groupe('cle_verifier_creation');

/** Une création valide, que chaque test dégrade d'un seul point. */
function creation(array $changer = []): array
{
    [$privee, $x, $y] = cle_ec();
    $id = $changer['id'] ?? random_bytes(32);
    $auth = $changer['auth'] ?? donnees_auth($changer['rp'] ?? RP, $changer['drapeaux'] ?? 0x41, $changer['compteur'] ?? 0, $id, $changer['cose'] ?? cose_ec($x, $y));
    return [
        'id'    => $id,
        'x'     => $x,
        'y'     => $y,
        'client' => $changer['client'] ?? client_data('webauthn.create'),
        'objet' => $changer['objet'] ?? attestation($auth),
    ];
}

function verifier_creation(array $c, string $defi = DEFI, string $origine = ORIGIN, string $rp = RP): array
{
    return cle_verifier_creation($c['client'], $c['objet'], $defi, $origine, $rp);
}

test('une création valide rend l\'identifiant, le PEM et le compteur', function () {
    $c = creation(['compteur' => 3]);
    $r = verifier_creation($c);
    vrai($r['ok'], 'acceptée (' . ($r['raison'] ?? '') . ')');
    egale($c['id'], $r['identifiant'], 'l\'identifiant de la clé');
    egale(3, $r['compteur'], 'le compteur');
    contient('BEGIN PUBLIC KEY', $r['cle_pem'], 'la clé publique, en PEM');
});

test('l\'attestation n\'est pas vérifiée : un format « packed » passe', function () {
    [, $x, $y] = cle_ec();
    $id = random_bytes(16);
    $auth = donnees_auth(RP, 0x41, 0, $id, cose_ec($x, $y));
    $objet = cb_table([
        [cb_texte('fmt'), cb_texte('packed')],
        [cb_texte('attStmt'), cb_table([[cb_texte('alg'), cb_entier(-7)], [cb_texte('sig'), cb_octets('nimporte quoi')]])],
        [cb_texte('authData'), cb_octets($auth)],
    ]);
    vrai(verifier_creation(creation(['objet' => $objet]))['ok'], 'acceptée sans lire la signature d\'attestation');
});

test('chaque altération est refusée', function () {
    [, $x, $y] = cle_ec();
    $mauvais = [
        'défi différent'        => [creation(), 'autre-defi', ORIGIN, RP],
        'autre origine'         => [creation(), DEFI, 'https://pirate.test', RP],
        'autre type de demande' => [creation(['client' => client_data('webauthn.get')]), DEFI, ORIGIN, RP],
        'page incorporée'       => [creation(['client' => client_data('webauthn.create', DEFI, ORIGIN, ['crossOrigin' => true])]), DEFI, ORIGIN, RP],
        'client illisible'      => [creation(['client' => 'pas du json']), DEFI, ORIGIN, RP],
        'autre site (rpId)'     => [creation(['rp' => 'pirate.test']), DEFI, ORIGIN, RP],
        'présence absente'      => [creation(['drapeaux' => 0x40]), DEFI, ORIGIN, RP],
        'aucune clé jointe'     => [creation(['drapeaux' => 0x01, 'auth' => donnees_auth()]), DEFI, ORIGIN, RP],
        'objet qui n\'est pas CBOR' => [creation(['objet' => 'xxxx']), DEFI, ORIGIN, RP],
        'objet sans authData'   => [creation(['objet' => cb_table([[cb_texte('fmt'), cb_texte('none')]])]), DEFI, ORIGIN, RP],
        'courbe inconnue'       => [creation(['cose' => cose_ec($x, $y, -7, 3)]), DEFI, ORIGIN, RP],
    ];
    foreach ($mauvais as $nom => [$c, $defi, $origine, $rp]) {
        $r = verifier_creation($c, $defi, $origine, $rp);
        faux($r['ok'], $nom . ' est refusée');
        vrai(($r['raison'] ?? '') !== '', $nom . ' dit pourquoi');
    }
});

test('un défi vide n\'est jamais accepté, même s\'il « correspond »', function () {
    $c = creation(['client' => client_data('webauthn.create', '')]);
    faux(verifier_creation($c, '')['ok'], 'défi vide des deux côtés');
});

/* ---------------------------------------------------------------------
   5. Connexion
   --------------------------------------------------------------------- */
groupe('cle_verifier_connexion');

/** Une clé EC enregistrée, et de quoi signer pour elle. */
function une_cle_ec(): array
{
    [$privee, $x, $y] = cle_ec();
    return ['privee' => $privee, 'pem' => cle_cose_vers_pem(cbor_lire(cose_ec($x, $y)))];
}

function connexion(array $cle, array $changer = []): array
{
    $client = $changer['client'] ?? client_data('webauthn.get');
    $auth   = $changer['auth'] ?? donnees_auth(RP, $changer['drapeaux'] ?? 0x01, $changer['compteur'] ?? 0);
    $sig    = $changer['signature'] ?? signer($cle['privee'], $auth, $client);
    return cle_verifier_connexion(
        $client, $auth, $sig, $changer['defi'] ?? DEFI, $changer['origine'] ?? ORIGIN,
        $changer['rp'] ?? RP, $changer['pem'] ?? $cle['pem'], $changer['connu'] ?? 0
    );
}

test('une connexion valide, ES256', function () {
    $r = connexion(une_cle_ec());
    vrai($r['ok'], 'acceptée (' . ($r['raison'] ?? '') . ')');
    egale(0, $r['compteur'], 'le compteur d\'une clé synchronisée reste à 0');
});

test('une connexion valide, RS256', function () {
    [$privee, $n, $e] = cle_rsa();
    $cle = ['privee' => $privee, 'pem' => cle_cose_vers_pem(cbor_lire(cose_rsa($n, $e)))];
    vrai(connexion($cle)['ok'], 'acceptée');
});

test('chaque altération est refusée', function () {
    $cle   = une_cle_ec();
    $autre = une_cle_ec();
    $client = client_data('webauthn.get');
    $auth   = donnees_auth();
    $signature = signer($cle['privee'], $auth, $client);
    $faux_client = client_data('webauthn.get', DEFI, ORIGIN, ['extra' => 'x']);

    $mauvais = [
        'défi différent'        => ['defi' => 'autre-defi'],
        'autre origine'         => ['origine' => 'https://pirate.test'],
        'autre type de demande' => ['client' => client_data('webauthn.create')],
        'autre site (rpId)'     => ['rp' => 'pirate.test'],
        'présence absente'      => ['drapeaux' => 0x00],
        'signature d\'une autre clé' => ['pem' => $autre['pem']],
        'signature vide'        => ['signature' => ''],
        'signature altérée'     => ['signature' => substr($signature, 0, -1) . chr(ord($signature[-1]) ^ 1), 'client' => $client, 'auth' => $auth],
        'message client modifié après signature' => ['signature' => $signature, 'client' => $faux_client, 'auth' => $auth],
        'données d\'authentification modifiées'  => ['signature' => $signature, 'client' => $client, 'auth' => donnees_auth(RP, 0x01, 5)],
        'données illisibles'    => ['auth' => 'court', 'signature' => $signature],
    ];
    foreach ($mauvais as $nom => $changer) {
        $r = connexion($cle, $changer);
        faux($r['ok'], $nom . ' est refusée');
        vrai(($r['raison'] ?? '') !== '', $nom . ' dit pourquoi');
    }
});

test('le compteur de signatures', function () {
    egale(true, cle_compteur_valide(0, 0), 'une clé synchronisée : 0 puis 0');
    egale(true, cle_compteur_valide(5, 6), 'il avance');
    egale(false, cle_compteur_valide(5, 5), 'il ne bouge pas : refusé');
    egale(false, cle_compteur_valide(5, 3), 'il recule : refusé');
    egale(false, cle_compteur_valide(5, 0), 'il retombe à 0 : refusé');
    egale(true, cle_compteur_valide(0, 1), 'il commence à compter');
    $cle = une_cle_ec();
    vrai(connexion($cle, ['compteur' => 6, 'connu' => 5])['ok'], 'la connexion accepte un compteur qui avance');
    faux(connexion($cle, ['compteur' => 5, 'connu' => 5])['ok'], 'la connexion refuse un compteur à l\'arrêt');
});

/* ---------------------------------------------------------------------
   6. Adresse du site, défi, options
   --------------------------------------------------------------------- */
groupe('cle_rp_id et cle_origine');

test('l\'adresse du site vient d\'APP_URL', function () {
    egale('livre.sylvestre.ovh', cle_rp_id('https://livre.sylvestre.ovh'), 'l\'hôte');
    egale('https://livre.sylvestre.ovh', cle_origine('https://livre.sylvestre.ovh'), 'l\'origine');
    egale('livre.sylvestre.ovh', cle_rp_id('https://LIVRE.Sylvestre.OVH/Livre'), 'sans la casse ni le chemin');
    egale('localhost', cle_rp_id('http://localhost/Livre'), 'le développement local');
    egale('http://localhost', cle_origine('http://localhost/Livre'), 'sans chemin');
    egale('http://localhost:8080', cle_origine('http://localhost:8080'), 'un port inhabituel reste');
    egale('https://exemple.test', cle_origine('https://exemple.test:443'), 'le port par défaut disparaît');
    egale('http://exemple.test', cle_origine('http://exemple.test:80'), 'le port 80 aussi');
});

groupe('Le défi');

test('un défi sert à un usage, pour un compte, et expire', function () {
    $s = ['defi' => 'abc', 'usage' => 'creation', 'id' => 42, 'le' => 1000];
    egale('abc', cle_defi_valide($s, 'creation', 42, 1000, 300), 'à l\'instant');
    egale('abc', cle_defi_valide($s, 'creation', 42, 1300, 300), 'à la limite');
    estNul(cle_defi_valide($s, 'creation', 42, 1301, 300), 'expiré');
    estNul(cle_defi_valide($s, 'connexion', 42, 1000, 300), 'un autre usage');
    estNul(cle_defi_valide($s, 'creation', 43, 1000, 300), 'un autre compte');
    estNul(cle_defi_valide($s, 'creation', 42, 999, 300), 'daté du futur');
    estNul(cle_defi_valide(null, 'creation', 42, 1000, 300), 'rien en session');
    estNul(cle_defi_valide('abc', 'creation', 42, 1000, 300), 'pas une liste');
    estNul(cle_defi_valide(['usage' => 'creation', 'id' => 42, 'le' => 1000], 'creation', 42, 1000, 300), 'sans défi');
});

test('le défi se prend UNE fois, réussite ou non', function () {
    $_SESSION = [];
    $defi = cle_defi_creer('connexion', 0);
    vrai(strlen($defi) >= 43, 'au moins 32 octets, en base64url');
    egale($defi, cle_defi_prendre('connexion', 0), 'la première fois');
    estNul(cle_defi_prendre('connexion', 0), 'plus la seconde');
    $_SESSION = [];
    cle_defi_creer('creation', 7);
    estNul(cle_defi_prendre('connexion', 0), 'un mauvais usage ne le rend pas');
    estNul(cle_defi_prendre('creation', 7), 'et l\'a quand même consommé');
    differe(cle_defi_creer('connexion', 0), cle_defi_creer('connexion', 0), 'deux défis ne se ressemblent pas');
});

groupe('Les options envoyées au navigateur');

test('les options de création', function () {
    $o = cle_options_creation(DEFI, ['id' => 42, 'identifiant' => 'lecteur'], ['aaa', 'bbb'], RP);
    egale(DEFI, $o['challenge'], 'le défi');
    egale(RP, $o['rp']['id'], 'l\'adresse du site');
    egale('lecteur', $o['user']['name'], 'le nom affiché dans le gestionnaire');
    egale('none', $o['attestation'], 'pas d\'attestation');
    egale('required', $o['authenticatorSelection']['residentKey'], 'clé retrouvable sans identifiant');
    egale('preferred', $o['authenticatorSelection']['userVerification'], 'vérification laissée au gestionnaire');
    egale([-7, -257], array_column($o['pubKeyCredParams'], 'alg'), 'ES256 puis RS256');
    egale(['aaa', 'bbb'], array_column($o['excludeCredentials'], 'id'), 'les clés déjà enregistrées sont exclues');
    egale([], cle_options_creation(DEFI, ['id' => 1, 'identifiant' => 'a'], [], RP)['excludeCredentials'], 'aucune au début');
});

test('l\'identifiant d\'utilisateur est opaque, stable et propre au compte', function () {
    $o = cle_options_creation(DEFI, ['id' => 42, 'identifiant' => 'lecteur'], [], RP);
    egale($o['user']['id'], base64url(cle_identifiant_utilisateur(42)), 'stable');
    differe(cle_identifiant_utilisateur(42), cle_identifiant_utilisateur(43), 'propre au compte');
    egale(32, strlen(cle_identifiant_utilisateur(42)), '32 octets');
    sans('42', $o['user']['id'], 'ne contient pas le numéro du compte');
    sans('lecteur', base64_decode(strtr($o['user']['id'], '-_', '+/')), 'ne contient pas l\'identifiant');
});

test('les options de connexion', function () {
    $o = cle_options_connexion(DEFI, RP);
    egale(DEFI, $o['challenge'], 'le défi');
    egale(RP, $o['rpId'], 'l\'adresse du site');
    egale('preferred', $o['userVerification'], 'vérification laissée au gestionnaire');
    faux(isset($o['allowCredentials']), 'aucune liste : le gestionnaire propose celles du site');
});

groupe('cle_nom');

test('le nom saisi, nettoyé, sinon un nom par défaut daté', function () {
    egale('Bitwarden', cle_nom('  Bitwarden  '), 'le nom saisi');
    egale('Deux lignes', cle_nom("Deux\nlignes"), 'une seule ligne');
    egale(CLE_NOM_MAX, mb_strlen(cle_nom(str_repeat('é', 200))), 'borné');
    egale("Clé d'accès du 03/10/2026", cle_nom('', mktime(12, 0, 0, 10, 3, 2026)), 'le nom par défaut');
    egale("Clé d'accès du 03/10/2026", cle_nom("  \t ", mktime(12, 0, 0, 10, 3, 2026)), 'des blancs valent un champ vide');
    egale("Clé d'accès du 03/10/2026", cle_nom(null, mktime(12, 0, 0, 10, 3, 2026)), 'un champ absent aussi');
});

groupe('Réglages par défaut');

test('dix clés par compte, un défi valable cinq minutes', function () {
    egale(10, CLE_ACCES_MAX, 'CLE_ACCES_MAX');
    egale(300, CLE_ACCES_DEFI_DUREE, 'CLE_ACCES_DEFI_DUREE');
});

groupe('bouton_cle_acces()');

test('le bouton est caché jusqu\'à ce que le navigateur sache faire, et son message a sa place', function () {
    $html = bouton_cle_acces();
    contient('id="btn-cle-acces"', $html, 'le bouton');
    motif('/class="btn btn-ghost full hidden"/', $html, 'caché au départ');
    contient('id="btn-cle-acces-erreur"', $html, 'la place du message, nommée « <id>-erreur »');
    contient('role="alert"', $html, 'annoncé aux lecteurs d\'écran');
    sans('onclick', $html, 'aucun JavaScript en ligne (CSP)');
});
