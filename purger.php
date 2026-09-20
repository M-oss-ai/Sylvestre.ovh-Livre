<?php
/* =====================================================================
   Entretien périodique de la base. À lancer une fois par jour par le
   cron de l'hébergeur (voir README).

   Sans ça, trois tables grossissent indéfiniment : les compteurs de
   tentatives, les jetons expirés et les jetons d'appareil remplacés.
   Sur un forfait mutualisé d'entrée de gamme, la base est plafonnée
   (souvent 200 Mo) — ce n'est pas théorique.

   Deux façons de l'appeler :
     - en ligne de commande :  php purger.php
     - en HTTP :               en-tête « X-Cron-Token: <CRON_TOKEN> »

   Le jeton voyage dans un EN-TÊTE, plus dans l'URL : une URL atterrit
   dans les journaux d'accès de l'hébergeur (consultables depuis le
   manager), dans le Referer et dans le cache des proxys. Sans jeton
   configuré, l'appel HTTP est refusé.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/mailer.php';

/* Hors requête web ? Tous les hébergeurs n'invoquent pas leurs tâches
   planifiées en « cli » (certains passent par CGI), d'où le test sur
   l'absence totale de contexte HTTP. La condition est volontairement
   restrictive : au moindre doute on exige le jeton, un fail-safe se
   conçoit fermé. */
$en_ligne_de_commande = PHP_SAPI === 'cli'
    || (!isset($_SERVER['REQUEST_METHOD'])
        && !isset($_SERVER['REMOTE_ADDR'])
        && !isset($_SERVER['HTTP_HOST']));

if (!$en_ligne_de_commande) {
    $fourni = (string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
    /* hash_equals : comparaison à temps constant, pour ne pas laisser
       deviner le jeton caractère par caractère. Un CRON_TOKEN vide
       refuse tout. La réponse est un 404 et non un 403 : elle ne
       confirme pas l'existence du script. */
    if (CRON_TOKEN === '' || !hash_equals(CRON_TOKEN, $fourni)) {
        http_response_code(404);
        exit('Not found');
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    /* « ?ip » — que voit RÉELLEMENT PHP derrière le répartiteur de
       l'hébergeur ? C'est la seule chose qu'on ne peut pas vérifier
       depuis un poste de développement, et s'y tromper coûte cher : si
       REMOTE_ADDR est l'adresse du répartiteur, tous les visiteurs
       partagent un unique compteur de tentatives.

       Placé ici, derrière le jeton du cron, plutôt que dans un fichier
       de test à déposer à la racine — un fichier qu'on oublie de
       supprimer, et qui expose alors l'adresse des visiteurs. */
    if (isset($_GET['ip'])) {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '(absent)');
        $xff    = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '(absent)');
        $entete = IP_ENTETE === '' ? '(vide)' : IP_ENTETE;
        $sauts  = IP_PROXY_SAUTS;
        $vu     = ip_client();

        if (ip_interne($remote)) {
            $verdict = IP_ENTETE === ''
                ? "A CORRIGER : PHP voit une adresse INTERNE et IP_ENTETE est vide.\n"
                . "Tous vos visiteurs partagent donc un seul compteur de tentatives :\n"
                . "cinq mauvais mots de passe bloquent la connexion pour tout le site.\n"
                . "=> mettez IP_ENTETE=X-Forwarded-For dans le .env, puis rechargez\n"
                . "   cette page pour confirmer."
                : "A VERIFIER : adresse interne, et IP_ENTETE est renseigne.\n"
                . "Controlez que « ip_client » ci-dessus est bien VOTRE adresse\n"
                . "publique. Si ce n'est pas elle, ajustez IP_PROXY_SAUTS.";
        } else {
            $verdict = IP_ENTETE === ''
                ? "CORRECT : PHP voit deja une adresse publique, laissez IP_ENTETE vide.\n"
                . "Controlez simplement que « ip_client » ci-dessus est bien la votre."
                : "A CORRIGER : PHP voit deja une adresse publique, et pourtant\n"
                . "IP_ENTETE est renseigne. C'est dangereux si le site reste joignable\n"
                . "sans passer par le proxy : n'importe qui enverrait l'en-tete de son\n"
                . "choix pour echapper au limiteur.\n"
                . "=> videz IP_ENTETE, sauf certitude contraire.";
        }

        echo <<<TXT
            REMOTE_ADDR     : {$remote}
            X-Forwarded-For : {$xff}
            IP_ENTETE       : {$entete}
            IP_PROXY_SAUTS  : {$sauts}
            ip_client       : {$vu}

            {$verdict}

            TXT;
        exit;
    }
}

$resume = [];

/* Jetons de confirmation / réinitialisation expirés. */
$req = $pdo->prepare('DELETE FROM jeton_action WHERE expire < NOW()');
$req->execute();
$resume[] = $req->rowCount() . ' jeton(s) d\'action expiré(s)';

/* Jetons d'appareil expirés, et ceux dont la rotation est consommée
   depuis plus d'une heure (le sursis anti-concurrence est de 60 s). */
$req = $pdo->prepare(
    'DELETE FROM session_persistante
      WHERE expire < NOW()
         OR (remplace_le IS NOT NULL AND remplace_le < NOW() - INTERVAL 1 HOUR)'
);
$req->execute();
$resume[] = $req->rowCount() . ' jeton(s) d\'appareil obsolète(s)';

/* Compteurs de tentatives : on garde ceux encore actifs (blocage en
   cours) et ceux touchés dans les 30 derniers jours — le compteur de
   récidives (« blocages ») doit survivre assez longtemps pour qu'une IP
   insistante ne reparte pas de zéro chaque nuit. */
$req = $pdo->prepare(
    'DELETE FROM tentative_ip
      WHERE maj_le < NOW() - INTERVAL 30 DAY
        AND (bloque_jusqu IS NULL OR bloque_jusqu < NOW())'
);
$req->execute();
$resume[] = $req->rowCount() . ' compteur(s) de tentatives';

/* File de rattrapage : on rejoue ce qui n'était pas parti. Normalement
   vide — elle ne se remplit que quand le serveur SMTP a refusé. */
[$mails_envoyes, $mails_abandonnes] = traiter_file_mail();
$resume[] = $mails_envoyes . ' e-mail(s) rattrapé(s), ' . $mails_abandonnes . ' abandonné(s)';

/* Images orphelines : plus aucune série ni profil ne les référence. Les
   suppressions nettoient au fil de l'eau, mais un plantage au mauvais
   moment peut en laisser — et le disque ne se vide pas tout seul. */
$references = [];
foreach ($pdo->query('SELECT DISTINCT couverture FROM serie WHERE couverture LIKE "uploads/%"') as $l) {
    $references[basename((string) $l['couverture'])] = true;
}
foreach ($pdo->query('SELECT DISTINCT photo FROM utilisateur WHERE photo LIKE "uploads/%"') as $l) {
    $references[basename((string) $l['photo'])] = true;
}

$supprimees = 0;
$dossier = CHEMIN_RACINE . '/uploads';
foreach (glob($dossier . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [] as $fichier) {
    $nom = basename($fichier);
    if (isset($references[$nom])) {
        continue;
    }
    // Marge de sécurité : un fichier tout juste écrit peut ne pas encore
    // avoir sa ligne en base (envoi en cours). On ne touche à rien de
    // récent.
    if (@filemtime($fichier) > time() - 86400) {
        continue;
    }
    if (@unlink($fichier)) {
        $supprimees++;
    }
}
$resume[] = $supprimees . ' image(s) orpheline(s)';

/* Temporaires d'écriture abandonnés (voir ecrire_image) : un renommage
   raté en laisse un derrière lui. Une heure de marge pour ne pas couper
   un envoi en cours. */
$temporaires = 0;
foreach (glob($dossier . '/.part-*.tmp') ?: [] as $fichier) {
    if (@filemtime($fichier) < time() - 3600 && @unlink($fichier)) {
        $temporaires++;
    }
}
$resume[] = $temporaires . ' temporaire(s)';

$message = 'Purge ' . date('Y-m-d H:i:s') . ' — ' . implode(', ', $resume);
error_log($message);

if ($en_ligne_de_commande) {
    echo $message, PHP_EOL;
} else {
    echo $message, "\n";
}
