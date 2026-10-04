<?php
/* =====================================================================
   Les PAGES de la bibliothèque (SERIES_PAGES_MAX, index.php).

   La bibliothèque montre SERIES_PAGES_MAX séries, puis un bouton « Afficher
   plus ». Le découpage se fait dans le navigateur : toutes les séries restent
   chargées, donc la recherche, les filtres et les compteurs portent sur TOUTES
   (demande de l'utilisateur). La logique pure (Bibliotheque.pagesSeries) est
   testée dans tests/js ; ce fichier vérifie :
     - les bornes du réglage, face à un .env hostile (processus à part : les
       constantes sont figées au chargement) ;
     - son branchement : index.php, js/app.js, l'administration, .env.example.
   ===================================================================== */

declare(strict_types=1);

putenv('SERIES_PAGES_MAX=0');   // 0 : pas de pages, toutes les séries sur une seule page (comme avant)

require __DIR__ . '/../lanceur.php';

function source_series_pages(string $chemin): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/' . $chemin));
}

groupe('SERIES_PAGES_MAX — bornes et défaut');

test('le .env dit 0 : « pas de pages », conservé tel quel — toutes les séries sur une seule page', function () {
    egale(0, SERIES_PAGES_MAX, 'zéro est une intention, pas une valeur absurde à relever');
});

test('le défaut est 30 (clé absente), les bornes 0 à 100 — dans le code comme dans l\'administration', function () {
    contient("define('SERIES_PAGES_MAX', min(100, max(0, (int) env('SERIES_PAGES_MAX', '30'))));", source_series_pages('includes/config.php'),
        'min 0, max 100, défaut 30 : la clé absente donne 30, pas 0');
    egale([0, 100, 30], [REGLAGES['SERIES_PAGES_MAX']['min'], REGLAGES['SERIES_PAGES_MAX']['max'], REGLAGES['SERIES_PAGES_MAX']['defaut']],
        'l\'administration : de 0 à 100, défaut 30');
    egale('toutes sur une seule page', REGLAGES['SERIES_PAGES_MAX']['zero'], 'et dit ce que veut dire 0');
});

test('l\'administration accepte 0 (une seule page) et 1 à 100, refuse le reste', function () {
    egale(['0', ''], reglage_valider('SERIES_PAGES_MAX', '0'), '0 : pas de pages');
    egale(['1', ''], reglage_valider('SERIES_PAGES_MAX', '1'), '1 : une série par page');
    egale(['30', ''], reglage_valider('SERIES_PAGES_MAX', '30'), '30');
    egale(['100', ''], reglage_valider('SERIES_PAGES_MAX', '100'), '100');
    estNul(reglage_valider('SERIES_PAGES_MAX', '101')[0], '101 : au-delà du plafond');
    estNul(reglage_valider('SERIES_PAGES_MAX', '-1')[0], '-1 : négatif');
    estNul(reglage_valider('SERIES_PAGES_MAX', 'abc')[0], 'pas un nombre');
});

test('l\'administration : un réglage du groupe « quotas », en séries, avec son aide', function () {
    egale('quotas', REGLAGES['SERIES_PAGES_MAX']['groupe'], 'groupe');
    egale('séries', REGLAGES['SERIES_PAGES_MAX']['unite'], 'unité');
    contient('Afficher plus', REGLAGES['SERIES_PAGES_MAX']['aide'], 'l\'aide dit ce qui se passe au-delà');
    contient('toutes les séries', REGLAGES['SERIES_PAGES_MAX']['aide'], 'et que la recherche porte sur toutes');
});

test('.env.example documente le réglage', function () {
    $env = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_PROJET . '/.env.example'));
    contient('SERIES_PAGES_MAX=30', $env, 'avec son défaut');
});

groupe('SERIES_PAGES_MAX — le branchement');

test('js/app.js : 0 (ou rien) veut dire « pas de découpage »', function () {
    $js = source_series_pages('js/app.js');
    contient('const SERIES_PAR_PAGE = parseInt(document.body.dataset.seriesParPage || "0", 10) || 0;', $js, '0, absent ou illisible : 0');
    contient('const limite = taille > 0 ? taille * p : n;', $js, 'une taille nulle ne coupe rien : la limite est le total');
});

test('index.php : la taille d\'une page vient de la constante, le bouton est caché au départ', function () {
    $page = source_series_pages('index.php');
    contient('data-series-par-page="<?= (int) SERIES_PAGES_MAX ?>"', $page, 'posée sur <body> (la CSP interdit le JavaScript en ligne)');
    contient('id="plus-series" class="plus-series hidden"', $page, 'le bloc est caché tant que tout tient');
    contient('id="btn-plus-series"', $page, 'le bouton');
    contient('id="plus-info"', $page, 'le décompte');
    sans('onclick=', substr($page, (int) strpos($page, 'id="plus-series"'), 400), 'aucun JavaScript en ligne');
    vrai(strpos($page, 'id="grid"') < strpos($page, 'id="plus-series"'), 'sous la grille');
    contient('echo carte_html($s, $bloque);', $page, 'toutes les séries sont toujours rendues : le serveur ne découpe pas');
});

test('js/app.js : le découpage se fait APRÈS le filtre et la recherche, et repart de la page 1 quand la vue change', function () {
    $js = source_series_pages('js/app.js');
    $vue = substr($js, (int) strpos($js, 'function appliquerVue()'), 4200);
    contient('B.pagesSeries(visibles, SERIES_PAR_PAGE, pagesAffichees)', $vue, 'la taille vient de la fonction pure, testée');
    contient('JSON.stringify([[...filtresStatut].sort(), [...filtresImage].sort(), favorisSeuls, prep.q])', $vue,
        'la vue = filtres + recherche : une autre vue repart de la première page');
    contient('pagesAffichees = 1;', $vue, 'remise à une page');
    vrai(strpos($vue, 'ordreBouscule = false;') < strpos($vue, 'B.pagesSeries('),
        'après le classement par pertinence : on garde les PREMIÈRES séries dans l\'ordre définitif');
    contient('c.classList.add("hidden")', $vue, 'les séries au-delà se cachent comme les séries filtrées : le clavier ne les voit pas');
    contient('$grid.classList.toggle("hidden", visibles === 0);', $vue, 'la grille et « aucune série » se décident sur les séries qui RÉPONDENT, pas sur celles qu\'on montre');
});

test('js/app.js : « Afficher plus » ajoute une page, et rend le focus quand il disparaît', function () {
    $js = source_series_pages('js/app.js');
    $bloc = substr($js, (int) strpos($js, '$btnPlus.addEventListener("click"'), 800);
    contient('pagesAffichees++;', $bloc, 'une page de plus');
    contient('appliquerVue();', $bloc, 'la vue est recalculée');
    contient('focus({ preventScroll: true })', $bloc, 'le focus va à la première série qui vient d\'apparaître, sans faire défiler la page');
});

test('style.css : le bouton « Afficher plus » a sa mise en page', function () {
    $css = source_series_pages('css/style.css');
    contient('.plus-series {', $css, 'le bloc');
    contient('.plus-info {', $css, 'le décompte');
});
