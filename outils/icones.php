<?php
/* =====================================================================
   Dessine les icônes de l'application (site/img/) avec GD.

       php outils/icones.php

   Les trois PNG sont au dépôt : ce script ne sert qu'à les refaire. Une pile
   de trois livres dorés sur le fond sombre du site, dans la zone centrale
   (les systèmes qui arrondissent ou masquent les icônes — Android, iOS —
   en rognent les bords).

     icone-512.png, icone-192.png : le manifeste (manifest.webmanifest)
     icone-apple.png (180 px)     : l'icône d'écran d'accueil d'un iPhone, sans
                                    laquelle « Ajouter à l'écran d'accueil »
                                    prend une capture de la page.
   ===================================================================== */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}
if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "L'extension GD de PHP est nécessaire.\n");
    exit(1);
}

$taille = 512;
$img = imagecreatetruecolor($taille, $taille);
imagealphablending($img, true);

$couleur = static fn ($im, string $hex) => imagecolorallocate($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
$fond  = $couleur($img, '17130f');   // --bg-0
$or    = $couleur($img, 'c9a87c');   // --gold
$clair = $couleur($img, 'f4e4d0');   // --gold-light
$terne = $couleur($img, 'a89584');   // --text-dim
$sombre = $couleur($img, '221b15');  // --bg-1

imagefilledrectangle($img, 0, 0, $taille, $taille, $fond);

/** Un livre vu de côté : un rectangle arrondi, sa tranche, deux filets. */
$livre = static function ($im, int $x, int $y, int $l, int $h, int $coul, int $filet) use ($sombre): void {
    $r = 14;
    imagefilledrectangle($im, $x + $r, $y, $x + $l - $r, $y + $h, $coul);
    imagefilledrectangle($im, $x, $y + $r, $x + $l, $y + $h - $r, $coul);
    foreach ([[$x + $r, $y + $r], [$x + $l - $r, $y + $r], [$x + $r, $y + $h - $r], [$x + $l - $r, $y + $h - $r]] as [$cx, $cy]) {
        imagefilledellipse($im, $cx, $cy, 2 * $r, 2 * $r, $coul);
    }
    // La tranche : deux filets verticaux près du bord gauche, comme sur un dos de livre relié.
    imagefilledrectangle($im, $x + 38, $y + 10, $x + 44, $y + $h - 10, $filet);
    imagefilledrectangle($im, $x + 56, $y + 10, $x + 60, $y + $h - 10, $filet);
};

// Trois livres empilés, décalés, centrés dans les 70 % du milieu.
$livre($img, 118, 136, 276, 78, $terne, $sombre);
$livre($img, 138, 226, 256, 78, $clair, $sombre);
$livre($img, 108, 316, 296, 78, $or, $sombre);

$dossier = dirname(__DIR__) . '/site/img';
if (!is_dir($dossier) && !mkdir($dossier, 0775, true)) {
    fwrite(STDERR, "Impossible de créer $dossier\n");
    exit(1);
}

foreach (['icone-512.png' => 512, 'icone-192.png' => 192, 'icone-apple.png' => 180] as $nom => $cote) {
    $copie = imagecreatetruecolor($cote, $cote);
    imagecopyresampled($copie, $img, 0, 0, 0, 0, $cote, $cote, $taille, $taille);
    imagepng($copie, $dossier . '/' . $nom, 9);
    echo "écrit : site/img/$nom ({$cote} px)\n";
}
