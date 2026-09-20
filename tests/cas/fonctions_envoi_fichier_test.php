<?php
/* =====================================================================
   enregistrer_image() — la réception d un fichier de formulaire.

   Cette fonction traduit les six codes d erreur de PHP en messages
   distincts. Avant ça, « L envoi de l image a échoué » les couvrait tous :
   l utilisateur dont le fichier était trop lourd n avait aucun moyen de
   le deviner, et l administrateur ne voyait pas passer les pannes du
   serveur.

   En ligne de commande, is_uploaded_file() rend toujours false — et
   c est très bien ainsi : ces tests vérifient tout ce qui se décide
   AVANT, plus le refus de tout fichier qui ne vient pas d un envoi HTTP.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

/** Prépare un $_FILES comme PHP le construirait. */
function fichier_recu(array $champs): void
{
    $_FILES['couverture'] = $champs + [
        'name'     => 'photo.jpg',
        'type'     => 'image/jpeg',
        'tmp_name' => '',
        'error'    => UPLOAD_ERR_OK,
        'size'     => 1024,
    ];
}

groupe('enregistrer_image() — aucun fichier envoyé');

test('un champ absent n est pas une erreur', function () {
    /* La couverture est facultative : un formulaire envoyé sans image
       doit simplement ne rien enregistrer, pas échouer. */
    $_FILES = [];
    $erreur = null;
    estNul(enregistrer_image('couverture', $erreur), 'aucun chemin rendu');
    estNul($erreur, 'et aucune erreur signalée');
});

test('un champ présent mais vide n est pas une erreur non plus', function () {
    // Le cas d un <input type="file"> laissé tel quel par l utilisateur.
    fichier_recu(['error' => UPLOAD_ERR_NO_FILE]);
    $erreur = null;
    estNul(enregistrer_image('couverture', $erreur), 'aucun chemin rendu');
    estNul($erreur, 'aucune erreur signalée');
    $_FILES = [];
});

groupe('enregistrer_image() — un message par cause réelle');

test('un fichier trop lourd pour PHP est annoncé comme tel', function () {
    fichier_recu(['error' => UPLOAD_ERR_INI_SIZE]);
    $erreur = null;
    estNul(enregistrer_image('couverture', $erreur), 'refusé');
    contient('trop lourde', (string) $erreur, 'la cause est dite');
    contient(taille_lisible(IMAGE_TAILLE_MAX), (string) $erreur, 'et la limite applicable citée');
    $_FILES = [];
});

test('une limite de formulaire dépassée donne le même message', function () {
    fichier_recu(['error' => UPLOAD_ERR_FORM_SIZE]);
    $erreur = null;
    enregistrer_image('couverture', $erreur);
    contient('trop lourde', (string) $erreur, 'même cause, même message');
    $_FILES = [];
});

test('un envoi interrompu propose de réessayer', function () {
    /* Le seul cas où l utilisateur peut agir tout de suite, et où lui
       dire « vérifiez votre connexion » a un sens. */
    fichier_recu(['error' => UPLOAD_ERR_PARTIAL]);
    $erreur = null;
    enregistrer_image('couverture', $erreur);
    contient('interrompu', (string) $erreur, "l'interruption est nommée");
    contient('connexion', (string) $erreur, 'et une piste est donnée');
    $_FILES = [];
});

test('une panne du serveur est consignée pour l administrateur', function () {
    /* Dossier temporaire absent, écriture refusée, extension PHP qui
       bloque : l utilisateur n y peut rien, mais l administrateur doit le
       savoir — sinon la panne reste invisible. */
    vider_journal_test();
    fichier_recu(['error' => UPLOAD_ERR_NO_TMP_DIR]);
    $erreur = null;
    enregistrer_image('couverture', $erreur);

    contient('Réessayez', (string) $erreur, "l'utilisateur reçoit un message neutre");
    sans('tmp', (string) $erreur, 'sans détail sur le serveur');
    contient('enregistrer_image: echec PHP code', journal_test(), 'le journal, lui, porte le code');
    $_FILES = [];
});

test('une panne d écriture est consignée elle aussi', function () {
    vider_journal_test();
    fichier_recu(['error' => UPLOAD_ERR_CANT_WRITE]);
    enregistrer_image('couverture');
    contient('enregistrer_image: echec PHP code', journal_test(), 'consignée');
    $_FILES = [];
});

test('un fichier trop lourd est refusé même si PHP l a accepté', function () {
    /* post_max_size peut être plus généreux que IMAGE_TAILLE_MAX : la
       limite de l application s applique par-dessus celle de PHP. */
    fichier_recu(['error' => UPLOAD_ERR_OK, 'size' => IMAGE_TAILLE_MAX + 1]);
    $erreur = null;
    estNul(enregistrer_image('couverture', $erreur), 'refusé');
    contient('trop lourde', (string) $erreur, 'la limite de l application');
    $_FILES = [];
});

groupe('enregistrer_image() — le fichier doit venir d un vrai envoi');

test('un chemin qui ne vient pas d un envoi HTTP est refusé', function () {
    /* is_uploaded_file() est la seule barrière contre un tmp_name forgé :
       sans elle, une requête fabriquée à la main pourrait désigner
       n importe quel fichier du serveur — includes/config.php, par
       exemple — et le faire recopier dans uploads/. */
    fichier_recu([
        'error'    => UPLOAD_ERR_OK,
        'size'     => 100,
        'tmp_name' => CHEMIN_PROJET . '/includes/config.php',
    ]);
    $erreur = null;
    estNul(enregistrer_image('couverture', $erreur), 'refusé');
    contient('invalide', (string) $erreur, 'le fichier est déclaré invalide');
    $_FILES = [];
});
