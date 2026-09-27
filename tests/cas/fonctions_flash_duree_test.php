<?php
/* =====================================================================
   flash() et duree_cookie_lisible() — deux messages qui ne mentaient plus.

   « Mot de passe modifié ✅ » était porté par l'URL (?mdp=1) et revenait
   à chaque rechargement ; flash() le range dans la session, où il ne se
   lit qu'une fois.

   Les mentions légales annonçaient un cookie « le temps de votre visite »
   alors que le serveur le réglait sur un an : elles lisent maintenant la
   durée dans la configuration, et duree_cookie_lisible() la met en mots.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('flash() — un message pour la page suivante, et elle seule');

test('le message est rendu une fois, puis plus jamais', function () {
    flash('Mot de passe modifié ✅');
    egale('Mot de passe modifié ✅', flash_prendre(), 'la page suivante le lit');
    egale('', flash_prendre(), 'un rechargement ne le revoit pas');
});

test('sans message, rien', function () {
    unset($_SESSION['flash']);
    egale('', flash_prendre(), 'une chaîne vide, que la page n affiche pas');
});

test('le dernier message l emporte', function () {
    flash('premier');
    flash('second');
    egale('second', flash_prendre(), 'une seule redirection, un seul message');
});

groupe('duree_cookie_lisible() — une durée en mots');

test('la plus grande unité qui divise exactement', function () {
    egale('1 an', duree_cookie_lisible(31536000), 'la valeur par défaut des cookies');
    egale('2 ans', duree_cookie_lisible(63072000), 'au pluriel');
    egale('30 jours', duree_cookie_lisible(2592000), 'un mois reste en jours : les mois n ont pas de longueur fixe');
    egale('1 jour', duree_cookie_lisible(86400), 'au singulier');
    egale('2 heures', duree_cookie_lisible(7200), 'en heures');
    egale('90 minutes', duree_cookie_lisible(5400), 'pas « 1,5 heure »');
    egale('45 secondes', duree_cookie_lisible(45), 'en secondes');
});

test('zéro et le négatif ne produisent pas de texte absurde', function () {
    egale('0 seconde', duree_cookie_lisible(0), 'zéro');
    egale('0 seconde', duree_cookie_lisible(-10), 'un négatif est ramené à zéro');
});

groupe('Mentions légales — la durée des cookies vient de la configuration');

/** La page, rendue dans un processus où les deux durées sont imposées. */
function mentions(string $session, string $remember): string
{
    $r = executer_php(CHEMIN_PROJET . '/tests/outils/afficher-mentions-legales.php', [], [
        'SESSION_DUREE' => $session, 'REMEMBER_DUREE_VIP' => $remember,
    ]);
    return $r['sortie'];
}

test('un cookie de session d un an est annoncé comme tel', function () {
    $page = mentions('31536000', '31536000');
    contient('conserve 1 an au plus', $page, 'la durée réelle, lue dans le .env');
    sans('le temps de votre visite', $page, 'plus de promesse fausse');
});

test('un cookie de session sans durée reste « le temps de votre visite »', function () {
    $page = mentions('0', '31536000');
    contient('le temps de votre visite', $page, 'SESSION_DUREE=0 : cookie effacé à la fermeture');
});

test('la connexion longue de l illimité annonce sa propre durée', function () {
    $page = mentions('0', '2592000');
    contient('LIVRE_REMEMBER', $page, 'le cookie est cité');
    contient('30 jours au plus', $page, 'la durée réglée, pas « un an » écrit en dur');
});

test('sans connexion longue, le cookie n est pas cité', function () {
    // REMEMBER_DUREE_VIP=0 : le cookie expire à l instant où il est posé.
    $page = mentions('0', '0');
    sans('LIVRE_REMEMBER', $page, 'on ne décrit pas un cookie qui n existe pas');
});
