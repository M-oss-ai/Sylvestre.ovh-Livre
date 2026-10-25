<?php
/* =====================================================================
   API JSON v1 — point d'entrée unique (voir includes/api_v1.php).

   Adresses : /api/v1/…  (api/.htaccess les ramène ici).
   Documentation : API.md et openapi.yaml à la racine du dépôt (ces
   fichiers ne sont pas servis, voir le .htaccess racine).
   ===================================================================== */

declare(strict_types=1);

/* Pas de session ni de cookie : l'API s'authentifie par jeton. */
define('SANS_SESSION', true);

/* Si la base est injoignable, erreur_fatale() (config.php) répond en JSON
   plutôt qu'en HTML dès que la requête dit en attendre. L'API répond
   toujours en JSON : on le dit d'avance. */
$_SERVER['HTTP_ACCEPT'] = 'application/json';

require_once __DIR__ . '/../includes/fonctions.php';
require_once __DIR__ . '/../includes/couvertures.php';
require_once __DIR__ . '/../includes/api_v1.php';

api_servir();
