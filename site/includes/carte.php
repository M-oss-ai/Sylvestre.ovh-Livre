<?php
/* =====================================================================
   Gabarit d'une carte de série.
   C'est le SEUL endroit du projet où ce HTML est écrit : index.php le
   sert au chargement, api.php le renvoie après chaque action AJAX.
   Un seul gabarit = aucune duplication, et tout est déjà échappé par e().
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/fonctions.php';

/* L'etoile est DESSINEE, pas ecrite.

   Un « ★ » n'a pas de version creuse fiable d'une police a l'autre,
   et le « ☆ » d'Unicode n'a ni la meme taille ni le meme centrage :
   basculer de l'un a l'autre faisait sauter la forme. Ici le meme
   trace sert aux deux etats, seul son remplissage change.

   La Content-Security-Policy n'y voit rien a redire : c'est du
   balisage, pas un style ni un script en ligne. */
const ETOILE_SVG = '<svg class="etoile" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
    . '<path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24'
    . 'l5.46 4.73L5.82 21z"/></svg>';

/**
 * La série est-elle « En attente » : arrivée au dernier tome paru d'une série
 * qui continue ? Le tome suivant n'existe pas encore : la carte dit « Tome N en
 * attente » au lieu de « à emprunter » (serie_fin_etiquette()).
 *
 * C'est le STATUT qui le dit, et rien d'autre : posé tout seul quand on arrive
 * au bout (includes/nouveautes.php), levé tout seul quand un tome de plus sort,
 * et que la personne peut aussi choisir à la main dans la fiche. Une série
 * « En cours » dont on ne sait pas encore qu'elle est au bout garde son
 * libellé habituel : on ne prétend rien qu'on ne sache.
 */
function serie_a_venir(array $s): bool
{
    return ($s['statut'] ?? '') === 'attente';
}

/**
 * La mention qu'une série liée à MangaDex porte dans sa carte, entre « Vous avez
 * lu le tome x » et les boutons — ou '' quand on ne sait encore rien d'elle
 * (demande de l'utilisateur : toute série liée à MangaDex a son message, même au
 * tome 2).
 *
 * Elle vient de l'état de publication que MangaDex donne à la série
 * (serie.publication) et de ses tomes connus :
 *
 *   ongoing    → « Tome N en attente »   (N : le tome qui n'est pas encore paru)
 *   hiatus     → « En pause au tome X »
 *   completed  → « Se termine au tome X »
 *   cancelled  → « Arrêtée au tome X »
 *
 * X est le plus haut tome connu : la dernière couverture (serie.dernier_tome) ou,
 * pour une série finie, le dernier volume que MangaDex DÉCLARE (serie.tome_final).
 *
 * Cas qui ne suivent pas cette règle, dans l'ordre :
 *   - la personne a lu PLUS de tomes que MangaDex n'en connaît : MangaDex est en
 *     retard, elle en est la preuve. « Tome 44 en attente » à qui lit le 44 serait
 *     faux : la carte dit alors « MangaDex s'arrête au tome X » ;
 *   - une série « En attente » (choisie à la main, ou état pas encore connu) dit
 *     « Tome N en attente » (N : le suivant du tome lu) : c'est le choix de la
 *     personne ;
 *   - l'état est lu mais on ne connaît aucun tome (aucune couverture, pas de
 *     dernier volume déclaré) : l'état sans numéro — « En cours de publication »,
 *     « En pause », « Série terminée », « Série arrêtée » ;
 *   - rien de lu (liée depuis quelques secondes, ou pas liée) : ''. Le relevé
 *     (includes/nouveautes.php) le lira, une fois.
 */
function serie_fin_etiquette(array $s): string
{
    return serie_fin_info($s)['texte'];
}

/**
 * La mention (voir serie_fin_etiquette()) ET son explication, pour le « ⓘ » de la
 * carte (demande de l'utilisateur : « un petit message clair »).
 *
 * Retourne ['texte' => la mention, 'aide' => une ou deux phrases qui disent ce
 * qu'elle veut dire et d'où elle vient]. Les deux sont '' quand on ne sait rien.
 *
 * **Seules les mentions SANS numéro de tome ont une aide** (demande de l'utilisateur :
 * « le ⓘ seulement sur les messages sans tomes ou flous, comme “En cours de
 * publication” et “Série terminée” ») : « En cours de publication », « En pause »,
 * « Série terminée », « Série arrêtée » — celles qu'on ne comprend pas d'un coup d'œil.
 * « Tome 44 en attente », « En pause au tome 43 »… se suffisent : 'aide' vaut '' et la
 * carte n'a pas de « ⓘ ». L'aide dit toujours QUI parle — « MangaDex indique que… » —
 * parce que ce sont des informations de MangaDex, saisies par des bénévoles, pas des
 * certitudes du site. Fonction pure, testée : un « ⓘ » n'existe que s'il a quelque
 * chose à dire (carte_html() le décide sur 'aide').
 */
function serie_fin_info(array $s): array
{
    $statut      = (string) ($s['statut'] ?? '');
    $tome        = max(0, (int) ($s['tome_actuel'] ?? 0));
    $dernier     = max(0, (int) ($s['dernier_tome'] ?? 0));
    $connu       = max($dernier, max(0, (int) ($s['tome_final'] ?? 0)));
    $publication = (string) ($s['publication'] ?? '');
    $lu          = in_array($publication, ['ongoing', 'hiatus', 'completed', 'cancelled'], true);

    if ($lu && $connu > 0 && $tome <= $connu) {
        switch ($publication) {
            case 'ongoing':
                if ($dernier > 0) {
                    return ['texte' => 'Tome ' . ($dernier + 1) . ' en attente', 'aide' => ''];
                }
                break;
            case 'hiatus':
                return ['texte' => 'En pause au tome ' . $connu, 'aide' => ''];
            case 'completed':
                return ['texte' => 'Se termine au tome ' . $connu, 'aide' => ''];
            default:
                return ['texte' => 'Arrêtée au tome ' . $connu, 'aide' => ''];
        }
    }
    if ($statut === 'attente') {
        return ['texte' => 'Tome ' . ($tome + 1) . ' en attente', 'aide' => ''];
    }
    if ($connu > 0 && $tome > $connu) {
        return ['texte' => "MangaDex s'arrête au tome " . $connu, 'aide' => ''];
    }
    if ($lu && $connu === 0) {
        return match ($publication) {
            'ongoing'   => [
                'texte' => 'En cours de publication',
                'aide'  => 'MangaDex indique que la série paraît toujours, mais il ne connaît aucun tome numéroté : le site ne peut pas dire où elle en est.',
            ],
            'hiatus'    => [
                'texte' => 'En pause',
                'aide'  => 'MangaDex indique que la série est en pause, mais il ne connaît aucun tome numéroté : le site ne peut pas dire à quel tome.',
            ],
            'completed' => [
                'texte' => 'Série terminée',
                'aide'  => 'MangaDex indique que la publication de la série est terminée, mais ne donne pas son nombre de tomes. '
                    . 'À ne pas confondre avec le statut « Terminée » de la pastille : celui-là est le vôtre, il dit que vous avez fini de la lire.',
            ],
            default     => [
                'texte' => 'Série arrêtée',
                'aide'  => 'MangaDex indique que la série a été arrêtée avant sa fin, mais il ne connaît aucun tome numéroté.',
            ],
        };
    }
    return ['texte' => '', 'aide' => ''];
}
/**
 * La personne a-t-elle lu exactement tout ce que MangaDex connaît de la série ?
 * Alors le tome suivant n'a pas de couverture chez lui : la carte ne propose plus
 * un « Tome N à emprunter » dont on ne sait pas qu'il existe. Il faut pour cela
 * connaître l'état de la série : sans lui, on ne prétend rien.
 */
function serie_au_bout(array $s): bool
{
    $connu = max(max(0, (int) ($s['dernier_tome'] ?? 0)), max(0, (int) ($s['tome_final'] ?? 0)));
    $lu    = in_array((string) ($s['publication'] ?? ''), ['ongoing', 'hiatus', 'completed', 'cancelled'], true);
    return $lu && $connu > 0 && (int) ($s['tome_actuel'] ?? 0) === $connu;
}

/**
 * `$lecture_seule` : le compte est bloqué (compte_bloque()). La carte garde
 * tout ce qui se lit — couverture, titre, progression — et perd ce qui
 * agit : la couverture n'ouvre plus la fiche, les quatre boutons s'en vont.
 * L'étoile reste visible, sans être un bouton : le favori est une
 * information, pas seulement une commande.
 */
function carte_html(array $s, bool $lecture_seule = false): string
{
    $statut  = isset(STATUTS[$s['statut']]) ? (string) $s['statut'] : 'cours';
    $tome    = max(0, (int) $s['tome_actuel']);
    $suivant = $tome + 1;

    // « Tome à emprunter » n'a de sens que pour une série qu'on continue.
    // Terminée / Abandonnée : on affiche seulement le dernier tome lu.
    $en_cours = ($statut === 'cours' || $statut === 'envie');

    $couverture = url_image_sure($s['couverture'] ?? '');
    $favori     = !empty($s['favori']);
    $titre      = (string) $s['titre'];
    $auteur     = (string) ($s['auteur'] ?? '');

    /* Sur le bas de la couverture : « Tome N à emprunter », pour une série qu'on
       continue, tant que MangaDex connaît des tomes plus loin que celui qu'on a lu
       (serie_au_bout()). Ailleurs, la couverture montre le dernier tome paru et ne
       porte rien.

       La MENTION de la série (« Tome N en attente », « En pause au tome X », « Se
       termine au tome X », « Arrêtée au tome X »…) est dans le corps de la carte,
       sous « Vous avez lu le tome x » (serie_fin_etiquette()), pour TOUTE série liée
       à MangaDex et même au tome 2. */
    $info      = serie_fin_info($s);
    $mention   = $info['texte'];
    $emprunter = $en_cours && !serie_au_bout($s);

    $alt = $emprunter ? "Couverture du tome {$suivant} de {$titre}" : "Couverture de {$titre} — dernier tome lu {$tome}";

    $image = $couverture !== ''
        ? '<img class="card-cover-img" src="' . e($couverture) . '" alt="' . e($alt) . '" loading="lazy">'
        : '<span class="no-cover" aria-hidden="true">📕</span>';

    $etiquette = $emprunter ? '<div class="next-tag">Tome ' . $suivant . ' à emprunter</div>' : '';
    /* La mention ; et, SEULEMENT quand elle n'a pas de numéro de tome (« En cours de
       publication », « Série terminée »… : serie_fin_info() lui donne une aide), un « ⓘ » :
       un bouton qui déplie l'explication juste dessous (js/app.js, « card-info »). Pas de
       data-action : ce n'est pas une commande, et un compte bloqué le garde (« aucune
       action pour le script »). tabindex="-1" comme tout ce qui se focalise dans une
       carte ; la carte active le rend (app.js, FOCUSABLES_CARTE). aria-expanded dit s'il
       est déplié. */
    $fin_html = '';
    if ($mention !== '') {
        $bouton = '';
        $aide   = '';
        if ($info['aide'] !== '') {
            $aide_id = 'aide-fin-' . (int) $s['id'];
            $bouton  = '<button type="button" class="card-info" tabindex="-1" aria-expanded="false" aria-controls="' . $aide_id . '"'
                . ' aria-label="Que veut dire « ' . e($mention) . ' » ?" title="Que veut dire ce message ?">ⓘ</button>';
            $aide    = '<p class="card-fin-aide hidden" id="' . $aide_id . '" role="note">' . e($info['aide']) . '</p>';
        }
        $fin_html = '<p class="card-fin"><span class="card-fin-texte">' . e($mention) . '</span>' . $bouton . '</p>' . $aide;
    }

    /* Le même libellé quel que soit le statut : c'est le dernier tome
       TERMINÉ, ce que « Vous en êtes au tome » ne disait pas. À 0, aucun
       tome n'est lu, et « tome 0 » ne voudrait rien dire. */
    if ($tome === 0 && $statut === 'envie') {
        $progression = 'Série non commencée';
    } else if ($tome === 0) {
        $progression = "Vous n'avez lu aucun tome";
    }
    else {
        $progression = 'Vous avez lu le tome <b>' . $tome . '</b>';
    }

    /* tabindex="-1" partout : au clavier, la grille ne compte qu'UN arrêt,
       la carte « active », que js/app.js remet à 0 (les flèches passent
       d'une carte à l'autre). Cinq arrêts par carte faisaient 780 appuis
       sur Tab pour traverser 150 séries. */
    if ($lecture_seule) {
        $couverture_html = '<div class="card-cover" tabindex="-1">';
        $actions_html    = $favori
            ? '<div class="card-actions card-actions-lecture">
            <span class="favori-lecture" role="img" aria-label="Favori" title="Favori">' . ETOILE_SVG . '</span>
          </div>'
            : '';
    } else {
        $couverture_html = '<div class="card-cover" data-action="edit" role="button" tabindex="-1" aria-label="Modifier ' . e($titre) . '">';
        $actions_html    = '<div class="card-actions">
            <button type="button" class="btn-undo" data-action="undo" tabindex="-1"
                    title="Annuler : revenir au tome précédent"
                    aria-label="Annuler la dernière lecture"' . ($tome <= 0 ? ' disabled' : '') . '>←</button>
            <button type="button" class="btn-advance" data-action="advance" tabindex="-1"
                    title="Marquer le tome ' . $suivant . ' comme lu"
                    aria-label="Marquer le tome ' . $suivant . ' comme lu">→</button>
            <button type="button" class="btn-favori' . ($favori ? ' actif' : '') . '" tabindex="-1"
                    data-action="favori" aria-pressed="' . ($favori ? 'true' : 'false') . '"
                    title="' . ($favori ? 'Retirer des favoris' : 'Mettre en favori') . '"
                    aria-label="' . ($favori ? 'Retirer' : 'Mettre') . ' &quot;' . e($titre) . '&quot; ' . ($favori ? 'des' : 'en') . ' favori' . ($favori ? 's' : '') . '">'
              . ETOILE_SVG . '</button>
            <button type="button" class="btn-edit" data-action="edit" tabindex="-1"
                    title="Modifier / gérer la couverture" aria-label="Modifier ' . e($titre) . '">✏️</button>
          </div>';
    }

    return '
      <article class="card status-' . e($statut) . '"
               data-id="' . (int) $s['id'] . '"
               data-statut="' . e($statut) . '"
               data-titre="' . e($titre) . '"
               data-auteur="' . e($auteur) . '"
               data-tome="' . $tome . '"
               data-couverture="' . e($couverture) . '"
               data-mangadex="' . e((string) ($s['mangadex_id'] ?? '')) . '"
               data-favori="' . ($favori ? '1' : '0') . '"
               data-image="' . e(type_image($couverture)) . '">
        ' . $couverture_html . '
          ' . $image . '
          <span class="badge">' . e(STATUTS[$statut]) . '</span>
          ' . $etiquette . '
        </div>
        <div class="card-body">
          <h3 class="card-title">' . e($titre) . '</h3>
          <p class="card-subtitle">' . e($auteur) . '</p>
          <p class="card-progress">' . $progression . '</p>
          ' . $fin_html . '
          ' . $actions_html . '
        </div>
      </article>';
}

/**
 * Compte des séries pour les pastilles des filtres : « all », un nombre
 * par statut, « favori », et « image-<type> » pour chaque type de
 * couverture (les clés d'IMAGES_TYPES). Ces clés sont aussi les
 * identifiants « count-<clé> » que js/app.js remet à jour.
 */
function compter_series(PDO $pdo, int $utilisateur_id): array
{
    /* Une seule lecture, comptée en PHP : le type d'une image ne se
       déduit que de type_image(), la règle unique. La refaire en SQL
       (LIKE sur les préfixes) la ferait diverger du filtre lui-même. */
    $req = $pdo->prepare('SELECT statut, favori, couverture FROM serie WHERE utilisateur_id = ?');
    $req->execute([$utilisateur_id]);
    return compter_lignes($req->fetchAll());
}

/** Le comptage de compter_series(), sur des lignes déjà lues (testable sans base). */
function compter_lignes(array $lignes): array
{
    // Un compteur par statut de STATUTS : en ajouter un ne demande pas de penser à cette liste.
    $c = ['all' => 0] + array_fill_keys(array_keys(STATUTS), 0) + ['favori' => 0];
    foreach (IMAGES_TYPES as $cle => $libelle) {
        $c['image-' . $cle] = 0;
    }

    foreach ($lignes as $ligne) {
        $c['all']++;
        $statut = (string) $ligne['statut'];
        if (isset(STATUTS[$statut])) {
            $c[$statut]++;
        }
        // Les favoris TRAVERSENT les statuts : une série peut être à la
        // fois « en cours » et en favori.
        if ((int) $ligne['favori'] === 1) {
            $c['favori']++;
        }
        $c['image-' . type_image((string) $ligne['couverture'])]++;
    }
    return $c;
}
