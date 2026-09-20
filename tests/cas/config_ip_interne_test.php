<?php
/* =====================================================================
   ip_interne()

   Ne sert jamais à autoriser quoi que ce soit : uniquement à signaler,
   dans le journal et dans « purger.php?ip », qu'une configuration est
   probablement incomplète — PHP voit l'adresse d'un relais au lieu de
   celle du visiteur, et tous les visiteurs partagent alors un seul
   compteur de tentatives.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('ip_interne() — reconnaître une adresse de relais');

test('les plages privées sont reconnues', function () {
    vrai(ip_interne('192.168.1.1'), '192.168.0.0/16');
    vrai(ip_interne('10.0.0.1'), '10.0.0.0/8');
    vrai(ip_interne('172.16.0.1'), '172.16.0.0/12');
});

test('les plages réservées sont reconnues', function () {
    vrai(ip_interne('127.0.0.1'), 'la boucle locale');
    vrai(ip_interne('169.254.1.1'), 'l auto-configuration');
});

test('l adressage partagé des opérateurs (RFC 6598) est reconnu', function () {
    /* 100.64.0.0/10. PHP ne le classe ni en privé ni en réservé, et c'est
       pourtant exactement ce que voit un site placé derrière le
       répartiteur d'un hébergeur mutualisé : sans ce cas particulier,
       l'avertissement ne se déclencherait jamais là où il sert le plus. */
    vrai(ip_interne('100.64.0.1'), 'le début de la plage');
    vrai(ip_interne('100.127.255.254'), 'la fin de la plage');
});

test('les adresses voisines de la plage RFC 6598 restent publiques', function () {
    // Les bornes exactes comptent : un « >= 64 » écrit « > 64 » ou un
    // « <= 127 » écrit « < 127 » ferait passer de vraies adresses pour
    // des relais, ou l'inverse.
    faux(ip_interne('100.63.255.255'), 'juste en dessous de la plage');
    faux(ip_interne('100.128.0.1'), 'juste au-dessus de la plage');
});

test('une adresse publique n est pas signalée', function () {
    faux(ip_interne('8.8.8.8'), 'une adresse publique bien connue');
    faux(ip_interne('203.0.113.5'), 'le bloc de documentation TEST-NET-3');
});

test('une chaîne vide n est pas une adresse interne', function () {
    // Cas d'un REMOTE_ADDR absent : il ne faut pas crier au loup.
    faux(ip_interne(''), 'la chaîne vide');
});

test('une valeur qui n est pas une adresse est traitée comme suspecte', function () {
    /* Le doute profite à l'avertissement, pas au silence : une valeur
       illisible signale elle aussi une configuration qui cloche. */
    vrai(ip_interne('pas-une-ip'), 'une chaîne quelconque');
    vrai(ip_interne('999.999.999.999'), 'une adresse impossible');
});

test('les adresses IPv6 sont classées elles aussi', function () {
    vrai(ip_interne('::1'), 'la boucle locale IPv6');
    vrai(ip_interne('fd00::1'), 'une adresse locale unique');
    faux(ip_interne('2a01:cb00::1'), 'une adresse IPv6 publique');
});
