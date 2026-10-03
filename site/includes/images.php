<?php
/* =====================================================================
   Traitement des images envoyées : validation, redimension, ré-encodage.

   Pourquoi ce fichier existe : une couverture de manga s'affiche dans une
   carte de 210 à 280 px de large. Stocker et servir le fichier d'origine
   (jusqu'à 3 Mo, souvent 1500 px de large) fait payer à chaque visiteur
   une dizaine de fois le poids utile. On ramène donc tout à 600 px de
   large maximum — de quoi rester net sur un écran Retina — et on
   ré-encode en WebP, 25 à 35 % plus léger que JPEG à qualité égale.
   ===================================================================== */

declare(strict_types=1);

const IMAGE_TYPES = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_GIF  => 'gif',
    IMAGETYPE_WEBP => 'webp',
];

/* IMAGE_TAILLE_MAX, IMAGE_COTE_MAX, IMAGE_QUALITE et IMAGE_PIXELS_MAX
   sont définies dans config.php, à partir du .env : tout ce qui se règle
   se règle là-bas, jamais ici. */

/** GD est-il disponible avec de quoi lire et écrire des images ? */
function gd_disponible(): bool
{
    return extension_loaded('gd') && function_exists('imagecreatefromstring');
}

/** GD sait-il produire du WebP sur cet hébergement ? */
function gd_webp_disponible(): bool
{
    return gd_disponible() && function_exists('imagewebp');
}

/**
 * Un GIF animé contient plusieurs blocs de contrôle graphique. GD ne sait
 * traiter que la première image : redimensionner un GIF animé le
 * transformerait en image fixe. On préfère le laisser intact.
 */
function gif_anime(string $brut): bool
{
    return substr_count($brut, "\x00\x21\xF9\x04") > 1;
}

/**
 * Applique la rotation décrite par l'EXIF. Les téléphones n'écrivent pas
 * l'image tournée : ils la stockent telle que prise et ajoutent une
 * étiquette « à afficher pivotée de 90° ». Les navigateurs la respectent,
 * GD l'ignore — sans cette correction, toutes les photos prises en
 * portrait se retrouveraient couchées après traitement.
 */
function corriger_orientation(GdImage $image, string $brut): GdImage
{
    if (!function_exists('exif_read_data')) {
        return $image;
    }
    // exif_read_data() ne lit qu'un flux ou un fichier : on lui donne les
    // octets déjà en mémoire, sans repasser par le disque.
    $flux = @fopen('php://memory', 'r+');
    if ($flux === false) {
        return $image;
    }
    fwrite($flux, $brut);
    rewind($flux);
    $exif = @exif_read_data($flux);
    fclose($flux);

    $orientation = (int) ($exif['Orientation'] ?? 0);
    $rotation = match ($orientation) {
        3 => 180,
        6 => -90,
        8 => 90,
        default => 0,
    };
    if ($rotation === 0) {
        return $image;
    }
    $tourne = @imagerotate($image, (float) $rotation, 0);
    if ($tourne === false) {
        return $image;
    }
    imagedestroy($image);
    return $tourne;
}

/**
 * Valide, redimensionne et ré-encode une image reçue.
 *
 * Retourne ['contenu' => octets, 'ext' => 'webp'] ou null si le fichier
 * n'est pas une image exploitable ($erreur est alors renseigné).
 *
 * Le type est déterminé par le CONTENU (getimagesizefromstring), jamais
 * par le nom du fichier ni par le Content-Type annoncé par le navigateur :
 * un script PHP renommé en .jpg est rejeté ici.
 */
function traiter_image(string $brut, ?string &$erreur = null): ?array
{
    if ($brut === '') {
        $erreur = 'Fichier vide.';
        return null;
    }
    if (strlen($brut) > IMAGE_TAILLE_MAX) {
        $erreur = 'Image trop lourde (' . taille_lisible(IMAGE_TAILLE_MAX) . ' maximum).';
        return null;
    }

    $info = @getimagesizefromstring($brut);
    if (!$info || !isset(IMAGE_TYPES[$info[2]])) {
        $erreur = "Ce fichier n'est pas une image valide (JPG, PNG, GIF ou WEBP).";
        return null;
    }

    [$largeur, $hauteur] = [(int) $info[0], (int) $info[1]];
    if ($largeur < 1 || $hauteur < 1) {
        $erreur = "Ce fichier n'est pas une image valide.";
        return null;
    }
    if ($largeur * $hauteur > IMAGE_PIXELS_MAX) {
        /* « 12 millions de pixels » ne dit rien à personne : on traduit en
           dimensions, la seule chose que l'utilisateur voit dans sa galerie
           et sur laquelle il peut agir. Calculé en 4:3, le format d'un
           appareil photo. */
        $cote_large = (int) round(sqrt(IMAGE_PIXELS_MAX * 4 / 3));
        $erreur = 'Image trop grande : ' . (int) round(IMAGE_PIXELS_MAX / 1000000)
                . ' millions de pixels maximum, soit environ ' . $cote_large
                . ' × ' . (int) round($cote_large * 3 / 4) . ' pixels. '
                . 'Réduisez ses dimensions, ou reprenez la photo en résolution standard.';
        return null;
    }

    $ext_origine = IMAGE_TYPES[$info[2]];

    // Cas où l'on conserve le fichier tel quel : GD absent (l'appli doit
    // rester utilisable), ou GIF animé (GD l'aplatirait).
    if (!gd_disponible() || ($info[2] === IMAGETYPE_GIF && gif_anime($brut))) {
        return ['contenu' => $brut, 'ext' => $ext_origine];
    }

    $image = @imagecreatefromstring($brut);
    if ($image === false) {
        $erreur = "Cette image n'a pas pu être lue.";
        return null;
    }

    if ($info[2] === IMAGETYPE_JPEG) {
        $image = corriger_orientation($image, $brut);
        // La rotation a pu échanger largeur et hauteur.
        $largeur = imagesx($image);
        $hauteur = imagesy($image);
    }

    // Redimension proportionnelle, jamais d'agrandissement : une petite
    // image reste petite plutôt que de devenir floue et plus lourde.
    $cote = max($largeur, $hauteur);
    if ($cote > IMAGE_COTE_MAX) {
        $ratio = IMAGE_COTE_MAX / $cote;
        $nouvelle = @imagescale(
            $image,
            max(1, (int) round($largeur * $ratio)),
            max(1, (int) round($hauteur * $ratio)),
            IMG_BICUBIC
        );
        if ($nouvelle !== false) {
            imagedestroy($image);
            $image = $nouvelle;
        }
    }

    // La transparence doit survivre au ré-encodage (logos, avatars PNG).
    $transparent = in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
    if ($transparent) {
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }

    ob_start();
    if (gd_webp_disponible()) {
        $ok  = imagewebp($image, null, IMAGE_QUALITE);
        $ext = 'webp';
    } elseif ($transparent) {
        // Sans WebP, seul PNG conserve l'alpha. Compression 6 : bon
        // compromis entre temps de calcul et poids.
        $ok  = imagepng($image, null, 6);
        $ext = 'png';
    } else {
        $ok  = imagejpeg($image, null, IMAGE_QUALITE);
        $ext = 'jpg';
    }
    $sortie = (string) ob_get_clean();
    imagedestroy($image);

    if (!$ok || $sortie === '') {
        // Le ré-encodage a échoué : mieux vaut stocker l'original que
        // refuser l'envoi de l'utilisateur.
        return ['contenu' => $brut, 'ext' => $ext_origine];
    }

    // Une image déjà petite et bien compressée peut grossir au
    // ré-encodage : dans ce cas on garde l'original.
    if (strlen($sortie) >= strlen($brut) && $cote <= IMAGE_COTE_MAX) {
        return ['contenu' => $brut, 'ext' => $ext_origine];
    }

    return ['contenu' => $sortie, 'ext' => $ext];
}

/**
 * Écrit dans uploads/ des octets d'image déjà validés par traiter_image().
 * Le nom est l'empreinte sha256 du contenu FINAL : deux envois de la même
 * image (par le même compte ou par un autre) partagent le même fichier au
 * lieu d'être dupliqués sur le disque.
 *
 * L'empreinte est salée (UPLOAD_SECRET) : sans sel, quiconque possède la
 * même image devine son URL et vérifie qu'un utilisateur la possède
 * aussi. Un secret vide revient au comportement d'avant, pour ne pas
 * casser une installation déjà en service.
 */
function ecrire_image(string $contenu, string $ext, ?string &$erreur = null): ?string
{
    $dossier = CHEMIN_RACINE . '/uploads';
    if (!is_dir($dossier) && !@mkdir($dossier, 0775, true)) {
        $erreur = "Le dossier uploads/ n'a pas pu être créé.";
        return null;
    }

    $nom    = hash('sha256', UPLOAD_SECRET . $contenu) . '.' . $ext;
    $chemin = 'uploads/' . $nom;
    $absolu = $dossier . '/' . $nom;

    if (is_file($absolu)) {
        return $chemin; // même contenu déjà présent : on le réutilise
    }

    /* Écriture atomique : fichier temporaire puis renommage, sinon deux
       envois simultanés peuvent servir un fichier à moitié écrit.
       Le nom « .part-xxxx.tmp » tombe sous les extensions refusées par le
       .htaccess de uploads/ ; l'ancienne forme n'y correspondait pas, et
       un résidu de renommage raté était servi en clair. */
    $temporaire = $dossier . '/.part-' . bin2hex(random_bytes(8)) . '.tmp';
    if (@file_put_contents($temporaire, $contenu, LOCK_EX) === false
        || !@rename($temporaire, $absolu)) {
        @unlink($temporaire);
        $erreur = "L'image n'a pas pu être enregistrée.";
        return null;
    }
    @chmod($absolu, 0644);

    return $chemin;
}
