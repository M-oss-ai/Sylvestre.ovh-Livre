<?php
/* =====================================================================
   Les réglages de l'API sans rien dans le .env : les valeurs annoncées
   par API.md (60 requêtes par minute, 10 jetons refusés puis 60 s).
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('Réglages de l\'API — valeurs par défaut');

test('les défauts sont ceux de la documentation', function () {
    egale(60, API_REQUETES_MAX, '60 requêtes…');
    egale(60, API_FENETRE, '…par 60 secondes');
    egale(10, API_ECHECS_MAX, '10 jetons refusés…');
    egale(60, API_ECHECS_BLOCAGE, '…puis 60 secondes de blocage');
});
