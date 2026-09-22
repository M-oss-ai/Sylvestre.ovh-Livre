<?php
/* =====================================================================
   Page principale : la bibliothèque.
   La grille est rendue par le serveur (donc déjà échappée) ; le
   navigateur ne fait que filtrer, rechercher et appeler api.php.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/carte.php';

$moi = exiger_connexion();

$req = $pdo->prepare(
    'SELECT id, titre, auteur, tome_actuel, statut, couverture, mangadex_id
       FROM serie
      WHERE utilisateur_id = ?
      ORDER BY maj_le DESC, id DESC'
);
$req->execute([(int) $moi['id']]);
$series = $req->fetchAll();

$compte  = compter_series($pdo, (int) $moi['id']);
$photo   = url_image_sure($moi['photo']);
$csrf    = jeton_csrf();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Ma Bibliothèque Manga</title>
<meta name="description" content="Gérez votre collection de mangas : séries, tomes, statuts de lecture.">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%93%9A%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= e(actif('css/style.css')) ?>">
</head>
<body data-csrf="<?= e($csrf) ?>" data-image-max="<?= IMAGE_TAILLE_MAX ?>">

<header class="topbar">
  <div class="topbar-row">
    <h1>📚 Ma Bibliothèque</h1>
    <div class="topbar-actions">
      <button id="btn-add" class="btn btn-primary" type="button">
        <span class="plus">＋</span> Ajouter une série
      </button>
      <a id="btn-account" class="avatar-btn" href="parametres.php" aria-label="Paramètres du compte">
        <?php if ($photo !== ''): ?>
          <img class="avatar-img" src="<?= e($photo) ?>" alt="">
        <?php else: ?>
          <span class="avatar-initials"><?= e(initiales($moi)) ?></span>
        <?php endif; ?>
      </a>
    </div>
  </div>

  <div class="search-row">
    <div class="search-wrap">
      <span class="search-icon" aria-hidden="true">🔎</span>
      <input id="search" type="search" placeholder="Rechercher un titre, un auteur…" autocomplete="off" aria-label="Rechercher">
    </div>
  </div>

  <nav class="filters" id="filters" aria-label="Filtrer par statut">
    <button class="filter-btn active" data-filter="all" type="button" aria-pressed="true">Toutes<span class="count" id="count-all"><?= (int) $compte['all'] ?></span></button>
    <button class="filter-btn status-cours" data-filter="cours" type="button" aria-pressed="false">En cours<span class="count" id="count-cours"><?= (int) $compte['cours'] ?></span></button>
    <button class="filter-btn status-envie" data-filter="envie" type="button" aria-pressed="false">Envie<span class="count" id="count-envie"><?= (int) $compte['envie'] ?></span></button>
    <button class="filter-btn status-termine" data-filter="termine" type="button" aria-pressed="false">Terminée<span class="count" id="count-termine"><?= (int) $compte['termine'] ?></span></button>
    <button class="filter-btn status-abandon" data-filter="abandon" type="button" aria-pressed="false">Abandonnée<span class="count" id="count-abandon"><?= (int) $compte['abandon'] ?></span></button>
  </nav>
</header>

<main>
  <div id="grid" class="grid<?= $series ? '' : ' hidden' ?>"><?php
    foreach ($series as $s) {
        echo carte_html($s);
    }
  ?></div>

  <div id="empty-collection" class="empty-state<?= $series ? ' hidden' : '' ?>">
    <p class="empty-emoji">📖</p>
    <p>Votre bibliothèque est vide pour l'instant.</p>
    <button class="btn btn-primary" id="btn-add-first" type="button">Ajouter votre première série</button>
  </div>

  <div id="empty-search" class="empty-state hidden">
    <p class="empty-emoji">🔍</p>
    <p>Aucune série ne correspond à votre recherche.</p>
  </div>
</main>

<!-- Modale ajout / édition -->
<div id="overlay" class="overlay hidden">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="modal-head">
      <button id="btn-close" class="icon-btn" type="button" aria-label="Fermer">✕</button>
      <h2 id="modal-title">Nouvelle série</h2>
      <!-- Hors du <form>, d'où l'attribut « form » : il suffit à en faire
           le bouton d'envoi. Caché sur grand écran, où celui du bas est
           tout de suite visible ; sur téléphone il prend sa place, parce
           que les résultats de recherche repoussent le bas de la modale
           hors de vue. -->
      <button id="btn-save-top" class="icon-btn icon-btn-valider" type="submit"
              form="series-form" aria-label="Enregistrer" title="Enregistrer">✓</button>
    </div>

    <form id="series-form" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="id" id="f-id" value="">

      <div class="field">
        <label for="f-title">Titre *</label>
        <input id="f-title" name="titre" type="text" required maxlength="190" placeholder="Ex. One Piece">
      </div>

      <div class="field">
        <label for="f-subtitle">Sous-titre / auteur</label>
        <input id="f-subtitle" name="auteur" type="text" maxlength="190" placeholder="Ex. Eiichiro Oda">
      </div>

      <div class="field-row">
        <div class="field">
          <label for="f-volume">Tome actuel</label>
          <input id="f-volume" name="tome_actuel" type="number" min="0" max="<?= TOME_MAX ?>" step="1" value="0" inputmode="numeric">
        </div>
        <div class="field">
          <label for="f-status">Statut</label>
          <select id="f-status" name="statut">
            <?php foreach (STATUTS as $cle => $libelle): ?>
              <option value="<?= e($cle) ?>"><?= e($libelle) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="field">
        <span class="field-label">Couverture du prochain tome à emprunter</span>
        <div id="dropzone" class="dropzone" tabindex="0" role="button" aria-label="Déposer ou choisir une image">
          <img id="cover-preview" class="cover-preview hidden" alt="Aperçu de la couverture">
          <div id="cover-placeholder" class="cover-placeholder">
            <span class="cover-icon" aria-hidden="true">🖼️</span>
            <span class="indice-souris">Glissez-déposez une image ici<br>ou utilisez les options ci-dessous</span>
            <span class="indice-tactile">Appuyez ici pour choisir une photo</span>
          </div>
        </div>

        <div class="cover-actions">
          <input id="f-image-url" name="couverture_url" type="url" placeholder="Coller une URL d'image…" inputmode="url" maxlength="500">
          <input type="hidden" name="couverture_retiree" id="f-cover-removed" value="0">
          <div class="cover-actions-buttons">
            <button type="button" id="btn-search-cover" class="btn btn-ghost small">🔎 Recherche auto</button>
            <label class="btn btn-ghost small file-label">
              📁 Choisir un fichier
              <input id="f-image-file" name="couverture_fichier" type="file" accept="image/*" class="visually-hidden">
            </label>
            <button type="button" id="btn-cover-linked" class="btn btn-ghost small hidden">🖼️ Image MangaDex</button>
            <button type="button" id="btn-remove-cover" class="btn btn-ghost small danger-text">Retirer l'image</button>
          </div>
        </div>
        <p id="cover-status" class="hint" aria-live="polite"></p>

        <div id="cover-results" class="cover-results hidden"></div>
      </div>

      <div class="modal-actions">
        <button type="button" id="btn-delete" class="btn btn-danger hidden">Supprimer la série</button>
        <div class="grow"></div>
        <button type="button" id="btn-cancel" class="btn btn-ghost">Annuler</button>
        <button type="submit" class="btn btn-primary">Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<div id="confirm-overlay" class="overlay hidden">
  <div class="modal small" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title">
    <h2 id="confirm-title">Supprimer cette série ?</h2>
    <p id="confirm-text" class="hint">Cette action est définitive.</p>
    <div class="modal-actions">
      <div class="grow"></div>
      <button type="button" id="confirm-cancel" class="btn btn-ghost">Annuler</button>
      <button type="button" id="confirm-ok" class="btn btn-danger">Supprimer</button>
    </div>
  </div>
</div>

<footer class="app-footer">
  Connecté en tant que <b><?= e($moi['identifiant']) ?></b>
  <span class="sep">·</span>
  <a href="parametres.php">Paramètres</a>
  <span class="sep">·</span>
  <a href="mentions-legales.php">Mentions légales</a>
</footer>

<div id="toast" class="toast hidden" role="status"></div>

<script src="<?= e(actif('js/delai.js')) ?>" defer></script>
<script src="<?= e(actif('js/commun.js')) ?>" defer></script>
<script src="<?= e(actif('js/app.js')) ?>" defer></script>
</body>
</html>
