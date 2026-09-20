<?php
/* =====================================================================
   avertir_compte_supprime() — l avis qui manquait.

   Supprimer son compte était l action la plus irréversible du site, et
   la seule action sensible qui ne laissait AUCUNE trace chez le
   titulaire : changer son mot de passe le prévenait, demander un
   changement d adresse aussi, mais tout effacer se faisait en silence.

   Ce qui se vérifie ici est le câblage, pas le contenu : l amorce vide
   les réglages SMTP, donc rien ne part et le corps du message n est
   observable nulle part. Vérifier qu il est bien ADRESSÉ et TENTÉ suffit
   à détecter la régression qui compte — un appel oublié, une signature
   changée, un destinataire perdu en route.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('avertir_compte_supprime() — le câblage');

test('la fonction existe avec la signature attendue', function () {
    /* api.php l appelle avec (email, identifiant). Une signature qui
       changerait sans que l appel suive ferait tomber la suppression de
       compte en erreur 500 — au pire moment, puisque les données sont
       déjà effacées à cet instant. */
    vrai(function_exists('avertir_compte_supprime'), 'la fonction est définie');
    $r = new ReflectionFunction('avertir_compte_supprime');
    egale(2, $r->getNumberOfParameters(), 'deux paramètres');
    egale('email', $r->getParameters()[0]->getName(), 'le destinataire en premier');
    egale('identifiant', $r->getParameters()[1]->getName(), 'l identifiant ensuite');
});

test('un envoi est tenté vers une adresse valide', function () {
    vider_journal_test();
    avertir_compte_supprime('titulaire@exemple.test', 'marco');

    /* SMTP est vidé par l amorce : la tentative échoue et le dit. Cette
       trace est la preuve que l envoi a bien été demandé. */
    contient('envoyer_email', journal_test(), 'un envoi a été tenté');
});

test('une adresse invalide n entraîne aucune tentative', function () {
    /* envoyer_email() valide le destinataire avant tout. Sans ce
       contrôle, une adresse contenant un retour à la ligne permettrait
       d ajouter des en-têtes SMTP arbitraires. */
    vider_journal_test();
    avertir_compte_supprime('pas-une-adresse', 'marco');
    egale('', trim(journal_test()), 'rien n est même tenté');
});

test('une adresse avec un retour à la ligne est refusée', function () {
    vider_journal_test();
    avertir_compte_supprime("titulaire@exemple.test\nBcc: ailleurs@exemple.test", 'marco');
    egale('', trim(journal_test()), 'aucune tentative sur une adresse à injection');
});

test('l appel ne lève jamais d exception', function () {
    /* Il a lieu APRÈS la suppression du compte, dans api.php. Une
       exception à ce moment-là afficherait une erreur à quelqu un dont
       le compte vient pourtant d être correctement effacé — et lui
       laisserait croire que l opération a échoué. */
    avertir_compte_supprime('titulaire@exemple.test', 'marco');
    avertir_compte_supprime('', '');
    vrai(true, 'aucune exception sur un cas normal ni sur des chaînes vides');
});
