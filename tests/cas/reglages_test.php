<?php
/* =====================================================================
   includes/reglages.php — les réglages modifiables depuis l'administration.

   Une valeur saisie dans la page remplace celle du .env. La liste est
   FERMÉE et chaque réglage a ses bornes ; ce fichier vérifie :
     - la liste elle-même (ce qui y est, ce qui n'y est PAS, les bornes
       validées par l'utilisateur, les défauts alignés sur config.php) ;
     - que les bornes de la page tiennent dans celles du code : un
       sous-processus impose à chaque réglage sa borne basse puis sa borne
       haute, et compare à ce que config.php en tire ;
     - les décisions pures : validation d'une saisie, conversion des
       unités, filtrage de ce que la base porte, source d'une valeur ;
     - le branchement dans config.php (la base s'ouvre avant les constantes)
       et la tolérance à une table absente.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

function config_source(): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/includes/config.php'));
}

groupe('REGLAGES — la liste fermée');

test('chaque réglage a un groupe connu, un libellé, une aide, des bornes cohérentes', function () {
    vrai(count(REGLAGES) >= 25, count(REGLAGES) . ' réglages');
    foreach (REGLAGES as $cle => $r) {
        vrai(isset(REGLAGES_GROUPES[$r['groupe']]), "$cle : groupe « {$r['groupe']} » connu");
        vrai(trim($r['libelle']) !== '' && trim($r['aide']) !== '', "$cle : libellé et aide");
        vrai(is_int($r['min']) && is_int($r['max']) && $r['min'] <= $r['max'], "$cle : min ≤ max");
        vrai($r['defaut'] >= $r['min'] && $r['defaut'] <= $r['max'], "$cle : le défaut ({$r['defaut']}) est dans les bornes");
    }
});

test('chaque groupe porte au moins un réglage', function () {
    foreach (array_keys(REGLAGES_GROUPES) as $groupe) {
        vrai(count(array_filter(REGLAGES, static fn (array $r): bool => $r['groupe'] === $groupe)) > 0, "groupe « $groupe »");
    }
});

test('ce que l\'utilisateur a demandé y est : quotas, couverture, durées, freins', function () {
    foreach ([
        'MAX_UTILISATEURS', 'MAX_SERIES_PAR_UTILISATEUR', 'MAX_APPAREILS',
        'COUVERTURE_QUOTA', 'COUVERTURE_QUOTA_ILLIMITE', 'COUVERTURE_FENETRE', 'COUVERTURE_MAX_SERIES',
        'COUVERTURE_CANDIDATS', 'COUVERTURE_CONTENU_ADULTE',
        'ADMIN_SUPPRESSION_DUREE', 'RAPPORT_HEURES', 'CRON_HEURES', 'NOUVEAUTE_MINUTES', 'SESSION_DUREE', 'REMEMBER_DUREE_VIP', 'CLE_ACCES_MAX',
        'MDP_MIN', 'MDP_MAJ', 'MDP_MINUSCULE', 'MDP_CHIFFRE', 'MDP_SPE',
    ] as $cle) {
        vrai(isset(REGLAGES[$cle]), "$cle est modifiable");
    }
});

test('les nouveaux tomes : NOUVEAUTE_MINUTES de 60 à 10 080 minutes, saisi en minutes', function () {
    egale([60, 10080, 60], [REGLAGES['NOUVEAUTE_MINUTES']['min'], REGLAGES['NOUVEAUTE_MINUTES']['max'], REGLAGES['NOUVEAUTE_MINUTES']['defaut']], 'NOUVEAUTE_MINUTES');
    egale(1, reglage_facteur('NOUVEAUTE_MINUTES'), 'la page saisit dans l\'unité du .env : des minutes');
    estNul(reglage_valider('NOUVEAUTE_MINUTES', '59')[0], '59 min : moins d\'une heure');
    estNul(reglage_valider('NOUVEAUTE_MINUTES', '10081')[0], '10 081 min : plus d\'une semaine');
    faux(isset(REGLAGES['NOUVEAUTE_MAX_VISITE']) || isset(REGLAGES['NOUVEAUTE_MAX_CRON']) || isset(REGLAGES['PUSH_MAX_APPAREILS']),
        'les nombres de séries du relevé restent dans le .env, et il n\'y a pas de réglage d\'appareils pour les notifications');
});

test('tous les freins anti-force-brute y sont — les quinze du .env', function () {
    $freins = array_keys(array_filter(REGLAGES, static fn (array $r): bool => $r['groupe'] === 'freins'));
    sort($freins);
    $attendus = [
        'CONNEXION_BLOCAGE', 'CONNEXION_BLOCAGE_COMPTE', 'CONNEXION_MAX_ESSAIS', 'CONNEXION_MAX_ESSAIS_COMPTE',
        'INSCRIPTION_BLOCAGE', 'INSCRIPTION_MAX', 'LIMITEUR_BLOCAGE_MAX', 'LIMITEUR_FENETRE', 'LIMITEUR_OUBLI',
        'MDP_CONFIRM_BLOCAGE', 'MDP_CONFIRM_MAX', 'MDP_OUBLIE_BLOCAGE', 'MDP_OUBLIE_MAX',
        'VERIF_RENVOI_BLOCAGE', 'VERIF_RENVOI_MAX',
    ];
    sort($attendus);
    egale($attendus, $freins, 'la section « Freins anti-force-brute » du .env, en entier');
});

test('« Images et import » n\'y est PAS (retiré à la demande de l\'utilisateur)', function () {
    foreach (['IMAGE_TAILLE_MAX', 'IMAGE_COTE_MAX', 'IMAGE_QUALITE', 'IMAGE_PIXELS_MAX', 'IMPORT_TAILLE_MAX', 'TOME_MAX'] as $cle) {
        faux(isset(REGLAGES[$cle]), "$cle reste dans le .env");
    }
});

test('rien de ce qui est secret, d\'identité ou de déploiement n\'est modifiable', function () {
    foreach ([
        'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',                       // la base
        'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASSWORD', 'SMTP_TIMEOUT', 'SMTP_EXPEDITEUR_NOM',
        'CRON_TOKEN', 'UPLOAD_SECRET', 'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', // les secrets
        'APP_URL', 'ADMIN_EMAIL',                                            // l'adresse du site, et la seconde clé des suppressions
        'IP_ENTETE', 'IP_PROXY_SAUTS', 'ASSETS_VERSION',                     // le déploiement
        'MDP_MAX', 'CONFIRMATION_DUREE', 'CLE_ACCES_DEFI_DUREE',              // MDP_MAX (borne technique) et les confirmations
        'COUVERTURE_ESPACEMENT', 'COUVERTURE_FILE_MAX', 'COUVERTURE_TIMEOUT', // ce qui protège l'IP du serveur
        'LEGAL_EDITEUR', 'LEGAL_CONTACT', 'QUOTA_BASE_MO',
    ] as $cle) {
        faux(isset(REGLAGES[$cle]), "$cle reste dans le .env");
    }
});

groupe('REGLAGES — la politique de mot de passe (demande de l\'utilisateur)');

test('MDP_MIN de 1 à 200 caractères, défaut 8', function () {
    egale([1, 200, 8, 'caractères'], [REGLAGES['MDP_MIN']['min'], REGLAGES['MDP_MIN']['max'], REGLAGES['MDP_MIN']['defaut'], REGLAGES['MDP_MIN']['unite']], 'MDP_MIN');
    egale(1, reglage_facteur('MDP_MIN'), 'saisi en caractères, tel quel');
    estNul(reglage_valider('MDP_MIN', '0')[0], '0 : pas de minimum');
    estNul(reglage_valider('MDP_MIN', '201')[0], '201 : au-delà');
    egale(['1', ''], reglage_valider('MDP_MIN', '1'), '1 passe');
    egale(['200', ''], reglage_valider('MDP_MIN', '200'), '200 passe');
});

test('les quatre classes sont des menus 0 / 1, exigées par défaut', function () {
    foreach (['MDP_MAJ', 'MDP_MINUSCULE', 'MDP_CHIFFRE', 'MDP_SPE'] as $cle) {
        egale(['0', '1'], array_map('strval', array_keys(REGLAGES[$cle]['choix'])), "$cle : un menu 0 / 1");
        egale([0, 1, 1], [REGLAGES[$cle]['min'], REGLAGES[$cle]['max'], REGLAGES[$cle]['defaut']], "$cle : de 0 à 1, exigée par défaut");
        egale(['0', ''], reglage_valider($cle, '0'), "$cle : « 0 » accepté");
        egale(['1', ''], reglage_valider($cle, '1'), "$cle : « 1 » accepté");
        estNul(reglage_valider($cle, '2')[0], "$cle : 2 refusé");
        estNul(reglage_valider($cle, 'oui')[0], "$cle : un mot refusé");
        vrai(max(array_map('mb_strlen', REGLAGES[$cle]['choix'])) <= 14, "$cle : les libellés tiennent dans la case (14 caractères)");
    }
});

test('ces réglages forment leur propre groupe « Mots de passe », où il n\'y a que la politique', function () {
    egale('Mots de passe', REGLAGES_GROUPES['mdp'], 'le groupe existe');
    $cles = array_keys(array_filter(REGLAGES, static fn (array $r): bool => $r['groupe'] === 'mdp'));
    egale(['MDP_MIN', 'MDP_MAJ', 'MDP_MINUSCULE', 'MDP_CHIFFRE', 'MDP_SPE'], $cles, 'dans cet ordre : la longueur, puis les quatre classes');
});

test('la base remplace le .env : « 0 » lu de la base est gardé, un choix inconnu ignoré', function () {
    $r = reglages_filtrer(['MDP_SPE' => '0', 'MDP_MAJ' => '1', 'MDP_MIN' => '5000', 'MDP_CHIFFRE' => '7']);
    egale('0', $r['MDP_SPE'], '« 0 » est gardé');
    egale('1', $r['MDP_MAJ'], '« 1 » aussi');
    egale('200', $r['MDP_MIN'], 'une longueur démesurée est ramenée à 200');
    faux(isset($r['MDP_CHIFFRE']), 'un choix inconnu est ignoré : le .env reprend la main');
});

groupe('REGLAGES — les bornes validées');

test('durées de session : 0 à 1 an, saisies en jours', function () {
    foreach (['SESSION_DUREE', 'REMEMBER_DUREE_VIP'] as $cle) {
        egale(0, REGLAGES[$cle]['min'], "$cle : à partir de 0");
        egale(31536000, REGLAGES[$cle]['max'], "$cle : jusqu'à un an");
        egale(86400, reglage_facteur($cle), "$cle : saisi en jours");
    }
});

test('clés d\'accès : 1 à 50 ; passage du cron : 1 à 168 h', function () {
    egale([1, 50], [REGLAGES['CLE_ACCES_MAX']['min'], REGLAGES['CLE_ACCES_MAX']['max']], 'CLE_ACCES_MAX');
    egale([1, 168], [REGLAGES['CRON_HEURES']['min'], REGLAGES['CRON_HEURES']['max']], 'CRON_HEURES');
});

test('freins : 3 à 20 tentatives, 60 à 600 s de blocage', function () {
    foreach (REGLAGES as $cle => $r) {
        if ($r['groupe'] !== 'freins' || str_starts_with($cle, 'LIMITEUR_')) {
            continue;
        }
        if (str_contains($cle, '_MAX')) {
            egale([3, 20], [$r['min'], $r['max']], "$cle : tentatives");
        } else {
            egale(60, $r['min'], "$cle : blocage d'au moins 60 s");
            vrai($r['max'] === 600 || $cle === 'MDP_OUBLIE_BLOCAGE', "$cle : blocage de 600 s au plus (sauf MDP_OUBLIE_BLOCAGE, dont le défaut est 900)");
        }
    }
});

test('les quatre défauts qui dépassent 600 s restent atteignables : leur défaut est leur plafond', function () {
    /* Les plafonner à 600 les aurait fait BAISSER sans que personne l'ait
       demandé — et LIMITEUR_OUBLI ne peut pas descendre sous les 3 600 s que
       config.php lui impose. */
    egale([60, 900, 900], [REGLAGES['LIMITEUR_FENETRE']['min'], REGLAGES['LIMITEUR_FENETRE']['max'], REGLAGES['LIMITEUR_FENETRE']['defaut']], 'LIMITEUR_FENETRE');
    egale([60, 3600, 3600], [REGLAGES['LIMITEUR_BLOCAGE_MAX']['min'], REGLAGES['LIMITEUR_BLOCAGE_MAX']['max'], REGLAGES['LIMITEUR_BLOCAGE_MAX']['defaut']], 'LIMITEUR_BLOCAGE_MAX');
    egale([3600, 86400, 86400], [REGLAGES['LIMITEUR_OUBLI']['min'], REGLAGES['LIMITEUR_OUBLI']['max'], REGLAGES['LIMITEUR_OUBLI']['defaut']], 'LIMITEUR_OUBLI');
    egale([60, 900, 900], [REGLAGES['MDP_OUBLIE_BLOCAGE']['min'], REGLAGES['MDP_OUBLIE_BLOCAGE']['max'], REGLAGES['MDP_OUBLIE_BLOCAGE']['defaut']], 'MDP_OUBLIE_BLOCAGE');
});

groupe('REGLAGES — alignés sur config.php');

test('chaque réglage est une constante de config.php, lue par env()', function () {
    $config = config_source();
    foreach (array_keys(REGLAGES) as $cle) {
        vrai(defined($cle), "$cle est une constante");
        vrai(preg_match("/env\('" . $cle . "',/", $config) === 1, "$cle passe par env() : la base peut donc la remplacer");
    }
});

test('le défaut annoncé est celui du code', function () {
    $config = config_source();
    foreach (REGLAGES as $cle => $r) {
        preg_match("/env\('" . $cle . "',\s*'(-?\d+)'\)/", $config, $m);
        vrai(isset($m[1]), "$cle : un défaut littéral dans config.php");
        egale($r['defaut'], (int) ($m[1] ?? -1), "$cle : le défaut annoncé est celui du code");
    }
});

test('les bornes de la page tiennent dans celles du code : borne BASSE', function () {
    $r = executer_php(CHEMIN_PROJET . '/tests/outils/afficher-constantes-reglages.php', ['min']);
    vrai(preg_match('/##CONSTANTES##(\{.*\})/', $r['sortie'], $m) === 1, 'le sous-processus répond : ' . substr($r['sortie'], 0, 200));
    $c = json_decode($m[1] ?? '{}', true);
    foreach (REGLAGES as $cle => $d) {
        if ($cle === 'RAPPORT_HEURES') {
            /* Le rapport ne part pas plus souvent que le cron ne passe : 0 veut dire « à chaque passage ». */
            egale(1, $c[$cle], "$cle : 0 devient « à chaque passage », donc le cron (1 h ici)");
            continue;
        }
        egale($d['min'], $c[$cle], "$cle : config.php laisse passer la borne basse ({$d['min']})");
    }
});

test('les bornes de la page tiennent dans celles du code : borne HAUTE', function () {
    $r = executer_php(CHEMIN_PROJET . '/tests/outils/afficher-constantes-reglages.php', ['max']);
    vrai(preg_match('/##CONSTANTES##(\{.*\})/', $r['sortie'], $m) === 1, 'le sous-processus répond : ' . substr($r['sortie'], 0, 200));
    $c = json_decode($m[1] ?? '{}', true);
    foreach (REGLAGES as $cle => $d) {
        egale($d['max'], $c[$cle], "$cle : config.php laisse passer la borne haute ({$d['max']})");
    }
});

groupe('reglage_valider() — ce qu\'on saisit');

test('un nombre dans les bornes part en valeur NATIVE', function () {
    egale(['5', ''], reglage_valider('CONNEXION_MAX_ESSAIS', '5'), 'tentatives');
    egale(['3', ''], reglage_valider('CONNEXION_MAX_ESSAIS', ' 3 '), 'les espaces de bord sont retirés');
    egale(['600', ''], reglage_valider('CONNEXION_BLOCAGE', '600'), 'secondes');
    egale(['5', ''], reglage_valider('CONNEXION_MAX_ESSAIS', 5), 'un entier PHP aussi');
});

test('les jours deviennent des secondes, les minutes aussi, les heures de même', function () {
    egale(['2592000', ''], reglage_valider('SESSION_DUREE', '30'), '30 jours');
    egale(['31536000', ''], reglage_valider('REMEMBER_DUREE_VIP', '365'), '365 jours = un an');
    egale(['0', ''], reglage_valider('SESSION_DUREE', '0'), '0 est une valeur : fermeture du navigateur');
    egale(['300', ''], reglage_valider('ADMIN_SUPPRESSION_DUREE', '5'), '5 minutes');
    egale(['86400', ''], reglage_valider('ADMIN_SUPPRESSION_DUREE', '1440'), '1 440 minutes = 24 h');
    egale(['3600', ''], reglage_valider('LIMITEUR_OUBLI', '1'), '1 heure');
    egale(['86400', ''], reglage_valider('LIMITEUR_OUBLI', '24'), '24 heures');
});

test('hors des bornes : refusé, avec la plage dite', function () {
    [$v, $e] = reglage_valider('CONNEXION_MAX_ESSAIS', '2');
    estNul($v, '2 tentatives : trop peu');
    contient('Entre 3 et 20', $e, 'la plage est dite');
    estNul(reglage_valider('CONNEXION_MAX_ESSAIS', '21')[0], '21 : trop');
    estNul(reglage_valider('CONNEXION_BLOCAGE', '59')[0], '59 s : trop court');
    estNul(reglage_valider('CONNEXION_BLOCAGE', '601')[0], '601 s : trop long');
    estNul(reglage_valider('SESSION_DUREE', '366')[0], '366 jours : plus d\'un an');
    contient('jours', reglage_valider('SESSION_DUREE', '366')[1], 'l\'unité de la page, pas des secondes');
    estNul(reglage_valider('CLE_ACCES_MAX', '0')[0], '0 clé');
    estNul(reglage_valider('CLE_ACCES_MAX', '51')[0], '51 clés');
    estNul(reglage_valider('CRON_HEURES', '169')[0], '169 h : plus d\'une semaine');
    estNul(reglage_valider('CRON_HEURES', '0')[0], '0 h');
    estNul(reglage_valider('ADMIN_SUPPRESSION_DUREE', '4')[0], '4 minutes : on n\'aurait pas le temps d\'ouvrir sa boîte');
    estNul(reglage_valider('LIMITEUR_OUBLI', '25')[0], '25 h');
    estNul(reglage_valider('LIMITEUR_FENETRE', '901')[0], '901 s : au-delà de ce qu\'il est aujourd\'hui');
});

test('ce qui n\'est pas un entier est refusé', function () {
    foreach (['', '  ', 'abc', '5.5', '5,5', '-3', '+5', '1e3', '0x10', '٣', '12 13'] as $saisie) {
        estNul(reglage_valider('CONNEXION_MAX_ESSAIS', $saisie)[0], var_export($saisie, true));
    }
    estNul(reglage_valider('CONNEXION_MAX_ESSAIS', null)[0], 'null');
    estNul(reglage_valider('CONNEXION_MAX_ESSAIS', true)[0], 'un booléen');
    estNul(reglage_valider('CONNEXION_MAX_ESSAIS', ['5'])[0], 'un tableau (POST valeur[]=…)');
    estNul(reglage_valider('CONNEXION_MAX_ESSAIS', '99999999999')[0], 'un nombre démesuré');
    contient('nombre entier', reglage_valider('CONNEXION_MAX_ESSAIS', 'abc')[1], 'et on dit quoi saisir');
});

test('un choix : les valeurs du menu, et seulement elles', function () {
    egale(['0', ''], reglage_valider('COUVERTURE_CONTENU_ADULTE', '0'), 'bloquées');
    egale(['1', ''], reglage_valider('COUVERTURE_CONTENU_ADULTE', '1'), 'autorisées');
    estNul(reglage_valider('COUVERTURE_CONTENU_ADULTE', '2')[0], '2 : le code ne connaît que 0 et 1');
    estNul(reglage_valider('COUVERTURE_CONTENU_ADULTE', '')[0], 'vide');
    estNul(reglage_valider('COUVERTURE_CONTENU_ADULTE', 'oui')[0], 'un mot');
});

test('une clé hors liste est refusée, quoi qu\'on y mette', function () {
    foreach (['DB_PASSWORD', 'ADMIN_EMAIL', 'APP_URL', 'IMAGE_TAILLE_MAX', 'inconnue', '', 'max_utilisateurs'] as $cle) {
        [$v, $e] = reglage_valider($cle, '5');
        estNul($v, "« $cle » refusée");
        contient('inconnu', $e, "« $cle » : réglage inconnu");
    }
});

groupe('reglages_filtrer() — ce que la base porte');

test('les valeurs sont ramenées dans leurs bornes', function () {
    $r = reglages_filtrer(['MAX_APPAREILS' => '5000', 'CONNEXION_MAX_ESSAIS' => '1', 'CONNEXION_BLOCAGE' => '60']);
    egale('100', $r['MAX_APPAREILS'], 'trop haut : le plafond');
    egale('3', $r['CONNEXION_MAX_ESSAIS'], 'trop bas : le plancher');
    egale('60', $r['CONNEXION_BLOCAGE'], 'dans les bornes : tel quel');
});

test('une ligne tapée à la main en SQL ne dépasse jamais l\'intervalle', function () {
    $r = reglages_filtrer(['SESSION_DUREE' => '999999999', 'CRON_HEURES' => '99999', 'CLE_ACCES_MAX' => '0']);
    egale('31536000', $r['SESSION_DUREE'], 'une session ne dure pas plus d\'un an');
    egale('168', $r['CRON_HEURES'], 'le cron : une semaine');
    egale('1', $r['CLE_ACCES_MAX'], 'au moins une clé');
});

test('seules les clés de la liste sont retenues', function () {
    $r = reglages_filtrer(['DB_PASSWORD' => 'x', 'ADMIN_EMAIL' => 'pirate@exemple.test', 'IMAGE_QUALITE' => '5', 'MAX_APPAREILS' => '10']);
    egale(['MAX_APPAREILS' => '10'], $r, 'tout le reste est ignoré : le .env reprend la main');
});

test('ce qui n\'est pas lisible est ignoré, sans erreur', function () {
    $r = reglages_filtrer(['MAX_APPAREILS' => 'abc', 'MAX_UTILISATEURS' => '', 'TOME_MAX' => '5', 'CLE_ACCES_MAX' => '1.5',
        'CRON_HEURES' => '-5', 'MDP_OUBLIE_MAX' => ['3'], 'LIMITEUR_FENETRE' => null]);
    egale([], $r, 'rien de lisible : le .env gouverne');
});

test('les entiers PHP et les espaces de bord passent', function () {
    $r = reglages_filtrer(['MAX_APPAREILS' => 12, 'CLE_ACCES_MAX' => ' 7 ']);
    egale(['MAX_APPAREILS' => '12', 'CLE_ACCES_MAX' => '7'], $r, 'lus tels quels');
});

test('un choix doit être l\'une des valeurs du menu', function () {
    egale(['COUVERTURE_CONTENU_ADULTE' => '1'], reglages_filtrer(['COUVERTURE_CONTENU_ADULTE' => '1']), '1');
    egale([], reglages_filtrer(['COUVERTURE_CONTENU_ADULTE' => '2']), '2 : ignoré, pas ramené à 1');
});

groupe('Les unités de la page');

test('reglage_vers_saisie() : jamais de zéros inutiles', function () {
    egale('1', reglage_vers_saisie('SESSION_DUREE', 86400), '86 400 s = 1 jour');
    egale('365', reglage_vers_saisie('REMEMBER_DUREE_VIP', 31536000), 'un an');
    egale('0', reglage_vers_saisie('SESSION_DUREE', 0), '0');
    egale('1.5', reglage_vers_saisie('SESSION_DUREE', 129600), 'un jour et demi');
    egale('60', reglage_vers_saisie('ADMIN_SUPPRESSION_DUREE', 3600), '3 600 s = 60 minutes');
    egale('24', reglage_vers_saisie('LIMITEUR_OUBLI', 86400), '86 400 s = 24 h');
    egale('600', reglage_vers_saisie('CONNEXION_BLOCAGE', 600), 'sans facteur : tel quel');
});

test('reglage_plage_texte() : la plage, dans l\'unité de la page', function () {
    egale('de 60 à 600 s', reglage_plage_texte('CONNEXION_BLOCAGE'), 'secondes');
    egale('de 3 à 20 tentatives', reglage_plage_texte('CONNEXION_MAX_ESSAIS'), 'tentatives');
    egale("de 0 à 365 jours (0 : jusqu'à la fermeture du navigateur)", reglage_plage_texte('SESSION_DUREE'), 'jours, avec le sens du zéro');
    egale('de 0 à 365 jours (0 : désactivée)', reglage_plage_texte('REMEMBER_DUREE_VIP'), 'idem');
    egale('de 0 à 1000 recherches (0 : sans limite)', reglage_plage_texte('COUVERTURE_QUOTA'), 'le zéro « sans limite »');
    egale('de 5 à 1440 min', reglage_plage_texte('ADMIN_SUPPRESSION_DUREE'), 'minutes');
    egale('', reglage_plage_texte('COUVERTURE_CONTENU_ADULTE'), 'un menu n\'a pas de plage');
    egale('', reglage_plage_texte('inconnue'), 'clé inconnue');
});

groupe('Source et vue d\'un réglage');

test('reglage_source() : base, .env, ou défaut', function () {
    egale('base', reglage_source('CLE_ACCES_MAX', ['CLE_ACCES_MAX' => '5'], '10'), 'la base prime');
    egale('base', reglage_source('CLE_ACCES_MAX', ['CLE_ACCES_MAX' => '5'], ''), 'même sans .env');
    egale('env', reglage_source('CLE_ACCES_MAX', [], '10'), 'le .env le fixe');
    egale('defaut', reglage_source('CLE_ACCES_MAX', [], ''), 'rien nulle part');
    egale('defaut', reglage_source('CLE_ACCES_MAX', [], '   '), 'un .env d\'espaces ne fixe rien');
    egale('env', reglage_source('CLE_ACCES_MAX', ['MAX_APPAREILS' => '5'], '10'), 'la base d\'un AUTRE réglage ne compte pas');
});

test('reglage_vue() : la case montre la saisie quand la base la porte, la valeur en vigueur sinon', function () {
    $v = reglage_vue('SESSION_DUREE', [], '', 0);
    egale('0', $v['saisie'], 'sans base : la valeur en vigueur');
    egale('defaut', $v['source'], 'défaut');
    egale('0', $v['min_saisie'], 'borne basse, en jours');
    egale('365', $v['max_saisie'], 'borne haute, en jours');
    estNul($v['en_vigueur'], 'rien à ajouter');

    $v = reglage_vue('SESSION_DUREE', ['SESSION_DUREE' => '2592000'], '', 2592000);
    egale('30', $v['saisie'], 'la base : 30 jours, en jours');
    egale('base', $v['source'], 'modifié');
    estNul($v['en_vigueur'], 'même valeur partout : rien à dire');
});

test('reglage_vue() : quand la valeur en vigueur n\'est pas celle qu\'on a saisie, on le dit', function () {
    /* Un quota « illimité » ne passe jamais sous le quota ordinaire : saisi 200
       avec un quota ordinaire de 0, il vaut 0 en vigueur. */
    $v = reglage_vue('COUVERTURE_QUOTA_ILLIMITE', ['COUVERTURE_QUOTA_ILLIMITE' => '200'], '', 0);
    egale('200', $v['saisie'], 'ce qui a été saisi');
    egale('0 recherches', $v['en_vigueur'], 'et ce qui s\'applique réellement');
});

test('reglage_vue() : un menu garde sa valeur brute', function () {
    $v = reglage_vue('COUVERTURE_CONTENU_ADULTE', ['COUVERTURE_CONTENU_ADULTE' => '1'], '', 1);
    egale('1', $v['saisie'], 'la valeur du menu');
    estNul($v['en_vigueur'], 'pas de note pour un menu');
    vrai(isset($v['choix']), 'ses choix suivent');
});

test('reglage_sans_base_texte() : ce qui s\'applique si on revient au .env', function () {
    egale('10 clés (.env)', reglage_sans_base_texte('CLE_ACCES_MAX', '10'), 'le .env');
    egale('10 clés (défaut)', reglage_sans_base_texte('CLE_ACCES_MAX', ''), 'le défaut du code');
    egale('1 jours (.env)', reglage_sans_base_texte('SESSION_DUREE', '86400'), 'dans l\'unité de la page');
    egale('365 jours (défaut)', reglage_sans_base_texte('REMEMBER_DUREE_VIP', ''), 'défaut d\'un an');
    egale('Autorisées (.env)', reglage_sans_base_texte('COUVERTURE_CONTENU_ADULTE', '1'), 'un menu : son libellé');
    egale('Bloquées (défaut)', reglage_sans_base_texte('COUVERTURE_CONTENU_ADULTE', ''), 'défaut d\'un menu');
    egale('10 clés (défaut)', reglage_sans_base_texte('CLE_ACCES_MAX', 'abc'), 'un .env illisible : le défaut s\'applique');
});

groupe('env() — la base remplace le .env');

test('une valeur de la base prime sur le .env, sans l\'effacer', function () {
    $avant = $GLOBALS['REGLAGES_BASE'];
    try {
        putenv('MAX_APPAREILS=12');
        $GLOBALS['REGLAGES_BASE'] = [];
        egale('12', env('MAX_APPAREILS', '30'), 'sans base : le .env');
        $GLOBALS['REGLAGES_BASE'] = ['MAX_APPAREILS' => '7'];
        egale('7', env('MAX_APPAREILS', '30'), 'avec base : la base');
        egale('12', env_brut('MAX_APPAREILS', '30'), 'env_brut() ignore la base : c\'est ce que « .env » rétablira');
        egale('autre', env('CLE_INCONNUE_DE_LA_BASE', 'autre'), 'une autre clé n\'est pas touchée');
    } finally {
        putenv('MAX_APPAREILS');
        $GLOBALS['REGLAGES_BASE'] = $avant;
    }
});

test('une base qui porte « 0 » est une valeur, pas une absence', function () {
    $avant = $GLOBALS['REGLAGES_BASE'];
    try {
        $GLOBALS['REGLAGES_BASE'] = ['SESSION_DUREE' => '0'];
        egale('0', env('SESSION_DUREE', '86400'), 'zéro : jusqu\'à la fermeture du navigateur, pas le repli');
    } finally {
        $GLOBALS['REGLAGES_BASE'] = $avant;
    }
});

groupe('config.php — la base s\'ouvre avant les constantes');

test('la connexion et la lecture des réglages précèdent la première constante réglable', function () {
    $config = config_source();
    egale(1, substr_count($config, 'new PDO('), 'une seule connexion');
    $admin  = strpos($config, "define('ADMIN_EMAIL'");
    $pdo    = strpos($config, 'new PDO(');
    $lecture = strpos($config, "\$GLOBALS['REGLAGES_BASE'] = reglages_lire(\$pdo)");
    $premiere = strpos($config, "define('MAX_UTILISATEURS'");
    vrai($admin !== false && $pdo !== false && $lecture !== false && $premiere !== false, 'les quatre repères existent');
    vrai($admin < $pdo, 'ADMIN_EMAIL d\'abord : erreur_fatale() l\'affiche si la connexion échoue');
    vrai($pdo < $lecture && $lecture < $premiere, 'la base est lue AVANT que MAX_UTILISATEURS ne soit figé');
    vrai(strpos($config, "require_once __DIR__ . '/reglages.php'") < $admin, 'reglages.php est chargé avant tout');
});

test('reglages.php ne lit aucune constante ni env() : config.php l\'inclut avant d\'en définir une', function () {
    $source = (string) file_get_contents(CHEMIN_SITE . '/includes/reglages.php');
    // Sans les commentaires : ils parlent de ce qu'ils interdisent.
    $code = (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $source);
    sans('env(', $code, 'ne lit pas le .env : c\'est env() qui lit ses réglages, pas l\'inverse');
    sans('define(', $code, 'ne définit aucune constante');
    sans('constant(', $code, 'n\'en lit aucune par son nom');
    sans('getenv(', $code, 'ni l\'environnement');
});

groupe('Tolérance : table absente, panne de la base');

test('sans table `reglage` (la base des tests), rien n\'est lu et rien n\'est dit', function () {
    global $pdo;
    vider_journal_test();
    egale([], reglages_lire($pdo), 'le .env gouverne seul');
    faux(reglages_disponibles($pdo), 'la page proposerait de rejouer livre.sql');
    egale('', trim(journal_test()), 'et rien n\'est consigné : ce n\'est pas une panne');
});

test('une autre panne de la base est consignée, et ne coupe rien', function () {
    $pdo_en_panne = new class extends PDO {
        public function __construct() {}
        #[\ReturnTypeWillChange]
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs)
        {
            throw new PDOException('la base ne répond plus');
        }
    };
    vider_journal_test();
    egale([], reglages_lire($pdo_en_panne), 'le .env gouverne : mieux vaut tourner que de ne plus tourner');
    contient('la base ne répond plus', journal_test(), 'la cause est au journal');
});

test('reglage_ecrire() et reglage_effacer() : une requête préparée, jamais de concaténation', function () {
    $source = (string) file_get_contents(CHEMIN_SITE . '/includes/reglages.php');
    preg_match('/function reglage_ecrire\(.*?\n}\n/s', str_replace("\r\n", "\n", $source), $m);
    contient('prepare(', (string) ($m[0] ?? ''), 'préparée');
    contient('ON DUPLICATE KEY UPDATE', (string) ($m[0] ?? ''), 'une ligne par réglage');
    sans('$cle .', (string) ($m[0] ?? ''), 'la clé n\'est jamais collée dans le SQL');
});

groupe('api.php et admin.php — branchement');

test('les actions de réglage sont gardées, relisent la liste fermée et consignent', function () {
    $api = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/api.php'));
    $debut = (int) strpos($api, "case 'admin.reglage':");
    $bloc = substr($api, $debut, (int) strpos($api, "default:\n", $debut) - $debut);
    contient('reglage_valider($cle', $bloc, 'la valeur est jugée par la fonction pure');
    contient("'champ' => 'valeur'", $bloc, 'l\'erreur va sous la case, pas dans un toast');
    contient('reglage_ecrire(', $bloc, 'écrit');
    contient('reglage_effacer(', $bloc, 'et efface');
    contient('reglages_disponibles($pdo)', $bloc, 'et dit quoi faire si la table manque');
    contient('503', $bloc, 'en 503');
    contient("journal_securite('admin_reglage'", $bloc, 'consigné');
    contient("'recharger' => true", $bloc, 'revenir au .env demande de recharger la page');
    sans('flash(', $bloc, 'sans flash : il s\'afficherait en haut de la page, loin des réglages');
    foreach (['admin.reglage', 'admin.reglage_retablir'] as $action) {
        vrai(in_array($action, ACTIONS_ADMIN, true), "$action est gardée par ACTIONS_ADMIN");
    }
});

test('la page : liste fermée, une case par réglage, tout échappé', function () {
    $page = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/admin.php'));
    contient('foreach (REGLAGES_GROUPES', $page, 'les groupes de la liste');
    contient('reglage_vue(', $page, 'la vue vient de la fonction pure');
    contient('reglages_disponibles($pdo)', $page, 'et la page dit quoi faire si la table manque');
    foreach (["e(\$v['libelle'])", "e(\$v['aide'])", "e(\$v['saisie'])", "e(\$cle)"] as $sortie) {
        contient($sortie, $page, $sortie);
    }
    sans("<?= \$v['libelle']", $page, 'jamais brut');
});

test('chaque ligne dit son nom dans le .env', function () {
    $page = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/admin.php'));
    contient('dans le .env : <code><?= e($cle) ?></code>', $page, 'la clé du .env, échappée, sur chaque ligne');
    contient("'base' => 'modifié'", $page, 'la pastille d\'une valeur changée dit « modifié »');
    sans('modifié ici', $page, 'plus « modifié ici »');
});

groupe('La mise en page — les cases s\'alignent');

test('la colonne de l\'unité a la largeur de la plus grande unité', function () {
    $css = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/css/style.css'));
    $plus_grande = max(array_map('mb_strlen', array_filter(array_column(REGLAGES, 'unite'))));
    vrai($plus_grande >= 8, 'la plus grande unité compte ' . $plus_grande . ' caractères');
    preg_match('/\.admin-reglage-champ\s*\{[^}]*grid-template-columns:\s*7\.5em\s+(\d+)ch/', $css, $m);
    vrai(isset($m[1]), 'la colonne de l\'unité est dite en « ch » dans style.css');
    egale($plus_grande, (int) ($m[1] ?? 0), 'et vaut la plus grande unité : plus étroite, elle couperait ; plus large, elle décalerait tout');
    preg_match('/\.admin-reglage-champ\s*\{[^}]*width:\s*calc\(7\.5em \+ (\d+)ch \+ 6px\)/', $css, $w);
    egale($plus_grande, (int) ($w[1] ?? 0), 'la case entière porte le même nombre');
});

test('l\'unité est collée à gauche, contre la case', function () {
    $css = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/css/style.css'));
    preg_match('/\.admin-unite\s*\{([^}]*)\}/', $css, $m);
    contient('text-align: left', (string) ($m[1] ?? ''), 'alignée à gauche de sa colonne');
});

test('un menu occupe toute la largeur de la case, et ses libellés y tiennent', function () {
    $css = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/css/style.css'));
    contient('.admin-reglage-champ select { grid-column: 1 / -1; width: 100%; }', $css, 'le menu prend les deux colonnes de la case');
    foreach (REGLAGES as $cle => $r) {
        if (isset($r['choix'])) {
            foreach ($r['choix'] as $libelle) {
                vrai(mb_strlen($libelle) <= 14, "$cle : « $libelle » tient dans la case (14 caractères au plus)");
            }
        }
    }
});

test('la migration 12 crée la table, rejouable, avec les bornes qu\'il faut', function () {
    $sql = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_PROJET . '/livre.sql'));
    $debut = (int) strpos($sql, '--  12. Réglages modifiables');
    vrai($debut > (int) strpos($sql, '--  11. Page d\'administration'), 'après la 11');
    $mig = substr($sql, $debut);
    contient('CREATE TABLE IF NOT EXISTS `reglage`', $mig, 'rejouable');
    contient('PRIMARY KEY (`cle`)', $mig, 'une ligne par réglage');
    contient('`valeur`      VARCHAR(190) NOT NULL', $mig, 'la valeur');
    contient('ON DELETE SET NULL', $mig, 'le réglage survit à son auteur');
});

groupe('La page des réglages — introduction, groupes repliables, lignes alternées');

test('l\'introduction est sous le titre « Réglages », avant le premier groupe, et respire', function () {
    $page = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/admin.php'));
    $css = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/css/style.css'));
    $titre = (int) strpos($page, 'id="admin-reglages-titre"');
    $intro = (int) strpos($page, 'admin-reglages-intro');
    $groupes = (int) strpos($page, 'foreach (REGLAGES_GROUPES');
    vrai($titre > 0 && $titre < $intro && $intro < $groupes, 'le titre, puis l\'introduction, puis les groupes');
    contient('Une valeur enregistrée ici', substr($page, $intro, 200), 'c\'est bien elle');
    preg_match('/\.admin-reglages-intro\s*\{[^}]*margin:\s*0 0 (\d+)px/', $css, $m);
    vrai(isset($m[1]) && (int) $m[1] >= 18, 'elle laisse de la place sous elle (' . ($m[1] ?? '?') . ' px) : elle ne touche plus « Quotas »');
});

test('chaque groupe a un titre qui est un bouton replié/déplié, relié à sa liste', function () {
    $page = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/admin.php'));
    contient('<div class="admin-groupe" data-groupe="<?= e($groupe) ?>">', $page, 'un bloc par groupe, son nom en data-');
    contient('<h3 class="settings-sous-titre admin-groupe-titre">', $page, 'le titre reste un titre (h3)');
    contient('<button type="button" class="admin-groupe-bouton" aria-expanded="true" aria-controls="admin-reglages-<?= e($groupe) ?>">', $page,
        'un vrai bouton, ouvert au départ, relié à la liste');
    contient('<span class="admin-chevron" aria-hidden="true"></span>', $page, 'le chevron, décoratif, à droite du nom');
    contient('<div class="admin-reglages" id="admin-reglages-<?= e($groupe) ?>">', $page, 'la liste porte l\'id que le bouton désigne');
    egale(1, substr_count($page, 'class="admin-groupe-bouton"'), 'écrit une fois, dans la boucle');
    /* Le bloc se ferme : un <div> de plus ouvert, un de plus fermé. */
    $debut = (int) strpos($page, 'foreach (REGLAGES_GROUPES');
    $fin = (int) strpos($page, '</section>', $debut);
    $zone = substr($page, $debut, $fin - $debut);
    egale(substr_count($zone, '<div'), substr_count($zone, '</div>'), 'autant de <div> ouverts que fermés dans les groupes');
});

test('style.css : le chevron descend quand le groupe est ouvert, va à droite quand il est replié', function () {
    $css = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/css/style.css'));
    preg_match('/\.admin-chevron\s*\{([^}]*)\}/', $css, $m);
    contient('transform: rotate(45deg)', (string) ($m[1] ?? ''), 'ouvert : la pointe vers le bas');
    contient('.admin-groupe-bouton[aria-expanded="false"] .admin-chevron { transform: rotate(-45deg); }', $css, 'replié : la pointe vers la droite (>)');
    contient('.admin-groupe-bouton:focus-visible', $css, 'un cadre au clavier');
    preg_match('/\.admin-groupe-bouton\s*\{([^}]*)\}/', $css, $b);
    contient('justify-content: space-between', (string) ($b[1] ?? ''), 'le nom à gauche, le chevron à droite');
    contient('width: 100%', (string) ($b[1] ?? ''), 'toute la rangée se clique');
});

test('style.css : la marge est sur le groupe, pas sur la liste (replié, la liste disparaît avec sa marge)', function () {
    $css = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/css/style.css'));
    contient('.admin-groupe { margin-bottom: 18px; }', $css, 'la marge du groupe');
    preg_match('/\.admin-reglages\s*\{([^}]*)\}/', $css, $m);
    sans('margin-bottom', (string) ($m[1] ?? ''), 'la liste n\'en porte pas');
});

test('style.css : les lignes alternent deux nuances, sans filet entre elles', function () {
    $css = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/css/style.css'));
    preg_match('/\.admin-reglage\s*\{([^}]*)\}/', $css, $m);
    $ligne = (string) ($m[1] ?? '');
    motif('/background:\s*rgba\(0, 0, 0, 0\.\d+\)/', $ligne, 'les lignes impaires : une nuance plus sombre');
    sans('border-bottom', $ligne, 'plus de filet : les nuances séparent');
    motif('/\.admin-reglage:nth-child\(even\)\s*\{\s*background:\s*rgba\(201, 168, 124, 0\.\d+\)/', $css, 'les paires : teintées d\'or, la couleur du site');
    preg_match('/\.admin-reglages\s*\{([^}]*)\}/', $css, $c);
    contient('overflow: hidden', (string) ($c[1] ?? ''), 'un cadre arrondi tient les rangées');
    sans('.admin-reglage:last-child', $css, 'l\'ancien filet du dernier est retiré');
});

test('js/admin.js : le repli est branché, mémorisé sans rien supposer du stockage', function () {
    $js = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/js/admin.js'));
    contient('document.querySelectorAll(".admin-groupe").forEach((bloc) => {', $js, 'un branchement par groupe');
    contient('bouton.setAttribute("aria-expanded", replie ? "false" : "true");', $js, 'aria-expanded suit');
    contient('liste.classList.toggle("hidden", replie);', $js, 'la liste se cache comme le reste du site (classe hidden)');
    contient('localStorage.setItem(CLE_GROUPES', $js, 'les groupes repliés sont mémorisés');
    /* Chaque lecture et chaque écriture du stockage est dans un try : navigation privée, stockage bloqué. */
    egale(2, substr_count($js, 'localStorage.'), 'une lecture et une écriture');
    contient("/* Stockage indisponible : tout reste ouvert. */", $js, 'la lecture a son « catch » : tout reste ouvert');
    contient("/* Stockage indisponible : le groupe se replie quand même, pour cette visite. */", $js, 'l\'écriture aussi : le groupe se replie quand même');
    sans('innerHTML', $js, 'rien n\'est fabriqué en HTML');
});
