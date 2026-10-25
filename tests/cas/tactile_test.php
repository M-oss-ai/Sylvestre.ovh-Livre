<?php
/* =====================================================================
   Le double-appui ne zoome sur AUCUNE page (demande de l'utilisateur).

   Sur iPhone, Safari attend un éventuel second appui avant d'agir, et
   zoome s'il vient : appuyer plusieurs fois sur un bouton, un texte ou le
   fond de page agrandissait la page. « touch-action: manipulation »
   supprime ce geste-là et garde le pincement, le moyen d'agrandir pour qui
   en a besoin.

   Ce n'était pas assez, et la vraie cause est ailleurs : depuis iOS 13,
   Safari ne zoome pas au double-appui là où la page réagit déjà au clic, et
   un gestionnaire de « click » posé sur `document` fait réagir TOUTE la page
   (WebKit, bug 205158). index.php et parametres.php en avaient un sans le
   savoir (celui de commun.js) ; connexion, inscription et mentions légales
   n'en avaient aucun, et zoomaient à côté des champs. js/double-appui.js en
   pose un, qui ne fait rien : chaque page le charge.

   Ce test lit les SOURCES : c'est un garde-fou, pas une mesure du
   navigateur. Il tient les promesses que rien d'autre ne surveille —
   la règle porte sur tous les éléments (le comportement d'un élément est
   l'intersection du sien et de celui de ses ancêtres, jusqu'au premier
   cadre qui défile : une liste d'éléments laissait zoomer ailleurs), et
   toute page qui s'affiche charge la feuille et le script qui la portent.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

$css = (string) file_get_contents(CHEMIN_SITE . '/css/style.css');

/** Les pages qui affichent du HTML : celles qui ont une balise <html>. */
function pages_html(): array
{
    $pages = [];
    foreach (glob(CHEMIN_SITE . '/*.php') ?: [] as $fichier) {
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

test('chaque page charge js/double-appui.js : un clic posé sur le document', function () {
    /* C est ce gestionnaire qui empêche Safari de zoomer à côté d un champ.
       Une page qui l oublie zoome — c était le cas de la connexion, de
       l inscription et des mentions légales. */
    foreach (pages_html() as $nom => $source) {
        contient("actif('js/double-appui.js')", $source, $nom . ' charge js/double-appui.js');
    }
});

test('le script pose un gestionnaire de clic sur le document, et ne fait rien d autre', function () {
    $js = (string) file_get_contents(CHEMIN_SITE . '/js/double-appui.js');
    contient('document.addEventListener("click", () => {});', $js, 'un gestionnaire vide, sur le document');
    /* Le commentaire dit pourquoi : on ne regarde que le code. */
    $code = preg_replace('~/\*.*?\*/~s', '', $js);
    sans('preventDefault', $code, 'rien n est annulé : le clic fait son chemin');
    sans('gesturestart', $code, 'aucun blocage du pincement');
    sans('user-scalable', $code, 'aucune interdiction de zoomer');
    egale(1, substr_count($code, 'addEventListener'), 'un seul gestionnaire');
});

test('aucune page n interdit le zoom au pincement', function () {
    foreach (pages_html() as $nom => $source) {
        contient('name="viewport"', $source, $nom . ' déclare sa fenêtre d affichage');
        sans('user-scalable', $source, $nom . ' ne bloque pas le zoom');
        sans('maximum-scale', $source, $nom . ' ne plafonne pas le zoom');
    }
});

groupe('Tirer la page vers le bas pour l\'actualiser (js/commun.js)');

/** commun.js sans ses commentaires : on regarde le code, pas ce qu'il raconte. */
function commun_sans_commentaires(): string
{
    $js = (string) file_get_contents(CHEMIN_SITE . '/js/commun.js');
    return (string) preg_replace('~/\*.*?\*/~s', '', $js);
}

test('le geste n\'est branché que dans une application installée, sur écran tactile', function () {
    $code = commun_sans_commentaires();
    /* Un onglet de navigateur actualise déjà : le brancher là rechargerait deux fois. */
    contient('window.matchMedia("(pointer: coarse)").matches && modeApplication()', $code, 'tactile ET installée');
    contient('window.navigator.standalone === true', $code, 'l\'écran d\'accueil de l\'iPhone');
    contient('(display-mode: standalone)', $code, 'et celui des autres navigateurs');
});

test('le mouvement n\'est annulé que par un écouteur non passif, et seulement s\'il le peut', function () {
    $code = commun_sans_commentaires();
    $debut = (int) strpos($code, 'const finDuGeste');
    $bloc = substr($code, (int) strpos($code, 'let geste = null;'), $debut - (int) strpos($code, 'let geste = null;'));
    contient('{ passive: false }', $bloc, 'sans cela preventDefault() ne fait rien');
    contient('if (e.cancelable) e.preventDefault();', $bloc, 'et il ne s\'y essaie que si l\'évènement le permet');
    egale(1, substr_count($bloc, 'preventDefault'), 'un seul endroit annule le mouvement : le tirage reconnu');
});

test('le geste ne part pas d\'une fenêtre, d\'un champ, ni d\'une zone qui défile', function () {
    $code = commun_sans_commentaires();
    contient('.overlay:not(.hidden)', $code, 'une fenêtre ouverte garde son geste');
    contient('e.target.closest("input, textarea, select")', $code, 'un champ : le doigt y sélectionne du texte');
    contient('el.scrollTop > 0', $code, 'une zone déjà défilée remonte d\'abord');
    contient('<= 0', $code, 'et la page doit être tout en haut');
});

test('le rond se pose en CSSOM : aucun attribut « style », que la CSP refuse', function () {
    $code = commun_sans_commentaires();
    sans('setAttribute("style"', $code, 'pas d\'attribut style');
    sans('cssText', $code, 'ni de cssText');
    contient('indicateur.style.setProperty("--tirer"', $code, 'une propriété personnalisée, posée en CSSOM');
    contient('setAttribute("aria-hidden", "true")', $code, 'et il n\'est pas lu par un lecteur d\'écran');
});

test('commun.js est chargé par les pages de l\'application (bibliothèque, paramètres, administration)', function () {
    foreach (['index.php', 'parametres.php', 'admin.php'] as $page) {
        contient("actif('js/commun.js')", (string) file_get_contents(CHEMIN_SITE . '/' . $page), $page);
    }
});

test('style.css : le rond, son rechargement, et la désactivation du geste natif en mode installé', function () use ($css) {
    foreach (['.tirer-indicateur {', '.tirer-indicateur.tirer-pret', '.tirer-fleche {', '@keyframes tirer-tourne'] as $regle) {
        contient($regle, $css, $regle);
    }
    motif('/@media \(display-mode: standalone\)\s*\{\s*html\s*\{\s*overscroll-behavior-y:\s*contain;/', $css,
        'Chrome sur Android ne recharge pas aussi de son côté');
    contient('pointer-events: none;', substr($css, (int) strpos($css, '.tirer-indicateur {'), 700), 'le rond ne capte aucun toucher');
    sans('touch-action: none', $css, 'et le pincement reste permis');
});
