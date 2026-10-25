<?php
/* =====================================================================
   Clés d'accès (« passkeys », WebAuthn) — se connecter sans mot de passe.

   Le parcours, en deux temps :

     AJOUTER (Paramètres › Sécurité, après avoir prouvé son identité) :
       1. Le serveur tire un DÉFI à usage unique, le range dans la session
          et envoie au navigateur les options de création.
       2. Le navigateur — ou le gestionnaire de mots de passe qui le
          remplace — fabrique une paire de clés et garde la privée.
          Il renvoie la clé PUBLIQUE, signée dans un objet que le serveur
          lit (cbor_lire, cle_lire_donnees_auth) et enregistre.

     SE CONNECTER (connexion.php, sans rien taper) :
       1. Même défi, à usage unique.
       2. Le gestionnaire propose la clé du site, la personne choisit.
          Il signe « données d'authentification + empreinte du message du
          navigateur » ; le serveur vérifie la signature avec la clé
          publique enregistrée, le défi, l'origine et l'adresse du site.

   Ce que le site ne vérifie PAS, et c'est voulu : l'attestation (le
   certificat du fabricant). On demande « attestation: none » ; on veut
   enregistrer une clé, pas savoir QUEL appareil la garde — et un
   gestionnaire de mots de passe n'en a pas à présenter.

   Toutes les fonctions ci-dessous sont pures, sauf celles de la dernière
   section (la base) et cle_defi_creer / cle_defi_prendre (la session) :
   c'est ce qui permet de les tester, avec un authentificateur simulé,
   sans navigateur ni base (tests/cas/cle_acces_test.php).

   Aucune bibliothèque : la lecture du CBOR et la mise en forme de la clé
   tiennent dans ce fichier, la signature se vérifie avec openssl_verify().
   Deux algorithmes, ceux que tout le monde sait faire : ES256 (courbe
   P-256, celui des gestionnaires de mots de passe) et RS256 (Windows
   Hello). EdDSA est laissé de côté : openssl_verify() ne le sait pas.
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/google.php';      // base64url(), base64url_decoder()

/** Ce que le site sait vérifier : COSE -7 (ES256) et -257 (RS256). */
const CLE_ALGOS = [-7 => 'ES256', -257 => 'RS256'];

/** Longueur maximale de l'identifiant d'une clé, en octets (la norme en admet 1023). */
const CLE_ID_MAX = 512;

/** Une clé RSA plus courte que 2048 bits ne protège plus de rien. */
const CLE_RSA_OCTETS_MIN = 256;
const CLE_RSA_OCTETS_MAX = 1024;

/** Garde-fous de la lecture du CBOR : une donnée venue du navigateur reste une donnée hostile. */
const CLE_CBOR_PROFONDEUR = 8;
const CLE_CBOR_TAILLE_MAX = 65536;

/** Longueur du nom qu'on donne à une clé. */
const CLE_NOM_MAX = 60;

/** Levée par la lecture d'une donnée mal formée ; les fonctions publiques la convertissent en « raison ». */
final class CleInvalide extends RuntimeException
{
}

/* ---------------------------------------------------------------------
   1. CBOR — le sous-ensemble que WebAuthn emploie
   --------------------------------------------------------------------- */

/**
 * Lit UN élément CBOR à partir de $pos, et avance $pos après lui.
 *
 * Entiers, chaînes d'octets et de texte, listes, tables, vrai/faux/nul.
 * Refusés : longueurs indéfinies, étiquettes, flottants, clés de table qui
 * ne sont ni un entier ni un texte, clés en double — tout ce que le format
 * « canonique » de WebAuthn n'emploie pas. Les longueurs annoncées sont
 * comparées à ce qui reste : un en-tête qui promet un milliard d'éléments
 * n'alloue rien.
 *
 * @throws CleInvalide
 */
function cbor_decoder(string $octets, int &$pos, int $profondeur = 0): mixed
{
    if ($profondeur > CLE_CBOR_PROFONDEUR) {
        throw new CleInvalide('imbrication trop profonde');
    }
    $total = strlen($octets);
    if ($pos >= $total) {
        throw new CleInvalide('données tronquées');
    }
    $initial = ord($octets[$pos++]);
    $majeur  = $initial >> 5;
    $info    = $initial & 31;

    if ($majeur === 7) {
        return match ($info) {
            20      => false,
            21      => true,
            22      => null,
            default => throw new CleInvalide('valeur simple non gérée'),
        };
    }

    if ($info < 24) {
        $valeur = $info;
    } elseif ($info <= 27) {
        $n = 1 << ($info - 24);               // 1, 2, 4 ou 8 octets
        if ($pos + $n > $total) {
            throw new CleInvalide('données tronquées');
        }
        $brut = substr($octets, $pos, $n);
        $pos += $n;
        $valeur = match ($n) {
            1       => ord($brut),
            2       => unpack('n', $brut)[1],
            4       => unpack('N', $brut)[1],
            default => unpack('J', $brut)[1],
        };
        if ($valeur < 0) {
            throw new CleInvalide('entier trop grand');
        }
    } else {
        throw new CleInvalide('longueur indéfinie ou réservée');
    }

    switch ($majeur) {
        case 0:
            return $valeur;
        case 1:
            return -1 - $valeur;
        case 2:
        case 3:
            if ($valeur > $total - $pos) {
                throw new CleInvalide('données tronquées');
            }
            $chaine = substr($octets, $pos, $valeur);
            $pos += $valeur;
            return $chaine;
        case 4:
            if ($valeur > $total - $pos) {          // chaque élément pèse au moins un octet
                throw new CleInvalide('données tronquées');
            }
            $liste = [];
            for ($i = 0; $i < $valeur; $i++) {
                $liste[] = cbor_decoder($octets, $pos, $profondeur + 1);
            }
            return $liste;
        case 5:
            if ($valeur > intdiv($total - $pos, 2)) {   // une clé et une valeur : deux octets au moins
                throw new CleInvalide('données tronquées');
            }
            $table = [];
            for ($i = 0; $i < $valeur; $i++) {
                $cle = cbor_decoder($octets, $pos, $profondeur + 1);
                if (!is_int($cle) && !is_string($cle)) {
                    throw new CleInvalide('clé de table inattendue');
                }
                if (array_key_exists($cle, $table)) {
                    throw new CleInvalide('clé de table en double');
                }
                $table[$cle] = cbor_decoder($octets, $pos, $profondeur + 1);
            }
            return $table;
        default:
            throw new CleInvalide('étiquette CBOR non gérée');
    }
}

/**
 * Lit un document CBOR entier : un élément, et rien après lui.
 *
 * @throws CleInvalide
 */
function cbor_lire(string $octets): mixed
{
    if ($octets === '' || strlen($octets) > CLE_CBOR_TAILLE_MAX) {
        throw new CleInvalide('taille de données refusée');
    }
    $pos    = 0;
    $valeur = cbor_decoder($octets, $pos);
    if ($pos !== strlen($octets)) {
        throw new CleInvalide('octets en trop');
    }
    return $valeur;
}

/* ---------------------------------------------------------------------
   2. Les données d'authentification et la clé publique
   --------------------------------------------------------------------- */

/**
 * Lit les « authenticator data » : l'empreinte de l'adresse du site,
 * des drapeaux, un compteur, et — à la création seulement — l'identifiant
 * de la clé et la clé publique.
 *
 * @return array{rp_hash: string, drapeaux: int, presence: bool, verifiee: bool, compteur: int, identifiant: ?string, cle: ?array}
 * @throws CleInvalide
 */
function cle_lire_donnees_auth(string $d): array
{
    if (strlen($d) < 37) {
        throw new CleInvalide("données d'authentification trop courtes");
    }
    $drapeaux = ord($d[32]);
    $r = [
        'rp_hash'     => substr($d, 0, 32),
        'drapeaux'    => $drapeaux,
        'presence'    => ($drapeaux & 0x01) !== 0,   // UP : la personne était là
        'verifiee'    => ($drapeaux & 0x04) !== 0,   // UV : elle s'est identifiée (code, empreinte…)
        'compteur'    => unpack('N', substr($d, 33, 4))[1],
        'identifiant' => null,
        'cle'         => null,
    ];

    $pos = 37;
    if (($drapeaux & 0x40) !== 0) {                  // AT : une clé est jointe
        if (strlen($d) < $pos + 18) {
            throw new CleInvalide('données de clé tronquées');
        }
        $pos += 16;                                  // l'AAGUID (le modèle d'appareil) : on ne s'en sert pas
        $long = unpack('n', substr($d, $pos, 2))[1];
        $pos += 2;
        if ($long < 1 || $long > CLE_ID_MAX) {
            throw new CleInvalide("longueur d'identifiant de clé refusée");
        }
        if (strlen($d) < $pos + $long) {
            throw new CleInvalide('données de clé tronquées');
        }
        $r['identifiant'] = substr($d, $pos, $long);
        $pos += $long;
        $cle = cbor_decoder($d, $pos);
        if (!is_array($cle)) {
            throw new CleInvalide('clé publique illisible');
        }
        $r['cle'] = $cle;
    }
    if (($drapeaux & 0x80) !== 0) {                  // ED : des extensions, lues pour savoir où la donnée finit
        cbor_decoder($d, $pos);
    }
    if ($pos !== strlen($d)) {
        throw new CleInvalide('octets en trop');
    }
    return $r;
}

/** Un élément DER : l'étiquette, la longueur, le contenu. */
function cle_der(int $etiquette, string $contenu): string
{
    $n = strlen($contenu);
    if ($n < 128) {
        $longueur = chr($n);
    } else {
        $octets   = ltrim(pack('N', $n), "\0");
        $longueur = chr(0x80 | strlen($octets)) . $octets;
    }
    return chr($etiquette) . $longueur . $contenu;
}

/** Un entier DER non signé : sans zéros de tête, avec un zéro devant si le bit de poids fort est pris. */
function cle_der_entier(string $non_signe): string
{
    $non_signe = ltrim($non_signe, "\0");
    if ($non_signe === '') {
        $non_signe = "\0";
    }
    if ((ord($non_signe[0]) & 0x80) !== 0) {
        $non_signe = "\0" . $non_signe;
    }
    return cle_der(0x02, $non_signe);
}

/**
 * Une clé publique COSE → le PEM que openssl_verify() lit.
 *
 * ES256 : courbe P-256, deux coordonnées de 32 octets. RS256 : module d'au
 * moins 2048 bits. OpenSSL refuse un point hors de la courbe au chargement :
 * c'est le dernier contrôle.
 *
 * @throws CleInvalide
 */
function cle_cose_vers_pem(array $cose): string
{
    $type  = $cose[1] ?? null;
    $algo  = $cose[3] ?? null;

    if ($type === 2 && $algo === -7) {
        $x = $cose[-2] ?? null;
        $y = $cose[-3] ?? null;
        if (($cose[-1] ?? null) !== 1) {
            throw new CleInvalide('courbe non gérée (P-256 seulement)');
        }
        if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            throw new CleInvalide('coordonnées de clé invalides');
        }
        // SubjectPublicKeyInfo d'une clé EC P-256, puis le point non compressé.
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
    } elseif ($type === 3 && $algo === -257) {
        $n = $cose[-1] ?? null;
        $e = $cose[-2] ?? null;
        if (!is_string($n) || !is_string($e)) {
            throw new CleInvalide('clé RSA invalide');
        }
        $n = ltrim($n, "\0");
        $e = ltrim($e, "\0");
        if (strlen($n) < CLE_RSA_OCTETS_MIN) {
            throw new CleInvalide('clé RSA trop courte');
        }
        if (strlen($n) > CLE_RSA_OCTETS_MAX || $e === '' || strlen($e) > 8) {
            throw new CleInvalide('clé RSA hors limites');
        }
        $cle = cle_der(0x30, cle_der_entier($n) . cle_der_entier($e));
        $der = cle_der(0x30, hex2bin('300d06092a864886f70d0101010500') . cle_der(0x03, "\0" . $cle));
    } else {
        throw new CleInvalide('algorithme de clé non géré');
    }

    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    if (openssl_pkey_get_public($pem) === false) {
        throw new CleInvalide('clé publique refusée par OpenSSL');
    }
    return $pem;
}

/* ---------------------------------------------------------------------
   3. Vérifier ce que le navigateur renvoie
   --------------------------------------------------------------------- */

/**
 * Le « client data » : ce que le navigateur dit avoir fait, et pour qui.
 * Rend '' s'il convient, sinon la raison du refus (pour le journal, jamais
 * pour l'écran).
 *
 * Le défi et l'origine sont ce qui lie la réponse à CETTE demande, sur CE
 * site : sans eux, une signature obtenue ailleurs ou plus tôt servirait.
 */
function cle_verifier_client_data(string $json, string $type, string $defi, string $origine): string
{
    $d = json_decode($json, true);
    if (!is_array($d)) {
        return 'données du navigateur illisibles';
    }
    if (($d['type'] ?? null) !== $type) {
        return 'type de demande inattendu';
    }
    if (!is_string($d['challenge'] ?? null) || $defi === '' || !hash_equals($defi, $d['challenge'])) {
        return 'défi différent';
    }
    if (($d['origin'] ?? null) !== $origine) {
        return 'origine inattendue';
    }
    if (!empty($d['crossOrigin'])) {
        return 'page incorporée dans un autre site';
    }
    return '';
}

/**
 * Vérifie une CRÉATION de clé.
 *
 * @return array{ok: true, identifiant: string, cle_pem: string, compteur: int}|array{ok: false, raison: string}
 */
function cle_verifier_creation(string $client_data, string $attestation, string $defi, string $origine, string $rp_id): array
{
    try {
        $raison = cle_verifier_client_data($client_data, 'webauthn.create', $defi, $origine);
        if ($raison !== '') {
            return ['ok' => false, 'raison' => $raison];
        }
        $objet = cbor_lire($attestation);
        if (!is_array($objet) || !is_string($objet['authData'] ?? null)) {
            return ['ok' => false, 'raison' => "objet d'attestation incomplet"];
        }
        $auth = cle_lire_donnees_auth($objet['authData']);
        if (!hash_equals(hash('sha256', $rp_id, true), $auth['rp_hash'])) {
            return ['ok' => false, 'raison' => 'adresse du site différente'];
        }
        if (!$auth['presence']) {
            return ['ok' => false, 'raison' => 'présence de la personne non constatée'];
        }
        if ($auth['cle'] === null || $auth['identifiant'] === null) {
            return ['ok' => false, 'raison' => 'aucune clé jointe'];
        }
        return [
            'ok'          => true,
            'identifiant' => $auth['identifiant'],
            'cle_pem'     => cle_cose_vers_pem($auth['cle']),
            'compteur'    => $auth['compteur'],
        ];
    } catch (CleInvalide $e) {
        return ['ok' => false, 'raison' => $e->getMessage()];
    }
}

/**
 * Le compteur de signatures a-t-il avancé ? Une clé qui en tient un le fait
 * grandir à chaque usage : s'il recule, la clé a peut-être été copiée.
 * Les clés synchronisées (gestionnaires de mots de passe) renvoient toujours
 * 0 : 0 puis 0 est donc accepté.
 */
function cle_compteur_valide(int $connu, int $recu): bool
{
    return ($connu === 0 && $recu === 0) || $recu > $connu;
}

/**
 * Vérifie une CONNEXION : la signature, le défi, l'origine, l'adresse du site.
 *
 * @return array{ok: true, compteur: int}|array{ok: false, raison: string}
 */
function cle_verifier_connexion(
    string $client_data, string $donnees_auth, string $signature,
    string $defi, string $origine, string $rp_id, string $cle_pem, int $compteur_connu
): array {
    try {
        $raison = cle_verifier_client_data($client_data, 'webauthn.get', $defi, $origine);
        if ($raison !== '') {
            return ['ok' => false, 'raison' => $raison];
        }
        $auth = cle_lire_donnees_auth($donnees_auth);
        if (!hash_equals(hash('sha256', $rp_id, true), $auth['rp_hash'])) {
            return ['ok' => false, 'raison' => 'adresse du site différente'];
        }
        if (!$auth['presence']) {
            return ['ok' => false, 'raison' => 'présence de la personne non constatée'];
        }
        $signe = $donnees_auth . hash('sha256', $client_data, true);
        if ($signature === '' || openssl_verify($signe, $signature, $cle_pem, OPENSSL_ALGO_SHA256) !== 1) {
            return ['ok' => false, 'raison' => 'signature invalide'];
        }
        if (!cle_compteur_valide($compteur_connu, $auth['compteur'])) {
            return ['ok' => false, 'raison' => 'compteur en recul : clé peut-être copiée'];
        }
        return ['ok' => true, 'compteur' => $auth['compteur']];
    } catch (CleInvalide $e) {
        return ['ok' => false, 'raison' => $e->getMessage()];
    }
}

/* ---------------------------------------------------------------------
   4. L'adresse du site, le défi, les options
   --------------------------------------------------------------------- */

/**
 * L'identifiant du site pour WebAuthn : son nom d'hôte. Lu dans APP_URL,
 * jamais dans l'en-tête Host que choisit le client (voir config.php).
 */
function cle_rp_id(string $app_url = APP_URL): string
{
    return strtolower((string) (parse_url($app_url, PHP_URL_HOST) ?? ''));
}

/** L'origine que le navigateur écrit dans le client data : schéma, hôte, port s'il n'est pas celui par défaut. */
function cle_origine(string $app_url = APP_URL): string
{
    $p       = parse_url($app_url) ?: [];
    $schema  = strtolower((string) ($p['scheme'] ?? 'https'));
    $port    = (int) ($p['port'] ?? 0);
    $defaut  = $schema === 'https' ? 443 : 80;
    return $schema . '://' . cle_rp_id($app_url) . ($port > 0 && $port !== $defaut ? ':' . $port : '');
}

/**
 * Ce que le navigateur retient du compte : un identifiant opaque, stable,
 * qui n'est ni le numéro du compte ni rien de personnel (la norme demande
 * qu'il n'y ait aucune donnée personnelle).
 */
function cle_identifiant_utilisateur(int $utilisateur_id): string
{
    return hash('sha256', 'livre-cle-acces|' . $utilisateur_id, true);
}

/** Un défi neuf, rangé dans la session pour UN usage (« creation » ou « connexion ») et UN compte (0 : personne). */
function cle_defi_creer(string $usage, int $utilisateur_id): string
{
    $defi = base64url(random_bytes(32));
    $_SESSION['cle_defi'] = ['defi' => $defi, 'usage' => $usage, 'id' => $utilisateur_id, 'le' => time()];
    return $defi;
}

/**
 * Le défi rangé, s'il sert à cet usage, pour ce compte, et n'a pas expiré ;
 * null sinon. Pure : l'appelant lui passe la session et l'heure.
 */
function cle_defi_valide(mixed $stocke, string $usage, int $utilisateur_id, int $maintenant, int $duree): ?string
{
    if (!is_array($stocke) || ($stocke['usage'] ?? null) !== $usage
        || (int) ($stocke['id'] ?? -1) !== $utilisateur_id
        || !is_string($stocke['defi'] ?? null) || $stocke['defi'] === '') {
        return null;
    }
    $le = (int) ($stocke['le'] ?? 0);
    return ($le <= $maintenant && $le >= $maintenant - $duree) ? $stocke['defi'] : null;
}

/** Reprend le défi ET l'efface : il ne sert qu'une fois, réussite ou non. */
function cle_defi_prendre(string $usage, int $utilisateur_id): ?string
{
    $stocke = $_SESSION['cle_defi'] ?? null;
    unset($_SESSION['cle_defi']);
    return cle_defi_valide($stocke, $usage, $utilisateur_id, time(), CLE_ACCES_DEFI_DUREE);
}

/**
 * Les options de CRÉATION envoyées au navigateur.
 *
 * « residentKey: required » : la clé doit pouvoir être retrouvée SANS saisir
 * d'identifiant — c'est ce qui permet au gestionnaire de proposer la clé du
 * site dès la page de connexion. « excludeCredentials » : un appareil qui a
 * déjà une clé de ce compte refuse d'en fabriquer une seconde.
 * « userVerification: preferred » : on laisse le gestionnaire décider s'il
 * redemande le code ; l'exiger ferait ressaisir à chaque connexion.
 *
 * @param string[] $deja identifiants (base64url) des clés déjà enregistrées
 */
function cle_options_creation(string $defi, array $compte, array $deja, string $rp_id): array
{
    $exclure = [];
    foreach ($deja as $identifiant) {
        $exclure[] = ['type' => 'public-key', 'id' => $identifiant];
    }
    $nom = (string) ($compte['identifiant'] ?? '');
    return [
        'challenge'              => $defi,
        'rp'                     => ['name' => 'Ma Bibliothèque Manga', 'id' => $rp_id],
        'user'                   => [
            'id'          => base64url(cle_identifiant_utilisateur((int) ($compte['id'] ?? 0))),
            'name'        => $nom,
            'displayName' => $nom,
        ],
        'pubKeyCredParams'       => [
            ['type' => 'public-key', 'alg' => -7],
            ['type' => 'public-key', 'alg' => -257],
        ],
        'timeout'                => 120000,
        'attestation'            => 'none',
        'excludeCredentials'     => $exclure,
        'authenticatorSelection' => [
            'residentKey'        => 'required',
            'requireResidentKey' => true,
            'userVerification'   => 'preferred',
        ],
    ];
}

/** Les options de CONNEXION : aucune liste de clés, le gestionnaire propose celles du site. */
function cle_options_connexion(string $defi, string $rp_id): array
{
    return ['challenge' => $defi, 'rpId' => $rp_id, 'timeout' => 120000, 'userVerification' => 'preferred'];
}

/**
 * Le nom d'une clé : ce que la personne a tapé, sinon « Clé d'accès du
 * 03/10/2026 ». Passe par texte() (fonctions.php), chargé par toute page.
 */
function cle_nom(mixed $saisi, ?int $maintenant = null): string
{
    $nom = texte($saisi, CLE_NOM_MAX);
    return $nom !== '' ? $nom : "Clé d'accès du " . date('d/m/Y', $maintenant ?? time());
}

/**
 * Le bouton « Se connecter avec une clé d'accès » de la page de connexion,
 * et la place de son message d'erreur (même convention que les champs :
 * « <id>-erreur »). Caché : js/cle-acces.js ne le montre que si le
 * navigateur sait faire — sans lui, ou sur un navigateur ancien, la page
 * reste celle d'avant.
 */
function bouton_cle_acces(): string
{
    return '<button type="button" id="btn-cle-acces" class="btn btn-ghost full hidden">'
        . "🔑 Se connecter avec une clé d'accès</button>"
        . '<p class="erreur-champ hidden" id="btn-cle-acces-erreur" role="alert"></p>';
}

/* ---------------------------------------------------------------------
   5. La base (table cle_acces) — hors de portée des tests
   --------------------------------------------------------------------- */

/** Les clés d'un compte, de la plus ancienne à la plus récente. */
function cle_lister(int $utilisateur_id): array
{
    global $pdo;
    $req = $pdo->prepare(
        'SELECT id, nom, cle_id, cree_le, utilisee_le FROM cle_acces WHERE utilisateur_id = ? ORDER BY id'
    );
    $req->execute([$utilisateur_id]);
    return $req->fetchAll();
}

/**
 * Enregistre une clé. Le plafond s'applique dans l'INSERT lui-même (un
 * contrôle préalable ne résiste pas à deux requêtes simultanées).
 *
 * @return string « ok », « limite » (CLE_ACCES_MAX atteint) ou « deja » (cette clé existe déjà)
 */
function cle_ajouter(int $utilisateur_id, string $identifiant, string $pem, int $compteur, string $nom): string
{
    global $pdo;
    try {
        $req = $pdo->prepare(
            'INSERT INTO cle_acces (utilisateur_id, cle_hash, cle_id, cle_publique, compteur, nom)
             SELECT ?, ?, ?, ?, ?, ? FROM DUAL
              WHERE (SELECT COUNT(*) FROM cle_acces WHERE utilisateur_id = ?) < ?'
        );
        $req->execute([
            $utilisateur_id, hash('sha256', $identifiant), base64url($identifiant), $pem, $compteur, $nom,
            $utilisateur_id, CLE_ACCES_MAX,
        ]);
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {      // clé déjà enregistrée (index unique)
            return 'deja';
        }
        throw $e;
    }
    return $req->rowCount() === 1 ? 'ok' : 'limite';
}

/** La clé qui porte cet identifiant (octets bruts), ou null. */
function cle_trouver(string $identifiant): ?array
{
    global $pdo;
    $req = $pdo->prepare('SELECT id, utilisateur_id, cle_publique, compteur FROM cle_acces WHERE cle_hash = ?');
    $req->execute([hash('sha256', $identifiant)]);
    return $req->fetch() ?: null;
}

/** Note un usage : la date, et le compteur que la clé vient de renvoyer. */
function cle_utilisee(int $id, int $compteur): void
{
    global $pdo;
    $pdo->prepare('UPDATE cle_acces SET compteur = ?, utilisee_le = NOW() WHERE id = ?')->execute([$compteur, $id]);
}

/** Retire UNE clé de CE compte. Faux si elle n'existe pas ou n'est pas à lui. */
function cle_supprimer(int $id, int $utilisateur_id): bool
{
    global $pdo;
    $req = $pdo->prepare('DELETE FROM cle_acces WHERE id = ? AND utilisateur_id = ?');
    $req->execute([$id, $utilisateur_id]);
    return $req->rowCount() === 1;
}

/** Retire toutes les clés d'un compte ; rend combien. */
function cle_effacer_toutes(int $utilisateur_id): int
{
    global $pdo;
    $req = $pdo->prepare('DELETE FROM cle_acces WHERE utilisateur_id = ?');
    $req->execute([$utilisateur_id]);
    return $req->rowCount();
}
