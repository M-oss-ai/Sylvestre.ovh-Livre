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
    $reponse = curl_exec($ch);
    $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erreur  = curl_error($ch);
    curl_close($ch);

    if ($code !== 200) {
        error_log('couvertures: ' . $chemin . ' a répondu ' . $code
                . ($erreur !== '' ? ' (' . $erreur . ')' : ''));
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
