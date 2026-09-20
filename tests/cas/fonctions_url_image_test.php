<?php
/* =====================================================================
   url_image_sure() et photo_depuis_formulaire()

   url_image_sure() est ce qui empêche qu une « couverture » devienne un
   vecteur XSS : l URL saisie par l utilisateur finit dans un attribut
   src, et dans un attribut data- de la carte.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('url_image_sure() — ce qui est accepté');

test('un fichier de notre dossier uploads/ passe', function () {
    egale('uploads/abc123.webp', url_image_sure('uploads/abc123.webp'), 'un nom d empreinte');
    egale('uploads/a_b-c.jpg', url_image_sure('uploads/a_b-c.jpg'), 'tiret et souligné admis');
});

test('les quatre extensions d image sont admises', function () {
    foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
        egale("uploads/x.{$ext}", url_image_sure("uploads/x.{$ext}"), $ext . ' est accepté');
    }
});

test('la casse de l extension est indifférente', function () {
    // Les fichiers écrits par le projet sont en minuscules, mais une
    // sauvegarde importée peut contenir autre chose.
    egale('uploads/x.JPG', url_image_sure('uploads/x.JPG'), 'extension en majuscules');
});

test('une adresse https externe passe', function () {
    egale('https://exemple.test/couverture.png',
        url_image_sure('https://exemple.test/couverture.png'), 'un lien externe');
});

test('les espaces autour sont rognés', function () {
    egale('uploads/x.webp', url_image_sure('  uploads/x.webp  '), 'la valeur est trim()');
});

groupe('url_image_sure() — ce qui est refusé');

test('javascript: est refusé', function () {
    /* Le cas qui justifie toute la fonction : sans elle, cette valeur
       atterrirait dans un attribut de la carte. */
    egale('', url_image_sure('javascript:alert(1)'), 'un script déguisé en URL');
    egale('', url_image_sure('JaVaScRiPt:alert(1)'), 'la casse ne sauve pas');
});

test('data: et vbscript: sont refusés', function () {
    egale('', url_image_sure('data:text/html,<script>alert(1)</script>'), 'une page en data:');
    egale('', url_image_sure('vbscript:msgbox(1)'), 'vbscript');
});

test('http:// est refusé, et pas seulement par principe', function () {
    /* La Content-Security-Policy du site n autorise que « https: » pour
       les images. Une couverture en http:// était acceptée par le
       serveur puis bloquée par le navigateur : l utilisateur voyait une
       image cassée sans comprendre pourquoi. */
    egale('', url_image_sure('http://exemple.test/x.png'), 'du http en clair');
});

test('un protocole relatif est refusé', function () {
    // « //exemple.test/x.png » suit le protocole de la page : accepté, il
    // contournerait le refus du http ci-dessus.
    egale('', url_image_sure('//exemple.test/x.png'), 'sans protocole explicite');
});

test('une remontée de dossier est refusée', function () {
    egale('', url_image_sure('uploads/../includes/config.php'), 'un ../ dans le chemin');
    egale('', url_image_sure('uploads/sous/dossier.png'), 'un sous-dossier');
});

test('une extension non prévue dans uploads/ est refusée', function () {
    /* C est ce qui empêche de désigner un fichier déposé sous un autre
       nom — un script, une archive. */
    egale('', url_image_sure('uploads/x.php'), 'un script');
    egale('', url_image_sure('uploads/x.svg'), 'du SVG, qui peut contenir du script');
    egale('', url_image_sure('uploads/x'), 'aucune extension');
});

test('un chemin local hors uploads/ est refusé', function () {
    egale('', url_image_sure('includes/config.php'), 'un fichier du projet');
    egale('', url_image_sure('/etc/passwd'), 'un chemin absolu');
    egale('', url_image_sure('file:///c:/windows/win.ini'), 'le protocole file');
});

test('une chaîne vide reste vide', function () {
    egale('', url_image_sure(''), 'la chaîne vide');
    egale('', url_image_sure('   '), 'des espaces seulement');
    egale('', url_image_sure(null), 'null');
});

test('une URL https sans hôte est refusée', function () {
    egale('', url_image_sure('https://'), 'rien après le protocole');
    egale('', url_image_sure('https:///chemin'), 'hôte vide');
});

test('une URL démesurée est refusée', function () {
    /* Elle irait en base (VARCHAR) et dans le HTML de chaque carte. La
       limite ne s applique qu aux liens externes : un chemin uploads/
       est validé par sa forme, et il est court par construction. */
    egale('', url_image_sure('https://exemple.test/' . str_repeat('a', 600)), 'plus de 500 caractères');
});

groupe('photo_depuis_formulaire() — l ordre de priorité');

test('un fichier envoyé l emporte sur tout', function () {
    $_POST = ['photo_url' => 'https://exemple.test/autre.png', 'photo_retiree' => '1'];
    egale('uploads/neuf.webp', photo_depuis_formulaire('uploads/neuf.webp', 'uploads/vieux.webp'),
        'le fichier tout juste envoyé gagne');
    $_POST = [];
});

test('sans fichier, une URL saisie est retenue', function () {
    $_POST = ['photo_url' => 'https://exemple.test/couverture.png'];
    egale('https://exemple.test/couverture.png', photo_depuis_formulaire(null, 'uploads/vieux.webp'),
        'l URL saisie remplace l ancienne image');
    $_POST = [];
});

test('une URL saisie dangereuse est filtrée, pas retenue', function () {
    /* Le filtrage passe bien par url_image_sure() ici aussi : c est le
       chemin sans JavaScript (formulaire classique de parametres.php),
       celui qu on oublie le plus facilement. */
    $_POST = ['photo_url' => 'javascript:alert(1)'];
    egale('uploads/vieux.webp', photo_depuis_formulaire(null, 'uploads/vieux.webp'),
        'on garde l ancienne image plutôt que la charge utile');
    $_POST = [];
});

test('« image retirée » vide le champ', function () {
    $_POST = ['photo_retiree' => '1'];
    egale('', photo_depuis_formulaire(null, 'uploads/vieux.webp'), 'la photo est effacée');
    $_POST = [];
});

test('sans rien de neuf, l image actuelle est conservée', function () {
    $_POST = [];
    egale('uploads/vieux.webp', photo_depuis_formulaire(null, 'uploads/vieux.webp'),
        'un formulaire qui ne touche pas à la photo ne la perd pas');
});

test('une image actuelle devenue invalide est nettoyée au passage', function () {
    // Une valeur douteuse arrivée en base par un autre chemin ne ressort
    // pas telle quelle.
    $_POST = [];
    egale('', photo_depuis_formulaire(null, 'javascript:alert(1)'), 'la valeur est filtrée');
});
