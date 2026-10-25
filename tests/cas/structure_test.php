<?php
/* =====================================================================
   La structure du dépôt : site/ est ce qui MONTE sur le serveur.

   Le projet s'envoie par copie de fichiers, et la règle est simple :
   tout ce qui est dans site/ (sauf uploads/, qui reste en place) part
   en ligne, tel quel. Le reste — livre.sql, README.md, CLAUDE.md, tests/,
   .env — vit à la racine du dépôt, hors de ce dossier, et ne peut donc
   jamais être servi, ni écraser le .env du serveur.

   D'où le .htaccess de site/ allégé : il ne bloque plus tests/, *.md ni
   *.sql, parce qu'ils ne peuvent plus s'y trouver. Cette garantie ne
   tient que si quelque chose la vérifie : c'est ce fichier. Il lit les
   SOURCES, comme tactile_test.php.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

/**
 * Tout ce que contient site/, chemins relatifs avec « / », fichiers
 * cachés compris. uploads/ n'est pas parcouru : ses images sont celles des
 * utilisateurs, seul son .htaccess appartient au dépôt.
 */
function entrees_du_site(string $relatif = ''): array
{
    $sortie = [];
    $dossier = CHEMIN_SITE . ($relatif === '' ? '' : '/' . $relatif);
    foreach (scandir($dossier) ?: [] as $nom) {
        if ($nom === '.' || $nom === '..') {
            continue;
        }
        $chemin = ($relatif === '' ? '' : $relatif . '/') . $nom;
        $sortie[] = $chemin;
        if (is_dir(CHEMIN_SITE . '/' . $chemin) && $chemin !== 'uploads') {
            $sortie = array_merge($sortie, entrees_du_site($chemin));
        }
    }
    return $sortie;
}

/** Les fichiers .php de site/ (hors uploads/), avec leur source. */
function sources_php_du_site(): array
{
    $sources = [];
    foreach (entrees_du_site() as $chemin) {
        if (str_ends_with($chemin, '.php') && is_file(CHEMIN_SITE . '/' . $chemin)) {
            $sources[$chemin] = (string) file_get_contents(CHEMIN_SITE . '/' . $chemin);
        }
    }
    return $sources;
}

function lire_fichier(string $chemin): string
{
    return (string) file_get_contents($chemin);
}

groupe('site/ — ce qui monte sur le serveur');

test('les pages, includes/, js/, css/ et les .htaccess y sont', function () {
    foreach ([
        'index.php', 'api.php', 'connexion.php', 'parametres.php', 'purger.php',
        'includes/config.php', 'includes/fonctions.php', 'includes/.htaccess',
        'css/style.css', 'js/app.js', 'js/commun.js',
        'uploads/.htaccess', '.htaccess', '.user.ini',
    ] as $attendu) {
        vrai(is_file(CHEMIN_SITE . '/' . $attendu), 'site/' . $attendu . ' existe');
    }
});

test('rien n y est qui ne doive pas être en ligne', function () {
    $intrus = [];
    foreach (entrees_du_site() as $chemin) {
        $nom = basename($chemin);
        $dossier = is_dir(CHEMIN_SITE . '/' . $chemin);
        $interdit =
            $nom === 'tests'                                           // les tests
            || preg_match('/^\.env(\.|$)/', $nom)                      // les secrets
            || preg_match('/\.(md|sql|log|bak|orig|swp)$/i', $nom)     // documentation, schéma, restes
            || in_array($nom, ['.gitignore', '.DS_Store', 'Thumbs.db'], true)
            || ($dossier && $nom[0] === '.');                          // .git, .claude, .vscode…
        if ($interdit) {
            $intrus[] = $chemin;
        }
    }
    /* Le .htaccess de site/ ne les bloque plus : seule leur absence les
       empêche d'être lus. */
    egale([], $intrus, 'aucun fichier de développement dans site/');
});

test('ce qui n est pas en ligne est à la racine du dépôt', function () {
    foreach (['livre.sql', 'README.md', 'CLAUDE.md', '.env.example', 'tests/lancer.php'] as $fichier) {
        vrai(is_file(CHEMIN_PROJET . '/' . $fichier), $fichier . ' est à la racine du dépôt');
        faux(file_exists(CHEMIN_SITE . '/' . $fichier), $fichier . ' n est pas dans site/');
    }
});

groupe('Les liens — rien ne pointe dans le vide');

test('chaque require de site/ trouve son fichier', function () {
    $vus = 0;
    foreach (sources_php_du_site() as $chemin => $source) {
        preg_match_all("~require(?:_once)?\s+__DIR__\s*\.\s*'([^']+)'~", $source, $m);
        foreach ($m[1] as $cible) {
            $vus++;
            $absolu = dirname(CHEMIN_SITE . '/' . $chemin) . $cible;
            vrai(is_file($absolu), 'site/' . $chemin . ' → ' . $cible . ' existe');
        }
    }
    /* Garde-fou du test lui-même : un motif qui ne trouve rien ferait
       passer la boucle à vide. */
    vrai($vus >= 30, $vus . ' require trouvés, au moins 30 attendus');
});

test('chaque feuille et chaque script chargé par actif() existe', function () {
    $vus = 0;
    foreach (sources_php_du_site() as $chemin => $source) {
        preg_match_all("~actif\('([^']+)'\)~", $source, $m);
        foreach ($m[1] as $cible) {
            $vus++;
            vrai(is_file(CHEMIN_SITE . '/' . $cible), 'site/' . $chemin . ' → ' . $cible . ' existe');
        }
    }
    vrai($vus >= 20, $vus . ' ressources trouvées, au moins 20 attendues');
});

test('config.php prend site/ pour racine : uploads/ et le .env se cherchent depuis là', function () {
    egale(realpath(CHEMIN_SITE), realpath(CHEMIN_RACINE), 'CHEMIN_RACINE est site/');
    vrai(is_dir(CHEMIN_RACINE . '/uploads'), 'uploads/ est dans la racine du site');
    $config = lire_fichier(CHEMIN_SITE . '/includes/config.php');
    contient("charger_env(dirname(CHEMIN_RACINE) . '/.env')", $config,
        'le .env est d abord cherché AU-DESSUS du site (la racine du dépôt, en local)');
    contient("env('APP_URL', 'http://localhost/Livre/site')", $config,
        'l adresse de développement par défaut est celle de site/');
});

groupe('.gitignore — les images envoyées restent hors du dépôt');

test('uploads/ est ignoré dans site/, sauf son .htaccess', function () {
    $lignes = array_map('trim', explode("\n", lire_fichier(CHEMIN_PROJET . '/.gitignore')));
    vrai(in_array('/site/uploads/*', $lignes, true), '/site/uploads/* est ignoré');
    vrai(in_array('!/site/uploads/.htaccess', $lignes, true), 'son .htaccess est gardé');
    vrai(in_array('.env', $lignes, true), '.env est ignoré');
    faux(in_array('/uploads/*', $lignes, true), 'plus de règle sur l ancien /uploads');
});

groupe('Les .htaccess — ce qu il reste à protéger');

test('site/.htaccess bloque les fichiers cachés et includes/', function () {
    $htaccess = lire_fichier(CHEMIN_SITE . '/.htaccess');
    motif('~<FilesMatch "\^\\\\\.">\s*Require all denied\s*</FilesMatch>~', $htaccess, 'tout fichier « point » est refusé');
    contient('RedirectMatch 404 (?i)/includes(/|$)', $htaccess, 'includes/ est introuvable');
    contient('Options -Indexes -MultiViews', $htaccess, 'ni listing de dossier ni négociation de contenu');
});

test('includes/.htaccess refuse tout', function () {
    $htaccess = lire_fichier(CHEMIN_SITE . '/includes/.htaccess');
    contient('Require all denied', $htaccess, 'aucun accès web à includes/');
});

test('uploads/.htaccess interdit l exécution de code', function () {
    $htaccess = lire_fichier(CHEMIN_SITE . '/uploads/.htaccess');
    contient('RemoveHandler .php', $htaccess, 'plus de gestionnaire PHP');
    motif('~FilesMatch "[^"]*php[^"]*">\s*Require all denied~', $htaccess, 'les .php y sont refusés');
    sans('php_flag', preg_replace('~^\s*#.*$~m', '', $htaccess), 'pas de php_flag : il fait un 500 hors mod_php');
});

test('le .htaccess de la racine du dépôt (développement local) ferme tout sauf site/', function () {
    $racine = CHEMIN_PROJET . '/.htaccess';
    vrai(is_file($racine), 'il existe à la racine du dépôt');
    $htaccess = lire_fichier($racine);
    contient('RewriteRule ^(?!site(/|$)) - [R=404,L]', $htaccess, 'tout ce qui n est pas dans site/ est introuvable');
    contient('RewriteRule ^$ %{REQUEST_URI}site/ [R=302,L]', $htaccess, 'l adresse nue mène à site/, sans chemin disque');
    faux(is_file(CHEMIN_PROJET . '/tests/.htaccess'), 'tests/ n a plus de .htaccess propre : celui de la racine suffit');
});
