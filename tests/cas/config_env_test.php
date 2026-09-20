<?php
/* =====================================================================
   charger_env() et env() — la lecture du .env.

   C'est le tout premier maillon : une valeur mal lue ici se propage en
   silence dans toutes les constantes du site.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

/** Écrit un .env temporaire et rend son chemin. */
function env_temporaire(string $contenu): string
{
    $chemin = sys_get_temp_dir() . '/livre-env-' . bin2hex(random_bytes(6)) . '.env';
    file_put_contents($chemin, $contenu);
    return $chemin;
}

groupe('charger_env() — analyse du fichier');

test('un fichier absent est signalé, pas ignoré en silence', function () {
    faux(
        charger_env(sys_get_temp_dir() . '/ce-fichier-nexiste-pas-' . bin2hex(random_bytes(6))),
        'charger_env() rend false quand le fichier est introuvable'
    );
});

test('une paire simple est chargée', function () {
    $f = env_temporaire("TEST_SIMPLE=bonjour\n");
    vrai(charger_env($f), 'le chargement réussit');
    egale('bonjour', getenv('TEST_SIMPLE'), 'la valeur est lue');
    egale('bonjour', $_ENV['TEST_SIMPLE'] ?? null, '$_ENV est renseigné aussi');
    unlink($f);
});

test('les commentaires et les lignes vides sont ignorés', function () {
    $f = env_temporaire("# un commentaire\n\n#TEST_COMMENTE=piege\nTEST_APRES=ok\n");
    charger_env($f);
    faux(getenv('TEST_COMMENTE'), 'une ligne commentée ne définit rien');
    egale('ok', getenv('TEST_APRES'), 'la ligne suivante est bien lue');
    unlink($f);
});

test('une ligne sans « = » est ignorée sans tout casser', function () {
    $f = env_temporaire("ceci n est pas une affectation\nTEST_MALGRE_TOUT=ok\n");
    vrai(charger_env($f), 'le chargement ne s interrompt pas');
    egale('ok', getenv('TEST_MALGRE_TOUT'), 'les lignes suivantes restent lues');
    unlink($f);
});

test('les guillemets encadrants sont retirés', function () {
    $f = env_temporaire("TEST_GUILLEMETS=\"avec espaces\"\nTEST_APOSTROPHES='autre valeur'\n");
    charger_env($f);
    egale('avec espaces', getenv('TEST_GUILLEMETS'), 'les guillemets doubles disparaissent');
    egale('autre valeur', getenv('TEST_APOSTROPHES'), 'les guillemets simples aussi');
    unlink($f);
});

test('un guillemet interne est conservé', function () {
    $f = env_temporaire("TEST_INTERNE=mot\"de\"passe\n");
    charger_env($f);
    egale('mot"de"passe', getenv('TEST_INTERNE'), 'seuls les guillemets ENCADRANTS sont retirés');
    unlink($f);
});

test('une valeur qui contient « = » n est pas tronquée', function () {
    // Un mot de passe ou une clé base64 finit très souvent par « = ».
    $f = env_temporaire("TEST_BASE64=abc==\nTEST_DSN=host=x;port=1\n");
    charger_env($f);
    egale('abc==', getenv('TEST_BASE64'), 'explode() est bien limité à 2 morceaux');
    egale('host=x;port=1', getenv('TEST_DSN'), 'tout ce qui suit le premier « = » est la valeur');
    unlink($f);
});

test('les espaces autour de la clé et de la valeur sont rognés', function () {
    $f = env_temporaire("  TEST_ESPACES   =   valeur   \n");
    charger_env($f);
    egale('valeur', getenv('TEST_ESPACES'), 'la valeur est trim()');
    unlink($f);
});

test('une variable déjà définie n est JAMAIS écrasée', function () {
    /* C'est la propriété sur laquelle repose toute cette suite de tests :
       tests/amorce.php impose sa configuration par putenv() AVANT de
       charger le projet, et compte sur le fait que le .env ne la
       recouvrira pas. C'est aussi ce qui permet, en production, à une
       variable d'environnement du serveur de primer sur le fichier. */
    putenv('TEST_PRIORITE=venu_de_l_environnement');
    $f = env_temporaire("TEST_PRIORITE=venu_du_fichier\n");
    charger_env($f);
    egale(
        'venu_de_l_environnement',
        getenv('TEST_PRIORITE'),
        'la variable d environnement l emporte sur le fichier'
    );
    unlink($f);
});

test('une clé vide ne définit rien', function () {
    $f = env_temporaire("=valeur_orpheline\n");
    vrai(charger_env($f), 'le chargement réussit quand même');
    unlink($f);
});

groupe('env() — valeur de repli');

test('une clé absente rend le repli', function () {
    egale('repli', env('TEST_TOTALEMENT_ABSENT', 'repli'), 'le défaut est utilisé');
    egale('', env('TEST_TOTALEMENT_ABSENT'), 'sans défaut, une chaîne vide');
});

test('une clé PRÉSENTE MAIS VIDE compte comme absente', function () {
    /* Dans un .env on écrit couramment « CLE= » pour dire « je n'ai rien
       à mettre ». C'est précisément là que le repli doit jouer, sinon la
       moitié des réglages par défaut du projet ne s'appliquent jamais. */
    putenv('TEST_VIDE=');
    egale('repli', env('TEST_VIDE', 'repli'), 'une valeur vide déclenche le repli');
});

test('une clé renseignée rend sa valeur', function () {
    putenv('TEST_RENSEIGNE=valeur');
    egale('valeur', env('TEST_RENSEIGNE', 'repli'), 'le défaut est ignoré');
});

test('« 0 » est une valeur, pas un vide', function () {
    // Un piège classique : empty('0') vaut true en PHP. Si env() utilisait
    // empty(), régler un quota à 0 rendrait silencieusement le défaut.
    putenv('TEST_ZERO=0');
    egale('0', env('TEST_ZERO', 'repli'), 'la chaîne « 0 » est conservée');
});
