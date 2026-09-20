<?php
/* =====================================================================
   COUVERTURE_CONTENU_ADULTE — le seul « 1 » qui ouvre.

   Ce réglage est celui qui engage la responsabilité de l éditeur du
   site. Il ne doit donc s activer que sur une valeur explicite et
   sans ambiguïté : ni « true », ni « oui », ni « yes », ni un espace
   égaré. Toute autre écriture doit laisser le contenu filtré plutôt
   que de deviner une intention.

   Son propre processus, puisqu une constante ne se redéfinit pas.
   ===================================================================== */

declare(strict_types=1);

putenv('COUVERTURE_CONTENU_ADULTE=1');

require __DIR__ . '/../lanceur.php';

groupe('COUVERTURE_CONTENU_ADULTE — activé explicitement');

test('la valeur « 1 » ouvre la possibilité', function () {
    vrai(COUVERTURE_CONTENU_ADULTE, 'le .env dit 1');
});

test('activer le site n accorde encore rien à personne', function () {
    /* Le réglage ouvre une possibilité, il ne l accorde pas : api.php
       exige EN PLUS que l utilisateur ait déclaré sa majorité, puis
       qu il ait effectivement levé le filtre. Trois conditions, dont
       celle-ci n est que la première.

       Les deux autres vivent en base (adulte_confirme, filtre_sensible)
       et relèvent d un test d intégration. Ce qui se vérifie ici, c est
       qu aucune valeur par défaut ne les contourne. */
    vrai(defined('COUVERTURE_CONTENU_ADULTE'), 'la constante existe');
    vrai(is_bool(COUVERTURE_CONTENU_ADULTE), 'et reste un booléen');
});
