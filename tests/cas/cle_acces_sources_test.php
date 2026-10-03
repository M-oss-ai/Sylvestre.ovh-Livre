<?php
/* =====================================================================
   Clés d'accès — ce qui se vérifie en LISANT les sources.

   Le parcours entier traverse la base, le réseau et un navigateur : rien
   de cela n'est à la portée des tests (voir tests/LISEZMOI.md). Ce fichier
   garde donc les liaisons dont l'oubli ne casse rien tout de suite — une
   page qui ne charge pas le script, une action qui ne consomme pas la
   confirmation, un point d'entrée qui compterait ses échecs avec ceux du
   mot de passe. Un garde-fou de lecture, pas une mesure du comportement :
   le comportement se vérifie à la main, et par la partie pure
   (cle_acces_test.php, tests/js).
   ===================================================================== */

declare(strict_types=1);

require __DIR__ . '/../lanceur.php';
require_once CHEMIN_PROJET . '/includes/google.php';

/** Le contenu d'un fichier du projet. */
function source(string $chemin): string
{
    return (string) file_get_contents(CHEMIN_PROJET . '/' . $chemin);
}

/** Le bloc d'un « case '…': { … }` d'api.php, jusqu'au case suivant. */
function bloc_api(string $source, string $action): string
{
    $debut = strpos($source, "case '" . $action . "'");
    if ($debut === false) {
        return '';
    }
    $fin = strpos($source, "\n    case '", $debut + 10);
    return substr($source, $debut, ($fin === false ? strlen($source) : $fin) - $debut);
}

groupe('livre.sql — la table cle_acces');

test('la table existe, rejouable, et ne garde que de quoi VÉRIFIER', function () {
    $sql = source('livre.sql');
    contient('CREATE TABLE IF NOT EXISTS `cle_acces`', $sql, 'rejouable sans effacer');
    motif('/`cle_hash`\s+CHAR\(64\)\s+NOT NULL/', $sql, 'l\'empreinte de l\'identifiant, qui se cherche');
    motif('/UNIQUE KEY `uk_cle_acces_hash`/', $sql, 'une même clé ne sert pas deux comptes');
    motif('/`cle_publique`\s+TEXT/', $sql, 'la clé publique');
    motif('/FOREIGN KEY \(`utilisateur_id`\) REFERENCES `utilisateur`[^;]*ON DELETE CASCADE/s', $sql, 'les clés tombent avec le compte');
    sans('cle_privee', $sql, 'aucune colonne pour une clé privée : le site n\'en a jamais');
});

groupe('Action sensible — ajouter une clé exige de prouver son identité');

test('compte.cle_creer est une action sensible, et Google ramène sur la bonne carte', function () {
    vrai(isset(ACTIONS_SENSIBLES['compte.cle_creer']), 'dans ACTIONS_SENSIBLES');
    contient('clé d\'accès', ACTIONS_SENSIBLES['compte.cle_creer'], 'le texte de la fenêtre parle de la clé');
    egale('parametres.php#securite', google_page_action('compte.cle_creer'), 'retour sur Sécurité après Google');
});

test('api.php : les trois actions existent et cloisonnent par compte', function () {
    $api = source('api.php');
    foreach (['cle.options', 'cle.creer', 'cle.supprimer'] as $action) {
        contient("case '$action'", $api, "$action existe");
    }
    contient("'compte.cle_creer'", bloc_api($api, 'cle.options'), 'cle.options exige la confirmation de cette action');
    contient('confirmation_recente', bloc_api($api, 'cle.options'), '… par confirmation_recente()');
    $creer = bloc_api($api, 'cle.creer');
    contient('confirmation_recente($mon_id, \'compte.cle_creer\')', $creer, 'cle.creer l\'exige aussi');
    contient('oublier_confirmation()', $creer, 'et la consomme : elle ne sert qu\'une fois');
    contient('cle_defi_prendre(\'creation\', $mon_id)', $creer, 'le défi est celui de CE compte, pris (donc consommé) d\'abord');
    contient('avertir_cle_acces_ajoutee', $creer, 'le titulaire est prévenu par e-mail');
    contient('$mon_id', bloc_api($api, 'cle.supprimer'), 'on ne retire que ses propres clés');
});

test('les Paramètres : le script est chargé AVANT settings.js, qui s\'en sert au chargement', function () {
    $page = source('parametres.php');
    $a = strpos($page, "actif('js/cle-acces.js')");
    $b = strpos($page, "actif('js/settings.js')");
    vrai($a !== false && $b !== false && $a < $b, 'cle-acces.js précède settings.js');
    contient('id="btn-ajouter-cle"', $page, 'le bouton d\'ajout');
    motif('/id="btn-ajouter-cle"[^>]*class="[^"]*hidden/', $page, 'caché sans JavaScript : sans lui, il ne ferait rien');
});

test('settings.js : au retour de Google, la fenêtre se rouvre sur cette action', function () {
    $js = source('js/settings.js');
    contient('"compte.cle_creer": "btn-ajouter-cle"', $js, 'BOUTON_ACTION');
    contient('PRECHARGER["compte.cle_creer"]', $js, 'le défi est tiré avant le clic final');
    contient('navigator.credentials.create', $js, 'la création passe par le navigateur');
});

groupe('Connexion par clé');

test('connexion.php : le champ propose les clés, le script est chargé, le jeton est là', function () {
    $page = source('connexion.php');
    contient('autocomplete="username webauthn"', $page, 'le gestionnaire propose ses clés dans le champ');
    contient("js/cle-acces.js", $page, 'le script');
    contient('data-csrf="<?= e($csrf) ?>"', $page, 'le jeton CSRF, que le script envoie');
    contient('bouton_cle_acces()', $page, 'le bouton');
});

test('connexion-cle.php : défi à usage unique, échecs comptés À PART, session ouverte par connecter()', function () {
    $src = source('connexion-cle.php');
    contient('csrf_valide', $src, 'le jeton CSRF est exigé');
    contient("cle_defi_prendre('connexion', 0)", $src, 'le défi est pris, donc consommé');
    contient("limiteur_echec('connexion_cle'", $src, 'les échecs se comptent');
    sans("limiteur_echec('connexion'", $src, 'mais pas avec ceux du mot de passe');
    sans("limiteur_echec('connexion_compte'", $src, 'ni par compte : on ne verrouille pas un compte de l\'extérieur');
    contient('connecter($compte_id)', $src, 'la session s\'ouvre comme après un mot de passe juste');
    contient('email_verifie', $src, 'une adresse non confirmée n\'ouvre rien');
    contient('cle_trouver(', $src, 'le compte vient de la clé retrouvée en base…');
    sans("\$_POST['utilisateur_id']", $src, '… jamais d\'un champ du navigateur');
    vrai(strlen('connexion_cle') <= 30, 'le nom d\'action tient dans tentative_ip.action (30 caractères)');
});

test('js/cle-acces.js : pas de HTML fabriqué, et la proposition en arrière-plan ne boucle pas', function () {
    $js = source('js/cle-acces.js');
    sans('innerHTML', $js, 'textContent seulement');
    sans('eval(', $js, 'aucun eval (CSP)');
    contient('mediation: "conditional"', $js, 'la proposition dans le champ identifiant');
    /* La première version relançait la demande en arrière-plan à chaque
       échec : un échec immédiat (aucune clé à proposer) bouclait, et
       chaque tour demandait une clé au gestionnaire de mots de passe. */
    $debut = strpos($js, 'async function proposerDansLeChamp');
    $fin   = strpos($js, '/** Reprend la proposition');
    $corps = substr($js, (int) $debut, (int) $fin - (int) $debut);
    motif('/catch \(err\) \{[^}]*return;/s', $corps, 'un échec de la demande en arrière-plan sort sans relancer');
});

test('reinitialiser-mot-de-passe.php : « Mot de passe oublié » retire aussi les clés d\'accès', function () {
    $src = source('reinitialiser-mot-de-passe.php');
    $a = strpos($src, 'invalider_sessions(');
    $b = strpos($src, 'cle_effacer_toutes(');
    vrai($a !== false && $b !== false && $b > $a, 'cle_effacer_toutes() suit invalider_sessions()');
});

test('connexion-cle.php est un point d\'entrée JSON : aucune page HTML, donc aucune feuille à charger', function () {
    $src = source('connexion-cle.php');
    sans('<html', $src, 'pas de balise html (tactile_test ne la compte pas parmi les pages)');
    contient('reponse_json', $src, 'il répond en JSON');
});
