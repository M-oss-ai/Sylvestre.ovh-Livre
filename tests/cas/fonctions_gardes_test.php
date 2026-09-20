<?php
/* =====================================================================
   Les contrôles qui s exécutent AVANT toute requête.

   Plusieurs fonctions du projet parlent à la base — mais pas tout de
   suite : elles commencent par un contrôle de forme et se terminent là
   quand la saisie n a aucune chance d être valide. Ce court-circuit a
   deux vertus, et la seconde permet ces tests :

     - il évite une requête (et un calcul d empreinte, et un
       password_verify() volontairement lent) à chaque tentative d un
       robot qui balaie le site ;
     - il est vérifiable sans base de données.

   Quand une saisie valide est fournie, la requête est bien tentée : la
   base de test ne porte pas les tables de l application, l exception
   qui en résulte est donc la PREUVE que le contrôle a laissé passer.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

/** Y a-t-il, parmi les erreurs, un message qui parle de $sujet ? */
function erreur_parle_de(array $erreurs, string $sujet): bool
{
    foreach ($erreurs as $message) {
        if (mb_stripos($message, $sujet, 0, 'UTF-8') !== false) {
            return true;
        }
    }
    return false;
}

groupe('valider_profil() — la forme de l identifiant');

test('un identifiant trop court est refusé', function () {
    vrai(erreur_parle_de(valider_profil('ab', 'a@exemple.test', 1), 'identifiant'), 'deux caractères');
});

test('un identifiant trop long est refusé', function () {
    vrai(erreur_parle_de(valider_profil(str_repeat('a', 31), 'a@exemple.test', 1), 'identifiant'),
        '31 caractères');
});

test('les bornes exactes sont respectées', function () {
    /* 3 et 30 caractères doivent PASSER le contrôle de forme : ils vont
       donc jusqu à la requête, et c est ce qu on observe ici. */
    leve(PDOException::class,
        static fn () => valider_profil('abc', 'a@exemple.test', 1),
        'trois caractères passent la forme');
    leve(PDOException::class,
        static fn () => valider_profil(str_repeat('a', 30), 'a@exemple.test', 1),
        'trente caractères aussi');
});

test('les caractères hors de la liste blanche sont refusés', function () {
    /* L identifiant apparaît dans des messages et sert de clé de
       comptage au limiteur : le restreindre évite d avoir à se demander,
       partout, comment il va être rendu. */
    foreach (['a b', 'a@b', 'a/b', 'a<b', "a'b", 'aé b', 'a;b'] as $mauvais) {
        vrai(erreur_parle_de(valider_profil($mauvais, 'a@exemple.test', 1), 'identifiant'),
            "« {$mauvais} » est refusé");
    }
});

test('les caractères autorisés le sont vraiment', function () {
    leve(PDOException::class,
        static fn () => valider_profil('a.b_c-d9', 'a@exemple.test', 1),
        'point, souligné, tiret et chiffres passent');
});

groupe('valider_profil() — la forme de l adresse');

test('une adresse invalide est refusée', function () {
    vrai(erreur_parle_de(valider_profil('lecteur', 'pas-une-adresse', 1), 'e-mail'), 'sans arobase');
    vrai(erreur_parle_de(valider_profil('lecteur', 'a@', 1), 'e-mail'), 'tronquée');
    vrai(erreur_parle_de(valider_profil('lecteur', '', 1), 'e-mail'), 'vide');
});

test('une saisie invalide n atteint jamais la base', function () {
    /* Le « if ($erreurs) return » avant la requête : inutile
       d interroger la base sur une saisie qui est déjà refusée. */
    $erreurs = valider_profil('a b', 'pas-une-adresse', 1);
    egale(2, count($erreurs), 'les deux problèmes sont signalés d un coup');
});

groupe('mot_de_passe_correct() — le mot de passe vide');

test('un mot de passe vide est refusé sans interroger la base', function () {
    /* Un compte ne peut pas avoir un mot de passe vide, et
       password_verify() est volontairement lent : autant s arrêter
       tout de suite. */
    faux(mot_de_passe_correct(1, ''), 'refusé immédiatement');
});

groupe('verifier_session_persistante() — la forme du cookie d appareil');

test('sans cookie, rien n est tenté', function () {
    unset($_COOKIE['LIVRE_REMEMBER']);
    verifier_session_persistante();
    vrai(true, 'la fonction rend la main sans requête');
});

test('un cookie mal formé est ignoré sans requête', function () {
    /* Le jeton est 64 caractères hexadécimaux. Tout le reste est écarté
       avant le calcul d empreinte : une valeur arbitraire déposée dans le
       navigateur ne déclenche donc aucun travail côté serveur. */
    foreach (['', 'x', str_repeat('a', 63), str_repeat('a', 65), str_repeat('Z', 64),
              "' OR 1=1 --"] as $mauvais) {
        $_COOKIE['LIVRE_REMEMBER'] = $mauvais;
        verifier_session_persistante();
    }
    vrai(true, 'aucune requête n a été tentée');
    unset($_COOKIE['LIVRE_REMEMBER']);
});

test('un cookie bien formé, lui, est bien cherché en base', function () {
    $_COOKIE['LIVRE_REMEMBER'] = str_repeat('a', 64);
    leve(PDOException::class, 'verifier_session_persistante', 'la recherche est lancée');
    unset($_COOKIE['LIVRE_REMEMBER']);
});

test('revoquer_session_persistante() applique le même contrôle', function () {
    $_COOKIE['LIVRE_REMEMBER'] = 'valeur-arbitraire';
    revoquer_session_persistante();
    vrai(true, 'aucune requête sur un cookie mal formé');
    unset($_COOKIE['LIVRE_REMEMBER']);
});

groupe('supprimer_images_locales() — on ne supprime QUE dans uploads/');

test('un chemin hors uploads/ ne supprime rien', function () {
    /* La fonction reçoit des chemins venus de la base. Si le filtre
       tombait, un « ../includes/config.php » arrivé là par un autre
       chemin ferait supprimer un fichier du projet. */
    $cible = CHEMIN_PROJET . '/tests/.cible-a-ne-pas-supprimer.tmp';
    file_put_contents($cible, 'ce fichier doit survivre');

    try {
        foreach ([
            'tests/.cible-a-ne-pas-supprimer.tmp',
            '../tests/.cible-a-ne-pas-supprimer.tmp',
            'uploads/../tests/.cible-a-ne-pas-supprimer.tmp',
            '/etc/passwd',
            'includes/config.php',
        ] as $chemin) {
            supprimer_image_locale($chemin);
        }

        vrai(is_file($cible), 'le fichier visé est toujours là');
        vrai(is_file(CHEMIN_PROJET . '/includes/config.php'), 'config.php aussi');
    } finally {
        @unlink($cible);
    }
});

test('une liste sans aucun chemin valide n atteint pas la base', function () {
    /* Le « if (!$candidats) return » avant les deux requêtes : c est
       aussi ce qui rend ce test possible sans base. */
    supprimer_images_locales(['', 'n importe quoi', 'uploads/x.php', 'uploads/sous/dossier.png']);
    vrai(true, 'aucune requête n a été tentée');
});

test('un chemin valide, lui, déclenche bien la vérification en base', function () {
    /* Avant de supprimer, la fonction vérifie qu aucune autre série ni
       aucun autre profil ne référence encore l image : une même image
       peut être partagée entre plusieurs comptes. */
    leve(PDOException::class,
        static fn () => supprimer_image_locale('uploads/abc123.webp'),
        'la recherche des références est lancée');
});
