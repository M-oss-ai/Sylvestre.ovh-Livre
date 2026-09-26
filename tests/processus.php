<?php
/* =====================================================================
   Lancement d'un script PHP dans un PROCESSUS SÉPARÉ.

   Pourquoi c'est la pièce centrale de cette suite de tests, et pas un
   détail technique : une grande partie de ce qu'il y a à vérifier ici
   dépend de choses qu'on ne peut plus changer une fois le processus
   démarré.

     - Les réglages du .env deviennent des CONSTANTES (MDP_MIN,
       IP_ENTETE, ASSETS_VERSION…). Une constante ne se redéfinit pas :
       vérifier qu'un MDP_MIN de 3 est bien relevé à 8 exige donc un
       processus dont l'environnement porte « MDP_MIN=3 » dès le départ.
     - ip_client() garde son résultat dans un « static » pour ne pas le
       recalculer : le premier appel fige la valeur pour toute la durée
       du processus.
     - erreur_fatale() et reponse_json() se terminent par exit : rien ne
       s'exécute après elles.

   Un fichier de cas = un processus = un scénario de configuration. C'est
   aussi ce qui garantit qu'un test ne peut pas en polluer un autre par
   une session, une variable globale ou un $_SERVER oublié.
   ===================================================================== */

declare(strict_types=1);

/**
 * Exécute $script (avec ses éventuels arguments) et retourne
 * ['sortie' => texte, 'code' => code de retour].
 *
 * $environnement ajoute des variables d'environnement au processus fils :
 * c'est par là qu'on lui impose une configuration (voir plus haut).
 *
 * proc_open() reçoit un TABLEAU et non une chaîne : sous Windows, la
 * citation des arguments par le shell est un nid à surprises (espaces
 * dans les chemins, accents, guillemets). La forme tableau court-circuite
 * complètement le shell.
 */
function executer_php(string $script, array $arguments = [], array $environnement = []): array
{
    $commande = array_merge([PHP_BINARY, $script], $arguments);

    $descripteurs = [
        1 => ['pipe', 'w'],   // stdout
        2 => ['pipe', 'w'],   // stderr, fusionné à la sortie : un avertissement
                              // PHP ou une erreur fatale doit se voir, pas
                              // disparaître dans un tuyau que personne ne lit.
    ];

    /* getenv() sans argument rend l'environnement courant : sans lui, le
       processus fils perdrait PATH, TEMP et SystemRoot — et php.exe ne
       démarrerait pas sous Windows. */
    $env = array_merge(getenv(), $environnement);

    $processus = proc_open($commande, $descripteurs, $tuyaux, null, $env);
    if (!is_resource($processus)) {
        return ['sortie' => "Impossible de lancer : {$script}\n", 'code' => 255];
    }

    $sortie = (string) stream_get_contents($tuyaux[1]);
    $erreur = (string) stream_get_contents($tuyaux[2]);
    fclose($tuyaux[1]);
    fclose($tuyaux[2]);
    $code = proc_close($processus);

    return ['sortie' => $sortie . $erreur, 'code' => $code];
}

/* ---------------------------------------------------------------------
   Les tests JavaScript : un vrai navigateur, sans fenêtre

   Le projet n'a pas Node, et n'en veut pas plus que de Composer. Le
   JavaScript se teste donc là où il tourne : dans un navigateur. Edge
   est présent sur tout Windows, Chrome ou Chromium presque partout
   ailleurs ; en mode « headless », il ouvre tests/js/banc.html sans
   fenêtre et rend la page une fois ses scripts exécutés.
   --------------------------------------------------------------------- */

/**
 * Le navigateur qui fera tourner les tests JavaScript : celui que désigne
 * la variable d'environnement NAVIGATEUR, sinon le premier Edge, Chrome
 * ou Chromium trouvé à son emplacement habituel. null si aucun.
 */
function navigateur_de_test(): ?string
{
    $impose = getenv('NAVIGATEUR');
    if (is_string($impose) && $impose !== '') {
        return is_file($impose) ? $impose : null;
    }

    $sous = static fn (string $variable, string $chemin): string =>
        (string) getenv($variable) !== '' ? getenv($variable) . $chemin : '';
    $candidats = [
        $sous('ProgramFiles(x86)', '/Microsoft/Edge/Application/msedge.exe'),
        $sous('ProgramFiles', '/Microsoft/Edge/Application/msedge.exe'),
        $sous('ProgramFiles', '/Google/Chrome/Application/chrome.exe'),
        $sous('ProgramFiles(x86)', '/Google/Chrome/Application/chrome.exe'),
        $sous('LOCALAPPDATA', '/Google/Chrome/Application/chrome.exe'),
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/usr/bin/google-chrome',
        '/usr/bin/microsoft-edge',
    ];
    foreach ($candidats as $chemin) {
        if ($chemin !== '' && is_file($chemin)) {
            return $chemin;
        }
    }
    return null;
}

/** L'adresse file:// d'un fichier local, chaque segment encodé. */
function url_fichier(string $chemin): string
{
    $segments = explode('/', ltrim(str_replace('\\', '/', $chemin), '/'));
    $segments = array_map('rawurlencode', $segments);
    // Le « C: » d'un lecteur Windows garde ses deux-points.
    $segments[0] = str_replace('%3A', ':', $segments[0]);
    return 'file:///' . implode('/', $segments);
}

/**
 * Ouvre $page dans le navigateur sans fenêtre et rend le DOM obtenu une
 * fois ses scripts exécutés : ['sortie' => …, 'code' => …].
 *
 * Un profil jetable, pour ne jamais toucher à celui de l'utilisateur (ni
 * entrer en conflit avec un navigateur ouvert), et une limite de temps :
 * une boucle sans fin dans un test ne doit pas bloquer toute la suite.
 * La sortie passe par des fichiers et non des tuyaux, que Windows ne
 * sait pas lire sans bloquer.
 */
function executer_navigateur(string $navigateur, string $page, int $delai = 90): array
{
    $base    = sys_get_temp_dir() . '/livre-test-navigateur-' . getmypid();
    $sortie  = $base . '.html';
    $erreurs = $base . '.log';

    $commande = [
        $navigateur, '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
        '--disable-extensions', '--user-data-dir=' . $base . '-profil',
        '--dump-dom', url_fichier((string) realpath($page)),
    ];
    $descripteurs = [1 => ['file', $sortie, 'w'], 2 => ['file', $erreurs, 'w']];

    $processus = proc_open($commande, $descripteurs, $tuyaux, null, getenv());
    if (!is_resource($processus)) {
        return ['sortie' => "Impossible de lancer : {$navigateur}\n", 'code' => 255];
    }

    $fin = microtime(true) + $delai;
    while (($etat = proc_get_status($processus))['running'] && microtime(true) < $fin) {
        usleep(100000);
    }
    $depasse = $etat['running'];
    if ($depasse) {
        proc_terminate($processus);
    }
    $code = proc_close($processus);
    // proc_close() rend -1 quand proc_get_status() a déjà relevé la fin.
    if ($code === -1 && !$depasse) {
        $code = (int) $etat['exitcode'];
    }

    $resultat = [
        'sortie' => (string) @file_get_contents($sortie),
        'erreurs' => (string) @file_get_contents($erreurs),
        'code'   => $depasse ? 124 : $code,
    ];
    @unlink($sortie);
    @unlink($erreurs);
    supprimer_dossier($base . '-profil');
    return $resultat;
}

/** Efface un dossier et son contenu, sans jamais s'arrêter sur un échec. */
function supprimer_dossier(string $dossier): void
{
    if (!is_dir($dossier)) {
        return;
    }
    $elements = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($elements as $element) {
        $element->isDir() ? @rmdir($element->getPathname()) : @unlink($element->getPathname());
    }
    @rmdir($dossier);
}
