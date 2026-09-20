<?php
/* =====================================================================
   Gabarit d'une carte de série.
   C'est le SEUL endroit du projet où ce HTML est écrit : index.php le
   sert au chargement, api.php le renvoie après chaque action AJAX.
   Un seul gabarit = aucune duplication, et tout est déjà échappé par e().
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/fonctions.php';

function carte_html(array $s): string
{
    $statut  = isset(STATUTS[$s['statut']]) ? (string) $s['statut'] : 'cours';
    $tome    = max(0, (int) $s['tome_actuel']);
    $suivant = $tome + 1;

    // « Tome à emprunter » n'a de sens que pour une série qu'on continue.
    // Terminée / Abandonnée : on affiche seulement le dernier tome lu.
    $en_cours = ($statut === 'cours' || $statut === 'envie');

    $couverture = url_image_sure($s['couverture'] ?? '');
    $titre      = (string) $s['titre'];
    $auteur     = (string) ($s['auteur'] ?? '');

    $alt = $en_cours
        ? "Couverture du tome {$suivant} de {$titre}"
        : "Couverture de {$titre} — dernier tome lu {$tome}";

    $image = $couverture !== ''
        ? '<img class="card-cover-img" src="' . e($couverture) . '" alt="' . e($alt) . '" loading="lazy">'
        : '<span class="no-cover" aria-hidden="true">📕</span>';

    $etiquette = $en_cours
        ? '<div class="next-tag">Tome ' . $suivant . ' à emprunter</div>'
        : '';

    $libelle_progression = $en_cours ? 'Vous en êtes au tome' : 'Dernier tome lu';

    return '
      <article class="card status-' . e($statut) . '"
               data-id="' . (int) $s['id'] . '"
               data-statut="' . e($statut) . '"
               data-titre="' . e($titre) . '"
               data-auteur="' . e($auteur) . '"
               data-tome="' . $tome . '"
               data-couverture="' . e($couverture) . '">
        <div class="card-cover" data-action="edit" role="button" tabindex="0" aria-label="Modifier ' . e($titre) . '">
          ' . $image . '
          <span class="badge">' . e(STATUTS[$statut]) . '</span>
          ' . $etiquette . '
        </div>
        <div class="card-body">
          <h3 class="card-title">' . e($titre) . '</h3>
          <p class="card-subtitle">' . e($auteur) . '</p>
          <p class="card-progress">' . $libelle_progression . ' <b>' . $tome . '</b></p>
          <div class="card-actions">
            <button type="button" class="btn-undo" data-action="undo"
                    title="Annuler : revenir au tome précédent"
                    aria-label="Annuler la dernière lecture"' . ($tome <= 0 ? ' disabled' : '') . '>←</button>
            <button type="button" class="btn-advance" data-action="advance"
                    title="Marquer le tome ' . $suivant . ' comme lu"
                    aria-label="Marquer le tome ' . $suivant . ' comme lu">→</button>
            <button type="button" class="btn-edit" data-action="edit"
                    title="Modifier / gérer la couverture" aria-label="Modifier ' . e($titre) . '">✏️</button>
          </div>
        </div>
      </article>';
}

/** Compte des séries par statut, pour les pastilles des filtres. */
function compter_series(PDO $pdo, int $utilisateur_id): array
{
    $c = ['all' => 0, 'cours' => 0, 'envie' => 0, 'termine' => 0, 'abandon' => 0];
    $req = $pdo->prepare('SELECT statut, COUNT(*) AS n FROM serie WHERE utilisateur_id = ? GROUP BY statut');
    $req->execute([$utilisateur_id]);
    foreach ($req->fetchAll() as $ligne) {
        $c[$ligne['statut']] = (int) $ligne['n'];
        $c['all'] += (int) $ligne['n'];
    }
    return $c;
}
