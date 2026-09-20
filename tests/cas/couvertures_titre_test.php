<?php
/* =====================================================================
   includes/couvertures.php — les deux fonctions qui ne parlent pas au
   réseau.

   Le reste du fichier interroge MangaDex : hors du périmètre de cette
   suite, qui ne sort jamais de la machine. Mais le classement des
   résultats repose entièrement sur titre_normalise(), et c est lui qui
   fait remonter la bonne série.

   Ce n est pas un détail cosmétique. Sans ce classement, chercher
   « Berserk » proposait « VRMMO Chronicles of a Solo Cleric » en
   premier : MangaDex classe par pertinence, et sa pertinence ne
   privilégie pas le titre exact.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

/* couvertures.php n'est pas dans la pile chargée par chaque page : seul
   api.php l'inclut, et uniquement pour l'action de recherche. Le test
   doit donc le demander explicitement. */
require_once CHEMIN_PROJET . '/includes/couvertures.php';

groupe('titre_normalise() — comparer deux titres');

test('la casse est ignorée', function () {
    egale(titre_normalise('BERSERK'), titre_normalise('berserk'), 'majuscules et minuscules');
    egale(titre_normalise('One Piece'), titre_normalise('ONE PIECE'), 'un titre de deux mots');
});

test('les accents sont ignorés', function () {
    /* Un utilisateur tape « Detective Conan » sans accent, la source
       renvoie « Détective Conan » : sans cette normalisation, le titre
       exact ne serait pas reconnu et remonterait derrière des homonymes. */
    egale(titre_normalise('Détective Conan'), titre_normalise('Detective Conan'), 'é et e');
    egale(titre_normalise('Hôtel'), titre_normalise('Hotel'), 'ô et o');
    egale(titre_normalise('Ça'), titre_normalise('Ca'), 'ç et c');
});

test('les ligatures sont développées', function () {
    egale(titre_normalise('Cœur'), titre_normalise('Coeur'), 'œ devient oe');
    egale(titre_normalise('Sœur'), titre_normalise('soeur'), 'avec la casse en plus');
});

test('la ponctuation devient un simple espace', function () {
    egale(titre_normalise('Fruits Basket'), titre_normalise('fruits-basket'), 'un tiret');
    egale(titre_normalise('Dr Stone'), titre_normalise('Dr. Stone'), 'un point');
    egale(titre_normalise('Jojo s Bizarre'), titre_normalise("Jojo's Bizarre"), 'une apostrophe');
});

test('les espaces en trop ne comptent pas', function () {
    egale(titre_normalise('One Piece'), titre_normalise('  One   Piece  '), 'espaces multiples et bords');
});

test('les chiffres sont conservés', function () {
    /* Ils distinguent des séries entières : « 20th Century Boys » n est
       pas « Century Boys », et « 7 Seeds » n est pas « Seeds ». */
    contient('20', titre_normalise('20th Century Boys'), 'le nombre reste');
    contient('7', titre_normalise('7 Seeds'), 'un chiffre isolé reste');
});

test('deux titres réellement différents restent différents', function () {
    /* Le risque de toute normalisation est d aplatir au point de
       confondre. Ces deux-là doivent continuer de se distinguer. */
    differe(titre_normalise('Berserk'), titre_normalise('Berserker'), 'un suffixe change le titre');
    differe(titre_normalise('Vinland Saga'), titre_normalise('Animalland Saga'), 'deux sagas distinctes');
});

test('une chaîne vide ne provoque rien', function () {
    egale('', titre_normalise(''), 'chaîne vide');
    egale('', titre_normalise('   '), 'que des espaces');
    egale('', titre_normalise('!?-.'), 'que de la ponctuation');
});

groupe('mangadex_titre() — choisir la langue du titre');

test('le français est préféré à tout le reste', function () {
    egale('Le Voyageur', mangadex_titre(['title' => [
        'ja' => 'Tabibito', 'en' => 'The Traveller', 'fr' => 'Le Voyageur',
    ]]), 'fr passe devant en et ja');
});

test('à défaut de français, l anglais', function () {
    egale('The Traveller', mangadex_titre(['title' => [
        'ja' => 'Tabibito', 'en' => 'The Traveller',
    ]]), 'en quand fr manque');
});

test('à défaut, le japonais translittéré avant le japonais', function () {
    /* « ja-ro » est le titre en caractères latins : lisible par un
       francophone, contrairement aux idéogrammes. */
    egale('Tabibito', mangadex_titre(['title' => [
        'ja' => '旅人', 'ja-ro' => 'Tabibito',
    ]]), 'ja-ro passe devant ja');
});

test('une langue inattendue est utilisée en dernier recours', function () {
    /* MangaDex ne garantit aucune des langues attendues. Plutôt que de
       n afficher aucun titre, on prend ce qui existe. */
    egale('El Viajero', mangadex_titre(['title' => ['es' => 'El Viajero']]),
        'une langue hors de la liste de préférence');
});

test('aucun titre ne fait pas échouer', function () {
    egale('', mangadex_titre(['title' => []]), 'tableau de titres vide');
    egale('', mangadex_titre([]), 'pas de clé « title » du tout');
});

test('un titre vide est ignoré au profit du suivant', function () {
    /* La source renvoie parfois une clé présente mais vide. La traiter
       comme un titre afficherait une vignette anonyme. */
    egale('The Traveller', mangadex_titre(['title' => [
        'fr' => '', 'en' => 'The Traveller',
    ]]), 'fr vide, on passe à en');
});
