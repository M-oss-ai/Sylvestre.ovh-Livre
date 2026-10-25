<?php
/* =====================================================================
   SANS_SESSION : l'API n'ouvre pas de session, les pages si.

   includes/fonctions.php démarre la session dès son inclusion. L'API
   définit SANS_SESSION d'abord pour l'éviter ; ce fichier prouve les
   deux côtés, car casser le second (les pages) serait une régression du
   site existant.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

/** Exécute un script qui charge le projet (avec ou sans SANS_SESSION) et rend son verdict. */
function verdict_session(bool $sans_session): string
{
    $script = tempnam(sys_get_temp_dir(), 'livre-sess-') . '.php';
    file_put_contents($script, '<?php ' . ($sans_session ? "define('SANS_SESSION', true);" : '')
        . "require " . var_export(__DIR__ . '/../amorce.php', true) . ';'
        . "echo session_status() === PHP_SESSION_ACTIVE ? 'ACTIVE' : 'AUCUNE';"
        . "echo '|', function_exists('verifier_session_persistante') ? 'fonction-presente' : 'fonction-absente';"
        . "echo '|', isset(\$_SESSION) ? 'SESSION-definie' : 'pas-de-SESSION';");
    $r = executer_php($script);
    @unlink($script);
    return trim($r['sortie']);
}

groupe('Session — pages et API');

test('une page (sans SANS_SESSION) démarre sa session comme avant', function () {
    contient('ACTIVE', verdict_session(false), 'la session est ouverte');
    contient('SESSION-definie', verdict_session(false), '$_SESSION existe');
});

test('l\'API (avec SANS_SESSION) n\'ouvre aucune session', function () {
    $v = verdict_session(true);
    contient('AUCUNE', $v, 'pas de session');
    contient('pas-de-SESSION', $v, 'pas de $_SESSION');
    contient('fonction-presente', $v, 'le reste de fonctions.php est bien chargé');
});
