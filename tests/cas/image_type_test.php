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
    egale(['aucune', 'mangadex', 'importee', 'lien'], array_keys(IMAGES_TYPES),
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
