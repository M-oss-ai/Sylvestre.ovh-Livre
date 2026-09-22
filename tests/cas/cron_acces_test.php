<?php
/* =====================================================================
   Qui a le droit de déclencher purger.php, et comment un refus doit
   échouer.

   Deux règles de sécurité, et deux façons de se tromper :

     - trop permissif, n importe qui déclenche la purge et lit le
       rapport d activité du site ;
     - trop silencieux, une tâche planifiée refusée sort en « réussite »
       et l hébergeur ne prévient jamais. C est exactement ce qui s est
       produit : un cron réglé sur une exécution par heure, 48 heures de
       silence complet, et aucune purge.

   Ces décisions vivent dans includes/fonctions.php et non dans
   purger.php, qui s exécute dès qu on l inclut et serait donc
   intestable.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('cron_en_ligne_de_commande() — se passer du jeton');

test('la ligne de commande se passe du jeton', function () {
    vrai(cron_en_ligne_de_commande('cli', []), 'php purger.php');
});

test('un contexte HTTP totalement absent aussi', function () {
    /* Certains hébergeurs invoquent leurs tâches par un enrobage CGI
       plutôt qu en « cli ». Sans variable HTTP d aucune sorte, il n y a
       pas de requête web à laquelle réclamer un jeton. */
    vrai(cron_en_ligne_de_commande('cgi-fcgi', []), 'aucune variable HTTP');
});

test('la moindre trace de requête web exige le jeton', function () {
    /* Le point qui protège le rapport d activité : il contient le
       nombre de comptes, l état du stockage et le détail des blocages.
       Un « au moindre doute, on exige le jeton » vaut mieux qu une
       détection astucieuse mais contournable. */
    faux(cron_en_ligne_de_commande('cgi-fcgi', ['REQUEST_METHOD' => 'GET']),
        'une méthode HTTP suffit');
    faux(cron_en_ligne_de_commande('cgi-fcgi', ['REMOTE_ADDR' => '1.2.3.4']),
        'une adresse d appelant suffit');
    faux(cron_en_ligne_de_commande('cgi-fcgi', ['HTTP_HOST' => 'livre.exemple.test']),
        'un nom d hôte suffit — c est le cas des crons OVH');
});

test('« cli » l emporte sur les variables résiduelles', function () {
    /* Un vrai processus en ligne de commande reste un processus en
       ligne de commande, même si l environnement porte des variables
       héritées du shell. */
    vrai(cron_en_ligne_de_commande('cli', ['HTTP_HOST' => 'residuel']),
        'le SAPI fait foi');
});

groupe('cron_refus_navigateur() — comment échouer');

test('un navigateur reçoit un refus muet', function () {
    /* 404 sans explication : la réponse ne confirme pas même
       l existence du script. */
    vrai(cron_refus_navigateur(['REQUEST_METHOD' => 'GET']), 'requête web');
    vrai(cron_refus_navigateur(['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '1.2.3.4']),
        'requête web complète');
});

test('une tâche planifiée refusée doit être bruyante', function () {
    /* LE cas qui coûte cher. Sans méthode HTTP, ce n est pas un
       visiteur : c est le cron, invoqué par un enrobage CGI qui a
       laissé traîner un HTTP_HOST. Il faut un code de retour non nul,
       sans quoi l hébergeur réglé sur « uniquement en cas d erreur »
       ne dira jamais rien. */
    faux(cron_refus_navigateur(['HTTP_HOST' => 'livre.exemple.test']),
        'un HTTP_HOST seul trahit un cron, pas un visiteur');
    faux(cron_refus_navigateur([]), 'aucun contexte du tout');
});

test('les deux règles se complètent sans se contredire', function () {
    /* Le cas qui a fait perdre 48 heures : assez de contexte HTTP pour
       qu on réclame un jeton, pas assez pour que ce soit un visiteur.
       Il tombe donc dans le refus BRUYANT, et non dans le 404 muet. */
    $cron_ovh = ['HTTP_HOST' => 'livre.exemple.test'];
    faux(cron_en_ligne_de_commande('cgi-fcgi', $cron_ovh), 'un jeton est exigé');
    faux(cron_refus_navigateur($cron_ovh), 'et le refus doit être bruyant');
});
groupe('cron_appelant_authentifie() — montrer la cause à qui de droit');

test('le bon jeton est reconnu', function () {
    vrai(cron_appelant_authentifie(['HTTP_X_CRON_TOKEN' => 'secret'], 'secret'),
        'jeton identique');
});

test('un mauvais jeton ne l est pas', function () {
    faux(cron_appelant_authentifie(['HTTP_X_CRON_TOKEN' => 'autre'], 'secret'),
        'jeton different');
    faux(cron_appelant_authentifie(['HTTP_X_CRON_TOKEN' => 'secre'], 'secret'),
        'jeton tronque');
    faux(cron_appelant_authentifie(['HTTP_X_CRON_TOKEN' => 'secretX'], 'secret'),
        'jeton rallonge');
});

test('aucun en-tête, aucune confiance', function () {
    faux(cron_appelant_authentifie([], 'secret'), 'en-tête absent');
    faux(cron_appelant_authentifie(['HTTP_X_CRON_TOKEN' => ''], 'secret'), 'en-tête vide');
});

test('un jeton attendu VIDE n autorise personne', function () {
    /* La garde qui compte. Sans elle, un site dont le .env n est pas
       rempli — CRON_TOKEN absent, donc chaîne vide — montrerait le
       chemin de ses fichiers et ses messages d erreur SQL au premier
       venu, y compris à qui n envoie aucun en-tête. */
    faux(cron_appelant_authentifie(['HTTP_X_CRON_TOKEN' => ''], ''), 'vide contre vide');
    faux(cron_appelant_authentifie([], ''), 'rien contre vide');
    faux(cron_appelant_authentifie(['HTTP_X_CRON_TOKEN' => 'nimporte'], ''), 'jeton contre vide');
});