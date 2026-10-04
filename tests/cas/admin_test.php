<?php
/* =====================================================================
   includes/admin.php — la page d'administration.

   Réservée aux comptes `admin`, colonne distincte du forfait (on peut être
   bloqué ET administrateur). Ce fichier teste :
     - les décisions PURES : ce qui est permis, ce qui est refusé et pourquoi,
       la forme du motif d'un blocage, les dates, les chiffres du bandeau ;
     - par lecture des sources : chaque action `admin.*` d'api.php est gardée
       par ACTIONS_ADMIN avant le switch, la page n'est ouverte qu'aux
       administrateurs, la suppression ne s'exécute pas au GET, le schéma
       porte les colonnes et leur migration.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';
require_once CHEMIN_SITE . '/includes/admin.php';

/** Le contenu d'un fichier du projet, en LF (le répertoire de travail est en CRLF, le dépôt en LF). */
function lire(string $chemin): string
{
    $source = (string) file_get_contents($chemin === 'livre.sql' ? CHEMIN_PROJET . '/livre.sql' : CHEMIN_SITE . '/' . $chemin);
    return str_replace("\r\n", "\n", $source);
}

/** Une ligne de la table, telle que la base la rend : tout en chaînes. */
function ligne_brute(array $m = []): array
{
    return $m + [
        'id' => '12', 'identifiant' => 'marco', 'email' => 'marco@exemple.test', 'email_verifie' => '1',
        'a_photo' => '0', 'forfait' => 'standard', 'admin' => '0', 'raison_blocage' => '',
        'bloque_le' => null, 'cree_le' => '2026-09-27 14:03:11', 'google' => '0', 'nb_series' => '4',
    ];
}

groupe('est_admin() — qui peut ouvrir la page');

test('seul admin = 1 ouvre la page', function () {
    vrai(est_admin(['admin' => 1]), 'entier');
    vrai(est_admin(['admin' => '1']), 'chaîne, comme la base la rend');
    vrai(est_admin(['admin' => true]), 'booléen, comme admin_ligne() le range');
    faux(est_admin(['admin' => 0]), '0');
    faux(est_admin(['admin' => '0']), '« 0 »');
    faux(est_admin(['admin' => '2']), 'une autre valeur n\'est pas « 1 »');
    faux(est_admin(['admin' => 'oui']), 'ni « oui »');
    faux(est_admin([]), 'colonne absente : migration 11 pas rejouée, personne n\'est administrateur');
    faux(est_admin(['admin' => null]), 'NULL');
});

test('être bloqué et administrateur, c\'est possible : deux colonnes, deux droits', function () {
    $les_deux = ['forfait' => 'bloque', 'admin' => 1];
    vrai(compte_bloque($les_deux), 'bloqué');
    vrai(est_admin($les_deux), 'et administrateur');
    foreach (ACTIONS_ADMIN as $action) {
        faux(action_bloquee($les_deux, $action), "« $action » n'est pas refusée par le forfait");
    }
});

test('action_admin() reconnaît les actions réservées', function () {
    foreach (['admin.forfait', 'admin.admin', 'admin.supprimer', 'admin.reglage', 'admin.reglage_retablir'] as $action) {
        vrai(action_admin($action), $action);
    }
    faux(action_admin('serie.favori'), 'une action ordinaire');
    faux(action_admin('admin.inconnue'), 'liste fermée : un préfixe ne suffit pas');
    faux(action_admin('ADMIN.FORFAIT'), 'la casse compte, comme dans le switch d\'api.php');
    faux(action_admin(''), 'vide');
});

groupe('admin_forfait_valide() — liste fermée');

test('les trois forfaits, et rien d\'autre', function () {
    foreach (['standard', 'illimite', 'bloque'] as $f) {
        vrai(admin_forfait_valide($f), $f);
    }
    foreach (['', 'BLOQUE', 'gratuit', ' bloque', 'bloque ', '0', 'admin'] as $f) {
        faux(admin_forfait_valide($f), var_export($f, true));
    }
    faux(admin_forfait_valide(null), 'null');
    faux(admin_forfait_valide(0), 'un entier');
    faux(admin_forfait_valide(['bloque']), 'un tableau (POST forfait[]=…)');
});

test('ADMIN_FORFAITS offre les valeurs de l\'ENUM de la base, ni plus ni moins', function () {
    preg_match("/`forfait`\s+ENUM\(([^)]+)\)/", lire('livre.sql'), $m);
    $enum = array_map(static fn (string $v): string => trim($v, " '"), explode(',', $m[1] ?? ''));
    $offerts = array_keys(ADMIN_FORFAITS);
    sort($enum);
    sort($offerts);
    egale($enum, $offerts, 'les mêmes valeurs : l\'ordre du menu n\'est pas celui de la base');
    egale(FORFAIT_BLOQUE, 'bloque', 'et « bloque » est celui qu\'api.php teste');
});

test('le menu des forfaits va du plus haut au plus bas : illimité, standard, bloqué', function () {
    egale(['illimite', 'standard', 'bloque'], array_keys(ADMIN_FORFAITS), 'l\'ordre demandé');
    egale(['Illimité', 'Standard', 'Bloqué'], array_values(ADMIN_FORFAITS), 'et leurs libellés');
});

groupe('admin_raison() — le motif d\'un blocage');

test('espaces de bord retirés, accents et emoji conservés', function () {
    egale('Publicité en série ✨', admin_raison("  Publicité en série ✨ \n"), 'bords nettoyés');
});

test('les retours à la ligne sont unifiés, les lignes vides en rafale ramenées à une', function () {
    egale("a\nb", admin_raison("a\r\nb"), 'CRLF → LF');
    egale("a\nb", admin_raison("a\rb"), 'CR → LF');
    egale("a\n\nb", admin_raison("a\n\n\n\n\nb"), 'trois lignes vides ou plus : une');
});

test('les caractères de contrôle disparaissent', function () {
    egale('ab', admin_raison("a\x00b"), 'NUL');
    egale('ab', admin_raison("a\x1bb"), 'ESC');
    egale('ab', admin_raison("a\u{200B}b"), 'espace de largeur nulle (catégorie « format »)');
    egale('ab', admin_raison("a\tb"), 'tabulation');
});

test('ce qui n\'est pas du texte devient « pas de motif »', function () {
    egale('', admin_raison("\xff\xfe"), 'UTF-8 invalide');
    egale('', admin_raison(null), 'null');
    egale('', admin_raison(['x']), 'un tableau');
    egale('', admin_raison('   '), 'des espaces');
    egale('12', admin_raison(12), 'un entier est lu comme du texte');
});

groupe('admin_raison_erreur() — sous le champ');

test('un motif est obligatoire', function () {
    contient('raison', admin_raison_erreur(''), 'dit quoi faire');
    contient('envoyée', admin_raison_erreur(''), 'et pourquoi : elle part par e-mail');
    egale('', admin_raison_erreur('Spam'), 'un motif court convient');
});

test('500 caractères au plus — des caractères, pas des octets', function () {
    egale('', admin_raison_erreur(str_repeat('a', 500)), '500 lettres');
    egale('', admin_raison_erreur(str_repeat('é', 500)), '500 é (1 000 octets) : la colonne compte des caractères');
    contient('500', admin_raison_erreur(str_repeat('a', 501)), '501 : trop long, et la limite est dite');
    egale(500, ADMIN_RAISON_MAX, 'VARCHAR(500)');
});

test('la limite est celle de la colonne', function () {
    contient('`raison_blocage` VARCHAR(500)', lire('livre.sql'), 'livre.sql');
});

groupe('Qui peut faire quoi à qui');

test('on ne change pas ses propres droits', function () {
    contient('propres droits', admin_refus_droits(7, 7), 'refusé, et dit pourquoi');
    egale('', admin_refus_droits(8, 7), 'ceux d\'un autre : permis');
});

test('on ne demande pas la suppression de son propre compte ici', function () {
    contient('Paramètres', admin_refus_suppression(['id' => 7, 'admin' => 0], 7), 'renvoyé vers ses Paramètres');
    contient('Paramètres', admin_refus_suppression(['id' => 7, 'admin' => 1], 7), 'même administrateur : c\'est son compte avant tout');
});

test('un administrateur ne se supprime pas d\'un clic : d\'abord ses droits', function () {
    contient("droits d'administrateur", admin_refus_suppression(['id' => 9, 'admin' => 1], 7), 'refusé');
    contient("droits d'administrateur", admin_refus_suppression(['id' => 9, 'admin' => true], 7), 'bool, comme admin_ligne() le range');
});

test('un compte ordinaire, bloqué ou non, peut être supprimé', function () {
    egale('', admin_refus_suppression(['id' => 9, 'admin' => 0, 'forfait' => 'standard'], 7), 'standard');
    egale('', admin_refus_suppression(['id' => 9, 'admin' => 0, 'forfait' => 'bloque'], 7), 'bloqué');
    egale('', admin_refus_suppression(['id' => 9], 7), 'colonne absente : pas administrateur');
});

groupe('Les dates — « en jour »');

test('admin_date_jour() : jj/mm/aaaa, sans l\'heure', function () {
    egale('27/09/2026', admin_date_jour('2026-09-27 14:03:11'), 'une date et heure');
    egale('01/01/2027', admin_date_jour('2027-01-01'), 'une date seule');
    egale('', admin_date_jour(null), 'null');
    egale('', admin_date_jour(''), 'vide');
    egale('', admin_date_jour('pas une date'), 'illisible');
});

test('admin_jours_depuis() : des jours entiers, jamais négatifs', function () {
    $maintenant = strtotime('2026-10-03 12:00:00');
    egale(6, admin_jours_depuis('2026-09-27 12:00:00', $maintenant), 'six jours pile');
    egale(5, admin_jours_depuis('2026-09-27 12:00:01', $maintenant), 'une seconde de moins : cinq jours entiers');
    egale(0, admin_jours_depuis('2026-10-03 08:00:00', $maintenant), 'ce matin');
    egale(0, admin_jours_depuis('2027-01-01 00:00:00', $maintenant), 'une date future : 0, pas -90');
    estNul(admin_jours_depuis(null, $maintenant), 'null');
    estNul(admin_jours_depuis('n importe quoi', $maintenant), 'illisible');
});

test('admin_depuis_texte()', function () {
    egale("aujourd'hui", admin_depuis_texte(0), '0');
    egale('hier', admin_depuis_texte(1), '1');
    egale('il y a 12 j', admin_depuis_texte(12), '12');
    egale('', admin_depuis_texte(null), 'inconnu : rien');
});

groupe('admin_ligne() — des types qu\'on peut comparer');

test('la base rend des chaînes, la ligne rend des entiers et des booléens', function () {
    $l = admin_ligne(ligne_brute(['admin' => '1', 'a_photo' => '1', 'google' => '1']), strtotime('2026-10-03 12:00:00'));
    egale(12, $l['id'], 'id');
    vrai($l['confirme'] === true, 'confirmé');
    vrai($l['photo'] === true, 'photo');
    vrai($l['admin'] === true, 'admin');
    vrai($l['google'] === true, 'Google');
    egale(4, $l['series'], 'séries');
    egale('27/09/2026', $l['inscrit_le'], 'inscrit le, en jour');
    egale(5, $l['inscrit_jours'], 'et depuis combien de jours');
    egale('2026-09-27 14:03:11', $l['cree_le'], 'la date complète reste disponible');
});

test('une adresse non confirmée, pas de photo, pas administrateur', function () {
    $l = admin_ligne(ligne_brute(['email_verifie' => '0']));
    vrai($l['confirme'] === false, 'non confirmé');
    vrai($l['photo'] === false, 'sans photo');
    vrai($l['admin'] === false, 'ordinaire');
    vrai($l['google'] === false, 'compte e-mail');
});

test('un forfait inconnu ou vide est lu « standard » (la migration 10 pas rejouée)', function () {
    egale('standard', admin_ligne(ligne_brute(['forfait' => '']))['forfait'], 'vide');
    egale('standard', admin_ligne(ligne_brute(['forfait' => 'gratuit']))['forfait'], 'inconnu');
    egale('bloque', admin_ligne(ligne_brute(['forfait' => 'bloque']))['forfait'], 'bloque reste bloque');
});

test('le blocage porte sa raison et sa date', function () {
    $l = admin_ligne(ligne_brute(['forfait' => 'bloque', 'raison_blocage' => 'Spam', 'bloque_le' => '2026-10-01 09:00:00']));
    egale('Spam', $l['raison_blocage'], 'raison');
    egale('01/10/2026', $l['bloque_le'], 'date du blocage, en jour');
});

test('une ligne presque vide ne casse rien', function () {
    $l = admin_ligne([]);
    egale(0, $l['id'], 'id');
    egale('', $l['identifiant'], 'identifiant');
    egale('standard', $l['forfait'], 'forfait');
    egale(0, $l['series'], 'séries');
    estNul($l['inscrit_jours'], 'pas de date, pas de jours');
});

groupe('admin_pour_acteur() — ce que l\'acteur peut faire de cette ligne');

test('sa propre ligne : ni droits ni suppression', function () {
    $l = admin_pour_acteur(admin_ligne(ligne_brute(['id' => '7', 'admin' => '1'])), 7);
    vrai($l['moi'] === true, 'c\'est lui');
    contient('propres droits', $l['refus_droits'], 'pas ses droits');
    contient('Paramètres', $l['refus_suppression'], 'pas sa suppression d\'ici');
});

test('un autre compte ordinaire : tout est permis', function () {
    $l = admin_pour_acteur(admin_ligne(ligne_brute(['id' => '9'])), 7);
    vrai($l['moi'] === false, 'un autre');
    egale('', $l['refus_droits'], 'ses droits se changent');
    egale('', $l['refus_suppression'], 'il se supprime');
});

test('un autre administrateur : ses droits se retirent, il ne se supprime pas encore', function () {
    $l = admin_pour_acteur(admin_ligne(ligne_brute(['id' => '9', 'admin' => '1'])), 7);
    egale('', $l['refus_droits'], 'les droits se retirent');
    contient("droits d'administrateur", $l['refus_suppression'], 'mais pas la suppression');
});

groupe('admin_totaux() — le bandeau');

test('les comptes, la confirmation, les forfaits, les séries', function () {
    $lignes = array_map('admin_ligne', [
        ligne_brute(['id' => '1', 'admin' => '1', 'nb_series' => '10']),
        ligne_brute(['id' => '2', 'email_verifie' => '0', 'nb_series' => '0']),
        ligne_brute(['id' => '3', 'forfait' => 'bloque', 'nb_series' => '3']),
        ligne_brute(['id' => '4', 'forfait' => 'illimite', 'nb_series' => '200']),
        ligne_brute(['id' => '5', 'forfait' => 'bloque', 'admin' => '1', 'email_verifie' => '0', 'nb_series' => '1']),
    ]);
    $t = admin_totaux($lignes);
    egale(5, $t['comptes'], 'comptes');
    egale(3, $t['confirmes'], 'confirmés');
    egale(2, $t['non_confirmes'], 'non confirmés');
    egale(2, $t['bloques'], 'bloqués (dont un administrateur)');
    egale(1, $t['illimites'], 'illimités');
    egale(2, $t['admins'], 'administrateurs (dont un bloqué)');
    egale(214, $t['series'], 'séries');
});

test('aucun compte : des zéros', function () {
    egale(['comptes' => 0, 'confirmes' => 0, 'non_confirmes' => 0, 'bloques' => 0,
           'illimites' => 0, 'admins' => 0, 'series' => 0], admin_totaux([]), 'tout à zéro');
});

groupe('Les phrases de la page');

test('après un changement de forfait, on dit si l\'e-mail est parti', function () {
    contient('« Bloqué »', admin_message_forfait('marco', 'bloque', 'envoye'), 'le forfait, par son libellé');
    contient('Un e-mail le lui annonce', admin_message_forfait('marco', 'bloque', 'envoye'), 'parti');
    contient('n\'a pas pu partir', admin_message_forfait('marco', 'illimite', 'echec'), 'raté : on le dit, il faudra le prévenir');
    contient('prévenez-le vous-même', admin_message_forfait('marco', 'illimite', 'echec'), 'avec la marche à suivre');
    contient('jamais été confirmée', admin_message_forfait('marco', 'standard', 'non_confirme'), 'adresse non confirmée : rien n\'est parti');
    contient('marco', admin_message_forfait('marco', 'standard', 'envoye'), 'et de qui on parle');
});

test('nommer ou révoquer un administrateur', function () {
    contient('est maintenant administrateur', admin_message_droits('marco', true), 'nommé');
    contient('n\'est plus administrateur', admin_message_droits('marco', false), 'révoqué');
});

groupe('api.php — chaque action d\'administration est gardée');

/** Les `case 'admin.…':` du switch d'api.php. */
function actions_admin_de_api(): array
{
    preg_match_all("/^    case '(admin\.[a-z_.]+)':/m", lire('api.php'), $m);
    return $m[1];
}

test('toute action admin.* d\'api.php est dans ACTIONS_ADMIN, et inversement', function () {
    $dans_api = actions_admin_de_api();
    vrai(count($dans_api) >= 5, count($dans_api) . ' actions trouvées : le motif lit bien le switch');
    foreach ($dans_api as $action) {
        vrai(in_array($action, ACTIONS_ADMIN, true), "« $action » est gardée par ACTIONS_ADMIN");
    }
    foreach (ACTIONS_ADMIN as $action) {
        vrai(in_array($action, $dans_api, true), "« $action » existe dans api.php : pas de nom mort");
    }
});

test('ACTIONS_ADMIN et la liste des actions libres du bloqué se recouvrent', function () {
    preg_match_all("/^    '(admin\.[a-z_.]+)'\s+=>/m", lire('../tests/cas/forfait_bloque_test.php'), $m);
    $libres = $m[1];
    sort($libres);
    $gardees = ACTIONS_ADMIN;
    sort($gardees);
    egale($gardees, $libres, 'un administrateur bloqué garde ces actions : forfait_bloque_test.php le dit');
});

test('la garde passe après le CSRF et avant le switch, en 403', function () {
    $api = lire('api.php');
    $csrf   = strpos($api, "csrf_valide(\$_POST['csrf']");
    $garde  = strpos($api, 'action_admin($action) && !est_admin($moi)');
    $switch = strpos($api, 'switch ($action)');
    vrai($csrf !== false && $garde !== false && $switch !== false, 'les trois repères existent');
    vrai($csrf < $garde, 'après le contrôle du jeton');
    vrai($garde < $switch, 'avant le switch : aucune action n\'a commencé');
    $bloc = substr($api, $garde, 220);
    contient('403', $bloc, 'interdit');
    contient('administrateurs', $bloc, 'et dit pourquoi');
});

test('l\'administrateur est relu en base à chaque requête', function () {
    $f = lire('includes/fonctions.php');
    preg_match('/function utilisateur_actuel\(\).*?\n}\n/s', $f, $m);
    contient('admin', (string) ($m[0] ?? ''), 'utilisateur_actuel() lit la colonne `admin` : un droit retiré s\'éteint aussitôt');
    contient('raison_blocage', (string) ($m[0] ?? ''), 'et la raison du blocage, pour la dire');
});

test('admin.forfait : raison exigée pour bloquer, forfait relu, e-mail seulement si l\'adresse est confirmée', function () {
    $api = lire('api.php');
    $debut = (int) strpos($api, "case 'admin.forfait'");
    $bloc  = substr($api, $debut, (int) strpos($api, "case 'admin.admin'") - $debut);
    contient('admin_forfait_valide($forfait)', $bloc, 'liste fermée');
    contient('admin_raison_erreur($raison)', $bloc, 'raison validée par la fonction pure');
    contient("'champ' => 'raison'", $bloc, 'l\'erreur va SOUS le champ, pas dans un toast');
    contient('admin_changer_forfait(', $bloc, 'la fonction qui relit ce qu\'elle écrit');
    contient("'non_confirme'", $bloc, 'pas d\'e-mail à une adresse jamais confirmée');
    contient('avertir_forfait_change(', $bloc, 'l\'e-mail part');
    contient('journal_securite(', $bloc, 'et c\'est consigné');
});

test('admin.admin : jamais pour soi-même', function () {
    $api = lire('api.php');
    $debut = (int) strpos($api, "case 'admin.admin'");
    $bloc  = substr($api, $debut, (int) strpos($api, "case 'admin.supprimer'") - $debut);
    contient('admin_refus_droits($cible_id, $mon_id)', $bloc, 'la règle pure, appliquée côté serveur');
    contient('journal_securite(', $bloc, 'consigné');
});

test('admin.supprimer : rien n\'est supprimé, un lien part vers ADMIN_EMAIL', function () {
    $api = lire('api.php');
    $debut = (int) strpos($api, "case 'admin.supprimer'");
    $bloc  = substr($api, $debut, (int) strpos($api, "default:\n", $debut) - $debut);
    contient("generer_jeton_action(\$cible_id, 'suppression_admin', ADMIN_SUPPRESSION_DUREE", $bloc, 'un jeton à durée limitée');
    contient('demander_confirmation_suppression(', $bloc, 'envoyé à ADMIN_EMAIL');
    contient('admin_refus_suppression($cible, $mon_id)', $bloc, 'mêmes règles que la page');
    sans('DELETE FROM utilisateur', $bloc, 'aucune suppression ici');
    sans('admin_supprimer_compte', $bloc, 'ni par la fonction');
    contient('DELETE FROM jeton_action', $bloc, 'un lien qu\'on n\'a pas pu envoyer ne reste pas valable');
});

groupe('admin.php — la page');

test('réservée aux administrateurs, avant tout le reste', function () {
    $page = lire('admin.php');
    $garde = strpos($page, 'exiger_admin()');
    vrai($garde !== false, 'exiger_admin() est appelée');
    $premier_acces_base = strpos($page, '$pdo');
    vrai($garde < $premier_acces_base, 'avant le premier accès à la base');
    $f = lire('includes/fonctions.php');
    preg_match('/function exiger_admin\(\).*?\n}\n/s', $f, $m);
    contient('http_response_code(404)', (string) ($m[0] ?? ''), 'un 404 : « interdit » confirmerait que la page existe');
    contient('exiger_connexion()', (string) ($m[0] ?? ''), 'et il faut d\'abord être connecté');
});

test('la suppression ne s\'exécute jamais au GET', function () {
    $page = lire('admin.php');
    $suppression = (int) strpos($page, 'admin_supprimer_compte(');
    $post = (int) strpos($page, "\$_SERVER['REQUEST_METHOD'] === 'POST'");
    $get  = (int) strpos($page, "\$_GET['supprimer']");
    vrai($suppression > $post && $suppression < $get, 'l\'effacement est dans la branche POST, avant la lecture du lien par GET');
    contient('exiger_csrf()', $page, 'avec le jeton CSRF');
    contient("jeton_action_valide(", $page, 'et le lien reçu par courrier');
    contient("'suppression_admin'", $page, 'du bon type');
});

test('la cible est relue avant d\'être effacée', function () {
    $page = lire('admin.php');
    $post = substr($page, (int) strpos($page, "formulaire === 'annuler_suppression'"));
    $relue = strpos($post, 'admin_utilisateur($pdo, (int) $j[\'id\'])');
    $refus = strpos($post, 'admin_refus_suppression($cible, $mon_id)');
    $efface = strpos($post, 'admin_supprimer_compte(');
    vrai($relue !== false && $refus !== false && $efface !== false, 'les trois étapes existent');
    vrai($relue < $refus && $refus < $efface, 'relue, jugée, puis seulement effacée : elle a pu devenir administrateur entre-temps');
});

test('chaque ligne passe par e() : identifiant, adresse, raison', function () {
    $page = lire('admin.php');
    foreach (["e(\$c['identifiant'])", "e(\$c['email'])", "e(\$c['raison_blocage']"] as $sortie) {
        contient($sortie, $page, $sortie);
    }
    sans("<?= \$c['identifiant']", $page, 'jamais brut');
    sans("<?= \$c['email']", $page, 'jamais brut');
});

test('la page charge son script, et rien d\'en ligne', function () {
    $page = lire('admin.php');
    contient("actif('js/admin.js')", $page, 'js/admin.js');
    contient("actif('js/commun.js')", $page, 'js/commun.js, qui porte Lib.api');
    sans('onclick=', $page, 'la CSP interdit le JavaScript en ligne');
    sans('<style', $page, 'et le CSS en ligne');
    sans('style="', $page, 'et l\'attribut style');
});

test('des liens y mènent : le pied de la bibliothèque et les Paramètres, aux administrateurs seulement', function () {
    foreach (['index.php', 'parametres.php'] as $fichier) {
        $source = lire($fichier);
        contient('href="admin.php"', $source, "$fichier : le lien existe");
        $lien = (int) strpos($source, 'href="admin.php"');
        $garde = strrpos(substr($source, 0, $lien), 'est_admin($moi)');
        vrai($garde !== false && $lien - $garde < 700, "$fichier : le lien est dans un « if est_admin »");
    }
});

groupe('admin_changer_forfait() / admin_supprimer_compte() — l\'écriture');

test('le forfait est relu dans une transaction annulée si la base ne le retient pas', function () {
    $source = lire('includes/admin.php');
    preg_match('/function admin_changer_forfait\(.*?\n}\n/s', $source, $m);
    $f = (string) ($m[0] ?? '');
    contient('beginTransaction()', $f, 'dans une transaction');
    contient('SELECT forfait FROM utilisateur', $f, 'on relit ce qui a été écrit');
    contient('rollBack()', $f, 'annulé en cas d\'écart');
    contient('rejouez livre.sql', $f, 'et l\'erreur dit quoi faire');
    contient('NOW()', $f, 'la date du blocage');
    contient('NULL', $f, 'effacée dans tous les autres cas');
});

test('la suppression relève les images AVANT d\'effacer la ligne', function () {
    $source = lire('includes/admin.php');
    preg_match('/function admin_supprimer_compte\(.*?\n}\n/s', $source, $m);
    $f = (string) ($m[0] ?? '');
    $couvertures = strpos($f, 'SELECT couverture');
    $photo       = strpos($f, 'SELECT photo');
    $delete      = strpos($f, 'DELETE FROM utilisateur');
    $images      = strpos($f, 'supprimer_images_locales');
    vrai($couvertures < $delete && $photo < $delete, 'les images sont relevées d\'abord');
    vrai($delete < $images, 'effacées du disque ensuite');
});

groupe('livre.sql — les colonnes et leur migration');

test('les colonnes sont dans le schéma neuf', function () {
    $sql = lire('livre.sql');
    preg_match('/CREATE TABLE IF NOT EXISTS `utilisateur` \(.*?\n\) ENGINE/s', $sql, $m);
    $table = (string) ($m[0] ?? '');
    contient('`admin`        TINYINT(1)   NOT NULL DEFAULT 0', $table, 'admin, à 0 : personne n\'est administrateur par défaut');
    contient('`raison_blocage` VARCHAR(500) NOT NULL DEFAULT \'\'', $table, 'raison_blocage');
    contient('`bloque_le`    DATETIME     NULL DEFAULT NULL', $table, 'bloque_le');
    preg_match('/CREATE TABLE IF NOT EXISTS `jeton_action` \(.*?\n\) ENGINE/s', $sql, $m);
    contient("'suppression_admin'", (string) ($m[0] ?? ''), 'le type de jeton de la suppression');
});

test('la migration 11 est rejouable, portable, et pose chaque colonne', function () {
    $sql = lire('livre.sql');
    $debut = (int) strpos($sql, '--  11. Page d\'administration');
    vrai($debut > 0, 'la migration 11 existe');
    vrai($debut > (int) strpos($sql, '--  10. Forfait'), 'après la 10');
    $mig = substr($sql, $debut);
    foreach (['admin', 'raison_blocage', 'bloque_le'] as $colonne) {
        contient("COLUMN_NAME = '$colonne'", $mig, "$colonne : on interroge information_schema d'abord");
        contient("ADD COLUMN `$colonne`", $mig, "$colonne : puis on l'ajoute");
    }
    contient("COLUMN_TYPE LIKE '%''suppression_admin''%'", $mig, 'le type de jeton : sans effet s\'il est déjà là');
    contient("'DO 0'", $mig, 'et « DO 0 » sinon');
    sans('ADD COLUMN IF NOT EXISTS', preg_replace('/--.*$/m', '', $mig), 'jamais « IF NOT EXISTS » : extension MariaDB');
});

test('la migration est avant tout UPDATE qui en dépend (même piège que la 10)', function () {
    $sql = lire('livre.sql');
    contient('UPDATE utilisateur SET admin = 1 WHERE identifiant', $sql, 'le premier administrateur se pose à la main, et le fichier l\'explique');
});
