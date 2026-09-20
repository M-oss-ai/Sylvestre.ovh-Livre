<?php
/* =====================================================================
   e() et texte() — la première ligne de défense contre les injections
   HTML / XSS, et le nettoyage des saisies.

   e() est appelée sur TOUT ce qui sort vers le HTML. Si elle laisse
   passer quoi que ce soit, la protection du site entier tombe.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('e() — échappement HTML');

test('les chevrons deviennent des entités', function () {
    egale('&lt;b&gt;', e('<b>'), 'une balise ne peut plus s ouvrir');
});

test('les deux sortes de guillemets sont neutralisées', function () {
    /* ENT_QUOTES, et pas seulement ENT_COMPAT : une valeur insérée dans
       un attribut écrit avec des apostrophes (title='…') s échapperait
       sinon de son attribut. Le projet en a plusieurs dans carte.php. */
    egale('&quot;', e('"'), 'le guillemet double');
    egale('&#039;', e("'"), 'l apostrophe');
});

test('l esperluette est échappée en premier', function () {
    // Sinon « &lt; » écrit par l'utilisateur ressortirait tel quel et
    // s'afficherait comme un vrai « < ».
    egale('&amp;', e('&'), 'une esperluette seule');
    egale('&amp;lt;', e('&lt;'), 'une entité déjà écrite est ré-échappée');
});

test('null est traité comme une chaîne vide', function () {
    // Les colonnes nullables de la base (auteur, prenom, nom, photo)
    // arrivent régulièrement à null.
    egale('', e(null), 'aucune erreur, aucune sortie');
});

test('une charge utile XSS classique est désamorcée', function () {
    $sortie = e('<img src=x onerror="alert(1)">');
    sans('<img', $sortie, 'la balise ne subsiste pas');
    sans('"', $sortie, 'aucun guillemet brut ne subsiste');
    contient('&lt;img', $sortie, 'elle est bien présente, mais inerte');
});

test('de l UTF-8 invalide ne vide pas la chaîne en silence', function () {
    /* Sans ENT_SUBSTITUTE, htmlspecialchars() rend une chaîne VIDE sur un
       octet invalide : un titre légitime disparaîtrait de l écran sans
       que rien ne le signale. */
    $sortie = e("Bonjour\xC3\x28monde");
    differe('', $sortie, 'la chaîne ne disparaît pas');
    contient('Bonjour', $sortie, 'la partie valide est conservée');
    contient('monde', $sortie, 'la suite aussi');
});

test('un texte ordinaire traverse sans dommage', function () {
    egale('Fullmetal Alchemist', e('Fullmetal Alchemist'), 'aucune altération');
    egale('Hiromu Arakawa — 荒川 弘', e('Hiromu Arakawa — 荒川 弘'), 'accents et idéogrammes intacts');
});

groupe('texte() — nettoyage d une saisie');

test('les espaces de début et de fin sont retirés', function () {
    egale('One Piece', texte('   One Piece   '), 'la valeur est trim()');
});

test('les caractères de contrôle sont supprimés', function () {
    /* Un octet nul ou un caractère d échappement glissé dans un titre
       finit dans la base, puis dans le journal, puis dans un e-mail. */
    egale('ab', texte("a\x00b"), 'l octet nul');
    egale('ab', texte("a\x07b"), 'la sonnerie');
    egale('ab', texte("a\x1Bb"), 'l échappement ANSI');
    egale('ab', texte("a\x7Fb"), 'le caractère de suppression');
});

test('les retours à la ligne deviennent un espace', function () {
    /* Tous les champs qui passent par texte() sont sur une seule ligne :
       titre, auteur, prénom, nom, identifiant, adresse. Un texte collé
       depuis deux lignes doit donner « Ligne1 Ligne2 ».

       Un espace, et non une suppression pure : « Ligne1Ligne2 » souderait
       deux mots sans prévenir. */
    egale('a b', texte("a\nb"), 'un saut de ligne');
    egale('a b', texte("a\rb"), 'un retour chariot');
    egale('a b', texte("a\r\nb"), 'la paire CRLF ne donne qu UN espace');
    egale('a b', texte("a\n\n\nb"), 'une suite de sauts de ligne aussi');
});

test('la tabulation devient un espace elle aussi', function () {
    // Invisible à l écran dans un champ d une seule ligne, et elle arrive
    // facilement d un copier-coller depuis un tableur.
    egale('a b', texte("a\tb"), 'la tabulation');
    egale('a b', texte("a\t\tb"), 'plusieurs tabulations de suite');
});

test('un titre multiligne reste lisible', function () {
    // Le cas concret : un titre copié depuis une page web ou un tableur.
    egale('Fullmetal Alchemist Édition intégrale',
        texte("Fullmetal Alchemist\r\nÉdition intégrale"),
        'les deux lignes sont recollées proprement');
});

test('aucun retour à la ligne ne subsiste après nettoyage', function () {
    /* C est ce qui permet à tout ce qui est en aval — journal, e-mail,
       attribut HTML — de compter sur une valeur d une seule ligne. */
    $nettoye = texte("a\r\nb\rc\nd\te");
    sans("\n", $nettoye, 'aucun saut de ligne');
    sans("\r", $nettoye, 'aucun retour chariot');
    sans("\t", $nettoye, 'aucune tabulation');
});

test('la longueur est bornée en CARACTÈRES, pas en octets', function () {
    /* mb_substr() et non substr() : couper « é » au milieu produirait un
       octet orphelin, et toute la chaîne deviendrait de l UTF-8 invalide. */
    egale(10, mb_strlen(texte(str_repeat('é', 50), 10), 'UTF-8'), '10 caractères accentués');
    egale(str_repeat('é', 10), texte(str_repeat('é', 50), 10), 'et ils sont intacts');
});

test('la limite par défaut correspond à la colonne de la base', function () {
    // VARCHAR(190) pour titre et auteur : au-delà, MySQL tronquerait ou
    // refuserait selon son mode.
    egale(190, mb_strlen(texte(str_repeat('a', 500)), 'UTF-8'), '190 caractères par défaut');
});

test('une chaîne plus courte que la limite n est pas touchée', function () {
    egale('Naruto', texte('Naruto', 190), 'aucune troncature inutile');
});

test('ce qui n est pas une chaîne devient une chaîne vide', function () {
    /* $_POST peut contenir un tableau (« titre[]=x » dans l URL) : sans
       ce garde-fou, PHP lèverait une erreur de type sur une requête
       forgée à la main. */
    egale('', texte(null), 'null');
    egale('', texte(['tableau']), 'un tableau');
    egale('', texte(42), 'un entier');
    egale('', texte(true), 'un booléen');
});

test('de l UTF-8 invalide est réparé, pas jeté', function () {
    /* preg_replace() avec /u rend null sur une entrée invalide, et le
       « ?? '' » qui suit transformerait alors un titre légitime en chaîne
       vide sans rien signaler. L encodage est donc réparé AVANT. */
    $sortie = texte("Titre\xC3\x28 valide");
    differe('', $sortie, 'la saisie n est pas effacée');
    contient('Titre', $sortie, 'le début est conservé');
    contient('valide', $sortie, 'la fin aussi');
    vrai(mb_check_encoding($sortie, 'UTF-8'), 'le résultat est de l UTF-8 valide');
});

test('une chaîne uniquement faite d espaces devient vide', function () {
    // C'est ce qui permet aux appelants de tester « $titre === '' » pour
    // savoir si le champ obligatoire a réellement été rempli.
    egale('', texte('     '), 'des espaces');
    egale('', texte("\r\n\t "), 'des blancs mélangés');
});
