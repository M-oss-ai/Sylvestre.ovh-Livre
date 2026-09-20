<?php
/* =====================================================================
   ip_client() — DERRIÈRE un répartiteur (le cas OVH habituel).
   ===================================================================== */

declare(strict_types=1);

// REMOTE_ADDR est celle du répartiteur : une adresse privée.
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';

/* Chaque relais ajoute à droite l'adresse de celui qui lui a parlé. Les
   entrées de GAUCHE viennent donc du client, et sont librement inventées :
   ici « 9.9.9.9 » est la valeur que l'attaquant a mise lui-même. */
$_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 198.51.100.7';

putenv('IP_ENTETE=X-Forwarded-For');
putenv('IP_PROXY_SAUTS=1');

require __DIR__ . '/../lanceur.php';

groupe('ip_client() — un répartiteur de confiance');

test('la lecture part de la DROITE, pas de la gauche', function () {
    /* Prendre la première entrée — l'erreur la plus répandue — revient à
       laisser l'attaquant choisir son adresse, et donc son compteur. */
    egale('198.51.100.7', ip_client(), 'la dernière entrée, écrite par NOTRE répartiteur');
});

test('la valeur inventée par le client est écartée', function () {
    differe('9.9.9.9', ip_client(), 'la partie gauche de l en-tête ne sert pas');
});

test('REMOTE_ADDR du répartiteur n est plus utilisée', function () {
    // Sinon tous les visiteurs partageraient un unique compteur : cinq
    // mauvais mots de passe bloqueraient la connexion pour tout le site.
    differe('10.0.0.1', ip_client(), 'l adresse du relais est remplacée');
});
