<?php
/* =====================================================================
   « Continuer avec Google » sans réglage : la fonction n'existe pas.

   Tant que GOOGLE_CLIENT_ID et GOOGLE_CLIENT_SECRET ne sont pas remplis,
   aucun bouton n'apparaît et google.php répond 404 : proposer une
   connexion qui ne peut pas aboutir ne ferait qu'égarer.
   ===================================================================== */

declare(strict_types=1);

// Vides = absents pour env() ; le .env local ne les remplace pas.
putenv('GOOGLE_CLIENT_ID=');
putenv('GOOGLE_CLIENT_SECRET=');

require __DIR__ . '/../lanceur.php';
require_once CHEMIN_PROJET . '/includes/google.php';

groupe('Google non configuré');

test('ni actif, ni bouton', function () {
    faux(google_actif(), 'inactif');
    egale('', bouton_google(), 'aucun bouton');
    egale(600, GOOGLE_CONFIRMATION_DUREE, 'dix minutes de confirmation par défaut');
});
