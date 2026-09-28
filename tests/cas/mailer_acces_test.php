<?php
/* =====================================================================
   includes/mailer.php — un e-mail ne parle que du mot de passe qui existe.

   Un compte Google peut ne pas en avoir. Les avis lui disaient pourtant
   « quelqu'un connaissait votre mot de passe », ou lui envoyaient un lien
   pour le « réinitialiser ». Chaque avis se compose donc selon
   acces_compte() : « email », « google » (Google seul) ou « google_mdp »
   (Google, puis le mot de passe du site).
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

/* Ce qu'un compte sans mot de passe ne doit jamais lire. */
const PHRASES_MOT_DE_PASSE = [
    'connaissait votre mot de passe',
    'a votre mot de passe',
    'connaît votre mot de passe',
    'changez de mot de passe',
    'mot de passe du site',
    'Mot de passe oublié',
    'vient d\'être modifié',
];

function sans_mot_de_passe_suppose(string $corps, string $avis): void
{
    foreach (PHRASES_MOT_DE_PASSE as $phrase) {
        sans($phrase, $corps, "$avis : pas de « $phrase »");
    }
}

groupe('acces_compte() — comment le compte s ouvre');

test('sans google_sub, c est un compte e-mail', function () {
    egale('email', acces_compte(null, true), 'google_sub absent');
    egale('email', acces_compte('', true), 'google_sub vide');
});

test('un compte Google, avec ou sans mot de passe', function () {
    egale('google', acces_compte('1234567890', false), 'Google seul');
    egale('google_mdp', acces_compte('1234567890', true), 'Google, puis le mot de passe');
});

groupe('avis_compte_supprime()');

test('un compte Google sans mot de passe : seul Google est en cause', function () {
    [, $corps] = avis_compte_supprime('marco', 'google');
    sans_mot_de_passe_suppose($corps, 'suppression');
    contient('votre compte Google', $corps, 'le compte Google est à sécuriser');
});

test('un compte Google avec mot de passe : les deux', function () {
    [, $corps] = avis_compte_supprime('marco', 'google_mdp');
    contient('votre compte Google', $corps, 'Google');
    contient('mot de passe du site', $corps, 'et le mot de passe');
});

test('un compte e-mail : le mot de passe, pas Google', function () {
    [$sujet, $corps] = avis_compte_supprime('marco', 'email');
    egale('Votre compte a été supprimé', $sujet, 'le sujet');
    contient('connaissait votre mot de passe', $corps, 'le mot de passe');
    sans('Google', $corps, 'Google n y est pour rien');
});

groupe('avis_mot_de_passe_change()');

test('un premier mot de passe est « ajouté », pas « modifié »', function () {
    [$sujet, $corps] = avis_mot_de_passe_change('marco', 'google');
    egale('Un mot de passe a été ajouté à votre compte', $sujet, 'le sujet');
    sans_mot_de_passe_suppose($corps, 'mot de passe ajouté');
    contient('votre compte Google', $corps, 'le compte Google est à sécuriser');
    contient('mot-de-passe-oublie.php', $corps, 'le compte en a un désormais : il peut le reprendre');
});

test('un compte Google qui en avait un : Google et le mot de passe', function () {
    [$sujet, $corps] = avis_mot_de_passe_change('marco', 'google_mdp');
    egale('Votre mot de passe a été modifié', $sujet, 'le sujet');
    contient('votre compte Google', $corps, 'Google');
    contient('connaissait votre mot de passe', $corps, 'et le mot de passe');
});

test('un compte e-mail : le texte d origine', function () {
    [$sujet, $corps] = avis_mot_de_passe_change('marco', 'email');
    egale('Votre mot de passe a été modifié', $sujet, 'le sujet');
    contient('votre compte est compromis', $corps, 'le recours');
    sans('Google', $corps, 'Google n y est pour rien');
});

groupe('avis_changement_email()');

test('un compte Google sans mot de passe : le lien en fait choisir un', function () {
    [, $corps] = avis_changement_email('marco', 'nouvelle@exemple.test', 'google', 'https://exemple.test/lien');
    sans_mot_de_passe_suppose($corps, 'changement d adresse');
    contient('https://exemple.test/lien', $corps, 'le lien de blocage');
    contient('vous sera demandé après Google', $corps, 'ce que le lien fera choisir');
});

test('sans lien de blocage, un compte Google sans mot de passe va aux Paramètres', function () {
    [, $corps] = avis_changement_email('marco', 'nouvelle@exemple.test', 'google', null);
    sans_mot_de_passe_suppose($corps, 'changement d adresse, sans lien');
    sans('mot-de-passe-oublie.php', $corps, 'rien à réinitialiser');
    contient('parametres.php#profil', $corps, 'remettre l adresse lui-même');
});

test('un compte e-mail : le texte d origine, avec ou sans lien', function () {
    [$sujet, $corps] = avis_changement_email('marco', 'nouvelle@exemple.test', 'email', 'https://exemple.test/lien');
    egale("Demande de changement d'adresse e-mail", $sujet, 'le sujet');
    contient("quelqu'un a votre mot de passe", $corps, 'le recours');
    contient('n***e@exemple.test', $corps, 'l adresse visée, masquée');
    [, $corps] = avis_changement_email('marco', 'nouvelle@exemple.test', 'email', null);
    contient('mot-de-passe-oublie.php', $corps, 'sans lien : le mot de passe oublié');
});

groupe('avis_mot_de_passe_oublie()');

test('un compte Google sans mot de passe ne reçoit aucun lien', function () {
    [$sujet, $corps] = avis_mot_de_passe_oublie('marco', 'google', 'https://exemple.test/reinit');
    egale('Connexion à votre compte', $sujet, 'le sujet');
    sans('https://exemple.test/reinit', $corps, 'pas de lien de réinitialisation');
    contient('connexion.php', $corps, 'le chemin de Google');
});

test('un compte avec mot de passe reçoit son lien', function () {
    [$sujet, $corps] = avis_mot_de_passe_oublie('marco', 'email', 'https://exemple.test/reinit');
    egale('Réinitialisation de votre mot de passe', $sujet, 'le sujet');
    contient('https://exemple.test/reinit', $corps, 'le lien');
    sans('Google', $corps, 'Google n y est pour rien');
    [, $corps] = avis_mot_de_passe_oublie('marco', 'google_mdp', 'https://exemple.test/reinit');
    contient('https://exemple.test/reinit', $corps, 'Google avec mot de passe : le lien aussi');
    contient('Google restera demandé', $corps, 'et Google reste la première étape');
});

groupe('avis_tentative_inscription()');

test('un compte Google sans mot de passe : Google, rien d autre', function () {
    [, $corps] = avis_tentative_inscription('marco', 'google');
    sans_mot_de_passe_suppose($corps, 'tentative d inscription');
    contient('connectez-vous avec Google', $corps, 'le chemin de sa connexion');
});

test('un compte e-mail garde « Mot de passe oublié ? »', function () {
    [, $corps] = avis_tentative_inscription('marco', 'email');
    contient('mot-de-passe-oublie.php', $corps, 'le lien');
    [, $corps] = avis_tentative_inscription('marco', 'google_mdp');
    contient('mot-de-passe-oublie.php', $corps, 'Google avec mot de passe : aussi');
});
