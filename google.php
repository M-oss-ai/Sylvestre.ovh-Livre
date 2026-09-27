<?php
/* =====================================================================
   « Continuer avec Google » — le départ ET le retour.

   Sans paramètre : on part chez Google (voir includes/google.php pour
   le parcours complet et ses garde-fous). Avec « code » et « state » :
   Google nous renvoie la personne, et l'on décide quoi faire de son
   compte (google_decision) :

     - compte créé avec ce compte Google  → on l'ouvre ; s'il a un mot de
       passe, google-mot-de-passe.php le demande d'abord ;
     - adresse d'un compte e-mail         → refus : les deux sortes de
       comptes ne se relient pas ;
     - adresse inconnue                    → google-inscription.php, où
       l'on choisit son identifiant ;
     - déjà connecté (depuis Paramètres)   → la personne se reconnecte
       pour UNE action à risque (« action », voir GOOGLE_ACTIONS) ; son
       mot de passe, si elle en a un, est demandé ensuite.

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
        // L'action à risque pour laquelle on se reconnecte (liste fermée).
        'action'       => $moi && isset(GOOGLE_ACTIONS[(string) ($_GET['action'] ?? '')]) ? (string) $_GET['action'] : '',
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
$colonnes = 'SELECT id, identifiant, email, email_verifie, google_sub, (mot_de_passe <> \'\') AS a_mdp FROM utilisateur WHERE ';
$req = $pdo->prepare($colonnes . 'google_sub = ?');
$req->execute([$sub]);
$par_sub = $req->fetch() ?: null;
$req = $pdo->prepare($colonnes . 'email = ?');   // collation insensible à la casse
$req->execute([$email_google]);
$par_email = $req->fetch() ?: null;

$decision = google_decision($moi, $par_sub, $par_email);

switch ($decision) {
    case 'confirmer':
        /* Une reconnexion vaut pour UNE action, choisie avant de partir.
           Le mot de passe du compte, s'il en a un, vient ensuite. */
        $action = (string) ($attendu['action'] ?? '');
        if (!isset(GOOGLE_ACTIONS[$action])) {
            google_echec("Choisissez d'abord, dans les Paramètres, l'action à confirmer.", $moi);
        }
        if ((int) $par_sub['a_mdp'] === 1) {
            session_regenerate_id(true);
            $_SESSION['google_mdp'] = ['id' => (int) $moi['id'], 'le' => time(),
                'destination' => google_page_action($action), 'action' => $action];
            header('Location: google-mot-de-passe.php');
            exit;
        }
        google_noter_confirmation((int) $moi['id'], $action);
        journal_securite('google_confirmation', ['utilisateur' => (int) $moi['id'], 'action' => $action]);
        flash('Identité confirmée ✅ — vous avez ' . intdiv(GOOGLE_CONFIRMATION_DUREE, 60)
            . ' minutes pour ' . GOOGLE_ACTIONS[$action] . '.');
        header('Location: ' . google_page_action($action));
        exit;

    case 'refus_autre':
        google_echec("Ce compte Google n'est pas celui de votre compte. Choisissez le bon compte Google.", $moi);

    case 'refus_adresse':
        /* Deux sortes de comptes, qui ne se relient pas : l'adresse sert
           déjà à un compte créé avec une adresse e-mail. */
        google_echec((string) ($par_email['google_sub'] ?? '') !== ''
            ? 'Cette adresse est déjà utilisée par un autre compte.'
            : 'Un compte existe déjà avec cette adresse e-mail : connectez-vous avec votre '
              . 'identifiant et votre mot de passe.', $moi);

    case 'mot_de_passe':
        /* Google a confirmé l'identité ; le mot de passe défini dans les
           Paramètres est la seconde clé. Rien n'est ouvert avant lui : le
           compte attend dans la session, le temps de le taper. */
        session_regenerate_id(true);
        $_SESSION['google_mdp'] = ['id' => (int) $par_sub['id'], 'le' => time(), 'destination' => $destination];
        header('Location: google-mot-de-passe.php');
        exit;

    case 'connecter':
        $id = (int) $par_sub['id'];
        connecter($id);   // sans confirmation : chaque action à risque demande de se reconnecter
        journal_securite('connexion_google', ['utilisateur' => $id]);
        header('Location: ' . $destination);
        exit;

    case 'creer':
        /* Rien n'est créé ici : la personne choisit d'abord son identifiant
           (et, si elle veut, un mot de passe). Ce que Google a confirmé
           attend dans la session le temps de remplir la page. */
        $_SESSION['google_inscription'] = ['sub' => $sub, 'email' => $email_google, 'le' => time()];
        header('Location: google-inscription.php');
        exit;
}

google_echec("Google n'a pas pu vous connecter. Réessayez.", $moi);
