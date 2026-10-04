<?php
/* =====================================================================
   includes/nouveautes.php — fin de série et nouveaux tomes.

   Deux règles portent tout le dispositif, et ce sont celles qui se testent
   sans réseau ni base :

     1. au dernier tome d'une série, finie ou non (fin_de_serie) ;
     2. à la revérification d'une série « à jour », y a-t-il un tome de plus à
        annoncer (nouveaute_evaluer) ?

   Autour : la lecture des réponses de MangaDex (fonctions pures, on leur
   donne des réponses fabriquées), les messages, et quelques garde-fous de
   câblage lus dans les sources — ce que seul un essai réel avait fait voir
   (une clé absente d'une ligne, un UPDATE qui ne trouvait rien).
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

require_once CHEMIN_SITE . '/includes/nouveautes.php';

function source(string $chemin): string
{
    $racine = $chemin === 'livre.sql' ? CHEMIN_PROJET : CHEMIN_SITE;
    return (string) file_get_contents($racine . '/' . $chemin);
}

/** Le corps d'une fonction, de sa signature à la suivante : de quoi y chercher un motif. */
function corps_de(string $source, string $fonction): string
{
    $debut = strpos($source, 'function ' . $fonction . '(');
    if ($debut === false) {
        return '';
    }
    $fin = strpos($source, "\nfunction ", $debut + 1);
    return substr($source, $debut, $fin === false ? null : $fin - $debut);
}

/** Une couverture comme MangaDex la renvoie dans /cover. */
function couv(string $volume, string $fichier, string $langue = 'ja'): array
{
    return ['attributes' => ['volume' => $volume, 'fileName' => $fichier, 'locale' => $langue]];
}

const ID_SERIE = '801513ba-a712-498c-8f57-cae55b38cc92';

groupe('fin_de_serie() — on vient d\'arriver au tome lu');

test('série finie ou abandonnée au dernier tome : terminée', function () {
    egale('termine', fin_de_serie('completed', 34, 34, 34), 'completed, tome 34 sur 34');
    egale('termine', fin_de_serie('cancelled', 0, 12, 12), 'cancelled : MangaDex ne déclare pas de dernier volume, la dernière couverture suffit');
});

test('série en cours ou en pause au dernier tome : à venir', function () {
    egale('a_venir', fin_de_serie('ongoing', 0, 43, 43), 'ongoing');
    egale('a_venir', fin_de_serie('hiatus', 0, 20, 20), 'hiatus');
});

test('un tome lu au-delà du dernier connu compte comme l\'avoir atteint', function () {
    /* La personne a saisi son tome à la main, ou MangaDex est en retard sur
       les sorties : elle n'a certainement pas plus à lire que ce qu'on sait. */
    egale('termine', fin_de_serie('completed', 34, 34, 40), 'finie, tome 40 sur 34');
    egale('a_venir', fin_de_serie('ongoing', 0, 43, 50), 'en cours, tome 50 sur 43');
});

test('des tomes existent plus loin : on est en route, rien ne change', function () {
    /* Le cas courant au-delà des premiers tomes : il manque seulement la
       couverture du suivant. Passer « Terminée » ici serait une erreur. */
    egale('en_route', fin_de_serie('ongoing', 0, 43, 10), 'en cours');
    egale('en_route', fin_de_serie('completed', 34, 34, 33), 'finie, un tome avant le dernier');
});

test('le dernier volume DÉCLARÉ compte autant que la dernière couverture', function () {
    // Série finie en 27 volumes dont les deux derniers n'ont pas de couverture.
    egale('en_route', fin_de_serie('completed', 27, 25, 25), 'à la dernière couverture, il reste 2 volumes');
    egale('termine', fin_de_serie('completed', 27, 25, 27), 'au dernier volume déclaré : terminée');
});

test('rien d\'exploitable : on ne devine pas', function () {
    egale('inconnu', fin_de_serie('completed', 0, 0, 5), 'aucune couverture, aucun dernier volume');
    egale('inconnu', fin_de_serie('', 0, 10, 10), 'état de publication absent');
    egale('inconnu', fin_de_serie('abandonned', 0, 10, 10), 'état de publication inconnu');
    egale('inconnu', fin_de_serie('ongoing', 0, 0, 0), 'rien du tout');
});

groupe('nouveaute_evaluer() — un tome de plus chez MangaDex ?');

test('un tome de plus que la dernière fois ET que le tome lu : on l\'annonce', function () {
    egale('nouveau', nouveaute_evaluer(43, 43, 44), 'à jour au 43, MangaDex en a 44');
    egale('nouveau', nouveaute_evaluer(43, 43, 46), 'trois tomes d\'un coup : une seule annonce');
});

test('la première vérification apprend sans rien annoncer', function () {
    /* Une série d\'avant la fonction n\'a pas de « dernier tome » en base :
       lui en découvrir un « nouveau » ferait annoncer tout ce qui est paru. */
    egale('memoriser', nouveaute_evaluer(43, 0, 43), 'à jour, on le note');
    egale('memoriser', nouveaute_evaluer(10, 0, 43), 'en retard de 33 tomes, on le note sans l\'annoncer');
});

test('des tomes nouveaux mais déjà lus ne sont pas annoncés', function () {
    egale('memoriser', nouveaute_evaluer(50, 43, 45), 'la personne a déjà dépassé');
    egale('memoriser', nouveaute_evaluer(45, 43, 45), 'elle est exactement au dernier');
});

test('rien de plus : rien à faire', function () {
    egale('inchange', nouveaute_evaluer(43, 43, 43), 'même nombre');
    egale('inchange', nouveaute_evaluer(43, 43, 0), 'MangaDex ne dit rien');
    egale('inchange', nouveaute_evaluer(43, 0, 0), 'ni avant ni maintenant');
});

test('un recul chez MangaDex ne fait jamais reculer la carte', function () {
    // Une couverture retirée : 41 au lieu de 43. On garde ce qu\'on savait.
    egale('inchange', nouveaute_evaluer(43, 43, 41), 'on ne change pas la carte sur un recul');
});

groupe('Les messages');

test('nouveaute_message() nomme la série et le tome', function () {
    egale('Un nouveau tome est disponible pour la série « Berserk » (tome 44).', nouveaute_message('Berserk', 44), 'le texte');
});

test('un titre reste du texte : le message ne l\'interprète pas', function () {
    $m = nouveaute_message('<b>x</b> & "y"', 3);
    contient('<b>x</b> & "y"', $m, 'le titre est repris tel quel : c\'est l\'appelant qui échappe');
});

test('fin_de_serie_message() dit ce qui s\'est passé', function () {
    contient('Terminée', fin_de_serie_message('Attack on Titan', 'termine', 35), 'passée en Terminée');
    contient('Attack on Titan', fin_de_serie_message('Attack on Titan', 'termine', 35), 'nomme la série');
    $venir = fin_de_serie_message('Berserk', 'a_venir', 44);
    contient('tome 44', $venir, 'nomme le tome qui n\'est pas paru');
    contient('pas encore paru', $venir, 'et dit pourquoi');
    sans('Terminée', $venir, 'une série qui continue n\'est pas dite terminée');
});

groupe('nouveautes_par_compte() — un message par compte');

test('plusieurs séries d\'un même compte sont regroupées', function () {
    $r = nouveautes_par_compte([
        ['id' => 1, 'utilisateur_id' => 7, 'titre' => 'Berserk', 'tome' => 44],
        ['id' => 2, 'utilisateur_id' => 9, 'titre' => 'One Piece', 'tome' => 115],
        ['id' => 3, 'utilisateur_id' => 7, 'titre' => 'Vagabond', 'tome' => 38],
    ]);
    egale([7, 9], array_keys($r), 'un groupe par compte');
    egale([['titre' => 'Berserk', 'tome' => 44], ['titre' => 'Vagabond', 'tome' => 38]], $r[7], 'les deux séries du compte 7, dans l\'ordre');
    egale([['titre' => 'One Piece', 'tome' => 115]], $r[9], 'la série du compte 9');
});

test('aucun tome nouveau : aucun message', function () {
    egale([], nouveautes_par_compte([]), 'rien');
});

groupe('mangadex_dernier_tome_depuis() — le plus haut tome illustré');

test('le plus haut volume gagne, quel que soit l\'ordre de la réponse', function () {
    $r = mangadex_dernier_tome_depuis(['data' => [couv('41', 'a'), couv('43', 'b'), couv('42', 'c')]], ID_SERIE);
    egale(43, $r['tome'], 'le tome');
    egale('https://uploads.mangadex.org/covers/' . ID_SERIE . '/b.512.jpg', $r['url'], 'la couverture de ce tome');
});

test('les volumes sont comparés comme des nombres, pas comme du texte', function () {
    // « 9 » > « 10 » en ordre alphabétique : la réponse n\'y est jamais supposée triée.
    $r = mangadex_dernier_tome_depuis(['data' => [couv('9', 'neuf'), couv('10', 'dix'), couv('100', 'cent')]], ID_SERIE);
    egale(100, $r['tome'], '100 et non 9');
});

test('un volume décimal est un hors-série : on garde sa partie entière', function () {
    $r = mangadex_dernier_tome_depuis(['data' => [couv('41.3', 'hs'), couv('40', 'quarante')]], ID_SERIE);
    egale(41, $r['tome'], '41.3 compte pour 41');
});

test('à tome égal, le français passe avant l\'anglais avant le japonais', function () {
    $r = mangadex_dernier_tome_depuis(['data' => [
        couv('43', 'jp', 'ja'), couv('43', 'en', 'en'), couv('43', 'fr', 'fr'), couv('43', 'it', 'it'),
    ]], ID_SERIE);
    contient('/fr.512.jpg', (string) $r['url'], 'la couverture française');
    $r = mangadex_dernier_tome_depuis(['data' => [couv('43', 'it', 'it'), couv('43', 'jp', 'ja')]], ID_SERIE);
    contient('/jp.512.jpg', (string) $r['url'], 'sans français ni anglais : le japonais avant une langue inconnue');
});

test('un volume sans numéro, vide ou à zéro n\'est pas un tome', function () {
    $r = mangadex_dernier_tome_depuis(['data' => [
        couv('', 'vide'), couv('0', 'zero'), couv('Extra', 'texte'), ['attributes' => ['fileName' => 'sans-volume']],
        couv('5', 'cinq'),
    ]], ID_SERIE);
    egale(5, $r['tome'], 'seul le 5 est un tome');
});

test('une couverture sans fichier est ignorée', function () {
    $r = mangadex_dernier_tome_depuis(['data' => [couv('9', ''), couv('3', 'trois')]], ID_SERIE);
    egale(3, $r['tome'], 'pas d\'URL à construire pour le 9');
});

test('aucune couverture, ou une réponse en désordre : tome 0 et pas d\'URL', function () {
    egale(['tome' => 0, 'url' => null], mangadex_dernier_tome_depuis(['data' => []], ID_SERIE), 'liste vide');
    egale(['tome' => 0, 'url' => null], mangadex_dernier_tome_depuis([], ID_SERIE), 'pas de clé data');
    egale(['tome' => 0, 'url' => null], mangadex_dernier_tome_depuis(['data' => ['pas un tableau', 7, null]], ID_SERIE), 'entrées qui n\'en sont pas');
});

test('le tome ne dépasse jamais TOME_MAX', function () {
    // Certaines séries numérotent leurs volumes par année (« 2019 »).
    $r = mangadex_dernier_tome_depuis(['data' => [couv('99999999', 'enorme')]], ID_SERIE);
    egale(TOME_MAX, $r['tome'], 'borné à ce que la colonne accepte');
});

groupe('mangadex_statut_depuis() — l\'état de publication');

test('les quatre états connus sont lus, avec le dernier volume déclaré', function () {
    foreach (['ongoing', 'completed', 'hiatus', 'cancelled'] as $statut) {
        $r = mangadex_statut_depuis(['data' => ['attributes' => ['status' => $statut, 'lastVolume' => '27']]]);
        egale($statut, $r['statut'], $statut);
        egale(27, $r['dernier_volume'], 'lastVolume « 27 » devient 27');
    }
});

test('un lastVolume vide, absent ou non numérique vaut 0', function () {
    foreach (['', null, 'abc', '-3'] as $valeur) {
        $r = mangadex_statut_depuis(['data' => ['attributes' => ['status' => 'ongoing', 'lastVolume' => $valeur]]]);
        egale(0, $r['dernier_volume'], 'valeur ' . var_export($valeur, true));
    }
    $r = mangadex_statut_depuis(['data' => ['attributes' => ['status' => 'ongoing']]]);
    egale(0, $r['dernier_volume'], 'clé absente (ce que MangaDex fait pour une série en cours)');
});

test('un état inconnu ou une réponse sans sens rend null : on ne devine pas', function () {
    estNul(mangadex_statut_depuis(['data' => ['attributes' => ['status' => 'abandonned']]]), 'état inventé');
    estNul(mangadex_statut_depuis(['data' => ['attributes' => ['status' => 7]]]), 'pas du texte');
    estNul(mangadex_statut_depuis(['data' => ['attributes' => []]]), 'aucun état');
    estNul(mangadex_statut_depuis(['data' => 'x']), 'data n\'est pas un objet');
    estNul(mangadex_statut_depuis([]), 'réponse vide');
});

groupe('mangadex_id_valide() et mangadex_url_couverture()');

test('seul un UUID passe : l\'identifiant entre dans un chemin d\'appel', function () {
    vrai(mangadex_id_valide(ID_SERIE), 'un UUID');
    vrai(mangadex_id_valide(strtoupper(ID_SERIE)), 'la casse n\'y change rien');
    foreach (['', 'abc', ID_SERIE . '/../x', '../' . ID_SERIE, ID_SERIE . '?a=1', ' ' . ID_SERIE] as $mauvais) {
        faux(mangadex_id_valide($mauvais), 'refusé : ' . var_export($mauvais, true));
    }
});

test('l\'adresse d\'une couverture est celle que mangadex_id_depuis_url() relit', function () {
    $url = mangadex_url_couverture(ID_SERIE, 'abc-def.jpg');
    egale('https://uploads.mangadex.org/covers/' . ID_SERIE . '/abc-def.jpg.512.jpg', $url, 'la forme');
    egale(ID_SERIE, mangadex_id_depuis_url($url), 'le lien se relit dans l\'image : les deux ne peuvent pas diverger');
});

test('un nom de fichier étrange est encodé, pas injecté', function () {
    contient('%2F', mangadex_url_couverture(ID_SERIE, '../x'), 'le slash est encodé');
});

groupe('Les bornes du .env');

test('NOUVEAUTE_HEURES, _MAX_VISITE et _MAX_CRON ont des valeurs sûres par défaut', function () {
    egale(1, NOUVEAUTE_HEURES, 'une heure entre deux vérifications d\'une même série');
    egale(6, NOUVEAUTE_MAX_VISITE, 'six séries par arrivée');
    egale(40, NOUVEAUTE_MAX_CRON, 'quarante par passage du cron');
});

test('le budget de temps laisse de la marge', function () {
    $b = nouveautes_budget();
    vrai($b >= 5.0, 'au moins cinq secondes');
    vrai($b <= 120.0, 'jamais plus de deux minutes');
});

groupe('Câblage — ce qu\'un essai réel avait fait voir');

test('le relevé lit dans sa requête chaque colonne que les fonctions utilisent', function () {
    /* nouveautes_verifier_serie() et nouveautes_verifier() lisent $s['…'] : si
       la requête qui fabrique ces lignes ne les sélectionne pas, PHP rend null
       avec un simple avertissement et l'UPDATE ne trouve plus rien. */
    $src = source('includes/nouveautes.php');
    $requete = corps_de($src, 'nouveautes_series_a_verifier');
    preg_match('/SELECT (.*?)\s+FROM serie/s', $requete, $m);
    vrai(isset($m[1]), 'la requête est lisible');
    $colonnes = array_map(static fn ($c) => trim(preg_replace('/^s\./', '', trim($c))), explode(',', $m[1]));

    foreach (['nouveautes_verifier_serie', 'nouveautes_verifier'] as $fonction) {
        preg_match_all("/\\\$s\\['([a-z_]+)'\\]/", corps_de($src, $fonction), $cles);
        foreach (array_unique($cles[1]) as $cle) {
            vrai(in_array($cle, $colonnes, true), $fonction . '() lit $s[\'' . $cle . '\'] : la requête doit le sélectionner');
        }
    }
});

test('nouveautes_fin_de_serie() reçoit le compte, elle ne le lit pas dans la ligne', function () {
    /* ma_serie() (api.php) ne rend pas utilisateur_id : y lire donnait 0, un
       UPDATE sans cible, et une série qui ne passait jamais « Terminée ». */
    $corps = corps_de(source('includes/nouveautes.php'), 'nouveautes_fin_de_serie');
    contient('int $utilisateur_id', $corps, 'le compte est un paramètre');
    sans("\$s['utilisateur_id']", $corps, 'et n\'est pas lu dans la ligne');
    contient('nouveautes_fin_de_serie($pdo, $mon_id, $s)', source('api.php'), 'api.php passe le compte connecté');
});

test('un relevé ne modifie pas la série : maj_le = maj_le', function () {
    /* La colonne se met à jour seule à tout UPDATE, et la bibliothèque est
       triée dessus : noter une vérification ne doit pas faire remonter la série. */
    $src = source('includes/nouveautes.php');
    foreach (['nouveautes_noter_verification', 'nouveautes_fin_de_serie'] as $fonction) {
        contient('maj_le = maj_le', corps_de($src, $fonction), $fonction . '() laisse la date de modification');
    }
    contient('maj_le = maj_le', corps_de($src, 'nouveautes_verifier_serie'), 'le relevé aussi (tome appris sans annonce)');
    contient('maj_le = NOW()', corps_de($src, 'nouveautes_verifier_serie'), 'et un tome NOUVEAU met la série en tête');
    $api = source('api.php');
    contient('SET nouveau_tome = 0, maj_le = maj_le WHERE utilisateur_id', $api, 'lire une annonce ne modifie pas la série');
});

test('les écritures du relevé sont gardées contre un changement en cours d\'appel', function () {
    $src = source('includes/nouveautes.php');
    $nouveau = corps_de($src, 'nouveautes_verifier_serie');
    contient('tome_actuel = ? AND dernier_tome = ?', $nouveau, 'un tome nouveau : tome lu et dernier tome relus (deux onglets, ou cron + visite)');
    $fin = corps_de($src, 'nouveautes_fin_de_serie');
    contient("AND tome_actuel = ? AND statut = 'cours'", $fin, 'fin de série : la série n\'a pas bougé pendant l\'appel');
});

test('les séries à vérifier : en cours, liées, à jour, pas vues depuis NOUVEAUTE_HEURES, jamais d\'un compte bloqué', function () {
    $q = corps_de(source('includes/nouveautes.php'), 'nouveautes_series_a_verifier');
    contient("s.statut = 'cours'", $q, 'seule une série en cours attend un tome');
    contient("s.mangadex_id <> ''", $q, 'liée à MangaDex');
    contient('s.dernier_tome = 0 OR s.tome_actuel >= s.dernier_tome', $q, 'à jour, ou jamais vérifiée');
    contient('NOUVEAUTE_HEURES', $q, 'pas plus d\'une fois par NOUVEAUTE_HEURES');
    contient("u.forfait <> 'bloque'", $q, 'la consultation seule ne sollicite pas MangaDex');
    contient('ORDER BY s.verifie_le ASC', $q, 'les plus anciennement vérifiées d\'abord : aucune ne reste à l\'écart');
});

test('une série que MangaDex ne connaît plus n\'affame pas les autres', function () {
    /* Triées par ancienneté de vérification, une série qui échoue sans être
       notée resterait en tête de file pour toujours. */
    $corps = corps_de(source('includes/nouveautes.php'), 'nouveautes_verifier_serie');
    $echec = (int) strpos($corps, "'etat' => 'echec'");
    $note  = (int) strpos($corps, 'nouveautes_noter_verification($pdo, $id, $uid);');
    vrai($note > 0 && $note < $echec, 'l\'échec est noté « vérifié » avant d\'être rendu');
    $file = (int) strpos($corps, "'etat' => 'file_pleine'");
    vrai($file > 0 && $file < $note, 'sauf si NOTRE file d\'attente a refusé : la série n\'y est pour rien');
});

test('api.php : le relevé libère la session, ne ralentit pas un « → » cliqué entre-temps', function () {
    $api = source('api.php');
    $debut = (int) strpos($api, "case 'serie.nouveautes': {");
    $bloc = substr($api, $debut, 700);
    contient('session_write_close();', $bloc, 'la session est libérée');
    vrai(strpos($bloc, 'session_write_close();') < strpos($bloc, 'nouveautes_verifier('), 'AVANT l\'attente de MangaDex');
});

test('api.php : un autre lien MangaDex remet à zéro ce qu\'on savait de l\'ancien', function () {
    $api = source('api.php');
    contient('if ($lien !== $ancien_lien)', $api, 'le test existe');
    contient('SET dernier_tome = 0, verifie_le = NULL, nouveau_tome = 0', $api, 'la remise à zéro');
});

test('api.php : « → » efface l\'annonce de la série, et le rafraîchissement automatique est distingué du bouton', function () {
    $api = source('api.php');
    contient('SET tome_actuel = ?, statut = ?, nouveau_tome = 0', $api, '« → » vaut lecture de l\'annonce');
    contient('$auto      = $persister && !$repli && !isset($_POST[\'tome_actuel\']) && !isset($_POST[\'statut\'])', $api,
        'seul le rafraîchissement qui part de la base décide d\'une fin de série');
    contient("\$statut_vu === 'cours'", $api, 'et seulement pour une série « En cours »');
});

test('purger.php : le cron charge nouveautes.php, jamais fonctions.php', function () {
    $cron = source('purger.php');
    contient("require_once __DIR__ . '/includes/nouveautes.php';", $cron, 'le cron charge le relevé');
    sans("require_once __DIR__ . '/includes/fonctions.php'", $cron, 'et pas fonctions.php : en-têtes HTTP et session');
    sans("require __DIR__ . '/includes/fonctions.php'", $cron, 'sous aucune forme');
    $nouveautes = source('includes/nouveautes.php');
    sans('fonctions.php', preg_replace('#/\*.*?\*/#s', '', $nouveautes), 'nouveautes.php ne le charge pas non plus');
    contient('nouveautes_verifier(', $cron, 'il lance le relevé');
    contient('avertir_nouveaux_tomes(', $cron, 'et prévient');
});

test('purger.php : pas d\'e-mail à une adresse non confirmée, à qui l\'a refusé, ni à un compte bloqué', function () {
    $cron = source('purger.php');
    contient("(int) \$u['email_verifie'] !== 1", $cron, 'adresse confirmée');
    contient("(int) \$u['notif_tomes'] !== 1", $cron, 'préférence respectée');
    contient("\$u['forfait'] === 'bloque'", $cron, 'compte bloqué');
});

test('purger.php : un schéma pas migré devient une anomalie bruyante, pas un plantage', function () {
    $cron = source('purger.php');
    contient('catch (PDOException $e)', $cron, 'l\'erreur est attrapée');
    contient('migration 13', $cron, 'et dit quoi faire');
});

test('purger.php : la ligne du bilan ne contient pas « e-mail » (le rapport les écarte)', function () {
    $cron = source('purger.php');
    preg_match("/\\\$ligne = (.*?);\n/s", $cron, $m);
    sans('e-mail', $m[1] ?? 'e-mail', 'sinon le rapport ne la montrerait pas');
});

test('index.php : annonces rendues par le serveur, relevé demandé seulement s\'il y a de quoi', function () {
    $page = source('index.php');
    contient('nouveautes_series_a_verifier($pdo, (int) $moi[\'id\'], 1)', $page, 'la page sait s\'il y a des séries à vérifier');
    contient('!$bloque &&', $page, 'jamais pour un compte bloqué');
    contient('data-nouveautes="<?= $a_verifier', $page, 'et le dit au script');
    contient('nouveaute_message(', $page, 'le texte de l\'annonce vient de la même fonction que celui du relevé');
    contient('e(nouveaute_message(', $page, 'échappé');
});

test('js/app.js : les messages entrent par textContent, jamais par innerHTML', function () {
    $js = source('js/app.js');
    $debut = (int) strpos($js, 'function annoncerNouveautes');
    $fonction = substr($js, $debut, 420);
    contient('ligne.textContent = m;', $fonction, 'un titre est du texte');
    sans('innerHTML', $fonction, 'jamais du HTML');
    contient('document.body.dataset.nouveautes === "1" && !BLOQUE', $js, 'le relevé ne part que si la page l\'a demandé, et pas pour un compte bloqué');
});

test('livre.sql : migration 13, rejouable et sans ADD COLUMN IF NOT EXISTS', function () {
    $sql = source('livre.sql');
    $m13 = substr($sql, (int) strpos($sql, '--  13. Nouveaux tomes'));
    vrai($m13 !== '', 'la migration 13 existe');
    foreach (["TABLE_NAME = 'serie' AND COLUMN_NAME = 'dernier_tome'", "TABLE_NAME = 'serie' AND COLUMN_NAME = 'verifie_le'",
              "TABLE_NAME = 'serie' AND COLUMN_NAME = 'nouveau_tome'", "TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'notif_tomes'"] as $colonne) {
        contient($colonne, $m13, 'elle interroge information_schema pour ' . $colonne);
    }
    contient("'DO 0'", $m13, 'et ne fait rien si la colonne est là');
    sans('ADD COLUMN IF NOT EXISTS', substr($m13, (int) strpos($m13, 'SET @c')), 'jamais cette extension MariaDB');
    // Le schéma NEUF porte aussi les colonnes : une base créée aujourd'hui n'a pas besoin de migration.
    $creation = substr($sql, (int) strpos($sql, 'CREATE TABLE IF NOT EXISTS `serie`'), 2500);
    foreach (['`dernier_tome`', '`verifie_le`', '`nouveau_tome`'] as $colonne) {
        contient($colonne, $creation, 'CREATE TABLE serie : ' . $colonne);
    }
    contient('`notif_tomes`  TINYINT(1)   NOT NULL DEFAULT 1', $sql, 'CREATE TABLE utilisateur : notif_tomes, activé par défaut');
});

test('utilisateur_actuel() lit notif_tomes, et la page Paramètres la propose', function () {
    contient('notif_tomes', source('includes/fonctions.php'), 'la colonne est lue à chaque requête');
    $p = source('parametres.php');
    contient('id="notif-tomes"', $p, 'l\'interrupteur');
    $carte = (int) strpos($p, 'id="notifications"');
    contient('<?php if (!$bloque): ?>', substr($p, max(0, $carte - 80), 80), 'la carte est cachée à un compte bloqué');
    $js = source('js/settings.js');
    contient('"compte.notifications"', $js, 'il enregistre par l\'API');
    contient('$notif.checked = r.actives;', $js, 'et reprend la valeur retenue par le serveur');
});
