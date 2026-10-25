<?php
/* =====================================================================
   Juge un mot de passe avec la politique que l'ENVIRONNEMENT du processus
   impose (MDP_MIN, MDP_MAJ, MDP_MINUSCULE, MDP_CHIFFRE, MDP_SPE), et affiche
   ce que la page en dirait au navigateur.

   Lancé en sous-processus par tests/cas/mdp_regles_test.php : les constantes
   sont figées au chargement, chaque politique demande son processus.

       MDP_MAJ=0 php tests/outils/valider-mdp.php "abcdefg1!" [identifiant]
   ===================================================================== */

declare(strict_types=1);

require __DIR__ . '/../amorce.php';

echo '##MDP##', json_encode([
    'erreurs'    => valider_mot_de_passe((string) ($argv[1] ?? ''), (string) ($argv[2] ?? '')),
    'attributs'  => attributs_regles_mdp(),
    'constantes' => [
        'MDP_MIN' => MDP_MIN, 'MDP_MAX' => MDP_MAX,
        'MDP_MAJ' => MDP_MAJ, 'MDP_MINUSCULE' => MDP_MINUSCULE, 'MDP_CHIFFRE' => MDP_CHIFFRE, 'MDP_SPE' => MDP_SPE,
    ],
], JSON_UNESCAPED_UNICODE), "\n";
