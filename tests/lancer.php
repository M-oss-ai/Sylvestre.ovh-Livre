<?php
/* =====================================================================
   Point d'entrée de la suite de tests.

       php tests/lancer.php              tout
       php tests/lancer.php mot_de_passe  seulement les fichiers dont le
                                          nom contient « mot_de_passe »

   Chaque fichier de tests/cas/ est exécuté dans SON PROPRE PROCESSUS
   (voir tests/processus.php pour le pourquoi : constantes, caches
   « static », fonctions qui se terminent par exit). Ce script se
   contente de les lancer, de relayer leur sortie et d'additionner.

   Code de retour : 0 si tout passe, 1 sinon — pour qu'un jour
   l'intégration continue ou un crochet Git puisse s'en servir.
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/processus.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");   // ces tests ne s'exécutent jamais depuis le web
}

if (function_exists('sapi_windows_cp_set')) {
    @sapi_windows_cp_set(65001);
}

$filtre = (string) ($argv[1] ?? '');

$fichiers = glob(__DIR__ . '/cas/*.php') ?: [];
sort($fichiers);

if ($filtre !== '') {
    $fichiers = array_values(array_filter(
        $fichiers,
        static fn (string $f): bool => str_contains(basename($f), $filtre)
    ));
}

if (!$fichiers) {
    fwrite(STDERR, "Aucun fichier de test" . ($filtre !== '' ? " ne correspond à « {$filtre} »" : '') . ".\n");
    exit(1);
}

echo "\n";
echo "===========================================================\n";
echo "  Ma Bibliothèque Manga — tests unitaires\n";
echo '  PHP ', PHP_VERSION, ' · ', count($fichiers), " fichier(s)\n";
echo "===========================================================\n";

$total          = 0;
$echecs         = 0;
$fichiers_casse = [];
$demarre        = microtime(true);

foreach ($fichiers as $fichier) {
    $nom = basename($fichier, '.php');
    echo "\n-- ", $nom, ' ', str_repeat('-', max(1, 55 - strlen($nom))), "\n";

    /* Le détour par executer-cas.php permet de savoir si le fichier est
       allé jusqu'à sa dernière ligne — voir ce fichier pour le pourquoi. */
    $resultat = executer_php(__DIR__ . '/executer-cas.php', [$fichier]);
    $sortie   = $resultat['sortie'];

    /* La dernière ligne « ##RESULTAT##total|échecs » est le compte rendu
       de lanceur.php. On l'extrait et on affiche le reste tel quel. */
    if (preg_match('/^##RESULTAT##(\d+)\|(\d+)$/m', $sortie, $m)) {
        $echecs_fichier = (int) $m[2];

        /* Un code de retour non nul alors que le compte rendu annonce
           zéro échec : le fichier s'est arrêté d'une façon que lanceur.php
           n'a pas su nommer. On ne laisse pas passer. */
        if ($resultat['code'] !== 0 && $echecs_fichier === 0) {
            $echecs_fichier = 1;
            $fichiers_casse[] = $nom;
            $sortie .= "\n    [CODE " . $resultat['code'] . "] le processus s'est terminé"
                     . " en erreur malgré un compte rendu sans échec.\n";
        }

        $total  += (int) $m[1];
        $echecs += $echecs_fichier;
        $sortie  = trim(preg_replace('/^##RESULTAT##\d+\|\d+$/m', '', $sortie) ?? $sortie, "\r\n");
        echo $sortie, "\n";
        continue;
    }

    /* Pas de compte rendu : le fichier s'est interrompu avant la fin
       (erreur fatale, base injoignable, boucle infinie tuée…). Le compter
       comme « aucun test » le ferait passer pour un succès. */
    $echecs++;
    $fichiers_casse[] = $nom;
    echo trim($sortie), "\n";
    echo "\n    [INTERROMPU] ce fichier n'a pas rendu de compte rendu",
         ' (code de retour ', $resultat['code'], ")\n";
}

$duree = round(microtime(true) - $demarre, 2);

/* ---------------------------------------------------------------------
   Ménage

   Chaque processus de test écrit son journal et ses sessions dans le
   dossier temporaire du système (voir tests/amorce.php). Il ne peut pas
   les effacer lui-même : les scripts de tests/outils/ relisent leur
   journal depuis un gestionnaire de fin de script, et un effacement
   depuis l intérieur arriverait trop tôt. C est donc ici, une fois tous
   les processus terminés, que ça se fait.
   --------------------------------------------------------------------- */
foreach (glob(sys_get_temp_dir() . '/livre-test-*.log') ?: [] as $journal) {
    @unlink($journal);
}
foreach (glob(sys_get_temp_dir() . '/livre-test-sessions/sess_*') ?: [] as $session) {
    @unlink($session);
}

echo "\n===========================================================\n";
if ($echecs === 0) {
    echo '  ', $total, " test(s) — tout passe. (", $duree, " s)\n";
} else {
    echo '  ', $total, ' test(s), ', $echecs, " ÉCHEC(S). (", $duree, " s)\n";
    if ($fichiers_casse) {
        echo '  Fichiers interrompus : ', implode(', ', $fichiers_casse), "\n";
    }
}
echo "===========================================================\n\n";

exit($echecs === 0 ? 0 : 1);
