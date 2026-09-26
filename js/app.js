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
      // Une série qu'on vient de créer ou de modifier garde une
      // EXCEPTION par catégorie (voir poserCarte()) : elle reste visible
      // même si elle ne correspond plus au filtre, jusqu'à ce qu'on
      // retouche cette catégorie précise (voir oublierExceptions()).
      const exceptee = (categorie) => c._exceptions && c._exceptions.has(categorie);
      const okStatut = filtresStatut.size === 0 || filtresStatut.has(c.dataset.statut)
        || exceptee("statut");
      const okImage = filtresImage.size === 0 || filtresImage.has(c.dataset.image)
        || exceptee("image");
      const okFavori = !favorisSeuls || c.dataset.favori === "1" || exceptee("favori");
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

    // La carte active a pu disparaître sous un filtre : la grille doit
    // garder son arrêt au clavier.
    if (!carteActive || !carteActive.isConnected || carteActive.classList.contains("hidden")) {
      rendreActive(visiblesDansLOrdre()[0] || null);
    }
  }

  function majCompteurs(compte) {
    if (!compte) return;
    Object.entries(compte).forEach(([cle, valeur]) => {
      const el = document.getElementById("count-" + cle);
      if (el) el.textContent = valeur;
    });
    majQuota();
  }

  /* ---------------- Clavier : un seul arrêt pour toute la grille ----------------

     Chaque carte ajoutait cinq arrêts de tabulation (couverture, ←, →,
     étoile, crayon) : 780 appuis sur Tab pour traverser 150 séries, et
     les Paramètres au bout. Désormais une seule carte est « active » : ses
     éléments sont dans l'ordre de tabulation, ceux des autres non
     (tabindex -1, posé par carte.php). Les flèches passent d'une carte à
     l'autre, Début et Fin vont aux extrémités ; Tab mène aux boutons de
     la carte active, puis sort de la grille. */

  const FOCUSABLES_CARTE = ".card-cover, .card-actions button";
  let carteActive = null;

  function rendreActive(carte) {
    if (carte === carteActive) return;
    if (carteActive) carteActive.querySelectorAll(FOCUSABLES_CARTE).forEach((el) => { el.tabIndex = -1; });
    carteActive = carte;
    if (carte) carte.querySelectorAll(FOCUSABLES_CARTE).forEach((el) => { el.tabIndex = 0; });
  }

  function visiblesDansLOrdre() {
    return cartes().filter((c) => !c.classList.contains("hidden"));
  }

  /** La carte voisine dans la direction donnée, telle qu'elle s'affiche. */
  function voisine(carte, direction) {
    const visibles = visiblesDansLOrdre();
    const i = visibles.indexOf(carte);
    if (i < 0) return null;
    if (direction === "premiere") return visibles[0];
    if (direction === "derniere") return visibles[visibles.length - 1];
    if (direction === "suivante") return visibles[i + 1] || null;
    if (direction === "precedente") return visibles[i - 1] || null;

    /* Haut / bas : la rangée voisine, et dans celle-ci la carte la plus
       proche en abscisse. Le nombre de colonnes dépend de la largeur de
       l'écran — une seule sur téléphone — d'où la mesure plutôt qu'un
       calcul. */
    const pas = direction === "bas" ? 1 : -1;
    const haut = carte.offsetTop;
    let j = i + pas;
    while (visibles[j] && visibles[j].offsetTop === haut) j += pas;
    if (!visibles[j]) return null;
    const rangee = visibles[j].offsetTop;
    let meilleure = visibles[j];
    for (; visibles[j] && visibles[j].offsetTop === rangee; j += pas) {
      if (Math.abs(visibles[j].offsetLeft - carte.offsetLeft)
          < Math.abs(meilleure.offsetLeft - carte.offsetLeft)) meilleure = visibles[j];
    }
    return meilleure;
  }

  const DIRECTIONS = {
    ArrowRight: "suivante", ArrowLeft: "precedente", ArrowDown: "bas", ArrowUp: "haut",
    Home: "premiere", End: "derniere",
  };

  $grid.addEventListener("keydown", (e) => {
    const direction = DIRECTIONS[e.key];
    if (!direction || e.altKey || e.ctrlKey || e.metaKey) return;
    const carte = e.target.closest(".card");
    if (!carte) return;
    const cible = voisine(carte, direction);
    e.preventDefault(); // pas de défilement de la page en butée
    if (!cible) return;
    rendreActive(cible);
    cible.querySelector(".card-cover").focus();
  });

  $grid.addEventListener("focusin", (e) => {
    const carte = e.target.closest(".card");
    if (!carte) return;
    rendreActive(carte);
    /* Le mode d'emploi n'est lu qu'en ENTRANT dans la grille : répété à
       chaque carte, il noierait le titre de la série. */
    const venuDeDehors = !e.relatedTarget || !$grid.contains(e.relatedTarget);
    if (venuDeDehors && e.target.classList.contains("card-cover")) {
      e.target.setAttribute("aria-describedby", "grille-aide");
    }
  });

  $grid.addEventListener("focusout", (e) => {
    if (e.target.classList && e.target.classList.contains("card-cover")) {
      e.target.removeAttribute("aria-describedby");
    }
  });

  /* Le lien d'évitement mène à la carte active — ou, bibliothèque vide,
     au bouton qui la remplit. */
  document.getElementById("lien-evitement").addEventListener("click", (e) => {
    e.preventDefault();
    const cible = carteActive && !carteActive.classList.contains("hidden")
      ? carteActive.querySelector(".card-cover")
      : document.querySelector("#empty-collection:not(.hidden) button") || $search;
    if (cible) cible.focus();
  });

  /* ---------------- Limite de séries (forfait standard) ----------------
     Annoncée dès 90 % du quota, et « ＋ » explique la limite au lieu
     d'ouvrir une fiche qu'on remplirait pour rien : elle ne se
     découvrait qu'à l'enregistrement, saisie et recherche de couverture
     perdues. Le serveur reste le garde-fou (voir l'INSERT d'api.php). */

  const QUOTA_SERIES = parseInt(document.body.dataset.quotaSeries || "0", 10) || 0;
  const $quotaSeries = document.getElementById("quota-series");
  const $btnAdd = document.getElementById("btn-add");
  const $quotaOverlay = document.getElementById("quota-overlay");

  const nombreSeries = () => parseInt(document.getElementById("count-all").textContent, 10) || 0;
  const bibliothequePleine = () => QUOTA_SERIES > 0 && nombreSeries() >= QUOTA_SERIES;

  function majQuota() {
    if (!QUOTA_SERIES) return;
    const n = nombreSeries();
    const pleine = n >= QUOTA_SERIES;
    $quotaSeries.classList.toggle("hidden", n < Math.ceil(QUOTA_SERIES * 0.9));
    $quotaSeries.classList.toggle("quota-plein", pleine);
    $quotaSeries.textContent = pleine
      ? "Bibliothèque pleine : " + n + " / " + QUOTA_SERIES + " séries, la limite du forfait standard."
      : n + " / " + QUOTA_SERIES + " séries — encore " + (QUOTA_SERIES - n)
        + " avant la limite du forfait standard.";
    $btnAdd.classList.toggle("btn-ajout-plein", pleine);
    $btnAdd.title = pleine ? "Bibliothèque pleine" : "Ajouter une série";
    $btnAdd.setAttribute("aria-label", pleine ? "Ajouter une série (bibliothèque pleine)" : "Ajouter une série");
  }

  let focusAvantQuota = null;

  function ouvrirQuota() {
    focusAvantQuota = document.activeElement;
    $quotaOverlay.classList.remove("hidden");
    document.getElementById("quota-ok").focus();
  }

  function fermerQuota() {
    $quotaOverlay.classList.add("hidden");
    if (focusAvantQuota && focusAvantQuota.focus) focusAvantQuota.focus();
    focusAvantQuota = null;
  }

  if ($quotaOverlay) {
    document.getElementById("quota-ok").addEventListener("click", fermerQuota);
    $quotaOverlay.addEventListener("click", (e) => { if (e.target === $quotaOverlay) fermerQuota(); });
  }

  function demanderAjout() {
    if (bibliothequePleine()) ouvrirQuota();
    else ouvrirModale(null);
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
  function poserCarte(html, id, focus = null) {
    const gabarit = document.createElement("div");
    gabarit.innerHTML = html.trim(); // HTML produit et échappé par carte.php
    const nouvelle = gabarit.firstElementChild;
    if (!nouvelle) return;
    indexer(nouvelle);

    // Créée ou modifiée : elle reste visible même si elle ne correspond
    // plus au filtre actif, tant qu'on ne retouche pas la catégorie
    // concernée (favoris, statut, image — jamais la recherche, qui n'a
    // pas ce genre de surprise). Sans quoi poser une étoile sous le
    // filtre « Favoris », ou changer une image sous le filtre « Image »,
    // ferait disparaître la carte sous les yeux de qui vient d'agir dessus.
    nouvelle._exceptions = new Set(["statut", "favori", "image"]);

    const ancienne = $grid.querySelector('.card[data-id="' + CSS.escape(String(id)) + '"]');
    if (ancienne) {
      // Une carte remplacée occupe la place de celle qu'elle remplace,
      // y compris dans l'ordre d'origine mémorisé.
      nouvelle._ordre = ancienne._ordre;
      const etaitActive = ancienne === carteActive;
      // Le focus peut aussi être DANS la carte sans qu'on l'ait signalé :
      // la couverture qui suit le tome la remplace après coup, en
      // arrière-plan (voir rafraichirCouverture).
      const actif = document.activeElement;
      if (!focus && actif && ancienne.contains(actif)) {
        focus = actif.classList.contains("card-cover") ? "couverture" : actif.dataset.action || "couverture";
      }
      ancienne.replaceWith(nouvelle);
      // Elle hérite aussi de son arrêt au clavier…
      if (etaitActive) {
        carteActive = null;
        rendreActive(nouvelle);
      }
      /* … et du focus, s'il était sur l'un de ses boutons : au clavier,
         avancer d'un tome renvoyait sinon tout en haut de la page. Le
         bouton « ← » d'une série revenue au tome 0 est désactivé : le
         focus se rabat alors sur la couverture. */
      if (focus) {
        const cible = (focus !== "couverture"
          && nouvelle.querySelector('[data-action="' + focus + '"]:not([disabled]):not(.card-cover)'))
          || nouvelle.querySelector(".card-cover");
        rendreActive(nouvelle);
        cible.focus();
      }
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
  /** L'action à refocaliser après remplacement de la carte, ou null. */
  const focusSur = (bouton) => (document.activeElement === bouton ? bouton.dataset.action : null);

  async function basculerFavori(id, bouton) {
    const focus = focusSur(bouton);
    bouton.disabled = true;
    try {
      const r = await L.api("serie.favori", { id });
      poserCarte(r.carte, id, focus);
      majCompteurs(r.compte);
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
    const focus = focusSur(bouton);
    bouton.disabled = true;
    try {
      const r = await L.api("serie.avancer", { id });
      poserCarte(r.carte, id, focus);
      majCompteurs(r.compte);
      L.toast(r.message);
      rafraichirCouverture(id);
    } catch (err) {
      bouton.disabled = false;
      L.toast(err.message);
    }
  }

  async function reculer(id, bouton) {
    const focus = focusSur(bouton);
    bouton.disabled = true;
    try {
      const r = await L.api("serie.reculer", { id });
      poserCarte(r.carte, id, focus);
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

  /**
   * Retouche une catégorie de filtre (statut, favori ou image) : les
   * exceptions qu'elle porte n'ont plus lieu d'être, on les efface sur
   * TOUTES les cartes — y compris celles dont la valeur ne correspond
   * pas au bouton cliqué. Un clic sur « Abandonnée » revérifie donc
   * aussi une série restée visible pour « En cours ».
   *
   * C'est délibérément la règle la plus simple des deux possibles :
   * l'autre (n'effacer que pour la valeur exacte qu'on vient de
   * toggler) demanderait de suivre, par carte, la valeur qu'elle
   * portait au moment de l'exception — plus fragile, et le résultat
   * serait difficile à deviner pour qui l'utilise.
   */
  function oublierExceptions(categorie) {
    cartes().forEach((c) => c._exceptions && c._exceptions.delete(categorie));
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
      // Ouvrir ou fermer le panneau n'est pas une décision de filtrage :
      // aucune exception n'a de raison de s'effacer pour autant.
      imageOuvert = !imageOuvert;
    } else if (btn === $btnFiltreFavori) {
      favorisSeuls = !favorisSeuls;
      oublierExceptions("favori");
    } else if (btn.dataset.filter === "all") {
      // « Toutes » n'est pas un filtre de plus : c'est leur remise à zéro.
      filtresStatut.clear();
      oublierExceptions("statut");
    } else if (btn.dataset.filter) {
      basculer(filtresStatut, btn.dataset.filter);
      oublierExceptions("statut");
    } else {
      return;
    }
    filtresChanges();
  });

  $filtresImage.addEventListener("click", (e) => {
    const btn = e.target.closest(".filter-btn[data-image]");
    if (!btn) return;
    basculer(filtresImage, btn.dataset.image);
    oublierExceptions("image");
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
  const $btnCoverUnlink = document.getElementById("btn-cover-unlink");
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

  const $formErreur = document.getElementById("form-erreur");
  const $quotaRecherche = document.getElementById("quota-recherche");

  /** Une erreur qui ne tient à aucun champ : en haut de la fiche. */
  function erreurFiche(message) {
    $formErreur.textContent = message;
    $formErreur.classList.remove("hidden");
    $formErreur.focus(); // la fait défiler en vue, et la fait lire
  }

  function effacerErreursFiche() {
    $formErreur.classList.add("hidden");
    $formErreur.textContent = "";
    L.effacerErreurs($form);
    $coverStatus.classList.remove("erreur");
  }

  /** Un message sur l'image (fichier refusé…) : sous l'image, en évidence. */
  function erreurImage(message) {
    $coverStatus.textContent = message;
    $coverStatus.classList.add("erreur");
    $coverStatus.scrollIntoView({ block: "nearest" });
  }

  function ouvrirModale(carte) {
    const edition = !!carte;
    idEnEdition = edition ? carte.dataset.id : "";
    focusAvantModale = document.activeElement;
    effacerErreursFiche();

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
    $btnCoverUnlink.classList.toggle("hidden", lienMangadex === "");
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
    effacerErreursFiche();
    /* La carte d'où l'on venait a pu être remplacée pendant l'édition
       (enregistrement, couverture rafraîchie) : le focus va alors à la
       nouvelle, pas dans le vide. */
    let retour = focusAvantModale;
    if (retour && !retour.isConnected && idEnEdition) {
      const carte = $grid.querySelector('.card[data-id="' + CSS.escape(String(idEnEdition)) + '"]');
      retour = carte ? carte.querySelector(".card-cover") : null;
      if (carte) rendreActive(carte);
    }
    idEnEdition = "";
    coverEnAttente = null;
    if (retour && retour.focus) retour.focus();
    focusAvantModale = null;
  }

  function majApercu() {
    // Une adresse refusée (http://…) n'a pas d'aperçu : l'image serait
    // cassée, et on croirait qu'elle va s'enregistrer.
    const src = coverEnAttente && !coverEnAttente.refusee
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

  $btnAdd.addEventListener("click", demanderAjout);
  document.getElementById("btn-add-first").addEventListener("click", demanderAjout);
  document.getElementById("btn-close").addEventListener("click", fermerModale);
  document.getElementById("btn-cancel").addEventListener("click", fermerModale);
  $overlay.addEventListener("click", (e) => { if (e.target === $overlay) fermerModale(); });

  /* ---------------- Enregistrement ---------------- */

  $form.addEventListener("submit", async (e) => {
    e.preventDefault();
    effacerErreursFiche();
    if (!$fTitle.value.trim()) {
      L.erreurChamp($fTitle, "Le titre est obligatoire.");
      $fTitle.focus();
      return;
    }
    if (coverEnAttente && coverEnAttente.refusee) {
      L.erreurChamp($fImageUrl, L.MESSAGE_URL_REFUSEE);
      $fImageUrl.focus();
      return;
    }

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
      /* Sous le champ concerné, et la fiche reste ouverte : une
         notification de deux secondes posée sur les boutons disait
         l'erreur sans dire où. */
      const champ = L.erreursSurChamps(err, { titre: $fTitle, couverture_url: $fImageUrl });
      if (champ) champ.focus();
      else if (err.champ === "couverture") erreurImage(err.message);
      else erreurFiche(err.message);
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
    const quotaOuvert = !!$quotaOverlay && !$quotaOverlay.classList.contains("hidden");

    if (e.key === "Tab") {
      if (quotaOuvert) L.piegerFocus($quotaOverlay, e);
      else if (confirmOuverte) L.piegerFocus($confirmOverlay, e);
      else if (modaleOuverte) L.piegerFocus($overlay, e);
      return;
    }
    if (e.key !== "Escape") return;
    if (quotaOuvert) fermerQuota();
    else if (confirmOuverte) fermerConfirmation();
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
      L.effacerErreur($fImageUrl);
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

  /* « Délier de MangaDex » : garder l'image affichée, mais lui faire
     perdre son URL MangaDex — le lien s'en déduisant, il disparaît de
     lui-même au prochain enregistrement.

     Comme « Image MangaDex », ceci ne fait que PROPOSER l'image : rien
     n'est écrit tant que la modale n'est pas validée. Et l'image
     obtenue est un chemin LOCAL (uploads/…), pas une adresse
     https:// : $fImageUrl reste vide, exactement comme ouvrirModale()
     le fait déjà pour une couverture déjà locale — un champ « url »
     n'accepte qu'une adresse absolue, un chemin local y serait rejeté
     par le navigateur. */
  $btnCoverUnlink.addEventListener("click", async () => {
    if (!lienMangadex || !idEnEdition) return;

    $btnCoverUnlink.disabled = true;
    $coverStatus.textContent = "Rapatriement de l'image…";
    try {
      const r = await L.api("couverture.delier", { id: idEnEdition });
      coverEnAttente = { type: "url", value: r.url };
      $fImageUrl.value = "";
      L.effacerErreur($fImageUrl);
      $fImageFile.value = "";
      $fCoverRemoved.value = "0";
      majApercu();
      $coverStatus.textContent = "Image dissociée de MangaDex ✅ — Enregistrez pour confirmer.";
    } catch (err) {
      $coverStatus.textContent = err.message;
    } finally {
      $btnCoverUnlink.disabled = false;
    }
  });

  document.getElementById("btn-remove-cover").addEventListener("click", () => {
    coverEnAttente = null;
    $fImageUrl.value = "";
    L.effacerErreur($fImageUrl);
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
      if (r.recherches) majQuotaRecherche(r.recherches);
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
        /* Deux attentes différentes : la limite du COMPTE, que la page
           annonce, ou l'encombrement du site, où l'on n'y est pour rien. */
        const duCompte = err.donnees && err.donnees.limite === "compte";
        if (duCompte) majQuotaRecherche({ restantes: 0, quota: QUOTA_RECHERCHE, tranche: TRANCHE_RECHERCHE });
        $coverStatus.textContent = duCompte
          ? "Limite atteinte : " + QUOTA_RECHERCHE + " recherches toutes les " + TRANCHE_RECHERCHE
            + ". Nouvelle recherche dans "
          : "Trop de recherches en cours sur le site. Réessayez dans ";
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

  /* Le solde de recherches, après chacune : la règle annoncée d'emblée
     (« 30 recherches toutes les 2 minutes ») ne dit pas où l'on en est. */
  const QUOTA_RECHERCHE = parseInt(document.body.dataset.quotaRecherche || "0", 10) || 0;
  const TRANCHE_RECHERCHE = document.body.dataset.trancheRecherche || "";

  function majQuotaRecherche(q) {
    $quotaRecherche.textContent = "Recherche auto : encore " + q.restantes + " sur " + q.quota
      + " — le compteur repart à " + q.quota + " toutes les " + q.tranche + ".";
    $quotaRecherche.classList.toggle("quota-epuise", q.restantes <= 0);
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
    L.effacerErreur($fImageUrl);
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
  majQuota();
})();
