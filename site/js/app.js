/* =========================================================
   Ma Bibliothèque Manga — page principale (index.php)
   Le serveur fabrique les cartes (HTML déjà échappé), le
   navigateur se contente de filtrer, rechercher et appeler
   api.php. Aucune donnée n'est injectée en innerHTML ici :
   on insère uniquement le HTML renvoyé par carte.php.
   ========================================================= */

/* ---------------- Logique pure ----------------
   Ce qui se décide sans toucher à la page : la carte voisine au
   clavier, le suivi du défilement qui cache ou montre les filtres, les
   textes des quotas. Isolée ici pour être testée (tests/js), le reste
   du fichier la branche sur la page. */

window.Bibliotheque = (() => {
  "use strict";

  /**
   * La carte voisine dans une direction : son rang parmi `positions` —
   * celles des cartes visibles, { haut, gauche }, dans l'ordre affiché —
   * ou -1 en butée.
   *
   * Haut / bas : la rangée voisine, et dans celle-ci la carte la plus
   * proche en abscisse. Le nombre de colonnes dépend de la largeur de
   * l'écran — une seule sur téléphone — d'où la mesure plutôt qu'un
   * calcul.
   */
  function voisine(positions, i, direction) {
    const n = positions.length;
    if (i < 0 || i >= n) return -1;
    if (direction === "premiere") return 0;
    if (direction === "derniere") return n - 1;
    if (direction === "suivante") return i + 1 < n ? i + 1 : -1;
    if (direction === "precedente") return i - 1;
    if (direction !== "bas" && direction !== "haut") return -1;

    const pas = direction === "bas" ? 1 : -1;
    const dans = (j) => j >= 0 && j < n;
    let j = i + pas;
    while (dans(j) && positions[j].haut === positions[i].haut) j += pas;
    if (!dans(j)) return -1;
    const rangee = positions[j].haut;
    const ecart = (k) => Math.abs(positions[k].gauche - positions[i].gauche);
    let meilleure = j;
    for (; dans(j) && positions[j].haut === rangee; j += pas) {
      if (ecart(j) < ecart(meilleure)) meilleure = j;
    }
    return meilleure;
  }

  /**
   * Un relevé du défilement, pour les filtres qui se cachent quand on
   * descend et reviennent quand on remonte.
   *
   * `etat` est ce qu'a laissé le relevé précédent (null au premier) ;
   * `mesure` : { y, max, gabarit } — la position, sa borne, et la somme
   * des hauteurs qui peuvent décaler la page. Rend le nouvel état, si les
   * filtres sont collés, et l'action : "montrer", "cacher" ou null.
   */
  function suiviDefilement(etat, mesure, seuil) {
    /* Bornée : le rebond élastique d'iOS, en haut comme en bas de page,
       ferait croire à un changement de sens. */
    const y = Math.min(Math.max(mesure.y, 0), Math.max(0, mesure.max));
    /* Une hauteur a changé depuis le relevé précédent (un groupe de
       filtres déplié, liste filtrée, cartes dessinées pour la première fois en
       remontant) : le navigateur a pu décaler le défilement d'autant,
       pour garder sous les yeux ce qu'on regardait. Ce décalage n'est pas
       un geste — pris pour une descente, il cachait les filtres qu'on
       venait de déplier. On repart simplement de la position actuelle. */
    const delta = etat && etat.gabarit === mesure.gabarit ? y - etat.y : 0;
    let parcouru = etat ? etat.parcouru : 0; // dans le sens actuel : > 0 en descendant
    let action = null;

    if (y <= 0) {
      // Tout en haut, les filtres sont à leur place.
      parcouru = 0;
      action = "montrer";
    } else if (delta !== 0) {
      if ((delta > 0) !== (parcouru > 0)) parcouru = 0;
      parcouru += delta;
      if (parcouru > seuil) action = "cacher";
      else if (parcouru < -seuil) action = "montrer";
    }
    return { etat: { y, parcouru, gabarit: mesure.gabarit }, collee: y > 0, action };
  }

  /**
   * La place restante du forfait standard, annoncée dès 90 % :
   * { texte, pleine }, ou null en deçà — et sans limite (quota 0).
   */
  function annonceQuota(nombre, quota) {
    if (!quota || nombre < Math.ceil(quota * 0.9)) return null;
    const pleine = nombre >= quota;
    return {
      pleine,
      texte: pleine
        ? "Bibliothèque pleine : " + nombre + " / " + quota + " séries, la limite du forfait standard."
        : nombre + " / " + quota + " séries — encore " + (quota - nombre)
          + " avant la limite du forfait standard.",
    };
  }

  /** Le solde des recherches de couverture, après chacune. */
  function texteQuotaRecherche(q) {
    return "Recherche auto : encore " + q.restantes + " sur " + q.quota
      + " — le compteur repart à " + q.quota + " toutes les " + q.tranche + ".";
  }

  /**
   * La couverture de la fiche, réduite à ce qui la distingue : "" sans
   * image, l'adresse pour une URL, nom + taille + date pour un fichier.
   */
  function signatureCouverture(c) {
    if (!c) return "";
    if (c.type === "file") {
      const f = c.file || {};
      return "fichier:" + f.name + ":" + f.size + ":" + f.lastModified;
    }
    return "url:" + (c.value || "");
  }

  /**
   * La fiche a-t-elle changé depuis son ouverture ? `avant` et `apres` :
   * { titre, auteur, tome, statut, couverture }. Les blancs autour du
   * titre et de l'auteur ne comptent pas (le serveur les retire), ni un
   * tome vide, qui s'enregistre comme 0.
   */
  function ficheModifiee(avant, apres) {
    if (!avant || !apres) return false;
    const net = (v) => String(v == null ? "" : v).trim();
    const tome = (v) => parseInt(v, 10) || 0;
    return net(avant.titre) !== net(apres.titre)
      || net(avant.auteur) !== net(apres.auteur)
      || tome(avant.tome) !== tome(apres.tome)
      || avant.statut !== apres.statut
      || avant.couverture !== apres.couverture;
  }

  /**
   * La touche demande-t-elle de supprimer la carte sélectionnée ? Suppr,
   * et Retour arrière : c'est la touche « delete » d'un clavier Mac. Seule
   * — un raccourci (Ctrl+Retour arrière efface un mot) n'y prétend pas.
   */
  function toucheSuppression(e) {
    return (e.key === "Delete" || e.key === "Backspace")
      && !e.altKey && !e.ctrlKey && !e.metaKey && !e.shiftKey;
  }

  // Les <input> qu'on ne remplit pas au clavier.
  const SANS_TEXTE = ["button", "submit", "reset", "checkbox", "radio", "file", "image", "color", "range", "hidden"];

  /**
   * L'élément reçoit-il du texte ? Suppr et Retour arrière y effacent un
   * caractère : là, ils ne suppriment jamais la série.
   */
  function champDeSaisie(el) {
    if (!el || !el.tagName) return false;
    if (el.isContentEditable || el.tagName === "TEXTAREA") return true;
    return el.tagName === "INPUT" && !SANS_TEXTE.includes(el.type);
  }

  // Les groupes de filtres qui se déplient, par le nom de leur data-panneau.
  const PANNEAUX = ["statut", "image"];

  /**
   * Le groupe ouvert après un clic sur le bouton de `demande` : le même
   * groupe se referme, un autre prend la place de celui qui était ouvert
   * (un seul à la fois, pour que la barre ne dépasse jamais sa rangée plus
   * une). « Toutes » (`demande` = "toutes") replie celui qui était ouvert
   * (demande de l'utilisateur : cliquer « Toutes » ferme les sous-filtres) ;
   * tout autre bouton qui n'est pas un groupe, « Favoris » par exemple, le
   * laisse comme il est.
   * `ouvert` : "statut", "image", ou "" quand tout est replié.
   */
  function panneauApres(ouvert, demande) {
    const courant = PANNEAUX.includes(ouvert) ? ouvert : "";
    if (demande === "toutes") return "";
    if (!PANNEAUX.includes(demande)) return courant;
    return courant === demande ? "" : demande;
  }

  /**
   * Le groupe ouvert d'après ce que le navigateur avait mémorisé. Les
   * versions d'avant ne gardaient que « imageOuvert » ; une valeur
   * inconnue ou illisible vaut « tout replié », le bon défaut.
   */
  function panneauMemorise(memoire) {
    if (!memoire || typeof memoire !== "object") return "";
    if (PANNEAUX.includes(memoire.panneau)) return memoire.panneau;
    return memoire.imageOuvert === true ? "image" : "";
  }

  /**
   * « Toutes » s'allume quand AUCUN filtre n'est posé, de quelque genre
   * que ce soit. `etat` : { statut, image } (des Set) et { favoris }.
   */
  function aucunFiltre(etat) {
    return etat.statut.size === 0 && etat.image.size === 0 && !etat.favoris;
  }

  /**
   * Une rangée qui coulisse a-t-elle de la suite à gauche, à droite ?
   * `mesure` : { gauche, visible, total } — son scrollLeft, sa largeur
   * visible, sa largeur totale. Un pixel de tolérance : les écrans à
   * densité fractionnaire rendent des positions décimales, et la butée
   * n'y tombe jamais pile.
   */
  function bordsDefilement(mesure) {
    const gauche = Number(mesure && mesure.gauche);
    const reste = Number(mesure && mesure.total) - Number(mesure && mesure.visible);
    if (!(reste > 1) || !(gauche >= 0)) return { gauche: false, droite: false };
    return { gauche: gauche > 1, droite: gauche < reste - 1 };
  }

  return {
    voisine, suiviDefilement, annonceQuota, texteQuotaRecherche,
    signatureCouverture, ficheModifiee, toucheSuppression, champDeSaisie,
    panneauApres, panneauMemorise, aucunFiltre, bordsDefilement,
  };
})();

(() => {
  "use strict";

  const L = window.Lib;
  const B = window.Bibliotheque;

  const $grid = document.getElementById("grid");
  // Hors de la bibliothèque — le banc de tests charge ce fichier pour sa
  // logique pure : rien à brancher.
  if (!$grid) return;
  const $emptyCollection = document.getElementById("empty-collection");
  const $emptySearch = document.getElementById("empty-search");
  const $search = document.getElementById("search");
  const $filtresStatut = document.getElementById("filtres-statut");
  const $filtresImage = document.getElementById("filtres-image");
  const $btnToutes = document.getElementById("btn-filtre-toutes");
  const $btnFiltreFavori = document.getElementById("btn-filtre-favori");
  const $filters = document.getElementById("filters");
  // Chaque groupe de filtres : son nom (data-panneau), sa rangée et son bouton.
  const GROUPES = [
    ["statut", $filtresStatut, document.getElementById("btn-filtres-statut")],
    ["image", $filtresImage, document.getElementById("btn-filtres-image")],
  ];

  /* Les filtres se CUMULENT, et se conservent d'une visite à l'autre.
     Un ensemble vide veut dire « aucun filtre de ce genre », ce qui est
     plus simple qu'une valeur « toutes » à traiter à part. */
  const filtresStatut = new Set();
  const filtresImage = new Set();
  let favorisSeuls = false;
  let panneauOuvert = ""; // "statut", "image", ou "" : un seul groupe déplié
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
    const positions = visibles.map((c) => ({ haut: c.offsetTop, gauche: c.offsetLeft }));
    const j = B.voisine(positions, visibles.indexOf(carte), direction);
    return j < 0 ? null : visibles[j];
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

  /* Forfait « bloqué » : consultation seule. La page n'affiche déjà plus ce
     qui modifie (index.php, carte.php) ; ces gardes ferment ce qui resterait
     atteignable — Entrée sur une couverture, Suppr sur une carte. Le serveur
     refuse de toute façon (ACTIONS_BLOQUEES, api.php). */
  const BLOQUE = document.body.dataset.bloque === "1";
  const $quotaSeries = document.getElementById("quota-series");
  const $btnAdd = document.getElementById("btn-add");
  const $quotaOverlay = document.getElementById("quota-overlay");

  const nombreSeries = () => parseInt(document.getElementById("count-all").textContent, 10) || 0;
  const bibliothequePleine = () => QUOTA_SERIES > 0 && nombreSeries() >= QUOTA_SERIES;

  function majQuota() {
    if (!QUOTA_SERIES) return;
    const annonce = B.annonceQuota(nombreSeries(), QUOTA_SERIES);
    const pleine = !!annonce && annonce.pleine;
    $quotaSeries.classList.toggle("hidden", !annonce);
    $quotaSeries.classList.toggle("quota-plein", pleine);
    $quotaSeries.textContent = annonce ? annonce.texte : "";
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
    if (BLOQUE) return;
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
    // Hors de « content-visibility » pour de bon : voir .card-posee dans
    // css/style.css (cartes vides sur iPhone).
    nouvelle.classList.add("card-posee");
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

  /* ---------------- Sélection et touche Suppr ----------------

     La carte sélectionnée, c'est celle qui a le focus : pas d'état à
     tenir à côté, qu'une carte remplacée après une action ferait mentir
     (poserCarte() lui rend déjà le focus). Sur ordinateur, un clic hors
     des boutons — le titre, l'auteur… — la sélectionne : il ne faisait
     rien. Les flèches déplacent la sélection, et Suppr demande la
     suppression, par la même fenêtre que la fiche. Le cadre qui la montre
     est dans css/style.css (.card:focus-within), sous la même condition
     « pointer: fine » que ce clic. Sur écran tactile, rien ne change : pas
     de touche Suppr, un cadre resté après un toucher ne voudrait rien
     dire.

     Un clic ailleurs désélectionne, et un second clic sur la carte aussi
     (demande de l'utilisateur). Dans les deux cas c'est le navigateur qui
     retire le focus : appuyer sur un texte le rend à la page. Il suffit
     donc de ne pas le redonner — d'où la carte notée à l'enfoncement du
     bouton, avant qu'il ne parte. */
  let selectionAuClic = null;

  $grid.addEventListener("mousedown", () => {
    const actif = document.activeElement;
    selectionAuClic = actif && $grid.contains(actif) ? actif.closest(".card") : null;
  });

  $grid.addEventListener("click", (e) => {
    if (e.target.closest("[data-action]")) return; // couverture et boutons : leur action
    const carte = e.target.closest(".card");
    if (!carte || !window.matchMedia("(pointer: fine)").matches) return;
    // Un texte qu'on vient de sélectionner (le titre, pour le copier).
    if (String(window.getSelection())) return;
    if (carte === selectionAuClic) {
      const actif = document.activeElement;
      if (actif && carte.contains(actif)) actif.blur(); // un navigateur qui l'aurait gardé
      return;
    }
    // Sans défilement : on voit ce qu'on vient de cliquer.
    carte.querySelector(".card-cover").focus({ preventScroll: true });
  });

  $grid.addEventListener("keydown", (e) => {
    if (!B.toucheSuppression(e)) return;
    const carte = e.target.closest(".card");
    if (!carte) return;
    e.preventDefault();
    demanderSuppression(carte.dataset.id, carte.dataset.titre, e.target);
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
     est le bon défaut.

     Une clé par compte (voir Lib.cleFiltres). */
  const CLE_FILTRES = L.cleFiltres(document.body.dataset.compte);

  function lireFiltres() {
    try {
      // L'ancienne clé, commune à tous les comptes : on ne sait plus à qui
      // elle appartenait, elle ne sert donc plus à personne.
      localStorage.removeItem(L.CLE_FILTRES);
      const brut = localStorage.getItem(CLE_FILTRES);
      if (!brut) return;
      const f = JSON.parse(brut) || {};
      (Array.isArray(f.statut) ? f.statut : []).forEach((v) => filtresStatut.add(v));
      (Array.isArray(f.image) ? f.image : []).forEach((v) => filtresImage.add(v));
      favorisSeuls = !!f.favoris;
      panneauOuvert = B.panneauMemorise(f);
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
        panneau: panneauOuvert,
      }));
    } catch (e) {
      /* Sans mémoire, les filtres ne valent que pour cette visite. */
    }
  }

  /** Met les boutons au diapason de l'état. */
  function refleterFiltres() {
    const allumer = (b, actif) => {
      b.classList.toggle("active", actif);
      b.setAttribute("aria-pressed", actif ? "true" : "false");
    };

    $filtresStatut.querySelectorAll(".filter-btn[data-filter]").forEach((b) => {
      allumer(b, filtresStatut.has(b.dataset.filter));
    });
    $filtresImage.querySelectorAll(".filter-btn[data-image]").forEach((b) => {
      allumer(b, filtresImage.has(b.dataset.image));
    });
    allumer($btnFiltreFavori, favorisSeuls);
    // « Toutes » s'allume quand aucun filtre, de quelque genre que ce soit, n'est posé.
    allumer($btnToutes, B.aucunFiltre({ statut: filtresStatut, image: filtresImage, favoris: favorisSeuls }));

    GROUPES.forEach(([nom, rangee, bouton]) => {
      const ouvert = panneauOuvert === nom;
      rangee.classList.toggle("hidden", !ouvert);
      bouton.setAttribute("aria-expanded", ouvert ? "true" : "false");
      /* Une pastille dorée dit combien de filtres le groupe porte, même
         replié : sans elle on cherche longtemps pourquoi la liste est si
         courte. */
      const actifs = (nom === "statut" ? filtresStatut : filtresImage).size;
      const pastille = bouton.querySelector(".nb-actifs");
      pastille.textContent = actifs;
      pastille.classList.toggle("hidden", actifs === 0);
    });
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

    if (btn.dataset.panneau) {
      // Ouvrir ou fermer un groupe n'est pas une décision de filtrage :
      // aucune exception n'a de raison de s'effacer pour autant.
      panneauOuvert = B.panneauApres(panneauOuvert, btn.dataset.panneau);
      filtresChanges();
      return;
    }
    if (btn === $btnFiltreFavori) {
      favorisSeuls = !favorisSeuls;
      oublierExceptions("favori");
    } else if (btn === $btnToutes) {
      // « Toutes » n'est pas un filtre de plus : c'est la remise à zéro de
      // tous, statut, favori et image, que leurs groupes soient ouverts ou non.
      filtresStatut.clear();
      filtresImage.clear();
      favorisSeuls = false;
      ["statut", "image", "favori"].forEach(oublierExceptions);
      // Et referme le sous-filtre resté ouvert. « Favoris » ne le referme pas,
      // ni les pastilles DANS un groupe (les statuts se cumulent).
      panneauOuvert = B.panneauApres(panneauOuvert, "toutes");
    } else {
      return;
    }
    filtresChanges();
    revenirEnHaut();
  });

  $filtresStatut.addEventListener("click", (e) => {
    const btn = e.target.closest(".filter-btn[data-filter]");
    if (!btn) return;
    basculer(filtresStatut, btn.dataset.filter);
    oublierExceptions("statut");
    filtresChanges();
    revenirEnHaut();
  });

  $filtresImage.addEventListener("click", (e) => {
    const btn = e.target.closest(".filter-btn[data-image]");
    if (!btn) return;
    basculer(filtresImage, btn.dataset.image);
    oublierExceptions("image");
    filtresChanges();
    revenirEnHaut();
  });

  /* ---------------- Rangées qui coulissent : un fondu dit qu'il y a de la suite ----------------
     Chaque rangée de filtres tient sur UNE ligne et défile sur le côté,
     barre de défilement masquée. Sans indice, un bouton entièrement hors
     de l'écran — « Pas d'image » sur un téléphone de 375 px — n'existe
     pas pour qui ne sait pas qu'il faut glisser. Le fondu (style.css) se
     pose sur le bord qui a de la suite : c'est bordsDefilement() qui le
     décide, ici on le relit au défilement et à chaque changement de
     taille (un groupe qui s'ouvre, un nombre qui change de largeur). */
  function majBords(rangee) {
    const bords = B.bordsDefilement({
      gauche: rangee.scrollLeft, visible: rangee.clientWidth, total: rangee.scrollWidth,
    });
    rangee.classList.toggle("bord-gauche", bords.gauche);
    rangee.classList.toggle("bord-droite", bords.droite);
  }

  const surveillerBords = new ResizeObserver((entrees) => {
    entrees.forEach((e) => majBords(e.target.closest(".filters")));
  });
  [$filters, $filtresStatut, $filtresImage].forEach((rangee) => {
    rangee.addEventListener("scroll", () => majBords(rangee), { passive: true });
    surveillerBords.observe(rangee);
    rangee.querySelectorAll(".filter-btn").forEach((b) => surveillerBords.observe(b));
  });

  /* ---------------- Les filtres reviennent quand on remonte ----------------
     Sortis de la barre du haut (ils y prenaient jusqu'au tiers d'un
     téléphone), les filtres obligeaient à remonter tout en haut pour en
     changer. Ils se collent maintenant sous elle : ils s'y effacent quand
     on descend, et reviennent dès qu'on remonte un peu. */

  const $topbar = document.querySelector(".topbar");
  const $barreFiltres = document.getElementById("barre-filtres");
  const $main = document.querySelector("main");
  const racine = document.documentElement;

  /* Le point d'accroche est la hauteur EXACTE de la barre du haut, qui
     change avec la largeur de l'écran et la taille du texte : trop grand,
     les filtres descendraient d'autant en haut de page ; trop petit, ils
     glisseraient dessous. Celle des filtres sert aux cartes atteintes au
     clavier (scroll-margin-top, dans style.css). */
  const mesurerBarres = new ResizeObserver(() => {
    racine.style.setProperty("--hauteur-topbar", $topbar.getBoundingClientRect().height + "px");
    racine.style.setProperty("--hauteur-filtres", $barreFiltres.offsetHeight + "px");
  });
  mesurerBarres.observe($topbar);
  mesurerBarres.observe($barreFiltres);

  /* Quelques pixels dans le même sens avant de basculer : le pouce qui se
     relève fait souvent remonter la page d'un rien. */
  const SEUIL_SENS = 10;
  let suivi = null; // le relevé précédent, voir Bibliotheque.suiviDefilement
  let suiviPrevu = false;

  function suivreDefilement() {
    suiviPrevu = false;
    const r = B.suiviDefilement(suivi, {
      y: window.scrollY,
      max: racine.scrollHeight - window.innerHeight,
      gabarit: $main.offsetHeight + $topbar.offsetHeight,
    }, SEUIL_SENS);
    suivi = r.etat;
    // Tout en haut, les filtres sont à leur place : ni fond, ni cache.
    $barreFiltres.classList.toggle("collee", r.collee);
    if (r.action) $barreFiltres.classList.toggle("escamotee", r.action === "cacher");
  }

  window.addEventListener("scroll", () => {
    if (suiviPrevu) return;
    suiviPrevu = true;
    requestAnimationFrame(suivreDefilement);
  }, { passive: true });

  /* Maj+Tab depuis la grille y revient alors qu'ils sont cachés derrière
     la barre du haut : ils doivent se montrer. */
  $barreFiltres.addEventListener("focusin", () => $barreFiltres.classList.remove("escamotee"));

  /** Un filtre changé en cours de liste : la nouvelle se lit depuis son
      début — et non d'un endroit quelconque, ou de sa fin si elle est
      plus courte. D'un bond : html défile en douceur (style.css), et
      glisser le long d'une liste qui vient de changer ne montre rien. */
  function revenirEnHaut() {
    if (window.scrollY > 0) window.scrollTo({ top: 0, behavior: "instant" });
  }

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
    if (BLOQUE) return;
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
    ficheAOuverture = etatFiche();
    $overlay.classList.remove("hidden");
    /* Pas de focus automatique sur un ecran tactile : il ouvre le clavier,
       qui recouvre aussitot l'apercu de la couverture — precisement ce qu'on
       vient d'ouvrir la fenetre pour regarder. Au clavier physique, donner le
       focus reste le bon comportement : au Titre d'une nouvelle serie, au
       titre de la fenetre pour une serie existante. Suppr l'y supprime, comme
       sur sa carte ; dans le champ, la touche n'efface que du texte. Tab mene
       au Titre. */
    if (!window.matchMedia("(pointer: coarse)").matches) (edition ? $modalTitle : $fTitle).focus();
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
    ficheAOuverture = null;
    if (retour && retour.focus) retour.focus();
    focusAvantModale = null;
  }

  /* ---------------- Modifications non enregistrées ----------------
     Échap ou un clic à côté de la fiche la fermaient en jetant la saisie,
     y compris une couverture qu'une recherche de plusieurs secondes venait
     de trouver. Ces deux gestes-là sont des réflexes : ils demandent donc
     confirmation dès que la fiche a changé. « Annuler » et « ✕ », eux,
     disent clairement ce qu'ils font et ferment sans question. */

  let ficheAOuverture = null;
  const $abandonOverlay = document.getElementById("abandon-overlay");

  function etatFiche() {
    return {
      titre: $fTitle.value,
      auteur: $fSubtitle.value,
      tome: $fVolume.value,
      statut: $fStatus.value,
      couverture: B.signatureCouverture(coverEnAttente),
    };
  }

  function fermerSiRienNeChange() {
    if (!B.ficheModifiee(ficheAOuverture, etatFiche())) {
      fermerModale();
      return;
    }
    $abandonOverlay.classList.remove("hidden");
    // Le choix sans perte d'abord : un Entrée réflexe ne jette rien.
    document.getElementById("abandon-non").focus();
  }

  function fermerAbandon(abandonner) {
    $abandonOverlay.classList.add("hidden");
    if (abandonner) fermerModale();
    else $fTitle.focus();
  }

  document.getElementById("abandon-non").addEventListener("click", () => fermerAbandon(false));
  document.getElementById("abandon-oui").addEventListener("click", () => fermerAbandon(true));
  $abandonOverlay.addEventListener("click", (e) => { if (e.target === $abandonOverlay) fermerAbandon(false); });

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
  $overlay.addEventListener("click", (e) => { if (e.target === $overlay) fermerSiRienNeChange(); });

  /* ---------------- Enregistrement ---------------- */

  $form.addEventListener("submit", async (e) => {
    e.preventDefault();
    effacerErreursFiche();
    if (!$fTitle.value.trim()) {
      L.erreurChamp($fTitle, "Ce champ est obligatoire.");
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

  let focusAvantConfirmation = null;

  /** « Supprimer cette série ? » — depuis la fiche (son bouton, ou Suppr),
      ou Suppr sur une carte.
      `retour` reprend le focus si l'on annule. Nommé par l'appelant, pas
      lu dans activeElement : commun.js sort d'un champ au moindre clic
      ailleurs, et Safari ne donne pas le focus au bouton cliqué — on
      n'aurait plus trouvé que <body>. */
  function demanderSuppression(id, titre, retour) {
    if (BLOQUE) return;
    idASupprimer = id;
    focusAvantConfirmation = retour;
    $confirmText.textContent = "« " + titre + " » sera définitivement supprimée.";
    $confirmOverlay.classList.remove("hidden");
    // « Annuler » d'abord : un Entrée réflexe ne supprime rien.
    document.getElementById("confirm-cancel").focus();
  }

  $btnDelete.addEventListener("click", () => {
    if (idEnEdition) demanderSuppression(idEnEdition, $fTitle.value, $btnDelete);
  });

  function fermerConfirmation() {
    $confirmOverlay.classList.add("hidden");
    idASupprimer = "";
    // Le focus revient d'où l'on venait : la carte sélectionnée, ou le
    // bouton de la fiche. Il restait sur le bouton caché, donc nulle part.
    const retour = focusAvantConfirmation;
    focusAvantConfirmation = null;
    if (retour && retour.isConnected) retour.focus();
  }

  document.getElementById("confirm-cancel").addEventListener("click", fermerConfirmation);
  $confirmOverlay.addEventListener("click", (e) => { if (e.target === $confirmOverlay) fermerConfirmation(); });

  document.getElementById("confirm-ok").addEventListener("click", async () => {
    if (!idASupprimer) return;
    const id = idASupprimer;
    const carte = $grid.querySelector('.card[data-id="' + CSS.escape(String(id)) + '"]');
    /* La sélection passe à la voisine, comme dans un explorateur de
       fichiers : le focus serait sinon perdu avec la carte, et le clavier
       reprendrait en haut de la page. */
    const suivante = carte && (voisine(carte, "suivante") || voisine(carte, "precedente"));
    let supprimee = false;
    try {
      const r = await L.api("serie.supprimer", { id });
      if (carte) carte.remove();
      majCompteurs(r.compte);
      L.toast(r.message);
      supprimee = true;
    } catch (err) {
      L.toast(err.message);
    }
    fermerConfirmation();
    if (!$overlay.classList.contains("hidden")) fermerModale();
    appliquerVue();
    if (supprimee && suivante && !suivante.classList.contains("hidden")) {
      rendreActive(suivante);
      suivante.querySelector(".card-cover").focus();
    }
  });

  document.addEventListener("keydown", (e) => {
    const confirmOuverte = !$confirmOverlay.classList.contains("hidden");
    const modaleOuverte = !$overlay.classList.contains("hidden");
    const quotaOuvert = !!$quotaOverlay && !$quotaOverlay.classList.contains("hidden");
    const abandonOuvert = !$abandonOverlay.classList.contains("hidden");

    if (e.key === "Tab") {
      if (quotaOuvert) L.piegerFocus($quotaOverlay, e);
      else if (abandonOuvert) L.piegerFocus($abandonOverlay, e);
      else if (confirmOuverte) L.piegerFocus($confirmOverlay, e);
      else if (modaleOuverte) L.piegerFocus($overlay, e);
      return;
    }
    /* Suppr dans la fiche d'une série existante : la même confirmation que
       sur sa carte. Jamais dans un champ, où la touche efface du texte, ni
       sous une question posée par-dessus la fiche. */
    if (B.toucheSuppression(e) && modaleOuverte && idEnEdition
        && !confirmOuverte && !abandonOuvert && !quotaOuvert && !B.champDeSaisie(e.target)) {
      e.preventDefault();
      /* Un clic dans le vide de la fiche rend le focus à la page : « Annuler »
         le ramène alors au titre de la fiche, pas derrière elle. */
      const retour = $overlay.contains(e.target) ? e.target : $modalTitle;
      demanderSuppression(idEnEdition, $fTitle.value, retour);
      return;
    }
    if (e.key !== "Escape") return;
    if (quotaOuvert) fermerQuota();
    else if (abandonOuvert) fermerAbandon(false);   // Échap annule la question, pas la saisie
    else if (confirmOuverte) fermerConfirmation();
    else if (modaleOuverte) fermerSiRienNeChange();
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
    const focusAuDepart = document.activeElement; // voir montrerResultats()
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
      montrerResultats(focusAuDepart);
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
    if (!$quotaRecherche) return; // forfait illimité : aucun quota à annoncer
    $quotaRecherche.textContent = B.texteQuotaRecherche(q);
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

  /* Les vignettes arrivent sous la ligne de flottaison : sur téléphone,
     tout le formulaire les précède, et il fallait deviner qu'elles étaient
     là pour aller les chercher. La fiche descend donc d'elle-même jusqu'au
     message « Choisissez la couverture… », posé juste sous la barre du
     haut, les vignettes à sa suite. Pas tout en bas : une recherche en
     renvoie souvent plus qu'un écran n'en montre, de la plus probable à
     la moins probable, et le bas montrerait les dernières. Quand elles
     tiennent à l'écran, la butée fait qu'on arrive en bas de toute façon.

     Seule la fiche défile (scrollBy sur elle, pas scrollIntoView) : la
     liste derrière ne doit pas bouger. Rien ne bouge si les vignettes se
     voient déjà en entier, ni si l'on s'est mis à écrire dans un autre
     champ PENDANT la recherche : on n'arrache pas une saisie en cours. Le
     champ qui avait déjà le focus au départ ne compte pas : Safari ne le
     donne pas au bouton cliqué, le Titre le garde. */
  const $modale = $overlay.querySelector(".modal");
  const $teteModale = $overlay.querySelector(".modal-head");

  function montrerResultats(focusAuDepart) {
    if ($overlay.classList.contains("hidden")) return;
    const actif = document.activeElement;
    if (actif !== focusAuDepart && $form.contains(actif)
        && actif.matches("input:not([type=file]), textarea")) return;

    const cadre = $modale.getBoundingClientRect();
    // Sur téléphone, la barre « ✕ Titre ✓ » colle en haut et masque ce
    // qui passe dessous.
    const tete = getComputedStyle($teteModale).position === "sticky" ? $teteModale.offsetHeight : 0;
    const haut = cadre.top + $modale.clientTop + tete;
    const bas = cadre.top + $modale.clientTop + $modale.clientHeight;
    const statut = $coverStatus.getBoundingClientRect();
    if (statut.top >= haut && $coverResults.getBoundingClientRect().bottom <= bas) return;

    const doux = !window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    $modale.scrollBy({ top: statut.top - haut - 8, behavior: doux ? "smooth" : "auto" });
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
  // Le message laissé par la page précédente (connexion avec Google…).
  if (document.body.dataset.flash) L.toast(document.body.dataset.flash);
  refleterFiltres();
  appliquerVue();
  majQuota();
  suivreDefilement(); // page rechargée en cours de liste
})();
