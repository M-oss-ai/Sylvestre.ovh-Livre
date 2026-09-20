<?php
/* =====================================================================
   corriger_orientation() — la rotation décrite par l EXIF.

   Les téléphones n écrivent pas l image tournée : ils la stockent telle
   que prise et ajoutent une étiquette « à afficher pivotée de 90° ». Les
   navigateurs la respectent, GD l ignore — sans cette correction, toutes
   les photos prises en portrait se retrouvent couchées après traitement.

   Les JPEG de ce fichier sont fabriqués à la volée : un segment EXIF
   minimal est inséré derrière le marqueur de début d un JPEG produit par
   GD. Rien à stocker dans le dépôt, et le cas testé est exactement celui
   qui arrive d un téléphone.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

/**
 * Un JPEG de $largeur × $hauteur portant l étiquette d orientation
 * $orientation (1 = aucune, 3 = 180°, 6 = 90° horaire, 8 = 90° anti-horaire).
 */
function jpeg_oriente(int $orientation, int $largeur, int $hauteur): string
{
    $image = imagecreatetruecolor($largeur, $hauteur);
    imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();
    imagedestroy($image);

    /* Un bloc TIFF minimal : en-tête petit-boutien, un répertoire d une
       seule entrée — la balise 0x0112 « Orientation », de type SHORT. */
    $tiff = "II*\x00" . pack('V', 8)
          . pack('v', 1)
          . pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', $orientation) . "\x00\x00"
          . pack('V', 0);
    $app1 = "Exif\x00\x00" . $tiff;
    $segment = "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1;

    // Le segment se place juste après le marqueur de début (FFD8).
    return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
}

/** Dimensions de l image produite par traiter_image(). */
function dimensions_apres_traitement(string $brut): array
{
    $resultat = traiter_image($brut);
    if (!is_array($resultat)) {
        return [0, 0];
    }
    $info = getimagesizefromstring($resultat['contenu']);
    return [(int) $info[0], (int) $info[1]];
}

groupe('corriger_orientation() — le fichier de test est valide');

test('le JPEG fabriqué porte bien son étiquette EXIF', function () {
    /* Garde-fou : si l étiquette n était pas lisible, tous les tests
       ci-dessous passeraient pour la mauvaise raison — la rotation
       n aurait simplement jamais lieu. */
    if (!function_exists('exif_read_data')) {
        vrai(true, "l extension EXIF est absente de ce serveur : rien à vérifier");
        return;
    }
    $flux = fopen('php://memory', 'r+');
    fwrite($flux, jpeg_oriente(6, 400, 200));
    rewind($flux);
    $exif = @exif_read_data($flux);
    fclose($flux);

    egale(6, (int) ($exif['Orientation'] ?? 0), 'l étiquette vaut bien 6');
});

groupe('corriger_orientation() — les rotations appliquées');

test('une photo étiquetée 90° est redressée', function () {
    if (!function_exists('exif_read_data') || !gd_disponible()) {
        vrai(true, 'EXIF ou GD absent : la rotation ne peut pas être vérifiée');
        return;
    }
    /* 400 × 200 étiquetée « 6 » doit ressortir en 200 × 400 : c est le
       cas de la photo prise en tenant le téléphone à la verticale. */
    egale([200, 400], dimensions_apres_traitement(jpeg_oriente(6, 400, 200)),
        'largeur et hauteur ont été échangées');
});

test('une photo étiquetée 90° dans l autre sens est redressée aussi', function () {
    if (!function_exists('exif_read_data') || !gd_disponible()) {
        vrai(true, 'EXIF ou GD absent');
        return;
    }
    egale([200, 400], dimensions_apres_traitement(jpeg_oriente(8, 400, 200)),
        'l orientation 8 tourne dans l autre sens, mêmes dimensions finales');
});

test('une photo étiquetée 180° garde ses dimensions', function () {
    if (!function_exists('exif_read_data') || !gd_disponible()) {
        vrai(true, 'EXIF ou GD absent');
        return;
    }
    // Une rotation de 180° n échange pas les côtés : c est justement ce
    // qui distingue ce cas des deux précédents.
    egale([400, 200], dimensions_apres_traitement(jpeg_oriente(3, 400, 200)),
        'les dimensions sont inchangées');
});

test('une photo sans rotation à appliquer n est pas touchée', function () {
    egale([400, 200], dimensions_apres_traitement(jpeg_oriente(1, 400, 200)),
        'l orientation 1 ne déclenche rien');
});

test('une orientation inconnue ne fait rien plutôt que n importe quoi', function () {
    /* Les valeurs 2, 4, 5 et 7 décrivent des miroirs, que le projet ne
       traite pas : mieux vaut ne pas tourner que tourner au hasard. */
    egale([400, 200], dimensions_apres_traitement(jpeg_oriente(5, 400, 200)),
        'aucune rotation hasardeuse');
});

groupe('corriger_orientation() — la redimension vient après');

test('une photo redressée est ensuite ramenée au côté maximal', function () {
    if (!function_exists('exif_read_data') || !gd_disponible()) {
        vrai(true, 'EXIF ou GD absent');
        return;
    }
    /* L ordre compte : si la redimension avait lieu avant la rotation,
       le plus grand côté serait calculé sur les dimensions d origine et
       l image finale dépasserait la limite. */
    [$largeur, $hauteur] = dimensions_apres_traitement(jpeg_oriente(6, 1600, 800));
    egale(IMAGE_COTE_MAX, $hauteur, 'le plus grand côté APRÈS rotation est borné');
    egale((int) round(IMAGE_COTE_MAX / 2), $largeur, 'les proportions sont conservées');
});

groupe('traiter_image() — l EXIF disparaît du fichier stocké');

test('les métadonnées ne survivent pas au ré-encodage', function () {
    /* Effet de bord essentiel du ré-encodage : la position GPS d une
       photo prise au téléphone part avec le reste des métadonnées. Une
       couverture de manga n a aucune raison de dire où vit son
       propriétaire. */
    if (!gd_disponible()) {
        vrai(true, 'sans GD, le fichier est stocké tel quel : rien à vérifier');
        return;
    }
    $resultat = traiter_image(jpeg_oriente(6, 1600, 800));
    sans("Exif\x00\x00", $resultat['contenu'], 'aucun bloc EXIF dans le fichier produit');
});
