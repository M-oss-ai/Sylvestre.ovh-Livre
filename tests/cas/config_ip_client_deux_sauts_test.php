<?php
/* =====================================================================
   ip_client() — deux relais de confiance (un CDN devant le répartiteur).
   ===================================================================== */

declare(strict_types=1);

$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
/* De gauche à droite : ce que le client a inventé, sa vraie adresse vue
   par le CDN, puis le CDN vu par le répartiteur. Avec deux relais de
   confiance, la bonne valeur est l'avant-dernière. */
$_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 198.51.100.7, 192.0.2.50';

putenv('IP_ENTETE=X-Forwarded-For');
putenv('IP_PROXY_SAUTS=2');

require __DIR__ . '/../lanceur.php';

groupe('ip_client() — deux relais de confiance');

test('on remonte d autant de crans qu il y a de relais', function () {
    egale('198.51.100.7', ip_client(), 'l avant-dernière entrée');
});

test('IP_PROXY_SAUTS ne peut pas descendre sous 1', function () {
    // Un « 0 » ou une ligne vide dans le .env donnerait un décalage hors
    // du tableau, donc un repli permanent sur REMOTE_ADDR.
    vrai(IP_PROXY_SAUTS >= 1, 'le plancher est appliqué');
    egale(2, IP_PROXY_SAUTS, 'la valeur demandée est respectée');
});
