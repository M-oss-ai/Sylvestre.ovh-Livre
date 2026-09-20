<?php
/* =====================================================================
   Les réglages de la recherche de couverture, face à un .env hostile.

   Ces valeurs ne protègent pas le site : elles protègent l ADRESSE IP
   DU SERVEUR. Les appels à MangaDex partent de l hébergement, pas du
   navigateur du visiteur ; c est donc l hébergement que MangaDex
   bloquerait si quelqu un s acharnait. Un « 0 » dans le .env ne doit
   pas pouvoir supprimer ce frein.

   Comme config_planchers_test.php, ce fichier impose des valeurs
   absurdes et vérifie qu elles sont relevées. Les constantes étant
   figées au chargement, il lui faut son propre processus.
   ===================================================================== */

declare(strict_types=1);

putenv('COUVERTURE_TIMEOUT=0');        // sans délai, l appel ne partirait jamais
putenv('COUVERTURE_MAX_SERIES=0');     // zéro série : la recherche n aurait aucun sens
putenv('COUVERTURE_MAX=0');            // zéro recherche : fonctionnalité morte
putenv('COUVERTURE_BLOCAGE=0');        // blocage nul : le frein ne freine plus

require __DIR__ . '/../lanceur.php';

groupe('Recherche de couverture — les planchers');

test('COUVERTURE_TIMEOUT ne descend pas à zéro', function () {
    /* Un délai nul signifierait « attendre indéfiniment » pour cURL :
       exactement le contraire de l intention, et un processus PHP
       immobilisé sans limite. Un mutualisé n en a qu une poignée. */
    egale(1, COUVERTURE_TIMEOUT, 'le .env demandait 0, relevé à 1 seconde');
    vrai(COUVERTURE_TIMEOUT >= 1, 'jamais nul');
});

test('COUVERTURE_MAX_SERIES reste entre 1 et 10', function () {
    egale(1, COUVERTURE_MAX_SERIES, 'le .env demandait 0');
});

test('le frein par IP ne peut pas être supprimé', function () {
    /* C est le seul garde-fou entre un utilisateur pressé et le blocage
       de l adresse du serveur par MangaDex — blocage qui priverait
       TOUS les comptes de la recherche. */
    egale(1, COUVERTURE_MAX, 'le .env demandait 0 recherche autorisée');
    egale(1, COUVERTURE_BLOCAGE, 'le .env demandait un blocage de 0 seconde');
});

groupe('COUVERTURE_CONTENU_ADULTE — le réglage qui engage l éditeur');

test('la valeur par défaut bloque', function () {
    /* Le .env de ce test ne définit pas la clé : c est le défaut du code
       qui s applique, et il doit être restrictif. Ouvrir le contenu
       adulte par omission serait exactement le genre d oubli qui engage
       la responsabilité de l éditeur du site. */
    faux(COUVERTURE_CONTENU_ADULTE, 'absent du .env = bloqué');
});

test('la constante est un booléen, pas une chaîne', function () {
    /* Elle est comparée avec && dans api.php : une chaîne « 0 » y serait
       vraie, et ouvrirait le contenu adulte en croyant le fermer. */
    vrai(is_bool(COUVERTURE_CONTENU_ADULTE), 'un vrai booléen');
});
