<?php
/* =====================================================================
   Point d'entrée de la suite de tests.

       php tests/lancer.php              tout
       php tests/lancer.php mot_de_passe  seulement les fichiers dont le
                                          nom contient « mot_de_passe »
       php tests/lancer.php javascript    seulement le JavaScript

   Chaque fichier de tests/cas/ est exécuté dans SON PROPRE PROCESSUS
   (voir tests/processus.php pour le pourquoi : constantes, caches
   « static », fonctions qui se terminent par exit). Ce script se
   contente de les lancer, de relayer leur sortie et d'additionner.

   Les tests JavaScript (tests/js/cas/) tournent ensuite dans un vrai
   navigateur sans fenêtre, sur la page tests/js/banc.html : ils sont
   lancés dès que le filtre est vide, vaut « javascript », ou désigne
   l'un de leurs fichiers.

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

$cas_js = glob(__DIR__ . '/js/cas/*.js') ?: [];
sort($cas_js);

if ($filtre !== '') {
    $correspond = static fn (string $f): bool => str_contains(basename($f), $filtre);
    $fichiers = array_values(array_filter($fichiers, $correspond));
    $lancer_js = str_contains('javascript', $filtre) || array_filter($cas_js, $correspond);
} else {
    $lancer_js = true;
}
$lancer_js = $lancer_js && $cas_js;

if (!$fichiers && !$lancer_js) {
    fwrite(STDERR, "Aucun fichier de test" . ($filtre !== '' ? " ne correspond à « {$filtre} »" : '') . ".\n");
    exit(1);
}

echo "\n";
echo "===========================================================\n";
echo "  Ma Bibliothèque Manga — tests unitaires\n";
echo '  PHP ', PHP_VERSION, ' · ', count($fichiers), ' fichier(s)',
     $lancer_js ? ' · JavaScript : ' . count($cas_js) . ' fichier(s)' : '', "\n";
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

/* ---------------------------------------------------------------------
   Le JavaScript, dans un navigateur sans fenêtre

   Le banc écrit son compte rendu dans la page, terminé par la même ligne
   « ##RESULTAT##total|échecs » que lanceur.php. Mêmes filets qu'en PHP :
   un fichier de cas que la page ne charge pas, un banc qui ne rend pas de
   compte rendu, un navigateur arrêté pour dépassement de temps comptent
   comme des échecs. Seule l'absence de navigateur ne l'est pas : elle
   est annoncée, en tête et en fin de rapport.
   --------------------------------------------------------------------- */
$total_js     = 0;
$js_non_lance = false;

if ($lancer_js) {
    echo "\n-- javascript (tests/js/banc.html) ", str_repeat('-', 22), "\n";
    $banc = __DIR__ . '/js/banc.html';

    /* Un fichier de cas que banc.html ne charge pas ne tournerait jamais,
       sans que personne ne s'en aperçoive. */
    $page = (string) file_get_contents($banc);
    foreach ($cas_js as $cas) {
        if (!str_contains($page, 'src="cas/' . basename($cas) . '"')) {
            $echecs++;
            $fichiers_casse[] = basename($cas);
            echo '    [OUBLIÉ] cas/', basename($cas), " n'est pas chargé par tests/js/banc.html\n";
        }
    }

    $navigateur = navigateur_de_test();
    if ($navigateur === null) {
        $js_non_lance = true;
        echo "    [NON LANCÉ] aucun navigateur trouvé (Edge, Chrome, Chromium).\n",
             "    Indiquez-en un : NAVIGATEUR=/chemin/vers/chrome php tests/lancer.php\n",
             "    Ou ouvrez tests/js/banc.html dans un navigateur.\n";
    } else {
        $resultat = executer_navigateur($navigateur, $banc);
        $rapport  = preg_match('#<pre id="compte-rendu">(.*?)</pre>#s', $resultat['sortie'], $m)
            ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : '';

        if (preg_match('/^##RESULTAT##(\d+)\|(\d+)$/m', $rapport, $r)) {
            $total_js = (int) $r[1];
            $total   += $total_js;
            $echecs  += (int) $r[2];
            echo trim(preg_replace('/^##RESULTAT##\d+\|\d+$/m', '', $rapport) ?? $rapport, "\r\n"), "\n";
        } else {
            $echecs++;
            $fichiers_casse[] = 'javascript';
            echo trim($rapport), "\n",
                 "\n    [INTERROMPU] le banc n'a pas rendu de compte rendu",
                 $resultat['code'] === 124 ? ' (temps dépassé)' : ' (code de retour ' . $resultat['code'] . ')', "\n";
            if (trim($resultat['erreurs']) !== '') {
                echo '    Navigateur : ', mb_substr(trim($resultat['erreurs']), 0, 600), "\n";
            }
        }
    }
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
// Un profil de navigateur qu'un processus encore ouvert aurait empêché d'effacer.
foreach (glob(sys_get_temp_dir() . '/livre-test-navigateur-*-profil', GLOB_ONLYDIR) ?: [] as $profil) {
    supprimer_dossier($profil);
}

$detail = $total_js > 0 ? ' (PHP ' . ($total - $total_js) . ', JavaScript ' . $total_js . ')' : '';

echo "\n===========================================================\n";
if ($echecs === 0) {
    echo '  ', $total, ' test(s)', $detail, " — tout passe. (", $duree, " s)\n";
} else {
    echo '  ', $total, ' test(s)', $detail, ', ', $echecs, " ÉCHEC(S). (", $duree, " s)\n";
    if ($fichiers_casse) {
        echo '  Fichiers interrompus : ', implode(', ', $fichiers_casse), "\n";
    }
}
if ($js_non_lance) {
    echo "  Tests JavaScript NON LANCÉS : aucun navigateur trouvé.\n";
}
echo "===========================================================\n\n";

exit($echecs === 0 ? 0 : 1);
