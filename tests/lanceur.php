<?php
/* =====================================================================
   Micro-lanceur de tests — aucune dépendance, dans l'esprit du projet
   (« pas de Composer, pas de framework »).

   Un fichier de cas ressemble à ça :

       <?php
       require __DIR__ . '/../lanceur.php';

       groupe('e() — échappement HTML');

       test('neutralise les chevrons', function () {
           egale('&lt;b&gt;', e('<b>'), 'les chevrons deviennent des entités');
       });

   Chaque assertion qui échoue interrompt SON test (et lui seul) : les
   assertions suivantes du même test porteraient sur un état déjà faux,
   leurs messages n'apprendraient rien. Les autres tests du fichier, eux,
   continuent — un fichier rend toujours le compte complet de ce qui
   marche et de ce qui ne marche pas.
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/amorce.php';      // charge le projet en état de test
require_once __DIR__ . '/processus.php';   // executer_php(), pour les fonctions qui font exit

/** Levée par une assertion qui échoue ; interceptée par test(). */
final class EchecAssertion extends Exception
{
}

/** Compteurs et mémoire du fichier en cours. */
final class Rapport
{
    public static int $total    = 0;
    public static int $echecs   = 0;
    public static string $groupe = '';

    /** Le fichier s'est-il arrêté avant la fin ? Voir le compte rendu, plus bas. */
    public static bool $interrompu = false;

    /** Posé par executer-cas.php quand le fichier est allé jusqu'au bout. */
    public static bool $complet = false;
}

/* ---------------------------------------------------------------------
   Déclaration des tests
   --------------------------------------------------------------------- */

/** Ouvre une section. Purement cosmétique, mais c'est ce qui rend la
    sortie lisible quand un fichier couvre plusieurs fonctions. */
function groupe(string $titre): void
{
    Rapport::$groupe = $titre;
    echo "\n  ", $titre, "\n";
}

/**
 * Déclare et exécute un test. Tout ce qui s'en échappe est capturé :
 * une assertion ratée, mais aussi une exception ou une erreur PHP
 * imprévue — qui compte alors, elle aussi, comme un échec.
 */
function test(string $nom, callable $corps): void
{
    Rapport::$total++;
    try {
        $corps();
        echo '    [ok]     ', $nom, "\n";
    } catch (EchecAssertion $e) {
        Rapport::$echecs++;
        echo '    [ECHEC]  ', $nom, "\n", $e->getMessage(), "\n";
    } catch (Throwable $e) {
        Rapport::$echecs++;
        echo '    [ERREUR] ', $nom, "\n",
             '      ', get_class($e), ' : ', $e->getMessage(), "\n",
             '      ', basename($e->getFile()), ':', $e->getLine(), "\n";
    }
}

/* ---------------------------------------------------------------------
   Assertions

   Toutes passent par echouer(), qui retrouve dans la pile d'appels le
   NUMÉRO DE LIGNE de l'assertion elle-même : sans lui, un test qui en
   enchaîne dix ne dit pas laquelle a cédé.
   --------------------------------------------------------------------- */

/** Rend une valeur lisible dans un message d'échec, sans noyer la console. */
function montrer($valeur): string
{
    if (is_string($valeur)) {
        $longueur = mb_strlen($valeur, 'UTF-8');
        $court = $longueur > 160 ? mb_substr($valeur, 0, 160, 'UTF-8') . '...' : $valeur;
        $court = str_replace(["\r", "\n", "\t"], ['\r', '\n', '\t'], $court);
        return '"' . $court . '"' . ($longueur > 160 ? " ({$longueur} caractères)" : '');
    }
    if (is_bool($valeur))   { return $valeur ? 'true' : 'false'; }
    if ($valeur === null)   { return 'null'; }
    if (is_array($valeur))  { return preg_replace('/\s+/', ' ', var_export($valeur, true)) ?? '[...]'; }
    if (is_object($valeur)) { return 'objet ' . get_class($valeur); }
    return var_export($valeur, true);
}

/**
 * Signale l'échec de l'assertion en cours et interrompt le test.
 * Ne jamais appeler directement depuis un fichier de cas : les
 * assertions ci-dessous s'en chargent, et c'est leur position dans la
 * pile qui permet de retrouver la bonne ligne.
 */
function echouer(string $description, string $detail = ''): void
{
    /* [0] = echouer, [1] = l'assertion appelante, dont 'file' et 'line'
       désignent l'endroit d'où ELLE a été appelée — c'est-à-dire la
       ligne du fichier de cas qu'on veut afficher. */
    $pile  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
    $cadre = $pile[1] ?? [];
    $ou    = isset($cadre['file'])
        ? basename((string) $cadre['file']) . ':' . ($cadre['line'] ?? '?')
        : '?';

    $message = '      -> ' . $description . "\n";
    if ($detail !== '') {
        $message .= $detail;
    }
    $message .= '      ' . $ou;

    throw new EchecAssertion($message);
}

/** La condition doit être vraie. */
function vrai($condition, string $description): void
{
    if ($condition !== true) {
        echouer($description, '      obtenu  : ' . montrer($condition) . "\n");
    }
}

/** La condition doit être fausse. */
function faux($condition, string $description): void
{
    if ($condition !== false) {
        echouer($description, '      obtenu  : ' . montrer($condition) . "\n");
    }
}

/** Égalité STRICTE (===) : 0, '0', false et null ne se confondent jamais. */
function egale($attendu, $obtenu, string $description): void
{
    if ($attendu !== $obtenu) {
        echouer(
            $description,
            '      attendu : ' . montrer($attendu) . "\n"
            . '      obtenu  : ' . montrer($obtenu) . "\n"
        );
    }
}

/** L'inverse : la valeur ne doit surtout pas être celle-là. */
function differe($interdit, $obtenu, string $description): void
{
    if ($interdit === $obtenu) {
        echouer($description, '      valeur interdite obtenue : ' . montrer($obtenu) . "\n");
    }
}

/** $botte doit contenir $aiguille. */
function contient(string $aiguille, string $botte, string $description): void
{
    if (!str_contains($botte, $aiguille)) {
        echouer(
            $description,
            '      cherché : ' . montrer($aiguille) . "\n"
            . '      dans    : ' . montrer($botte) . "\n"
        );
    }
}

/** $botte ne doit PAS contenir $aiguille — c'est la forme d'assertion des
    tests d'échappement : on vérifie qu'une charge utile a bien disparu. */
function sans(string $aiguille, string $botte, string $description): void
{
    if (str_contains($botte, $aiguille)) {
        echouer(
            $description,
            '      ne devait pas contenir : ' . montrer($aiguille) . "\n"
            . '      dans                   : ' . montrer($botte) . "\n"
        );
    }
}

/** $sujet doit correspondre à l'expression rationnelle $expression. */
function motif(string $expression, string $sujet, string $description): void
{
    if (!preg_match($expression, $sujet)) {
        echouer(
            $description,
            '      motif  : ' . $expression . "\n"
            . '      sujet  : ' . montrer($sujet) . "\n"
        );
    }
}

/** La valeur doit être exactement null. */
function estNul($valeur, string $description): void
{
    if ($valeur !== null) {
        echouer($description, '      obtenu  : ' . montrer($valeur) . "\n");
    }
}

/** Le tableau doit être vide — la forme que prend « aucune erreur » dans
    valider_mot_de_passe() et valider_profil(). */
function vide(array $valeur, string $description): void
{
    if ($valeur !== []) {
        echouer($description, '      obtenu  : ' . montrer($valeur) . "\n");
    }
}

/** $fn doit lever une exception (ou une erreur) de la classe $classe. */
function leve(string $classe, callable $fn, string $description): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $classe) {
            return;
        }
        echouer($description, '      levé    : ' . get_class($e) . ' au lieu de ' . $classe . "\n");
    }
    echouer($description, "      aucune exception levée\n");
}

/* ---------------------------------------------------------------------
   Ce qui arrive quand un fichier de tests s effondre

   includes/config.php installe un set_exception_handler() qui affiche
   une page d erreur neutre et coupe le processus. C est exactement ce
   qu il faut en production — et exactement ce qu il ne faut pas ici :
   le fichier de tests s arrêtait en rendant « 0 test, 0 échec », donc
   en passant pour un succès.

   On reprend donc la main dans les processus de test : une exception non
   rattrapée y est un échec, bruyant, et le compte rendu sort quand même.
   Les scripts de tests/outils/, eux, ne chargent pas ce fichier et
   gardent le gestionnaire de production — c est justement lui qu ils
   servent à tester.
   --------------------------------------------------------------------- */
set_exception_handler(static function (Throwable $e): void {
    Rapport::$echecs++;
    Rapport::$interrompu = true;
    echo "\n    [NON RATTRAPÉE] ", get_class($e), ' : ', $e->getMessage(), "\n",
         '      ', basename($e->getFile()), ':', $e->getLine(), "\n";
});

/* ---------------------------------------------------------------------
   Compte rendu de fin de fichier

   Émis par register_shutdown_function() et non à la dernière ligne du
   script : un test de erreur_fatale() ou de reponse_json() se termine
   par exit, et le compte doit quand même sortir.

   Quatre filets pour qu un fichier interrompu ne passe jamais pour un
   fichier sans problème :
     - une erreur fatale de PHP est relevée dans error_get_last() ;
     - une exception non rattrapée est relevée ci-dessus ;
     - un fichier qui n a exécuté AUCUN test s est forcément arrêté
       avant la fin — un fichier de cas sans test n existe pas ;
     - un fichier qui s arrête PROPREMENT mais avant sa dernière ligne
       (une fonction du projet qui appelle exit au milieu) est repéré par
       le drapeau que pose executer-cas.php. C est le cas le plus
       sournois : ni erreur, ni exception, juste des tests qui
       disparaissent sans que le total ne le dise.

   La ligne « ##RESULTAT## » est lue par lancer.php, qui compte aussi
   comme un échec le fichier qui n en produit pas du tout.
   --------------------------------------------------------------------- */
register_shutdown_function(static function (): void {
    $derniere = error_get_last();
    $fatales  = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;

    if ($derniere !== null && ($derniere['type'] & $fatales) !== 0) {
        Rapport::$echecs++;
        Rapport::$interrompu = true;
        echo "\n    [FATALE] ", $derniere['message'], "\n",
             '      ', basename((string) $derniere['file']), ':', $derniere['line'], "\n";
    }

    if (!Rapport::$interrompu && Rapport::$total === 0) {
        Rapport::$echecs++;
        Rapport::$interrompu = true;
        echo "\n    [VIDE] aucun test n a été exécuté : ce fichier n en déclare aucun,",
             " ou s est interrompu avant d en déclarer un seul.\n";
    }

    /* Le fichier s est arrêté proprement, mais pas à sa dernière ligne.
       Contrôle possible uniquement sous lancer.php : appelé à la main,
       un fichier de cas n a personne pour poser ce drapeau. */
    if (defined('TEST_SOUS_LANCEUR') && !Rapport::$interrompu && !Rapport::$complet) {
        Rapport::$echecs++;
        echo "\n    [TRONQUÉ] ce fichier s est arrêté avant sa dernière ligne :",
             " des tests n ont pas été exécutés.\n",
             "      Une fonction du projet appelée depuis un test a sans doute fait exit().\n";
    }

    if (Rapport::$echecs === 0 && Rapport::$interrompu) {
        // Ceinture et bretelles : un fichier interrompu ne rend jamais
        // « 0 échec », quel que soit le chemin qui y a mené.
        Rapport::$echecs++;
    }

    echo "\n##RESULTAT##", Rapport::$total, '|', Rapport::$echecs, "\n";
});
