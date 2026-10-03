<?php
/* =====================================================================
   Le double-appui ne zoome sur AUCUNE page (demande de l'utilisateur).

   Sur iPhone, Safari attend un éventuel second appui avant d'agir, et
   zoome s'il vient : appuyer plusieurs fois sur un bouton, un texte ou le
   fond de page agrandissait la page. « touch-action: manipulation »
   supprime ce geste-là et garde le pincement, le moyen d'agrandir pour qui
   en a besoin.

   Ce test lit les SOURCES : c'est un garde-fou, pas une mesure du
   navigateur. Il tient deux promesses que rien d'autre ne surveille —
   la règle porte sur tous les éléments (le comportement d'un élément est
   l'intersection du sien et de celui de ses ancêtres, jusqu'au premier
   cadre qui défile : une liste d'éléments laissait zoomer ailleurs), et
   toute page qui s'affiche charge la feuille qui la porte.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

$css = (string) file_get_contents(CHEMIN_PROJET . '/css/style.css');

/** Les pages qui affichent du HTML : celles qui ont une balise <html>. */
function pages_html(): array
{
    $pages = [];
    foreach (glob(CHEMIN_PROJET . '/*.php') ?: [] as $fichier) {
        $source = (string) file_get_contents($fichier);
        if (str_contains($source, '<html')) {
            $pages[basename($fichier)] = $source;
        }
    }
    return $pages;
}

groupe('css/style.css — le double-appui ne zoome pas');

test('une règle universelle supprime le zoom au double-appui', function () use ($css) {
    motif('/^\*\s*\{[^}]*touch-action:\s*manipulation\s*;/m', $css,
        'la règle « * { touch-action: manipulation } » existe');
});

test('le pincement reste possible', function () use ($css) {
    /* « none » ou un « pan-… » sans « pinch-zoom » le couperaient aussi :
       agrandir à deux doigts reste le recours de qui lit mal. */
    sans('touch-action: none', $css, 'aucune règle ne coupe tous les gestes');
    sans('touch-action: pan', $css, 'aucune règle ne se limite au défilement');
});

groupe('Les pages — toutes chargent cette règle');

test('toutes les pages HTML sont trouvées', function () {
    $pages = pages_html();
    /* Garde-fou du test lui-même : un glob qui ne trouve rien ferait
       passer les vérifications suivantes à vide. */
    vrai(count($pages) >= 10, count($pages) . ' pages trouvées, au moins 10 attendues');
    foreach (['index.php', 'connexion.php', 'inscription.php', 'parametres.php', 'mentions-legales.php'] as $page) {
        vrai(isset($pages[$page]), $page . ' est une page HTML');
    }
});

test('chaque page charge la feuille de style', function () {
    foreach (pages_html() as $nom => $source) {
        contient("actif('css/style.css')", $source, $nom . ' charge css/style.css');
    }
});

test('aucune page n interdit le zoom au pincement', function () {
    foreach (pages_html() as $nom => $source) {
        contient('name="viewport"', $source, $nom . ' déclare sa fenêtre d affichage');
        sans('user-scalable', $source, $nom . ' ne bloque pas le zoom');
        sans('maximum-scale', $source, $nom . ' ne plafonne pas le zoom');
    }
});
