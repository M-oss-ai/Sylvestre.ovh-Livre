<?php
/* =====================================================================
   Connexion par clé d'accès — l'appel JSON de js/cle-acces.js.

   Deux étapes, toutes deux en POST avec le jeton CSRF de la page de
   connexion :

     etape=options   un défi neuf (à usage unique, rangé dans la session) et
                     les options de la demande : aucune liste de clés, le
                     gestionnaire de mots de passe propose celles du site ;
     etape=verifier  la réponse du gestionnaire. Le site retrouve la clé par
                     son identifiant, vérifie la signature, le défi, l'origine
                     et l'adresse du site (voir includes/cle_acces.php), puis
                     ouvre la session — comme connexion.php après un mot de
                     passe juste, sans en passer par le mot de passe.

   Les échecs se comptent par adresse IP (action « connexion_cle », séparée
   de « connexion » : une clé périmée ne doit pas bloquer le mot de passe).
   Il n'y a pas de limiteur par compte : une signature ne se devine pas, et
   en compter les échecs sur un compte ne servirait qu'à le verrouiller
   depuis l'extérieur.

   Le compte n'est jamais désigné par le navigateur : c'est la clé retrouvée
   en base qui le dit. L'identifiant d'utilisateur que renvoie le
   gestionnaire n'est qu'un recoupement.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/fonctions.php';
require_once __DIR__ . '/includes/cle_acces.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reponse_json(['ok' => false, 'erreur' => 'Méthode non autorisée.'], 405);
}
if (post_trop_gros() || !csrf_valide($_POST['csrf'] ?? null)) {
    reponse_json(['ok' => false, 'erreur' => 'Jeton de sécurité invalide. Rechargez la page.'], 403);
}
if (utilisateur_actuel()) {
    reponse_json(['ok' => true, 'redirection' => 'index.php']);   // déjà connecté
}

$etape = (string) ($_POST['etape'] ?? '');

if ($etape === 'options') {
    $defi = cle_defi_creer('connexion', 0);
    reponse_json(['ok' => true, 'options' => cle_options_connexion($defi, cle_rp_id())]);
}

if ($etape !== 'verifier') {
    reponse_json(['ok' => false, 'erreur' => 'Demande inconnue.'], 422);
}

/** Un refus : compté, consigné, et dit sans détail à l'écran. */
function refus_cle(string $raison, string $message = "Cette clé d'accès n'a pas pu être vérifiée. Réessayez, ou connectez-vous avec votre mot de passe."): never
{
    limiteur_echec('connexion_cle', CONNEXION_MAX_ESSAIS, CONNEXION_BLOCAGE);
    journal_securite('connexion_cle_echouee', ['raison' => $raison]);
    $reste = limiteur_bloque_depuis('connexion_cle');
    if ($reste > 0) {
        reponse_json(['ok' => false, 'attente' => $reste, 'erreur' => 'Trop de tentatives. Réessayez dans ' . $reste . ' secondes.'], 429);
    }
    reponse_json(['ok' => false, 'erreur' => $message], 403);
}

$attente = limiteur_bloque_depuis('connexion_cle');
if ($attente > 0) {
    reponse_json(['ok' => false, 'attente' => $attente, 'erreur' => 'Trop de tentatives. Réessayez dans ' . $attente . ' secondes.'], 429);
}

/* Le défi se consomme d'abord : réussite ou non, il ne resservira pas. Sans
   défi valable (expiré, ou remplacé par un autre onglet), ce n'est pas une
   tentative suspecte : on le dit, sans compter d'échec. */
$defi = cle_defi_prendre('connexion', 0);
if ($defi === null) {
    reponse_json(['ok' => false, 'renouveler' => true,
        'erreur' => 'La demande a expiré. Choisissez de nouveau votre clé d\'accès.'], 409);
}

$identifiant = base64url_decoder((string) ($_POST['identifiant'] ?? ''));
$client      = base64url_decoder((string) ($_POST['client_data'] ?? ''));
$auth        = base64url_decoder((string) ($_POST['authenticator'] ?? ''));
$signature   = base64url_decoder((string) ($_POST['signature'] ?? ''));
$utilisateur = (string) ($_POST['utilisateur'] ?? '');
if ($identifiant === null || $client === null || $auth === null || $signature === null
    || strlen($identifiant) > CLE_ID_MAX || strlen($client) > 4096 || strlen($auth) > 4096 || strlen($signature) > 1024) {
    refus_cle('réponse mal formée');
}

$cle = cle_trouver($identifiant);
if ($cle === null) {
    refus_cle('clé inconnue', "Le site ne reconnaît plus cette clé d'accès. Retirez-la de votre gestionnaire de mots de passe, ou connectez-vous avec votre mot de passe.");
}
$compte_id = (int) $cle['utilisateur_id'];

$verdict = cle_verifier_connexion(
    $client, $auth, $signature, $defi, cle_origine(), cle_rp_id(), (string) $cle['cle_publique'], (int) $cle['compteur']
);
if (!$verdict['ok']) {
    refus_cle($verdict['raison']);
}

/* Le gestionnaire renvoie l'identifiant d'utilisateur posé à la création :
   s'il est là, il doit être celui de CE compte. */
if ($utilisateur !== '') {
    $rendu = base64url_decoder($utilisateur);
    if ($rendu === null || !hash_equals(cle_identifiant_utilisateur($compte_id), $rendu)) {
        refus_cle('identifiant d\'utilisateur différent');
    }
}

$req = $pdo->prepare('SELECT id, email_verifie FROM utilisateur WHERE id = ?');
$req->execute([$compte_id]);
$compte = $req->fetch();
if (!$compte) {
    refus_cle('compte disparu');
}
if ((int) $compte['email_verifie'] === 0) {
    reponse_json(['ok' => false, 'erreur' => "Votre adresse e-mail n'a pas encore été confirmée. "
        . 'Ouvrez le lien reçu par e-mail pour activer votre compte.'], 403);
}

cle_utilisee((int) $cle['id'], (int) $verdict['compteur']);
unset($_SESSION['verification_en_attente']);
connecter($compte_id);
journal_securite('connexion_cle', ['utilisateur' => $compte_id]);

reponse_json(['ok' => true, 'redirection' => 'index.php', 'csrf' => jeton_csrf()]);
