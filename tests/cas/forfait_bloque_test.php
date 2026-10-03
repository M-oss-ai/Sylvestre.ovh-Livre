<?php
/* =====================================================================
   Le forfait « bloqué » : consultation seule.

   Un compte bloqué lit, cherche, filtre, exporte et gère son compte ; il ne
   peut plus créer, modifier, mettre en favori, changer de tome, importer,
   supprimer une série, vider sa bibliothèque ni chercher une couverture
   (demande de l'utilisateur, plus ce qui relève de la même idée : voir
   ACTIONS_BLOQUEES, includes/fonctions.php).

   Le point faible d'une telle liste est l'OUBLI : une action ajoutée plus
   tard à api.php et que personne ne classe resterait ouverte à un compte
   bloqué. Le test « chaque action est classée » l'empêche : il lit les
   `case` d'api.php et exige que chacun soit bloqué, ou nommé ici parmi les
   actions libres, avec sa raison.
   ===================================================================== */

declare(strict_types=1);

require __DIR__ . '/../lanceur.php';

/** Le contenu d'un fichier du projet : dans site/, sauf le schéma, à la racine du dépôt. */
function source(string $chemin): string
{
    $racine = $chemin === 'livre.sql' ? CHEMIN_PROJET : CHEMIN_SITE;
    return (string) file_get_contents($racine . '/' . $chemin);
}

/** Les comptes qu'on essaie : seul `forfait` compte. */
function compte_de(?string $forfait): array
{
    return $forfait === null ? ['id' => 4] : ['id' => 4, 'forfait' => $forfait];
}

/** Une série complète, dont on ne change que ce que le test regarde. */
function serie_test(array $modifications = []): array
{
    return $modifications + [
        'id'          => 7,
        'titre'       => 'Fullmetal Alchemist',
        'auteur'      => 'Hiromu Arakawa',
        'tome_actuel' => 3,
        'statut'      => 'cours',
        'couverture'  => '',
        'favori'      => 0,
    ];
}

/**
 * Les actions d'api.php que le forfait bloqué laisse ouvertes, et pourquoi.
 * (`donnees.exporter` n'est pas un `case` : c'est un bloc avant le switch,
 * ouvert lui aussi — un droit sur ses propres données.)
 */
const ACTIONS_LIBRES_DU_BLOQUE = [
    'session.verifier'             => 'savoir si la session vit encore',
    'compte.authentifier'          => 'prouver son identité, pour gérer son compte',
    'compte.profil'                => 'identifiant, adresse, photo : le compte, pas la bibliothèque',
    'compte.motdepasse'            => 'sa sécurité',
    'compte.supprimer_mdp'         => 'sa sécurité',
    'cle.options'                  => 'sa sécurité (clés d\'accès)',
    'cle.creer'                    => 'sa sécurité (clés d\'accès)',
    'cle.supprimer'                => 'sa sécurité (clés d\'accès)',
    'compte.annuler_confirmation'  => 'sa sécurité',
    'compte.renvoyer_verification' => 'confirmer son adresse',
    'compte.supprimer'             => 'quitter le site : un droit, pas une faveur',
    'compte.filtre_sensible'       => 'une préférence de recherche, sans effet sur la bibliothèque',
    /* Les actions d'administration portent sur d'AUTRES comptes : on peut être
       bloqué ET administrateur (colonne `admin`, distincte du forfait). Elles
       sont gardées par ACTIONS_ADMIN, pas par le forfait ; admin_test.php
       vérifie que cette liste et ACTIONS_ADMIN se recouvrent. */
    'admin.forfait'                => 'administrer les autres comptes : un administrateur bloqué le reste',
    'admin.admin'                  => 'administrer les autres comptes : un administrateur bloqué le reste',
    'admin.supprimer'              => 'administrer les autres comptes : un administrateur bloqué le reste',
];

groupe('compte_bloque() — qui est en consultation seule');

test('seul le forfait « bloque » l\'est', function () {
    vrai(compte_bloque(compte_de('bloque')), 'bloque');
    faux(compte_bloque(compte_de('standard')), 'standard');
    faux(compte_bloque(compte_de('illimite')), 'illimite : ce forfait-là est le plus ouvert');
});

test('un forfait vide, inconnu ou absent ne bloque personne', function () {
    faux(compte_bloque(compte_de('')), 'vide — ce que MySQL range quand la valeur manque à l\'ENUM');
    faux(compte_bloque(compte_de('gratuit')), 'inconnu');
    faux(compte_bloque(compte_de('BLOQUE')), 'la casse compte : la base ne range que « bloque »');
    faux(compte_bloque(compte_de(null)), 'clé absente');
    faux(compte_bloque([]), 'aucune ligne');
});

groupe('action_bloquee() — ce que le serveur refuse');

test('ce que l\'utilisateur a demandé est refusé : créer, modifier, favori, tome, import', function () {
    $bloque = compte_de('bloque');
    vrai(action_bloquee($bloque, 'serie.enregistrer'), 'créer ET modifier une fiche');
    vrai(action_bloquee($bloque, 'serie.favori'), 'mettre en favori');
    vrai(action_bloquee($bloque, 'serie.avancer'), 'tome suivant');
    vrai(action_bloquee($bloque, 'serie.reculer'), 'tome précédent');
    vrai(action_bloquee($bloque, 'donnees.importer'), 'importer un .json');
});

test('ce qui touche à la même bibliothèque l\'est aussi', function () {
    $bloque = compte_de('bloque');
    vrai(action_bloquee($bloque, 'serie.supprimer'), 'supprimer une série');
    vrai(action_bloquee($bloque, 'donnees.vider'), 'vider la bibliothèque');
    vrai(action_bloquee($bloque, 'couverture.chercher'), 'chercher une couverture (appels MangaDex, quota)');
    vrai(action_bloquee($bloque, 'couverture.rafraichir'), 'réécrire l\'image d\'une série');
    vrai(action_bloquee($bloque, 'couverture.delier'), 'rapatrier une image sur le disque');
});

test('les autres forfaits ne sont jamais gênés', function () {
    foreach (['standard', 'illimite', '', 'gratuit'] as $forfait) {
        foreach (ACTIONS_BLOQUEES as $action) {
            faux(action_bloquee(compte_de($forfait), $action), "« $forfait » peut faire « $action »");
        }
    }
});

test('un compte bloqué garde l\'export, son compte, sa sécurité et la sortie', function () {
    $bloque = compte_de('bloque');
    foreach (array_keys(ACTIONS_LIBRES_DU_BLOQUE) as $action) {
        faux(action_bloquee($bloque, $action), "« $action » reste ouverte");
    }
    faux(action_bloquee($bloque, 'donnees.exporter'), 'l\'export : le droit d\'emporter ses données');
});

test('une action inconnue ou vide n\'est pas bloquée par ce mécanisme', function () {
    faux(action_bloquee(compte_de('bloque'), ''), 'vide');
    faux(action_bloquee(compte_de('bloque'), 'inventee.action'), 'inconnue : api.php répond déjà « action inconnue »');
    faux(action_bloquee(compte_de('bloque'), 'SERIE.FAVORI'), 'la casse compte, comme dans le switch d\'api.php');
});

groupe('ACTIONS_BLOQUEES — la liste tient avec api.php');

/** Les noms des `case '…':` du switch d'api.php. */
function actions_de_api(): array
{
    preg_match_all("/^    case '([a-z_.]+)':/m", source('api.php'), $m);
    return $m[1];
}

test('la liste est faite de noms uniques', function () {
    egale(count(ACTIONS_BLOQUEES), count(array_unique(ACTIONS_BLOQUEES)), 'aucun doublon');
    foreach (ACTIONS_BLOQUEES as $action) {
        motif('/^[a-z]+\.[a-z_]+$/', $action, "« $action » a la forme d'un nom d'action");
    }
});

test('chaque action bloquée existe dans api.php', function () {
    $api = actions_de_api();
    vrai(count($api) >= 20, count($api) . ' actions trouvées, au moins 20 attendues (le test lit bien api.php)');
    foreach (ACTIONS_BLOQUEES as $action) {
        vrai(in_array($action, $api, true), "« $action » est un case d'api.php : sinon la liste garde un nom mort");
    }
});

test('chaque action d\'api.php est CLASSÉE : bloquée, ou libre pour une raison dite', function () {
    foreach (actions_de_api() as $action) {
        $classee = in_array($action, ACTIONS_BLOQUEES, true) || isset(ACTIONS_LIBRES_DU_BLOQUE[$action]);
        vrai($classee, "« $action » n'est ni dans ACTIONS_BLOQUEES (includes/fonctions.php) ni dans "
            . 'ACTIONS_LIBRES_DU_BLOQUE (ce fichier) : un compte bloqué pourrait s\'en servir. Décidez.');
    }
});

test('aucune action n\'est à la fois bloquée et libre', function () {
    foreach (array_keys(ACTIONS_LIBRES_DU_BLOQUE) as $action) {
        faux(in_array($action, ACTIONS_BLOQUEES, true), "« $action » ne peut pas être dans les deux listes");
    }
});

groupe('api.php — la garde passe avant tout traitement');

test('le refus est posé après le CSRF et avant l\'export comme avant le switch', function () {
    $api = source('api.php');
    $csrf   = strpos($api, "csrf_valide(\$_POST['csrf']");
    $garde  = strpos($api, 'action_bloquee($moi, $action)');
    $export = strpos($api, "\$action === 'donnees.exporter'");
    $switch = strpos($api, 'switch ($action)');
    vrai($csrf !== false && $garde !== false && $export !== false && $switch !== false, 'les quatre repères existent');
    vrai($csrf < $garde, 'après le contrôle du jeton : un refus ne se joue pas sans lui');
    vrai($garde < $switch, 'avant le switch : aucune action n\'a commencé');
});

test('le refus est un 403 qui dit pourquoi, sans rien faire d\'autre', function () {
    $api = source('api.php');
    $debut = (int) strpos($api, 'action_bloquee($moi, $action)');
    $garde = substr($api, $debut, 320);
    contient('message_compte_bloque(', $garde, 'le message est celui de la fonction');
    contient('raison_blocage', $garde, 'et il porte la raison saisie par l\'administrateur');
    contient('403', $garde, 'interdit, pas « introuvable »');
    contient("'bloque' => true", $garde, 'le navigateur peut le reconnaître');
});

test('le message nomme la situation et un recours', function () {
    $message = message_compte_bloque();
    contient('consultation seule', $message, 'dit ce qui se passe');
    contient(ADMIN_EMAIL, $message, 'donne le moyen de le rétablir');
    sans('Raison', $message, 'sans motif noté (blocage posé à la main en base), aucune « Raison : » creuse');
});

test('le message porte la raison saisie par l\'administrateur', function () {
    $message = message_compte_bloque("  Publicité en série dans les titres.\n");
    contient('Raison : Publicité en série dans les titres.', $message, 'la raison, sans ses espaces de bord');
    contient(ADMIN_EMAIL, $message, 'le recours reste donné');
    sans('Raison', message_compte_bloque('   '), 'une raison faite d\'espaces n\'en est pas une');
});

groupe('carte_html($s, true) — la carte d\'un compte bloqué');

test('rien n\'y agit : ni bouton, ni couverture cliquable', function () {
    $html = carte_html(serie_test(), true);
    sans('<button', $html, 'aucun bouton');
    sans('data-action', $html, 'aucune action pour le script');
    sans('role="button"', $html, 'la couverture n\'est plus présentée comme un bouton');
    sans('Modifier', $html, 'aucune invitation à modifier');
});

test('tout ce qui se lit reste', function () {
    $html = carte_html(serie_test(['auteur' => 'Hiromu Arakawa', 'tome_actuel' => 3]), true);
    contient('Fullmetal Alchemist', $html, 'le titre');
    contient('Hiromu Arakawa', $html, 'l\'auteur');
    contient('Vous avez lu le tome <b>3</b>', $html, 'la progression');
    contient('Tome 4 à emprunter', $html, 'le prochain tome');
    contient('class="badge"', $html, 'la pastille de statut');
    contient('class="card-cover"', $html, 'la couverture, que le clavier retrouve');
});

test('les attributs dont le filtre et la recherche ont besoin restent', function () {
    $html = carte_html(serie_test(['favori' => 1, 'statut' => 'termine']), true);
    foreach (['data-id="7"', 'data-statut="termine"', 'data-titre="Fullmetal Alchemist"', 'data-favori="1"',
              'data-tome="3"', 'data-image="'] as $attribut) {
        contient($attribut, $html, $attribut);
    }
});

test('le favori se lit : une étoile qui n\'est pas un bouton', function () {
    $avec = carte_html(serie_test(['favori' => 1]), true);
    contient('favori-lecture', $avec, 'l\'étoile est là');
    contient('aria-label="Favori"', $avec, 'et dite aux lecteurs d\'écran');
    sans('btn-favori', $avec, 'mais ce n\'est pas le bouton');
    $sans = carte_html(serie_test(['favori' => 0]), true);
    sans('favori-lecture', $sans, 'pas de favori : pas d\'étoile');
    sans('card-actions', $sans, 'et pas de rangée vide');
});

test('l\'échappement tient en consultation seule aussi', function () {
    $html = carte_html(serie_test(['titre' => '<img src=x onerror=alert(1)>', 'auteur' => '" onmouseover="x']), true);
    sans('<img src=x', $html, 'aucune balise injectée');
    sans('onmouseover="x', $html, 'aucun attribut greffé');
});

test('par défaut la carte est inchangée : ses quatre boutons y sont', function () {
    $html = carte_html(serie_test());
    foreach (['data-action="undo"', 'data-action="advance"', 'data-action="favori"', 'data-action="edit"',
              'role="button"'] as $morceau) {
        contient($morceau, $html, $morceau);
    }
    egale(carte_html(serie_test()), carte_html(serie_test(), false), 'faux explicite = valeur par défaut');
});

groupe('Les pages — elles cessent de proposer ce que le serveur refuse');

test('index.php : le « + », les cartes et le quota suivent le forfait', function () {
    $page = source('index.php');
    motif('/\$bloque\s*= compte_bloque\(\$moi\)/', $page, 'la page sait que le compte est bloqué');
    contient('carte_html($s, $bloque)', $page, 'les cartes sont rendues en lecture seule');
    contient('data-bloque="<?= $bloque', $page, 'le script le sait aussi (data-bloque sur <body>)');
    motif('/id="btn-add" class="[^"]*<\?= \$bloque \? \' hidden\'/', $page, 'le « + » est caché');
    motif('/class="btn btn-primary<\?= \$bloque \? \' hidden\' : \'\' \?>" id="btn-add-first"/', $page, 'le bouton de la bibliothèque vide aussi');
    contient('compte-bloque', $page, 'un bandeau dit pourquoi');
    contient('($bloque || $moi[\'forfait\'] === \'illimite\') ? 0', $page, 'pas de quota de séries à annoncer');
});

test('js/app.js : les gestes restés possibles sont fermés', function () {
    $js = source('js/app.js');
    contient('document.body.dataset.bloque === "1"', $js, 'lit data-bloque');
    foreach (['function demanderAjout() {', 'function ouvrirModale(carte) {', 'function demanderSuppression(id, titre, retour) {'] as $fonction) {
        $debut = (int) strpos($js, $fonction);
        contient('if (BLOQUE) return;', substr($js, $debut, 160), $fonction . ' refuse');
    }
});

test('parametres.php : forfait dit, import et « Vider » désactivés, export intact', function () {
    $page = source('parametres.php');
    motif('/\$bloque\s*= compte_bloque\(\$moi\)/', $page, 'la page sait que le compte est bloqué');
    contient('<b>Bloqué 🔒</b>', $page, 'la carte Forfait le dit');
    motif('/id="btn-import-all"[^>]*<\?= \$bloque \? \' disabled\'/', $page, 'l\'import est désactivé');
    motif('/id="btn-clear-library"[^>]*<\?= \$bloque \? \' disabled\'/', $page, '« Vider » est désactivé');
    contient('id="btn-export-all"', $page, 'l\'export reste');
    sans('btn-export-all" class="btn btn-ghost"<?= $bloque', $page, 'et n\'est pas désactivé');
});

groupe('Base de données — la valeur « bloque » existe');

test('livre.sql : l\'ENUM la porte, et une migration rejouable l\'ajoute aux bases existantes', function () {
    $sql = source('livre.sql');
    contient("`forfait`      ENUM('standard','illimite','bloque') NOT NULL DEFAULT 'standard'", $sql, 'le schéma neuf');
    contient("COLUMN_NAME = 'forfait'", $sql, 'la migration interroge information_schema');
    contient("LIKE '%''bloque''%'", $sql, 'elle ne s\'exécute que si la valeur manque');
    contient("MODIFY COLUMN `forfait` ENUM(''standard'',''illimite'',''bloque'') NOT NULL DEFAULT ''standard''", $sql,
        'MODIFY garde le défaut : aucun compte ne change de forfait');
    // Les commentaires du fichier la citent pour l'interdire : on ne regarde que la migration 10.
    sans('ADD COLUMN IF NOT EXISTS', substr($sql, (int) strpos($sql, '--  10. Forfait')), 'la migration n emploie jamais cette extension MariaDB');
    vrai(strpos($sql, "COLUMN_NAME = 'forfait'") > strpos($sql, 'CREATE TABLE IF NOT EXISTS `utilisateur`'),
        'la migration vient après la création de la table');
});

test('le rapport du cron compte les comptes bloqués', function () {
    $cron = source('purger.php');
    contient("SUM(forfait = 'bloque')", $cron, 'la requête les compte');
    contient("'Forfait bloqué'", $cron, 'le rapport les annonce');
});
