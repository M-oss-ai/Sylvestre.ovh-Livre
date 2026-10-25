<?php
/* =====================================================================
   Déconnexion : vide la session et supprime le cookie.

   Exige un POST accompagné d'un jeton CSRF valide. La version
   précédente ne vérifiait le jeton QUE sur les requêtes POST et
   laissait passer les GET : n'importe quel site tiers pouvait
   déconnecter un visiteur — et révoquer définitivement son jeton
   d'appareil — avec un simple <img src="…/deconnexion.php">.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/fonctions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
exiger_csrf();

revoquer_session_persistante(); // ne révoque que l'appareil courant

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?? 'Lax',
    ]);
}

session_destroy();

header('Location: connexion.php?deconnecte=1');
exit;
