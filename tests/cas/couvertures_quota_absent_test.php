<?php
/* =====================================================================
   COUVERTURE_QUOTA et COUVERTURE_QUOTA_ILLIMITE absents du .env.

   Une ligne commentée ou vide doit donner des recherches SANS LIMITE,
   pas un quota par défaut caché dans le code : c'est ce que l'on
   attend en retirant la ligne. Ces réglages deviennent des constantes
   au chargement, d'où ce processus à part.
   ===================================================================== */

declare(strict_types=1);

// Vide = absent pour env() (voir config.php), et charger_env() ne
// remplace pas une variable déjà posée : le .env local ne s'en mêle pas.
putenv('COUVERTURE_QUOTA=');
putenv('COUVERTURE_QUOTA_ILLIMITE=');

require __DIR__ . '/../lanceur.php';
require_once CHEMIN_PROJET . '/includes/couvertures.php';

groupe('Recherche de couverture — quotas absents du .env');

test('sans ligne, pas de quota, pour aucun forfait', function () {
    egale(0, COUVERTURE_QUOTA, 'standard');
    egale(0, COUVERTURE_QUOTA_ILLIMITE, 'illimité');
    egale(0, couverture_quota(['forfait' => 'standard']), 'rien n est décompté');
    egale(0, couverture_quota(['forfait' => 'illimite']), 'ni pour l illimité');
});

test('la file d attente, elle, ne disparaît jamais', function () {
    vrai(COUVERTURE_ESPACEMENT >= 100, 'les appels restent espacés');
});
