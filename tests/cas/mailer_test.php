<?php
/* =====================================================================
   includes/mailer.php — la partie qui ne parle pas au réseau.

   tests/amorce.php vide SMTP_HOST, SMTP_USER et SMTP_PASSWORD : aucun
   e-mail ne peut partir pendant les tests, envoyer_email_smtp() refusant
   de commencer dès que l un des trois manque.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('url_publique() — l adresse des liens envoyés par e-mail');

test('l adresse vient de APP_URL', function () {
    /* Et JAMAIS de l en-tête Host, qui est choisi par le client. Sinon un
       attaquant demande une réinitialisation pour le compte de quelqu un
       d autre avec « Host: son-serveur » : la victime reçoit un e-mail
       authentique, expédié par ce site, dont le lien pointe chez lui — et
       il récupère le jeton dès qu elle clique. */
    egale('https://exemple.test/bibliotheque/inscription.php',
        url_publique('inscription.php'), 'le lien est construit sur APP_URL');
});

test('l en-tête Host ne peut pas détourner le lien', function () {
    $_SERVER['HTTP_HOST']  = 'serveur-de-l-attaquant.test';
    $_SERVER['SERVER_NAME'] = 'serveur-de-l-attaquant.test';

    $lien = url_publique('reinitialiser-mot-de-passe.php');
    sans('attaquant', $lien, 'le Host falsifié n apparaît nulle part');
    contient('exemple.test', $lien, 'le domaine configuré est utilisé');
});

test('un chemin avec slash initial ne produit pas de double slash', function () {
    egale('https://exemple.test/bibliotheque/verifier-email.php',
        url_publique('/verifier-email.php'), 'le slash en trop est retiré');
});

test('les paramètres de la requête sont conservés', function () {
    $lien = url_publique('verifier-email.php?jeton=' . str_repeat('a', 64));
    contient('?jeton=', $lien, 'la chaîne de requête est intacte');
});

groupe('envoyer_email() — la validation du destinataire');

test('une adresse invalide est refusée sans rien tenter', function () {
    faux(envoyer_email('pas-une-adresse', 'Sujet', 'Corps'), 'aucun envoi');
    faux(envoyer_email('', 'Sujet', 'Corps'), 'une adresse vide');
    faux(envoyer_email('a@', 'Sujet', 'Corps'), 'une adresse tronquée');
});

test('une adresse avec un retour à la ligne est refusée', function () {
    /* La forme classique de l injection d en-têtes : tout ce qui suit le
       retour à la ligne devient un en-tête SMTP, par exemple un « Bcc: »
       vers l attaquant. */
    faux(envoyer_email("victime@exemple.test\r\nBcc: attaquant@exemple.test", 'Sujet', 'Corps'),
        'FILTER_VALIDATE_EMAIL rejette l adresse');
});

groupe('envoyer_email_smtp() — SMTP non configuré');

test('sans configuration SMTP, rien ne part et c est consigné', function () {
    /* Le comportement attendu en développement, et le garde-fou de cette
       suite de tests : tant que SMTP_HOST est vide, aucun octet ne
       quitte la machine. */
    vider_journal_test();
    faux(envoyer_email_smtp('destinataire@exemple.test', 'Sujet', 'Corps'), 'aucun envoi');
    contient('SMTP non configuré', journal_test(), 'l administrateur est prévenu par le journal');
});

groupe('avertir_*() — les avis de sécurité');

test('les trois avis existent et acceptent leurs arguments', function () {
    /* Ils n envoient rien ici (SMTP vide), mais on vérifie qu ils se
       composent sans erreur : ce sont les seuls signaux qu a le titulaire
       légitime quand quelqu un d autre a son mot de passe, et une erreur
       PHP au moment de les composer les ferait disparaître en silence. */
    vider_journal_test();
    avertir_mot_de_passe_change('titulaire@exemple.test', 'lecteur92');
    avertir_compte_supprime('titulaire@exemple.test', 'lecteur92');
    avertir_changement_email_demande('ancienne@exemple.test', 'lecteur92', 'nouvelle@exemple.test');
    vrai(true, 'les trois se composent sans lever d erreur');
});
