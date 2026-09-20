<?php
/* =====================================================================
   Appelle exiger_csrf() et rend compte de ce qu elle a produit.

   Lancé en sous-processus par tests/cas/csrf_barriere_test.php :
   exiger_csrf() se termine par exit dès qu elle refuse.

       php tests/outils/appeler-exiger-csrf.php bon
       php tests/outils/appeler-exiger-csrf.php mauvais
       php tests/outils/appeler-exiger-csrf.php absent
       php tests/outils/appeler-exiger-csrf.php tableau
       php tests/outils/appeler-exiger-csrf.php trop-gros
   ===================================================================== */

declare(strict_types=1);

require __DIR__ . '/../amorce.php';

$cas = (string) ($argv[1] ?? 'absent');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_FILES = [];

switch ($cas) {
    case 'bon':
        $_POST = ['csrf' => jeton_csrf()];
        break;

    case 'mauvais':
        jeton_csrf();   // un jeton existe bien en session, mais ce n est pas celui-ci
        $_POST = ['csrf' => str_repeat('a', 64)];
        break;

    case 'tableau':
        // « csrf[]=x » dans une requête forgée fait arriver un TABLEAU.
        jeton_csrf();
        $_POST = ['csrf' => ['x']];
        break;

    case 'trop-gros':
        /* PHP a jeté le corps parce qu il dépassait post_max_size : $_POST
           et $_FILES sont vides, donc le jeton manque — alors que
           l utilisateur a simplement choisi un fichier trop lourd. */
        $_POST = [];
        $_SERVER['CONTENT_LENGTH'] = (string) (ini_octets((string) ini_get('post_max_size')) + 1);
        break;

    case 'absent':
    default:
        jeton_csrf();
        $_POST = [];
        break;
}

register_shutdown_function(static function (): void {
    /* En ligne de commande, http_response_code() rend false tant que rien
       ne l a fixé : c est le cas quand exiger_csrf() laisse passer la
       requête. On le traduit par le 200 qu un serveur web enverrait. */
    $code = http_response_code();
    echo "\n##CODE##", $code === false ? 200 : $code, "\n";
});

exiger_csrf();

// Atteint uniquement quand le jeton est accepté.
echo 'REQUETE-ACCEPTEE';
