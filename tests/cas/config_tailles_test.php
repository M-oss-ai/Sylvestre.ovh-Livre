<?php
/* =====================================================================
   ini_octets(), taille_lisible(), plafond_pixels()

   Trois fonctions de conversion, dont deux gardent la mémoire du
   processus : plafond_pixels() est ce qui empêche une image trop grande
   de tuer PHP par épuisement mémoire — une erreur fatale que
   set_exception_handler() ne rattrape PAS.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('ini_octets() — « 128M » vers des octets');

test('les suffixes G, M et K sont compris', function () {
    egale(1073741824, ini_octets('1G'), '1G');
    egale(134217728,  ini_octets('128M'), '128M');
    egale(524288,     ini_octets('512K'), '512K');
});

test('la casse du suffixe est indifférente', function () {
    // php.ini accepte les deux formes : le code doit en faire autant.
    egale(8388608, ini_octets('8m'), '8m en minuscule');
    egale(8388608, ini_octets('8M'), '8M en majuscule');
    egale(2097152, ini_octets('2048k'), '2048k');
});

test('un nombre nu est déjà en octets', function () {
    egale(1024, ini_octets('1024'), 'aucun suffixe');
    egale(0,    ini_octets('0'), 'zéro reste zéro');
});

test('les espaces autour sont tolérés', function () {
    egale(67108864, ini_octets(' 64M '), 'la valeur est trim()');
});

test('« pas de limite » vaut -1', function () {
    /* memory_limit peut valoir -1, et post_max_size peut être vide. Les
       deux veulent dire « aucune limite » : les appelants testent
       « > 0 », il faut donc surtout ne pas rendre 0, qui voudrait dire
       « limite de zéro octet » et refuserait tout. */
    egale(-1, ini_octets('-1'), 'la valeur -1 de php.ini');
    egale(-1, ini_octets(''), 'une valeur vide');
});

groupe('taille_lisible() — des octets vers « 3 Mo »');

test('au-delà du mégaoctet, la sortie est en Mo', function () {
    egale('1 Mo', taille_lisible(1048576), 'exactement 1 Mo');
    egale('3 Mo', taille_lisible(3145728), 'la valeur par défaut de IMAGE_TAILLE_MAX');
    egale('10 Mo', taille_lisible(10485760), '10 Mo');
});

test('la décimale utilise la virgule et disparaît si elle est nulle', function () {
    // Le message est lu par un francophone : « 1.5 Mo » n'est pas du français,
    // et « 3,0 Mo » est du bruit.
    egale('1,5 Mo', taille_lisible(1572864), 'une demie est affichée');
    egale('3 Mo',   taille_lisible(3145728), 'le « ,0 » est retiré');
});

test('en dessous du mégaoctet, la sortie est en Ko', function () {
    egale('512 Ko', taille_lisible(524288), '512 Ko');
    egale('1 Ko',   taille_lisible(1024), '1 Ko');
    egale('0 Ko',   taille_lisible(0), 'zéro octet');
});

test('le message suit le réglage au lieu de le répéter en dur', function () {
    /* Toute la raison d'être de cette fonction : quand IMAGE_TAILLE_MAX
       change dans le .env, le message d'erreur affiché à l'utilisateur
       doit changer avec lui. */
    contient(taille_lisible(IMAGE_TAILLE_MAX), message_post_trop_gros(),
        'message_post_trop_gros() cite la limite réellement appliquée');
});

groupe('plafond_pixels() — le garde-fou mémoire');

test('sans limite mémoire, la demande passe telle quelle', function () {
    $avant = ini_get('memory_limit');
    ini_set('memory_limit', '-1');
    egale(40000000, plafond_pixels(40000000), 'rien ne vient rogner la demande');
    ini_set('memory_limit', (string) $avant);
});

test('une demande trop gourmande est ramenée à ce que la mémoire permet', function () {
    /* GD alloue environ 4 octets par pixel. Avec 128 Mo et 32 Mo réservés
       au reste de la requête, il reste (128-32)/4 = 24 Mpx. Une demande de
       40 Mpx — la valeur d'origine du projet — passait le contrôle puis
       tuait le processus par épuisement mémoire. */
    $avant = ini_get('memory_limit');
    ini_set('memory_limit', '128M');
    egale(25165824, plafond_pixels(40000000), '40 Mpx demandés, 24 Mpx accordés');
    ini_set('memory_limit', (string) $avant);
});

test('une demande raisonnable n est pas rabotée pour rien', function () {
    $avant = ini_get('memory_limit');
    ini_set('memory_limit', '128M');
    egale(12000000, plafond_pixels(12000000), '12 Mpx tiennent dans 128 Mo');
    ini_set('memory_limit', (string) $avant);
});

test('un plancher empêche de refuser absolument toute image', function () {
    /* Avec très peu de mémoire, le calcul donnerait un plafond ridicule
       (ici 0,5 Mpx) et plus aucune photo ne passerait. Le plancher à
       1 Mpx garde l'application utilisable. */
    $avant = ini_get('memory_limit');
    ini_set('memory_limit', '34M');
    egale(1000000, plafond_pixels(12000000), 'le plancher de 1 Mpx s applique');
    ini_set('memory_limit', (string) $avant);
});

test('la constante du projet reste dans des bornes crédibles', function () {
    vrai(IMAGE_PIXELS_MAX >= 1000000, 'IMAGE_PIXELS_MAX ne descend pas sous 1 Mpx');
    vrai(is_int(IMAGE_PIXELS_MAX), 'c est bien un entier');
});
