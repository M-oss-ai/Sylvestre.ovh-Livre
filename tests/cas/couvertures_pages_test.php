<?php
/* =====================================================================
   Les PAGES de la recherche de couverture (COUVERTURE_PAGE_MAX).

   Les séries retenues par la recherche sont montrées par tranches :
   « Page 2 / 3 », avec précédent et suivant. Ce fichier vérifie :
     - la découpe, pure (couverture_page) ;
     - les bornes du réglage, face à un .env hostile ;
     - son branchement : api.php, index.php, js/app.js, l'administration.
   Les constantes étant figées au chargement, le .env hostile vit ici dans
   son propre processus.
   ===================================================================== */

declare(strict_types=1);

putenv('COUVERTURE_PAGE_MAX=0');   // une page sans aucune série n'afficherait jamais rien

require __DIR__ . '/../lanceur.php';
require_once CHEMIN_SITE . '/includes/couvertures.php';

function source_pages(string $chemin): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/' . $chemin));
}

/** n candidats, rangés : le rang est la clé, comme dans chercher_couvertures(). */
function candidats_de_test(int $n): array
{
    $liste = [];
    for ($i = 0; $i < $n; $i++) {
        $liste[$i] = ['id' => 'serie-' . $i, 'nom' => 'Série ' . $i];
    }
    return $liste;
}

groupe('couverture_page() — la découpe en pages');

test('25 séries par pages de 9 : trois pages, 9 + 9 + 7', function () {
    $c = candidats_de_test(25);
    $p1 = couverture_page($c, 1, 9);
    $p3 = couverture_page($c, 3, 9);
    egale([1, 3, 25, 9], [$p1['page'], $p1['pages'], $p1['total'], count($p1['candidats'])], 'page 1');
    egale(9, count(couverture_page($c, 2, 9)['candidats']), 'page 2');
    egale([3, 7], [$p3['page'], count($p3['candidats'])], 'la dernière page porte le reste');
});

test('les pages se suivent sans trou ni doublon, et gardent leur rang d\'origine', function () {
    $c = candidats_de_test(25);
    $vus = [];
    for ($page = 1; $page <= 3; $page++) {
        foreach (couverture_page($c, $page, 9)['candidats'] as $rang => $cand) {
            $vus[] = $rang;
            egale('serie-' . $rang, $cand['id'], 'le rang est la clé : celui du classement, pas celui de la page');
        }
    }
    egale(range(0, 24), $vus, 'les 25 séries, une fois chacune, dans l\'ordre');
});

test('une seule page quand tout tient, ou quand la liste est vide', function () {
    egale([1, 1, 5], [couverture_page(candidats_de_test(5), 1, 9)['page'], couverture_page(candidats_de_test(5), 1, 9)['pages'], count(couverture_page(candidats_de_test(5), 1, 9)['candidats'])], '5 séries, pages de 9');
    egale(1, couverture_page(candidats_de_test(9), 1, 9)['pages'], 'pile une page pleine');
    $vide = couverture_page([], 1, 9);
    egale([1, 1, 0, []], [$vide['page'], $vide['pages'], $vide['total'], $vide['candidats']], 'une liste vide a UNE page, vide');
});

test('une page hors limites est ramenée dans [1, pages], jamais une page vide', function () {
    $c = candidats_de_test(25);
    egale(3, couverture_page($c, 99, 9)['page'], 'trop grande → la dernière');
    egale(1, couverture_page($c, 0, 9)['page'], 'zéro → la première');
    egale(1, couverture_page($c, -4, 9)['page'], 'négative → la première');
    egale(7, count(couverture_page($c, 99, 9)['candidats']), 'et elle porte des séries');
});

test('une taille de page absurde vaut 1 ; une page de 1 donne autant de pages que de séries', function () {
    $c = candidats_de_test(4);
    egale(4, couverture_page($c, 1, 0)['pages'], 'taille 0 → 1');
    egale(4, couverture_page($c, 1, -7)['pages'], 'taille négative → 1');
    egale(1, count(couverture_page($c, 2, 1)['candidats']), 'une série par page');
    egale(1, couverture_page(candidats_de_test(100), 1, 100)['pages'], '100 séries, pages de 100');
    egale(100, couverture_page(candidats_de_test(100), 1, 1)['pages'], '100 séries, pages de 1 : le maximum de pages');
});

groupe('COUVERTURE_PAGE_MAX — bornes et défaut');

test('le .env demandait 0 : relevé à 1 série par page, jamais une page vide', function () {
    egale(1, COUVERTURE_PAGE_MAX, 'plancher à 1');
});

test('le défaut est 9, le plafond 100 (le maximum de l\'API MangaDex)', function () {
    $cfg = source_pages('includes/config.php');
    contient("define('COUVERTURE_PAGE_MAX', min(100, max(1, (int) env('COUVERTURE_PAGE_MAX', '9'))));", $cfg, 'min 1, max 100, défaut 9');
    egale([1, 100, 9], [REGLAGES['COUVERTURE_PAGE_MAX']['min'], REGLAGES['COUVERTURE_PAGE_MAX']['max'], REGLAGES['COUVERTURE_PAGE_MAX']['defaut']],
        'l\'administration : de 1 à 100, défaut 9');
});

groupe('COUVERTURE_PAGE_MAX — le branchement');

test('chercher_couvertures() ne paie que les séries de la page demandée', function () {
    $src = source_pages('includes/couvertures.php');
    $i = (int) strpos($src, 'function chercher_couvertures(');
    $corps = substr($src, $i);
    contient('couverture_page($candidats, $page, COUVERTURE_PAGE_MAX)', $corps, 'la tranche, à la taille du réglage');
    vrai(strpos($corps, 'couverture_page(') < strpos($corps, 'mangadex_couvertures_serie('),
        'la tranche est faite AVANT les appels /cover : seules ses séries coûtent un appel');
    contient('$pagination = [', $corps, 'et la pagination est rendue à l\'appelant');
});

test('api.php : la page est lue, bornée, comptée comme une recherche, et rendue', function () {
    $api = source_pages('api.php');
    $bloc = substr($api, (int) strpos($api, "case 'couverture.chercher': {"), 4800);
    contient("\$page      = max(1, min(100, (int) (\$_POST['page'] ?? 1)));", $bloc, 'page entre 1 et 100');
    contient('chercher_couvertures($titre, $tome, $adulte, $page, $pagination)', $bloc, 'transmise à la recherche');
    contient("'page'      => \$pagination,", $bloc, 'rendue au navigateur');
    vrai(strpos($bloc, 'couverture_consommer(') < strpos($bloc, 'chercher_couvertures('),
        'le quota est compté AVANT la recherche, pour chaque page : elle coûte la même chose');
});

test('index.php : le sélecteur de pages, caché tant qu\'il n\'y a qu\'une page', function () {
    $page = source_pages('index.php');
    contient('id="cover-pages" class="cover-pages hidden"', $page, 'le bloc, caché au départ');
    foreach (['cover-prec', 'cover-suiv', 'cover-page-info'] as $id) {
        contient('id="' . $id . '"', $page, $id);
    }
    sans('onclick=', substr($page, (int) strpos($page, 'id="cover-pages"'), 700), 'aucun JavaScript en ligne (CSP)');
});

test('js/app.js : la page suivante redemande le MÊME titre et le MÊME tome', function () {
    $js = source_pages('js/app.js');
    contient('let rechercheCouv = null;', $js, 'la recherche dont on parcourt les pages est mémorisée');
    contient('({ titre, tome } = rechercheCouv);', $js, 'une page > 1 la réutilise');
    contient('L.api("couverture.chercher", { titre, tome, page })', $js, 'et demande la page');
    contient('B.pagesCouvertures(p && p.page, p && p.pages)', $js, 'le sélecteur vient de la fonction pure, testée');
    contient('addEventListener("click", () => chercherCouverture(1))', $js, 'le bouton de recherche repart de la page 1 (jamais l\'évènement en guise de page)');
});

test('l\'administration : COUVERTURE_PAGE_MAX est un réglage du groupe « recherche de couverture »', function () {
    egale('couverture', REGLAGES['COUVERTURE_PAGE_MAX']['groupe'], 'groupe');
    egale('séries', REGLAGES['COUVERTURE_PAGE_MAX']['unite'], 'unité');
    contient('COUVERTURE_PAGE_MAX', source_racine_pages('.env.example'), 'documenté dans .env.example');
});

function source_racine_pages(string $chemin): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_PROJET . '/' . $chemin));
}
