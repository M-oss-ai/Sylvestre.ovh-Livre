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
 * La série est-elle « à jour » : suivie chez MangaDex, en cours, et son tome lu
 * a atteint le dernier tome que MangaDex connaît ? Le tome suivant n'existe
 * pas encore : la carte dit « pas encore paru » au lieu de « à emprunter ».
 *
 * `dernier_tome` vaut 0 tant que MangaDex n'a pas été interrogé (série
 * d'avant la fonction, ou panne au moment de l'avancer) : on ne prétend alors
 * rien, la carte garde son libellé habituel. Voir includes/nouveautes.php.
 */
function serie_a_venir(array $s): bool
{
    $dernier = (int) ($s['dernier_tome'] ?? 0);
    return ($s['statut'] ?? '') === 'cours'
        && (string) ($s['mangadex_id'] ?? '') !== ''
        && $dernier > 0
        && (int) ($s['tome_actuel'] ?? 0) >= $dernier;
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

    /* À jour : le tome suivant n'est pas paru. La couverture montre alors le
       dernier tome paru, et non « le tome à emprunter ». */
    $a_venir = serie_a_venir($s);

    $alt = $a_venir
        ? "Couverture de {$titre} — dernier tome lu {$tome}, tome {$suivant} pas encore paru"
        : ($en_cours
            ? "Couverture du tome {$suivant} de {$titre}"
            : "Couverture de {$titre} — dernier tome lu {$tome}");

    $image = $couverture !== ''
        ? '<img class="card-cover-img" src="' . e($couverture) . '" alt="' . e($alt) . '" loading="lazy">'
        : '<span class="no-cover" aria-hidden="true">📕</span>';

    $etiquette = $a_venir
        ? '<div class="next-tag next-tag-avenir">Tome ' . $suivant . ' pas encore paru</div>'
        : ($en_cours
            ? '<div class="next-tag">Tome ' . $suivant . ' à emprunter</div>'
            : '');

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
    $c = ['all' => 0, 'cours' => 0, 'envie' => 0, 'termine' => 0, 'abandon' => 0, 'favori' => 0];
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
