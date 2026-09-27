<?php
/* =====================================================================
   « Continuer avec Google » — le départ ET le retour.

   Sans paramètre : on part chez Google (voir includes/google.php pour
   le parcours complet et ses garde-fous). Avec « code » et « state » :
   Google nous renvoie la personne, et l'on décide quoi faire de son
   compte (google_decision) :

     - compte Google déjà relié          → on ouvre ce compte ;
     - adresse d'un compte existant       → on relie, puis on l'ouvre
       (et un e-mail prévient le titulaire) ;
     - adresse inconnue                   → nouveau compte, sans mot de
       passe : il se connectera avec Google ;
     - déjà connecté (depuis Paramètres)  → on associe ce compte Google,
       ou l'on note que la personne vient de confirmer son identité.

   Toute erreur ramène à la page d'où l'on venait, avec un message ; le
   détail technique va au journal, jamais à l'écran.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/fonctions.php';
require_once __DIR__ . '/includes/google.php';

if (!google_actif()) {
    http_response_code(404);
    exit('Not found');
}
header('Cache-Control: no-store, private');

$moi = utilisateur_actuel();

/* Où revenir une fois connecté. Une liste fermée : une adresse de
   retour libre ferait de cette page un tremplin vers n'importe quel site. */
const GOOGLE_RETOURS = ['index' => 'index.php', 'parametres' => 'parametres.php#securite'];

/** Échec : un message, et retour à la page d'où l'on est parti. */
function google_echec(string $message, ?array $moi): never
{
    flash($message, 'erreur');
    header('Location: ' . ($moi ? 'parametres.php#securite' : 'connexion.php'));
    exit;
}

/* ---------------------------------------------------------------------
   1. Le départ : trois secrets à usage unique, puis Google
   --------------------------------------------------------------------- */
if (!isset($_GET['code']) && !isset($_GET['error']) && !isset($_GET['state'])) {
    $retour = (string) ($_GET['retour'] ?? '');
    $verificateur = base64url(random_bytes(48));
    $_SESSION['google'] = [
        'etat'         => base64url(random_bytes(24)),
        'nonce'        => base64url(random_bytes(24)),
        'verificateur' => $verificateur,
        'retour'       => isset(GOOGLE_RETOURS[$retour]) ? $retour : ($moi ? 'parametres' : 'index'),
        'le'           => time(),
    ];
    header('Location: ' . google_url_autorisation(
        $_SESSION['google']['etat'], $_SESSION['google']['nonce'], pkce_defi($verificateur),
        GOOGLE_CLIENT_ID, google_redirection()
    ));
    exit;
}

/* ---------------------------------------------------------------------
   2. Le retour : est-ce bien NOTRE demande, et Google confirme-t-il ?
   --------------------------------------------------------------------- */
$attendu = $_SESSION['google'] ?? null;
unset($_SESSION['google']);   // à usage unique, réussite ou non

if (isset($_GET['error'])) {
    google_echec($_GET['error'] === 'access_denied'
        ? 'Connexion avec Google annulée.'
        : "Google n'a pas pu vous connecter. Réessayez.", $moi);
}

/* L'état doit être celui que CETTE session a envoyé. Sans ce contrôle,
   quelqu'un pourrait envoyer à sa victime un lien de retour portant son
   propre code : la victime se retrouverait dans le compte de l'attaquant,
   et y rangerait ses données. */
$etat = $_GET['state'] ?? null;
if (!is_array($attendu) || !is_string($etat) || !hash_equals((string) $attendu['etat'], $etat)
    || (int) $attendu['le'] < time() - GOOGLE_PARCOURS_MAX || !is_string($_GET['code'] ?? null)) {
    journal_securite('google_etat_invalide');
    google_echec('La connexion avec Google a expiré ou a été interrompue. Réessayez.', $moi);
}

$jetons = google_echanger_code($_GET['code'], (string) $attendu['verificateur']);
$c = is_array($jetons) && is_string($jetons['id_token'] ?? null) ? google_lire_id_token($jetons['id_token']) : null;
$raison = $c === null ? 'réponse illisible' : google_verifier_revendications($c, GOOGLE_CLIENT_ID, (string) $attendu['nonce'], time());
if ($raison !== '') {
    journal_securite('google_refuse', ['raison' => $raison]);
    google_echec("Google n'a pas pu confirmer votre identité. Réessayez.", $moi);
}

$sub          = (string) $c['sub'];
$email_google = texte($c['email'], 190);
$destination  = GOOGLE_RETOURS[$attendu['retour']] ?? 'index.php';

/* ---------------------------------------------------------------------
   3. Qui est-ce, pour le site ?
   --------------------------------------------------------------------- */
$colonnes = 'SELECT id, identifiant, email, email_verifie, google_sub FROM utilisateur WHERE ';
$req = $pdo->prepare($colonnes . 'google_sub = ?');
$req->execute([$sub]);
$par_sub = $req->fetch() ?: null;
$req = $pdo->prepare($colonnes . 'email = ?');   // collation insensible à la casse
$req->execute([$email_google]);
$par_email = $req->fetch() ?: null;

$decision = google_decision($moi, $par_sub, $par_email);

/** Relie ce compte Google au compte $id, s'il ne l'est pas déjà ailleurs. */
function google_relier(int $id, string $sub, ?array $moi): void
{
    global $pdo;
    try {
        $req = $pdo->prepare('UPDATE utilisateur SET google_sub = ? WHERE id = ? AND google_sub IS NULL');
        $req->execute([$sub, $id]);
    } catch (PDOException $e) {
        // Index unique : relié à un autre compte entre-temps.
        google_echec('Ce compte Google est déjà associé à un autre compte du site.', $moi);
    }
    if ($req->rowCount() === 0) {
        google_echec('Votre compte est déjà associé à un autre compte Google.', $moi);
    }
}

switch ($decision) {
    case 'confirmer':
        google_noter_confirmation((int) $moi['id']);
        journal_securite('google_confirmation', ['utilisateur' => (int) $moi['id']]);
        flash('Identité confirmée avec Google ✅ — vous pouvez poursuivre.');
        header('Location: ' . $destination);
        exit;

    case 'associer':
        google_relier((int) $moi['id'], $sub, $moi);
        google_noter_confirmation((int) $moi['id']);
        journal_securite('google_associe', ['utilisateur' => (int) $moi['id']]);
        avertir_google_associe((string) $moi['email'], (string) $moi['identifiant'], $email_google);
        flash('Compte Google associé ✅ — vous pourrez vous connecter avec « Continuer avec Google ».');
        header('Location: ' . $destination);
        exit;

    case 'refus_autre':
        google_echec('Ce compte Google est déjà associé à un autre compte du site.', $moi);

    case 'refus_deja_lie':
        google_echec('Votre compte est déjà associé à un autre compte Google. Dissociez-le d\'abord.', $moi);

    case 'refus_conflit':
        journal_securite('google_conflit', ['utilisateur' => (int) $par_email['id']]);
        google_echec('Un compte du site utilise déjà cette adresse, associé à un autre compte Google.', $moi);

    case 'connecter':
        $id = (int) $par_sub['id'];
        break;

    case 'lier':
        /* Même adresse, déjà confirmée par e-mail sur le site, et Google
           vient de prouver que la personne la possède : c'est le même
           titulaire. Il est prévenu par e-mail, à l'adresse du compte. */
        $id = (int) $par_email['id'];
        google_relier($id, $sub, $moi);
        avertir_google_associe((string) $par_email['email'], (string) $par_email['identifiant'], $email_google);
        journal_securite('google_associe', ['utilisateur' => $id]);
        flash('Votre compte est maintenant relié à Google ✅ — votre mot de passe reste valable.');
        break;

    case 'reprendre':
        /* Même adresse, mais JAMAIS confirmée : n'importe qui a pu créer ce
           compte avec l'adresse d'un autre, et en connaître le mot de passe.
           Google vient de prouver à qui appartient l'adresse : le compte lui
           revient, et le mot de passe choisi par l'inconnu est effacé (toutes
           ses sessions avec). Un titulaire légitime qui avait simplement
           oublié de confirmer en définira un nouveau dans les Paramètres. */
        $id = (int) $par_email['id'];
        $pdo->prepare("UPDATE utilisateur SET google_sub = ?, email_verifie = 1, mot_de_passe = '' WHERE id = ? AND google_sub IS NULL")
            ->execute([$sub, $id]);
        invalider_sessions($id);
        $pdo->prepare("DELETE FROM jeton_action WHERE utilisateur_id = ? AND type IN ('verification', 'reinit')")
            ->execute([$id]);
        journal_securite('google_reprise', ['utilisateur' => $id]);
        flash('Adresse confirmée par Google ✅ Ce compte n\'avait jamais été activé : par sécurité, '
            . 'son mot de passe a été retiré. Vous vous connecterez avec Google, ou en définissant '
            . 'un mot de passe dans Paramètres › Sécurité.');
        break;

    case 'creer':
        $id = google_creer_compte($c, $sub, $email_google);
        break;

    default:
        google_echec("Google n'a pas pu vous connecter. Réessayez.", $moi);
}

/**
 * Un nouveau compte, sans mot de passe, adresse confirmée par Google.
 * Mêmes garde-fous que l'inscription : limiteur par adresse IP, et
 * plafond de comptes appliqué dans l'INSERT lui-même.
 */
function google_creer_compte(array $c, string $sub, string $email): int
{
    global $pdo;

    $bloque = limiteur_bloque_depuis('inscription');
    if ($bloque > 0) {
        google_echec('Trop de comptes créés depuis cette adresse. Réessayez dans ' . $bloque . ' secondes.', null);
    }
    limiteur_echec('inscription', INSCRIPTION_MAX, INSCRIPTION_BLOCAGE);

    // Un identifiant libre : le début de l'adresse, puis un suffixe si pris.
    $base = identifiant_depuis_google($email);
    $identifiant = $base;
    $existe = $pdo->prepare('SELECT 1 FROM utilisateur WHERE identifiant = ?');
    for ($n = 2; ; $n++) {
        $existe->execute([$identifiant]);
        if (!$existe->fetchColumn()) {
            break;
        }
        $identifiant = $base . ($n < 100 ? $n : random_int(100, 999999));
    }

    try {
        $req = $pdo->prepare(
            "INSERT INTO utilisateur (identifiant, email, mot_de_passe, prenom, nom, email_verifie, google_sub)
             SELECT ?, ?, '', ?, ?, 1, ?
               FROM DUAL
              WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM utilisateur) AS c) < ?"
        );
        $req->execute([
            $identifiant, $email,
            texte($c['given_name'] ?? '', 80), texte($c['family_name'] ?? '', 80),
            $sub, MAX_UTILISATEURS,
        ]);
    } catch (PDOException $e) {
        // Doublon apparu entre la recherche et l'écriture (deux onglets).
        error_log('google: création refusée (' . $e->getMessage() . ')');
        google_echec('La création du compte a échoué. Réessayez.', null);
    }
    if ($req->rowCount() === 0) {
        google_echec('Le nombre maximum de comptes a été atteint. Contactez l\'administrateur : ' . ADMIN_EMAIL, null);
    }

    $id = (int) $pdo->lastInsertId();
    journal_securite('google_compte_cree', ['utilisateur' => $id]);
    flash('Bienvenue ! Votre compte a été créé avec Google ✅ Votre identifiant est « '
        . $identifiant . ' » — modifiable dans Paramètres.');
    return $id;
}

/* ---------------------------------------------------------------------
   4. Ouverture de la session
   --------------------------------------------------------------------- */
connecter($id);
google_noter_confirmation($id);
journal_securite('connexion_google', ['utilisateur' => $id]);
header('Location: ' . $destination);
exit;
