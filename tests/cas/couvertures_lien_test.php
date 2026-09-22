<?php
/* =====================================================================
   Le lien entre une série de la bibliothèque et sa série chez MangaDex.

   Sans lui, retrouver la couverture du tome suivant obligeait à
   relancer une recherche complète et à redeviner quelle série était la
   bonne : une vingtaine de requêtes, à chaque tome, avec le risque de
   se tromper de série à chaque fois. Avec lui, une seule requête, et
   elle ne se trompe pas.

   Deux décisions portent tout le dispositif, et ce sont les deux qui
   se testent sans réseau : d'où vient le lien, et quel tome illustrer.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

require_once CHEMIN_PROJET . '/includes/couvertures.php';

groupe('mangadex_id_depuis_url() — le lien se lit dans l image');

test('une URL de couverture MangaDex livre son identifiant', function () {
    egale(
        '801513ba-a712-498c-8f57-cae55b38cc92',
        mangadex_id_depuis_url(
            'https://uploads.mangadex.org/covers/801513ba-a712-498c-8f57-cae55b38cc92/abc.jpg.512.jpg'
        ),
        'identifiant extrait'
    );
});

test('la casse de l identifiant est normalisée', function () {
    /* Comparé à ce qui est en base, et stocké tel quel : deux écritures
       du même identifiant doivent donner la même chaîne, sans quoi une
       série paraîtrait déliée alors qu elle ne l est pas. */
    egale(
        '801513ba-a712-498c-8f57-cae55b38cc92',
        mangadex_id_depuis_url(
            'https://uploads.mangadex.org/covers/801513BA-A712-498C-8F57-CAE55B38CC92/x.jpg'
        ),
        'ramené en minuscules'
    );
});

test('une image envoyée par l utilisateur ne donne aucun lien', function () {
    /* C est LA règle voulue : choisir son image coupe le lien, sans
       qu on ait à y penser et sans code dédié. Le lien se déduisant de
       l image, les deux ne peuvent pas se contredire. */
    egale('', mangadex_id_depuis_url('uploads/a1b2c3d4.webp'), 'fichier local');
});

test('une URL d ailleurs ne donne aucun lien', function () {
    egale('', mangadex_id_depuis_url('https://exemple.test/couverture.jpg'), 'autre site');
    egale('', mangadex_id_depuis_url(''), 'chaîne vide');
    egale('', mangadex_id_depuis_url('pas une url du tout'), 'texte quelconque');
});

test('un hôte qui ressemble à MangaDex est refusé', function () {
    /* « uploads.mangadex.org.attaquant.test » contient bien le nom
       attendu. Sans ancrage strict du motif, il passerait — et le site
       irait chercher ses images suivantes chez l attaquant. */
    egale('', mangadex_id_depuis_url(
        'https://uploads.mangadex.org.attaquant.test/covers/'
        . '801513ba-a712-498c-8f57-cae55b38cc92/x.jpg'
    ), 'sous-domaine suffixé');

    egale('', mangadex_id_depuis_url(
        'https://attaquant.test/uploads.mangadex.org/covers/'
        . '801513ba-a712-498c-8f57-cae55b38cc92/x.jpg'
    ), 'le nom placé dans le chemin');
});

test('le HTTP simple est refusé', function () {
    /* Le site est servi en HTTPS et sa Content-Security-Policy le
       serait de toute façon : une image en clair ne s afficherait pas,
       autant ne pas enregistrer le lien qui va avec. */
    egale('', mangadex_id_depuis_url(
        'http://uploads.mangadex.org/covers/801513ba-a712-498c-8f57-cae55b38cc92/x.jpg'
    ), 'http au lieu de https');
});

test('un identifiant mal formé est refusé', function () {
    /* Il part dans une requête vers MangaDex : sa forme doit être
       vérifiée ici, pas espérée plus loin. */
    egale('', mangadex_id_depuis_url(
        'https://uploads.mangadex.org/covers/pas-un-uuid/x.jpg'
    ), 'texte à la place de l identifiant');

    egale('', mangadex_id_depuis_url(
        'https://uploads.mangadex.org/covers/801513ba-a712-498c-8f57/x.jpg'
    ), 'identifiant tronqué');

    egale('', mangadex_id_depuis_url(
        'https://uploads.mangadex.org/covers/../../etc/passwd/x.jpg'
    ), 'tentative de remontée de chemin');
});

groupe('couverture_tome_vise() — quel tome illustrer');

test('une série en cours regarde en avant', function () {
    egale(5, couverture_tome_vise('cours', 4), 'le prochain à emprunter');
    egale(1, couverture_tome_vise('cours', 0), 'jamais commencée');
});

test('une série à commencer regarde le premier tome', function () {
    egale(1, couverture_tome_vise('envie', 0), 'le tome 1');
});

test('une série terminée montre le tome qu on en a', function () {
    /* Elle ne sera plus empruntée plus loin : annoncer le tome suivant
       n aurait aucun sens, et la couverture affichée serait celle d un
       tome qu on ne lira pas. */
    egale(4, couverture_tome_vise('termine', 4), 'le tome réel');
    egale(7, couverture_tome_vise('abandon', 7), 'idem pour une abandonnée');
});

test('le tome visé ne descend jamais sous 1', function () {
    /* Le tome 0 n existe chez personne, et le demander ne renverrait
       jamais rien. */
    egale(1, couverture_tome_vise('termine', 0), 'terminée à zéro');
    egale(1, couverture_tome_vise('abandon', 0), 'abandonnée à zéro');
    egale(1, couverture_tome_vise('cours', -5), 'valeur aberrante');
});

test('un statut inconnu regarde en avant', function () {
    /* Le défaut du projet est « en cours » : une série dont le statut
       serait illisible doit se comporter comme celles qu on continue,
       jamais comme une série close. */
    egale(3, couverture_tome_vise('', 2), 'statut vide');
    egale(3, couverture_tome_vise('inconnu', 2), 'statut inventé');
});
