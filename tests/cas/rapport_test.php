<?php
/* =====================================================================
   Le rapport du cron : quand il part, et ce qu'il annonce couvrir.

   Le cron passe toutes les CRON_HEURES heures (il rejoue les e-mails
   bloqués), mais le rapport détaillé ne part que toutes les
   RAPPORT_HEURES heures, et couvre le temps écoulé depuis le précédent.
   Deux façons de se tromper :

     - sauter ou doubler un rapport parce que l'hébergeur lance le cron
       « dans l'heure », tantôt à 3 h 02, tantôt à 3 h 58 ;
     - annoncer une durée qui n'est pas celle comptée.

   Ce fichier impose aussi un .env déraisonnable : un rapport tous les
   100 000 heures. Les compteurs de tentatives n'étant gardés que 30
   jours, la rubrique SÉCURITÉ annoncerait des mois qu'elle ne couvre pas.
   ===================================================================== */

declare(strict_types=1);

putenv('CRON_HEURES=48');
putenv('RAPPORT_HEURES=100000');

require __DIR__ . '/../lanceur.php';

groupe('CRON_HEURES et RAPPORT_HEURES — les bornes');

test('les constantes sont bornées au chargement', function () {
    egale(48, CRON_HEURES, 'valeur du .env, dans les bornes');
    egale(720, RAPPORT_HEURES, 'le .env demandait 100 000 h ; tentative_ip ne garde que 30 jours');
});

test('cron_reglages() : les cas courants passent tels quels', function () {
    egale([24, 168], cron_reglages(24, 168), 'cron quotidien, rapport hebdomadaire');
    egale([1, 24], cron_reglages(1, 24), 'cron horaire, rapport quotidien');
});

test('un rapport ne part pas plus souvent que le cron ne passe', function () {
    egale([24, 24], cron_reglages(24, 12), 'demander 12 h avec un cron de 24 h donne 24 h');
    egale([24, 24], cron_reglages(24, 0), '0 = à chaque passage');
});

test('les valeurs absurdes sont ramenées dans [1, 720]', function () {
    egale([1, 1], cron_reglages(0, 0), 'zéro');
    egale([1, 50], cron_reglages(-5, 50), 'négatif');
    egale([720, 720], cron_reglages(1000, 1000), 'au-delà de 30 jours');
});

groupe('rapport_du() — quand il part');

test('le tout premier rapport part tout de suite', function () {
    vrai(rapport_du(null, 168, 24), 'aucun rapport encore envoyé');
});

test('hebdomadaire avec un cron quotidien : pas avant la semaine', function () {
    faux(rapport_du(144 * 60, 168, 24), 'six jours');
    faux(rapport_du(156 * 60 - 1, 168, 24), 'juste avant la marge');
    vrai(rapport_du(156 * 60, 168, 24), 'marge d une demi-journée atteinte');
    vrai(rapport_du(168 * 60, 168, 24), 'sept jours');
    vrai(rapport_du(167 * 60 + 1, 168, 24), 'passage en avance de 59 min');
});

test('à chaque passage : un second lancement le même jour ne renvoie rien', function () {
    vrai(rapport_du(24 * 60 - 58, 24, 24), 'passage du lendemain, en avance');
    faux(rapport_du(3 * 60, 24, 24), 'relancé à la main trois heures après');
});

test('dix semaines de cron « dans l heure » : dix rapports, jamais deux', function () {
    /* Le cœur de la règle. Un passage par jour à 3 h, décalé de 0 à 59
       minutes selon le jour, comme chez OVH. Le rapport doit partir une
       fois par semaine exactement, à sept jours d'écart (à l'heure près). */
    foreach ([[24, 168], [1, 168], [24, 24], [12, 72]] as [$cron, $rapport]) {
        $decalages = [0, 59, 3, 41, 58, 1, 30, 17, 44, 9];
        $dernier = null;
        $envois = [];
        $passages = intdiv(10 * $rapport, $cron);
        for ($i = 0; $i < $passages; $i++) {
            $maintenant = $i * $cron * 60 + $decalages[$i % count($decalages)];
            $ecoule = $dernier === null ? null : $maintenant - $dernier;
            if (rapport_du($ecoule, $rapport, $cron)) {
                $envois[] = $maintenant;
                $dernier = $maintenant;
            }
        }
        egale(10, count($envois), "cron $cron h, rapport $rapport h : nombre de rapports");
        for ($k = 1; $k < count($envois); $k++) {
            $ecart = $envois[$k] - $envois[$k - 1];
            vrai(abs($ecart - $rapport * 60) < 60,
                 "cron $cron h, rapport $rapport h : écart de $ecart min entre deux rapports");
        }
    }
});

groupe('rapport_fenetre_minutes() — la période couverte');

test('sans rapport précédent : la période réglée', function () {
    egale(168 * 60, rapport_fenetre_minutes(null, 168), 'premier rapport');
});

test('sinon : le temps écoulé depuis le dernier rapport', function () {
    egale(10020, rapport_fenetre_minutes(10020, 168), '6 j 23 h');
});

test('bornée entre une heure et 720 heures', function () {
    egale(60, rapport_fenetre_minutes(5, 24), 'cinq minutes');
    egale(60, rapport_fenetre_minutes(-30, 24), 'horloge revenue en arrière');
    egale(720 * 60, rapport_fenetre_minutes(90 * 24 * 60, 168), 'cron arrêté trois mois');
});

groupe('duree_lisible() — ce que le mail affiche');

test('de 1 à 47 : en heures', function () {
    egale('1 heure', duree_lisible(1), 'singulier');
    egale('2 heures', duree_lisible(2), 'pluriel');
    egale('24 heures', duree_lisible(24), 'un jour s affiche encore en heures');
    egale('47 heures', duree_lisible(47), 'dernière valeur en heures');
});

test('à partir de 48 : en jours, et les heures qui restent', function () {
    egale('2 jours', duree_lisible(48), 'jours ronds');
    egale('7 jours', duree_lisible(168), 'une semaine');
    egale('7 j et 5 heures', duree_lisible(173), 'avec un reste');
    egale('7 j et 1 heure', duree_lisible(169), 'reste au singulier');
    egale('30 jours', duree_lisible(720), 'le plafond');
});

test('jamais « 0 heure »', function () {
    egale('1 heure', duree_lisible(0), 'zéro');
    egale('1 heure', duree_lisible(-4), 'négatif');
});
