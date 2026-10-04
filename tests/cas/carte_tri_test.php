<?php
/* =====================================================================
   Le total de la bibliothèque et le tri (demande de l'utilisateur :
   « voir le nombre de mes séries, le nombre de livres que j'ai lu au total,
   trier les séries par tomes restants jusqu'à la fin ou par tome actuel »).

   Côté PHP :
     - serie_restants() / serie_restants_texte() : les tomes qu'il reste à lire d'après
       ce que MangaDex connaît ; null quand on ne le sait pas ;
     - statistiques_series() : « 42 séries · 318 tomes lus » ;
     - carte_html() : data-restants (lu par le tri) et « il en reste N » (caché sauf
       sous le tri « tomes restants »).
   Le tri lui-même (comparerTri) et le même texte côté navigateur se jugent dans
   tests/js/cas/bibliotheque_test.js ; ici on vérifie que les deux côtés parlent de la
   même chose (les valeurs du menu = Bibliotheque.TRIS).
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

/** Une série liée à MangaDex, dont on ne change que ce que le test regarde. */
function serie_liee(array $modifications = []): array
{
    return $modifications + [
        'id'           => 9,
        'titre'        => 'Berserk',
        'auteur'       => 'Kentaro Miura',
        'tome_actuel'  => 40,
        'statut'       => 'cours',
        'couverture'   => '',
        'dernier_tome' => 43,
        'tome_final'   => 0,
        'publication'  => 'ongoing',
    ];
}

groupe('serie_restants() — les tomes qu il reste à lire');

test('le dernier tome connu moins le tome lu', function () {
    egale(3, serie_restants(serie_liee()), '43 connus, 40 lus');
    egale(43, serie_restants(serie_liee(['tome_actuel' => 0])), 'rien de lu : tout reste');
});

test('rien à lire : 0, une vraie réponse', function () {
    egale(0, serie_restants(serie_liee(['tome_actuel' => 43])), 'le dernier tome connu est lu');
});

test('le dernier volume DÉCLARÉ par MangaDex compte quand les dernières couvertures manquent', function () {
    egale(7, serie_restants(serie_liee(['tome_actuel' => 43, 'tome_final' => 50])), '50 déclarés, 43 couvertures, 43 lus');
    egale(3, serie_restants(serie_liee(['dernier_tome' => 0, 'tome_final' => 43])), 'seul le volume déclaré est connu');
    egale(3, serie_restants(serie_liee(['tome_final' => 10])), 'le plus haut des deux : 43, pas 10');
});

test('on ne sait pas : null — jamais « 0 reste »', function () {
    estNul(serie_restants(serie_liee(['dernier_tome' => 0, 'tome_final' => 0])), 'aucun tome connu (série pas liée, ou pas encore lue)');
    estNul(serie_restants(serie_liee(['tome_actuel' => 44])), 'lue AU-DELÀ de ce que MangaDex connaît : MangaDex est en retard');
    estNul(serie_restants([]), 'une ligne sans les colonnes');
    estNul(serie_restants(['tome_actuel' => 5]), 'sans dernier tome');
});

test('des valeurs absurdes ne donnent jamais un nombre négatif', function () {
    estNul(serie_restants(serie_liee(['dernier_tome' => -5, 'tome_final' => -1])), 'des tomes connus négatifs : inconnus');
    egale(43, serie_restants(serie_liee(['tome_actuel' => -3])), 'un tome lu négatif vaut 0');
    egale(3, serie_restants(serie_liee(['tome_actuel' => '40', 'dernier_tome' => '43'])), 'les nombres lus de la base sont des chaînes');
});

groupe('serie_restants_texte() — ce que dit la carte');

test('« il en reste N », « à jour », ou rien', function () {
    egale('il en reste 3', serie_restants_texte(3), 'plusieurs');
    egale('il en reste 1', serie_restants_texte(1), 'un seul : « il en reste 1 », la phrase reste juste');
    egale('à jour', serie_restants_texte(0), 'plus rien à lire');
    egale('', serie_restants_texte(null), 'on ne sait pas : on ne dit rien');
});

groupe('statistiques_series() — le total de la bibliothèque');

test('le nombre de séries et la somme des tomes lus', function () {
    $s = statistiques_series([['tome_actuel' => 5], ['tome_actuel' => 3], ['tome_actuel' => 0]]);
    egale(3, $s['series'], 'trois séries');
    egale(8, $s['tomes'], '5 + 3 + 0 tomes lus');
    egale('3 séries · 8 tomes lus', $s['texte'], 'le texte, au pluriel');
});

test('0 et 1 sont au singulier, en français', function () {
    egale('0 série · 0 tome lu', statistiques_series([])['texte'], 'bibliothèque vide');
    egale('1 série · 1 tome lu', statistiques_series([['tome_actuel' => 1]])['texte'], 'une série, un tome');
    egale('1 série · 0 tome lu', statistiques_series([['tome_actuel' => 0]])['texte'], 'une série pas commencée');
    egale('2 séries · 1 tome lu', statistiques_series([['tome_actuel' => 1], ['tome_actuel' => 0]])['texte'], 'deux séries, un tome');
    egale('1 série · 12 tomes lus', statistiques_series([['tome_actuel' => 12]])['texte'], 'une série, douze tomes');
});

test('un tome négatif ou absent ne retire rien', function () {
    egale(5, statistiques_series([['tome_actuel' => 5], ['tome_actuel' => -9], []])['tomes'], 'seul ce qui est lu compte');
    egale(12, statistiques_series([['tome_actuel' => '12']])['tomes'], 'les chaînes de la base');
});

groupe('carte_html() — data-restants et « il en reste N »');

test('la carte porte les tomes restants pour le tri, et le dit à la suite de la progression', function () {
    $html = carte_html(serie_liee());
    contient('data-restants="3"', $html, 'l attribut lu par js/app.js');
    contient('<span class="card-restants">il en reste 3</span>', $html, 'le texte, caché sauf sous le tri');
    motif('~<p class="card-progress">Vous avez lu le tome <b>40</b><span class="card-restants">~', $html, 'DANS la progression, à la suite du tome lu');
});

test('à jour : « à jour », et data-restants vaut 0', function () {
    $html = carte_html(serie_liee(['tome_actuel' => 43]));
    contient('data-restants="0"', $html, '0 est une réponse');
    contient('<span class="card-restants">à jour</span>', $html, 'à jour');
});

test('on ne sait pas : data-restants vide et aucun texte ajouté', function () {
    $html = carte_html(serie_liee(['dernier_tome' => 0]));
    contient('data-restants=""', $html, 'vide, pas « 0 »');
    sans('card-restants', $html, 'aucun texte inventé');
    $html = carte_html(serie_liee(['tome_actuel' => 44]));
    contient('data-restants=""', $html, 'lue au-delà de MangaDex : inconnu');
    sans('il en reste', $html, 'et rien de dit');
});

test('une ligne sans les colonnes de MangaDex ne casse pas la carte', function () {
    $html = carte_html(['id' => 1, 'titre' => 'X', 'auteur' => '', 'tome_actuel' => 2, 'statut' => 'cours', 'couverture' => '']);
    contient('data-restants=""', $html, 'inconnu');
    contient('Vous avez lu le tome <b>2</b>', $html, 'la progression est intacte');
});

test('un compte bloqué (consultation seule) voit les mêmes tomes restants', function () {
    $html = carte_html(serie_liee(), true);
    contient('data-restants="3"', $html, 'le tri est une lecture');
    contient('il en reste 3', $html, 'la mention aussi');
});

groupe('La page — les boutons de tri et le total');

$index = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/index.php'));
$js    = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/js/app.js'));
$css   = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/css/style.css'));

test('les boutons de tri (data-critere) sont exactement les critères de Bibliotheque.CRITERES', function () use ($index, $js) {
    preg_match_all('~<button type="button" class="filter-btn tri-btn[^"]*" data-critere="([^"]+)"~', $index, $boutons);
    preg_match('~const CRITERES = \[([^\]]*)\]~', $js, $liste);
    vrai(isset($liste[1]), 'app.js déclare CRITERES');
    preg_match_all('~"([^"]+)"~', $liste[1] ?? '', $valeurs);
    egale($valeurs[1], $boutons[1], 'même liste, même ordre : un critère ajouté d un côté s ajoute de l autre');
    egale('recentes', $boutons[1][0] ?? '', 'le premier est le défaut : l ordre du serveur');
    // Et chaque tri mémorisé a son critère : « restants-asc » → « restants ».
    preg_match('~const TRIS = \[([^\]]*)\]~', $js, $tris);
    preg_match_all('~"([^"]+)"~', $tris[1] ?? '', $valeursTris);
    $criteres = array_values(array_unique(array_map(static fn (string $t): string => explode('-', $t)[0], $valeursTris[1])));
    egale($criteres, $valeurs[1], 'les critères sont ceux qu on déduit des tris : aucun bouton sans tri, aucun tri sans bouton');
});

test('trois boutons dans l ordre « Plus récent », « Tome », « Tomes restants », dont un seul allumé au départ', function () use ($index) {
    vrai(substr_count($index, 'class="filter-btn tri-btn active"') === 1, 'un seul actif : « Plus récent »');
    vrai(substr_count($index, 'class="filter-btn tri-btn') === 3, 'trois boutons');
    contient('data-critere="recentes" aria-pressed="true"', $index, 'dit aux lecteurs d écran lequel est choisi');
    motif('~data-critere="recentes".*?Plus récent.*?data-critere="tome".*?>Tome<.*?data-critere="restants".*?Tomes restants~s', $index, 'dans l ordre de la demande');
    contient('<span class="tri-libelle">Plus récent</span><span class="tri-fleche" aria-hidden="true">↓</span>', $index, 'le tri par défaut porte déjà sa flèche : les plus récentes d abord, ↓');
    vrai(substr_count($index, '<span class="tri-fleche" aria-hidden="true"></span>') === 2, 'les deux autres boutons n en ont pas (vide, sans place)');
});

test('les boutons sont DANS la barre collée, sur une rangée qui coulisse comme les filtres', function () use ($index) {
    $barre = strpos($index, 'id="barre-filtres"');
    $tri   = strpos($index, 'id="tri-rangee"');
    $total = strpos($index, 'id="ligne-outils"');
    vrai($barre !== false && $tri !== false && $total !== false, 'les trois sont dans la page');
    vrai($barre < $tri && $tri < $total, 'après les filtres, avant le total : donc DANS la barre, qui se ferme avant le total');
    motif('~<nav class="filters filters-tri<\?= \$series \? \'\' : \' hidden\' \?>" id="tri-rangee" aria-label="Trier">~', $index,
        'une rangée « .filters » (une ligne, défilement sur le côté, fondu), cachée tant qu il n y a aucune série');
    motif('~<span class="tri-titre">Trier par</span>~', $index, 'le titre de la rangée, qui coulisse avec elle');
    // La barre se ferme APRÈS la rangée du tri et AVANT le total : le total ne se colle pas.
    $apres = substr($index, $tri, $total - $tri);
    vrai(substr_count($apres, '</nav>') === 1 && substr_count($apres, '</div>') >= 1, 'la rangée se ferme, puis la barre');
});

test('aucune phrase d explication, aucun bouton « Inverser » : trop d infos (demande de l utilisateur)', function () use ($index) {
    sans('tri-aide', $index, 'plus de phrase sous les boutons');
    sans('tri-explication', $index, 'ni de bloc qui la portait');
    sans('id="tri-inverser"', $index, 'plus de bouton Inverser : recliquer le bouton allumé suffit');
    sans('Les séries où il reste', $index, 'ni la phrase des tomes restants');
    sans('d\'après MangaDex) que vous n\'avez pas lus', $index, 'ni son explication');
    sans('<select', substr($index, (int) strpos($index, 'id="tri-rangee"'), 1500), 'et plus de menu déroulant');
});
test('le total a son emplacement', function () use ($index) {
    contient('id="stats-bibliotheque"', $index, 'le total, que js/app.js tient à jour');
    contient('statistiques_series($series)', $index, 'rendu par la même fonction que celle testée plus haut');
    contient('id="ligne-outils" class="ligne-outils<?= $series ? \'\' : \' hidden\' ?>"', $index, 'caché tant qu il n y a aucune série');
});

test('la barre de filtres reste le PREMIER élément de <main> : la ligne du total vient après', function () use ($index) {
    /* Les filtres se collent dès le premier pixel de défilement parce qu'ils ouvrent <main>
       (voir index.php et css/style.css) : rien ne doit s'y glisser devant. */
    motif('~<main class="bibliotheque">\s*(?:<!--.*?-->\s*)*<div class="barre-filtres" id="barre-filtres">~s', $index, 'la barre ouvre <main>');
    $barre  = strpos($index, 'id="barre-filtres"');
    $outils = strpos($index, 'id="ligne-outils"');
    vrai($barre !== false && $outils !== false && $barre < $outils, 'puis le total et le tri, sous les filtres');
});

test('« il en reste N » est caché par défaut et ne se montre que sous le tri « tomes restants »', function () use ($css, $js) {
    motif('~\.card-restants \{ display: none; \}~', $css, 'caché');
    motif('~\.tri-restants \.card-restants \{\s*display: block;~', $css, 'montré sous .tri-restants, sur sa propre ligne');
    contient('$grid.classList.toggle("tri-restants", tri === "restants-asc" || tri === "restants-desc")', $js, 'la classe suit le tri choisi');
});

test('le tri est mémorisé avec les filtres, et lu par triValide() : une mémoire illisible ne casse rien', function () use ($js) {
    contient('tri = B.triValide(f.tri);', $js, 'à la lecture');
    motif('~panneau: panneauOuvert,\s*tri,~', $js, 'à l écriture');
});

test('changer de tri revient à la première page de la liste', function () use ($js) {
    motif('~prep\.q, tri\]\)~', $js, 'le tri est dans la signature de la vue : une autre vue repart de la page 1');
});
