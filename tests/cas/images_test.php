<?php
/* =====================================================================
   includes/images.php — validation, redimension, ré-encodage.

   C est le point d entrée le plus exposé du site : un octet envoyé par
   un inconnu y est décodé. Deux protections comptent particulièrement :

     - le type est lu dans le CONTENU (getimagesizefromstring) et jamais
       dans le nom du fichier : un script PHP renommé en .jpg est refusé ;
     - les DIMENSIONS sont lues dans l en-tête, sans décompresser : un
       fichier de quelques centaines de Ko peut décrire une image de
       20 000 × 20 000 px et saturer la mémoire du processus.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

/** Fabrique un vrai PNG de $l × $h, en mémoire. */
function png_de(int $l, int $h): string
{
    $image = imagecreatetruecolor($l, $h);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
    ob_start();
    imagepng($image);
    $octets = (string) ob_get_clean();
    imagedestroy($image);
    return $octets;
}

/** Fabrique un vrai GIF de $l × $h, en mémoire. */
function gif_de(int $l, int $h): string
{
    $image = imagecreatetruecolor($l, $h);
    ob_start();
    imagegif($image);
    $octets = (string) ob_get_clean();
    imagedestroy($image);
    return $octets;
}

groupe('gd_disponible() — l application reste utilisable sans GD');

test('la disponibilité de GD est correctement rapportée', function () {
    egale(extension_loaded('gd') && function_exists('imagecreatefromstring'), gd_disponible(),
        'gd_disponible() reflète l état réel du serveur');
});

test('la disponibilité du WebP implique celle de GD', function () {
    if (gd_webp_disponible()) {
        vrai(gd_disponible(), 'pas de WebP sans GD');
    } else {
        vrai(true, 'WebP indisponible sur ce serveur : rien à vérifier');
    }
});

groupe('gif_anime() — ne pas aplatir une animation');

test('un GIF à une seule image n est pas pris pour une animation', function () {
    faux(gif_anime(gif_de(20, 20)), 'un GIF fixe produit par GD');
});

test('plusieurs blocs de contrôle graphique signalent une animation', function () {
    /* GD ne sait traiter que la première image d un GIF animé :
       le redimensionner le transformerait en image fixe. On préfère le
       laisser intact.

       Le fichier fabriqué ici porte deux blocs de contrôle après son
       marqueur de fin : getimagesizefromstring() ne lit que l en-tête et
       l accepte donc toujours, ce qui permet de suivre le chemin complet
       sans embarquer un vrai GIF animé dans le dépôt. */
    $anime = gif_de(20, 20) . "\x00\x21\xF9\x04" . "\x00\x21\xF9\x04";
    vrai(gif_anime($anime), 'deux blocs suffisent');
});

groupe('traiter_image() — ce qui est refusé');

test('un fichier vide est refusé', function () {
    $erreur = null;
    estNul(traiter_image('', $erreur), 'aucune image produite');
    contient('vide', (string) $erreur, 'la cause est expliquée');
});

test('un fichier trop lourd est refusé avant tout décodage', function () {
    $erreur = null;
    estNul(traiter_image(str_repeat('a', IMAGE_TAILLE_MAX + 1), $erreur), 'refusé');
    contient(taille_lisible(IMAGE_TAILLE_MAX), (string) $erreur, 'le message cite la limite réelle');
});

test('un script PHP renommé en image est refusé', function () {
    /* LE test de cette fonction : le type vient du contenu, pas du nom
       ni du Content-Type annoncé par le navigateur. */
    $erreur = null;
    estNul(traiter_image("<?php system(\$_GET['c']); ?>", $erreur), 'le script est rejeté');
    contient("n'est pas une image valide", (string) $erreur, 'message clair');
});

test('du HTML déguisé est refusé', function () {
    $erreur = null;
    estNul(traiter_image('<html><script>alert(1)</script></html>', $erreur), 'refusé');
});

test('une bombe de décompression est refusée SANS être décodée', function () {
    /* On fabrique un en-tête PNG annonçant 20 000 × 20 000 px, sans les
       données qui vont avec : c est exactement la forme de l attaque.
       Les dimensions se lisent dans l en-tête ; on refuse AVANT de
       décompresser, jamais après. */
    $entete = pack('N', 20000) . pack('N', 20000) . "\x08\x02\x00\x00\x00";
    $bombe  = "\x89PNG\r\n\x1a\n"
            . pack('N', 13) . 'IHDR' . $entete . pack('N', crc32('IHDR' . $entete));

    $erreur = null;
    estNul(traiter_image($bombe, $erreur), '400 Mpx sont refusés');
    contient('millions de pixels', (string) $erreur, 'la limite est expliquée');
});

test('le refus est traduit en dimensions, pas en mégapixels', function () {
    /* « 12 millions de pixels » ne dit rien à personne : l utilisateur
       voit des dimensions dans sa galerie, c est là-dessus qu il peut
       agir. */
    $entete = pack('N', 20000) . pack('N', 20000) . "\x08\x02\x00\x00\x00";
    $bombe  = "\x89PNG\r\n\x1a\n"
            . pack('N', 13) . 'IHDR' . $entete . pack('N', crc32('IHDR' . $entete));

    $erreur = null;
    traiter_image($bombe, $erreur);
    motif('/\d+ × \d+ pixels/u', (string) $erreur, 'des dimensions concrètes sont proposées');
});

groupe('traiter_image() — redimension et ré-encodage');

test('une grande image est ramenée au côté maximal', function () {
    $resultat = traiter_image(png_de(800, 400));
    vrai(is_array($resultat), 'une image est bien produite');
    [$largeur, $hauteur] = getimagesizefromstring($resultat['contenu']);
    egale(IMAGE_COTE_MAX, $largeur, 'le plus grand côté est ramené à la limite');
    egale(300, $hauteur, 'les proportions sont conservées (800x400 devient 600x300)');
});

test('une image en portrait est ramenée par sa hauteur', function () {
    // max($largeur, $hauteur) : c est le plus grand côté qui est borné,
    // pas la largeur.
    $resultat = traiter_image(png_de(400, 800));
    [$largeur, $hauteur] = getimagesizefromstring($resultat['contenu']);
    egale(IMAGE_COTE_MAX, $hauteur, 'la hauteur est ramenée à la limite');
    egale(300, $largeur, 'les proportions sont conservées');
});

test('une petite image n est jamais agrandie', function () {
    /* Agrandir la rendrait floue ET plus lourde : deux fois perdant. */
    $resultat = traiter_image(png_de(50, 30));
    [$largeur, $hauteur] = getimagesizefromstring($resultat['contenu']);
    egale(50, $largeur, 'la largeur d origine est conservée');
    egale(30, $hauteur, 'la hauteur aussi');
});

test('la sortie est en WebP quand le serveur sait en produire', function () {
    if (!gd_webp_disponible()) {
        vrai(true, 'WebP indisponible : le repli JPEG/PNG s applique');
        return;
    }
    $resultat = traiter_image(png_de(800, 400));
    egale('webp', $resultat['ext'], 'WebP, 25 à 35 % plus léger que JPEG');
});

test('le ré-encodage allège réellement le fichier', function () {
    // La raison d être du fichier : une couverture de 1500 px stockée
    // telle quelle fait payer à chaque visiteur dix fois le poids utile.
    $source = png_de(1200, 900);
    $resultat = traiter_image($source);
    vrai(strlen($resultat['contenu']) < strlen($source), 'la sortie est plus légère que l entrée');
});

test('un GIF animé est conservé intact', function () {
    $anime = gif_de(200, 200) . "\x00\x21\xF9\x04" . "\x00\x21\xF9\x04";
    $resultat = traiter_image($anime);
    egale('gif', $resultat['ext'], 'l extension d origine est gardée');
    egale($anime, $resultat['contenu'], 'les octets sont rendus tels quels');
});

test('aucune erreur n est renseignée quand tout se passe bien', function () {
    $erreur = 'valeur précédente';
    traiter_image(png_de(100, 100), $erreur);
    egale('valeur précédente', $erreur, 'la variable d erreur n est pas touchée');
});

groupe('ecrire_image() — nom de fichier et écriture');

test('le nom est l empreinte SALÉE du contenu', function () {
    /* Sans sel, quiconque possède la même image devine son URL et
       vérifie qu un utilisateur la possède aussi. UPLOAD_SECRET vaut
       « sel-de-test » ici (voir tests/amorce.php). */
    $contenu = 'octets-uniques-' . bin2hex(random_bytes(16));
    $chemin  = ecrire_image($contenu, 'webp');

    try {
        $attendu = 'uploads/' . hash('sha256', UPLOAD_SECRET . $contenu) . '.webp';
        egale($attendu, $chemin, 'le nom est bien salé');

        $sans_sel = 'uploads/' . hash('sha256', $contenu) . '.webp';
        differe($sans_sel, $chemin, 'l empreinte nue ne permet pas de retrouver le fichier');
    } finally {
        @unlink(CHEMIN_PROJET . '/' . $chemin);
    }
});

test('le fichier est réellement écrit, avec le bon contenu', function () {
    $contenu = 'octets-uniques-' . bin2hex(random_bytes(16));
    $chemin  = ecrire_image($contenu, 'webp');

    try {
        vrai(is_file(CHEMIN_PROJET . '/' . $chemin), 'le fichier existe sur le disque');
        egale($contenu, file_get_contents(CHEMIN_PROJET . '/' . $chemin), 'le contenu est intact');
    } finally {
        @unlink(CHEMIN_PROJET . '/' . $chemin);
    }
});

test('deux envois du même contenu partagent un seul fichier', function () {
    // Le nom étant l empreinte du contenu, la déduplication est gratuite.
    $contenu = 'octets-uniques-' . bin2hex(random_bytes(16));
    $premier = ecrire_image($contenu, 'webp');

    try {
        egale($premier, ecrire_image($contenu, 'webp'), 'le même chemin est rendu');
    } finally {
        @unlink(CHEMIN_PROJET . '/' . $premier);
    }
});

test('aucun fichier temporaire ne reste derrière', function () {
    /* L écriture est atomique : fichier « .part-xxxx.tmp » puis
       renommage, sinon deux envois simultanés peuvent servir un fichier à
       moitié écrit. Le nom du temporaire tombe sous les extensions
       refusées par le .htaccess de uploads/ — un résidu ne serait donc
       jamais servi en clair, mais il ne doit pas non plus s accumuler. */
    $avant   = glob(CHEMIN_PROJET . '/uploads/.part-*.tmp') ?: [];
    $contenu = 'octets-uniques-' . bin2hex(random_bytes(16));
    $chemin  = ecrire_image($contenu, 'webp');

    try {
        $apres = glob(CHEMIN_PROJET . '/uploads/.part-*.tmp') ?: [];
        egale(count($avant), count($apres), 'le temporaire a été renommé, pas laissé');
    } finally {
        @unlink(CHEMIN_PROJET . '/' . $chemin);
    }
});

groupe('enregistrer_image_depuis_donnees() — l import d une sauvegarde');

test('une donnée qui n est pas une image en base64 est refusée', function () {
    estNul(enregistrer_image_depuis_donnees('pas une data URI'), 'chaîne quelconque');
    estNul(enregistrer_image_depuis_donnees('data:text/html;base64,PGh0bWw+'), 'du HTML en data URI');
    estNul(enregistrer_image_depuis_donnees('https://exemple.test/x.png'), 'une URL');
});

test('une base64 démesurée est refusée AVANT d être décodée', function () {
    /* Sinon un fichier volontairement énorme ferait allouer sa taille en
       mémoire avant même qu on constate qu il est trop gros. */
    $enorme = 'data:image/png;base64,' . str_repeat('A', (int) (IMAGE_TAILLE_MAX * 1.5));
    estNul(enregistrer_image_depuis_donnees($enorme), 'refusé sur la seule longueur');
});

test('une image valide en data URI est restituée sur le disque', function () {
    $uri    = 'data:image/png;base64,' . base64_encode(png_de(120, 90));
    $chemin = enregistrer_image_depuis_donnees($uri);

    try {
        vrai(is_string($chemin), 'un chemin est rendu');
        motif('#^uploads/[a-f0-9]{64}\.(webp|png)$#', (string) $chemin, 'la forme attendue');
        vrai(is_file(CHEMIN_PROJET . '/' . $chemin), 'le fichier est bien là');
    } finally {
        if (is_string($chemin)) {
            @unlink(CHEMIN_PROJET . '/' . $chemin);
        }
    }
});
