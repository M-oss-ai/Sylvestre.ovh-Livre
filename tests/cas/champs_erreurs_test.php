<?php
/* =====================================================================
   champ_aria(), champ_erreur() et url_image_refusee()

   Les deux premières rattachent une erreur de formulaire à SON champ :
   le message s'affiche dessous, le champ le désigne (aria-describedby)
   et se déclare invalide (aria-invalid), le premier champ fautif prend
   le focus. Une liste en haut de page ne disait ni quel champ corriger,
   ni où il se trouvait.

   La troisième distingue « rien de saisi » de « saisi puis refusé » :
   url_image_sure() rend une chaîne vide dans les deux cas, et une
   adresse en http:// disparaissait ainsi sans un mot.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('champ_aria() — les attributs du champ');

test('un champ sans erreur ne reçoit rien', function () {
    egale('', champ_aria([], 'email', 'a-email'), 'aucune erreur, aucun attribut');
    egale('', champ_aria(['nom' => 'x'], 'email', 'a-email'), 'l erreur d un autre champ ne le concerne pas');
});

test('un champ sans erreur garde son texte d aide', function () {
    egale(' aria-describedby="a-email-aide"', champ_aria([], 'email', 'a-email', 'a-email-aide'),
        'l aide reste reliée au champ');
});

test('un champ fautif se déclare invalide et désigne son message', function () {
    $attributs = champ_aria(['email' => 'Adresse invalide.'], 'email', 'a-email');
    contient('aria-invalid="true"', $attributs, 'aria-invalid est posé');
    contient('aria-describedby="a-email-erreur"', $attributs, 'le message est désigné par son id');
});

test('le message passe avant l aide, qui reste désignée', function () {
    /* L'ordre compte : un lecteur d'écran lit les descriptions dans
       l'ordre de la liste, et c'est l'erreur qu'il faut entendre d'abord. */
    contient('aria-describedby="a-email-erreur a-email-aide"',
        champ_aria(['email' => 'x'], 'email', 'a-email', 'a-email-aide'), 'erreur, puis aide');
});

test('seul le PREMIER champ fautif reçoit le focus', function () {
    $erreurs = ['identifiant' => 'x', 'email' => 'y'];
    contient('autofocus', champ_aria($erreurs, 'identifiant', 'a-username'), 'le premier l a');
    sans('autofocus', champ_aria($erreurs, 'email', 'a-email'), 'le second ne l a pas');
});

test('seul le premier de la liste a le focus, même si ce n est pas un champ', function () {
    /* La clé '' porte ce qui ne tient à aucun champ (limite atteinte…) et
       s'affiche en tête. Le premier CHAMP venant après elle n'est pas
       « le premier fautif » : il ne prend pas le focus pour autant. */
    $erreurs = ['' => 'Trop de comptes.', 'email' => 'y'];
    sans('autofocus', champ_aria($erreurs, 'email', 'a-email'), 'le champ n est pas le premier de la liste');
});

test('les identifiants sont échappés', function () {
    sans('"><', champ_aria(['x' => 'y'], 'x', '"><script>'), 'aucune balise ne peut s ouvrir');
});

groupe('champ_erreur() — le message sous le champ');

test('rien quand le champ n est pas en erreur', function () {
    egale('', champ_erreur([], 'email', 'a-email'), 'aucun message');
});

test('le message porte l id que le champ désigne', function () {
    $html = champ_erreur(['email' => 'Adresse invalide.'], 'email', 'a-email');
    contient('id="a-email-erreur"', $html, 'même id que dans aria-describedby');
    contient('Adresse invalide.', $html, 'le texte est là');
});

test('le message est échappé', function () {
    $html = champ_erreur(['email' => '<img src=x onerror=alert(1)>'], 'email', 'a-email');
    sans('<img', $html, 'aucune balise interprétée');
    contient('&lt;img', $html, 'le texte est affiché tel quel');
});

test('plusieurs messages pour un champ : une ligne chacun', function () {
    // Les règles du mot de passe peuvent échouer ensemble.
    $html = champ_erreur(['mdp' => ['Trop court.', 'Il manque un chiffre.']], 'mdp', 'a-new');
    contient('Trop court.<br>Il manque un chiffre.', $html, 'séparés par un retour à la ligne');
});

groupe('url_image_refusee() — saisie refusée ou rien de saisi');

test('rien de saisi n est pas un refus', function () {
    faux(url_image_refusee(''), 'chaîne vide');
    faux(url_image_refusee('   '), 'des espaces seulement');
    faux(url_image_refusee(null), 'champ absent');
    faux(url_image_refusee(['https://x.test/a.png']), 'un tableau (champ bricolé) compte comme vide');
});

test('une adresse acceptée n est pas refusée', function () {
    faux(url_image_refusee('https://exemple.test/couverture.png'), 'https');
    faux(url_image_refusee('uploads/abc.webp'), 'un fichier du site');
});

test('une adresse en http:// est refusée', function () {
    /* Le cas de l'audit : acceptée à l'écran, puis jetée en silence par
       le serveur, la série s'enregistrant « ✅ » sans son image. */
    vrai(url_image_refusee('http://exemple.test/couverture.png'), 'http');
});

test('les schémas dangereux sont refusés', function () {
    vrai(url_image_refusee('javascript:alert(1)'), 'javascript:');
    vrai(url_image_refusee('data:image/png;base64,AAAA'), 'data:');
});
