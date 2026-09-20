<?php
/* =====================================================================
   envoyer_email_smtp() — le dernier rempart avant le réseau.

   Cette fonction revalide le destinataire et le sujet alors que ses
   appelants l ont déjà fait. Ce n est pas une redondance inutile : elle
   est aussi appelée par traiter_file_mail(), qui dépile des messages
   écrits en base — donc par un chemin où la validation d origine est
   loin derrière.

   Pour atteindre ce contrôle, il faut que SMTP soit considéré comme
   CONFIGURÉ : sinon la fonction s arrête une étape plus tôt et le test
   ne prouverait rien. On désigne donc un port fermé de la boucle locale.
   Aucune connexion n est censée être tentée — c est précisément ce que
   ces tests vérifient — et si elle l était, elle serait refusée
   immédiatement, sans quitter la machine.
   ===================================================================== */

declare(strict_types=1);

putenv('SMTP_HOST=127.0.0.1');
putenv('SMTP_PORT=1');
putenv('SMTP_USER=utilisateur-de-test');
putenv('SMTP_PASSWORD=mot-de-passe-de-test');
putenv('SMTP_TIMEOUT=1');

require __DIR__ . '/../lanceur.php';

groupe('envoyer_email_smtp() — injection d en-têtes');

test('la configuration de test atteint bien le contrôle visé', function () {
    /* Garde-fou du garde-fou : si SMTP passait pour non configuré, tous
       les tests ci-dessous rendraient « false » pour la mauvaise raison
       et ne prouveraient plus rien. */
    vider_journal_test();
    envoyer_email_smtp("a@b.test\r\nBcc: x@y.test", 'Sujet', 'Corps');
    sans('SMTP non configuré', journal_test(), 'on a dépassé le contrôle de configuration');
});

test('un retour à la ligne dans le destinataire arrête tout', function () {
    /* « victime@site.fr\r\nBcc: attaquant@site.fr » ferait partir une
       copie cachée vers l attaquant — avec, par exemple, un lien de
       réinitialisation de mot de passe. */
    vider_journal_test();
    faux(
        envoyer_email_smtp("victime@exemple.test\r\nBcc: attaquant@exemple.test", 'Sujet', 'Corps'),
        'aucun envoi'
    );
    contient('destinataire ou sujet invalide', journal_test(), 'la tentative est consignée');
});

test('un retour à la ligne dans le SUJET arrête tout aussi', function () {
    /* Le sujet est écrit dans un en-tête « Subject: » : un retour à la
       ligne y ouvre un en-tête supplémentaire tout aussi bien. Le
       contrôle porte sur la concaténation des deux champs, pas sur le
       seul destinataire. */
    vider_journal_test();
    faux(
        envoyer_email_smtp('destinataire@exemple.test', "Sujet\r\nBcc: attaquant@exemple.test", 'Corps'),
        'aucun envoi'
    );
    contient('destinataire ou sujet invalide', journal_test(), 'la tentative est consignée');
});

test('un simple saut de ligne suffit à faire refuser', function () {
    // Certains serveurs SMTP tolèrent un LF seul comme fin de ligne :
    // n exiger que la paire CRLF laisserait passer l attaque.
    vider_journal_test();
    faux(envoyer_email_smtp("a@exemple.test\nBcc: x@exemple.test", 'Sujet', 'Corps'), 'aucun envoi');
    contient('destinataire ou sujet invalide', journal_test(), 'le LF seul est couvert');
});

test('une adresse simplement invalide est refusée elle aussi', function () {
    vider_journal_test();
    faux(envoyer_email_smtp('pas-une-adresse', 'Sujet', 'Corps'), 'aucun envoi');
    contient('destinataire ou sujet invalide', journal_test(), 'la cause est consignée');
});

test('un corps multiligne reste parfaitement légitime', function () {
    /* Le contrôle ne porte QUE sur le destinataire et le sujet : tous les
       e-mails du site ont un corps sur plusieurs lignes. Si le corps
       était refusé lui aussi, plus aucun message ne partirait. */
    vider_journal_test();
    envoyer_email_smtp('destinataire@exemple.test', 'Sujet', "Bonjour,\r\n\r\nVoici le lien.");
    sans('destinataire ou sujet invalide', journal_test(),
        'le corps multiligne ne déclenche pas le refus');
});
