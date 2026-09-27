<?php
/* =====================================================================
   import_serie() et import_complement() — l'import d'une sauvegarde,
   sans la base.

   Réimporter la même sauvegarde créait 150 doublons, et un aller-retour
   export / import perdait les favoris et le lien MangaDex. La série
   déjà présente se reconnaît à son titre (c'est la collation de la
   colonne qui compare, hors de portée ici) ; ce qui est testé, c'est ce
   qu'on lit dans le fichier, et ce qu'on rend à une série déjà là.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('import_serie() — une ligne de la sauvegarde');

test('une série complète est reprise telle quelle', function () {
    $s = import_serie([
        'titre' => 'One Piece', 'auteur' => 'Eiichiro Oda', 'tome_actuel' => 12,
        'statut' => 'termine', 'favori' => 1,
        'couverture' => 'https://uploads.mangadex.org/covers/abc/def.jpg',
    ]);
    egale('One Piece', $s['titre'], 'le titre');
    egale('Eiichiro Oda', $s['auteur'], 'l auteur');
    egale(12, $s['tome'], 'le tome');
    egale('termine', $s['statut'], 'le statut');
    egale(1, $s['favori'], 'le favori — perdu par l ancien import');
    egale('https://uploads.mangadex.org/covers/abc/def.jpg', $s['couverture'], 'l adresse de l image');
    egale('', $s['donnees'], 'pas d image jointe');
});

test('ce qui n est pas une série est écarté', function () {
    estNul(import_serie('One Piece'), 'une chaîne');
    estNul(import_serie(null), 'null');
    estNul(import_serie(42), 'un nombre');
});

test('une série sans titre est écartée', function () {
    estNul(import_serie(['auteur' => 'Quelqu un']), 'aucun titre');
    estNul(import_serie(['titre' => '   ']), 'un titre fait d espaces');
    estNul(import_serie(['titre' => ['One Piece']]), 'un titre qui n est pas du texte');
});

test('l ancien format est compris', function () {
    $s = import_serie([
        'title' => 'Berserk', 'subtitle' => 'Kentaro Miura', 'volume' => 7, 'status' => 'envie',
        'cover' => ['type' => 'url', 'value' => 'https://exemple.test/berserk.jpg'],
    ]);
    egale('Berserk', $s['titre'], 'title');
    egale('Kentaro Miura', $s['auteur'], 'subtitle');
    egale(7, $s['tome'], 'volume');
    egale('envie', $s['statut'], 'status');
    egale('https://exemple.test/berserk.jpg', $s['couverture'], 'cover.value');
});

test('un statut inconnu devient « en cours »', function () {
    egale('cours', import_serie(['titre' => 'X', 'statut' => 'pirate'])['statut'], 'valeur inconnue');
    egale('cours', import_serie(['titre' => 'X'])['statut'], 'statut absent');
    egale('cours', import_serie(['titre' => 'X', 'statut' => ['termine']])['statut'], 'un tableau');
});

test('le tome est borné', function () {
    egale(0, import_serie(['titre' => 'X', 'tome_actuel' => -3])['tome'], 'pas de tome négatif');
    egale(TOME_MAX, import_serie(['titre' => 'X', 'tome_actuel' => TOME_MAX + 50])['tome'], 'plafonné à TOME_MAX');
    egale(0, import_serie(['titre' => 'X', 'tome_actuel' => 'douze'])['tome'], 'du texte vaut 0');
    egale(4, import_serie(['titre' => 'X', 'tome_actuel' => '4'])['tome'], 'un nombre écrit en texte est compris');
});

test('le favori ne vaut 1 que s il est posé', function () {
    egale(0, import_serie(['titre' => 'X'])['favori'], 'absent');
    egale(0, import_serie(['titre' => 'X', 'favori' => 0])['favori'], 'zéro');
    egale(1, import_serie(['titre' => 'X', 'favori' => true])['favori'], 'vrai');
});

test('le titre et l auteur passent par texte()', function () {
    // Une ligne, pas de caractère de contrôle, 190 caractères au plus.
    $s = import_serie(['titre' => "Un\ttitre\nsur deux lignes", 'auteur' => str_repeat('a', 300)]);
    sans("\n", $s['titre'], 'pas de retour à la ligne dans un titre');
    egale(190, mb_strlen($s['auteur']), 'l auteur est tronqué');
});

groupe('import_serie() — l image');

test('une adresse en http:// est écartée', function () {
    egale('', import_serie(['titre' => 'X', 'couverture' => 'http://exemple.test/a.jpg'])['couverture'], 'http');
});

test('un chemin local venu d un autre serveur est écarté', function () {
    /* « uploads/… » ne pointe vers rien ici sans l'image elle-même : on
       l'ignore plutôt que d'afficher une image cassée. */
    egale('', import_serie(['titre' => 'X', 'couverture' => 'uploads/abc.webp'])['couverture'], 'chemin local');
});

test('les schémas dangereux sont écartés', function () {
    egale('', import_serie(['titre' => 'X', 'couverture' => 'javascript:alert(1)'])['couverture'], 'javascript:');
    egale('', import_serie(['titre' => 'X', 'cover' => ['value' => 'data:image/png;base64,AAAA']])['couverture'], 'data:');
});

test('une image jointe en base64 est transmise telle quelle', function () {
    // Elle sera recréée sur disque par api.php (enregistrer_image_depuis_donnees).
    $s = import_serie(['titre' => 'X', 'couverture' => 'uploads/abc.webp', 'couverture_donnees' => 'data:image/webp;base64,UklGRg==']);
    egale('data:image/webp;base64,UklGRg==', $s['donnees'], 'les données de l image');
    egale('', $s['couverture'], 'et pas le chemin d origine');
});

test('des données d image qui ne sont pas du texte sont ignorées', function () {
    egale('', import_serie(['titre' => 'X', 'couverture_donnees' => ['x']])['donnees'], 'un tableau');
});

groupe('import_complement() — une série déjà dans la bibliothèque');

test('rien à compléter : rien ne change', function () {
    estNul(import_complement('https://exemple.test/a.jpg', 1, '', 1), 'image et étoile déjà là');
    estNul(import_complement('', 0, '', 0), 'la sauvegarde n apporte rien non plus');
});

test('une série sans image reçoit celle de la sauvegarde', function () {
    egale(['couverture' => 'https://exemple.test/b.jpg', 'favori' => 0],
        import_complement('', 0, 'https://exemple.test/b.jpg', 0), 'l image manquante');
});

test('l image en place n est jamais remplacée', function () {
    /* Elle a peut-être été choisie après la sauvegarde. D'ailleurs
       l'appelant ne calcule même pas celle de la sauvegarde dans ce cas :
       la recréer écrirait un fichier pour rien. */
    estNul(import_complement('https://exemple.test/a.jpg', 0, 'https://exemple.test/b.jpg', 0), 'image gardée');
});

test('l étoile de la sauvegarde est rendue', function () {
    egale(['couverture' => 'https://exemple.test/a.jpg', 'favori' => 1],
        import_complement('https://exemple.test/a.jpg', 0, '', 1), 'le favori revient');
});

test('une étoile posée depuis n est pas retirée', function () {
    estNul(import_complement('', 1, '', 0), 'la sauvegarde sans étoile ne l enlève pas');
});

test('image et étoile ensemble', function () {
    egale(['couverture' => 'https://exemple.test/b.jpg', 'favori' => 1],
        import_complement('', 0, 'https://exemple.test/b.jpg', 1), 'les deux manques comblés');
});
