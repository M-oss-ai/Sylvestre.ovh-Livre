<?php
/* =====================================================================
   ip_client() — en-tête présent mais inexploitable.

   En-tête tronqué, mal formé, ou rempli de valeurs inventées : on garde
   REMOTE_ADDR. Compter tout le monde ensemble est gênant ; accepter une
   valeur inventée désarmerait complètement le limiteur.
   ===================================================================== */

declare(strict_types=1);

$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'pas-une-adresse';

putenv('IP_ENTETE=X-Forwarded-For');
putenv('IP_PROXY_SAUTS=1');

require __DIR__ . '/../lanceur.php';

groupe('ip_client() — en-tête inexploitable');

test('une valeur qui n est pas une adresse est refusée', function () {
    egale('10.0.0.1', ip_client(), 'on retombe sur REMOTE_ADDR');
});

test('la valeur inventée ne devient jamais une clé de comptage', function () {
    /* Le vrai danger : si une chaîne arbitraire était acceptée, il
       suffirait d'en changer à chaque requête pour n'être jamais compté
       par le limiteur anti force brute. */
    differe('pas-une-adresse', ip_client(), 'la chaîne arbitraire est écartée');
});
