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

test('série en cours ou en pause au dernier tome : le suivant n\'est pas paru', function () {
    egale('a_venir', fin_de_serie('ongoing', 0, 43, 43), 'ongoing');
    egale('a_venir', fin_de_serie('hiatus', 0, 20, 20), 'hiatus');
});

test('un tome lu AU-DELÀ du dernier connu : MangaDex est en retard, on ne dit rien', function () {
    /* La personne a lu plus de tomes que MangaDex n'en connaît (couvertures pas
       encore ajoutées : HORION, lu au tome 5, n'en a que 3 chez MangaDex) : elle en
       est la preuve. Seul le tome EXACT du dernier connu est une fin. */
    egale('inconnu', fin_de_serie('completed', 34, 34, 40), 'finie, tome 40 sur 34');
    egale('inconnu', fin_de_serie('ongoing', 0, 43, 50), 'en cours, tome 50 sur 43');
    egale('inconnu', fin_de_serie('hiatus', 0, 3, 5), 'en pause, tome 5 sur 3');
    egale('inconnu', fin_de_serie('cancelled', 0, 12, 13), 'arrêtée, tome 13 sur 12');
    egale('a_venir', fin_de_serie('ongoing', 0, 43, 43), 'mais le tome exact reste une fin');
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

test('seule une personne ARRIVÉE AU BOUT des tomes est prévenue', function () {
    /* Au tome 1 d'une série dont le tome 4 est le dernier connu, le tome 5 qui
       sort n'est pas une nouvelle : il reste trois tomes à lire avant. */
    egale('memoriser', nouveaute_evaluer(1, 4, 5), 'au tome 1, le 5 sort : rien à dire');
    egale('memoriser', nouveaute_evaluer(3, 4, 5), 'au tome 3 sur 4 : rien non plus');
    egale('nouveau', nouveaute_evaluer(4, 4, 5), 'au tome 4 sur 4 : le 5 est LA suite');
    egale('nouveau', nouveaute_evaluer(5, 4, 6), 'déjà au-delà de ce qu\'on savait (saisie à la main) : le 6 est à lire');
});

test('seule une personne ARRIVÉE AU BOUT des tomes est prévenue', function () {
    /* Au tome 1 d'une série dont le tome 4 est le dernier connu, le tome 5 qui
       sort n'est pas une nouvelle : il reste trois tomes à lire avant. */
    egale('memoriser', nouveaute_evaluer(1, 4, 5), 'au tome 1, le 5 sort : rien à dire');
    egale('memoriser', nouveaute_evaluer(3, 4, 5), 'au tome 3 sur 4 : rien non plus');
    egale('nouveau', nouveaute_evaluer(4, 4, 5), 'au tome 4 sur 4 : le 5 est LA suite');
    egale('nouveau', nouveaute_evaluer(5, 4, 6), 'déjà au-delà de ce qu\'on savait (saisie à la main) : le 6 est à lire');
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
    contient('En attente', $venir, 'et dit que la série passe « En attente »');
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

test('NOUVEAUTE_MINUTES vaut 60 par défaut, _MAX_VISITE et _MAX_CRON « sans limite »', function () {
    egale(60, NOUVEAUTE_MINUTES, 'une heure entre deux vérifications d\'une même série');
    egale(7, NOUVEAUTE_PUBLICATION_JOURS, 'l\'état de publication est relu chaque semaine');
    faux(defined('NOUVEAUTE_HEURES'), 'l\'ancien nom n\'existe plus');
    egale(0, NOUVEAUTE_MAX_VISITE, 'ligne absente du .env : 0, donc autant de séries que le temps le permet');
    egale(0, NOUVEAUTE_MAX_CRON, 'idem pour un passage du cron');
});

test('nouveautes_limite_sql() : 0 (ou moins) ne limite rien, un nombre limite', function () {
    egale('', nouveautes_limite_sql(0), '0 = sans limite');
    egale('', nouveautes_limite_sql(-3), 'un nombre négatif vaut 0');
    egale(' LIMIT 1', nouveautes_limite_sql(1), 'la bibliothèque s\'en sert pour savoir s\'il y a quelque chose à vérifier');
    egale(' LIMIT 40', nouveautes_limite_sql(40), 'un nombre');
});

test('nouveautes_series_a_verifier() n\'impose plus de LIMIT quand on lui donne 0', function () {
    $q = corps_de(source('includes/nouveautes.php'), 'nouveautes_series_a_verifier');
    contient('nouveautes_limite_sql($limite)', $q, 'la clause vient de nouveautes_limite_sql()');
    sans('max(1, $limite)', $q, 'plus de plancher à 1 : 0 veut dire sans limite');
});

test('sans limite de nombre, le budget de temps arrête quand même le relevé', function () {
    $src = source('includes/nouveautes.php');
    contient('$budget_secondes', corps_de($src, 'nouveautes_verifier'), 'nouveautes_verifier() compte son temps');
    contient("'temps'", corps_de($src, 'nouveautes_verifier'), 'et le dit quand il s\'arrête');
    contient('nouveautes_budget()', source('purger.php'), 'le cron lui donne son budget');
    contient('nouveautes_budget()', source('api.php'), 'la visite aussi');
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
    /* « s.titre » → titre ; une colonne calculée (« … AS publication_perimee ») → son alias. */
    $colonnes = array_map(static function ($c) {
        $c = trim($c);
        return preg_match('/ AS ([a-z_]+)$/', $c, $a) ? $a[1] : trim(preg_replace('/^s\./', '', $c));
    }, explode(',', $m[1]));
    vrai(in_array('publication_perimee', $colonnes, true), 'la requête dit, ligne par ligne, si l\'état de publication est à relire');
    vrai(in_array('tome_final', $colonnes, true), 'et rend le dernier volume déclaré');

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
    contient('maj_le = maj_le', corps_de($src, 'nouveautes_noter_verification'), 'noter une vérification laisse la date de modification');
    contient('maj_le = maj_le', corps_de($src, 'nouveautes_verifier_serie'), 'le relevé aussi (tome appris sans annonce)');
    /* Un CHANGEMENT DE STATUT, lui, est une vraie modification : la colonne suit toute seule. */
    sans('maj_le = maj_le', corps_de($src, 'nouveautes_fin_de_serie'), 'passer « En attente » ou « Terminée » modifie la série');
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

test('les séries à vérifier : en cours, liées, à jour, pas vues depuis NOUVEAUTE_MINUTES, jamais d\'un compte bloqué', function () {
    $q = corps_de(source('includes/nouveautes.php'), 'nouveautes_series_a_verifier');
    contient("s.statut IN ('cours', 'attente')", $q, 'une série en cours, ou déjà en attente, attend un tome — jamais une série terminée ou abandonnée');
    sans("'termine'", $q, 'terminée : jamais revérifiée');
    sans("'abandon'", $q, 'abandonnée : jamais revérifiée');
    contient('s.statut,', $q, 'le statut est sélectionné : la décision en a besoin');
    contient("s.mangadex_id <> ''", $q, 'liée à MangaDex');
    contient('s.dernier_tome = 0 OR s.tome_actuel >= s.dernier_tome', $q, 'à jour, ou jamais vérifiée');
    contient('NOUVEAUTE_MINUTES', $q, 'pas plus d\'une fois par NOUVEAUTE_MINUTES');
    contient('MINUTE)', $q, 'l\'intervalle est bien en minutes');
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
    contient('push_envoyer_a_compte(', $cron, 'et prévient, par notification push');
});

test('purger.php : un schéma pas migré devient une anomalie bruyante, pas un plantage', function () {
    $cron = source('purger.php');
    contient('catch (PDOException $e)', $cron, 'l\'erreur est attrapée');
    contient('migrations 13 à 17', $cron, 'et dit quoi faire');
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
              "TABLE_NAME = 'serie' AND COLUMN_NAME = 'nouveau_tome'"] as $colonne) {
        contient($colonne, $m13, 'elle interroge information_schema pour ' . $colonne);
    }
    contient("'DO 0'", $m13, 'et ne fait rien si la colonne est là');
    sans('ADD COLUMN IF NOT EXISTS', substr($m13, (int) strpos($m13, 'SET @c')), 'jamais cette extension MariaDB');
    // Le schéma NEUF porte aussi les colonnes : une base créée aujourd'hui n'a pas besoin de migration.
    $creation = substr($sql, (int) strpos($sql, 'CREATE TABLE IF NOT EXISTS `serie`'), 2500);
    foreach (['`dernier_tome`', '`verifie_le`', '`nouveau_tome`'] as $colonne) {
        contient($colonne, $creation, 'CREATE TABLE serie : ' . $colonne);
    }
});

groupe('Le statut « En attente »');

test('arrivée au bout d\'une série qui continue : le statut est posé, gardé, et relu', function () {
    $src = source('includes/nouveautes.php');
    $fin = corps_de($src, 'nouveautes_fin_de_serie');
    contient("SET statut = 'attente', dernier_tome = ?, verifie_le = NOW(), publication = ?", $fin, 'le statut « attente » est écrit, avec l\'état de publication');
    contient("AND tome_actuel = ? AND statut = 'cours'", $fin, 'seulement sur une série « En cours » qui n\'a pas bougé pendant l\'appel');
    contient('nouveautes_statut_ecrit($pdo', $fin, 'et relu : hors mode strict, une valeur absente de l\'ENUM devient « » sans erreur');
    contient('catch (PDOException $e)', $fin, 'en mode strict, la base refuse : on le dit au journal au lieu de planter');
    $relu = corps_de($src, 'nouveautes_statut_ecrit');
    contient('SELECT statut FROM serie', $relu, 'la relecture');
    contient("SET statut = 'cours'", $relu, 'une série qui perdrait son statut est rétablie « En cours »');
    contient('migration 15', $relu, 'et le journal dit quoi faire');
});

test('un tome nouveau fait repasser « En cours » une série qui attendait', function () {
    $nouveau = corps_de(source('includes/nouveautes.php'), 'nouveautes_verifier_serie');
    contient("statut = 'cours', verifie_le = NOW(), maj_le = NOW()", $nouveau, 'le statut revient, la série remonte en tête');
    contient('AND dernier_tome = ? AND statut = ?', $nouveau, 'gardé par le statut lu : un statut changé pendant l\'appel n\'est pas écrasé');
});

test('le relevé range les séries déjà au bout : « En cours », la PREMIÈRE fois seulement, et quand MangaDex ne dit rien, rien ne change', function () {
    $c = corps_de(source('includes/nouveautes.php'), 'nouveautes_verifier_serie');
    contient("(string) (\$s['statut'] ?? '') === 'cours'", $c, 'seule une série « En cours » est reclassée');
    contient('$connu === 0 &&', $c, 'à la première vérification seulement : une personne qui remet « En cours » garde le dernier mot');
    contient('$tome >= $dernier[\'tome\']', $c, 'et seulement si le tome lu est le dernier connu');
    contient('nouveautes_fin_de_serie($pdo, $uid, $s, $dernier, $infos)', $c, 'avec les couvertures ET l\'état déjà lus : deux appels de moins');
    contient("'etat' => 'statut', 'statut' => 'termine'", $c, 'finie ou abandonnée : « Terminée »');
    contient("'etat' => 'statut', 'statut' => 'attente'", $c, 'qui continue : « En attente »');
    $apres = substr($c, (int) strpos($c, "'etat' => 'statut', 'statut' => 'attente'"));
    contient("nouveautes_noter_verification(\$pdo, \$id, \$uid);\n    return ['etat' => 'inchange'];", $apres,
        'sans réponse exploitable : la série reste telle quelle et se retrouve au relevé suivant');
});

test('un changement de statut est rendu à l\'écran, sans rien annoncer', function () {
    $api = source('api.php');
    $bloc = substr($api, (int) strpos($api, "case 'serie.nouveautes': {"), 2600);
    contient("foreach (\$bilan['statuts'] as \$c)", $bloc, 'les séries reclassées');
    contient("'changements' => \$changements", $bloc, 'leur carte est renvoyée');
    contient("'compte'      => compter_series(\$pdo, \$mon_id)", $bloc, 'avec les compteurs des filtres');
    $js = source('js/app.js');
    contient('(r.changements || []).forEach((c) => poserCarte(c.carte, c.id));', $js, 'le navigateur refait ces cartes');
    contient('majCompteurs(r.compte);', substr($js, (int) strpos($js, 'async function verifierNouveautes')), 'et met les compteurs à jour');
});

test('revenir d\'un tome sur une série « En attente » ou « Terminée » la remet « En cours »', function () {
    $api = source('api.php');
    $bloc = substr($api, (int) strpos($api, "case 'serie.reculer': {"), 1900);
    contient("statut_apres_recul((string) \$s['statut'], (int) \$s['dernier_tome'], (int) \$s['tome_actuel'])", $bloc,
        'la décision est celle de la fonction pure, avec le tome QUITTÉ');
    contient('SET tome_actuel = ?, statut = ?', $bloc, 'et l\'écrit');
    contient('repassée « En cours »', $bloc, 'le message le dit');
    contient('dernier_tome, nouveau_tome, publication', source('api.php'), 'ma_serie() lit dernier_tome : sans lui, jamais de retour « En cours »');
});

groupe('statut_apres_recul() — quitter le dernier tome connu reprend la lecture');

test('« En attente » et « Terminée » : en quitter le dernier tome connu → « En cours »', function () {
    egale('cours', statut_apres_recul('attente', 43, 43), 'En attente, tome 43 sur 43 → retour au 42');
    egale('cours', statut_apres_recul('termine', 34, 34), 'Terminée, tome 34 sur 34 → retour au 33 (demande de l\'utilisateur)');
});

test('un statut choisi à la main, sans rien de connu, ne bouge pas', function () {
    egale('attente', statut_apres_recul('attente', 0, 20), 'En attente à la main, dernier tome inconnu');
    egale('termine', statut_apres_recul('termine', 0, 20), 'Terminée à la main, dernier tome inconnu');
});

test('ni au milieu des tomes, ni au-delà : seul le dernier tome connu compte', function () {
    egale('attente', statut_apres_recul('attente', 43, 20), 'En attente à la main au tome 20 sur 43 (on attend l\'édition française)');
    egale('termine', statut_apres_recul('termine', 34, 20), 'Terminée à la main au tome 20 sur 34 (on a lâché)');
    egale('attente', statut_apres_recul('attente', 43, 44), 'au tome 44, pas encore paru : reculer au 43 ne reprend rien, on y est');
});

test('les autres statuts ne changent jamais', function () {
    foreach (['cours', 'envie', 'abandon', '', 'inconnu'] as $statut) {
        egale($statut, statut_apres_recul($statut, 43, 43), var_export($statut, true));
    }
});

groupe('publication_connue() — ce qu\'on range en base');

test('les quatre mots de MangaDex, et rien d\'autre', function () {
    foreach (['ongoing', 'completed', 'hiatus', 'cancelled'] as $mot) {
        egale($mot, publication_connue($mot), $mot);
    }
    foreach (['', 'Ongoing', 'abandonned', '<script>', 'completed ', 'hiatus;DROP'] as $autre) {
        egale('', publication_connue($autre), var_export($autre, true) . ' : refusé');
    }
});

test('l\'état de publication est rangé avec « Terminée » et « En attente », avec le dernier volume déclaré', function () {
    $src = source('includes/nouveautes.php');
    $fin = corps_de($src, 'nouveautes_fin_de_serie');
    contient('$publication = publication_connue($infos[\'statut\']);', $fin, 'lu dans la réponse de MangaDex, contrôlé');
    contient("verifie_le = NOW(), publication = ?,\n", $fin, '« Terminée » le range');
    contient('tome_final = ?, publication_le = NOW()', $fin, 'avec le dernier volume déclaré et le moment de la lecture');
    contient('$infos[\'dernier_volume\']', $fin, 'le dernier volume vient de la réponse de MangaDex');
});

test('le relevé lit l\'état de publication de TOUTE série liée (la mention se voit au tome 2), une fois puis toutes les NOUVEAUTE_PUBLICATION_JOURS', function () {
    $src = source('includes/nouveautes.php');
    $q = corps_de($src, 'nouveautes_series_a_verifier');
    contient('NOUVEAUTE_PUBLICATION_JOURS', $q, 'la période de relecture');
    contient('s.publication_le IS NULL OR s.publication_le < NOW() - INTERVAL', $q, 'jamais lu, ou trop ancien');
    contient("(s.statut IN ('cours', 'attente') AND (s.dernier_tome = 0 OR s.tome_actuel >= s.dernier_tome))\n                    OR \" . \$perimee", $q,
        'les couvertures : « En cours » / « En attente » au bout seulement ; la mention : toute série dont l\'état est à relire, QUEL QUE SOIT SON STATUT');
    sans('s.statut IN (\'cours\', \'attente\')' . "\n               AND s.mangadex_id", $q, 'le statut n\'est plus un filtre global : « Terminée », « Abandonnée » et « Envie » ont aussi leur message');
    $releve = corps_de($src, 'nouveautes_verifier_serie');
    contient("!empty(\$s['publication_perimee'])", $releve, 'le relevé lit l\'état quand la requête le dit à relire');
    contient('nouveautes_publication_ecrire($pdo, $id, $uid, $lien, $infos);', $releve, 'et le range');
    contient("\$connu > 0 && \$tome < \$connu", $releve, 'une série pas au bout n\'interroge pas ses couvertures pour rien');
    contient("'etat' => \$infos !== null ? 'publication' : 'echec'", $releve, 'et le dit');
    contient("\$suit_les_tomes = in_array((string) (\$s['statut'] ?? ''), ['cours', 'attente'], true);", $releve,
        'une série ni « En cours » ni « En attente » ne cherche JAMAIS de nouveau tome');
    contient('if (!$suit_les_tomes || ($connu > 0 && $tome < $connu)) {', $releve, 'elle s\'arrête après la lecture de son état');
    contient('if (!$suit_les_tomes && $connu === 0 && $infos !== null) {', $releve,
        'sauf UNE lecture de ses couvertures, pour le « X » de « En pause au tome X » (sinon on ne saurait pas de quel tome parler)');
    contient('AND mangadex_id = ? AND dernier_tome = 0', $releve, 'gardée : jamais écraser un dernier tome déjà connu');
    $ecrire = corps_de($src, 'nouveautes_publication_ecrire');
    contient('maj_le = maj_le', $ecrire, 'lire un état n\'est pas modifier la série');
    contient('AND mangadex_id = ?', $ecrire, 'gardé par le lien : une série qui a changé de MangaDex n\'hérite pas de l\'état de l\'autre');
    contient('publication_connue($infos[\'statut\'])', $ecrire, 'et seul un état connu est rangé');
});

test('changer de série MangaDex remet aussi à zéro l\'état de publication', function () {
    $api = source('api.php');
    $bloc = substr($api, (int) strpos($api, 'if ($lien !== $ancien_lien) {'), 520);
    contient("publication = '', tome_final = 0, publication_le = NULL", $bloc, 'l\'état de l\'ancienne série ne vaut rien pour la nouvelle');
});

groupe('statut_apres_avance() — lire AU-DELÀ de MangaDex : il est en retard');

test('« En attente » : un tome lu au-delà du dernier connu → « En cours »', function () {
    egale('cours', statut_apres_avance('attente', 43, 44), 'au tome 44 alors que MangaDex n\'en connaît que 43');
    egale('cours', statut_apres_avance('attente', 3, 5), 'HORION : lu le 5, MangaDex en a 3');
});

test('pas au-delà, ou rien de connu, ou un autre statut : rien ne change', function () {
    egale('attente', statut_apres_avance('attente', 43, 43), 'au dernier tome exactement : on y est, pas au-delà');
    egale('attente', statut_apres_avance('attente', 43, 20), 'au milieu des tomes (mise à la main)');
    egale('attente', statut_apres_avance('attente', 0, 8), 'dernier tome inconnu : un statut mis à la main reste');
    foreach (['cours', 'envie', 'termine', 'abandon'] as $statut) {
        egale($statut, statut_apres_avance($statut, 43, 50), $statut . ' : pas la décision de cette fonction');
    }
});

test('serie.avancer : un tome au-delà de MangaDex remet « En cours », et le dit', function () {
    $api = source('api.php');
    $bloc = substr($api, (int) strpos($api, "case 'serie.avancer': {"), 2200);
    contient("statut_apres_avance((string) \$statut, (int) \$s['dernier_tome'], \$tome)", $bloc, 'la décision est celle de la fonction pure');
    contient('($demarre || $repasse)', $bloc, 'le message dit « passée en « En cours » »');
});

test('livre.sql : migration 16, rejouable, une colonne texte contrôlée par le code', function () {
    $sql = source('livre.sql');
    contient("`publication`    VARCHAR(12)  NOT NULL DEFAULT ''", $sql, 'le schéma neuf');
    $m16 = substr($sql, (int) strpos($sql, '--  16. État de publication'));
    vrai($m16 !== '', 'la migration 16 existe');
    contient("TABLE_NAME = 'serie' AND COLUMN_NAME = 'publication'", $m16, 'elle interroge information_schema');
    contient("'DO 0'", $m16, 'et ne fait rien si la colonne est là');
    contient("ADD COLUMN `publication` VARCHAR(12) NOT NULL DEFAULT '''' AFTER `nouveau_tome`", $m16, 'sans toucher aux séries');
    sans('ADD COLUMN IF NOT EXISTS', substr($m16, (int) strpos($m16, 'SET @c')), 'jamais cette extension MariaDB');
    sans('UPDATE', substr($m16, (int) strpos($m16, 'SET @c')), 'aucune donnée n\'est touchée');
    sans('ENUM', substr($m16, (int) strpos($m16, 'SET @c')), 'pas d\'ENUM : hors mode strict, une valeur absente y serait rangée « » sans un mot');
});

test('livre.sql : migration 15, rejouable, l\'ENUM porte « attente » en fin de liste', function () {
    $sql = source('livre.sql');
    contient("`statut`         ENUM('cours','envie','termine','abandon','attente') NOT NULL DEFAULT 'cours'", $sql, 'le schéma neuf');
    $m15 = substr($sql, (int) strpos($sql, '--  15. Statut'));
    vrai($m15 !== '', 'la migration 15 existe');
    contient("COLUMN_NAME = 'statut'", $m15, 'elle interroge information_schema');
    contient("LIKE '%''attente''%'", $m15, 'elle ne s\'exécute que si la valeur manque');
    contient("MODIFY COLUMN `statut` ENUM(''cours'',''envie'',''termine'',''abandon'',''attente'') NOT NULL DEFAULT ''cours''", $m15,
        'MODIFY garde le défaut et AJOUTE en fin de liste : aucune série ne change de statut');
    sans('ADD COLUMN IF NOT EXISTS', substr($m15, (int) strpos($m15, 'SET @c')), 'jamais cette extension MariaDB');
    sans('UPDATE', substr($m15, (int) strpos($m15, 'SET @c')), 'aucune donnée n\'est touchée');
    vrai(strpos($sql, "COLUMN_NAME = 'statut'") > strpos($sql, 'CREATE TABLE IF NOT EXISTS `serie`'), 'après la création de la table');
});

test('livre.sql : migration 17, rejouable, deux colonnes qui ne touchent aucune donnée', function () {
    $sql = source('livre.sql');
    contient("`tome_final`     INT UNSIGNED NOT NULL DEFAULT 0", $sql, 'le schéma neuf : le dernier volume déclaré');
    contient("`publication_le` DATETIME     NULL DEFAULT NULL", $sql, 'le schéma neuf : le moment de la lecture');
    $m17 = substr($sql, (int) strpos($sql, '--  17. Mention de la couverture'));
    vrai($m17 !== '', 'la migration 17 existe');
    foreach (['tome_final', 'publication_le'] as $colonne) {
        contient("TABLE_NAME = 'serie' AND COLUMN_NAME = '" . $colonne . "'", $m17, 'elle interroge information_schema pour ' . $colonne);
    }
    contient("'DO 0'", $m17, 'et ne fait rien si la colonne est là');
    contient("ADD COLUMN `tome_final` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `publication`", $m17, 'tome_final après publication');
    contient("ADD COLUMN `publication_le` DATETIME NULL DEFAULT NULL AFTER `tome_final`", $m17, 'publication_le après tome_final');
    sans('ADD COLUMN IF NOT EXISTS', substr($m17, (int) strpos($m17, 'SET @c')), 'jamais cette extension MariaDB');
    sans('UPDATE', substr($m17, (int) strpos($m17, 'SET @c')), 'aucune donnée n\'est touchée');
    vrai(strpos($sql, '--  17. Mention') > strpos($sql, '--  16. État'), 'après la migration 16');
});

test('les séries dont seul l\'état vient d\'être lu sont rendues à l\'écran, pour que leur mention apparaisse tout de suite', function () {
    $src = source('includes/nouveautes.php');
    $v = corps_de($src, 'nouveautes_verifier');
    contient("'publications' => []", $v, 'le bilan porte la liste');
    contient('$r = nouveautes_verifier_serie($pdo, $s, $lue);', $v, 'chaque série dit si son état vient d\'être lu');
    contient("if (\$lue && !in_array(\$r['etat'], ['nouveau', 'statut'], true)) {", $v,
        'remplie pour TOUTE série dont l\'état vient d\'être lu (celles d\'un tome nouveau ou d\'un changement de statut sont déjà rendues)');
    contient('$publication_lue = true;', corps_de(source('includes/nouveautes.php'), 'nouveautes_verifier_serie'), 'le drapeau est levé quand l\'état est rangé');
    $api = source('api.php');
    $bloc = substr($api, (int) strpos($api, "case 'serie.nouveautes': {"), 3600);
    contient("foreach (\$bilan['publications'] as \$c)", $bloc, 'api.php les parcourt');
    contient("\$changements[] = ['id' => \$c['id'], 'carte' => carte_html(ma_serie(\$pdo, \$mon_id, \$c['id']))];", substr($bloc, (int) strpos($bloc, "\$bilan['publications']")),
        'et renvoie leur carte, comme pour un changement de statut : le navigateur la pose sans recharger la page');
});