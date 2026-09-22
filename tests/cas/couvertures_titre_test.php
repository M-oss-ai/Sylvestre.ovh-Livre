<?php
/* =====================================================================
   includes/couvertures.php — les fonctions qui ne parlent pas au
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

groupe('couverture_quota() — le barème par compte');

test('le forfait illimité a droit à davantage', function () {
    vrai(couverture_quota(['forfait' => 'illimite']) > couverture_quota(['forfait' => 'standard']),
        'plus haut que le forfait ordinaire');
    egale(COUVERTURE_QUOTA_ILLIMITE, couverture_quota(['forfait' => 'illimite']),
        'exactement le réglage prévu pour lui');
});

test('« illimité » ne veut pas dire sans plafond', function () {
    /* Sans aucun plafond, une page laissée à boucler occuperait la file
       toute la journée et en priverait les autres comptes. Le forfait
       donne droit à plus, pas à tout. */
    vrai(couverture_quota(['forfait' => 'illimite']) > 0, 'un nombre fini');
    vrai(is_int(couverture_quota(['forfait' => 'illimite'])), 'un entier de recherches');
});

test('les autres forfaits reçoivent le quota ordinaire', function () {
    egale(COUVERTURE_QUOTA, couverture_quota(['forfait' => 'standard']), 'forfait standard');
    egale(COUVERTURE_QUOTA, couverture_quota(['forfait' => 'gratuit']), 'forfait quelconque');
});

test('un forfait absent reçoit le quota ordinaire, pas le plus généreux', function () {
    /* Absence de donnée = le barème le plus restrictif, jamais
       l inverse : un défaut permissif accorderait silencieusement le
       plafond haut à n importe quel tableau incomplet. */
    egale(COUVERTURE_QUOTA, couverture_quota([]), 'clé « forfait » absente');
});

groupe('couverture_tranche_lisible() — énoncer la règle');

test('les minutes rondes s écrivent en minutes', function () {
    /* « 30 recherches par 2 minutes » se vérifie de tête ; « par 120
       secondes » demande une division avant de savoir si c est
       raisonnable. */
    egale('2 minutes', couverture_tranche_lisible(120), 'le réglage par défaut');
    egale('1 minute', couverture_tranche_lisible(60), 'au singulier');
    egale('5 minutes', couverture_tranche_lisible(300), 'une tranche plus longue');
});

test('ce qui ne tombe pas juste reste en secondes', function () {
    egale('90 secondes', couverture_tranche_lisible(90), 'pas un compte rond de minutes');
    egale('45 secondes', couverture_tranche_lisible(45), 'moins d une minute');
    egale('1 seconde', couverture_tranche_lisible(1), 'au singulier');
});

groupe('couverture_titres_connus() — toutes les écritures d une série');

test('le titre principal et ses traductions sont rassemblés', function () {
    $t = couverture_titres_connus(['title' => ['en' => 'Attack on Titan', 'ja' => '進撃の巨人']]);
    contient('Attack on Titan', implode('|', $t), 'le titre anglais');
    contient('進撃の巨人', implode('|', $t), 'le titre japonais');
});

test('les titres alternatifs comptent autant que les autres', function () {
    /* C est là que vit « Shingeki no Kyojin » : MangaDex affiche la
       série sous « Attack on Titan », et l utilisateur tape l autre. */
    $t = couverture_titres_connus([
        'title'     => ['en' => 'Attack on Titan'],
        'altTitles' => [['ja-ro' => 'Shingeki no Kyojin'], ['fr' => "L Attaque des Titans"]],
    ]);
    contient('Shingeki no Kyojin', implode('|', $t), 'le titre translittéré');
    contient('Attaque des Titans', implode('|', $t), 'le titre français');
});

test('les entrées vides ou mal formées sont ignorées', function () {
    /* La source n est pas garantie : une clé présente mais vide, ou une
       entrée qui n est pas un tableau, ne doit pas faire échouer le
       classement de toute la recherche. */
    $t = couverture_titres_connus([
        'title'     => ['en' => 'Vrai', 'fr' => '', 'de' => '   '],
        'altTitles' => [['ja' => 'Autre'], 'pas un tableau', []],
    ]);
    egale(2, count($t), 'seuls les deux titres réels sont retenus');
});

test('un manga sans aucun titre ne provoque rien', function () {
    egale([], couverture_titres_connus([]), 'aucune clé');
    egale([], couverture_titres_connus(['title' => [], 'altTitles' => []]), 'clés vides');
});

groupe('couverture_ecart_titre() — ce qui décide du classement');

test('un titre exact vaut zéro', function () {
    egale(0, couverture_ecart_titre(['title' => ['en' => 'Berserk']], titre_normalise('Berserk')),
        'le titre affiché');
});

test('un titre exact trouvé dans les alternatifs vaut zéro aussi', function () {
    /* LE cas qui motive tout ceci. Sans lui, chercher « Shingeki no
       Kyojin » ne reconnaissait pas « Attack on Titan » et la vraie
       série tombait derrière n importe quel homonyme. */
    egale(0, couverture_ecart_titre([
        'title'     => ['en' => 'Attack on Titan'],
        'altTitles' => [['ja-ro' => 'Shingeki no Kyojin']],
    ], titre_normalise('Shingeki no Kyojin')), 'reconnu par son titre alternatif');
});

test('la casse et les accents ne changent rien', function () {
    egale(0, couverture_ecart_titre(['title' => ['fr' => 'Détective Conan']],
        titre_normalise('detective conan')), 'normalisation appliquée des deux côtés');
});

test('un titre qui contient la recherche vaut le palier intermédiaire', function () {
    /* Il doit passer devant une série sans rapport, et rester derrière
       le titre exact. */
    $e = couverture_ecart_titre(['title' => ['ja-ro' => 'Ayanashi no Kimi']], titre_normalise('Ayanashi'));
    egale(4, $e, 'contient la recherche');
    vrai($e > 0 && $e < 10, 'entre l exact et le hors-sujet');
});

test('une recherche plus longue que le titre compte aussi', function () {
    egale(4, couverture_ecart_titre(['title' => ['en' => 'Berserk']],
        titre_normalise('Berserk edition couleur')), 'la recherche contient le titre');
});

test('une série sans rapport vaut dix', function () {
    egale(10, couverture_ecart_titre(['title' => ['en' => 'One Piece']], titre_normalise('Berserk')),
        'rien en commun');
});

test('un fragment trop court ne rapproche de rien', function () {
    /* Sans ce plancher, une série dont un titre alternatif est « Aya »
       serait « proche » de toute recherche contenant ces trois lettres,
       et remonterait devant des séries réellement pertinentes. */
    egale(10, couverture_ecart_titre(['title' => ['ja' => 'Aya']], titre_normalise('Ayanashi')),
        'trois caractères ne suffisent pas');
    egale(4, couverture_ecart_titre(['title' => ['ja' => 'Ayan']], titre_normalise('Ayanashi')),
        'quatre caractères suffisent');
});

test('une recherche vide ne rapproche de rien', function () {
    /* Un titre en japonais se normalise en chaîne vide : sans cette
       garde, toute série deviendrait un résultat exact. */
    egale(10, couverture_ecart_titre(['title' => ['en' => 'Berserk']], ''), 'recherche vide');
    egale(10, couverture_ecart_titre(['title' => ['ja' => 'アヤナシ']], titre_normalise('Ayanashi')),
        'un titre non latin ne correspond à rien après normalisation');
});

test('le meilleur titre l emporte, pas le premier', function () {
    /* Un exact trouvé en dernière position doit primer sur un simple
       rapprochement trouvé en première. */
    egale(0, couverture_ecart_titre([
        'title'     => ['en' => 'Ayanashi no Kimi'],
        'altTitles' => [['ja-ro' => 'Ayanashi']],
    ], titre_normalise('Ayanashi')), 'l exact trouvé après le partiel');
});