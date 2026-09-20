<?php
/* =====================================================================
   ip_client() — SANS répartiteur devant le site (IP_ENTETE vide).

   ip_client() met son résultat en cache dans un « static » : le premier
   appel le fige pour toute la durée du processus. Chaque scénario a donc
   son propre fichier, donc son propre processus (voir tests/processus.php).
   ===================================================================== */

declare(strict_types=1);

$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

/* Un en-tête envoyé par le client. Il ne doit RIEN changer tant que
   IP_ENTETE est vide : c'est toute la raison du réglage vide par défaut. */
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

putenv('IP_ENTETE=');

require __DIR__ . '/../lanceur.php';

groupe('ip_client() — connexion directe');

test('REMOTE_ADDR est utilisée telle quelle', function () {
    egale('203.0.113.5', ip_client(), 'l adresse du visiteur');
});

test('un X-Forwarded-For envoyé par le client est IGNORÉ', function () {
    /* Sans répartiteur devant, n'importe qui peut envoyer l'en-tête de
       son choix. S'il était lu, chaque requête passerait pour un nouveau
       visiteur et le limiteur anti force brute ne verrait plus jamais
       deux tentatives venir du même endroit : il serait désarmé. */
    egale('203.0.113.5', ip_client(), 'l en-tête falsifié ne prend pas la main');
    differe('1.2.3.4', ip_client(), 'la valeur inventée par le client est écartée');
});

test('IP_ENTETE est bien vide par défaut', function () {
    egale('', IP_ENTETE, 'le réglage est fermé par défaut, et ce n est pas un oubli');
});

test('le résultat est stable au sein d une requête', function () {
    // Le limiteur et le journal l'appellent chacun : la valeur ne peut pas
    // changer en cours de route, sinon on compterait sur deux clés.
    egale(ip_client(), ip_client(), 'deux appels rendent la même valeur');
});
