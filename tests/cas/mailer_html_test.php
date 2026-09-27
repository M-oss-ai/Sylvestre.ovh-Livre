<?php
/* =====================================================================
   composer_message(), message_mime() et corps_html() — l'e-mail tel
   qu'il part : en texte brut ET en HTML.

   Un e-mail en texte brut ne contient aucun lien. C'est la messagerie
   qui décide d'en fabriquer un à partir du texte, et Proton, sur
   ordinateur, ne le faisait pas : chaque lien de confirmation était à
   copier à la main. La version HTML porte de vrais liens — vers le site,
   et vers lui seul.

   APP_URL vaut « https://exemple.test/bibliotheque » (tests/amorce.php).
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

/** Un lien comme en portent les e-mails du site. */
function lien_du_site(): string
{
    return url_publique('reinitialiser-mot-de-passe.php?jeton=' . str_repeat('ab', 32));
}

/**
 * Découpe un message composé : ses en-têtes, sa frontière, et chaque
 * partie avec son type et son contenu DÉCODÉ.
 */
function decomposer(string $message): array
{
    [$entetes, $corps] = explode("\r\n\r\n", $message, 2) + ['', ''];
    $frontiere = preg_match('/boundary="([^"]+)"/', $entetes, $m) ? $m[1] : '';
    $parties = [];
    foreach ($frontiere === '' ? [] : explode('--' . $frontiere, $corps) as $bloc) {
        if (!str_contains($bloc, 'Content-Type:')) {
            continue;   // avant la première frontière, ou après la dernière
        }
        [$tete, $contenu] = explode("\r\n\r\n", ltrim($bloc, "\r\n"), 2) + ['', ''];
        $parties[] = [
            'type'    => preg_match('/Content-Type: ([^;\r\n]+)/', $tete, $t) ? $t[1] : '',
            'tete'    => $tete,
            'contenu' => base64_decode(str_replace("\r\n", '', $contenu), true),
        ];
    }
    return ['entetes' => $entetes, 'corps' => $corps, 'frontiere' => $frontiere, 'parties' => $parties];
}

/** Relit les mots encodés « =?UTF-8?B?…?= », repliés ou non. */
function relire_mots(string $valeur): string
{
    preg_match_all('/=\?UTF-8\?B\?([A-Za-z0-9+\/=]*)\?=/', $valeur, $m);
    return implode('', array_map(static fn (string $b): string => (string) base64_decode($b, true), $m[1]));
}

/** La valeur d'un en-tête, lignes de repli comprises, relue. */
function relire_entete(string $entetes, string $nom): string
{
    // Un en-tête replié continue sur les lignes qui commencent par un espace.
    return preg_match('/^' . $nom . ': (.*(?:\r\n .*)*)/m', $entetes, $m) ? relire_mots($m[1]) : '';
}

groupe('corps_html() — les liens');

test('une adresse du site devient un vrai lien', function () {
    $lien = lien_du_site();
    $html = corps_html("Choisissez un nouveau mot de passe ici :\n" . $lien);
    contient('<a href="' . $lien . '"', $html, 'le lien mène à l adresse');
    contient('>' . $lien . '</a>', $html, 'et la montre : on voit où il mène avant de cliquer');
});

test('une adresse étrangère reste du texte', function () {
    /* Le texte d'un e-mail peut porter ce qu'un autre a saisi (un
       identifiant, une adresse) : rien de tout cela ne doit devenir un
       lien cliquable dans un message authentique du site. */
    $html = corps_html('Voir https://attaquant.test/reprendre-votre-compte');
    sans('<a', $html, 'aucun lien');
    contient('https://attaquant.test/reprendre-votre-compte', $html, 'le texte reste lisible');
});

test('un domaine sosie ne passe pas pour le site', function () {
    sans('<a', corps_html('https://exemple.test.attaquant.test/bibliotheque/x'), 'le domaine du site en préfixe d un autre');
    sans('<a', corps_html('https://exemple.test/bibliotheque.attaquant.test/x'), 'APP_URL collé à un autre nom');
});

test('une adresse du site en http:// n est pas un lien', function () {
    sans('<a', corps_html('http://exemple.test/bibliotheque/connexion.php'), 'seul APP_URL, en https, fait foi');
});

test('la ponctuation qui suit l adresse n en fait pas partie', function () {
    $lien = url_publique('inscription.php');
    $html = corps_html('Recréez un compte ici : ' . $lien . '.');
    contient('<a href="' . $lien . '"', $html, 'le point final est hors du lien');
    contient('</a>.', $html, 'il reste dans la phrase');
});

test('l adresse nue du site est un lien elle aussi', function () {
    contient('<a href="' . APP_URL . '"', corps_html('Rendez-vous sur ' . APP_URL), 'APP_URL sans chemin');
});

groupe('corps_html() — le texte');

test('le texte est échappé', function () {
    $html = corps_html('Bonjour <script>alert(1)</script> & compagnie');
    sans('<script>', $html, 'aucune balise interprétée');
    contient('&lt;script&gt;', $html, 'elle s affiche telle quelle');
    contient('&amp; compagnie', $html, 'l esperluette aussi');
});

test('un lien avec plusieurs paramètres reste un attribut valide', function () {
    $lien = url_publique('verifier-email.php?jeton=abc&suite=1');
    contient('href="' . str_replace('&', '&amp;', $lien) . '"', corps_html($lien), 'l esperluette est échappée dans href');
});

test('les paragraphes et les retours à la ligne sont conservés', function () {
    $html = corps_html("Bonjour,\n\nPremière ligne\nseconde ligne\n\n\nFin.");
    egale(3, substr_count($html, '<p '), 'trois paragraphes, même séparés par plusieurs lignes vides');
    contient("Première ligne<br>\nseconde ligne", $html, 'un simple retour à la ligne devient <br>');
});

test('les fins de ligne Windows sont comprises', function () {
    egale(2, substr_count(corps_html("Un\r\n\r\nDeux"), '<p '), 'CRLF sépare les paragraphes comme LF');
});

test('le sujet devient le titre de la page, échappé', function () {
    contient('<title>Sujet &lt;b&gt;</title>', corps_html('x', 'Sujet <b>'), 'le titre');
});

test('la page est déclarée en français et en UTF-8', function () {
    $html = corps_html('x');
    contient('<html lang="fr">', $html, 'la langue, pour les lecteurs d écran');
    contient('<meta charset="UTF-8">', $html, 'l encodage');
});

groupe('message_mime() — deux versions du même message');

test('le texte brut d abord, le HTML ensuite', function () {
    /* L'ordre compte : la messagerie affiche la DERNIÈRE version qu'elle
       sait lire. Le HTML en premier ferait préférer le texte partout. */
    $d = decomposer(composer_message('a@exemple.test', 'Sujet', "Texte\n" . lien_du_site(), 'site@exemple.test', 'Site', 'exemple.test'));
    egale(2, count($d['parties']), 'deux parties');
    egale('text/plain', $d['parties'][0]['type'], 'le texte d abord');
    egale('text/html', $d['parties'][1]['type'], 'le HTML ensuite');
});

test('chaque partie annonce son jeu de caractères et son encodage', function () {
    $d = decomposer(composer_message('a@exemple.test', 'Sujet', 'Accentué : é', 'site@exemple.test', 'Site', 'exemple.test'));
    egale(2, count($d['parties']), 'deux parties');
    foreach ($d['parties'] as $p) {
        contient('charset=UTF-8', $p['tete'], $p['type'] . ' : UTF-8');
        contient('Content-Transfer-Encoding: base64', $p['tete'], $p['type'] . ' : base64');
    }
});

test('le texte brut arrive intact, en fins de ligne CRLF', function () {
    $texte = "Bonjour lecteur92,\n\nVotre lien :\n" . lien_du_site();
    $d = decomposer(composer_message('a@exemple.test', 'Sujet', $texte, 'site@exemple.test', 'Site', 'exemple.test'));
    egale(str_replace("\n", "\r\n", $texte), $d['parties'][0]['contenu'], 'le texte décodé est celui qu on a écrit');
});

test('la version HTML est celle de corps_html()', function () {
    $texte = "Bonjour,\n\n" . lien_du_site();
    $d = decomposer(composer_message('a@exemple.test', 'Le sujet', $texte, 'site@exemple.test', 'Site', 'exemple.test'));
    egale(corps_html($texte, 'Le sujet'), $d['parties'][1]['contenu'], 'même contenu, sujet compris');
});

test('le message se termine par la frontière de fin', function () {
    egale(true, str_ends_with(message_mime('x', 'S', 'FRONTIERE'), "--FRONTIERE--\r\n"), 'la frontière fermante');
});

test('aucune ligne trop longue, aucune ligne qui commence par un point', function () {
    /* SMTP fait d'une ligne réduite à « . » la fin du message, et retire
       le premier point de toute autre ligne qui en commence une. Le
       base64 n'en produit jamais : rien n'a besoin d'être doublé. */
    $message = composer_message('a@exemple.test', 'Sujet', str_repeat("Une ligne de texte.\n", 40) . lien_du_site(),
        'site@exemple.test', 'Site', 'exemple.test');
    $fautives = array_filter(
        explode("\r\n", $message),
        static fn (string $ligne): bool => strlen($ligne) > 78 || str_starts_with($ligne, '.')
    );
    vide(array_values($fautives), 'toutes les lignes font 78 caractères au plus et aucune ne commence par un point');
});

groupe('mots_encodes() — l accent dans un en-tête');

test('un texte court tient en un mot encodé', function () {
    $mots = mots_encodes('Ma Bibliothèque Manga');
    motif('/^=\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=$/', $mots, 'un seul mot, sans repli');
    egale('Ma Bibliothèque Manga', relire_mots($mots), 'il se relit tel quel');
});

test('un sujet long est coupé en mots de 75 caractères au plus', function () {
    // Le sujet du rapport du cron dépassait 120 caractères d'un seul mot.
    $sujet = '[ANOMALIE] Ma Bibliothèque — rapport du 26/09/2026 (depuis 1 jour 2 heures)';
    $mots  = mots_encodes($sujet);
    vrai(substr_count($mots, "\r\n ") >= 1, 'replié sur plusieurs lignes');
    foreach (explode("\r\n ", $mots) as $mot) {
        vrai(strlen($mot) <= 75, 'mot de ' . strlen($mot) . ' caractères');
    }
    vrai(strlen('Subject: ' . explode("\r\n", $mots)[0]) <= 78, 'la première ligne, nom compris, tient en 78');
    egale($sujet, relire_mots($mots), 'recollés, les mots rendent le sujet exact');
});

test('une lettre accentuée n est jamais coupée en deux', function () {
    /* 41 octets ASCII puis « é » (2 octets) : le mot déborderait de 42
       octets en l'incluant, elle passe donc entière au mot suivant. */
    $mots = explode("\r\n ", mots_encodes(str_repeat('a', 41) . 'é' . str_repeat('b', 10)));
    foreach ($mots as $mot) {
        vrai(mb_check_encoding(relire_mots($mot), 'UTF-8'), 'chaque mot est de l UTF-8 valide à lui seul');
    }
    egale(str_repeat('a', 41), relire_mots($mots[0]), 'le premier s arrête avant la lettre accentuée');
});

groupe('composer_message() — les en-têtes');

test('le corps est annoncé en multipart/alternative, avec SA frontière', function () {
    $d = decomposer(composer_message('a@exemple.test', 'Sujet', 'Corps', 'site@exemple.test', 'Site', 'exemple.test'));
    contient('Content-Type: multipart/alternative; boundary="' . $d['frontiere'] . '"', $d['entetes'], 'l en-tête');
    differe('', $d['frontiere'], 'une frontière est bien déclarée');
    contient('--' . $d['frontiere'] . "\r\n", $d['corps'], 'et utilisée dans le corps');
    contient('MIME-Version: 1.0', $d['entetes'], 'MIME');
});

test('les en-têtes sont en ASCII pur', function () {
    $d = decomposer(composer_message('a@exemple.test', 'Votre mot de passe a été modifié', 'x',
        'site@exemple.test', 'Ma Bibliothèque Manga', 'exemple.test'));
    egale(0, preg_match('/[^\x20-\x7E\r\n]/', $d['entetes']), 'aucun octet accentué brut dans les en-têtes');
});

test('le nom de l expéditeur est encodé et se relit tel quel', function () {
    $d = decomposer(composer_message('a@exemple.test', 'S', 'x', 'site@exemple.test', 'Ma Bibliothèque Manga', 'exemple.test'));
    egale('Ma Bibliothèque Manga', relire_entete($d['entetes'], 'From'), 'le nom affiché');
    contient('<site@exemple.test>', $d['entetes'], 'l adresse d expédition');
});

test('le sujet est encodé et se relit tel quel', function () {
    $d = decomposer(composer_message('a@exemple.test', 'Demande de changement d’adresse e-mail', 'x',
        'site@exemple.test', 'Site', 'exemple.test'));
    egale('Demande de changement d’adresse e-mail', relire_entete($d['entetes'], 'Subject'), 'le sujet');
});

test('le destinataire et l identifiant du message', function () {
    $d = decomposer(composer_message('lecteur@exemple.test', 'S', 'x', 'site@exemple.test', 'Site', 'exemple.test'));
    contient('To: <lecteur@exemple.test>', $d['entetes'], 'le destinataire');
    motif('/^Message-ID: <[a-f0-9]{32}@exemple\.test>\r?$/m', $d['entetes'], 'Message-ID sur le domaine du site');
});

test('chaque message a sa propre frontière', function () {
    $a = decomposer(composer_message('a@exemple.test', 'S', 'x', 's@exemple.test', 'Site', 'exemple.test'));
    $b = decomposer(composer_message('a@exemple.test', 'S', 'x', 's@exemple.test', 'Site', 'exemple.test'));
    differe($a['frontiere'], $b['frontiere'], 'tirée au hasard à chaque message');
});

test('un vrai avis de sécurité : son lien est cliquable', function () {
    /* Le texte de avertir_mot_de_passe_change(), qui n'envoie rien pendant
       les tests : on compose donc son message à partir du même texte. */
    $texte = "Bonjour lecteur92,\n\nSINON, votre compte est compromis : reprenez-en le contrôle "
        . "immédiatement en demandant un nouveau mot de passe ici :\n" . url_publique('mot-de-passe-oublie.php');
    $d = decomposer(composer_message('a@exemple.test', 'Votre mot de passe a été modifié', $texte,
        'site@exemple.test', 'Ma Bibliothèque Manga', 'exemple.test'));
    contient('<a href="https://exemple.test/bibliotheque/mot-de-passe-oublie.php"', (string) $d['parties'][1]['contenu'],
        'le lien de reprise du compte');
});
