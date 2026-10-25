<?php
/* =====================================================================
   Routeur du serveur intégré de PHP, pour les tests de l'API.

   Fait ce que api/.htaccess fait chez Apache : confier /api/… à
   api/index.php. Rien d'autre n'est servi.
   ===================================================================== */

declare(strict_types=1);

$chemin = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if (str_starts_with($chemin, '/api/')) {
    // Le serveur intégré met l'URL entière dans SCRIPT_NAME ; Apache y met le script.
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require dirname(__DIR__) . '/site/api/index.php';
    return true;
}

http_response_code(404);
echo 'hors API';
return true;
