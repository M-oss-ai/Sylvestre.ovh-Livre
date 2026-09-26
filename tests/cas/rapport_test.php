<?php
/* =====================================================================
   Le rapport du cron : quand il part, et ce qu'il annonce couvrir.

   Le cron passe chaque jour (il rejoue les e-mails bloqués), mais le
   rapport détaillé ne part que tous les RAPPORT_JOURS jours et couvre
   cette période. Deux façons de se tromper :

     - le mauvais jour, ou deux jours de suite : un rapport hebdomadaire
       qui manque sa semaine, ou qui en compte deux ;
     - une période annoncée qui n'est pas celle comptée : « 7 derniers
       jours » en titre sur des chiffres de 24 h.

   Ce fichier impose aussi un .env déraisonnable (365 jours) : les
   compteurs de tentatives n'étant gardés que 30 jours, la rubrique
   SÉCURITÉ annoncerait une année qu'elle ne couvre pas.
   ===================================================================== */

declare(strict_types=1);

putenv('RAPPORT_JOURS=365');

require __DIR__ . '/../lanceur.php';

groupe('RAPPORT_JOURS — le plafond');

test('une période plus longue que la mémoire des tentatives est ramenée à 30', function () {
    egale(30, RAPPORT_JOURS, 'le .env demandait 365 ; tentative_ip ne garde que 30 jours');
});

groupe('rapport_periode() — ce que le rapport annonce');

test('un jour reste « 24 h », comme le rapport quotidien d avant', function () {
    egale(['24 h', '24 dernières heures'], rapport_periode(1), 'libellé court, puis titre de rubrique');
});

test('une semaine s annonce en jours', function () {
    egale(['7 jours', '7 derniers jours'], rapport_periode(7), 'libellé court, puis titre de rubrique');
});

test('trente jours aussi', function () {
    egale(['30 jours', '30 derniers jours'], rapport_periode(30), 'libellé court, puis titre de rubrique');
});

groupe('rapport_jour_prevu() — quel jour il part');

test('réglé sur 1, il part tous les jours', function () {
    foreach (['2026-09-26', '2026-09-27', '2026-09-28', '2027-02-28'] as $date) {
        vrai(rapport_jour_prevu($date, 1), $date);
    }
});

test('réglé sur 7, il part le lundi', function () {
    vrai(rapport_jour_prevu('2026-09-28', 7), 'lundi 28 septembre 2026');
    vrai(rapport_jour_prevu('2026-10-05', 7), 'lundi suivant');
    vrai(rapport_jour_prevu('1970-01-05', 7), 'le lundi de référence lui-même');
});

test('réglé sur 7, jamais un autre jour', function () {
    foreach (['2026-09-26', '2026-09-27', '2026-09-29', '2026-09-30',
              '2026-10-01', '2026-10-02', '2026-10-03'] as $date) {
        faux(rapport_jour_prevu($date, 7), $date);
    }
});

test('exactement un rapport par période, quelle qu elle soit', function () {
    /* Le cœur de la règle : sur N jours consécutifs, pris n'importe où
       (y compris à cheval sur un changement d'année ou un 29 février),
       il part UNE fois. Ni semaine sautée, ni semaine comptée deux fois. */
    foreach ([2, 3, 7, 14, 30] as $n) {
        foreach (['2026-09-20', '2027-12-25', '2028-02-20'] as $depart) {
            $jour = new DateTimeImmutable($depart);
            $envois = 0;
            for ($i = 0; $i < $n; $i++) {
                $envois += rapport_jour_prevu($jour->modify("+$i day")->format('Y-m-d'), $n) ? 1 : 0;
            }
            egale(1, $envois, "$n jours à partir du $depart");
        }
    }
});

test('une date illisible déclenche l envoi plutôt que le silence', function () {
    /* Un silence se lit « tout va bien ». Un rapport de trop se lit
       « tiens, un rapport ». Le second est le moins dangereux. */
    vrai(rapport_jour_prevu('', 7), 'vide');
    vrai(rapport_jour_prevu('pas-une-date', 7), 'texte');
    vrai(rapport_jour_prevu('2026-02-30', 7), '30 février');
});
