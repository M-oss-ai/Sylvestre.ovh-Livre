<?php
/* =====================================================================
   ip_client() — regroupement des adresses IPv6 sur leur /64.
   ===================================================================== */

declare(strict_types=1);

$_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678:9abc:def0:1111:2222';
putenv('IP_ENTETE=');

require __DIR__ . '/../lanceur.php';

groupe('ip_client() — IPv6');

test('une adresse IPv6 est ramenée à son préfixe /64', function () {
    /* En IPv4, une adresse = un client. En IPv6, non : le moindre serveur
       loué reçoit un bloc /64, soit 18 milliards de milliards d'adresses.
       En changeant les 64 derniers bits à chaque requête, chaque tentative
       passait pour un nouveau visiteur et le blocage ne se déclenchait
       jamais. */
    egale('2001:db8:1234:5678::/64', ip_client(), 'les 64 bits d interface sont effacés');
});

test('les 64 bits de poids fort sont conservés intacts', function () {
    // Sinon deux abonnés différents partageraient un compteur.
    contient('2001:db8:1234:5678', ip_client(), 'le préfixe du réseau est préservé');
});
