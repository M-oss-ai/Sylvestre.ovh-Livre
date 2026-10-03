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

putenv('COUVERTURE_TIMEOUT=0');          // sans délai, l appel ne partirait jamais
putenv('COUVERTURE_MAX_SERIES=0');       // zéro série : la recherche n aurait aucun sens
putenv('COUVERTURE_CANDIDATS=0');        // examiner zéro série pour en retenir une
putenv('COUVERTURE_ESPACEMENT=0');       // aucun espacement : la file ne freine plus rien
putenv('COUVERTURE_FILE_MAX=0');         // aucune attente tolérée : la file refuse tout
putenv('COUVERTURE_QUOTA=0');            // 0 = pas de quota (voulu, voir config.php)
putenv('COUVERTURE_QUOTA_ILLIMITE=50');   // un plafond pour l illimité… sans objet si le standard n en a pas
putenv('COUVERTURE_FENETRE=0');          // tranche nulle : la règle n aurait plus de durée

require __DIR__ . '/../lanceur.php';
require_once CHEMIN_SITE . '/includes/couvertures.php';

groupe('Recherche de couverture — les planchers');

test('COUVERTURE_TIMEOUT ne descend pas à zéro', function () {
    /* Un délai nul signifierait « attendre indéfiniment » pour cURL :
       exactement le contraire de l intention, et un processus PHP
       immobilisé sans limite. Un mutualisé n en a qu une poignée. */
    egale(1, COUVERTURE_TIMEOUT, 'le .env demandait 0, relevé à 1 seconde');
    vrai(COUVERTURE_TIMEOUT >= 1, 'jamais nul');
});

test('COUVERTURE_MAX_SERIES à zéro veut dire « sans limite »', function () {
    /* Contrairement aux autres réglages, zéro n est pas ici une valeur
       absurde à relever : c est une intention, « ne cache rien ». Le
       coût, lui, reste borné par COUVERTURE_CANDIDATS. */
    egale(0, COUVERTURE_MAX_SERIES, 'zéro est conservé tel quel');
});

test('on n examine jamais moins de séries qu on n en retient', function () {
    /* Retenir quatre séries parmi deux examinées n a pas de sens.
       Le vivier ne coûte rien — c est le même appel avec une limite
       plus haute —, il n y a donc aucune raison de le laisser
       descendre sous le nombre de résultats affichés. */
    vrai(COUVERTURE_CANDIDATS >= 1, 'au moins une série examinée');
    egale(1, COUVERTURE_CANDIDATS, 'le .env demandait 0, relevé au minimum utile');
});

test('la cadence des appels sortants ne peut pas être supprimée', function () {
    /* C est le seul garde-fou entre le site et le blocage de l adresse
       du SERVEUR par MangaDex — blocage qui priverait TOUS les comptes
       de la recherche, pas seulement celui qui a insisté. */
    egale(100, COUVERTURE_ESPACEMENT, 'le .env demandait 0 ms entre deux appels');
    egale(200, COUVERTURE_FILE_MAX, 'le .env ne tolérait aucune attente');
});

test('un quota à 0 veut dire « sans limite », pas « aucune recherche »', function () {
    /* L exception voulue à la règle des planchers : ce quota répartit
       l usage entre comptes, il ne protège pas le serveur — la file
       d attente, testée plus haut, s en charge et garde son plancher. */
    egale(0, COUVERTURE_QUOTA, 'le standard n a plus de quota');
    egale(0, couverture_quota(['forfait' => 'standard']), 'rien n est décompté');
    egale(0, COUVERTURE_QUOTA_ILLIMITE, 'et l illimité n est jamais plus bridé que le standard');
    egale(10, COUVERTURE_FENETRE, 'le .env demandait une tranche de 0 seconde');
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
