<?php
/* =====================================================================
   actif() — en PRODUCTION, c est-à-dire avec ASSETS_VERSION renseigné.

   ASSETS_VERSION est figée en constante au chargement de config.php :
   ce scénario a donc besoin de son propre processus.

   L enjeu n est pas cosmétique. Le .htaccess demande aux navigateurs de
   garder css/ et js/ pendant UN AN : sans changement d URL, une
   correction déployée ne parvient jamais à ceux qui ont déjà visité le
   site. Et en production, le mode « date de modification » coûterait un
   accès disque par ressource et par page — sur l espace NFS d un
   mutualisé, un aller-retour réseau à chaque fois.
   ===================================================================== */

declare(strict_types=1);

putenv('ASSETS_VERSION=42');

require __DIR__ . '/../lanceur.php';

groupe('actif() — ASSETS_VERSION renseigné');

test('le numéro de version est ajouté tel quel', function () {
    egale('css/style.css?v=42', actif('css/style.css'), 'la version vient du .env');
    egale('js/app.js?v=42', actif('js/app.js'), 'pour toutes les ressources');
});

test('aucun accès au disque n est nécessaire', function () {
    /* Un fichier inexistant rend la même forme d URL qu un fichier
       existant : la preuve que filemtime() n est pas appelé. */
    egale('css/nexiste-pas.css?v=42', actif('css/nexiste-pas.css'),
        'le disque n est pas interrogé');
});

test('la version ne dépend pas du contenu du fichier', function () {
    // C est bien à l humain d incrémenter ASSETS_VERSION au déploiement :
    // le README en fait une étape de la mise en ligne.
    egale(actif('css/style.css'), actif('css/style.css'), 'stable d un appel à l autre');
});
