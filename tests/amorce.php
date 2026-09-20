<?php
/* =====================================================================
   Amorce : place le projet dans un état de test, puis le charge.

   Ce fichier doit être inclus AVANT toute autre chose par un fichier de
   cas, et la raison est dans l'ordre des opérations : includes/config.php
   fige la configuration en constantes et ouvre la connexion PDO dès son
   inclusion. Tout ce qu'on veut imposer — configuration, journal,
   sessions — doit donc être posé avant cette ligne-là.

   Trois garde-fous, qui font qu'un test ne peut rien abîmer :

     1. DB_NAME pointe sur « information_schema », jamais sur la base du
        site. La connexion s'ouvre (config.php l'exige) mais aucune table
        de l'application n'est joignable : un test qui tenterait une
        écriture échouerait bruyamment au lieu de toucher vos données.
     2. SMTP_HOST / SMTP_USER / SMTP_PASSWORD sont VIDÉS. envoyer_email_smtp()
        refuse de partir quand l'un des trois manque (voir son code) :
        aucun e-mail ne peut sortir pendant les tests, même par erreur.
     3. Le journal (error_log) et les sessions sont redirigés vers des
        fichiers jetables du dossier temporaire du système.
   ===================================================================== */

declare(strict_types=1);

define('CHEMIN_PROJET', dirname(__DIR__));

/* ---------------------------------------------------------------------
   1. Les erreurs doivent se VOIR — c'est l'exact inverse de la production
   --------------------------------------------------------------------- */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

/* Sous Windows, la console est en cp850 ou cp1252 par défaut : les noms
   de tests accentués y deviennent illisibles. */
if (function_exists('sapi_windows_cp_set')) {
    @sapi_windows_cp_set(65001);
}

/* ---------------------------------------------------------------------
   2. Journal isolé

   journal_securite(), et une bonne partie des messages d'erreur du
   projet, passent par error_log(). On le détourne vers un fichier à
   nous : d'abord pour que la sortie des tests reste lisible, ensuite
   parce que plusieurs tests RELISENT ce journal — c'est la seule façon
   de vérifier ce qui a été consigné, et notamment qu'un retour à la
   ligne glissé dans une donnée ne peut pas fabriquer une fausse entrée.
   --------------------------------------------------------------------- */
define('TEST_JOURNAL', sys_get_temp_dir() . '/livre-test-' . getmypid() . '.log');
@unlink(TEST_JOURNAL);
ini_set('error_log', TEST_JOURNAL);

/** Contenu actuel du journal des tests (chaîne vide s'il est resté muet). */
function journal_test(): string
{
    return is_file(TEST_JOURNAL) ? (string) file_get_contents(TEST_JOURNAL) : '';
}

/** Repart d'un journal vide, pour qu'un test ne lise pas les traces du précédent. */
function vider_journal_test(): void
{
    @file_put_contents(TEST_JOURNAL, '');
}

/* ---------------------------------------------------------------------
   3. Sessions jetables

   includes/fonctions.php appelle session_start() dès son inclusion. En
   ligne de commande ça fonctionne, mais les fichiers de session
   atterriraient au milieu de ceux du serveur de développement.
   --------------------------------------------------------------------- */
$dossier_sessions = sys_get_temp_dir() . '/livre-test-sessions';
if (!is_dir($dossier_sessions)) {
    @mkdir($dossier_sessions, 0777, true);
}
ini_set('session.save_path', $dossier_sessions);

/* ---------------------------------------------------------------------
   4. Configuration de test

   charger_env() n'écrase JAMAIS une variable d'environnement déjà
   définie (« if (getenv($cle) === false) », voir includes/config.php).
   Ce qu'on pose ici l'emporte donc sur le .env du projet — et un fichier
   de cas qui aurait fait son propre putenv() avant d'inclure cette
   amorce l'emporte à son tour, grâce au même test.
   --------------------------------------------------------------------- */
$configuration_de_test = [
    // Garde-fou : la base du site reste hors de portée (voir l'en-tête).
    'DB_NAME'             => 'information_schema',

    // Garde-fou : aucun e-mail ne peut partir.
    'SMTP_HOST'           => '',
    'SMTP_USER'           => '',
    'SMTP_PASSWORD'       => '',
    'SMTP_TIMEOUT'        => '1',

    // Valeurs fixes, pour que les tests ne dépendent pas de votre .env.
    'APP_URL'             => 'https://exemple.test/bibliotheque',
    'ADMIN_EMAIL'         => 'admin@exemple.test',
    'UPLOAD_SECRET'       => 'sel-de-test',
    'ASSETS_VERSION'      => '',
    'IP_ENTETE'           => '',
    'CRON_TOKEN'          => '',
];
foreach ($configuration_de_test as $cle => $valeur) {
    if (getenv($cle) === false) {
        putenv($cle . '=' . $valeur);
    }
}

/* Une adresse publique et stable par défaut (203.0.113.0/24 est le bloc
   de documentation TEST-NET-3 : PHP ne le classe ni en privé ni en
   réservé, ip_interne() répond donc « false » dessus). */
if (!isset($_SERVER['REMOTE_ADDR'])) {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
}

/* ---------------------------------------------------------------------
   5. Chargement du projet

   includes/carte.php tire toute la chaîne : fonctions → config, images,
   mailer. Si la connexion à la base échoue, config.php appelle
   erreur_fatale(), qui affiche une page HTML et coupe le processus. Sans
   le filet ci-dessous, on ne verrait qu'un pavé de HTML sans rapport
   avec les tests. On explique donc la vraie cause.
   --------------------------------------------------------------------- */
$projet_charge = false;
ob_start();

register_shutdown_function(static function () use (&$projet_charge): void {
    if ($projet_charge) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();   // on jette la page d'erreur HTML de erreur_fatale()
    }
    fwrite(STDERR,
        "\n  Le projet n'a pas pu être chargé.\n\n"
        . "  Cause la plus fréquente : MySQL n'est pas démarré. includes/config.php\n"
        . "  ouvre une connexion PDO dès son inclusion — même pour des tests qui ne\n"
        . "  touchent pas à la base. Démarrez MySQL dans le panneau XAMPP, puis\n"
        . "  relancez.\n\n"
        /* La cause exacte est dans le journal : config.php y consigne le
           message d'origine de PDO avant d'afficher sa page neutre. */
        . '  Journal : ' . (trim(journal_test()) ?: '(vide)') . "\n"
    );
});

require_once CHEMIN_PROJET . '/includes/carte.php';

$projet_charge = true;
ob_end_clean();   // les en-têtes ne servent à rien en ligne de commande
