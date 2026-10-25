<?php
/* =====================================================================
   Les réglages de l'API : planchers (.env hostile, valeurs trop basses).

   Un « API_REQUETES_MAX=0 » ne doit pas fermer l'API, et un
   « API_ECHECS_MAX=0 » ne doit pas bloquer tout le monde d'emblée.
   Le pendant « valeurs trop hautes » est dans api_config_plafonds_test.php.
   ===================================================================== */

declare(strict_types=1);

putenv('API_REQUETES_MAX=0');
putenv('API_FENETRE=0');
putenv('API_ECHECS_MAX=0');
putenv('API_ECHECS_BLOCAGE=0');

require __DIR__ . '/../lanceur.php';

groupe('Réglages de l\'API — planchers');

test('un .env à zéro est relevé à une valeur utilisable', function () {
    egale(1, API_REQUETES_MAX, 'au moins une requête par tranche');
    egale(10, API_FENETRE, 'tranche d\'au moins 10 secondes');
    egale(1, API_ECHECS_MAX, 'au moins un échec toléré');
    egale(10, API_ECHECS_BLOCAGE, 'blocage d\'au moins 10 secondes');
});
