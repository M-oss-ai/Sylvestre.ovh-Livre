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

require __DIR__ . '/../lanceur.php';

groupe('Clés d\'accès — plafonds');

test('CLE_ACCES_MAX est plafonné à 50', function () {
    egale(50, CLE_ACCES_MAX, 'le .env demandait un million');
});

test('CLE_ACCES_DEFI_DUREE est plafonné à 15 minutes', function () {
    egale(900, CLE_ACCES_DEFI_DUREE, 'le .env demandait 999999 secondes');
});
