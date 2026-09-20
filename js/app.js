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
  const $filters = document.getElementById("filters");

  let filtreActif = "all";
  let recherche = "";
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
    carte._recherche = L.normalize(
      (carte.dataset.titre || "") + " " + (carte.dataset.auteur || "")
    );
    return carte;
  }

  function cartes() {
    return Array.from($grid.querySelectorAll(".card"));
  }

  function appliquerVue() {
    const toutes = cartes();
    const prep = L.prepareRecherche(recherche);
    let visibles = 0;

    toutes.forEach((c) => {
      if (c._recherche === undefined) indexer(c);
      const okStatut = filtreActif === "all" || c.dataset.statut === filtreActif;
      const okRecherche = !prep.q || L.correspondPrepare(c._recherche, prep);
      const visible = okStatut && okRecherche;
      c.classList.toggle("hidden", !visible);
      if (visible) visibles++;
    });

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
   * L'ordre affiché (du plus récemment modifié au plus ancien) vient du tri
   * serveur au chargement de la page : ici on ne déplace jamais une carte,
   * pour éviter qu'elle saute sous les yeux pendant qu'on la modifie.
   */
  function poserCarte(html, id) {
    const gabarit = document.createElement("div");
    gabarit.innerHTML = html.trim(); // HTML produit et échappé par carte.php
    const nouvelle = gabarit.firstElementChild;
    if (!nouvelle) return;
    indexer(nouvelle);

    const ancienne = $grid.querySelector('.card[data-id="' + CSS.escape(String(id)) + '"]');
    if (ancienne) {
      ancienne.replaceWith(nouvelle);
    } else {
      $grid.appendChild(nouvelle);
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
    else if (action === "edit") ouvrirModale(carte);
  });

  $grid.addEventListener("keydown", (e) => {
    if (e.key !== "Enter" && e.key !== " ") return;
    const cover = e.target.closest(".card-cover");
    if (!cover) return;
    e.preventDefault();
    ouvrirModale(e.target.closest(".card"));
  });

  async function avancer(id, bouton) {
    bouton.disabled = true;
    try {
      const r = await L.api("serie.avancer", { id });
      poserCarte(r.carte, id);
      majCompteurs(r.compte);
      L.toast(r.message);
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
    } catch (err) {
      bouton.disabled = false;
      L.toast(err.message);
    }
  }

  /* ---------------- Filtres / recherche ---------------- */

  $filters.addEventListener("click", (e) => {
    const btn = e.target.closest(".filter-btn");
    if (!btn) return;
    filtreActif = btn.dataset.filter;
    document.querySelectorAll(".filter-btn").forEach((b) => {
      const actif = b === btn;
      b.classList.toggle("active", actif);
      b.setAttribute("aria-pressed", actif ? "true" : "false");
    });
    appliquerVue();
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
  const $fImageUrl = document.getElementById("f-image-url");
  const $fImageFile = document.getElementById("f-image-file");
  const $fCoverRemoved = document.getElementById("f-cover-removed");
  const $coverPreview = document.getElementById("cover-preview");
  const $coverPlaceholder = document.getElementById("cover-placeholder");
  const $coverStatus = document.getElementById("cover-status");
  const $coverResults = document.getElementById("cover-results");
  const $dropzone = document.getElementById("dropzone");
  const $btnDelete = document.getElementById("btn-delete");
  const $btnSubmit = $form.querySelector('button[type="submit"]');

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

    $btnSubmit.disabled = true;
    try {
      const r = await L.api("serie.enregistrer", fd);
      poserCarte(r.carte, r.id);
      majCompteurs(r.compte);
      L.toast(r.message);
      fermerModale();
    } catch (err) {
      L.toast(err.message);
    } finally {
      $btnSubmit.disabled = false;
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
  appliquerVue();
})();
