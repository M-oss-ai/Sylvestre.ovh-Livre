<?php
/* =====================================================================
   Recherche de couverture par TOME.

   L'ancienne version interrogeait api.jikan.moe depuis le navigateur.
   Deux défauts, et le second est rédhibitoire :

     - le service répond 504 depuis un moment ;
     - surtout, Jikan ne connaît que des SÉRIES. Il renvoie une image par
       manga, jamais une par tome. Or ce que l'utilisateur cherche, c'est
       la couverture du prochain tome à emprunter : impossible à obtenir
       de cette source, quelle que soit sa disponibilité.

   MangaDex, lui, expose une couverture par tome. Mais il n'envoie pas
   d'en-tête « Access-Control-Allow-Origin » : un appel direct depuis le
   navigateur est refusé. L'appel part donc d'ici.

   Ce détour vaut mieux que le montage précédent :
     - la Content-Security-Policy revient à « connect-src 'self' » ;
     - l'adresse IP du visiteur n'est plus communiquée à un tiers ;
     - le délai est borné, donc un service lent ne fige pas le site.
   ===================================================================== */

declare(strict_types=1);

/** Hôte interrogé. Codé en dur : aucune URL ne vient de l'utilisateur. */
const MANGADEX_API = 'https://api.mangadex.org';
const MANGADEX_IMAGES = 'https://uploads.mangadex.org/covers/';

/* --- La cadence des appels --------------------------------------------

   MangaDex tolère environ 5 requêtes par seconde et par adresse IP.
   L'adresse comptée est celle du SERVEUR, jamais celle du visiteur :
   tous les comptes partagent donc un seul et même budget. Le dépasser
   vaut un 429, puis un blocage temporaire de l'hébergement, puis un
   blocage complet dont la durée n'est pas publiée.

   Une seule recherche coûte 1 + COUVERTURE_MAX_SERIES appels. À deux
   personnes en même temps le budget est dépassé sans que personne n'ait
   rien fait d'anormal. Tenir la cadence est donc le travail du serveur,
   pas une raison de rationner l'utilisateur : ici on ne compte rien et
   on ne sanctionne personne, les appels font la queue.

   Le quota par compte, lui, est une autre affaire et vit plus bas. */

/** Fichier témoin de la file. Hors du site : il ne contient qu'une date,
    il n'a rien à faire dans une sauvegarde ni derrière une URL. */
function mangadex_fichier_rythme(): string
{
    return sys_get_temp_dir() . '/mangadex-rythme.lock';
}

/**
 * Attente à proposer quand un appel n'a pas pu partir, en secondes.
 * Zéro le reste du temps.
 *
 * Une variable statique plutôt qu'une valeur de retour : mangadex_get()
 * répond déjà null pour toute avarie, et distinguer « indisponible » de
 * « trop de monde » à l'intérieur de ce null aurait demandé de changer
 * la signature de toute la chaîne d'appel.
 */
function mangadex_attente_suggeree(?int $definir = null): int
{
    static $attente = 0;
    if ($definir !== null) {
        $attente = max(0, $definir);
    }
    return $attente;
}

/**
 * Fait attendre son tour à l'appel qui suit, pour que le débit sortant
 * du serveur reste sous la limite de MangaDex quel que soit le nombre
 * de visiteurs simultanés.
 *
 * Retourne 0 quand le tour est acquis, sinon un nombre de secondes :
 * mieux vaut répondre « réessayez dans deux secondes » que d'immobiliser
 * un processus PHP, denrée rare sur un mutualisé — c'est la seule
 * ressource que cette borne protège.
 *
 * Faute de pouvoir écrire le fichier témoin, l'appel part sans attendre :
 * une précaution en panne ne doit pas emporter la fonctionnalité avec
 * elle, et le 429 de MangaDex reste là pour rattraper le coup.
 */
function mangadex_attendre_son_tour(): int
{
    $f = @fopen(mangadex_fichier_rythme(), 'c+');
    if ($f === false) {
        return 0;
    }

    $espacement = COUVERTURE_ESPACEMENT / 1000;   // ms -> s
    $plafond    = COUVERTURE_FILE_MAX / 1000;
    $debut      = microtime(true);

    /* Verrou NON bloquant : flock() attendrait sans limite, or c'est
       justement la limite qui nous intéresse. */
    while (!flock($f, LOCK_EX | LOCK_NB)) {
        if (microtime(true) - $debut >= $plafond) {
            fclose($f);
            return max(1, (int) ceil($plafond));
        }
        usleep(20000);
    }

    rewind($f);
    $precedent = (float) stream_get_contents($f);
    $reste     = $precedent + $espacement - microtime(true);

    /* Si respecter la cadence dépassait l'attente acceptable, on rend la
       main plutôt que de dormir dessus en retenant un processus. */
    if ($reste > 0 && (microtime(true) - $debut) + $reste > $plafond) {
        flock($f, LOCK_UN);
        fclose($f);
        return max(1, (int) ceil($reste));
    }
    if ($reste > 0) {
        usleep((int) ($reste * 1000000));
    }

    ftruncate($f, 0);
    rewind($f);
    fwrite($f, (string) microtime(true));
    fflush($f);
    flock($f, LOCK_UN);
    fclose($f);

    return 0;
}

/**
 * Appel GET sur l'API, en JSON. Retourne null sur le moindre problème :
 * l'appelant affiche alors « recherche indisponible », ce qui est la
 * seule chose utile à dire à l'utilisateur.
 *
 * Le délai est court et volontairement : l'appel immobilise un processus
 * PHP, et un hébergement mutualisé n'en a qu'une poignée — même
 * raisonnement que pour SMTP_TIMEOUT.
 */
function mangadex_get(string $chemin, array $parametres): ?array
{
    /* Son tour d'abord : c'est ce qui garantit que le SERVEUR respecte
       le débit de MangaDex, là où un quota par visiteur ne borne jamais
       la somme des visiteurs. */
    $tour = mangadex_attendre_son_tour();
    if ($tour > 0) {
        mangadex_attente_suggeree($tour);
        return null;
    }

    $ch = curl_init(MANGADEX_API . $chemin . '?' . http_build_query($parametres));
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => COUVERTURE_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => min(3, COUVERTURE_TIMEOUT),
        CURLOPT_FOLLOWLOCATION => false,   // aucune redirection à suivre
        CURLOPT_USERAGENT      => 'MaBibliothequeManga/1.0',
    ]);

    /* Un seul en-tête nous intéresse, et il n'arrive qu'avec un 429 :
       MangaDex y donne l'instant de reprise, en horodatage UNIX. */
    $reprise = 0;
    curl_setopt($ch, CURLOPT_HEADERFUNCTION,
        static function ($ch, string $entete) use (&$reprise): int {
            if (stripos($entete, 'x-ratelimit-retry-after:') === 0) {
                $reprise = (int) trim(substr($entete, 24));
            }
            return strlen($entete);   // cURL exige le nombre d'octets lus
        });

    $reponse = curl_exec($ch);
    $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erreur  = curl_error($ch);
    curl_close($ch);

    if ($code !== 200) {
        error_log('couvertures: ' . $chemin . ' a répondu ' . $code
                . ($erreur !== '' ? ' (' . $erreur . ')' : ''));

        /* 429 : la cadence n'a pas suffi. L'horodatage de reprise devient
           un délai, borné — une valeur aberrante ne doit pas afficher
           « réessayez dans trois heures ». Ignorer ce signal et laisser
           réessayer aussitôt est exactement ce qui mène au blocage
           complet de l'hébergement. */
        if ($code === 429) {
            mangadex_attente_suggeree(max(1, min(60, $reprise > 0 ? $reprise - time() : 0)));
        }
        return null;
    }
    $donnees = json_decode((string) $reponse, true);
    return is_array($donnees) ? $donnees : null;
}

/**
 * Titre lisible d'une série, en préférant le français puis l'anglais.
 * MangaDex range les titres par langue et n'en garantit aucune.
 */
function mangadex_titre(array $attributs): string
{
    foreach (['fr', 'en', 'ja-ro', 'ja'] as $langue) {
        if (!empty($attributs['title'][$langue])) {
            return (string) $attributs['title'][$langue];
        }
    }
    $titres = $attributs['title'] ?? [];
    return (string) (reset($titres) ?: '');
}

/**
 * Comparaison de titres insensible à la casse, aux accents et à la
 * ponctuation : « Fruits Basket » et « fruits-basket » doivent se
 * reconnaître.
 *
 * Écrit à la main plutôt qu'avec Normalizer ou iconv : le premier exige
 * l'extension intl, absente de bien des hébergements mutualisés, et le
 * second produit des résultats qui varient d'une plateforme à l'autre.
 * Une table de correspondance ne dépend de rien.
 */
function titre_normalise(string $t): string
{
    $t = mb_strtolower(trim($t), 'UTF-8');
    $t = strtr($t, [
        'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a',
        'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
        'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ø'=>'o',
        'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n','ÿ'=>'y','æ'=>'ae','œ'=>'oe','ß'=>'ss',
    ]);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $t) ?? $t);
}

/**
 * Nombre de recherches autorisées à ce compte par tranche de
 * COUVERTURE_FENETRE secondes.
 *
 * Une règle annoncée, fixe, sans escalade : trente recherches par deux
 * minutes, et au-delà l'attente vaut exactement le temps restant avant
 * la tranche suivante. Chercher une couverture n'est pas une faute, et
 * la mécanique précédente — celle des mots de passe, qui double la peine
 * à chaque récidive — punissait l'usage normal d'une fonctionnalité.
 *
 * Le forfait illimité a un plafond lui aussi, simplement plus haut :
 * sans aucun plafond, une page laissée à boucler suffirait à occuper la
 * file toute la journée et à en priver les autres comptes.
 *
 * Fonction pure, donc testable sans base : c'est le comptage qui exige
 * une base, pas le barème.
 */
function couverture_quota(array $utilisateur): int
{
    return ($utilisateur['forfait'] ?? '') === 'illimite'
        ? COUVERTURE_QUOTA_ILLIMITE
        : COUVERTURE_QUOTA;
}

/**
 * La durée d'une tranche, en toutes lettres, pour l'annoncer à
 * l'utilisateur : « 30 recherches par 2 minutes ».
 *
 * Une règle qu'on ne sait pas énoncer n'est pas une règle claire — d'où
 * cette fonction plutôt qu'un nombre de secondes brut dans le message.
 */
function couverture_tranche_lisible(int $secondes): string
{
    if ($secondes % 60 !== 0) {
        return $secondes . ' seconde' . ($secondes > 1 ? 's' : '');
    }
    $minutes = intdiv($secondes, 60);
    return $minutes . ' minute' . ($minutes > 1 ? 's' : '');
}

/**
 * Compte une recherche pour ce compte et dit s'il peut la faire.
 * Retourne 0 si oui, sinon le nombre de secondes avant la tranche
 * suivante — une information, pas une sanction : rien ne s'aggrave, rien
 * ne se cumule, la tranche suivante repart entière.
 *
 * Fenêtre FIXE et non glissante : c'est ce qui permet d'annoncer une
 * règle vérifiable par l'utilisateur (« trente par deux minutes »)
 * plutôt qu'un solde dont personne ne peut suivre le calcul.
 *
 * L'incrément tient en une instruction : deux recherches simultanées
 * liraient sinon le même compteur et le réécriraient à l'identique, si
 * bien que deux recherches n'en compteraient qu'une.
 */
function couverture_consommer(int $utilisateur_id, int $quota, int $fenetre): int
{
    global $pdo;

    $req = $pdo->prepare(
        'INSERT INTO recherche_couverture (utilisateur_id, essais, fenetre_fin)
         VALUES (?, 1, NOW() + INTERVAL ? SECOND)
         ON DUPLICATE KEY UPDATE
           essais      = IF(fenetre_fin <= NOW(), 1, essais + 1),
           fenetre_fin = IF(fenetre_fin <= NOW(), NOW() + INTERVAL ? SECOND, fenetre_fin)'
    );
    $req->execute([$utilisateur_id, $fenetre, $fenetre]);

    $req = $pdo->prepare(
        'SELECT essais, GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), fenetre_fin)) AS reste
           FROM recherche_couverture WHERE utilisateur_id = ?'
    );
    $req->execute([$utilisateur_id]);
    $ligne = $req->fetch();

    if (!$ligne || (int) $ligne['essais'] <= $quota) {
        return 0;
    }
    /* Au moins une seconde : « réessayez dans 0 seconde » ne veut rien
       dire, et le compte à rebours n'aurait rien à afficher. */
    return max(1, (int) $ligne['reste']);
}

/**
 * Rend la recherche décomptée par couverture_consommer() lorsque
 * l'appel n'est finalement pas parti — file d'attente saturée, ou 429
 * renvoyé par MangaDex.
 *
 * Sans cela, une indisponibilité du site entamerait le quota de
 * quelqu'un qui n'a rien obtenu en échange. Le quota mesure des
 * recherches faites, pas des tentatives : c'est exactement la nuance
 * qui sépare ce compteur de celui des mots de passe.
 *
 * GREATEST(0, …) plutôt qu'une soustraction nue : deux restitutions
 * concurrentes ne doivent pas faire passer le compteur sous zéro, et
 * une tranche échue entre-temps ne doit pas être entamée à rebours.
 */
function couverture_rendre(int $utilisateur_id): void
{
    global $pdo;

    $pdo->prepare(
        'UPDATE recherche_couverture
            SET essais = GREATEST(0, essais - 1)
          WHERE utilisateur_id = ? AND fenetre_fin > NOW()'
    )->execute([$utilisateur_id]);
}

/**
 * Cherche la couverture du tome $tome pour les séries correspondant à
 * $titre. Retourne une liste de candidats, le plus probable en premier :
 *
 *   ['serie' => 'One Piece', 'tome' => 105, 'exact' => true, 'url' => '…']
 *
 * « exact » distingue la vraie couverture du tome demandé de celle de la
 * série, servie en repli. L'utilisateur doit savoir laquelle il choisit.
 */
function chercher_couvertures(string $titre, int $tome, bool $adulte = false): array
{
    /* « erotica » n'est proposé que si DEUX conditions sont réunies : le
       site l'autorise (COUVERTURE_CONTENU_ADULTE) et l'utilisateur a
       déclaré sa majorité. L'appelant a déjà fait cette vérification ;
       ici on ne fait qu'en tirer les conséquences.

       Ce classement porte sur le contenu SEXUEL, pas sur la violence :
       c'est pourquoi Berserk s'y trouve. « pornographic » n'est jamais
       proposé, quelle que soit la configuration. */
    $classements = $adulte
        ? ['safe', 'suggestive', 'erotica']
        : ['safe', 'suggestive'];

    $recherche = mangadex_get('/manga', [
        'title' => $titre,
        'limit' => COUVERTURE_MAX_SERIES,
        'contentRating' => $classements,
        'order' => ['relevance' => 'desc'],
    ]);
    if ($recherche === null || empty($recherche['data'])) {
        return [];
    }

    $vise = titre_normalise($titre);
    $resultats = [];

    foreach ($recherche['data'] as $rang => $manga) {
        $id = (string) ($manga['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $nom = mangadex_titre($manga['attributes'] ?? []);

        /* Les couvertures sont triées par tome : on saute directement à
           la page qui contient celui qu'on cherche, plutôt que de
           parcourir les cent premières d'une série qui en compte cent
           cinquante. */
        $page = max(0, intdiv(max(0, $tome - 1), 100) * 100);
        $couvertures = mangadex_get('/cover', [
            'manga'  => [$id],
            'limit'  => 100,
            'offset' => $page,
            'order'  => ['volume' => 'asc'],
        ]);

        $choisie = null;
        $repli   = null;
        $rangLangue = ['fr' => 0, 'en' => 1, 'ja' => 2];

        foreach ($couvertures['data'] ?? [] as $couverture) {
            $a = $couverture['attributes'] ?? [];
            $fichier = (string) ($a['fileName'] ?? '');
            if ($fichier === '') {
                continue;
            }
            $url = MANGADEX_IMAGES . rawurlencode($id) . '/' . rawurlencode($fichier) . '.512.jpg';
            $repli ??= $url;

            if (isset($a['volume']) && (int) $a['volume'] === $tome) {
                $poids = $rangLangue[$a['locale'] ?? ''] ?? 9;
                if ($choisie === null || $poids < $choisie['poids']) {
                    $choisie = ['url' => $url, 'poids' => $poids];
                }
            }
        }

        $url = $choisie['url'] ?? $repli;
        if ($url === null) {
            continue;
        }

        $resultats[] = [
            'serie' => $nom,
            'tome'  => $tome,
            'exact' => $choisie !== null,
            'url'   => $url,
            /* Tri : d'abord le titre qui correspond vraiment, ensuite
               celui dont le tome a été trouvé, et seulement après le
               classement de MangaDex — qui place « VRMMO Chronicles of a
               Solo Cleric » avant « Berserk » quand on cherche Berserk. */
            '_score' => (titre_normalise($nom) === $vise ? 0 : 10)
                      + ($choisie !== null ? 0 : 3)
                      + min(2, $rang * 0.1),
        ];
    }

    usort($resultats, static fn (array $a, array $b) => $a['_score'] <=> $b['_score']);

    return array_map(
        static fn (array $r) => ['serie' => $r['serie'], 'tome' => $r['tome'],
                                 'exact' => $r['exact'], 'url' => $r['url']],
        $resultats
    );
}
