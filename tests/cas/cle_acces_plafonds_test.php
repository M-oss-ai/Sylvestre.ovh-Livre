<?php
/* =====================================================================
   Les plafonds des réglages des clés d'accès.

   Le pendant « .env trop généreux » de config_planchers_test.php (qui
   impose des valeurs trop basses). Les constantes étant figées au
   chargement, ce scénario vit dans son propre processus.
   ===================================================================== */

declare(strict_types=1);

putenv('CLE_ACCES_MAX=999999');        // un compte n'écrit pas un million de lignes
putenv('CLE_ACCES_DEFI_DUREE=999999'); // un défi oublié ne traîne pas des jours
putenv('ADMIN_SUPPRESSION_DUREE=99999999'); // un lien oublié dans une boîte mail ne reste pas une arme
putenv('CRON_HEURES=99999');           // un cron plus espacé qu'une semaine, .env compris
putenv('RAPPORT_HEURES=99999');
putenv('NOUVEAUTE_MINUTES=99999999');  // une série jamais revérifiée n'annoncerait plus jamais rien
putenv('NOUVEAUTE_PUBLICATION_JOURS=99999'); // une mention jamais relue resterait fausse des années
putenv('NOUVEAUTE_MAX_VISITE=99999');  // pas de plafond : 0 les rend déjà illimitées, un plafond n'aurait aucun sens
putenv('NOUVEAUTE_MAX_CRON=99999');
putenv('PUSH_TTL=99999999');           // les services refusent au-delà de quatre semaines
putenv('PUSH_TIMEOUT=99999');          // un service muet ne retient pas un processus PHP une heure

require __DIR__ . '/../lanceur.php';

groupe('Nouveaux tomes — plafonds');

test('NOUVEAUTE_MINUTES est plafonné à une semaine (10 080 minutes)', function () {
    egale(10080, NOUVEAUTE_MINUTES, 'le .env demandait 99999999 minutes');
});

test('NOUVEAUTE_PUBLICATION_JOURS est plafonné à 90 jours', function () {
    egale(90, NOUVEAUTE_PUBLICATION_JOURS, 'le .env demandait 99999 jours');
});

groupe('Notifications push — plafonds');

test('PUSH_TTL est plafonné à quatre semaines, PUSH_TIMEOUT à 20 secondes', function () {
    egale(2419200, PUSH_TTL, 'quatre semaines : le maximum que les services acceptent');
    egale(20, PUSH_TIMEOUT, 'le .env demandait 99999 secondes');
});

groupe('Nouveaux tomes — plafonds (suite)');

test('NOUVEAUTE_MAX_VISITE et NOUVEAUTE_MAX_CRON ne sont pas plafonnés', function () {
    /* Écrire 0 les rend illimités : borner le haut n'aurait aucun sens. Ce qui
       garde la visite et le cron, c'est le budget de temps du relevé. */
    egale(99999, NOUVEAUTE_MAX_VISITE, 'la valeur du .env, telle quelle');
    egale(99999, NOUVEAUTE_MAX_CRON, 'idem');
});

groupe('Clés d\'accès — plafonds');

test('CLE_ACCES_MAX est plafonné à 50', function () {
    egale(50, CLE_ACCES_MAX, 'le .env demandait un million');
});

test('CLE_ACCES_DEFI_DUREE est plafonné à 15 minutes', function () {
    egale(900, CLE_ACCES_DEFI_DUREE, 'le .env demandait 999999 secondes');
});

groupe('Administration — plafond');

test('ADMIN_SUPPRESSION_DUREE est plafonné à 24 heures', function () {
    egale(86400, ADMIN_SUPPRESSION_DUREE, 'le .env demandait ~3 ans');
});

test('CRON_HEURES est plafonné à une semaine — dans le .env comme dans la page', function () {
    egale(168, CRON_HEURES, 'le .env demandait 99 999 h');
});

test('RAPPORT_HEURES reste plafonné à 30 jours', function () {
    egale(720, RAPPORT_HEURES, 'le .env demandait 99 999 h');
});
