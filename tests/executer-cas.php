<?php
/* =====================================================================
   Enveloppe d'exécution d'un fichier de cas.

   lancer.php passe par ici plutôt que d'appeler le fichier de cas
   directement, et ce détour sert une seule chose : savoir si le fichier
   est allé JUSQU'AU BOUT.

   Sans ça, un fichier qui s'interrompt en silence — une fonction du
   projet qui appelle exit() au milieu, par exemple reponse_json() ou
   exiger_connexion() — rendait le compte des tests qu'il avait eu le
   temps d'exécuter, et passait donc pour un fichier sain amputé de la
   moitié de ses tests. Les erreurs fatales et les exceptions non
   rattrapées, elles, sont déjà repérées par lanceur.php ; c'est
   l'arrêt PROPRE mais prématuré qui manquait.

   Le fichier de cas charge lui-même le lanceur : il faut surtout pas le
   faire ici à sa place, car plusieurs fichiers posent leur configuration
   par putenv() AVANT de le charger, et cette configuration doit être en
   place avant que config.php ne la fige en constantes.
   ===================================================================== */

declare(strict_types=1);

/* Lu par le gestionnaire de fin de script de lanceur.php : lancé à la
   main, un fichier de cas n'a personne pour poser le drapeau de fin, et
   il ne doit pas être accusé de s'être interrompu pour autant. */
define('TEST_SOUS_LANCEUR', true);

$cas = (string) ($argv[1] ?? '');
if ($cas === '' || !is_file($cas)) {
    fwrite(STDERR, "Fichier de cas introuvable : {$cas}\n");
    exit(2);
}

require $cas;

/* Atteint uniquement si le fichier s'est déroulé en entier. Rapport
   n'existe pas s'il s'est arrêté avant même de charger le lanceur — et
   dans ce cas l'absence de compte rendu suffit à alerter lancer.php. */
if (class_exists('Rapport', false)) {
    Rapport::$complet = true;
}
