<?php
/* =====================================================================
   API JSON v1 — de bout en bout, sur le fil.

   Un vrai serveur HTTP (le serveur intégré de PHP) devant une vraie base
   jetable (Livre_test, voir tests/base_de_test.php). On y vérifie ce que
   les fonctions pures ne peuvent pas : les codes de réponse, les
   en-têtes, le cloisonnement entre comptes, les quotas appliqués par la
   base, les jetons révoqués ou expirés, les freins.

   Ce qui n'y est PAS : l'appel réel à MangaDex. Il dépend d'un réseau et
   d'un service tiers ; une suite qui échoue quand MangaDex dort ne prouve
   rien. On vérifie donc tout ce qui précède l'appel (jeton, série,
   corps, quota) et tout ce qui le suit (choix de la couverture).
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../base_de_test.php';
preparer_base_de_test();
require __DIR__ . '/../lanceur.php';
require_once CHEMIN_SITE . '/includes/couvertures.php';
require_once CHEMIN_SITE . '/includes/api_v1.php';

$bd = pdo_de_test();

/* Les serveurs, démarrés une fois. Chacun porte sa configuration : les
   constantes se figent au chargement (voir tests/processus.php). */
$serveurs = [
    'normal'  => demarrer_serveur_api(),
    'quota'   => demarrer_serveur_api(['MAX_SERIES_PAR_UTILISATEUR' => '3', 'COUVERTURE_QUOTA' => '1']),
    'limite'  => demarrer_serveur_api(['API_REQUETES_MAX' => '3', 'API_FENETRE' => '60']),
    'echecs'  => demarrer_serveur_api(['API_ECHECS_MAX' => '3', 'API_ECHECS_BLOCAGE' => '60']),
];
$arreter_tout = static function () use (&$serveurs): void {
    foreach ($serveurs as $s) {
        ($s['arreter'])();
    }
    $serveurs = [];
};
register_shutdown_function($arreter_tout);

$u = $serveurs['normal']['url'];
$A = compte_de_test($bd, 'a');
$B = compte_de_test($bd, 'b');

/** Crée une série par l'API et rend sa représentation. */
$creer = static function (array $corps, string $jeton) use ($u): array {
    $r = appel_api($u, 'POST', '/api/v1/series', $jeton, $corps);
    if ($r['code'] !== 201) {
        throw new RuntimeException('création refusée : ' . $r['brut']);
    }
    return $r['json']['serie'];
};

/** Aucune erreur ne doit trahir l'intérieur : SQL, chemins, noms de classe. */
$sans_fuite = static function (array $r, string $quoi): void {
    foreach (['SQLSTATE', 'PDO', 'Stack trace', '.php', 'mysql', 'Exception', 'Warning', 'Notice'] as $mot) {
        sans($mot, $r['brut'], $quoi . ' : pas de « ' . $mot . ' » dans la réponse');
    }
};

/* ---------------------------------------------------------------------
   Accès refusé
   --------------------------------------------------------------------- */
groupe('Accès — jeton absent, invalide, révoqué, expiré');

test('sans jeton : 401 jeton_invalide', function () use ($u, $sans_fuite) {
    foreach (['GET /api/v1/series', 'GET /api/v1/moi', 'POST /api/v1/series', 'GET /api/v1/series/1',
              'PATCH /api/v1/series/1', 'POST /api/v1/series/1/couvertures/recherche',
              'PUT /api/v1/series/1/couverture', 'DELETE /api/v1/series/1', 'GET /api/v1/inconnu'] as $requete) {
        [$m, $chemin] = explode(' ', $requete);
        $r = appel_api($u, $m, $chemin, null, $m === 'GET' || $m === 'DELETE' ? null : ['titre' => 'x']);
        egale(401, $r['code'], $requete . ' → 401');
        egale('jeton_invalide', $r['json']['erreur']['code'] ?? null, $requete . ' : code stable');
        $sans_fuite($r, $requete);
    }
});

test('401 annonce le schéma Bearer, sans ouvrir de session ni de cookie', function () use ($u) {
    $r = appel_api($u, 'GET', '/api/v1/series');
    contient('Bearer', $r['entetes']['www-authenticate'] ?? '', 'WWW-Authenticate');
    faux(isset($r['entetes']['set-cookie']), 'aucun cookie posé');
});

test('un jeton inconnu, mal formé ou d\'un autre schéma : 401 identique', function () use ($u) {
    $inconnu = 'lbm_' . str_repeat('0', 64);
    foreach ([$inconnu, 'pas-un-jeton', 'lbm_court', str_repeat('x', 150)] as $jeton) {
        $r = appel_api($u, 'GET', '/api/v1/series', $jeton);
        egale(401, $r['code'], 'refusé : ' . substr($jeton, 0, 20));
        egale('jeton_invalide', $r['json']['erreur']['code'], 'même réponse : on ne dit pas pourquoi');
    }
    $r = appel_api($u, 'GET', '/api/v1/series', null, null, ['Authorization: Basic dXNlcjpwYXNz']);
    egale(401, $r['code'], 'Basic refusé');
});

test('le jeton dans l\'URL ou dans un autre en-tête n\'est pas lu', function () use ($u, $A) {
    egale(401, appel_api($u, 'GET', '/api/v1/series?jeton=' . $A['jeton'])['code'], 'dans la requête');
    egale(401, appel_api($u, 'GET', '/api/v1/series', null, null, ['X-Api-Key: ' . $A['jeton']])['code'], 'X-Api-Key');
});

test('un jeton révoqué est refusé aussitôt', function () use ($u, $bd, $A) {
    $j = api_jeton_creer($bd, $A['id'], 'a révoquer');
    egale(200, appel_api($u, 'GET', '/api/v1/moi', $j['jeton'])['code'], 'valable avant');
    $bd->prepare('UPDATE jeton_api SET revoque_le = NOW() WHERE id = ?')->execute([$j['id']]);
    $r = appel_api($u, 'GET', '/api/v1/moi', $j['jeton']);
    egale(401, $r['code'], 'refusé après');
    egale('jeton_invalide', $r['json']['erreur']['code'], 'sans révéler qu\'il a existé');
});

test('un jeton expiré : 401 jeton_expire (colonne facultative)', function () use ($u, $bd, $A) {
    $j = api_jeton_creer($bd, $A['id'], 'expirera');
    $bd->prepare('UPDATE jeton_api SET expire = NOW() - INTERVAL 1 HOUR WHERE id = ?')->execute([$j['id']]);
    $r = appel_api($u, 'GET', '/api/v1/moi', $j['jeton']);
    egale(401, $r['code'], 'refusé');
    egale('jeton_expire', $r['json']['erreur']['code'], 'code explicite pour l\'application');

    $bd->prepare('UPDATE jeton_api SET expire = NOW() + INTERVAL 1 HOUR WHERE id = ?')->execute([$j['id']]);
    egale(200, appel_api($u, 'GET', '/api/v1/moi', $j['jeton'])['code'], 'valable tant que la date n\'est pas passée');
});

test('par défaut un jeton n\'expire jamais', function () use ($bd, $A) {
    $req = $bd->prepare('SELECT expire FROM jeton_api WHERE id = ?');
    $req->execute([$A['jeton_id']]);
    estNul($req->fetchColumn(), 'expire vaut NULL');
});

test('la base ne garde que l\'empreinte du jeton', function () use ($bd, $A) {
    $req = $bd->prepare('SELECT * FROM jeton_api WHERE id = ?');
    $req->execute([$A['jeton_id']]);
    $ligne = $req->fetch();
    sans($A['jeton'], implode('|', array_map('strval', $ligne)), 'le jeton n\'est dans aucune colonne');
    egale(api_jeton_hash($A['jeton']), $ligne['jeton_hash'], 'seule l\'empreinte y est');
});

test('plusieurs jetons valides en même temps (rotation)', function () use ($u, $bd, $A) {
    $nouveau = api_jeton_creer($bd, $A['id'], 'rotation');
    egale(200, appel_api($u, 'GET', '/api/v1/moi', $A['jeton'])['code'], 'l\'ancien marche');
    egale(200, appel_api($u, 'GET', '/api/v1/moi', $nouveau['jeton'])['code'], 'le nouveau aussi');
});

test('au plus ' . API_JETONS_MAX . ' jetons actifs par compte', function () use ($bd) {
    $C = compte_de_test($bd, 'plafond');
    for ($i = 1; $i < API_JETONS_MAX; $i++) {
        vrai(api_jeton_creer($bd, $C['id'], 'n' . $i) !== null, 'jeton ' . ($i + 1) . ' accepté');
    }
    estNul(api_jeton_creer($bd, $C['id'], 'de trop'), 'le suivant est refusé');
    $bd->prepare('UPDATE jeton_api SET revoque_le = NOW() WHERE id = ?')->execute([$C['jeton_id']]);
    vrai(api_jeton_creer($bd, $C['id'], 'après révocation') !== null, 'révoquer libère une place');
});

/* ---------------------------------------------------------------------
   En-têtes
   --------------------------------------------------------------------- */
groupe('En-têtes de réponse');

test('JSON, jamais mis en cache, pas de CORS, pas de cookie', function () use ($u, $A) {
    $r = appel_api($u, 'GET', '/api/v1/moi', $A['jeton']);
    egale(200, $r['code'], 'accepté');
    contient('application/json', $r['entetes']['content-type'] ?? '', 'type JSON');
    contient('no-store', $r['entetes']['cache-control'] ?? '', 'jamais en cache');
    egale('nosniff', $r['entetes']['x-content-type-options'] ?? '', 'nosniff');
    faux(isset($r['entetes']['access-control-allow-origin']), 'aucun CORS ouvert');
    faux(isset($r['entetes']['set-cookie']), 'aucun cookie');
});

test('une demande de pré-vérification CORS n\'obtient aucune autorisation', function () use ($u, $A) {
    $r = appel_api($u, 'OPTIONS', '/api/v1/series', $A['jeton'], null,
        ['Origin: https://autre-site.example', 'Access-Control-Request-Method: GET']);
    egale(405, $r['code'], 'OPTIONS n\'est pas une méthode de l\'API');
    faux(isset($r['entetes']['access-control-allow-origin']), 'pas d\'Access-Control-Allow-Origin');
});

test('les erreurs ne trahissent rien de l\'intérieur', function () use ($u, $A, $sans_fuite) {
    foreach ([
        ['GET', '/api/v1/series/99999999999'], ['GET', '/api/v1/series/abc'], ['GET', '/api/v1/rien'],
        ['PUT', '/api/v1/series'], ['GET', '/api/v1/series?limite=-1'], ['GET', '/api/v1/series?q[]=x'],
    ] as [$m, $chemin]) {
        $r = appel_api($u, $m, $chemin, $A['jeton']);
        $sans_fuite($r, $m . ' ' . $chemin);
        vrai(isset($r['json']['erreur']['code']), $m . ' ' . $chemin . ' : une erreur JSON structurée');
    }
});

/* ---------------------------------------------------------------------
   Compte
   --------------------------------------------------------------------- */
groupe('GET /v1/moi');

test('donne le compte, ses limites et les statuts autorisés', function () use ($u, $A) {
    $c = appel_api($u, 'GET', '/api/v1/moi', $A['jeton'])['json']['compte'];
    egale('api_test_a', $c['identifiant'], 'identifiant');
    egale('standard', $c['forfait'], 'forfait');
    egale(['cours', 'attente', 'envie', 'termine', 'abandon'], array_keys($c['statuts']), 'les cinq statuts');
    egale('En cours', $c['statuts']['cours'], 'libellés');
    egale(1000, $c['requetes']['max'], 'limite de requêtes annoncée');
    sans('email', json_encode($c), 'ne donne pas l\'adresse e-mail');
});

/* ---------------------------------------------------------------------
   Création
   --------------------------------------------------------------------- */
groupe('POST /v1/series — ajouter');

test('le minimum : un titre. Valeurs par défaut du site', function () use ($u, $A) {
    $r = appel_api($u, 'POST', '/api/v1/series', $A['jeton'], ['titre' => 'Berserk']);
    egale(201, $r['code'], '201 Created');
    $s = $r['json']['serie'];
    egale('Berserk', $s['titre'], 'titre');
    egale('', $s['auteur'], 'auteur vide');
    egale(0, $s['tome_lu'], 'série non commencée');
    egale('cours', $s['statut'], 'statut par défaut du site');
    egale(false, $s['favori'], 'pas favori');
    egale(null, $s['couverture']['url'], 'pas de couverture');
    motif('#/api/v1/series/' . $s['id'] . '$#', $r['entetes']['location'] ?? '', 'Location pointe vers la série');
});

test('tous les champs, accents et emoji compris', function () use ($u, $A, $creer) {
    $s = $creer(['titre' => 'L\'Attaque des Titans 進撃 🗡', 'auteur' => 'Hajime Isayama — 諫山創',
                 'tome_lu' => 12, 'statut' => 'termine', 'favori' => true], $A['jeton']);
    egale('L\'Attaque des Titans 進撃 🗡', $s['titre'], 'UTF-8 intact');
    egale('Hajime Isayama — 諫山創', $s['auteur'], 'auteur');
    egale(12, $s['tome_lu'], 'tome lu');
    egale(13, $s['tome_suivant'], 'tome suivant');
    egale('Terminée', $s['statut_libelle'], 'libellé');
    egale(true, $s['favori'], 'favori');
});

test('chacun des quatre statuts est enregistrable', function () use ($u, $A, $creer) {
    foreach (['cours' => 'En cours', 'envie' => 'Envie', 'termine' => 'Terminée', 'abandon' => 'Abandonnée'] as $code => $libelle) {
        $s = $creer(['titre' => 'Statut ' . $code, 'statut' => $code], $A['jeton']);
        egale($libelle, $s['statut_libelle'], $code);
    }
});

test('un titre piégé est stocké tel quel (l\'API ne rend jamais de HTML)', function () use ($u, $A, $creer) {
    $s = $creer(['titre' => '<script>alert(1)</script>"\' OR 1=1 --'], $A['jeton']);
    egale('<script>alert(1)</script>"\' OR 1=1 --', $s['titre'], 'valeur brute, en JSON');
});

test('entrées invalides : 422 et un message par champ', function () use ($u, $A) {
    $r = appel_api($u, 'POST', '/api/v1/series', $A['jeton'],
        ['titre' => '', 'tome_lu' => -3, 'statut' => 'inconnu', 'favori' => 'oui']);
    egale(422, $r['code'], '422');
    egale('validation', $r['json']['erreur']['code'], 'code');
    egale(['titre', 'tome_lu', 'statut', 'favori'], array_keys($r['json']['erreur']['champs']), 'champs fautifs');
});

test('« En attente » est refusé : seul le code du statut est accepté', function () use ($u, $A) {
    foreach (['inconnu', 'En attente', 'en_attente'] as $statut) {
        egale(422, appel_api($u, 'POST', '/api/v1/series', $A['jeton'], ['titre' => 'x', 'statut' => $statut])['code'], $statut);
    }
});

test('sans titre, tome trop grand, champ inconnu : 422', function () use ($u, $A) {
    egale(422, appel_api($u, 'POST', '/api/v1/series', $A['jeton'], ['auteur' => 'x'])['code'], 'sans titre');
    egale(422, appel_api($u, 'POST', '/api/v1/series', $A['jeton'], ['titre' => 'x', 'tome_lu' => TOME_MAX + 1])['code'], 'tome > max');
    egale(422, appel_api($u, 'POST', '/api/v1/series', $A['jeton'], ['titre' => 'x', 'tome_lu' => '3'])['code'], 'tome en texte');
    egale(422, appel_api($u, 'POST', '/api/v1/series', $A['jeton'], ['titre' => 'x', 'utilisateur_id' => 1])['code'], 'utilisateur_id');
    egale(422, appel_api($u, 'POST', '/api/v1/series', $A['jeton'], ['titre' => 'x', 'couverture' => 'https://x.test/a.jpg'])['code'], 'couverture');
});

test('corps absent, mal typé ou illisible : 400, 415', function () use ($u, $A) {
    egale(400, appel_api($u, 'POST', '/api/v1/series', $A['jeton'])['code'], 'corps absent');
    egale(400, appel_api($u, 'POST', '/api/v1/series', $A['jeton'], null, [], '{pas du json')['code'], 'JSON illisible');
    egale(400, appel_api($u, 'POST', '/api/v1/series', $A['jeton'], null, [], '[1,2]')['code'], 'tableau et non objet');
    egale(415, appel_api($u, 'POST', '/api/v1/series', $A['jeton'], null,
        ['Content-Type: application/x-www-form-urlencoded'], 'titre=Berserk')['code'], 'formulaire');
    egale(413, appel_api($u, 'POST', '/api/v1/series', $A['jeton'], null, [],
        '{"titre":"' . str_repeat('a', API_CORPS_MAX) . '"}')['code'], 'corps énorme');
});

/* ---------------------------------------------------------------------
   Lecture et recherche
   --------------------------------------------------------------------- */
groupe('GET /v1/series — rechercher dans la bibliothèque');

test('le compte B a ses propres séries ; A ne les voit jamais', function () use ($u, $B, $creer) {
    $creer(['titre' => 'Série secrète de B', 'auteur' => 'Mystère'], $B['jeton']);
    $creer(['titre' => 'Berserk de B'], $B['jeton']);
    egale(2, appel_api($u, 'GET', '/api/v1/series', $B['jeton'])['json']['total'], 'B en a deux');
});

test('liste : total, limite, décalage, et le plus récemment modifié d\'abord', function () use ($u, $A) {
    $r = appel_api($u, 'GET', '/api/v1/series', $A['jeton'])['json'];
    vrai($r['total'] >= 7, 'les séries créées plus haut');
    egale(50, $r['limite'], 'limite par défaut');
    egale(0, $r['decalage'], 'décalage');
    $ids = array_column($r['series'], 'id');
    $tri = $ids;
    rsort($tri);
    egale($tri, $ids, 'ids décroissants quand les dates sont égales');
});

test('la recherche trouve par titre, sans tenir compte de la casse ni des accents', function () use ($u, $A, $creer) {
    $creer(['titre' => 'Élan Vital', 'auteur' => 'Hiromu Arakawa'], $A['jeton']);
    $trouve = static fn (string $q) => array_column(
        appel_api($u, 'GET', '/api/v1/series?q=' . rawurlencode($q), $A['jeton'])['json']['series'], 'titre');
    contient('Élan Vital', implode('|', $trouve('elan')), 'sans accent');
    contient('Élan Vital', implode('|', $trouve('ÉLAN')), 'majuscules');
    contient('Berserk', implode('|', $trouve('BERSERK')), 'casse');
    contient('Élan Vital', implode('|', $trouve('arakawa')), 'par auteur');
    egale([], $trouve('zzz-introuvable'), 'rien');
});

test('les jokers LIKE saisis sont du texte : « % » et « _ » ne trouvent pas tout', function () use ($u, $A) {
    egale(0, appel_api($u, 'GET', '/api/v1/series?q=' . rawurlencode('%'), $A['jeton'])['json']['total'], '%');
    egale(0, appel_api($u, 'GET', '/api/v1/series?q=' . rawurlencode('_'), $A['jeton'])['json']['total'], '_');
});

test('une injection SQL dans q ne renvoie rien de plus et ne casse rien', function () use ($u, $A) {
    $r = appel_api($u, 'GET', '/api/v1/series?q=' . rawurlencode("x' OR '1'='1"), $A['jeton']);
    egale(200, $r['code'], 'traitée comme un simple texte');
    egale(0, $r['json']['total'], 'aucun résultat');
});

test('filtres statut et favori', function () use ($u, $A) {
    foreach (appel_api($u, 'GET', '/api/v1/series?statut=termine', $A['jeton'])['json']['series'] as $s) {
        egale('termine', $s['statut'], 'que des séries terminées');
    }
    $fav = appel_api($u, 'GET', '/api/v1/series?favori=true', $A['jeton'])['json'];
    vrai($fav['total'] >= 1, 'au moins un favori');
    foreach ($fav['series'] as $s) {
        egale(true, $s['favori'], 'que des favoris');
    }
    foreach (appel_api($u, 'GET', '/api/v1/series?favori=0', $A['jeton'])['json']['series'] as $s) {
        egale(false, $s['favori'], 'que des non-favoris');
    }
});

test('pagination', function () use ($u, $A) {
    $p1 = appel_api($u, 'GET', '/api/v1/series?limite=2&decalage=0', $A['jeton'])['json'];
    $p2 = appel_api($u, 'GET', '/api/v1/series?limite=2&decalage=2', $A['jeton'])['json'];
    egale(2, count($p1['series']), 'deux par page');
    egale($p1['total'], $p2['total'], 'le total ne dépend pas de la page');
    egale([], array_intersect(array_column($p1['series'], 'id'), array_column($p2['series'], 'id')), 'pages distinctes');
    egale([], appel_api($u, 'GET', '/api/v1/series?decalage=100000', $A['jeton'])['json']['series'], 'au-delà de la fin');
});

test('paramètres invalides : 422', function () use ($u, $A) {
    foreach (['limite=0', 'limite=101', 'limite=abc', 'decalage=-1', 'statut=inconnu', 'favori=peut-etre'] as $q) {
        egale(422, appel_api($u, 'GET', '/api/v1/series?' . $q, $A['jeton'])['code'], $q);
    }
});

test('lire une série', function () use ($u, $A, $creer) {
    $s = $creer(['titre' => 'Monster', 'auteur' => 'Naoki Urasawa', 'tome_lu' => 4], $A['jeton']);
    $r = appel_api($u, 'GET', '/api/v1/series/' . $s['id'], $A['jeton']);
    egale(200, $r['code'], 'trouvée');
    egale($s, $r['json']['serie'], 'identique à la création');
});

/* ---------------------------------------------------------------------
   Cloisonnement
   --------------------------------------------------------------------- */
groupe('Cloisonnement — un compte ne touche jamais aux séries d\'un autre');

test('la série d\'un autre compte est introuvable, partout', function () use ($u, $A, $B, $creer, $bd) {
    $s = $creer(['titre' => 'Privée de A', 'tome_lu' => 7], $A['jeton']);
    $id = $s['id'];
    foreach ([
        ['GET', '/api/v1/series/' . $id, null],
        ['PATCH', '/api/v1/series/' . $id, ['tome_lu' => 99]],
        ['POST', '/api/v1/series/' . $id . '/couvertures/recherche', null],
        ['PUT', '/api/v1/series/' . $id . '/couverture',
            ['url' => 'https://uploads.mangadex.org/covers/a1b2c3d4-0000-4000-8000-0123456789ab/x.jpg']],
    ] as [$m, $chemin, $corps]) {
        $r = appel_api($u, $m, $chemin, $B['jeton'], $corps);
        egale(404, $r['code'], $m . ' ' . $chemin . ' → 404 (pas 403 : on ne confirme pas son existence)');
        egale('serie_introuvable', $r['json']['erreur']['code'], 'code');
    }
    $reste = $bd->prepare('SELECT tome_actuel, couverture FROM serie WHERE id = ?');
    $reste->execute([$id]);
    egale(['tome_actuel' => 7, 'couverture' => ''], $reste->fetch(), 'rien n\'a changé en base');
});

test('B ne voit pas les séries de A dans ses résultats', function () use ($u, $B) {
    $titres = implode('|', array_column(appel_api($u, 'GET', '/api/v1/series?limite=100', $B['jeton'])['json']['series'], 'titre'));
    sans('Privée de A', $titres, 'absente');
    sans('Berserk', str_replace('Berserk de B', '', $titres), 'celles de A non plus');
    egale(0, appel_api($u, 'GET', '/api/v1/series?q=Priv%C3%A9e', $B['jeton'])['json']['total'], 'même en la cherchant');
});

/* ---------------------------------------------------------------------
   Modification
   --------------------------------------------------------------------- */
groupe('PATCH /v1/series/{id} — modifier');

test('modifie seulement ce qui est envoyé', function () use ($u, $A, $creer) {
    $s = $creer(['titre' => 'Vinland Saga', 'auteur' => 'Makoto Yukimura', 'tome_lu' => 10,
                 'statut' => 'cours', 'favori' => true], $A['jeton']);
    $r = appel_api($u, 'PATCH', '/api/v1/series/' . $s['id'], $A['jeton'], ['tome_lu' => 11]);
    egale(200, $r['code'], '200');
    $m = $r['json']['serie'];
    egale(11, $m['tome_lu'], 'tome avancé');
    egale('Vinland Saga', $m['titre'], 'titre inchangé');
    egale('Makoto Yukimura', $m['auteur'], 'auteur inchangé');
    egale('cours', $m['statut'], 'statut inchangé');
    egale(true, $m['favori'], 'favori inchangé');
});

test('tous les champs modifiables, y compris retirer un favori et vider l\'auteur', function () use ($u, $A, $creer) {
    $s = $creer(['titre' => 'Avant', 'auteur' => 'Quelqu\'un', 'favori' => true], $A['jeton']);
    $m = appel_api($u, 'PATCH', '/api/v1/series/' . $s['id'], $A['jeton'],
        ['titre' => 'Après', 'auteur' => '', 'tome_lu' => 0, 'statut' => 'abandon', 'favori' => false])['json']['serie'];
    egale(['Après', '', 0, 'abandon', false], [$m['titre'], $m['auteur'], $m['tome_lu'], $m['statut'], $m['favori']], 'tout est changé');
});

test('favori est une valeur, pas une bascule : répéter ne change rien', function () use ($u, $A, $creer) {
    $s = $creer(['titre' => 'Bascule'], $A['jeton']);
    for ($i = 0; $i < 3; $i++) {
        egale(true, appel_api($u, 'PATCH', '/api/v1/series/' . $s['id'], $A['jeton'], ['favori' => true])['json']['serie']['favori'], 'toujours vrai');
    }
});

test('le statut n\'est jamais changé en cachette', function () use ($u, $A, $creer) {
    $s = $creer(['titre' => 'Envie sage', 'statut' => 'envie'], $A['jeton']);
    $m = appel_api($u, 'PATCH', '/api/v1/series/' . $s['id'], $A['jeton'], ['tome_lu' => 3])['json']['serie'];
    egale('envie', $m['statut'], 'avancer le tome ne passe pas la série « En cours » (le fait l\'application)');
});

test('corps vide, champ inconnu, valeur invalide : 422, et rien n\'est modifié', function () use ($u, $A, $creer, $bd) {
    $s = $creer(['titre' => 'Intacte', 'tome_lu' => 5], $A['jeton']);
    $chemin = '/api/v1/series/' . $s['id'];
    egale(422, appel_api($u, 'PATCH', $chemin, $A['jeton'], [])['code'], '{} : rien à modifier');
    egale(422, appel_api($u, 'PATCH', $chemin, $A['jeton'], ['id' => 1])['code'], 'id');
    egale(422, appel_api($u, 'PATCH', $chemin, $A['jeton'], ['utilisateur_id' => 1])['code'], 'utilisateur_id');
    egale(422, appel_api($u, 'PATCH', $chemin, $A['jeton'], ['mangadex_id' => 'a'])['code'], 'mangadex_id');
    egale(422, appel_api($u, 'PATCH', $chemin, $A['jeton'], ['tome_lu' => 6, 'statut' => 'nimporte'])['code'], 'une valeur fausse annule tout');
    egale(5, appel_api($u, 'GET', $chemin, $A['jeton'])['json']['serie']['tome_lu'], 'tome toujours à 5');
});

test('une série inexistante : 404', function () use ($u, $A) {
    egale(404, appel_api($u, 'PATCH', '/api/v1/series/987654321', $A['jeton'], ['tome_lu' => 1])['code'], 'PATCH');
    egale(404, appel_api($u, 'GET', '/api/v1/series/987654321', $A['jeton'])['code'], 'GET');
});

/* ---------------------------------------------------------------------
   Suppression
   --------------------------------------------------------------------- */
groupe('Suppression — impossible par l\'API');

test('DELETE est refusé (405) et la série reste', function () use ($u, $A, $creer) {
    $s = $creer(['titre' => 'A garder'], $A['jeton']);
    $r = appel_api($u, 'DELETE', '/api/v1/series/' . $s['id'], $A['jeton']);
    egale(405, $r['code'], '405');
    contient('GET', $r['entetes']['allow'] ?? '', 'Allow liste les méthodes permises');
    sans('DELETE', $r['entetes']['allow'] ?? '', 'DELETE n\'y figure pas');
    egale(200, appel_api($u, 'GET', '/api/v1/series/' . $s['id'], $A['jeton'])['code'], 'toujours là');
});

test('aucune autre porte vers la suppression ni vers l\'administration', function () use ($u, $A) {
    foreach (['/api/v1/series/1/supprimer', '/api/v1/compte', '/api/v1/jetons', '/api/v1/admin', '/api/v1/donnees/vider'] as $chemin) {
        foreach (['GET', 'POST', 'DELETE'] as $m) {
            egale(404, appel_api($u, $m, $chemin, $A['jeton'], $m === 'POST' ? [] : null)['code'], $m . ' ' . $chemin);
        }
    }
});

/* ---------------------------------------------------------------------
   Couverture
   --------------------------------------------------------------------- */
groupe('Couvertures — choisir');

$uuid = 'a1b2c3d4-0000-4000-8000-0123456789ab';
$couv = 'https://uploads.mangadex.org/covers/' . $uuid . '/image-du-tome-3.jpg.512.jpg';

test('enregistrer une proposition : l\'adresse, le lien et la source suivent', function () use ($u, $A, $creer, $couv, $uuid) {
    $s = $creer(['titre' => 'Avec couverture', 'tome_lu' => 2], $A['jeton']);
    $r = appel_api($u, 'PUT', '/api/v1/series/' . $s['id'] . '/couverture', $A['jeton'], ['url' => $couv]);
    egale(200, $r['code'], '200');
    $c = $r['json']['serie']['couverture'];
    egale($couv, $c['url'], 'adresse');
    egale('mangadex', $c['source'], 'source');
    egale($uuid, $c['mangadex_id'], 'lien déduit de l\'adresse');
    egale(2, $r['json']['serie']['tome_lu'], 'le reste est intact');
});

test('refuser toute adresse qui n\'est pas une couverture MangaDex', function () use ($u, $A, $creer, $uuid) {
    $s = $creer(['titre' => 'Sans détournement'], $A['jeton']);
    foreach ([
        'https://evil.example/x.jpg', 'http://uploads.mangadex.org/covers/' . $uuid . '/x.jpg',
        'javascript:alert(1)', 'data:image/png;base64,AAAA', 'uploads/image.webp',
        'https://uploads.mangadex.org/covers/' . $uuid . '/x.jpg?x=1', '',
    ] as $mauvaise) {
        $r = appel_api($u, 'PUT', '/api/v1/series/' . $s['id'] . '/couverture', $A['jeton'], ['url' => $mauvaise]);
        egale(422, $r['code'], 'refusée : ' . $mauvaise);
        vrai(isset($r['json']['erreur']['champs']['url']), 'erreur rangée sous « url »');
    }
    egale(null, appel_api($u, 'GET', '/api/v1/series/' . $s['id'], $A['jeton'])['json']['serie']['couverture']['url'], 'aucune couverture posée');
});

test('url absente ou champ en trop : 422', function () use ($u, $A, $creer, $couv) {
    $s = $creer(['titre' => 'Strict'], $A['jeton']);
    $chemin = '/api/v1/series/' . $s['id'] . '/couverture';
    egale(422, appel_api($u, 'PUT', $chemin, $A['jeton'], [])['code'], 'sans url');
    egale(422, appel_api($u, 'PUT', $chemin, $A['jeton'], ['url' => $couv, 'mangadex_id' => 'x'])['code'], 'champ en trop');
    egale(422, appel_api($u, 'PUT', $chemin, $A['jeton'], ['url' => 12])['code'], 'mauvais type');
    egale(400, appel_api($u, 'PUT', $chemin, $A['jeton'])['code'], 'sans corps');
});

test('PATCH ne touche pas à la couverture, et la couverture ne touche pas au reste', function () use ($u, $A, $creer, $couv) {
    $s = $creer(['titre' => 'Stable'], $A['jeton']);
    appel_api($u, 'PUT', '/api/v1/series/' . $s['id'] . '/couverture', $A['jeton'], ['url' => $couv]);
    $m = appel_api($u, 'PATCH', '/api/v1/series/' . $s['id'], $A['jeton'], ['tome_lu' => 9])['json']['serie'];
    egale($couv, $m['couverture']['url'], 'couverture conservée');
});

test('remplacer une image envoyée par fichier l\'efface du disque', function () use ($u, $A, $creer, $couv, $bd) {
    $s = $creer(['titre' => 'Image locale'], $A['jeton']);
    $nom = 'uploads/apitest' . bin2hex(random_bytes(4)) . '.webp';
    file_put_contents(CHEMIN_SITE . '/' . $nom, 'faux-webp');
    $bd->prepare('UPDATE serie SET couverture = ? WHERE id = ?')->execute([$nom, $s['id']]);

    try {
        vrai(is_file(CHEMIN_SITE . '/' . $nom), 'le fichier existe avant');
        egale(200, appel_api($u, 'PUT', '/api/v1/series/' . $s['id'] . '/couverture', $A['jeton'], ['url' => $couv])['code'], 'remplacée');
        clearstatcache();   // PHP retient le résultat du is_file() précédent
        faux(is_file(CHEMIN_SITE . '/' . $nom), 'le fichier orphelin est supprimé');
    } finally {
        @unlink(CHEMIN_SITE . '/' . $nom);
    }
});

groupe('Couvertures — Recherche auto (avant l\'appel à MangaDex)');

test('corps invalide : 422, sans consommer de recherche', function () use ($u, $A, $creer, $bd) {
    $s = $creer(['titre' => 'Corps invalide'], $A['jeton']);
    $chemin = '/api/v1/series/' . $s['id'] . '/couvertures/recherche';
    foreach ([['tome' => 0], ['tome' => '2'], ['tome' => TOME_MAX + 1], ['titre' => 'x']] as $corps) {
        egale(422, appel_api($u, 'POST', $chemin, $A['jeton'], $corps)['code'], json_encode($corps));
    }
    $req = $bd->prepare('SELECT COUNT(*) FROM recherche_couverture WHERE utilisateur_id = ?');
    $req->execute([$A['id']]);
    egale(0, (int) $req->fetchColumn(), 'aucune recherche décomptée');
});

test('quota de recherche épuisé : 429 quota_recherche avec Retry-After, avant tout réseau', function () use ($serveurs, $bd, $creer) {
    $q = $serveurs['quota']['url'];
    $C = compte_de_test($bd, 'quota');
    $s = appel_api($q, 'POST', '/api/v1/series', $C['jeton'], ['titre' => 'Recherchée'])['json']['serie'];

    // Le compte a déjà fait sa recherche de la tranche (COUVERTURE_QUOTA=1).
    $bd->prepare('INSERT INTO recherche_couverture (utilisateur_id, essais, fenetre_fin)
                  VALUES (?, 1, NOW() + INTERVAL 100 SECOND)')->execute([$C['id']]);

    $r = appel_api($q, 'POST', '/api/v1/series/' . $s['id'] . '/couvertures/recherche', $C['jeton']);
    egale(429, $r['code'], '429');
    egale('quota_recherche', $r['json']['erreur']['code'], 'code');
    vrai(($r['json']['erreur']['attente'] ?? 0) >= 1, 'secondes à attendre dans le corps');
    vrai((int) ($r['entetes']['retry-after'] ?? 0) >= 1, 'Retry-After');
});

test('le forfait illimité n\'a pas de quota de recherche (annoncé par /moi)', function () use ($u, $bd) {
    $I = compte_de_test($bd, 'illimite', 'illimite');
    $c = appel_api($u, 'GET', '/api/v1/moi', $I['jeton'])['json']['compte'];
    egale(null, $c['series_max'], 'pas de plafond de séries');
    egale(null, $c['recherches_couverture'], 'pas de quota de recherche');
});

/* ---------------------------------------------------------------------
   Quotas et freins
   --------------------------------------------------------------------- */
groupe('Quotas et freins');

test('plafond de séries : appliqué par la base, refusé avec 422 quota_series', function () use ($serveurs, $bd) {
    $q = $serveurs['quota']['url'];
    $C = compte_de_test($bd, 'plafond_series');
    for ($i = 1; $i <= 3; $i++) {
        egale(201, appel_api($q, 'POST', '/api/v1/series', $C['jeton'], ['titre' => 'Série ' . $i])['code'], 'série ' . $i);
    }
    $r = appel_api($q, 'POST', '/api/v1/series', $C['jeton'], ['titre' => 'Série 4']);
    egale(422, $r['code'], 'la 4e est refusée');
    egale('quota_series', $r['json']['erreur']['code'], 'code');
    $req = $bd->prepare('SELECT COUNT(*) FROM serie WHERE utilisateur_id = ?');
    $req->execute([$C['id']]);
    egale(3, (int) $req->fetchColumn(), 'trois séries en base, pas quatre');
    egale(200, appel_api($q, 'PATCH', '/api/v1/series/' . appel_api($q, 'GET', '/api/v1/series', $C['jeton'])['json']['series'][0]['id'],
        $C['jeton'], ['tome_lu' => 1])['code'], 'modifier reste possible au plafond');
});

test('le forfait illimité dépasse ce plafond', function () use ($serveurs, $bd) {
    $q = $serveurs['quota']['url'];
    $I = compte_de_test($bd, 'illimite_plafond', 'illimite');
    for ($i = 1; $i <= 5; $i++) {
        egale(201, appel_api($q, 'POST', '/api/v1/series', $I['jeton'], ['titre' => 'Série ' . $i])['code'], 'série ' . $i);
    }
});

test('frein par jeton : 429 trop_de_requetes, Retry-After et compteurs', function () use ($serveurs, $bd) {
    $l = $serveurs['limite']['url'];
    $C = compte_de_test($bd, 'frein');
    for ($i = 1; $i <= 3; $i++) {
        $r = appel_api($l, 'GET', '/api/v1/moi', $C['jeton']);
        egale(200, $r['code'], 'requête ' . $i);
        egale('3', $r['entetes']['x-ratelimit-limit'] ?? '', 'limite annoncée');
        egale((string) (3 - $i), $r['entetes']['x-ratelimit-remaining'] ?? '', 'reste annoncé');
    }
    $r = appel_api($l, 'GET', '/api/v1/moi', $C['jeton']);
    egale(429, $r['code'], 'la 4e est refusée');
    egale('trop_de_requetes', $r['json']['erreur']['code'], 'code');
    vrai((int) ($r['entetes']['retry-after'] ?? 0) >= 1, 'Retry-After');
    egale('0', $r['entetes']['x-ratelimit-remaining'] ?? '', 'plus rien');
    egale(429, appel_api($l, 'GET', '/api/v1/moi', $C['jeton'])['code'], 'insister ne change rien');
});

test('le frein est PAR JETON : un autre jeton n\'est pas touché', function () use ($serveurs, $bd) {
    $l = $serveurs['limite']['url'];
    $C = compte_de_test($bd, 'frein2');
    for ($i = 0; $i < 5; $i++) {
        appel_api($l, 'GET', '/api/v1/moi', $C['jeton']);
    }
    egale(429, appel_api($l, 'GET', '/api/v1/moi', $C['jeton'])['code'], 'ce jeton est freiné');
    $autre = api_jeton_creer($bd, $C['id'], 'autre');
    egale(200, appel_api($l, 'GET', '/api/v1/moi', $autre['jeton'])['code'], 'l\'autre jeton passe');
});

test('la tranche suivante repart entière', function () use ($serveurs, $bd) {
    $l = $serveurs['limite']['url'];
    $C = compte_de_test($bd, 'frein3');
    for ($i = 0; $i < 4; $i++) {
        appel_api($l, 'GET', '/api/v1/moi', $C['jeton']);
    }
    egale(429, appel_api($l, 'GET', '/api/v1/moi', $C['jeton'])['code'], 'freiné');
    $bd->prepare('UPDATE jeton_api SET fenetre_fin = NOW() - INTERVAL 1 SECOND WHERE id = ?')->execute([$C['jeton_id']]);
    egale(200, appel_api($l, 'GET', '/api/v1/moi', $C['jeton'])['code'], 'tranche échue : repart');
});

test('jetons refusés en série : l\'adresse est bloquée, même avec un bon jeton ensuite', function () use ($serveurs, $bd) {
    $e = $serveurs['echecs']['url'];
    $C = compte_de_test($bd, 'echecs');
    // Le compteur est par adresse, partagé par tous les serveurs de ce fichier : on repart de zéro.
    $bd->exec("DELETE FROM tentative_ip WHERE action = 'api_jeton'");
    try {
        for ($i = 0; $i < 3; $i++) {
            egale(401, appel_api($e, 'GET', '/api/v1/moi', 'lbm_' . str_repeat((string) $i, 64))['code'], 'échec ' . ($i + 1));
        }
        $r = appel_api($e, 'GET', '/api/v1/moi', $C['jeton']);
        egale(429, $r['code'], 'bloquée');
        egale('trop_d_echecs', $r['json']['erreur']['code'], 'code');
        vrai((int) ($r['entetes']['retry-after'] ?? 0) >= 1, 'Retry-After');
    } finally {
        $bd->exec("DELETE FROM tentative_ip WHERE action = 'api_jeton'");
    }
});

test('les succès ne comptent pas contre l\'adresse', function () use ($serveurs, $bd) {
    $e = $serveurs['echecs']['url'];
    $C = compte_de_test($bd, 'succes');
    for ($i = 0; $i < 10; $i++) {
        egale(200, appel_api($e, 'GET', '/api/v1/moi', $C['jeton'])['code'], 'requête ' . $i);
    }
});

test('le journal ne contient jamais un jeton', function () use ($u, $A) {
    appel_api($u, 'GET', '/api/v1/moi', 'lbm_' . str_repeat('f', 64));
    // Les serveurs de test écrivent dans leur propre journal : on lit celui du processus courant et le disque.
    $tous = '';
    foreach (glob(sys_get_temp_dir() . '/livre-test-*.log') ?: [] as $f) {
        $tous .= (string) @file_get_contents($f);
    }
    sans($A['jeton'], $tous, 'le jeton valide n\'est dans aucun journal');
    sans(str_repeat('f', 64), $tous, 'le jeton refusé non plus');
});

$arreter_tout();
