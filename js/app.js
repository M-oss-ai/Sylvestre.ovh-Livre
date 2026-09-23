/* =========================================================
   Ma Bibliothèque Manga — page principale (index.php)
   Le serveur fabrique les cartes (HTML déjà échappé), le
   navigateur se contente de filtrer, rechercher et appeler
   api.php. Aucune donnée n'est injectée en innerHTML ici :
   on insère uniquement le HTML renvoyé par carte.php.
   ========================================================= */

(() => {
  "use strict";

  const L = window.Lib;

  const $grid = document.getElementById("grid");
  const $emptyCollection = document.getElementById("empty-collection");
  const $emptySearch = document.getElementById("empty-search");
  const $search = document.getElementById("search");
  const $filtresImage = document.getElementById("filtres-image");
  const $btnFiltreFavori = document.getElementById("btn-filtre-favori");
  const $btnFiltresPlus = document.getElementById("btn-filtres-plus");
  const $filters = document.getElementById("filters");

  /* Les filtres se CUMULENT, et se conservent d'une visite à l'autre.
     Un ensemble vide veut dire « aucun filtre de ce genre », ce qui est
     plus simple qu'une valeur « toutes » à traiter à part. */
  const filtresStatut = new Set();
  const filtresImage = new Set();
  let favorisSeuls = false;
  let imageOuvert = false;
  let recherche = "";
  // La grille est-elle actuellement rangée par pertinence plutôt que
  // dans l'ordre du serveur ? Voir appliquerVue().
  let ordreBouscule = false;
  /* Rang d'origine des séries créées depuis le chargement. Négatif et
     décroissant : elles se placent en tête, la plus récente devant. */
  let ordreNouveau = -1;
  // Série MangaDex de la fiche ouverte, ou "" si elle n'est pas liée.
  let lienMangadex = "";
  let coverEnAttente = null; // null | {type:'url'|'file', …}
  let idEnEdition = "";
  let idASupprimer = "";

  /* ---------------- Affichage : filtres + recherche ---------------- */

  /**
   * La chaîne de recherche d'une carte est normalisée UNE fois (accents
   * retirés, minuscules) et conservée sur l'élément. Sans ce cache, chaque
   * caractère tapé re-normalisait toutes les cartes — le plus gros du coût,
   * avant même le calcul des distances.
   */
  function indexer(carte) {
    // Construit depuis data-titre et data-auteur, qui sont déjà là pour
    // remplir la modale d'édition. Un attribut data-recherche séparé
    // répétait ces deux valeurs dans le HTML de chaque carte, pour rien.
    //
    // Les deux champs sont gardés séparément : le classement par
    // pertinence a besoin de savoir si c'est le TITRE qui répond à la
    // recherche, ou seulement l'auteur.
    carte._titre = L.normalize(carte.dataset.titre || "");
    carte._auteur = L.normalize(carte.dataset.auteur || "");
    carte._recherche = carte._titre + " " + carte._auteur;
    return carte;
  }

  /**
   * À quel point cette carte répond-elle à la recherche ? Plus bas, plus
   * pertinent.
   *
   * Sans ce classement, une recherche rendait les cartes dans l'ordre de
   * la grille — la plus récemment modifiée d'abord — si bien qu'une
   * série trouvée par son auteur pouvait précéder celle dont le titre
   * est exactement ce qu'on a tapé.
   */
  function pertinence(carte, q) {
    if (carte._titre === q) return 0;
    if (carte._titre.startsWith(q)) return 1;
    if (carte._titre.includes(q)) return 2;
    if (carte._auteur.includes(q)) return 3;
    return 4; // trouvée seulement par tolérance aux fautes de frappe
  }

  function cartes() {
    return Array.from($grid.querySelectorAll(".card"));
  }

  function appliquerVue() {
    const toutes = cartes();
    const prep = L.prepareRecherche(recherche);
    let visibles = 0;

    /* Tant qu'aucune recherche n'a bousculé la grille, l'ordre du DOM EST
       l'ordre d'origine (le plus récemment modifié d'abord, trié par le
       serveur). On le note au passage : c'est lui qu'on restituera, et
       c'est aussi ce qui donne sa place à une carte ajoutée entre-temps. */
    if (!ordreBouscule) {
      toutes.forEach((c, i) => { c._ordre = i; });
    }

    toutes.forEach((c) => {
      if (c._recherche === undefined) indexer(c);
      const okStatut = filtresStatut.size === 0 || filtresStatut.has(c.dataset.statut);
      const okImage = filtresImage.size === 0 || filtresImage.has(c.dataset.image);
      const okFavori = !favorisSeuls || c.dataset.favori === "1";
      const okRecherche = !prep.q || L.correspondPrepare(c._recherche, prep);
      const visible = okStatut && okImage && okFavori && okRecherche;
      c.classList.toggle("hidden", !visible);
      if (visible) visibles++;
    });

    if (prep.q) {
      /* Les ex aequo gardent leur ordre d'origine : à pertinence égale,
         la série modifiée en dernier reste devant. */
      toutes
        .filter((c) => !c.classList.contains("hidden"))
        .map((c) => [pertinence(c, prep.q), c._ordre, c])
        .sort((a, b) => a[0] - b[0] || a[1] - b[1])
        .forEach(([, , c]) => $grid.appendChild(c));
      ordreBouscule = true;
    } else if (ordreBouscule) {
      // Recherche effacée : la grille retrouve exactement l'ordre du serveur.
      toutes
        .slice()
        .sort((a, b) => a._ordre - b._ordre)
        .forEach((c) => $grid.appendChild(c));
      ordreBouscule = false;
    }

    $emptyCollection.classList.toggle("hidden", toutes.length !== 0);
    $emptySearch.classList.toggle("hidden", !(toutes.length > 0 && visibles === 0));
    $grid.classList.toggle("hidden", visibles === 0);
  }

  function majCompteurs(compte) {
    if (!compte) return;
    Object.entries(compte).forEach(([cle, valeur]) => {
      const el = document.getElementById("count-" + cle);
      if (el) el.textContent = valeur;
    });
  }

  /**
   * Remplace (ou ajoute) une carte à partir du HTML renvoyé par le serveur.
   *
   * L'ordre affiché — du plus récemment modifié au plus ancien — vient du
   * tri serveur au chargement de la page. Les deux cas n'appellent pas le
   * même traitement :
   *
   *   - une série MODIFIÉE garde sa place. La voir sauter ailleurs pendant
   *     qu'on vient de la changer est désagréable, et on la perd des yeux ;
   *   - une série NOUVELLE se met en TÊTE. Ajoutée en bas d'une liste de
   *     cent cinquante, il fallait recharger la page pour la retrouver.
   */
  function poserCarte(html, id) {
    const gabarit = document.createElement("div");
    gabarit.innerHTML = html.trim(); // HTML produit et échappé par carte.php
    const nouvelle = gabarit.firstElementChild;
    if (!nouvelle) return;
    indexer(nouvelle);

    const ancienne = $grid.querySelector('.card[data-id="' + CSS.escape(String(id)) + '"]');
    if (ancienne) {
      // Une carte remplacée occupe la place de celle qu'elle remplace,
      // y compris dans l'ordre d'origine mémorisé.
      nouvelle._ordre = ancienne._ordre;
      ancienne.replaceWith(nouvelle);
    } else {
      // En tête, et son rang la garde en tête quand une recherche est
      // effacée — sans quoi elle repartirait se cacher en bas de liste.
      nouvelle._ordre = ordreNouveau--;
      $grid.prepend(nouvelle);
    }

    // Le fondu ne s'applique qu'ici, et une seule fois : la classe est
    // retirée dès l'animation finie, sinon « content-visibility » la
    // rejouerait à chaque fois que la carte repasse devant l'écran.
    nouvelle.classList.add("card-nouvelle");
    nouvelle.addEventListener(
      "animationend",
      () => nouvelle.classList.remove("card-nouvelle"),
      { once: true }
    );

    appliquerVue();
  }

  /* ---------------- Actions sur une carte ---------------- */

  $grid.addEventListener("click", (e) => {
    const cible = e.target.closest("[data-action]");
    if (!cible) return;
    const carte = e.target.closest(".card");
    if (!carte) return;

    const id = carte.dataset.id;
    const action = cible.dataset.action;

    if (action === "advance") avancer(id, cible);
    else if (action === "undo") reculer(id, cible);
    else if (action === "favori") basculerFavori(id, cible);
    else if (action === "edit") ouvrirModale(carte);
  });

  $grid.addEventListener("keydown", (e) => {
    if (e.key !== "Enter" && e.key !== " ") return;
    const cover = e.target.closest(".card-cover");
    if (!cover) return;
    e.preventDefault();
    ouvrirModale(e.target.closest(".card"));
  });

  /**
   * Une série liée à MangaDex suit son tome : dès qu'il change, on va
   * chercher la couverture du nouveau tome.
   *
   * En arrière-plan et sans rien bloquer — le tome, lui, a déjà changé
   * sous les yeux. Et en silence : si le tome n'a pas de couverture
   * chez MangaDex, ou si la file d'attente est pleine, l'image en place
   * reste, ce qui vaut mieux qu'un message pour une action que
   * personne n'a demandée.
   */
  function rafraichirCouverture(id) {
    const carte = $grid.querySelector('.card[data-id="' + CSS.escape(String(id)) + '"]');
    if (!carte || !carte.dataset.mangadex) return;

    L.api("couverture.rafraichir", { id })
      .then((r) => poserCarte(r.carte, id))
      .catch(() => {});
  }

  /**
   * Met la série en favori, ou l'en retire.
   *
   * La valeur finale vient du serveur et n'est pas devinée ici : deux
   * frappes rapides sur l'étoile ne peuvent donc pas laisser l'affichage
   * et la base en désaccord.
   */
  async function basculerFavori(id, bouton) {
    bouton.disabled = true;
    try {
      const r = await L.api("serie.favori", { id });
      poserCarte(r.carte, id);
      L.toast(r.message);
      /* Le filtre « Favoris » peut faire disparaître la carte qu'on
         vient de retirer : c'est cohérent, et le message l'explique. */
      appliquerVue();
    } catch (err) {
      bouton.disabled = false;
      L.toast(err.message);
    }
  }

  async function avancer(id, bouton) {
    bouton.disabled = true;
    try {
      const r = await L.api("serie.avancer", { id });
      poserCarte(r.carte, id);
      majCompteurs(r.compte);
      L.toast(r.message);
      rafraichirCouverture(id);
    } catch (err) {
      bouton.disabled = false;
      L.toast(err.message);
    }
  }

  async function reculer(id, bouton) {
    bouton.disabled = true;
    try {
      const r = await L.api("serie.reculer", { id });
      poserCarte(r.carte, id);
      majCompteurs(r.compte);
      L.toast(r.message);
      // Reculer aussi : la couverture redescend avec le tome.
      rafraichirCouverture(id);
    } catch (err) {
      bouton.disabled = false;
      L.toast(err.message);
    }
  }

  /* ---------------- Filtres / recherche ---------------- */

  /* Les filtres survivent au rechargement : c'est le sens d'un filtre
     qu'on pose pour faire le tri dans cent cinquante séries.

     localStorage peut lever — navigation privée, stockage bloqué — et
     peut revenir vide. La page doit donc s'afficher correctement sans
     lui, d'où les deux try/catch et le repli sur « aucun filtre », qui
     est le bon défaut. */
  const CLE_FILTRES = "livre.filtres";

  function lireFiltres() {
    try {
      const brut = localStorage.getItem(CLE_FILTRES);
      if (!brut) return;
      const f = JSON.parse(brut) || {};
      (Array.isArray(f.statut) ? f.statut : []).forEach((v) => filtresStatut.add(v));
      (Array.isArray(f.image) ? f.image : []).forEach((v) => filtresImage.add(v));
      favorisSeuls = !!f.favoris;
      imageOuvert = !!f.imageOuvert;
    } catch (e) {
      /* Illisible ou indisponible : on repart sans filtre. */
    }
  }

  function ecrireFiltres() {
    try {
      localStorage.setItem(CLE_FILTRES, JSON.stringify({
        statut: [...filtresStatut],
        image: [...filtresImage],
        favoris: favorisSeuls,
        imageOuvert,
      }));
    } catch (e) {
      /* Sans mémoire, les filtres ne valent que pour cette visite. */
    }
  }

  /** Met les boutons au diapason de l'état. */
  function refleterFiltres() {
    $filters.querySelectorAll(".filter-btn[data-filter]").forEach((b) => {
      const cle = b.dataset.filter;
      // « Toutes » s'allume quand aucun statut n'est retenu.
      const actif = cle === "all" ? filtresStatut.size === 0 : filtresStatut.has(cle);
      b.classList.toggle("active", actif);
      b.setAttribute("aria-pressed", actif ? "true" : "false");
    });

    $filtresImage.querySelectorAll(".filter-btn[data-image]").forEach((b) => {
      const actif = filtresImage.has(b.dataset.image);
      b.classList.toggle("active", actif);
      b.setAttribute("aria-pressed", actif ? "true" : "false");
    });

    $btnFiltreFavori.classList.toggle("active", favorisSeuls);
    $btnFiltreFavori.setAttribute("aria-pressed", favorisSeuls ? "true" : "false");

    $filtresImage.classList.toggle("hidden", !imageOuvert);
    $btnFiltresPlus.setAttribute("aria-expanded", imageOuvert ? "true" : "false");
    /* Une pastille quand un filtre d'image est actif mais replié : sans
       elle on cherche longtemps pourquoi la liste est si courte. */
    $btnFiltresPlus.classList.toggle("a-un-filtre", filtresImage.size > 0);
  }

  function basculer(ensemble, valeur) {
    if (ensemble.has(valeur)) ensemble.delete(valeur);
    else ensemble.add(valeur);
  }

  function filtresChanges() {
    refleterFiltres();
    ecrireFiltres();
    appliquerVue();
  }

  $filters.addEventListener("click", (e) => {
    const btn = e.target.closest(".filter-btn");
    if (!btn) return;

    if (btn === $btnFiltresPlus) {
      imageOuvert = !imageOuvert;
    } else if (btn === $btnFiltreFavori) {
      favorisSeuls = !favorisSeuls;
    } else if (btn.dataset.filter === "all") {
      // « Toutes » n'est pas un filtre de plus : c'est leur remise à zéro.
      filtresStatut.clear();
    } else if (btn.dataset.filter) {
      basculer(filtresStatut, btn.dataset.filter);
    } else {
      return;
    }
    filtresChanges();
  });

  $filtresImage.addEventListener("click", (e) => {
    const btn = e.target.closest(".filter-btn[data-image]");
    if (!btn) return;
    basculer(filtresImage, btn.dataset.image);
    filtresChanges();
  });

  // Débouncé : on attend une courte pause dans la frappe avant de
  // recalculer, au lieu de tout refiltrer à chaque caractère.
  const relancerRecherche = L.debounce(() => {
    recherche = $search.value;
    appliquerVue();
  }, 150);
  $search.addEventListener("input", relancerRecherche);

  /* ---------------- Modale ajout / édition ---------------- */

  const $overlay = document.getElementById("overlay");
  const $modalTitle = document.getElementById("modal-title");
  const $form = document.getElementById("series-form");
  const $fId = document.getElementById("f-id");
  const $fTitle = document.getElementById("f-title");
  const $fSubtitle = document.getElementById("f-subtitle");
  const $fVolume = document.getElementById("f-volume");
  const $fStatus = document.getElementById("f-status");
  const $btnCoverLinked = document.getElementById("btn-cover-linked");
  const $fImageUrl = document.getElementById("f-image-url");
  const $fImageFile = document.getElementById("f-image-file");
  const $fCoverRemoved = document.getElementById("f-cover-removed");
  const $coverPreview = document.getElementById("cover-preview");
  const $coverPlaceholder = document.getElementById("cover-placeholder");
  const $coverStatus = document.getElementById("cover-status");
  const $coverResults = document.getElementById("cover-results");
  const $dropzone = document.getElementById("dropzone");
  const $btnDelete = document.getElementById("btn-delete");
  /* Il y a DEUX boutons d'envoi : celui du bas, et celui de la barre
     fixe sur téléphone. « form.elements » les rassemble tous les deux,
     y compris celui qui vit HORS du <form> et n'y est rattaché que par
     son attribut « form ». Les désactiver ensemble pendant l'envoi
     évite qu'une double frappe enregistre la série deux fois. */
  const $boutonsEnvoi = Array.from($form.elements).filter((el) => el.type === "submit");
  const envoiEnCours = (oui) => $boutonsEnvoi.forEach((b) => { b.disabled = oui; });

  // Élément qui avait le focus avant l'ouverture d'une modale : on le lui
  // rend à la fermeture, sinon la navigation au clavier repart du haut de
  // la page à chaque fois.
  let focusAvantModale = null;

  function ouvrirModale(carte) {
    const edition = !!carte;
    idEnEdition = edition ? carte.dataset.id : "";
    focusAvantModale = document.activeElement;

    $modalTitle.textContent = edition ? "Modifier la série" : "Nouvelle série";
    $fId.value = idEnEdition;
    $fTitle.value = edition ? carte.dataset.titre : "";
    $fSubtitle.value = edition ? carte.dataset.auteur : "";
    $fVolume.value = edition ? carte.dataset.tome : "0";
    $fStatus.value = edition ? carte.dataset.statut : "cours";
    $fImageFile.value = "";
    $fCoverRemoved.value = "0";

    const couverture = edition ? carte.dataset.couverture : "";
    coverEnAttente = couverture ? { type: "url", value: couverture, existante: true } : null;
    $fImageUrl.value = couverture && /^https:\/\//i.test(couverture) ? couverture : "";

    $btnDelete.classList.toggle("hidden", !edition);

    /* Le bouton n'a de sens que pour une série déjà désignée chez
       MangaDex : ailleurs il n'aurait nulle part où aller chercher. */
    lienMangadex = edition ? carte.dataset.mangadex || "" : "";
    $btnCoverLinked.classList.toggle("hidden", lienMangadex === "");
    $coverStatus.textContent = "";
    $coverResults.classList.add("hidden");
    $coverResults.replaceChildren();

    majApercu();
    $overlay.classList.remove("hidden");
    /* Pas de focus automatique sur un ecran tactile : il ouvre le clavier,
       qui recouvre aussitot l'apercu de la couverture — precisement ce qu'on
       vient d'ouvrir la fenetre pour regarder. Au clavier physique, donner le
       focus reste le bon comportement. */
    if (!window.matchMedia("(pointer: coarse)").matches) $fTitle.focus();
  }

  function fermerModale() {
    $overlay.classList.add("hidden");
    $form.reset();
    idEnEdition = "";
    coverEnAttente = null;
    if (focusAvantModale && focusAvantModale.focus) focusAvantModale.focus();
    focusAvantModale = null;
  }

  function majApercu() {
    const src = coverEnAttente
      ? coverEnAttente.type === "file"
        ? coverEnAttente.apercu
        : coverEnAttente.value
      : "";
    if (src) {
      $coverPreview.src = src;
      $coverPreview.classList.remove("hidden");
      $coverPlaceholder.classList.add("hidden");
    } else {
      $coverPreview.classList.add("hidden");
      $coverPreview.removeAttribute("src");
      $coverPlaceholder.classList.remove("hidden");
    }
  }

  document.getElementById("btn-add").addEventListener("click", () => ouvrirModale(null));
  document.getElementById("btn-add-first").addEventListener("click", () => ouvrirModale(null));
  document.getElementById("btn-close").addEventListener("click", fermerModale);
  document.getElementById("btn-cancel").addEventListener("click", fermerModale);
  $overlay.addEventListener("click", (e) => { if (e.target === $overlay) fermerModale(); });

  /* ---------------- Enregistrement ---------------- */

  $form.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (!$fTitle.value.trim()) { $fTitle.focus(); return; }

    const fd = new FormData();
    fd.set("id", $fId.value || "0");
    fd.set("titre", $fTitle.value);
    fd.set("auteur", $fSubtitle.value);
    fd.set("tome_actuel", $fVolume.value || "0");
    fd.set("statut", $fStatus.value);

    if (coverEnAttente && coverEnAttente.type === "file") {
      fd.set("couverture_fichier", coverEnAttente.file);
    } else if (coverEnAttente && coverEnAttente.type === "url" && !coverEnAttente.existante) {
      fd.set("couverture_url", coverEnAttente.value);
    } else if (!coverEnAttente) {
      fd.set("couverture_retiree", "1");
    }

    envoiEnCours(true);
    try {
      const r = await L.api("serie.enregistrer", fd);
      poserCarte(r.carte, r.id);
      /* La carte doit passer par le filtre et la recherche en cours,
         comme les autres : sans cela elle s'afficherait même sous un
         filtre qui l'exclut. */
      appliquerVue();
      majCompteurs(r.compte);
      L.toast(r.message);
      fermerModale();
    } catch (err) {
      L.toast(err.message);
    } finally {
      envoiEnCours(false);
    }
  });

  /* ---------------- Suppression ---------------- */

  const $confirmOverlay = document.getElementById("confirm-overlay");
  const $confirmText = document.getElementById("confirm-text");

  $btnDelete.addEventListener("click", () => {
    if (!idEnEdition) return;
    idASupprimer = idEnEdition;
    $confirmText.textContent = "« " + $fTitle.value + " » sera définitivement supprimée.";
    $confirmOverlay.classList.remove("hidden");
    document.getElementById("confirm-cancel").focus();
  });

  function fermerConfirmation() {
    $confirmOverlay.classList.add("hidden");
    idASupprimer = "";
  }

  document.getElementById("confirm-cancel").addEventListener("click", fermerConfirmation);
  $confirmOverlay.addEventListener("click", (e) => { if (e.target === $confirmOverlay) fermerConfirmation(); });

  document.getElementById("confirm-ok").addEventListener("click", async () => {
    if (!idASupprimer) return;
    const id = idASupprimer;
    try {
      const r = await L.api("serie.supprimer", { id });
      const carte = $grid.querySelector('.card[data-id="' + CSS.escape(String(id)) + '"]');
      if (carte) carte.remove();
      majCompteurs(r.compte);
      L.toast(r.message);
    } catch (err) {
      L.toast(err.message);
    }
    fermerConfirmation();
    fermerModale();
    appliquerVue();
  });

  document.addEventListener("keydown", (e) => {
    const confirmOuverte = !$confirmOverlay.classList.contains("hidden");
    const modaleOuverte = !$overlay.classList.contains("hidden");

    if (e.key === "Tab") {
      if (confirmOuverte) L.piegerFocus($confirmOverlay, e);
      else if (modaleOuverte) L.piegerFocus($overlay, e);
      return;
    }
    if (e.key !== "Escape") return;
    if (confirmOuverte) fermerConfirmation();
    else if (modaleOuverte) fermerModale();
  });

  /* ---------------- Couverture ---------------- */

  L.wireImagePicker({
    dropzone: $dropzone,
    urlInput: $fImageUrl,
    fileInput: $fImageFile,
    statusEl: $coverStatus,
    onChange: (cover) => {
      coverEnAttente = cover;
      $fCoverRemoved.value = cover ? "0" : "1";
      majApercu();
    },
  });

  /* « Image MangaDex » : reprendre l'image de la série liée, même
     lorsqu'elle ne correspond pas au tome en cours.

     Le rafraîchissement automatique, lui, refuse de se rabattre sur
     autre chose que le tome exact — il vaut mieux qu'il ne touche à
     rien que de poser une image trompeuse sans qu'on l'ait demandé.
     Ici on l'a demandé, d'où « repli ».

     Et « enregistrer: 0 » : on ne fait que proposer l'URL. Écrire tout
     de suite serait écrasé par le formulaire encore ouvert à la
     validation. */
  $btnCoverLinked.addEventListener("click", async () => {
    if (!lienMangadex || !idEnEdition) return;

    $btnCoverLinked.disabled = true;
    $coverStatus.textContent = "Recherche de l'image…";
    try {
      const r = await L.api("couverture.rafraichir", {
        id: idEnEdition,
        repli: "1",
        enregistrer: "0",
        /* Ce qui est SAISI, pas ce qui est enregistré : on vient
           peut-être de corriger le tome, et c'est la couverture
           correspondante qu'on veut voir sans valider d'abord. */
        tome_actuel: $fVolume.value || "0",
        statut: $fStatus.value,
      });
      coverEnAttente = { type: "url", value: r.url };
      $fImageUrl.value = r.url;
      $fImageFile.value = "";
      $fCoverRemoved.value = "0";
      majApercu();
      // Le numéro est annoncé : la règle « prochain tome à emprunter »
      // surprendrait sinon quelqu'un qui vient de taper 10.
      $coverStatus.textContent = "Image du tome " + r.tome + " reprise ✅";
    } catch (err) {
      $coverStatus.textContent = err.message;
    } finally {
      $btnCoverLinked.disabled = false;
    }
  });

  document.getElementById("btn-remove-cover").addEventListener("click", () => {
    coverEnAttente = null;
    $fImageUrl.value = "";
    $fImageFile.value = "";
    $fCoverRemoved.value = "1";
    $coverStatus.textContent = "Image retirée.";
    majApercu();
  });

  /* Recherche automatique de la couverture d'un tome précis. L'appel passe
     par notre propre api.php, qui interroge MangaDex depuis le serveur —
     MangaDex refuse les appels directs du navigateur, et ce détour a
     l'avantage de ne plus exposer l'adresse IP du visiteur à un tiers.

     Le tome recherché dépend du statut : une série « terminée » ou
     « abandonnée » ne sera plus empruntée plus loin que son tome actuel,
     la couverture cherchée est donc celle-là. Une série « en cours » ou
     « à commencer » se cherche sur le PROCHAIN tome à emprunter, soit
     « tome actuel + 1 ». */
  async function chercherCouverture() {
    const titre = $fTitle.value.trim();
    if (!titre) {
      $coverStatus.textContent = "Saisissez d'abord un titre.";
      $fTitle.focus();
      return;
    }
    const volumeActuel = parseInt($fVolume.value, 10) || 0;
    const statutFini = $fStatus.value === "termine" || $fStatus.value === "abandon";
    const tome = Math.max(1, statutFini ? volumeActuel : volumeActuel + 1);

    $coverStatus.textContent = "Recherche du tome " + tome + "…";
    $coverResults.classList.add("hidden");
    $coverResults.replaceChildren();
    document.querySelectorAll(".majorite").forEach((n) => n.remove());

    try {
      const r = await L.api("couverture.chercher", { titre, tome });
      const resultats = r.resultats || [];
      if (!resultats.length) {
        $coverStatus.textContent = r.message || "Aucun résultat.";
        return;
      }

      // Construction par le DOM, jamais innerHTML : aucune donnée venue
      // d'un service tiers ne peut être interprétée comme du HTML.
      resultats.forEach((res) => {
        if (!/^https:\/\//i.test(res.url || "")) return;

        const bloc = document.createElement("button");
        bloc.type = "button";
        bloc.className = "cover-result" + (res.exact ? "" : " cover-result-approx");
        bloc.dataset.url = res.url;
        bloc.title = res.exact
          ? res.serie + " — tome " + res.tome
          : res.serie + " — couverture de la série (tome " + res.tome + " introuvable)";

        const img = document.createElement("img");
        img.src = res.url;
        img.alt = bloc.title;
        img.loading = "lazy";
        bloc.appendChild(img);

        // Le libellé n'est pas décoratif : sans lui, rien ne distingue la
        // vraie couverture du tome demandé de celle de la série, servie
        // en repli. L'utilisateur doit savoir ce qu'il choisit.
        const nom = document.createElement("span");
        nom.className = "cover-result-nom";
        nom.textContent = res.serie;
        bloc.appendChild(nom);

        const info = document.createElement("span");
        info.className = "cover-result-tome";
        info.textContent = res.exact ? "Tome " + res.tome : "Série";
        bloc.appendChild(info);

        $coverResults.appendChild(bloc);
      });

      if (!$coverResults.children.length) {
        $coverStatus.textContent = "Aucune couverture exploitable.";
        return;
      }
      $coverResults.classList.remove("hidden");
      $coverStatus.textContent = "Choisissez la couverture du tome " + tome + " ci-dessous.";

      /* Certaines séries sont classées « adulte » par la source et ont
         donc été écartées. Le bouton n'apparaît qu'ici, au moment où
         l'absence se remarque : proposé en permanence, il ne serait
         qu'une invitation sans objet. */
      if (r.filtre) mentionnerFiltre();
    } catch (err) {
      if (err.attente) {
        $coverStatus.textContent = "Trop de recherches. Réessayez dans ";
        const compteur = document.createElement("b");
        compteur.className = "delai";
        $coverStatus.appendChild(compteur);
        $coverStatus.appendChild(document.createTextNode("."));
        window.Delai.lancer(compteur, err.attente);
      } else {
        $coverStatus.textContent = err.message || "Recherche indisponible.";
      }
    }
  }

  /* Une mention, pas un bouton : lever le filtre engage l'utilisateur
     (déclaration de majorité), cela se fait dans les paramètres et en
     connaissance de cause — pas d'un geste au milieu d'une saisie. */
  function mentionnerFiltre() {
    const bloc = document.createElement("p");
    bloc.className = "majorite";
    bloc.textContent = "Le filtre des images sensibles a pu écarter des séries. "
      + "Vous pouvez le désactiver depuis ";
    const lien = document.createElement("a");
    lien.href = "parametres.php";
    lien.textContent = "vos paramètres";
    bloc.appendChild(lien);
    bloc.appendChild(document.createTextNode("."));
    $coverResults.after(bloc);
  }

  document.getElementById("btn-search-cover").addEventListener("click", chercherCouverture);

  $coverResults.addEventListener("click", (e) => {
    const el = e.target.closest(".cover-result");
    if (!el || !el.dataset.url) return;
    coverEnAttente = { type: "url", value: el.dataset.url };
    $fImageUrl.value = el.dataset.url;
    $fImageFile.value = "";
    $fCoverRemoved.value = "0";
    majApercu();
    $coverStatus.textContent = "Couverture sélectionnée ✅";
  });

  /* ---------------- Démarrage ---------------- */

  cartes().forEach(indexer);
  lireFiltres();
  refleterFiltres();
  appliquerVue();
})();
