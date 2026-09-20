<?php
/* =====================================================================
   jeton_csrf(), csrf_valide(), post_trop_gros(), message_post_trop_gros()

   Le jeton CSRF est exigé sur CHAQUE POST du site, déconnexion comprise.
   post_trop_gros() existe parce que, sans lui, un utilisateur qui choisit
   simplement une image trop lourde lit « jeton de sécurité invalide » :
   PHP a vidé $_POST, donc le jeton manque, et le message n a plus aucun
   rapport avec ce qui s est passé.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('jeton_csrf() — fabrication');

test('le jeton fait 64 caractères hexadécimaux', function () {
    // 32 octets tirés au hasard : impossible à deviner.
    motif('/^[a-f0-9]{64}$/', jeton_csrf(), '256 bits en hexadécimal');
});

test('le jeton ne change pas au sein d une même session', function () {
    /* S il était retiré à chaque appel, une page affichant deux
       formulaires en invaliderait un sur deux. */
    egale(jeton_csrf(), jeton_csrf(), 'deux appels rendent le même jeton');
});

test('le jeton est bien celui rangé en session', function () {
    egale($_SESSION['csrf'], jeton_csrf(), 'la session porte la référence');
});

groupe('csrf_valide() — vérification');

test('le bon jeton est accepté', function () {
    vrai(csrf_valide(jeton_csrf()), 'le jeton courant passe');
});

test('un jeton faux est refusé', function () {
    faux(csrf_valide(str_repeat('a', 64)), 'un jeton inventé de la bonne longueur');
    faux(csrf_valide('x'), 'un jeton manifestement faux');
    faux(csrf_valide(''), 'une chaîne vide');
});

test('un jeton tronqué est refusé', function () {
    /* hash_equals() compare la LONGUEUR d abord : un préfixe correct ne
       doit pas passer, sinon il suffirait de deviner caractère par
       caractère. */
    faux(csrf_valide(substr(jeton_csrf(), 0, 32)), 'la moitié du bon jeton');
});

test('ce qui n est pas une chaîne est refusé', function () {
    /* « csrf[]=x » dans une requête forgée fait arriver un TABLEAU.
       Sans le is_string(), hash_equals() lèverait une erreur de type et
       la page finirait en 500 au lieu d un refus propre. */
    faux(csrf_valide(null), 'null');
    faux(csrf_valide(['tableau']), 'un tableau');
    faux(csrf_valide(42), 'un entier');
    faux(csrf_valide(true), 'un booléen');
});

test('sans jeton en session, rien n est accepté', function () {
    /* Le cas d une session neuve ou expirée : aucune valeur ne doit
       passer, surtout pas la chaîne vide. */
    $memoire = $_SESSION['csrf'] ?? null;
    unset($_SESSION['csrf']);

    faux(csrf_valide(''), 'la chaîne vide ne vaut pas un jeton absent');
    faux(csrf_valide(str_repeat('a', 64)), 'ni quoi que ce soit d autre');

    if ($memoire !== null) {
        $_SESSION['csrf'] = $memoire;
    }
});

groupe('post_trop_gros() — distinguer « trop lourd » de « jeton invalide »');

test('hors POST, la question ne se pose pas', function () {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    faux(post_trop_gros(), 'une requête GET');
});

test('un POST qui a bien reçu ses données n est pas concerné', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['action' => 'serie.enregistrer'];
    $_FILES = [];
    $_SERVER['CONTENT_LENGTH'] = '999999999';
    faux(post_trop_gros(), '$_POST est rempli : PHP n a rien jeté');
    $_POST = [];
});

test('un POST vidé par PHP avec un corps énorme est reconnu', function () {
    $max = ini_octets((string) ini_get('post_max_size'));
    if ($max <= 0) {
        // Sans limite configurée, le cas ne peut pas se produire.
        vrai(true, 'post_max_size illimité : rien à vérifier ici');
        return;
    }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [];
    $_FILES = [];
    $_SERVER['CONTENT_LENGTH'] = (string) ($max + 1);
    vrai(post_trop_gros(), 'le corps dépassait post_max_size');
});

test('un POST vide avec un corps normal n est pas accusé à tort', function () {
    /* Sinon un vrai problème de jeton CSRF serait présenté comme un
       problème de taille, et l utilisateur chercherait au mauvais
       endroit. */
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [];
    $_FILES = [];
    $_SERVER['CONTENT_LENGTH'] = '10';
    faux(post_trop_gros(), 'dix octets ne dépassent rien');
});

test('un POST sans CONTENT_LENGTH n est pas accusé non plus', function () {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [];
    $_FILES = [];
    unset($_SERVER['CONTENT_LENGTH']);
    faux(post_trop_gros(), 'en-tête absent : on ne conclut rien');
});

groupe('message_post_trop_gros() — un message qui aide vraiment');

test('le message cite les deux limites en jeu', function () {
    /* Celle de PHP (post_max_size, la cause du rejet) ET celle de
       l application (IMAGE_TAILLE_MAX, celle sur laquelle l utilisateur
       peut agir). */
    $message = message_post_trop_gros();
    contient(taille_lisible(ini_octets((string) ini_get('post_max_size'))), $message,
        'la limite de PHP');
    contient(taille_lisible(IMAGE_TAILLE_MAX), $message, 'la limite de l application');
});

test('le message dit quoi faire', function () {
    contient('image plus légère', message_post_trop_gros(), 'une action concrète est proposée');
});
