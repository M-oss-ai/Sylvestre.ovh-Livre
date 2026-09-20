<?php
/* =====================================================================
   journal_securite()

   Sans cette trace, le jour où un compte est compromis, il n y a
   strictement aucun moyen de reconstituer ce qui s est passé. On note
   quoi, qui et d où — jamais le mot de passe ni le jeton concerné.

   tests/amorce.php détourne error_log() vers un fichier jetable : ces
   tests le relisent. C est la seule façon de vérifier ce qui est
   réellement consigné.
   ===================================================================== */

declare(strict_types=1);

/* Une adresse de relais, pour déclencher l avertissement de configuration
   incomplète vérifié plus bas. */
$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
putenv('IP_ENTETE=');

require __DIR__ . '/../lanceur.php';

groupe('journal_securite() — la forme d une entrée');

test('l évènement est préfixé pour être retrouvable', function () {
    /* Le journal de l hébergeur mêle tout : sans préfixe, on ne peut pas
       isoler les évènements de sécurité. Chez OVH : Hébergements →
       Statistiques et logs. */
    vider_journal_test();
    journal_securite('connexion_reussie');
    contient('[securite] connexion_reussie', journal_test(), 'le préfixe et le nom de l évènement');
});

test('l adresse du client est toujours notée', function () {
    vider_journal_test();
    journal_securite('connexion_echouee');
    contient('ip=10.0.0.1', journal_test(), 'l adresse figure dans l entrée');
});

test('les détails sont ajoutés en clé=valeur', function () {
    vider_journal_test();
    journal_securite('mot_de_passe_change', ['utilisateur' => 42, 'identifiant' => 'lecteur']);
    $journal = journal_test();
    contient('utilisateur=42', $journal, 'le premier détail');
    contient('identifiant=lecteur', $journal, 'le second');
});

test('une entrée sans détail reste valide', function () {
    vider_journal_test();
    journal_securite('deconnexion');
    contient('deconnexion', journal_test(), 'l évènement est consigné');
});

groupe('journal_securite() — on ne peut pas fabriquer de fausse ligne');

test('un retour à la ligne dans une donnée est neutralisé', function () {
    /* Sans ce remplacement, une valeur contrôlée par l utilisateur — un
       identifiant, une adresse — permettrait d écrire ce qu on veut dans
       le journal : une fausse entrée « connexion_reussie » pour brouiller
       une enquête, par exemple. */
    vider_journal_test();
    journal_securite('connexion_echouee', [
        'identifiant' => "victime\n[securite] connexion_reussie ip=1.2.3.4",
    ]);

    $journal = journal_test();
    sans("\n[securite] connexion_reussie", $journal, 'aucune ligne supplémentaire n a été créée');
    contient('connexion_reussie', $journal, 'le texte est là, mais sur la même ligne');
});

test('un retour chariot seul est neutralisé aussi', function () {
    // \r suffit à certains lecteurs de journaux pour couper une ligne.
    vider_journal_test();
    journal_securite('test_cr', ['donnee' => "avant\rapres"]);
    sans("\rapres", journal_test(), 'le retour chariot est remplacé');
});

groupe('journal_securite() — l avertissement de configuration');

test('une adresse interne sans IP_ENTETE déclenche une alerte', function () {
    /* Le signal d alarme : PHP voit l adresse d un relais et aucun
       en-tête n est configuré pour retrouver celle du visiteur. Tous les
       visiteurs partagent alors UN SEUL compteur — cinq mauvais mots de
       passe bloquent la connexion pour tout le site.

       Noté ici plutôt qu à chaque requête : les évènements de sécurité
       sont rares, et c est ce journal qu on lit le jour où ça cloche. */
    vider_journal_test();
    journal_securite('connexion_echouee');
    contient('ATTENTION=adresse_interne', journal_test(), 'la configuration est signalée');
    contient('IP_ENTETE', journal_test(), 'et le message dit quoi corriger');
});
