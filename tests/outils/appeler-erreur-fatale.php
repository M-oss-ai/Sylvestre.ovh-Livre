<?php
/* =====================================================================
   Appelle erreur_fatale() et rend compte de ce qu elle a produit.

   Lancé en sous-processus par tests/cas/sorties_terminales_test.php :
   erreur_fatale() se termine par exit, donc rien ne peut être vérifié
   depuis le même processus.

       php tests/outils/appeler-erreur-fatale.php json
       php tests/outils/appeler-erreur-fatale.php html
       php tests/outils/appeler-erreur-fatale.php cron

   La sortie est celle de la fonction, suivie de deux marqueurs lus par
   le test : le code de réponse HTTP et le contenu du journal.
   ===================================================================== */

declare(strict_types=1);

require __DIR__ . '/../amorce.php';

$mode = (string) ($argv[1] ?? 'html');

/* Tous les modes sauf « cron » simulent une requête HTTP. REQUEST_METHOD
   est ce qui distingue les deux pour erreur_fatale() : un serveur web le
   renseigne toujours, le cron jamais. Sans lui, ces appels partiraient
   dans la branche « ligne de commande » et les tests du rendu HTML et
   JSON ne vérifieraient plus rien. */
if ($mode !== 'cron') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

if ($mode === 'json') {
    // Une requête AJAX : elle attend du JSON, pas une page HTML.
    $_SERVER['HTTP_ACCEPT'] = 'application/json, text/plain, */*';
} elseif ($mode === 'ajax') {
    // L autre signe qu une requête vient du JavaScript.
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
}

register_shutdown_function(static function (): void {
    echo "\n##CODE##", http_response_code(), "\n";
    echo '##JOURNAL##', str_replace("\n", ' ', journal_test()), "\n";
});

/* Le détail passé ici est exactement ce qui ne doit JAMAIS arriver à
   l écran : en production il contient le message d une exception, donc
   parfois un chemin de serveur, une requête SQL ou une valeur de
   configuration. */
erreur_fatale('DETAIL-CONFIDENTIEL: mot de passe base = secret, /home/site/includes/config.php:42');
