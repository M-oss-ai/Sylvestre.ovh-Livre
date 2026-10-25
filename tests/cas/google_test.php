<?php
/* =====================================================================
   « Continuer avec Google » — tout ce qui se décide sans réseau.

   L'échange du code contre le jeton (google_echanger_code) appelle
   Google : il n'est pas testé ici. Tout le reste l'est — l'adresse de
   départ, la lecture et la vérification du jeton, la décision prise pour
   le compte, l'identifiant d'un compte créé, et la fenêtre pendant
   laquelle Google tient lieu de mot de passe.
   ===================================================================== */

declare(strict_types=1);

putenv('GOOGLE_CLIENT_ID=client-test.apps.googleusercontent.com');
putenv('GOOGLE_CLIENT_SECRET=secret-de-test');
putenv('CONFIRMATION_DUREE=5');   // absurde : relevé à une minute

require __DIR__ . '/../lanceur.php';
require_once CHEMIN_SITE . '/includes/google.php';

const CLIENT = 'client-test.apps.googleusercontent.com';

/** Un id_token factice (la signature n'est pas lue, voir includes/google.php). */
function jeton_factice(array $revendications): string
{
    return base64url('{"alg":"RS256"}') . '.' . base64url(json_encode($revendications)) . '.signature';
}

/** Des revendications valides, à dégrader une par une. */
function revendications(array $changer = []): array
{
    return array_merge([
        'iss' => 'https://accounts.google.com', 'aud' => CLIENT, 'sub' => '1098765432',
        'email' => 'marie@exemple.test', 'email_verified' => true,
        'nonce' => 'nonce-attendu', 'exp' => 2000000600, 'iat' => 2000000000,
    ], $changer);
}

const MAINTENANT = 2000000100;

groupe('Réglages Google');

test('configuré, Google est actif', function () {
    vrai(google_actif(), 'identifiant et secret présents');
    egale(APP_URL . '/google.php', google_redirection(), 'l adresse de retour à déclarer chez Google');
});

test('la confirmation par Google ne peut pas durer moins d une minute', function () {
    egale(60, CONFIRMATION_DUREE, 'le .env demandait 5 secondes');
});

groupe('google_url_autorisation() — le départ chez Google');

test('tous les garde-fous partent avec la demande', function () {
    $url = google_url_autorisation('etat-1', 'nonce-1', 'defi-1', CLIENT, 'https://livre.exemple/google.php');
    vrai(str_starts_with($url, GOOGLE_AUTORISATION . '?'), 'chez Google');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $p);
    egale(CLIENT, $p['client_id'], 'notre application');
    egale('https://livre.exemple/google.php', $p['redirect_uri'], 'le retour');
    egale('code', $p['response_type'], 'le flux par code, jamais le jeton dans l adresse');
    egale('openid email profile', $p['scope'], 'identité, adresse et nom seulement');
    egale('etat-1', $p['state'], 'l état contre le login CSRF');
    egale('nonce-1', $p['nonce'], 'le nonce contre le rejeu');
    egale('defi-1', $p['code_challenge'], 'le défi PKCE');
    egale('S256', $p['code_challenge_method'], 'PKCE par empreinte, pas en clair');
    egale('select_account', $p['prompt'], 'toujours choisir le compte');
});

groupe('base64url et PKCE');

test('aller-retour, et refus de ce qui n en est pas', function () {
    $octets = random_bytes(40);
    egale($octets, base64url_decoder(base64url($octets)), 'aller-retour');
    sans('=', base64url(random_bytes(10)), 'sans remplissage');
    estNul(base64url_decoder('a+b/c'), 'l alphabet ordinaire est refusé');
    estNul(base64url_decoder(''), 'vide');
});

test('le défi PKCE est l empreinte SHA-256 du vérificateur, en base64url', function () {
    /* SHA-256 de « abc » vaut, en base64 ordinaire,
       « ungWv48Bz+pBQUDeXa4iI7ADYaOWF3qctBD/YfIAFa0= » : il contient un
       « + », un « / » et un « = », les trois caractères que base64url
       remplace ou retire. */
    egale('ungWv48Bz-pBQUDeXa4iI7ADYaOWF3qctBD_YfIAFa0', pkce_defi('abc'), 'empreinte connue, alphabet URL, sans remplissage');
});

groupe('google_lire_id_token() — la partie centrale du jeton');

test('un jeton bien formé est lu', function () {
    $c = google_lire_id_token(jeton_factice(['sub' => '42']));
    egale('42', $c['sub'] ?? null, 'la revendication');
});

test('un jeton mal formé ne donne rien', function () {
    estNul(google_lire_id_token('deux.parties'), 'deux parties');
    estNul(google_lire_id_token('a.b.c.d'), 'quatre parties');
    estNul(google_lire_id_token('a.' . base64url('pas du json') . '.c'), 'pas du JSON');
    estNul(google_lire_id_token('a.!!!.c'), 'pas du base64url');
});

groupe('google_verifier_revendications() — ce que le jeton doit dire');

test('un jeton conforme est accepté', function () {
    egale('', google_verifier_revendications(revendications(), CLIENT, 'nonce-attendu', MAINTENANT), 'accepté');
    egale('', google_verifier_revendications(revendications(['iss' => 'accounts.google.com']), CLIENT, 'nonce-attendu', MAINTENANT),
        'l émetteur sans « https:// », que Google emploie aussi');
    egale('', google_verifier_revendications(revendications(['email_verified' => 'true']), CLIENT, 'nonce-attendu', MAINTENANT),
        'une vérification écrite en texte');
});

test('chaque manquement est refusé', function () {
    $refus = static fn (array $changer, string $nonce = 'nonce-attendu', string $client = CLIENT): string =>
        google_verifier_revendications(revendications($changer), $client, $nonce, MAINTENANT);
    differe('', $refus(['iss' => 'https://faux-google.test']), 'un autre émetteur');
    differe('', $refus(['aud' => 'autre-application']), 'un jeton émis pour une autre application');
    differe('', $refus(['aud' => [CLIENT, 'autre']]), 'plusieurs destinataires sans « azp » à notre nom');
    differe('', $refus([], 'nonce-attendu', ''), 'un identifiant client vide ne reconnaît rien');
    differe('', $refus(['exp' => MAINTENANT - 301]), 'expiré, au-delà de la tolérance');
    differe('', $refus(['iat' => MAINTENANT + 301]), 'daté du futur');
    differe('', $refus([], 'autre-nonce'), 'un autre nonce : un jeton rejoué');
    differe('', $refus(['sub' => '']), 'sans identifiant Google');
    differe('', $refus(['email' => 'pas-une-adresse']), 'sans adresse valide');
    differe('', $refus(['email_verified' => false]), 'une adresse que Google n a pas vérifiée');
});

test('la tolérance d horloge de 5 minutes', function () {
    egale('', google_verifier_revendications(revendications(['exp' => MAINTENANT - 200]), CLIENT, 'nonce-attendu', MAINTENANT),
        'expiré depuis 200 s : l horloge du serveur peut avancer');
});

groupe('google_decision() — que faire de ce compte Google');

test('sans session : ouvrir, refuser ou créer', function () {
    $compte_email = ['id' => 7, 'google_sub' => null, 'email_verifie' => 1];
    egale('connecter', google_decision(null, ['id' => 7, 'google_sub' => 's'], null), 'compte créé avec ce compte Google');
    egale('refus_adresse', google_decision(null, null, $compte_email),
        'l adresse d un compte e-mail : les deux sortes de comptes ne se relient pas');
    egale('refus_adresse', google_decision(null, null, ['google_sub' => 'x', 'email_verifie' => 1] + $compte_email),
        'l adresse d un autre compte Google');
    egale('creer', google_decision(null, null, null), 'personne : on propose de créer');
});

test('un compte Google avec mot de passe : le mot de passe d abord', function () {
    egale('mot_de_passe', google_decision(null, ['id' => 7, 'google_sub' => 's', 'a_mdp' => 1], null),
        'Google ne suffit pas : la seconde étape attend');
    egale('connecter', google_decision(null, ['id' => 7, 'google_sub' => 's', 'a_mdp' => 0], null),
        'sans mot de passe, Google seul ouvre le compte');
    egale('confirmer', google_decision(['id' => 7, 'google_sub' => 's'], ['id' => 7, 'a_mdp' => 1], null),
        'déjà connecté : confirmer son identité ne redemande pas le mot de passe');
});

test('un compte e-mail jamais confirmé ne bloque pas l adresse', function () {
    /* N importe qui a pu le créer avec l adresse d un autre ; Google vient
       de prouver à qui elle est. Ce compte jamais activé cède la place. */
    egale('creer', google_decision(null, null, ['id' => 7, 'google_sub' => null, 'email_verifie' => 0]),
        'création proposée');
});

test('connecté : seulement confirmer son identité, jamais relier', function () {
    $moi = ['id' => 5, 'google_sub' => 's5'];
    egale('confirmer', google_decision($moi, ['id' => 5], null), 'son propre compte Google');
    egale('refus_autre', google_decision($moi, ['id' => 7], null), 'le compte Google d un autre');
    egale('refus_autre', google_decision(['id' => 5, 'google_sub' => null], null, null),
        'un compte e-mail ne se relie pas à Google');
});

groupe('google_etape_mdp() — le compte qui attend son mot de passe');

test('valable le temps du parcours, puis il faut repasser par Google', function () {
    egale(7, google_etape_mdp(['id' => 7, 'le' => 1000], 1000 + GOOGLE_PARCOURS_MAX), 'à la limite : encore valable');
    egale(0, google_etape_mdp(['id' => 7, 'le' => 1000], 1001 + GOOGLE_PARCOURS_MAX), 'au-delà : expiré');
    egale(0, google_etape_mdp(null, 1000), 'aucune étape en cours');
    egale(0, google_etape_mdp('7', 1000), 'pas un tableau');
    egale(0, google_etape_mdp(['id' => -3, 'le' => 1000], 1000), 'jamais un id négatif');
});

groupe('google_attente() — la saisie gardée pendant l aller-retour chez Google');

test('pour ce compte, cette action, le temps du parcours et de la confirmation', function () {
    $attente = ['id' => 7, 'action' => 'compte.profil', 'le' => 1000, 'donnees' => ['identifiant' => 'nouveau']];
    $limite  = 1000 + GOOGLE_PARCOURS_MAX + CONFIRMATION_DUREE;
    egale(['identifiant' => 'nouveau'], google_attente($attente, 7, 'compte.profil', $limite), 'à la limite : encore là');
    estNul(google_attente($attente, 7, 'compte.profil', $limite + 1), 'au-delà : perdue');
    estNul(google_attente($attente, 8, 'compte.profil', 1000), 'un autre compte n en profite pas');
    estNul(google_attente($attente, 7, 'compte.motdepasse', 1000), 'une autre action non plus');
    estNul(google_attente($attente, 0, 'compte.profil', 1000), 'personne de connecté');
    estNul(google_attente(null, 7, 'compte.profil', 1000), 'rien en attente');
    estNul(google_attente(['id' => 7, 'action' => 'compte.profil', 'le' => 1000, 'donnees' => 'x'], 7, 'compte.profil', 1000), 'des données illisibles');
});

test('google_attente_resume() — ce que la fenêtre rappelle', function () {
    $moi = ['identifiant' => 'ancien', 'email' => 'a@exemple.test', 'photo' => 'uploads/a.webp', 'sans_mot_de_passe' => 1];
    $meme = ['identifiant' => 'ancien', 'email' => 'A@exemple.test', 'photo' => 'uploads/a.webp'];
    egale('', google_attente_resume('compte.profil', $meme, $moi), 'rien de changé (la casse de l adresse ne compte pas)');
    egale(
        'Identifiant : « ancien » → « nouveau ». Adresse e-mail : b@exemple.test (à confirmer depuis cette adresse). Nouvelle photo.',
        google_attente_resume('compte.profil', ['identifiant' => 'nouveau', 'email' => 'b@exemple.test', 'photo' => 'uploads/b.webp'], $moi),
        'chaque changement, dans l ordre du formulaire'
    );
    egale('Photo retirée.', google_attente_resume('compte.profil', ['photo' => ''] + $meme, $moi), 'la photo retirée');
    contient('sera défini', google_attente_resume('compte.motdepasse', ['empreinte' => 'x'], $moi), 'un premier mot de passe');
    contient('remplacera', google_attente_resume('compte.motdepasse', ['empreinte' => 'x'], ['sans_mot_de_passe' => 0] + $moi), 'un mot de passe remplacé');
    sans('$2y$', google_attente_resume('compte.motdepasse', ['empreinte' => '$2y$10$abc'], $moi), 'jamais l empreinte');
});

groupe('identifiant_depuis_google() — le nom d un compte créé');

test('le début de l adresse, dans les règles de l inscription', function () {
    egale('Marie.Dupont', identifiant_depuis_google('Marie.Dupont+manga@gmail.com'), 'sans l étiquette « + »');
    egale('lecteur', identifiant_depuis_google('ab@exemple.test'), 'trop court : un nom par défaut');
    egale('lecteur', identifiant_depuis_google('+++@exemple.test'), 'rien d utilisable');
    egale(24, strlen(identifiant_depuis_google(str_repeat('a', 60) . '@exemple.test')), 'de la marge pour un suffixe');
    motif('/^[A-Za-z0-9._-]{3,30}$/', identifiant_depuis_google('jo.sé!#$%@exemple.test'), 'que des caractères admis');
    egale('abc', identifiant_depuis_google('.abc.@exemple.test'), 'sans ponctuation aux bords');
});

groupe('confirmation_recente() — se reconnecter pour UNE action');

test('valable une minute, pour ce compte et cette action seulement', function () {
    $session = ['confirme' => ['id' => 5, 'action' => 'compte.supprimer', 'le' => 1000]];
    vrai(confirmation_recente(5, 'compte.supprimer', $session, 1059), 'dans la minute');
    faux(confirmation_recente(5, 'compte.supprimer', $session, 1060), 'passé le délai');
    faux(confirmation_recente(5, 'donnees.vider', $session, 1001),
        'une autre action à risque demande de se reconnecter');
    faux(confirmation_recente(6, 'compte.supprimer', $session, 1001), 'pour un autre compte');
    faux(confirmation_recente(5, 'compte.supprimer', [], 1001), 'sans confirmation');
    faux(confirmation_recente(0, 'compte.supprimer',
        ['confirme' => ['id' => 0, 'action' => 'compte.supprimer', 'le' => 1000]], 1001), 'jamais pour « personne »');
});

test('une confirmation sans action (celle d une connexion) ne vaut rien', function () {
    $session = ['confirme' => ['id' => 5, 'le' => 1000]];
    estNul(confirmation_en_cours(5, $session, 1001), 'se connecter ne confirme aucune action');
    estNul(confirmation_en_cours(5, ['confirme' => ['id' => 5, 'action' => 'inconnue', 'le' => 1000]], 1001),
        'une action hors de la liste');
});

test('confirmation_en_cours() dit l action et le temps qui reste', function () {
    $session = ['confirme' => ['id' => 5, 'action' => 'compte.motdepasse', 'le' => 1000]];
    egale(['action' => 'compte.motdepasse', 'restant' => 45], confirmation_en_cours(5, $session, 1015), '45 s sur 60');
});

test('noter, puis oublier : elle ne sert qu une fois', function () {
    noter_confirmation(9, 'donnees.vider');
    vrai(confirmation_recente(9, 'donnees.vider'), 'aussitôt valable');
    oublier_confirmation();
    faux(confirmation_recente(9, 'donnees.vider'), 'l action faite, elle a disparu');
});

test('chaque action à risque a son message', function () {
    contient('supprimer votre compte', message_reconnexion_google('compte.supprimer'), 'dit pour quoi se reconnecter');
    egale('parametres.php#donnees', google_page_action('compte.supprimer'), 'retour sur la bonne carte');
    egale('parametres.php#securite', google_page_action('compte.motdepasse'), 'le mot de passe : Sécurité');
    egale('parametres.php#profil', google_page_action('compte.profil'), 'identifiant et adresse : Profil');
});

groupe('bouton_google() — le bouton');

test('un lien vers google.php, sans style en ligne', function () {
    $html = bouton_google('Continuer avec Google', 'parametres');
    contient('href="google.php?retour=parametres"', $html, 'le départ, avec son retour');
    contient('Continuer avec Google', $html, 'le libellé');
    sans('style=', $html, 'la CSP interdit le style en ligne');
    sans('<script', $html, 'ni script');
});

test('le bouton de reconnexion porte son action', function () {
    contient('href="google.php?retour=parametres&amp;action=compte.supprimer"',
        bouton_google('Se reconnecter', 'parametres', 'compte.supprimer'), 'l action part avec la demande');
});

test('le libellé est échappé', function () {
    contient('&lt;b&gt;', bouton_google('<b>'), 'pas de HTML injecté');
});
