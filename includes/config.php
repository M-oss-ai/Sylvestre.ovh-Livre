<?php
/* =====================================================================
   Configuration : lecture du .env, connexion à la base de données,
   constantes globales, gestion des erreurs.
   ===================================================================== */

declare(strict_types=1);

/** Dossier racine du projet (un niveau au-dessus de includes/). */
define('CHEMIN_RACINE', dirname(__DIR__));

/* ---------------------------------------------------------------------
   0. Erreurs : jamais affichées, toujours journalisées
   Une trace PHP affichée à l'écran révèle les chemins du serveur, les
   requêtes SQL et parfois des valeurs de configuration. En production on
   ne montre qu'un message neutre (voir le gestionnaire plus bas) ; le
   détail part dans le journal d'erreurs de l'hébergeur.
   --------------------------------------------------------------------- */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/* ---------------------------------------------------------------------
   0bis. HTTPS derrière le répartiteur de charge de l'hébergeur
   Chez OVH (comme chez la plupart des mutualisés), TLS est terminé en
   amont : $_SERVER['HTTPS'] n'est PAS renseigné, c'est X-Forwarded-Proto
   qui l'est. Sans cette normalisation, le cookie de session ne reçoit
   jamais son drapeau « Secure » en production.

   Cet en-tête est falsifiable par le client — mais le seul effet d'une
   falsification est d'ajouter « Secure » à tort, ce qui ne dégrade la
   sécurité de personne. Il ne doit JAMAIS servir à une décision
   d'autorisation.
   --------------------------------------------------------------------- */
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    || ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on') {
    $_SERVER['HTTPS'] = 'on';
}

/**
 * Charge un fichier .env très simple (CLE=valeur, # pour les commentaires)
 * dans getenv()/$_ENV, sans écraser une variable d'environnement déjà
 * définie par le système. Aucune dépendance externe (pas de Composer).
 */
function charger_env(string $chemin): bool
{
    if (!is_file($chemin) || !is_readable($chemin)) {
        return false;
    }
    $lignes = file($chemin, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lignes as $ligne) {
        $ligne = trim($ligne);
        if ($ligne === '' || $ligne[0] === '#' || !str_contains($ligne, '=')) {
            continue;
        }
        [$cle, $valeur] = explode('=', $ligne, 2);
        $cle    = trim($cle);
        $valeur = trim($valeur);
        if (strlen($valeur) >= 2 && (
            ($valeur[0] === '"' && str_ends_with($valeur, '"')) ||
            ($valeur[0] === "'" && str_ends_with($valeur, "'"))
        )) {
            $valeur = substr($valeur, 1, -1);
        }
        if ($cle !== '' && getenv($cle) === false) {
            putenv($cle . '=' . $valeur);
            $_ENV[$cle] = $valeur;
        }
    }
    return true;
}

/* Le .env est cherché d'abord AU-DESSUS de la racine web (recommandé en
   production : hors de portée du serveur web même si un .htaccess est
   ignoré), puis dans le projet (pratique en développement).
   On s'arrête au premier trouvé : chercher les deux systématiquement
   coûtait un is_file() de plus à chaque requête, et l'espace des
   hébergements mutualisés OVH est monté en NFS — chaque accès au disque
   y est un aller-retour réseau. */
if (!charger_env(dirname(CHEMIN_RACINE) . '/.env')) {
    charger_env(CHEMIN_RACINE . '/.env');
}

/**
 * Lit une variable d'environnement, avec valeur de repli.
 *
 * Une clé présente mais VIDE compte comme absente : dans un .env, on
 * laisse couramment « CLE= » pour dire « je n'ai rien à mettre », et
 * c'est justement là que le repli doit jouer.
 */
function env(string $cle, string $defaut = ''): string
{
    $v = getenv($cle);
    return ($v === false || $v === '') ? $defaut : $v;
}

$host     = env('DB_HOST', 'localhost');
$user     = env('DB_USER', 'root');
$password = env('DB_PASSWORD', '');
$database = env('DB_NAME', 'Livre');

/* Adresse de contact affichée aux utilisateurs (limites atteintes, etc.) */
define('ADMIN_EMAIL', env('ADMIN_EMAIL', 'admin@example.com'));

/* ---------------------------------------------------------------------
   Adresse publique du site, utilisée pour fabriquer les liens envoyés par
   e-mail (confirmation, mot de passe oublié).

   Elle DOIT venir de la configuration et jamais de l'en-tête Host de la
   requête : cet en-tête est choisi par le client. Un attaquant qui
   demande une réinitialisation pour votre compte avec « Host: son-site »
   vous ferait recevoir un vrai e-mail, envoyé par ce site, contenant un
   lien vers chez lui — et récupérerait votre jeton au clic.

   Si APP_URL est absent, on retombe sur l'adresse de développement : les
   liens seront cassés en production, ce qui se remarque tout de suite,
   au lieu d'être silencieusement détournables.
   --------------------------------------------------------------------- */
define('APP_URL', rtrim(env('APP_URL', 'http://localhost/Livre'), '/'));

/* Quotas de l'application */
define('MAX_UTILISATEURS', max(1, (int) env('MAX_UTILISATEURS', '100')));
define('MAX_SERIES_PAR_UTILISATEUR', max(1, (int) env('MAX_SERIES_PAR_UTILISATEUR', '150')));

/* Durée de vie du cookie de session, en secondes (0 = jusqu'à la fermeture du navigateur) */
define('SESSION_DUREE', max(0, (int) env('SESSION_DUREE', '0')));

/* Forfait « illimite » : durée du jeton de connexion persistante par
   appareil, en secondes (défaut 1 an) — voir creer_session_persistante(). */
define('REMEMBER_DUREE_VIP', max(0, (int) env('REMEMBER_DUREE_VIP', '31536000')));

/* Nombre maximum d'appareils mémorisés (« se souvenir de moi ») par
   compte illimité. Au-delà, le plus ancien jeton est supprimé : un vieux
   téléphone revendu ne garde pas un accès valide un an. */
define('MAX_APPAREILS', max(1, (int) env('MAX_APPAREILS', '30')));

/* ---------------------------------------------------------------------
   Identification du client — limiteurs et journal de sécurité

   Tout le limiteur anti force brute compte « par IP », et le journal de
   sécurité note d'où vient chaque évènement : encore faut-il que l'IP
   observée soit celle du visiteur.

   Chez OVH (comme chez la plupart des mutualisés), le site est derrière
   un répartiteur de charge. Selon l'infrastructure, REMOTE_ADDR contient
   soit l'adresse du visiteur, soit celle du répartiteur — et dans ce
   second cas TOUS les visiteurs partagent un unique compteur : cinq
   mauvais mots de passe bloquent alors la connexion pour tout le monde,
   et le journal ne sert plus à rien.

   IP_ENTETE nomme l'en-tête à lire à la place (« X-Forwarded-For » chez
   OVH). VIDE PAR DÉFAUT, et ce n'est pas un oubli : sans répartiteur
   devant, n'importe qui peut envoyer l'en-tête de son choix et devenir
   un nouveau visiteur à chaque requête — le limiteur ne verrait plus
   jamais deux tentatives venir du même endroit. On ne l'active donc que
   si l'on a VÉRIFIÉ que le site n'est joignable qu'à travers le proxy.

   IP_PROXY_SAUTS = nombre de relais de confiance devant le site. On lit
   l'en-tête en partant de la DROITE : chaque relais y ajoute l'adresse
   de celui qui lui a parlé, si bien que les entrées de gauche viennent
   du client et sont librement inventées. 1 = un répartiteur (le cas OVH
   habituel) ; 2 = un CDN devant le répartiteur.
   --------------------------------------------------------------------- */
define('IP_ENTETE', env('IP_ENTETE', ''));
define('IP_PROXY_SAUTS', max(1, (int) env('IP_PROXY_SAUTS', '1')));

/**
 * Clé d'identification du client pour le limiteur.
 *
 * En IPv4, une adresse = un client, et compter par adresse a du sens.
 * En IPv6, non : le moindre serveur loué reçoit un bloc /64, soit 18
 * milliards de milliards d'adresses. En changeant les 64 derniers bits à
 * chaque requête, chaque tentative passait pour un nouveau visiteur et le
 * blocage ne se déclenchait jamais. On regroupe donc toute adresse IPv6
 * sur son /64, qui correspond à un abonné ou à une machine.
 */
function ip_client(): string
{
    // Appelée plusieurs fois par requête (limiteurs + journal) : le
    // résultat ne peut pas changer en cours de route.
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

    /* Derrière un répartiteur, REMOTE_ADDR est celle du répartiteur : on
       lit alors l'en-tête désigné par IP_ENTETE (voir config.php, qui
       explique pourquoi ce réglage est vide par défaut). */
    if (IP_ENTETE !== '') {
        $brut = (string) ($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', IP_ENTETE))] ?? '');
        if ($brut !== '') {
            $liste = array_values(array_filter(
                array_map('trim', explode(',', $brut)),
                static fn (string $v): bool => $v !== ''
            ));

            /* En partant de la DROITE, jamais de la gauche : la dernière
               entrée est celle qu'a écrite NOTRE répartiteur, les
               précédentes viennent de plus loin et le client peut en
               fabriquer autant qu'il veut. Prendre la première (l'erreur
               habituelle) revient à laisser choisir son IP à l'attaquant. */
            $candidat = $liste[count($liste) - IP_PROXY_SAUTS] ?? '';

            /* En-tête absent, tronqué ou mal formé : on garde REMOTE_ADDR.
               Compter tout le monde ensemble est gênant ; accepter une
               valeur inventée désarmerait complètement le limiteur. */
            if ($candidat !== '' && filter_var($candidat, FILTER_VALIDATE_IP) !== false) {
                $ip = $candidat;
            }
        }
    }

    if (str_contains($ip, ':')) {
        $octets = @inet_pton($ip);
        if ($octets !== false && strlen($octets) === 16) {
            $prefixe = @inet_ntop(substr($octets, 0, 8) . str_repeat("\0", 8));
            if ($prefixe !== false) {
                return $cache = $prefixe . '/64';
            }
        }
    }
    return $cache = $ip;
}

/**
 * L'adresse ressemble-t-elle à celle d'un relais interne plutôt qu'à
 * celle d'un visiteur ? Sert UNIQUEMENT à signaler une configuration
 * probablement incomplète (voir journal_securite et purger.php ?ip) —
 * jamais à prendre une décision d'autorisation ni de comptage.
 */
function ip_interne(string $ip): bool
{
    if ($ip === '') {
        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return true;
    }
    /* 100.64.0.0/10 : l'adressage partagé des opérateurs (RFC 6598). PHP
       ne le classe ni en privé ni en réservé, et c'est pourtant
       exactement ce que voit un site placé derrière un répartiteur. */
    $o = explode('.', $ip);
    return count($o) === 4 && (int) $o[0] === 100 && (int) $o[1] >= 64 && (int) $o[1] <= 127;
}


/* ---------------------------------------------------------------------
   Limiteur de tentatives (anti force brute)

   Tout est réglable depuis le .env, avec un plancher codé en dur sur
   chaque valeur : une faute de frappe (« 0 », une ligne vide) ne doit pas
   pouvoir désarmer la protection ni bloquer tout le monde au premier
   essai. Les valeurs par défaut ci-dessous sont celles du projet.
   --------------------------------------------------------------------- */

/* Fenêtre de comptage : un échec plus ancien ne compte plus. Elle est
   DISTINCTE de la durée de blocage — tant que les deux étaient
   confondues, espacer ses tentatives d'une minute suffisait à n'être
   jamais bloqué (voir limiteur_echec()). */
define('LIMITEUR_FENETRE', max(60, (int) env('LIMITEUR_FENETRE', '900')));

/* Plafond de la durée de blocage, quel que soit le nombre de récidives. */
define('LIMITEUR_BLOCAGE_MAX', max(60, (int) env('LIMITEUR_BLOCAGE_MAX', '3600')));

/* Au-delà de ce délai sans tentative, le compteur de récidives (donc le
   doublement de la durée) repart à zéro. */
define('LIMITEUR_OUBLI', max(3600, (int) env('LIMITEUR_OUBLI', '86400')));

/* Connexion, par IP : arrête l'attaquant unique qui essaie beaucoup. */
define('CONNEXION_MAX_ESSAIS', max(1, (int) env('CONNEXION_MAX_ESSAIS', '5')));
define('CONNEXION_BLOCAGE', max(1, (int) env('CONNEXION_BLOCAGE', '60')));

/* Connexion, par COMPTE : arrête l'attaque distribuée, qu'un compteur
   par IP ne voit pas (une tentative par machine, mille machines).
   Volontairement plus tolérant : qui connaît un identifiant peut sinon
   verrouiller le compte de son titulaire à volonté. Montez ce seuil si
   vos utilisateurs se plaignent d'être bloqués ; descendez-le si vous
   voyez passer du bourrage d'identifiants dans le journal. */
define('CONNEXION_MAX_ESSAIS_COMPTE', max(1, (int) env('CONNEXION_MAX_ESSAIS_COMPTE', '12')));
define('CONNEXION_BLOCAGE_COMPTE', max(1, (int) env('CONNEXION_BLOCAGE_COMPTE', '120')));

/* Créations de compte par IP. */
define('INSCRIPTION_MAX', max(1, (int) env('INSCRIPTION_MAX', '5')));
define('INSCRIPTION_BLOCAGE', max(1, (int) env('INSCRIPTION_BLOCAGE', '600')));

/* Demandes de « mot de passe oublié » par IP. Avec la disparition des
   quotas d'envoi, c'est le SEUL frein sur les e-mails de
   réinitialisation : ne le desserrez pas à la légère. */
define('MDP_OUBLIE_MAX', max(1, (int) env('MDP_OUBLIE_MAX', '3')));
define('MDP_OUBLIE_BLOCAGE', max(1, (int) env('MDP_OUBLIE_BLOCAGE', '900')));

/* Renvois du lien de confirmation d'adresse, par IP. Même remarque. */
define('VERIF_RENVOI_MAX', max(1, (int) env('VERIF_RENVOI_MAX', '3')));
define('VERIF_RENVOI_BLOCAGE', max(1, (int) env('VERIF_RENVOI_BLOCAGE', '600')));

/* Confirmation par mot de passe des actions sensibles (changement
   d'adresse, vidage, suppression du compte). Sans limite, une session
   volée permet de deviner le mot de passe sans trace — et chaque essai
   coûte un password_verify() volontairement lent. */
define('MDP_CONFIRM_MAX', max(1, (int) env('MDP_CONFIRM_MAX', '5')));
define('MDP_CONFIRM_BLOCAGE', max(1, (int) env('MDP_CONFIRM_BLOCAGE', '60')));

/* ---------------------------------------------------------------------
   Politique de mot de passe

   Seule la LONGUEUR est réglable. Les classes de caractères exigées
   (majuscule, minuscule, chiffre, caractère spécial) restent codées dans
   valider_mot_de_passe() : les rendre optionnelles demanderait de
   reconstruire le texte d'aide au cas par cas, pour un réglage que
   personne ne desserre dans le bon sens.

   MDP_MIN ne peut pas descendre en dessous de 8, quoi qu'on mette dans
   le .env : au-dessous, la politique ne protège plus de rien.
   --------------------------------------------------------------------- */
define('MDP_MIN', max(8, (int) env('MDP_MIN', '8')));
define('MDP_MAX', min(4096, max(MDP_MIN + 1, (int) env('MDP_MAX', '200'))));

/* ---------------------------------------------------------------------
   Images envoyées
   --------------------------------------------------------------------- */

/* Taille du fichier reçu, en octets, avant traitement. Doit rester
   SOUS « upload_max_filesize » et « post_max_size » du .user.ini :
   au-delà, PHP refuse la requête avant que ce contrôle ne s'exécute. */
define('IMAGE_TAILLE_MAX', max(65536, (int) env('IMAGE_TAILLE_MAX', '8388608')));

/* Plus grand côté de l'image stockée, en pixels. Les cartes font 210 à
   280 px de large : 600 px reste net sur un écran Retina. */
define('IMAGE_COTE_MAX', max(100, (int) env('IMAGE_COTE_MAX', '600')));

/* Qualité de ré-encodage WebP/JPEG (1-100). 82 : aucune différence
   visible sur une vignette, environ deux fois plus léger que 95. */
define('IMAGE_QUALITE', min(100, max(1, (int) env('IMAGE_QUALITE', '82'))));

/**
 * Convertit une valeur de php.ini (« 128M », « 512K », « 1G ») en octets.
 * Retourne -1 quand il n'y a pas de limite.
 */
function ini_octets(string $valeur): int
{
    $valeur = trim($valeur);
    $n = (int) $valeur;
    if ($valeur === '' || $n < 0) {
        return -1;
    }
    return match (strtolower(substr($valeur, -1))) {
        'g'     => $n * 1024 * 1024 * 1024,
        'm'     => $n * 1024 * 1024,
        'k'     => $n * 1024,
        default => $n,
    };
}

/**
 * Ramène le plafond de pixels demandé à ce que la mémoire du processus
 * peut réellement encaisser.
 *
 * GD alloue ~4 OCTETS PAR PIXEL en image « truecolor ». Un plafond réglé
 * au-dessus de ce que permet memory_limit ne protège de rien : il laisse
 * passer l'image, et le décodage part en erreur fatale mémoire — que
 * set_exception_handler() ne rattrape PAS. L'utilisateur voit une page
 * blanche, et le processus PHP est tué. C'était le cas avec les 40 Mpx
 * d'origine (~160 Mo) face à un memory_limit de 128 Mo.
 *
 * On réserve 32 Mo pour le reste de la requête : le fichier source en
 * mémoire, la copie redimensionnée, le tampon de sortie et PHP lui-même.
 */
function plafond_pixels(int $demande): int
{
    $memoire = ini_octets((string) ini_get('memory_limit'));
    if ($memoire <= 0) {
        return $demande;   // pas de limite mémoire : on s'en remet au .env
    }
    return max(1000000, min($demande, (int) (($memoire - 33554432) / 4)));
}

/* Nombre de pixels au-delà duquel on refuse de décompresser (« bombe de
   décompression » : un fichier de quelques centaines de Ko peut décrire
   une image de 20 000 x 20 000 px). Les dimensions se lisent dans
   l'en-tête, sans décompresser : on refuse avant, pas après.

   12 Mpx laissent passer toute photo de téléphone en résolution
   standard, et le plafond mémoire ci-dessus rattrape une valeur trop
   généreuse dans le .env au lieu de la laisser tuer le processus. */
define('IMAGE_PIXELS_MAX', plafond_pixels(max(1000000, (int) env('IMAGE_PIXELS_MAX', '12000000'))));

/* ---------------------------------------------------------------------
   Divers
   --------------------------------------------------------------------- */

/* Taille maximale d'un fichier de sauvegarde à l'import, en octets. */
define('IMPORT_TAILLE_MAX', max(65536, (int) env('IMPORT_TAILLE_MAX', '5242880')));

/* ---------------------------------------------------------------------
   Recherche automatique de couverture (voir includes/couvertures.php)
   --------------------------------------------------------------------- */

/* Délai accordé à MangaDex, en secondes. Court volontairement : l'appel
   immobilise un processus PHP le temps de la réponse, et un mutualisé
   n'en a qu'une poignée. Au-delà, la recherche est déclarée
   indisponible — ce qui vaut mieux qu'une page qui ne répond plus. */
define('COUVERTURE_TIMEOUT', max(1, (int) env('COUVERTURE_TIMEOUT', '5')));

/* Nombre de séries proposées. Chacune coûte un appel supplémentaire
   pour aller chercher ses couvertures : au-delà de quelques-unes,
   l'attente devient sensible sans aider au choix. */
define('COUVERTURE_MAX_SERIES', min(10, max(1, (int) env('COUVERTURE_MAX_SERIES', '4'))));

/* Nombre de séries EXAMINÉES avant d'en retenir COUVERTURE_MAX_SERIES.

   MangaDex ordonne par sa propre pertinence, qui place volontiers les
   dérivés et les doujinshi avant la série d'origine : chercher
   « Shingeki no Kyojin » ne ramenait que des doujinshi dans les quatre
   premiers résultats, et la vraie série n'était même pas candidate.

   Élargir ici ne coûte AUCUN appel supplémentaire — c'est le même appel
   avec une limite plus haute. Ce sont les appels /cover qui coûtent, et
   ils restent limités aux séries retenues.

   Jamais en dessous du nombre de séries proposées, sinon on retiendrait
   plus de séries qu'on n'en examine. Plafonné à 100, maximum accepté
   par l'API. */
define('COUVERTURE_CANDIDATS',
    min(100, max(COUVERTURE_MAX_SERIES, (int) env('COUVERTURE_CANDIDATS', '25'))));

/* Autoriser les séries classées « erotica » par MangaDex (nudité, thèmes
   sexuels marqués) dans la recherche automatique de couverture.

   BLOQUÉ PAR DÉFAUT, et volontairement : c'est le réglage qui engage la
   responsabilité de l'éditeur du site, il doit donc être un choix
   explicite et jamais un oubli.

   Même activé ici, rien ne change tant que l'utilisateur n'a pas
   lui-même déclaré sa majorité (colonne utilisateur.adulte_confirme) :
   ce réglage ouvre la possibilité, il ne l'accorde pas.

   Conséquence de le laisser à 0 : les séries concernées deviennent
   introuvables — Berserk, par exemple, que MangaDex classe « erotica »
   pour des scènes de nudité et non pour sa violence. */
define('COUVERTURE_CONTENU_ADULTE', env('COUVERTURE_CONTENU_ADULTE', '0') === '1');

/* --- Ce qui protège le SERVEUR ---------------------------------------

   MangaDex tolère environ 5 requêtes par seconde et par adresse IP —
   celle de l'hébergement, partagée par tous les visiteurs. Une seule
   recherche coûte 1 + COUVERTURE_MAX_SERIES appels, si bien que deux
   personnes en même temps suffisent à dépasser la limite sans que
   personne n'ait rien fait d'anormal.

   D'où une file d'attente et non un quota : les appels sortants sont
   espacés, et rien n'est compté au nom de qui que ce soit. */

/* Millisecondes entre deux appels sortants, tous visiteurs confondus.
   250 ms = 4 appels par seconde, sous la limite de 5. */
define('COUVERTURE_ESPACEMENT', min(2000, max(100, (int) env('COUVERTURE_ESPACEMENT', '250'))));

/* Attente maximale dans cette file, en millisecondes. Au-delà, la
   recherche répond « réessayez dans N secondes » plutôt que de retenir
   un processus PHP — denrée rare sur un mutualisé, et la seule
   ressource que cette borne protège. */
define('COUVERTURE_FILE_MAX', min(5000, max(200, (int) env('COUVERTURE_FILE_MAX', '2000'))));

/* --- Ce qui encadre chaque COMPTE ------------------------------------

   Une règle fixe et annoncée, sans escalade : COUVERTURE_QUOTA
   recherches par tranche de COUVERTURE_FENETRE secondes, et au-delà
   l'attente vaut exactement le temps restant avant la tranche suivante.
   Rien ne double, rien ne se cumule — se servir d'une fonctionnalité
   autant qu'elle le permet n'est pas une faute à sanctionner. */
define('COUVERTURE_FENETRE', min(3600, max(10, (int) env('COUVERTURE_FENETRE', '120'))));
define('COUVERTURE_QUOTA', min(1000, max(1, (int) env('COUVERTURE_QUOTA', '30'))));

/* Le forfait illimité a un plafond lui aussi, simplement plus haut :
   sans plafond du tout, une page laissée à boucler occuperait la file
   toute la journée et en priverait les autres comptes. Jamais sous le
   quota ordinaire — ce serait un forfait « illimité » plus sévère que
   les autres. */
define('COUVERTURE_QUOTA_ILLIMITE',
    min(5000, max(COUVERTURE_QUOTA, (int) env('COUVERTURE_QUOTA_ILLIMITE', '120'))));

/* Borne haute du numéro de tome (colonne INT UNSIGNED). */
define('TOME_MAX', max(1, (int) env('TOME_MAX', '9999')));

/* Délai accordé au serveur SMTP, en secondes. L'envoi immobilise un
   processus PHP et un mutualisé n'en a qu'une poignée : au-delà de
   quelques secondes, quelques inscriptions simultanées figent le site.
   Un envoi trop lent n'est pas perdu, il part en file. */
define('SMTP_TIMEOUT', max(1, (int) env('SMTP_TIMEOUT', '5')));

/* Nombre d'échecs après lequel un message en file est abandonné. */
define('MAIL_FILE_MAX_ESSAIS', max(1, (int) env('MAIL_FILE_MAX_ESSAIS', '3')));

/* ---------------------------------------------------------------------
   Accès à la tâche planifiée (purger.php)

   Ici et non dans fonctions.php : purger.php ne charge que ce fichier
   et mailer.php. fonctions.php, lui, envoie des en-têtes de sécurité et
   démarre une session dès l'inclusion — deux choses qu'un cron n'a pas
   à faire.

   Ici et non dans purger.php non plus : ce script s'exécute dès qu'on
   l'inclut, il est donc intestable. Or ce sont des règles de sécurité,
   et une règle de sécurité qu'on ne peut pas tester finit par dériver
   sans qu'on le voie.
   --------------------------------------------------------------------- */

/**
 * L'appel a-t-il lieu HORS d'une requête web, c'est-à-dire assez
 * sûrement pour se passer du jeton ?
 *
 * Volontairement restrictif : tous les hébergeurs n'invoquent pas leurs
 * tâches planifiées en « cli », certains passent par un enrobage CGI
 * qui laisse traîner des variables HTTP. Au moindre doute on exige le
 * jeton — un fail-safe se conçoit fermé.
 */
function cron_en_ligne_de_commande(string $sapi, array $serveur): bool
{
    return $sapi === 'cli'
        || (!isset($serveur['REQUEST_METHOD'])
            && !isset($serveur['REMOTE_ADDR'])
            && !isset($serveur['HTTP_HOST']));
}

/**
 * Un appel refusé vient-il d'un NAVIGATEUR, ou d'une tâche planifiée
 * mal configurée ?
 *
 * La distinction décide de la façon d'échouer, et elle compte plus
 * qu'il n'y paraît :
 *
 *   - navigateur ou robot : 404 muet, qui ne confirme pas même
 *     l'existence du script ;
 *   - tâche planifiée : message explicite et code de retour NON NUL.
 *
 * Sans ce second cas, un cron refusé sortait en 0 — une réussite, pour
 * l'hébergeur. Réglé sur « envoyer uniquement en cas d'erreur », il ne
 * disait donc jamais rien, et la purge pouvait ne jamais tourner
 * pendant des semaines sans que personne s'en aperçoive.
 *
 * REQUEST_METHOD est le bon marqueur : aucune requête HTTP réelle n'en
 * est dépourvue, et aucun cron n'en fabrique.
 */
function cron_refus_navigateur(array $serveur): bool
{
    return isset($serveur['REQUEST_METHOD']);
}

/**
 * L'appel porte-t-il le jeton de la tâche planifiée ?
 *
 * Sert à décider si l'on peut montrer le DÉTAIL TECHNIQUE d'une erreur
 * plutôt que la page polie. Qui détient ce jeton est l'administrateur
 * du site : lui cacher la cause d'un 500 ne protège personne, et lui
 * coûte des heures — il faut sinon aller lire les journaux de
 * l'hébergeur, quand on y a accès et qu'on sait où ils sont.
 *
 * hash_equals : comparaison à temps constant, pour ne pas laisser
 * deviner le jeton caractère par caractère. Un jeton vide n'autorise
 * rien : sans cette garde, un site dont le .env n'est pas rempli
 * montrerait ses erreurs internes à n'importe qui.
 */
function cron_appelant_authentifie(array $serveur, string $jeton_attendu): bool
{
    if ($jeton_attendu === '') {
        return false;
    }
    return hash_equals($jeton_attendu, (string) ($serveur['HTTP_X_CRON_TOKEN'] ?? ''));
}
/**
 * « 3 Mo », « 512 Ko » — pour que les messages d'erreur suivent le
 * réglage au lieu de répéter une valeur écrite en dur à côté.
 */
function taille_lisible(int $octets): string
{
    if ($octets >= 1048576) {
        $mo = number_format($octets / 1048576, 1, ',', ' ');
        return rtrim(rtrim($mo, '0'), ',') . ' Mo';
    }
    return (int) round($octets / 1024) . ' Ko';
}

/* Sel des noms de fichiers envoyés. Le nom d'une image est l'empreinte
   de son contenu ; sans sel, elle se recalcule de l'extérieur et permet
   de vérifier qu'un utilisateur possède telle image. À ne plus changer
   une fois en service : les images déjà là garderaient leur ancien nom. */
define('UPLOAD_SECRET', env('UPLOAD_SECRET', ''));

/* ---------------------------------------------------------------------
   Informations légales et politique de confidentialité.
   Affichées par mentions-legales.php. Tout se règle dans le .env : rien
   à retoucher dans le code.

   Le site relève du droit SUISSE (nLPD). Deux champs seulement sont
   juridiquement exigés — l'art. 19 nLPD impose d'indiquer « l'identité et
   les coordonnées du responsable du traitement » :
     - LEGAL_EDITEUR  : le nom réel, un pseudonyme ne suffit pas ;
     - LEGAL_CONTACT  : une adresse qui fonctionne (un alias convient).
   Le reste est facultatif et disparaît de la page s'il est laissé vide.
   Contrairement à la France, la Suisse n'impose ni adresse postale ni
   « directeur de la publication » à un site gratuit et non commercial.
   --------------------------------------------------------------------- */
define('LEGAL_EDITEUR', env('LEGAL_EDITEUR', ''));
define('LEGAL_STATUT', env('LEGAL_STATUT', 'Particulier'));
define('LEGAL_ADRESSE', env('LEGAL_ADRESSE', ''));
define('LEGAL_CONTACT', env('LEGAL_CONTACT', env('ADMIN_EMAIL', '')));
define('LEGAL_HEBERGEUR', env('LEGAL_HEBERGEUR', 'OVH SAS'));
define('LEGAL_HEBERGEUR_ADRESSE', env('LEGAL_HEBERGEUR_ADRESSE', '2 rue Kellermann, 59100 Roubaix, France'));
define('LEGAL_HEBERGEUR_TEL', env('LEGAL_HEBERGEUR_TEL', '1007'));

/* Pays où les données sont physiquement stockées. Champ à part, et non
   un bout de LEGAL_HEBERGEUR_ADRESSE : l'art. 19 al. 4 nLPD exige
   d'indiquer l'ÉTAT vers lequel les données sont communiquées, ce qui
   est une information juridique distincte d'une adresse postale — et
   elle reste exacte même si l'hébergeur déménage ses bureaux.

   ⚠️ En le changeant, vérifiez que le nouveau pays figure toujours sur
   la liste des États à protection adéquate (annexe 1 OPDo) : sinon le
   transfert exige des garanties supplémentaires, et la phrase
   correspondante de mentions-legales.php devient fausse. */
define('LEGAL_HEBERGEUR_PAYS', env('LEGAL_HEBERGEUR_PAYS', 'France'));

/* Quota de la base de donnees de votre hebergement, en Mo, affiche
   dans le rapport du cron pour situer la taille actuelle. 0 = ne rien
   afficher. Chez OVH, l'offre d'entree de gamme plafonne souvent a 200. */
define('QUOTA_BASE_MO', max(0, (int) env('QUOTA_BASE_MO', '200')));

/* Jeton attendu par purger.php quand il est appelé en HTTP (cron OVH). */
define('CRON_TOKEN', env('CRON_TOKEN', ''));

/* Numéro de version ajouté aux URL de css/ et js/ (voir actif()).
   À incrémenter à chaque déploiement : le .htaccess demande aux
   navigateurs de garder ces fichiers un an, donc sans changement d'URL
   une correction ne parvient jamais à ceux qui ont déjà visité le site.
   Laissé vide, on retombe sur la date de modification des fichiers —
   pratique en développement, mais coûteux sur un disque réseau. */
define('ASSETS_VERSION', env('ASSETS_VERSION', ''));

/* Fuseau horaire pour les dates affichées */
date_default_timezone_set('Europe/Paris');

/* ---------------------------------------------------------------------
   Page d'erreur neutre, en HTML ou en JSON selon ce que la requête
   attendait. Sert aussi bien à l'échec de connexion à la base qu'au
   gestionnaire d'exceptions global.
   --------------------------------------------------------------------- */
function erreur_fatale(string $journal = ''): never
{
    if ($journal !== '') {
        error_log($journal);
    }

    /* ----------------------------------------------------------------
       Pas de navigateur au bout du fil ?

       En ligne de commande — purger.php lancé par le cron — ni le code
       de réponse HTTP ni la page d'erreur ci-dessous n'ont le moindre
       destinataire. Ce qui en tient lieu est le CODE DE RETOUR, et il
       doit être NON NUL.

       Sans cette branche, purger.php se terminait en SUCCÈS le jour où
       la base était injoignable, après avoir écrit une page HTML dans le
       journal de la tâche planifiée. Le réglage « envoyer le journal
       uniquement en cas d'erreur » de l'hébergeur ne produisait alors
       aucun message : une panne totale de base de données passait
       parfaitement inaperçue, ce qui est exactement ce que le code de
       retour de purger.php existe pour empêcher (voir sa fin de fichier).

       REQUEST_METHOD est renseigné par tout serveur web, et par lui
       seul : sa présence signale une vraie requête HTTP. purger.php
       raisonne déjà de cette façon pour décider s'il doit exiger le
       jeton du cron.
       ---------------------------------------------------------------- */
    if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
        fwrite(
            STDERR,
            'ERREUR FATALE : le script s\'arrête sans avoir fait son travail.'
            . ($journal !== '' ? PHP_EOL . '  ' . $journal : '')
            . PHP_EOL
        );
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Cache-Control: no-store');
    }

    /* L'administrateur, lui, a droit à la cause.
       Diagnostiquer un 500 sur un mutualisé sans cela oblige à aller
       chercher les journaux de l'hébergeur ; on tourne longtemps à
       deviner. Le jeton du cron identifie la seule personne qui a le
       droit de déclencher purger.php : elle peut voir pourquoi il
       échoue. */
    $detail = ($journal !== '' && defined('CRON_TOKEN')
               && cron_appelant_authentifie($_SERVER, CRON_TOKEN))
        ? $journal
        : '';

    // Une requête AJAX attend du JSON : lui renvoyer du HTML produirait
    // « Réponse inattendue du serveur » côté navigateur au lieu du message.
    $veutJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '';

    if ($veutJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        $corps = [
            'ok'     => false,
            'erreur' => "Une erreur technique est survenue. Réessayez dans quelques instants.",
        ];
        if ($detail !== '') {
            $corps['detail'] = $detail;
        }
        echo json_encode($corps, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    exit(
        "<!doctype html><meta charset='utf-8'><title>Service indisponible</title>"
        . "<div style=\"font-family:system-ui;background:#17130f;color:#e6dccf;padding:40px;min-height:100vh\">"
        . "<h1 style=\"color:#f4e4d0\">Service temporairement indisponible</h1>"
        . "<p>Merci de réessayer dans quelques instants. Si le problème persiste, contactez "
        . "l'administrateur à " . htmlspecialchars(ADMIN_EMAIL, ENT_QUOTES, 'UTF-8') . ".</p>"
        . ($detail !== ''
            ? "<pre style=\"white-space:pre-wrap;background:#0d0a08;padding:16px;"
              . "border-radius:8px;color:#f0b8a0\">"
              . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . "</pre>"
            : '')
        . "</div>"
    );
}

/* Toute exception non rattrapée (violation de contrainte SQL, erreur
   d'écriture…) arrive ici : l'utilisateur voit un message neutre, le
   détail part dans le journal. Sans ça, une exception au milieu d'une
   réponse JSON produit une page HTML que le navigateur ne sait pas lire. */
set_exception_handler(static function (Throwable $e): void {
    erreur_fatale('Exception non rattrapée: ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
});

/* ---------------------------------------------------------------------
   PDO en requêtes préparées réelles (EMULATE_PREPARES = false) :
   les valeurs ne sont jamais concaténées dans le SQL, donc aucune
   injection SQL n'est possible.
   --------------------------------------------------------------------- */
try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Le message d'origine peut contenir l'hôte et l'utilisateur de la
    // base : il va au journal, jamais à l'écran.
    erreur_fatale('Connexion base impossible: ' . $e->getMessage());
}
