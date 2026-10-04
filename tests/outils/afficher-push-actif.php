<?php
/* =====================================================================
   Affiche ce que push_actif() répond pour l'environnement reçu.

   Lancé en sous-processus par tests/cas/push_test.php : VAPID_PUBLIC et
   VAPID_PRIVATE deviennent des constantes au chargement de config.php, donc
   chaque scénario de clés (absentes, mal formées, une seule) demande son
   processus.
   ===================================================================== */

declare(strict_types=1);

require __DIR__ . '/../amorce.php';
require_once CHEMIN_SITE . '/includes/push.php';

echo 'ACTIF=' . (push_actif() ? 'oui' : 'non') . "\n";
