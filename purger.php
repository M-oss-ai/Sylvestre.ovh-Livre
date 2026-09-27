<?php
/* =====================================================================
   Entretien périodique de la base, lancé par le cron de l'hébergeur
   toutes les CRON_HEURES heures (.env ; 24 conseillé, voir README). Le
   rapport détaillé, lui, ne part que toutes les RAPPORT_HEURES heures,
   et couvre le temps écoulé depuis le précédent.

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

/* Hors requête web ? Voir cron_en_ligne_de_commande() dans
   includes/fonctions.php : la règle y vit pour être testable. */
$en_ligne_de_commande = cron_en_ligne_de_commande(PHP_SAPI, $_SERVER);

if (!$en_ligne_de_commande) {
    $fourni = (string) ($_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
    /* hash_equals : comparaison à temps constant, pour ne pas laisser
       deviner le jeton caractère par caractère. Un CRON_TOKEN vide
       refuse tout. La réponse est un 404 et non un 403 : elle ne
       confirme pas l'existence du script. */
    if (CRON_TOKEN === '' || !hash_equals(CRON_TOKEN, $fourni)) {
        if (cron_refus_navigateur($_SERVER)) {
            http_response_code(404);
            exit('Not found');
        }

        /* Pas de méthode HTTP : ce n'est pas un visiteur, c'est la tâche
           planifiée elle-même, invoquée par un enrobage CGI qui a laissé
           traîner un HTTP_HOST. Le test ci-dessus l'a donc prise pour
           une requête web et lui a réclamé un jeton qu'un cron n'envoie
           pas.

           Sortir en 0 ici serait le pire des cas : l'hébergeur, réglé
           sur « envoyer uniquement en cas d'erreur », verrait une
           réussite. C'est ainsi qu'une purge peut ne jamais tourner
           pendant des semaines dans le silence le plus complet. */
        $err = defined('STDERR') ? STDERR : fopen('php://stderr', 'w');
        fwrite($err, "purger.php : appel REFUSÉ, jeton absent ou invalide." . PHP_EOL);
        fwrite($err, "La purge n'a PAS tourné." . PHP_EOL);
        fwrite($err, "=> lancez la tâche en ligne de commande (php purger.php)," . PHP_EOL);
        fwrite($err, "   ou faites-lui envoyer l'en-tête X-Cron-Token." . PHP_EOL);
        error_log("purger.php: appel refusé (jeton absent ou invalide) — la purge n'a pas tourné");
        exit(1);
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

$demarre = microtime(true);
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

/* Quotas de recherche de couverture : une tranche échue depuis un jour
   ne sert plus à rien. Rien à conserver au-delà — contrairement aux
   tentatives, ce compteur ne mémorise aucune récidive. */
$req = $pdo->prepare(
    'DELETE FROM recherche_couverture WHERE fenetre_fin < NOW() - INTERVAL 1 DAY'
);
$req->execute();
$resume[] = $req->rowCount() . ' quota(s) de recherche';

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

/* ---------------------------------------------------------------------
   Anomalies : ce qui merite qu'on vous previenne

   La tache planifiee d'OVH propose « envoyer un e-mail uniquement en cas
   d'erreur ». Encore faut-il que ce script sache en signaler une : tant
   qu'il se terminait toujours en succes, ce reglage ne produisait jamais
   le moindre message, et une file d'e-mails bloquee pouvait grossir des
   semaines sans que personne ne le sache.

   On separe donc le compte rendu (normal, silencieux) de l'anomalie
   (bruyante). Le silence devient alors une information : tout va bien.
   --------------------------------------------------------------------- */
$anomalies = [];

[$etat_lisible, $depuis_dernier] = rapport_etat($pdo);
if (!$etat_lisible) {
    $anomalies[] = 'table rapport_cron absente : rejouez livre.sql'
                 . ' (en attendant, le rapport part à chaque passage)';
}

if ($mails_abandonnes > 0) {
    $anomalies[] = $mails_abandonnes . ' e-mail(s) définitivement perdu(s) après '
                 . MAIL_FILE_MAX_ESSAIS . ' tentatives';
}

/* Une file encore pleine apres le passage signifie que le serveur SMTP a
   refuse : plus aucune inscription ni reinitialisation de mot de passe
   n'aboutit, et rien d'autre ne vous le dirait. */
$en_attente = (int) $pdo->query('SELECT COUNT(*) FROM mail_file')->fetchColumn();
if ($en_attente > 0) {
    $anomalies[] = $en_attente . ' e-mail(s) toujours en attente : le serveur SMTP ne répond pas'
                 . ' (vérifiez les réglages SMTP_* du .env)';
}
$resume[] = $en_attente . ' e-mail(s) en attente';

/* ---------------------------------------------------------------------
   Rapport d'activite

   Le rapport part par envoyer_email_smtp(), qui declare
   « charset=UTF-8 » et encode le corps en base64 : les accents y sont
   rendus fidelement. La copie ecrite sur la sortie standard, elle,
   voyage dans le journal de la tache planifiee d'OVH, dont l'encodage
   n'est pas sous notre controle — c'est une copie de secours, servant
   le jour ou le SMTP est en panne, et un accent mal rendu y est un
   moindre mal.

   ⚠️ L'alignement des colonnes se calcule en CARACTERES : sprintf()
   et str_pad() comptent les octets, si bien que chaque « e » accentue
   decalait sa colonne d'un cran vers la gauche.
   --------------------------------------------------------------------- */
function rapport_texte(PDO $pdo, array $resume, array $anomalies, int $rattrapes,
                       int $perdus, int $en_attente, float $demarre,
                       int $fenetre_minutes): string
{
    /* La période couverte : depuis le dernier rapport (voir
       rapport_fenetre_minutes() dans config.php). Un entier borné, donc
       sans danger une fois écrit dans le SQL. */
    $depuis = 'NOW() - INTERVAL ' . (int) $fenetre_minutes . ' MINUTE';
    $duree  = 'depuis ' . duree_lisible((int) round($fenetre_minutes / 60));

    /* Alignement compte par CARACTERES et non par octets : sprintf()
       et str_pad() mesurent en octets, si bien que chaque « é » du
       libelle decalait sa colonne d'un cran. mb_str_pad() n'existe
       qu'a partir de PHP 8.3, on le fait donc a la main.
       La colonne s'elargit pour le plus long libelle, celui qui porte
       la duree (« Nouveaux comptes (depuis 7 j et 5 heures) »). */
    $largeur = max(34, mb_strlen('Nouveaux comptes (' . $duree . ')', 'UTF-8') + 2);
    $lit = static function (string $cle, $valeur) use ($largeur): string {
        $remplissage = max(1, $largeur - mb_strlen($cle, 'UTF-8'));
        return '  ' . $cle . str_repeat(' ', $remplissage) . $valeur . "\n";
    };
    $un  = static fn (string $sql) => $pdo->query($sql)->fetch(PDO::FETCH_NUM);

    $u = $un("SELECT COUNT(*), SUM(email_verifie), SUM(forfait = 'illimite'),
                     SUM(cree_le > {$depuis}) FROM utilisateur");
    /* « maj_le > cree_le » est ce qui distingue une modification d'une
       création. La colonne vaut CURRENT_TIMESTAMP à l'insertion, si bien
       qu'une série ajoutée et jamais retouchée a maj_le = cree_le : sans
       cette condition elle gonflerait le compteur des modifications le
       jour même de sa création.

       Conséquence assumée : une série créée puis modifiée dans la MÊME
       seconde ne compte pas comme modifiée. Les deux colonnes sont des
       DATETIME, à la seconde près — et une correction faite dans la
       seconde qui suit la saisie tient plus de la faute de frappe
       rattrapée que d'une modification. */
    $s = $un("SELECT COUNT(*), COUNT(DISTINCT utilisateur_id),
                     SUM(cree_le > {$depuis}),
                     SUM(maj_le > {$depuis} AND maj_le > cree_le)
                FROM serie");
    $appareils = $un('SELECT COUNT(*) FROM session_persistante
                       WHERE remplace_le IS NULL AND expire > NOW()');
    $bloques   = $un('SELECT COUNT(*) FROM tentative_ip WHERE bloque_jusqu > NOW()');
    $echecs = $pdo->query("SELECT action, COUNT(*) n, SUM(blocages) b FROM tentative_ip
                            WHERE maj_le > {$depuis}
                            GROUP BY action ORDER BY n DESC")->fetchAll();
    $base = $un('SELECT ROUND(SUM(data_length + index_length) / 1048576, 2)
                   FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');

    $fichiers = 0;
    $octets   = 0;
    foreach (glob(CHEMIN_RACINE . '/uploads/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [] as $f) {
        $fichiers++;
        $octets += (int) @filesize($f);
    }

    $t  = "============================================================\n";
    $t .= '  Ma Bibliothèque Manga — rapport du ' . date('d/m/Y') . ' à ' . date('H\hi') . "\n";
    $t .= '  Période couverte : ' . $duree . "\n";
    $t .= "============================================================\n\n";

    $t .= "COMPTES\n";
    $t .= $lit('Total', (int) $u[0] . ' / ' . MAX_UTILISATEURS
             . ' (' . (int) round($u[0] * 100 / MAX_UTILISATEURS) . ' % du quota)');
    $t .= $lit('Adresse confirmée', (int) $u[1]);
    $t .= $lit('En attente de confirmation', (int) $u[0] - (int) $u[1]);
    $t .= $lit('Forfait illimité', (int) $u[2]);
    $t .= $lit('Nouveaux comptes (' . $duree . ')', (int) $u[3]);

    $t .= "\nBIBLIOTHÈQUES\n";
    $t .= $lit('Séries au total', (int) $s[0]);
    $t .= $lit('Comptes ayant au moins 1 série', (int) $s[1]);
    $t .= $lit('Moyenne par compte actif', $s[1] > 0 ? round($s[0] / $s[1], 1) : 0);
    $t .= $lit('Ajoutées (' . $duree . ')', (int) $s[2]);
    $t .= $lit('Modifiées (' . $duree . ')', (int) $s[3]);

    $t .= "\nSÉCURITÉ (" . $duree . ")\n";
    if ($echecs) {
        foreach ($echecs as $e) {
            $t .= $lit('Échecs « ' . $e['action'] . ' »',
                       $e['n'] . ' source(s), ' . (int) $e['b'] . ' blocage(s)');
        }
    } else {
        $t .= $lit('Aucune tentative échouée', '-');
    }
    $t .= $lit('Blocages encore actifs', (int) $bloques[0]);
    $t .= $lit('Appareils mémorisés', (int) $appareils[0]);

    /* Un e-mail n'entre dans la file QUE si son envoi immediat a echoue :
       une valeur non nulle signifie que quelqu'un attend un lien qui
       n'est jamais parti, pas qu'un envoi soit en cours. */
    $t .= "\nE-MAILS\n";
    $t .= $lit('Bloqués (envoi immédiat échoué)', $en_attente);
    $t .= $lit('Rattrapés à ce passage', $rattrapes);
    $t .= $lit('Perdus définitivement', $perdus);

    $t .= "\nSTOCKAGE\n";
    $t .= $lit('Base de données', ($base[0] ?? 0) . ' Mo'
             . (QUOTA_BASE_MO > 0 ? ' / ' . QUOTA_BASE_MO . ' Mo' : ''));
    $t .= $lit('Images envoyées', $fichiers . ' fichier(s), '
             . round($octets / 1048576, 2) . ' Mo');

    $t .= "\nMÉNAGE DE CE PASSAGE\n";
    foreach ($resume as $ligne) {
        // Les compteurs d'e-mails ont deja leur propre rubrique plus haut.
        if (str_contains($ligne, 'e-mail')) {
            continue;
        }
        $t .= '  - ' . $ligne . "\n";
    }

    if ($anomalies) {
        $t .= "\nANOMALIE\n";
        foreach ($anomalies as $a) {
            $t .= '  - ' . $a . "\n";
        }
    }

    return $t . sprintf("\nDurée : %.2f s\n", microtime(true) - $demarre);
}

/* ---------------------------------------------------------------------
   Date du dernier rapport (table rapport_cron, une seule ligne)

   Retourne [lisible, minutes depuis le dernier envoi | null]. Une table
   absente (base pas encore migree) ne doit pas faire planter le cron :
   c'est lui qui rejoue les e-mails. Elle devient une ANOMALIE, et le
   rapport part a chaque passage jusqu'a la migration. Bruyant, mais pas
   en panne.
   --------------------------------------------------------------------- */
function rapport_etat(PDO $pdo): array
{
    try {
        $v = $pdo->query('SELECT TIMESTAMPDIFF(MINUTE, envoye_le, NOW())
                            FROM rapport_cron WHERE id = 1')->fetchColumn();
    } catch (PDOException $e) {
        return [false, null];
    }
    return [true, ($v === false || $v === null) ? null : (int) $v];
}

function rapport_noter_envoi(PDO $pdo): void
{
    $pdo->exec('INSERT INTO rapport_cron (id, envoye_le) VALUES (1, NOW())
                ON DUPLICATE KEY UPDATE envoye_le = NOW()');
}

$fenetre = rapport_fenetre_minutes($depuis_dernier, RAPPORT_HEURES);
$rapport = rapport_texte($pdo, $resume, $anomalies, $mails_envoyes,
                         $mails_abandonnes, $en_attente, $demarre, $fenetre);

/* Envoi DIRECT, sans passer par la file de rattrapage : un rapport est
   perissable, le suivant arrive au prochain passage. L'empiler ferait
   grossir la file d'un message par execution le jour ou le SMTP tombe,
   en noyant justement les e-mails d'utilisateurs qu'elle doit rejouer.
   S'il echoue, le texte reste dans le journal de la tache planifiee.

   Il ne part que lorsqu'il est du (toutes les RAPPORT_HEURES heures),
   SAUF anomalie : un e-mail perdu ou un SMTP en panne n'attend pas le
   rapport de la semaine, il part tout de suite.

   Seul un rapport DU et bien PARTI est note comme envoye :
     - un rapport d'anomalie hors echeance ne decale pas le suivant,
       qui couvre toujours la periode entiere ;
     - un rapport du mais rate (SMTP) sera retente au passage suivant,
       au lieu d'etre perdu jusqu'a la prochaine echeance. */
$rapport_du = rapport_du($depuis_dernier, RAPPORT_HEURES, CRON_HEURES);

if (ADMIN_EMAIL !== '' && ($rapport_du || $anomalies)) {
    $sujet = ($anomalies ? '[ANOMALIE] ' : '') . 'Ma Bibliothèque — rapport du ' . date('d/m/Y')
           . ' (depuis ' . duree_lisible((int) round($fenetre / 60)) . ')';
    if (!envoyer_email_smtp(ADMIN_EMAIL, $sujet, $rapport)) {
        error_log('purger.php: rapport non envoye a ' . ADMIN_EMAIL);
    } elseif ($rapport_du && $etat_lisible) {
        rapport_noter_envoi($pdo);
    }
}

$message = 'Purge ' . date('Y-m-d H:i:s') . ' — ' . implode(', ', $resume);
error_log($message);

if ($anomalies) {
    error_log('purger.php: ANOMALIE - ' . implode(' | ', $anomalies));
}

if ($en_ligne_de_commande) {
    echo $rapport;
    if ($anomalies) {
        /* Sortie d'erreur ET code de retour non nul : selon les
           hebergeurs, c'est l'un ou l'autre qui declenche l'envoi du
           journal. On fait les deux plutot que de parier sur le bon. */
        fwrite(STDERR, 'ANOMALIE :' . PHP_EOL);
        foreach ($anomalies as $a) {
            fwrite(STDERR, '  - ' . $a . PHP_EOL);
        }
        exit(1);
    }
    exit(0);
}

echo $rapport;
