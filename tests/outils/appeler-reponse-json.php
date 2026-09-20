<?php
/* =====================================================================
   Appelle reponse_json() et rend compte de ce qu elle a produit.

   Lancé en sous-processus par tests/cas/sorties_terminales_test.php :
   reponse_json() se termine par exit.

       php tests/outils/appeler-reponse-json.php 422
   ===================================================================== */

declare(strict_types=1);

require __DIR__ . '/../amorce.php';

$code = (int) ($argv[1] ?? 200);

register_shutdown_function(static function (): void {
    echo "\n##CODE##", http_response_code(), "\n";
});

reponse_json([
    'ok'     => false,
    'erreur' => "Le titre est obligatoire.",
    // Des caractères qui révèlent les options d encodage retenues :
    // accents non échappés, et slashs laissés tels quels.
    'detail' => 'accentué — uploads/a.webp',
], $code);
