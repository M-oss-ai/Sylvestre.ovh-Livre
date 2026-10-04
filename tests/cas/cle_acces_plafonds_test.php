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
putenv('NOUVEAUTE_HEURES=99999');      // une série jamais revérifiée n'annoncerait plus jamais rien
putenv('NOUVEAUTE_MAX_VISITE=99999');  // une arrivée sur la page ne lance pas des centaines d'appels
putenv('NOUVEAUTE_MAX_CRON=99999');    // ni un passage du cron : il tient dans le temps que PHP lui laisse
putenv('PUSH_MAX_APPAREILS=99999');    // un compte n'inscrit pas des milliers d'appareils : autant d'envois à chaque passage
putenv('PUSH_TTL=99999999');           // les services refusent au-delà de quatre semaines
putenv('PUSH_TIMEOUT=99999');          // un service muet ne retient pas un processus PHP une heure

require __DIR__ . '/../lanceur.php';

groupe('Nouveaux tomes — plafonds');

test('NOUVEAUTE_HEURES est plafonné à une semaine', function () {
    egale(168, NOUVEAUTE_HEURES, 'le .env demandait 99999 heures');
});

groupe('Notifications push — plafonds');

test('PUSH_MAX_APPAREILS est plafonné à 50, PUSH_TTL à quatre semaines, PUSH_TIMEOUT à 20 secondes', function () {
    egale(50, PUSH_MAX_APPAREILS, 'le .env demandait 99999');
    egale(2419200, PUSH_TTL, 'quatre semaines : le maximum que les services acceptent');
    egale(20, PUSH_TIMEOUT, 'le .env demandait 99999 secondes');
});

groupe('Nouveaux tomes — plafonds (suite)');

test('NOUVEAUTE_MAX_VISITE est plafonné à 20, NOUVEAUTE_MAX_CRON à 500', function () {
    egale(20, NOUVEAUTE_MAX_VISITE, 'le .env demandait 99999');
    egale(500, NOUVEAUTE_MAX_CRON, 'le .env demandait 99999');
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
