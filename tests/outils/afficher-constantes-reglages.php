<?php
/* =====================================================================
   Impose, à CHAQUE réglage modifiable, sa borne basse ou sa borne haute
   (celles de includes/reglages.php), puis affiche la constante que
   config.php en tire.

   Lancé en sous-processus par tests/cas/reglages_test.php : les constantes
   sont figées au chargement, chaque scénario demande son processus.

       php tests/outils/afficher-constantes-reglages.php min
       php tests/outils/afficher-constantes-reglages.php max

   Ce que cela mesure : les bornes de la page se trouvent DANS celles du
   code. Si config.php relevait ou abaissait une valeur que la page
   autorise, l'administrateur croirait avoir réglé 5 et obtiendrait 8.
   ===================================================================== */

declare(strict_types=1);

/* Les descriptifs d'abord : ils ne dépendent de rien, et il faut les
   connaître avant l'amorce pour poser l'environnement. */
require dirname(__DIR__, 2) . '/site/includes/reglages.php';

$borne = ($argv[1] ?? 'min') === 'max' ? 'max' : 'min';
foreach (REGLAGES as $cle => $r) {
    putenv($cle . '=' . $r[$borne]);
}

require __DIR__ . '/../amorce.php';

$constantes = [];
foreach (array_keys(REGLAGES) as $cle) {
    $v = constant($cle);
    $constantes[$cle] = is_bool($v) ? (int) $v : $v;
}
echo '##CONSTANTES##', json_encode($constantes), "\n";
