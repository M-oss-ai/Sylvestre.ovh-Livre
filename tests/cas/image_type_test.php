<?php
/* =====================================================================
   type_image() — d'où vient une couverture.

   Sert au filtre avancé : « montre-moi les séries sans image », ou
   « celles dont j'ai posé le lien à la main ». La classification vit en
   PHP et voyage jusqu'au navigateur dans un attribut « data-image » :
   le JavaScript n'a pas à redécouvrir ce qu'une URL veut dire, et la
   règle ne peut pas diverger entre les deux.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('type_image() — les quatre provenances');

test('une couverture absente donne « aucune »', function () {
    egale('aucune', type_image(''), 'chaîne vide');
    egale('aucune', type_image('   '), 'que des espaces');
});

test('une couverture MangaDex est reconnue', function () {
    egale('mangadex', type_image(
        'https://uploads.mangadex.org/covers/801513ba-a712-498c-8f57-cae55b38cc92/x.jpg.512.jpg'
    ), 'l hôte d images de MangaDex');
});

test('un fichier envoyé donne « importee »', function () {
    egale('importee', type_image('uploads/a1b2c3d4e5f6.webp'), 'chemin local');
});

test('toute autre adresse donne « lien »', function () {
    egale('lien', type_image('https://exemple.test/couverture.jpg'), 'un autre site');
});

test('les quatre clés existent dans IMAGES_TYPES', function () {
    /* index.php construit les boutons du filtre en parcourant cette
       liste : une clé rendue par type_image() mais absente d IMAGES_TYPES
       serait un état impossible à filtrer, donc des séries invisibles
       quel que soit le filtre choisi. */
    egale(['mangadex', 'lien', 'importee', 'aucune'], array_keys(IMAGES_TYPES),
        'les clés attendues, dans l ordre d affichage');

    foreach (IMAGES_TYPES as $cle => $libelle) {
        differe('', $libelle, "le type « {$cle} » a un libellé");
    }
});

test('toute valeur possible de type_image() est filtrable', function () {
    /* Le contrat qui lie la fonction au formulaire. */
    foreach ([
        '',
        'uploads/x.webp',
        'https://uploads.mangadex.org/covers/801513ba-a712-498c-8f57-cae55b38cc92/x.jpg',
        'https://ailleurs.test/x.png',
    ] as $couverture) {
        vrai(isset(IMAGES_TYPES[type_image($couverture)]),
            'le type de « ' . ($couverture ?: '(vide)') . ' » a un bouton');
    }
});

test('un hôte qui ressemble à MangaDex compte comme un lien', function () {
    /* Pas un enjeu de sécurité ici — on classe, on n autorise rien —
       mais le ranger en « MangaDex » ferait croire que la série est liée
       et suivra ses tomes toute seule, ce qui serait faux. */
    egale('lien', type_image(
        'https://uploads.mangadex.org.attaquant.test/covers/'
        . '801513ba-a712-498c-8f57-cae55b38cc92/x.jpg'
    ), 'sous-domaine suffixé');
});

test('la casse de l adresse ne change pas le classement', function () {
    egale('mangadex', type_image(
        'HTTPS://UPLOADS.MANGADEX.ORG/covers/801513ba-a712-498c-8f57-cae55b38cc92/x.jpg'
    ), 'en majuscules');
    egale('importee', type_image('UPLOADS/photo.webp'), 'chemin en majuscules');
});

groupe('compter_lignes() — les nombres des pastilles');

test('chaque type d image a son compteur, à zéro pour une bibliothèque vide', function () {
    $c = compter_lignes([]);
    egale(0, $c['all'], 'aucune série');
    foreach (IMAGES_TYPES as $cle => $libelle) {
        egale(0, $c['image-' . $cle], "le compteur « {$cle} » existe");
    }
});

test('les séries se rangent par type d image, une seule fois chacune', function () {
    $serie = fn (string $couverture, string $statut = 'cours', int $favori = 0) =>
        ['statut' => $statut, 'favori' => $favori, 'couverture' => $couverture];

    $c = compter_lignes([
        $serie(''),
        $serie('   '),
        $serie('uploads/a.webp'),
        $serie('https://uploads.mangadex.org/covers/801513ba-a712-498c-8f57-cae55b38cc92/x.jpg'),
        $serie('https://uploads.mangadex.org/covers/801513ba-a712-498c-8f57-cae55b38cc92/y.jpg'),
        $serie('https://uploads.mangadex.org/covers/801513ba-a712-498c-8f57-cae55b38cc92/z.jpg'),
        $serie('https://ailleurs.test/x.png'),
        $serie('https://ailleurs.test/y.png'),
    ]);

    egale(2, $c['image-aucune'], 'sans image (vide et espaces)');
    egale(1, $c['image-importee'], 'importée');
    egale(3, $c['image-mangadex'], 'MangaDex');
    egale(2, $c['image-lien'], 'lien');
    egale(8, $c['all'], 'le total');
    egale($c['all'], $c['image-aucune'] + $c['image-importee'] + $c['image-mangadex'] + $c['image-lien'],
        'chaque série compte dans un seul type');
});

test('les statuts et les favoris se comptent comme avant', function () {
    $c = compter_lignes([
        ['statut' => 'cours',   'favori' => 1, 'couverture' => ''],
        ['statut' => 'cours',   'favori' => '0', 'couverture' => ''],
        ['statut' => 'envie',   'favori' => '1', 'couverture' => ''],
        ['statut' => 'termine', 'favori' => 0, 'couverture' => ''],
    ]);
    egale(4, $c['all'], 'tout');
    egale(2, $c['cours'], 'en cours');
    egale(1, $c['envie'], 'envie');
    egale(1, $c['termine'], 'terminée');
    egale(0, $c['abandon'], 'abandonnée');
    egale(2, $c['favori'], 'les favoris traversent les statuts');
});
