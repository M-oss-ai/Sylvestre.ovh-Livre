<?php
/* =====================================================================
   Affiche la page des mentions légales, telle qu'un visiteur la reçoit.

   Lancé en sous-processus par tests/cas/fonctions_flash_duree_test.php,
   avec SESSION_DUREE et REMEMBER_DUREE_VIP imposés dans l'environnement :
   ces réglages deviennent des constantes au chargement, chaque valeur
   testée demande donc son propre processus.

       SESSION_DUREE=31536000 php tests/outils/afficher-mentions-legales.php
   ===================================================================== */

declare(strict_types=1);

require __DIR__ . '/../amorce.php';

require CHEMIN_SITE . '/mentions-legales.php';
