<?php
/* =====================================================================
   La file d attente des appels à MangaDex.

   MangaDex tolère environ 5 requêtes par seconde et par adresse IP —
   celle du SERVEUR, partagée par tous les visiteurs. Une recherche coûte
   1 + COUVERTURE_MAX_SERIES appels : deux personnes en même temps
   suffisent à dépasser la limite sans que personne n ait rien fait
   d anormal.

   D où une file plutôt qu un quota : ici on ne compte personne et on ne
   sanctionne personne, les appels attendent leur tour. Ce qui se vérifie
   ci-dessous, c est que l attente existe VRAIMENT (sinon la protection
   est décorative) et qu elle reste BORNÉE (sinon elle retient les
   processus PHP d un mutualisé, qui se comptent sur les doigts d une
   main).

   Ce fichier attend pour de bon : d où une cadence raccourcie, et son
   propre processus puisqu une constante ne se redéfinit pas.
   ===================================================================== */

declare(strict_types=1);

putenv('COUVERTURE_ESPACEMENT=120');
putenv('COUVERTURE_FILE_MAX=300');

require __DIR__ . '/../lanceur.php';
require_once CHEMIN_PROJET . '/includes/couvertures.php';

groupe('mangadex_attente_suggeree() — le délai à proposer');

test('rien à proposer tant que tout va bien', function () {
    egale(0, mangadex_attente_suggeree(), 'zéro au départ');
});

test('la valeur posée est relue telle quelle', function () {
    egale(7, mangadex_attente_suggeree(7), 'retour immédiat');
    egale(7, mangadex_attente_suggeree(), 'et conservée pour la suite de la requête');
});

test('jamais un délai négatif', function () {
    /* La valeur vient d un horodatage envoyé par MangaDex, moins
       l heure locale. Deux horloges qui divergent donneraient un délai
       négatif, et « réessayez dans -4 secondes » n a aucun sens — ni à
       l écran, ni pour le compte à rebours qui l affiche. */
    egale(0, mangadex_attente_suggeree(-30), 'ramené à zéro');
});

groupe('mangadex_attendre_son_tour() — la file elle-même');

test('la file libre laisse passer', function () {
    mangadex_attente_suggeree(0);
    egale(0, mangadex_attendre_son_tour(), 'le tour est acquis');
});

test('deux appels consécutifs sont réellement espacés', function () {
    /* C est tout l objet du dispositif. Sans cette attente, cinq appels
       d une seule recherche partiraient en rafale et la limite serait
       franchie par un utilisateur seul, sans même de simultanéité. */
    mangadex_attendre_son_tour();
    $debut = microtime(true);
    egale(0, mangadex_attendre_son_tour(), 'le second passe aussi');
    $ecoule = (microtime(true) - $debut) * 1000;
    vrai($ecoule >= COUVERTURE_ESPACEMENT * 0.8,
        'au moins ' . COUVERTURE_ESPACEMENT . ' ms attendus, ' . round($ecoule) . ' ms écoulées');
});

test('une file occupée rend la main au lieu de retenir le processus', function () {
    /* Le point qui compte vraiment. Un mutualisé n a qu une poignée de
       processus PHP : les retenir tous dans une file d attente rendrait
       le site ENTIER indisponible, y compris pour qui ne cherche aucune
       couverture. Mieux vaut dire « revenez dans deux secondes ». */
    $verrou = fopen(mangadex_fichier_rythme(), 'c+');
    flock($verrou, LOCK_EX);

    $debut   = microtime(true);
    $attente = mangadex_attendre_son_tour();
    $ecoule  = (microtime(true) - $debut) * 1000;

    flock($verrou, LOCK_UN);
    fclose($verrou);

    vrai($attente > 0, 'un délai est proposé plutôt qu une attente sans fin');
    vrai($ecoule < COUVERTURE_FILE_MAX * 3,
        'la main est rendue vite (' . round($ecoule) . ' ms)');
});

test('le délai proposé est affichable tel quel', function () {
    $verrou = fopen(mangadex_fichier_rythme(), 'c+');
    flock($verrou, LOCK_EX);
    $attente = mangadex_attendre_son_tour();
    flock($verrou, LOCK_UN);
    fclose($verrou);

    /* js/delai.js reçoit ce nombre et le fait descendre à l écran :
       zéro n aurait rien à montrer, et une fraction de seconde non
       plus. */
    vrai(is_int($attente), 'un entier');
    vrai($attente >= 1, 'au moins une seconde');
});

test('le fichier témoin reste hors du site', function () {
    /* Il ne contient qu une date : rien à faire dans une sauvegarde, et
       surtout rien à faire derrière une URL publique. */
    faux(str_contains(str_replace('\\', '/', mangadex_fichier_rythme()),
         str_replace('\\', '/', CHEMIN_PROJET)),
        'en dehors du répertoire du site');
});