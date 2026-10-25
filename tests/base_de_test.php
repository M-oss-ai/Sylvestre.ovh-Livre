<?php
/* =====================================================================
   Une vraie base, jetable, pour les tests qui exigent des tables.

   Les autres tests n'ont pas besoin de base : amorce.php pointe
   DB_NAME sur « information_schema ». Ceux de l'API, eux, vérifient de
   bout en bout des requêtes SQL (quota, cloisonnement entre comptes,
   jetons) ; ils vivent dans une base À PART, « Livre_test », créée ici à
   partir de livre.sql.

   Garde-fous :
     - le nom est écrit en dur et vérifié : ce fichier ne touchera jamais
       à la base « Livre » du site ;
     - il n'efface que les lignes qu'il a lui-même créées (comptes
       « api_test_… »), et les compteurs d'IP qu'il a fait monter.

   À inclure AVANT lanceur.php : config.php ouvre la connexion dès son
   inclusion, la base doit donc exister à ce moment-là.
   ===================================================================== */

declare(strict_types=1);

const BASE_DE_TEST = 'Livre_test';

/** Crée la base si besoin, y rejoue livre.sql, et la rend propre. */
function preparer_base_de_test(): void
{
    putenv('DB_NAME=' . BASE_DE_TEST);

    $hote = getenv('DB_HOST') ?: 'localhost';
    $pdo  = new PDO(
        'mysql:host=' . $hote . ';charset=utf8mb4',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . BASE_DE_TEST . '`
                DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . BASE_DE_TEST . '`');

    /* livre.sql est rejouable en entier (c'est sa règle), moins les deux
       instructions CREATE DATABASE / USE du début, qui pointent sur la
       base du site. On le rejoue à chaque fois : c'est aussi la preuve
       qu'il se rejoue sans erreur. */
    $sql = (string) file_get_contents(dirname(__DIR__) . '/livre.sql');
    $sql = preg_replace('/CREATE DATABASE.*?;\s*USE `Livre`;/s', '', $sql, 1) ?? '';
    $pdo->exec($sql);

    // Repart propre : la suppression d'un compte emporte ses séries et jetons (CASCADE).
    $pdo->exec("DELETE FROM utilisateur WHERE identifiant LIKE 'api_test_%'");
    $pdo->exec("DELETE FROM tentative_ip WHERE action = 'api_jeton'");
}

/** Une connexion à la base de test, pour poser et relire des lignes. */
function pdo_de_test(): PDO
{
    return new PDO(
        'mysql:host=' . (getenv('DB_HOST') ?: 'localhost') . ';dbname=' . BASE_DE_TEST . ';charset=utf8mb4',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

/**
 * Un compte de test et un jeton valide pour lui.
 * Retourne ['id' => compte, 'jeton' => en clair, 'jeton_id' => ligne].
 */
function compte_de_test(PDO $pdo, string $nom, string $forfait = 'standard'): array
{
    $pdo->prepare(
        "INSERT INTO utilisateur (identifiant, email, mot_de_passe, forfait)
         VALUES (?, ?, 'sans-importance', ?)"
    )->execute(['api_test_' . $nom, 'api_test_' . $nom . '@exemple.test', $forfait]);
    $id = (int) $pdo->lastInsertId();

    $jeton = api_jeton_creer($pdo, $id, 'test');
    return ['id' => $id, 'jeton' => $jeton['jeton'], 'jeton_id' => $jeton['id']];
}

/**
 * Lance le site en local (serveur intégré de PHP) avec cette configuration.
 * Retourne ['url' => …, 'arreter' => callable].
 *
 * Un VRAI serveur HTTP plutôt qu'un appel de fonction : ce qu'on teste
 * est ce qui sort sur le fil — codes, en-têtes, corps — y compris les
 * exit de reponse_json() et la lecture de php://input.
 */
function demarrer_serveur_api(array $environnement = []): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port   = (int) explode(':', (string) stream_socket_get_name($socket, false))[1];
    fclose($socket);

    $environnement += [
        'DB_NAME'           => BASE_DE_TEST,
        // Larges par défaut : un test qui veut un frein serré le demande.
        'API_REQUETES_MAX'  => '1000',
        'API_ECHECS_MAX'    => '1000',
    ];

    $processus = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/site', __DIR__ . '/serveur_api.php'],
        [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/livre-test-serveur-' . $port . '.log', 'w'], 2 => ['redirect', 1]],
        $tuyaux,
        dirname(__DIR__) . '/site',
        array_merge(getenv(), $environnement)
    );

    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($c) {
            fclose($c);
            break;
        }
        usleep(100000);
    }

    return [
        'url'     => 'http://127.0.0.1:' . $port,
        'arreter' => static function () use ($processus, $tuyaux, $port): void {
            foreach ($tuyaux as $t) {
                if (is_resource($t)) {
                    fclose($t);
                }
            }
            // Sous Windows, proc_terminate ne tue que le premier processus : taskkill pour être sûr.
            $etat = proc_get_status($processus);
            if (PHP_OS_FAMILY === 'Windows' && !empty($etat['pid'])) {
                @exec('taskkill /F /T /PID ' . (int) $etat['pid'] . ' 2>NUL');
            }
            @proc_terminate($processus);
            @proc_close($processus);
            @unlink(sys_get_temp_dir() . '/livre-test-serveur-' . $port . '.log');
        },
    ];
}

/**
 * Un appel HTTP. Retourne ['code' => int, 'entetes' => [minuscule => valeur],
 * 'json' => décodé|null, 'brut' => corps].
 */
function appel_api(string $serveur, string $methode, string $chemin, ?string $jeton = null,
                   ?array $corps = null, array $entetes = [], ?string $brut = null): array
{
    $lignes = $entetes;
    if ($jeton !== null) {
        $lignes[] = 'Authorization: Bearer ' . $jeton;
    }
    $contenu = $brut ?? ($corps !== null ? json_encode($corps, JSON_UNESCAPED_UNICODE) : '');
    if ($contenu !== '' && !preg_grep('/^content-type:/i', $lignes)) {
        $lignes[] = 'Content-Type: application/json';
    }

    $flux = stream_context_create(['http' => [
        'method'        => $methode,
        'header'        => implode("\r\n", $lignes),
        'content'       => $contenu,
        'ignore_errors' => true,
        'timeout'       => 15,
    ]]);
    $reponse = @file_get_contents($serveur . $chemin, false, $flux);

    $code = 0;
    $tete = [];
    foreach ($http_response_header ?? [] as $ligne) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $ligne, $m)) {
            $code = (int) $m[1];
        } elseif (str_contains($ligne, ':')) {
            [$k, $v] = explode(':', $ligne, 2);
            $tete[strtolower(trim($k))] = trim($v);
        }
    }
    return [
        'code'    => $code,
        'entetes' => $tete,
        'json'    => json_decode((string) $reponse, true),
        'brut'    => (string) $reponse,
    ];
}
