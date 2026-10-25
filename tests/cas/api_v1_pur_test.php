<?php
/* =====================================================================
   API JSON v1 — les fonctions pures (includes/api_v1.php).

   Rien ici ne touche la base ni le réseau : la route, la validation
   des entrées, la forme du jeton, la pagination, la sortie d'une série.
   Le comportement de bout en bout (codes HTTP, cloisonnement entre
   comptes, jetons en base) est dans api_v1_http_test.php.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';
require_once CHEMIN_PROJET . '/includes/couvertures.php';
require_once CHEMIN_PROJET . '/includes/api_v1.php';

groupe('Jeton — forme et empreinte');

test('un jeton généré a la forme attendue et est différent à chaque fois', function () {
    $a = api_jeton_generer();
    $b = api_jeton_generer();
    vrai(api_jeton_format_valide($a), 'le format est reconnu');
    differe($a, $b, 'deux jetons ne se ressemblent pas');
    egale(68, strlen($a), 'lbm_ + 64 caractères hexadécimaux (256 bits)');
});

test('des formes qui ne sont pas un jeton sont écartées avant la base', function () {
    foreach (['', 'lbm_', 'lbm_xyz', 'abc_' . str_repeat('a', 64), 'lbm_' . str_repeat('A', 64),
              'lbm_' . str_repeat('a', 65), "lbm_" . str_repeat('a', 63) . "\n"] as $mauvais) {
        faux(api_jeton_format_valide($mauvais), 'refusé : ' . montrer($mauvais));
    }
});

test('l\'empreinte est un sha256 et ne ressemble pas au jeton', function () {
    $j = api_jeton_generer();
    $h = api_jeton_hash($j);
    egale(64, strlen($h), 'sha256 en hexadécimal');
    sans($j, $h, 'le jeton n\'apparaît pas dans son empreinte');
    egale($h, api_jeton_hash($j), 'déterministe : c\'est ce qui permet la recherche');
});

groupe('En-tête Authorization');

test('Bearer est lu, avec ou sans la casse', function () {
    $j = api_jeton_generer();
    egale($j, api_jeton_extraire(['HTTP_AUTHORIZATION' => 'Bearer ' . $j]), 'forme standard');
    egale($j, api_jeton_extraire(['HTTP_AUTHORIZATION' => 'bearer ' . $j]), 'casse libre');
});

test('la copie faite par Apache (REDIRECT_…) est lue aussi', function () {
    $j = api_jeton_generer();
    egale($j, api_jeton_extraire(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $j]), 'variante OVH');
});

test('les autres schémas et les en-têtes mal formés donnent null', function () {
    foreach ([[], ['HTTP_AUTHORIZATION' => ''], ['HTTP_AUTHORIZATION' => 'Basic abc'],
              ['HTTP_AUTHORIZATION' => 'Bearer'], ['HTTP_AUTHORIZATION' => 'Bearer a b'],
              ['HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('x', 300)]] as $serveur) {
        estNul(api_jeton_extraire($serveur), 'refusé : ' . montrer($serveur));
    }
});

groupe('HTTPS');

test('HTTPS est exigé, sauf depuis la boucle locale', function () {
    vrai(api_https_ok(['HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.5']), 'https accepté');
    faux(api_https_ok(['REMOTE_ADDR' => '203.0.113.5']), 'http depuis Internet refusé');
    faux(api_https_ok(['HTTPS' => 'off', 'REMOTE_ADDR' => '203.0.113.5']), '« off » ne vaut pas https');
    vrai(api_https_ok(['REMOTE_ADDR' => '127.0.0.1']), 'boucle locale (développement)');
    vrai(api_https_ok(['REMOTE_ADDR' => '::1']), 'boucle locale IPv6');
});

test('l\'en-tête Host ne peut pas désactiver l\'exigence', function () {
    faux(api_https_ok(['HTTP_HOST' => 'localhost', 'REMOTE_ADDR' => '203.0.113.5']),
        'un Host « localhost » falsifié ne suffit pas');
});

groupe('Route');

test('chaque route existante est reconnue avec sa méthode', function () {
    egale(['route' => 'moi', 'id' => null], api_route('GET', 'v1/moi'), 'moi');
    egale(['route' => 'series.lister', 'id' => null], api_route('GET', 'v1/series'), 'liste');
    egale(['route' => 'series.creer', 'id' => null], api_route('POST', 'v1/series'), 'création');
    egale(['route' => 'series.lire', 'id' => 12], api_route('GET', 'v1/series/12'), 'lecture');
    egale(['route' => 'series.modifier', 'id' => 12], api_route('PATCH', 'v1/series/12'), 'modification');
    egale(['route' => 'couvertures.chercher', 'id' => 12],
        api_route('POST', 'v1/series/12/couvertures/recherche'), 'recherche de couverture');
    egale(['route' => 'couvertures.choisir', 'id' => 12],
        api_route('PUT', 'v1/series/12/couverture'), 'choix de la couverture');
});

test('il n\'y a AUCUNE route de suppression', function () {
    foreach (['v1/series/12', 'v1/series', 'v1/series/12/couverture', 'v1/moi'] as $chemin) {
        $r = api_route('DELETE', $chemin);
        egale(405, $r['erreur'] ?? null, 'DELETE ' . $chemin . ' refusé');
        faux(in_array('DELETE', $r['permises'] ?? [], true), 'DELETE n\'est jamais permis');
    }
});

test('une mauvaise méthode donne 405 avec la liste des permises', function () {
    $r = api_route('PUT', 'v1/series/12');
    egale(405, $r['erreur'], 'méthode refusée');
    egale(['GET', 'PATCH'], $r['permises'], 'les méthodes de cette adresse');
});

test('un chemin inconnu ou tordu donne 404', function () {
    foreach (['', 'v2/series', 'v1/series/0', 'v1/series/-1', 'v1/series/abc', 'v1/series/012',
              'v1/series/12345678901', 'v1/series/12/', 'v1/series/12/autre', 'v1/../api.php',
              'v1/series/12/couvertures', 'x/v1/series'] as $chemin) {
        $r = api_route('GET', $chemin);
        $r2 = api_route('POST', $chemin);
        vrai(($r['erreur'] ?? 0) !== 0 || ($r2['erreur'] ?? 0) !== 0 || $chemin === 'v1/series/12/',
            'pas de route pour ' . montrer($chemin));
    }
    egale(404, api_route('GET', 'v2/series')['erreur'], '404 et non 405');
});

test('les barres obliques de fin sont tolérées', function () {
    egale('series.lire', api_route('GET', '/v1/series/12/')['route'], 'normalisé');
});

test('api_chemin retire le dossier de l\'API et la chaîne de requête', function () {
    egale('v1/series/12', api_chemin('/api/v1/series/12?x=1', '/api/index.php'), 'à la racine');
    egale('v1/series', api_chemin('/Livre/api/v1/series', '/Livre/api/index.php'), 'dans un sous-dossier');
    egale('v1/series/12', api_chemin('/api/v1/series/12/', '/api/index.php'), 'barre finale');
});

groupe('Corps de la requête');

test('un objet JSON valide est accepté', function () {
    $r = api_corps_decoder('{"titre":"Berserk"}', 'application/json', true);
    egale(['titre' => 'Berserk'], $r['corps'], 'décodé');
    estNul($r['erreur'], 'aucune erreur');
});

test('le charset après le type est toléré', function () {
    estNul(api_corps_decoder('{"a":1}', 'application/json; charset=utf-8', true)['erreur'], 'accepté');
});

test('corps vide : accepté si facultatif, refusé si obligatoire', function () {
    estNul(api_corps_decoder('', '', false)['erreur'], 'facultatif');
    egale(400, api_corps_decoder('', '', true)['erreur'][0], 'obligatoire');
});

test('ce qui n\'est pas un objet JSON est refusé', function () {
    foreach (['[1,2]', '"texte"', '12', 'null', '{pas du json', '{"a":'] as $brut) {
        egale(400, api_corps_decoder($brut, 'application/json', true)['erreur'][0] ?? 0, 'refusé : ' . $brut);
    }
});

test('un autre type de contenu donne 415', function () {
    egale(415, api_corps_decoder('titre=x', 'application/x-www-form-urlencoded', true)['erreur'][0], '415');
    egale(415, api_corps_decoder('{"a":1}', '', true)['erreur'][0], 'sans type : 415');
});

test('un corps trop gros donne 413, avant même de le décoder', function () {
    $gros = '{"titre":"' . str_repeat('a', API_CORPS_MAX) . '"}';
    egale(413, api_corps_decoder($gros, 'application/json', true)['erreur'][0], '413');
});

test('un JSON trop profond est refusé', function () {
    $profond = str_repeat('{"a":', 20) . '1' . str_repeat('}', 20);
    egale(400, api_corps_decoder($profond, 'application/json', true)['erreur'][0], 'profondeur bornée');
});

groupe('Validation d\'une série');

test('une série complète et correcte passe, avec les noms de colonnes', function () {
    [$d, $e] = api_valider_serie([
        'titre' => '  One Piece ', 'auteur' => 'Eiichiro Oda', 'tome_lu' => 105,
        'statut' => 'cours', 'favori' => true,
    ], true);
    vide($e, 'aucune erreur');
    egale(['titre' => 'One Piece', 'auteur' => 'Eiichiro Oda', 'tome_actuel' => 105,
           'statut' => 'cours', 'favori' => 1], $d, 'données prêtes pour le SQL');
});

test('à la création, le titre est obligatoire ; à la modification, non', function () {
    egale(['titre'], array_keys(api_valider_serie([], true)[1]), 'création sans titre');
    vide(api_valider_serie(['favori' => false], false)[1], 'modification sans titre');
});

test('un titre vide ou blanc est refusé', function () {
    foreach (['', '   ', "\t\n"] as $vide) {
        vrai(isset(api_valider_serie(['titre' => $vide], false)[1]['titre']), 'refusé : ' . montrer($vide));
    }
});

test('les longueurs sont bornées à 190 SANS troncature silencieuse', function () {
    vide(api_valider_serie(['titre' => str_repeat('é', 190)], true)[1], '190 caractères : accepté');
    vrai(isset(api_valider_serie(['titre' => str_repeat('é', 191)], true)[1]['titre']), '191 : refusé');
    vrai(isset(api_valider_serie(['titre' => 'x', 'auteur' => str_repeat('a', 191)], true)[1]['auteur']),
        'même règle pour l\'auteur');
});

test('un auteur vide est permis (c\'est ainsi qu\'on l\'efface)', function () {
    [$d, $e] = api_valider_serie(['titre' => 'x', 'auteur' => ''], true);
    vide($e, 'accepté');
    egale('', $d['auteur'], 'vidé');
});

test('les retours à la ligne et caractères de contrôle sont nettoyés', function () {
    [$d] = api_valider_serie(['titre' => "Ligne1\nLigne2\x00"], true);
    egale('Ligne1 Ligne2', $d['titre'], 'même nettoyage que le site (texte())');
});

test('tome_lu : entier de 0 à TOME_MAX, rien d\'autre', function () {
    foreach ([0, 1, TOME_MAX] as $ok) {
        vide(api_valider_serie(['tome_lu' => $ok], false)[1], 'accepté : ' . $ok);
    }
    foreach ([-1, TOME_MAX + 1, 1.5, 3.0, '3', true, null, [], 'abc'] as $mauvais) {
        vrai(isset(api_valider_serie(['tome_lu' => $mauvais], false)[1]['tome_lu']),
            'refusé : ' . montrer($mauvais));
    }
});

test('statut : seulement les quatre statuts du site', function () {
    foreach (['cours', 'envie', 'termine', 'abandon'] as $ok) {
        vide(api_valider_serie(['statut' => $ok], false)[1], 'accepté : ' . $ok);
    }
    foreach (['attente', 'En cours', 'COURS', '', 1, null, ['cours'], true] as $mauvais) {
        vrai(isset(api_valider_serie(['statut' => $mauvais], false)[1]['statut']),
            'refusé : ' . montrer($mauvais));
    }
});

test('favori : un vrai booléen seulement', function () {
    vide(api_valider_serie(['favori' => true], false)[1], 'true');
    vide(api_valider_serie(['favori' => false], false)[1], 'false');
    foreach ([1, 0, '1', 'true', 'oui', null, []] as $mauvais) {
        vrai(isset(api_valider_serie(['favori' => $mauvais], false)[1]['favori']),
            'refusé : ' . montrer($mauvais));
    }
});

test('un champ inconnu est refusé — dont ceux qui ne se règlent pas par ici', function () {
    foreach (['id', 'utilisateur_id', 'couverture', 'mangadex_id', 'tome_actuel', 'cree_le', 'maj_le', 'x'] as $champ) {
        $e = api_valider_serie(['titre' => 'x', $champ => 'valeur'], true)[1];
        egale('Champ inconnu.', $e[$champ] ?? null, 'refusé : ' . $champ);
    }
});

test('toutes les erreurs sont rendues d\'un coup, rangées par champ', function () {
    $e = api_valider_serie(['titre' => '', 'tome_lu' => -1, 'statut' => 'x', 'favori' => 'oui'], true)[1];
    egale(['titre', 'tome_lu', 'statut', 'favori'], array_keys($e), 'un message par champ fautif');
});

test('les données ne portent jamais que des noms de colonnes connus', function () {
    [$d] = api_valider_serie(['titre' => 'a', 'auteur' => 'b', 'tome_lu' => 1, 'statut' => 'envie', 'favori' => true], true);
    egale(['titre', 'auteur', 'tome_actuel', 'statut', 'favori'], array_keys($d),
        'c\'est cette liste qui construit le SET du UPDATE');
});

groupe('Validation de la recherche de couverture');

test('le tome est facultatif ; s\'il est donné, entier de 1 à TOME_MAX', function () {
    egale([null, []], api_valider_recherche([]), 'absent');
    egale([4, []], api_valider_recherche(['tome' => 4]), 'donné');
    foreach ([0, -1, TOME_MAX + 1, '2', 1.0, null, true] as $mauvais) {
        vrai(isset(api_valider_recherche(['tome' => $mauvais])[1]['tome']), 'refusé : ' . montrer($mauvais));
    }
    vrai(isset(api_valider_recherche(['titre' => 'x'])[1]['titre']), 'champ inconnu refusé');
});

groupe('Adresse de couverture acceptée');

test('seule une image de couverture MangaDex est acceptée', function () {
    $u = 'https://uploads.mangadex.org/covers/a1b2c3d4-0000-4000-8000-0123456789ab/fichier-1.jpg.512.jpg';
    vrai(api_couverture_url_acceptee($u), 'une proposition de la recherche');
});

test('tout le reste est refusé', function () {
    $uuid = 'a1b2c3d4-0000-4000-8000-0123456789ab';
    foreach ([
        'http://uploads.mangadex.org/covers/' . $uuid . '/x.jpg',
        'https://evil.example/covers/' . $uuid . '/x.jpg',
        'https://uploads.mangadex.org.evil.example/covers/' . $uuid . '/x.jpg',
        'https://uploads.mangadex.org/covers/' . $uuid . '/x.jpg?a=b',
        'https://uploads.mangadex.org/covers/' . $uuid . '/x.jpg#f',
        'https://uploads.mangadex.org/covers/' . $uuid . '/../x.jpg',
        'https://uploads.mangadex.org/covers/' . $uuid . '/..x.jpg',
        'https://uploads.mangadex.org/covers/pas-un-uuid/x.jpg',
        'https://uploads.mangadex.org/covers/' . $uuid . '/x.svg',
        'https://uploads.mangadex.org/covers/' . $uuid . '/',
        'https://user@uploads.mangadex.org/covers/' . $uuid . '/x.jpg',
        'javascript:alert(1)', 'uploads/image.webp', '', 'data:image/png;base64,AAAA',
        'https://uploads.mangadex.org/covers/' . $uuid . '/' . str_repeat('a', 500) . '.jpg',
        null, 12, ['x'], true,
    ] as $mauvaise) {
        faux(api_couverture_url_acceptee($mauvaise), 'refusée : ' . montrer($mauvaise));
    }
});

test('le lien MangaDex se déduit de l\'adresse acceptée', function () {
    $u = 'https://uploads.mangadex.org/covers/A1B2C3D4-0000-4000-8000-0123456789AB/x.jpg';
    egale('a1b2c3d4-0000-4000-8000-0123456789ab', mangadex_id_depuis_url($u), 'en minuscules, comme le site');
});

groupe('Filtres et pagination d\'une liste');

test('sans paramètre : 50 résultats, depuis le début', function () {
    $p = api_liste_parametres([]);
    egale(50, $p['limite'], 'limite par défaut');
    egale(0, $p['decalage'], 'décalage');
    egale('', $p['where'], 'aucun filtre');
    vide($p['erreurs'], 'aucune erreur');
});

test('q cherche dans le titre ET l\'auteur, avec des « ? » seulement', function () {
    $p = api_liste_parametres(['q' => 'berserk']);
    egale(' AND (titre LIKE ? OR auteur LIKE ?)', $p['where'], 'forme du filtre');
    egale(['%berserk%', '%berserk%'], $p['params'], 'valeurs liées');
});

test('les jokers LIKE saisis par le client sont neutralisés', function () {
    $p = api_liste_parametres(['q' => '100%_\\']);
    egale('%100\\%\\_\\\\%', $p['params'][0], '% _ et \\ échappés : « 100% » ne cherche pas tout');
});

test('une injection SQL reste une valeur, jamais du SQL', function () {
    $p = api_liste_parametres(['q' => "' OR 1=1 --", 'statut' => 'cours']);
    sans('1=1', $p['where'], 'le SQL ne contient pas la saisie');
    contient("' OR 1=1 --", $p['params'][0], 'elle voyage en paramètre');
});

test('statut et favori', function () {
    $p = api_liste_parametres(['statut' => 'termine', 'favori' => 'true']);
    egale(' AND statut = ? AND favori = 1', $p['where'], 'filtres combinés');
    egale(['termine'], $p['params'], 'valeur liée');
    contient('favori = 0', api_liste_parametres(['favori' => '0'])['where'], 'favori=0');
    vrai(isset(api_liste_parametres(['statut' => 'attente'])['erreurs']['statut']), 'statut inconnu refusé');
    vrai(isset(api_liste_parametres(['favori' => 'peut-être'])['erreurs']['favori']), 'favori invalide refusé');
});

test('limite et décalage : entiers bornés', function () {
    egale(100, api_liste_parametres(['limite' => '100'])['limite'], '100 accepté');
    egale(30, api_liste_parametres(['decalage' => '30'])['decalage'], 'décalage accepté');
    foreach (['0', '101', '-1', 'abc', '1.5', '', ' 5'] as $mauvaise) {
        vrai(isset(api_liste_parametres(['limite' => $mauvaise])['erreurs']['limite']),
            'limite refusée : ' . montrer($mauvaise));
    }
    foreach (['-1', 'x', '1.5', '99999999'] as $mauvais) {
        vrai(isset(api_liste_parametres(['decalage' => $mauvais])['erreurs']['decalage']),
            'décalage refusé : ' . montrer($mauvais));
    }
});

test('un paramètre répété (tableau) est refusé plutôt que mal compris', function () {
    vrai(isset(api_liste_parametres(['q' => ['a', 'b']])['erreurs']['q']), 'q[]');
    vrai(isset(api_liste_parametres(['statut' => ['cours']])['erreurs']['statut']), 'statut[]');
    vrai(isset(api_liste_parametres(['limite' => ['5']])['erreurs']['limite']), 'limite[]');
});

groupe('Une série vue par l\'API');

test('la forme de sortie', function () {
    $j = api_serie_json([
        'id' => '7', 'titre' => 'Berserk', 'auteur' => 'Kentaro Miura', 'tome_actuel' => '41',
        'statut' => 'cours', 'couverture' => '', 'mangadex_id' => '', 'favori' => '1',
        'cree_le' => '2026-01-02 03:04:05', 'maj_le' => '2026-02-03 04:05:06',
    ]);
    egale(7, $j['id'], 'id entier');
    egale(41, $j['tome_lu'], 'tome lu');
    egale(42, $j['tome_suivant'], 'tome suivant');
    egale('cours', $j['statut'], 'code du statut');
    egale('En cours', $j['statut_libelle'], 'libellé');
    egale(true, $j['favori'], 'booléen');
    egale(['url' => null, 'source' => 'aucune', 'mangadex_id' => null], $j['couverture'], 'sans couverture');
    motif('/^2026-01-02T03:04:05[+-]\d\d:\d\d$/', $j['cree_le'], 'date ISO 8601');
});

test('une couverture locale devient une adresse absolue', function () {
    $j = api_serie_json(['id' => 1, 'titre' => 'x', 'auteur' => '', 'tome_actuel' => 0, 'statut' => 'envie',
        'couverture' => 'uploads/abc123.webp', 'mangadex_id' => '', 'favori' => 0]);
    egale(APP_URL . '/uploads/abc123.webp', $j['couverture']['url'], 'préfixée par APP_URL');
    egale('importee', $j['couverture']['source'], 'source');
});

test('une couverture MangaDex porte son lien', function () {
    $u = 'https://uploads.mangadex.org/covers/a1b2c3d4-0000-4000-8000-0123456789ab/x.jpg';
    $j = api_serie_json(['id' => 1, 'titre' => 'x', 'auteur' => '', 'tome_actuel' => 0, 'statut' => 'envie',
        'couverture' => $u, 'mangadex_id' => 'a1b2c3d4-0000-4000-8000-0123456789ab', 'favori' => 0]);
    egale($u, $j['couverture']['url'], 'adresse');
    egale('mangadex', $j['couverture']['source'], 'source');
    egale('a1b2c3d4-0000-4000-8000-0123456789ab', $j['couverture']['mangadex_id'], 'lien');
});

test('la sortie ne contient aucun champ interne', function () {
    $j = api_serie_json(['id' => 1, 'titre' => 'x', 'auteur' => '', 'tome_actuel' => 0, 'statut' => 'envie',
        'couverture' => '', 'mangadex_id' => '', 'favori' => 0, 'utilisateur_id' => 99]);
    sans('utilisateur_id', implode(',', array_keys($j)), 'pas d\'identifiant de compte');
});
