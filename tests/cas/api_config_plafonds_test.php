<?php
/* =====================================================================
   Les réglages de l'API : plafonds (.env hostile, valeurs trop hautes),
   et valeurs par défaut quand le .env ne dit rien.
   ===================================================================== */

declare(strict_types=1);

putenv('API_REQUETES_MAX=99999999');
putenv('API_FENETRE=99999999');
putenv('API_ECHECS_MAX=99999999');
putenv('API_ECHECS_BLOCAGE=99999999');

require __DIR__ . '/../lanceur.php';

groupe('Réglages de l\'API — plafonds');

test('une valeur démesurée est ramenée à un plafond', function () {
    egale(10000, API_REQUETES_MAX, 'requêtes par tranche');
    egale(3600, API_FENETRE, 'tranche d\'une heure au plus');
    egale(1000, API_ECHECS_MAX, 'échecs tolérés');
    egale(3600, API_ECHECS_BLOCAGE, 'blocage d\'une heure au plus');
});
