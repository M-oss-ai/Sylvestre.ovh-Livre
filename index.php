<?php
/* =====================================================================
   Page principale : la bibliothèque.
   La grille est rendue par le serveur (donc déjà échappée) ; le
   navigateur ne fait que filtrer, rechercher et appeler api.php.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/carte.php';
require_once __DIR__ . '/includes/couvertures.php';   // le quota de recherche, annoncé dans la fiche

$moi = exiger_connexion();

$req = $pdo->prepare(
    'SELECT id, titre, auteur, tome_actuel, statut, couverture, mangadex_id, favori
       FROM serie
      WHERE utilisateur_id = ?
      ORDER BY maj_le DESC, id DESC'
);
$req->execute([(int) $moi['id']]);
$series = $req->fetchAll();

$compte  = compter_series($pdo, (int) $moi['id']);
$photo   = url_image_sure($moi['photo']);
$csrf    = jeton_csrf();

/* Les deux limites du compte, annoncées AVANT qu'on bute dessus : la
   limite de séries ne se découvrait qu'une fiche entièrement remplie, et
   celle des recherches de couverture nulle part. 0 = pas de limite. */
$quota_series    = $moi['forfait'] === 'illimite' ? 0 : MAX_SERIES_PAR_UTILISATEUR;
$quota_recherche = couverture_quota($moi);
$tranche         = couverture_tranche_lisible(COUVERTURE_FENETRE);
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
<body data-csrf="<?= e($csrf) ?>" data-image-max="<?= IMAGE_TAILLE_MAX ?>"
      data-quota-series="<?= (int) $quota_series ?>"
      data-quota-recherche="<?= (int) $quota_recherche ?>" data-tranche-recherche="<?= e($tranche) ?>"
      data-prive="1">

<!-- Premier arrêt au clavier : sans lui, atteindre la première série
     demandait de traverser tout l'en-tête et les filtres. -->
<a class="lien-evitement" href="#grid" id="lien-evitement">Aller aux séries</a>

<!-- Seuls le titre et la recherche restent collés en haut de l'écran. Les
     filtres ont quitté la barre fixe : ils y mangeaient jusqu'au tiers de
     l'écran d'un téléphone, pour un réglage qu'on pose une fois puis
     qu'on oublie. Ils reviennent sous elle dès qu'on remonte (voir
     .barre-filtres). -->
<header class="topbar">
  <div class="topbar-row">
    <h1>📚 Ma Bibliothèque</h1>
    <a id="btn-account" class="avatar-btn" href="parametres.php" aria-label="Paramètres du compte">
      <?php if ($photo !== ''): ?>
        <img class="avatar-img" src="<?= e($photo) ?>" alt="">
      <?php else: ?>
        <span class="avatar-initials"><?= e(initiales($moi)) ?></span>
      <?php endif; ?>
    </a>
  </div>

  <div class="search-row">
    <div class="search-wrap">
      <span class="search-icon" aria-hidden="true">🔎</span>
      <!-- Texte indicatif court : le champ partage désormais sa rangée avec
           le « ＋ », et la version longue était coupée sur téléphone. -->
      <input id="search" type="search" placeholder="Titre ou auteur…" autocomplete="off" aria-label="Rechercher un titre ou un auteur">
    </div>
    <!-- Un « + » à côté de la recherche plutôt qu'un bouton pleine
         largeur : sur téléphone, celui-ci occupait une rangée entière de
         la barre fixe. Le libellé reste là pour les lecteurs d'écran et au
         survol. -->
    <button id="btn-add" class="btn btn-primary btn-ajout" type="button"
            aria-label="Ajouter une série" title="Ajouter une série"><span aria-hidden="true">＋</span></button>
  </div>
</header>

<main class="bibliotheque">
  <!-- Les deux rangées de filtres, collées sous la barre du haut : elles
       s'effacent derrière elle quand on descend et reviennent dès qu'on
       remonte, sans devoir retourner en haut de la page (js/app.js).
       Doit rester le PREMIER élément de <main> : c'est ce qui les fait se
       coller dès le premier pixel de défilement. -->
  <div class="barre-filtres" id="barre-filtres">
  <!-- Les statuts se cumulent : cliquer « En cours » puis « Envie » montre
       les deux. « Toutes » n'est pas un filtre de plus, c'est leur remise
       à zéro — d'où son data-filter particulier. -->
  <nav class="filters" id="filters" aria-label="Filtrer">
    <button class="filter-btn active" data-filter="all" type="button" aria-pressed="true">Toutes<span class="count" id="count-all"><?= (int) $compte['all'] ?></span></button>
    <button class="filter-btn status-cours" data-filter="cours" type="button" aria-pressed="false">En cours<span class="count" id="count-cours"><?= (int) $compte['cours'] ?></span></button>
    <button class="filter-btn status-envie" data-filter="envie" type="button" aria-pressed="false">Envie<span class="count" id="count-envie"><?= (int) $compte['envie'] ?></span></button>
    <button class="filter-btn status-termine" data-filter="termine" type="button" aria-pressed="false">Terminée<span class="count" id="count-termine"><?= (int) $compte['termine'] ?></span></button>
    <button class="filter-btn status-abandon" data-filter="abandon" type="button" aria-pressed="false">Abandonnée<span class="count" id="count-abandon"><?= (int) $compte['abandon'] ?></span></button>
    <button class="filter-btn filter-favori" id="btn-filtre-favori" data-favori="1" type="button" aria-pressed="false">★ Favoris<span class="count" id="count-favori"><?= (int) $compte['favori'] ?></span></button>
    <button class="filter-btn filter-plus" id="btn-filtres-plus" type="button"
            aria-expanded="false" aria-controls="filtres-image">Image ▾</button>
  </nav>

  <!-- Le type d'image est replié par défaut : c'est un filtre qu'on sort
       pour faire le ménage (« lesquelles n'ont pas de couverture ? »),
       pas un réglage du quotidien. -->
  <div class="filters filters-image hidden" id="filtres-image" aria-label="Filtrer par type d'image">
    <?php foreach (IMAGES_TYPES as $cle => $libelle): ?>
      <button class="filter-btn" data-image="<?= e($cle) ?>" type="button" aria-pressed="false"><?= e($libelle) ?></button>
    <?php endforeach; ?>
  </div>
  </div>

  <!-- La place restante, annoncée à l'approche de la limite (voir
       majQuota dans js/app.js) : elle ne se découvrait qu'au moment
       d'enregistrer une fiche entièrement remplie. -->
  <p id="quota-series" class="quota-series hidden" role="status"></p>

  <!-- Au clavier, la grille ne compte qu'UN arrêt : on y entre par Tab,
       les flèches passent d'une série à l'autre (voir js/app.js). Chaque
       carte en ajoutait cinq, soit 780 appuis pour traverser 150 séries.
       Le mode d'emploi est lu à l'entrée dans la grille. -->
  <p id="grille-aide" class="visually-hidden">Flèches pour passer d'une série à l'autre, Tab pour ses boutons.</p>
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

    <form id="series-form" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="id" id="f-id" value="">

      <!-- Les erreurs qui ne tiennent à aucun champ (limite de séries…).
           Dans la fiche et en haut, pas dans une notification de deux
           secondes posée sur les boutons. -->
      <p id="form-erreur" class="erreur-form hidden" role="alert" tabindex="-1"></p>

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
          <!-- Une étiquette, et pas seulement un texte indicatif : celui-ci
               disparaît à la première lettre, et un lecteur d'écran
               n'annonçait rien. -->
          <label for="f-image-url" class="sous-label">Adresse d'une image (https://…)</label>
          <input id="f-image-url" name="couverture_url" type="url" placeholder="https://…" inputmode="url" maxlength="500">
          <input type="hidden" name="couverture_retiree" id="f-cover-removed" value="0">
          <div class="cover-actions-buttons">
            <button type="button" id="btn-search-cover" class="btn btn-ghost small">🔎 Recherche auto</button>
            <label class="btn btn-ghost small file-label">
              📁 Choisir un fichier
              <input id="f-image-file" name="couverture_fichier" type="file" accept="image/*" class="visually-hidden">
            </label>
            <button type="button" id="btn-cover-linked" class="btn btn-ghost small hidden">🖼️ Image MangaDex</button>
            <button type="button" id="btn-cover-unlink" class="btn btn-ghost small hidden" title="Garde cette image, mais arrête de la faire suivre automatiquement les tomes">🔓 Délier de MangaDex</button>
            <button type="button" id="btn-remove-cover" class="btn btn-ghost small danger-text">Retirer l'image</button>
          </div>
        </div>
        <p id="cover-status" class="hint" aria-live="polite"></p>
        <!-- La règle est dite AVANT la première recherche, puis le solde
             après chacune (voir chercherCouverture dans js/app.js). -->
        <p id="quota-recherche" class="hint quota-recherche">
          Recherche auto : <?= (int) $quota_recherche ?> recherches toutes les <?= e($tranche) ?>.
        </p>

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

<?php if ($quota_series > 0): ?>
<!-- Bibliothèque pleine : « ＋ » ouvre cette explication au lieu d'une
     fiche qu'on remplirait pour rien. -->
<div id="quota-overlay" class="overlay hidden">
  <div class="modal small" role="alertdialog" aria-modal="true" aria-labelledby="quota-titre" aria-describedby="quota-texte">
    <h2 id="quota-titre">Bibliothèque pleine</h2>
    <p id="quota-texte" class="hint">
      Le forfait standard permet <?= (int) $quota_series ?> séries, et elles sont toutes utilisées.
      Pour en ajouter une, supprimez-en une autre, ou demandez le forfait illimité à
      l'administrateur : <a href="mailto:<?= e(ADMIN_EMAIL) ?>"><?= e(ADMIN_EMAIL) ?></a>.
    </p>
    <div class="modal-actions">
      <div class="grow"></div>
      <button type="button" id="quota-ok" class="btn btn-primary">Compris</button>
    </div>
  </div>
</div>
<?php endif; ?>

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
