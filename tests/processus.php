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
