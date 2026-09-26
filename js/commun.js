/* =========================================================
   Petites fonctions partagées par les pages du site.
   Aucun HTML n'est fabriqué ici à partir de données :
   on passe systématiquement par textContent ou par le HTML
   déjà échappé que renvoie le serveur.
   ========================================================= */

window.Lib = (() => {
  "use strict";

  /* ---------- Jeton CSRF (déposé par PHP sur <body data-csrf>) ---------- */

  function csrf() {
    return document.body.dataset.csrf || "";
  }

  /* ---------- Limites de taille ----------
     Elles viennent du serveur (data-* sur <body>, alimenté par le .env) :
     recopiées en dur ici, elles se désynchronisaient du jour où l'on
     touchait au réglage, et le navigateur refusait un fichier que le
     serveur aurait accepté — ou l'inverse. Le repli ne sert que si
     l'attribut manque. */

  function limite(nom, defaut) {
    const v = parseInt(document.body.dataset[nom] || "", 10);
    return Number.isFinite(v) && v > 0 ? v : defaut;
  }

  /** 3145728 → « 3 Mo » (même formulation que taille_lisible() côté PHP). */
  function tailleLisible(octets) {
    if (octets >= 1048576) {
        return String(Math.round((octets / 1048576) * 10) / 10).replace(".", ",") + " Mo";
    }
    return Math.round(octets / 1024) + " Ko";
  }

  /* ---------- Appel de l'API ---------- */

  async function api(action, donnees) {
    const fd = donnees instanceof FormData ? donnees : new FormData();
    if (!(donnees instanceof FormData) && donnees) {
      Object.entries(donnees).forEach(([k, v]) => fd.append(k, v));
    }
    fd.set("action", action);
    fd.set("csrf", csrf());

    const res = await fetch("api.php", {
      method: "POST",
      body: fd,
      credentials: "same-origin",
      headers: { "X-Requested-With": "fetch" },
    });

    let data;
    try {
      data = await res.json();
    } catch (e) {
      throw new Error("Réponse inattendue du serveur.");
    }

    // Certaines actions régénèrent la session (changement de mot de passe) :
    // le jeton CSRF change alors, et les appels suivants seraient refusés
    // si on gardait l'ancien.
    if (data && data.csrf) document.body.dataset.csrf = data.csrf;

    if (!res.ok || !data.ok) {
      if (res.status === 401) {
        window.location.href = "connexion.php";
      }
      const erreur = new Error(data.erreur || "Une erreur est survenue.");
      // Les refus pour cause de délai portent le nombre de secondes :
      // l'appelant peut en faire un compte à rebours plutôt que de
      // répéter un chiffre qui ne bougera plus.
      if (data.attente) erreur.attente = data.attente;
      // Le champ fautif (« champ »), ou un message par champ (« erreurs ») :
      // l'appelant pose chaque message sous son champ (voir erreurChamp).
      if (data.champ) erreur.champ = data.champ;
      if (data.erreurs && typeof data.erreurs === "object") erreur.erreurs = data.erreurs;
      erreur.donnees = data; // le reste de la réponse, pour qui en a l'usage
      throw erreur;
    }
    return data;
  }

  /* ---------- Toast ----------
     Pour les confirmations (« Série ajoutée ✅ ») : les ERREURS d'un
     formulaire vont sous leur champ, voir erreurChamp().

     La durée suit la longueur du message — 60 ms par caractère, quatre
     secondes au moins. Elle était fixe (2,6 s), si bien qu'un message de
     deux lignes disparaissait avant d'avoir été lu. Le survol ou le
     focus la suspendent, et un clic la ferme. */

  const TOAST_MIN_MS = 4000;
  const TOAST_MS_PAR_CARACTERE = 60;

  /** Le temps de lire le message. */
  function dureeToast(msg) {
    return Math.max(TOAST_MIN_MS, String(msg).length * TOAST_MS_PAR_CARACTERE);
  }

  function toast(msg, ms) {
    const el = document.getElementById("toast");
    if (!el) return;
    el.textContent = msg; // textContent : jamais d'interprétation HTML
    el.classList.remove("hidden");
    toast._duree = ms || dureeToast(msg);
    toastArmer(el);
  }

  function toastArmer(el) {
    clearTimeout(toast._t);
    toast._t = setTimeout(() => el.classList.add("hidden"), toast._duree);
  }

  (() => {
    const el = document.getElementById("toast");
    if (!el) return;
    const suspendre = () => clearTimeout(toast._t);
    const reprendre = () => { if (!el.classList.contains("hidden")) toastArmer(el); };
    el.addEventListener("mouseenter", suspendre);
    el.addEventListener("mouseleave", reprendre);
    el.addEventListener("focusin", suspendre);
    el.addEventListener("focusout", reprendre);
    el.addEventListener("click", () => { clearTimeout(toast._t); el.classList.add("hidden"); });
  })();

  /* ---------- Erreurs rattachées à leur champ ----------
     Le message s'affiche SOUS le champ, qui le désigne (aria-describedby)
     et se déclare invalide (aria-invalid) : un lecteur d'écran l'annonce
     avec le champ, et l'œil le trouve à côté de sa cause. Il s'efface dès
     qu'on corrige. Même convention d'identifiant (« <id>-erreur ») que les
     messages posés par PHP (champ_erreur), qui s'effacent donc de même. */

  function erreurChamp(champ, message) {
    if (!champ) return;
    const id = champ.id + "-erreur";
    let p = document.getElementById(id);
    if (!p) {
      p = document.createElement("p");
      p.className = "erreur-champ";
      p.id = id;
      (champ.closest(".password-wrap") || champ).after(p);
    }
    p.textContent = message;
    champ.setAttribute("aria-invalid", "true");
    const decrit = (champ.getAttribute("aria-describedby") || "").split(/\s+/).filter(Boolean);
    if (!decrit.includes(id)) champ.setAttribute("aria-describedby", [id, ...decrit].join(" "));
  }

  function effacerErreur(champ) {
    if (!champ || !champ.id) return;
    const id = champ.id + "-erreur";
    const p = document.getElementById(id);
    if (p) p.remove();
    champ.removeAttribute("aria-invalid");
    const reste = (champ.getAttribute("aria-describedby") || "").split(/\s+/).filter((x) => x && x !== id);
    if (reste.length) champ.setAttribute("aria-describedby", reste.join(" "));
    else champ.removeAttribute("aria-describedby");
  }

  function effacerErreurs(racine) {
    racine.querySelectorAll('[aria-invalid="true"]').forEach(effacerErreur);
  }

  /**
   * Pose les erreurs d'une réponse de l'API sur les champs : `champs`
   * fait correspondre le nom côté serveur à l'élément. Retourne le premier
   * champ marqué (à focaliser), ou null si aucune erreur n'y correspondait.
   */
  function erreursSurChamps(err, champs) {
    const messages = err.erreurs
      ? Object.entries(err.erreurs)
      : err.champ ? [[err.champ, err.message]] : [];
    let premier = null;
    messages.forEach(([nom, msg]) => {
      const champ = champs[nom];
      if (!champ) return;
      erreurChamp(champ, Array.isArray(msg) ? msg.join(" ") : String(msg));
      if (!premier) premier = champ;
    });
    return premier;
  }

  // Corriger le champ efface son erreur : elle ne doit pas survivre à ce
  // qui l'a causée. En phase de CAPTURE, donc avant les écouteurs du champ
  // lui-même : celui d'une adresse d'image peut ainsi reposer aussitôt
  // l'erreur si la nouvelle saisie est encore refusée.
  document.addEventListener(
    "input",
    (e) => {
      const champ = e.target;
      if (champ && champ.getAttribute && champ.getAttribute("aria-invalid") === "true") effacerErreur(champ);
    },
    true
  );

  /* ---------- Adresse d'image : https uniquement ----------
     La Content-Security-Policy n'accepte que « https: » pour les images,
     et le serveur refuse le reste. Une adresse en http:// s'affichait en
     image cassée, puis la série s'enregistrait « ✅ »… sans elle. */
  const MESSAGE_URL_REFUSEE = "Seules les adresses d'image en https:// sont acceptées.";
  const urlImageAcceptee = (val) => /^https:\/\/[^\s/]+/i.test(val);

  /* ---------- Attendre une pause dans la frappe ---------- */

  /**
   * Retarde l'exécution tant que l'évènement se répète. Sur la recherche,
   * sans ça, chaque caractère tapé relance un calcul de distance de
   * Levenshtein sur toutes les cartes — visible dès quelques dizaines de
   * séries, et franchement pénible sur mobile.
   */
  function debounce(fn, ms = 150) {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  }

  /* ---------- Recherche tolérante ---------- */

  function normalize(str) {
    return (str || "")
      .toString()
      .normalize("NFD")
      .replace(/[̀-ͯ]/g, "") // retire les accents
      .toLowerCase()
      .trim();
  }

  function levenshtein(a, b) {
    if (a === b) return 0;
    const al = a.length, bl = b.length;
    if (al === 0) return bl;
    if (bl === 0) return al;
    let prev = new Array(bl + 1);
    let curr = new Array(bl + 1);
    for (let j = 0; j <= bl; j++) prev[j] = j;
    for (let i = 1; i <= al; i++) {
      curr[0] = i;
      for (let j = 1; j <= bl; j++) {
        const cout = a[i - 1] === b[j - 1] ? 0 : 1;
        curr[j] = Math.min(prev[j] + 1, curr[j - 1] + 1, prev[j - 1] + cout);
      }
      [prev, curr] = [curr, prev];
    }
    return prev[bl];
  }

  /**
   * Prépare une requête une fois pour toutes, au lieu de la re-normaliser
   * et de la re-découper pour chaque carte examinée.
   */
  function prepareRecherche(requete) {
    const q = normalize(requete);
    return { q, mots: q.split(/\s+/).filter(Boolean) };
  }

  /**
   * La cible (déjà normalisée) correspond-elle à la requête préparée ?
   * Fautes de frappe tolérées, avec un seuil proportionnel à la longueur
   * du mot cherché.
   */
  function correspondPrepare(cible, prep) {
    if (!prep.q) return true;
    if (cible.includes(prep.q)) return true;

    const mots = cible.split(/\s+/).filter(Boolean);

    return prep.mots.every((qm) => {
      if (cible.includes(qm)) return true;
      const seuil = qm.length <= 3 ? 1 : qm.length <= 6 ? 2 : 3;
      return mots.some((m) => {
        // L'utilisateur a tapé un début de mot : c'est une recherche par
        // préfixe, elle est voulue, et même « on » pour « One Piece ».
        if (m.startsWith(qm)) return true;

        /* Le cas inverse — l'utilisateur en tape PLUS que le mot stocké,
           « Berserker » pour trouver « Berserk ».

           Sans plancher de longueur, un mot d'UNE lettre du titre
           suffisait : « À toi d'être un héros ! » contient le mot « a »,
           et « a » est bien le début de « ayanashi ». Toute recherche
           commençant par un « a » remontait donc cette série — et le même
           piège valait pour « à », « d », « l », « y », omniprésents dans
           les titres français. */
        if (m.length >= 4 && qm.startsWith(m)) return true;

        // Filtre bon marché avant le calcul coûteux : deux mots dont les
        // longueurs diffèrent de plus que le seuil ne peuvent pas
        // correspondre, quelle que soit leur composition.
        if (Math.abs(m.length - qm.length) > seuil) return false;
        return levenshtein(m, qm) <= seuil;
      });
    });
  }

  /** Le texte correspond-il à la recherche (fautes de frappe tolérées) ? */
  function correspond(texte, requete) {
    return correspondPrepare(normalize(texte), prepareRecherche(requete));
  }

  /* ---------- Sélecteur d'image (URL / fichier / glisser-déposer) ----------
     onChange reçoit :
       { type: "url",  value: "https://…" }
       { type: "file", file: File, apercu: "blob:…" }
       null (aucune image)
  */

  /* ---------- Réduction de l'image avant l'envoi ----------

     Une photo de téléphone pèse 4 à 12 Mo et peut atteindre 50 millions
     de pixels. Le serveur la refusait, et l'envoyer telle quelle sur un
     réseau mobile serait long pour un résultat affiché sur 92 px de
     large. On la ramène donc ici aux dimensions utiles : ce qui part ne
     dépasse plus quelques dizaines de kilo-octets, et plus aucune photo
     n'est refusée pour son poids.

     Effet de bord précieux sur iPhone : les photos y sont en HEIC, que le
     serveur ne sait pas lire — c'est pourquoi une capture d'écran (PNG)
     passait quand une photo ne passait pas. Safari, lui, sait décoder le
     HEIC : en repassant par un canevas, on l'envoie en WebP ou en JPEG.

     Le serveur revalide tout de toute façon : ceci est un confort, pas
     un contrôle de sécurité. */

  const COTE_MAX_ENVOI = 1200; // large : reste net même sur écran Retina

  /** Décode le fichier, en respectant l'orientation notée dans l'EXIF. */
  async function decoderImage(file) {
    if (typeof createImageBitmap === "function") {
      try {
        // Les capteurs n'écrivent pas la photo tournée : ils ajoutent une
        // étiquette « à afficher pivotée ». Sans cette option, toutes les
        // photos prises en portrait partiraient couchées.
        return await createImageBitmap(file, { imageOrientation: "from-image" });
      } catch (e) {
        /* option ou format non gérés : on tente le repli ci-dessous */
      }
    }
    return await new Promise((ok, non) => {
      const url = URL.createObjectURL(file);
      const img = new Image();
      img.onload = () => { URL.revokeObjectURL(url); ok(img); };
      img.onerror = () => { URL.revokeObjectURL(url); non(new Error("illisible")); };
      img.src = url;
    });
  }

  /** WebP si le navigateur sait le produire, JPEG sinon. */
  function versBlob(canvas) {
    return new Promise((resolve) => {
      canvas.toBlob(
        (webp) => {
          // Un navigateur sans WebP ne renvoie pas null : il retombe
          // silencieusement sur du PNG, bien plus lourd. D'où le test
          // sur le type réellement obtenu plutôt que sur la présence.
          if (webp && webp.type === "image/webp") return resolve(webp);
          canvas.toBlob((jpeg) => resolve(jpeg || webp), "image/jpeg", 0.85);
        },
        "image/webp",
        0.85
      );
    });
  }

  async function reduireImage(file) {
    // Un GIF animé perdrait son animation en passant par un canevas.
    if (file.type === "image/gif") return file;

    let source;
    try {
      source = await decoderImage(file);
    } catch (e) {
      return file; // format que le navigateur ne sait pas lire : au serveur de trancher
    }

    const large = source.width;
    const haut = source.height;
    const ratio = Math.min(1, COTE_MAX_ENVOI / Math.max(large, haut));
    const fermer = () => { if (source.close) source.close(); };

    // Déjà petite et déjà légère : on garde l'original, dont l'encodage
    // est souvent meilleur que ce que produirait un ré-encodage.
    if (ratio === 1 && file.size <= 600 * 1024) { fermer(); return file; }

    const canvas = document.createElement("canvas");
    canvas.width = Math.max(1, Math.round(large * ratio));
    canvas.height = Math.max(1, Math.round(haut * ratio));
    const ctx = canvas.getContext("2d");
    if (!ctx) { fermer(); return file; }
    ctx.drawImage(source, 0, 0, canvas.width, canvas.height);
    fermer();

    const blob = await versBlob(canvas);
    if (!blob || blob.size >= file.size) return file; // aucun gain
    const ext = blob.type === "image/webp" ? "webp" : "jpg";
    return new File([blob], "photo." + ext, { type: blob.type, lastModified: Date.now() });
  }

  function wireImagePicker({ dropzone, urlInput, fileInput, statusEl, onChange }) {
    async function depuisFichier(choisi) {
      if (!choisi || !choisi.type.startsWith("image/")) {
        if (statusEl) statusEl.textContent = "Ce fichier n'est pas une image.";
        return;
      }
      if (statusEl) statusEl.textContent = "Préparation de l'image…";

      let file = choisi;
      try {
        file = await reduireImage(choisi);
      } catch (e) {
        file = choisi; // la réduction est un confort : son échec ne doit rien bloquer
      }

      const max = limite("imageMax", 3 * 1024 * 1024);
      if (file.size > max) {
        if (statusEl) statusEl.textContent = "Image trop lourde (" + tailleLisible(max) + " maximum).";
        return;
      }
      if (urlInput) { urlInput.value = ""; effacerErreur(urlInput); }
      onChange({ type: "file", file, apercu: URL.createObjectURL(file) });
      if (statusEl) {
        statusEl.textContent = file === choisi
          ? "Image prête : " + choisi.name
          : "Image prête (réduite à " + tailleLisible(file.size) + ")";
      }
    }

    if (urlInput) {
      urlInput.addEventListener("input", () => {
        const val = urlInput.value.trim();
        if (fileInput) fileInput.value = "";
        /* Refusée dès la saisie, avec la raison sous le champ — et non
           plus acceptée à l'écran puis jetée sans un mot par le serveur.
           « refusee » : l'appelant n'en fait pas d'aperçu, et bloque
           l'enregistrement tant qu'elle reste dans le champ. */
        if (val && !urlImageAcceptee(val)) {
          erreurChamp(urlInput, MESSAGE_URL_REFUSEE);
          onChange({ type: "url", value: val, refusee: true });
          return;
        }
        effacerErreur(urlInput);
        onChange(val ? { type: "url", value: val } : null);
      });
    }

    if (fileInput) {
      fileInput.addEventListener("change", () => {
        const file = fileInput.files && fileInput.files[0];
        if (file) depuisFichier(file);
      });
    }

    if (dropzone) {
      ["dragenter", "dragover"].forEach((evt) =>
        dropzone.addEventListener(evt, (e) => {
          e.preventDefault();
          dropzone.classList.add("dragover");
        })
      );
      ["dragleave", "dragend", "drop"].forEach((evt) =>
        dropzone.addEventListener(evt, () => dropzone.classList.remove("dragover"))
      );

      dropzone.addEventListener("drop", (e) => {
        e.preventDefault();
        const dt = e.dataTransfer;
        if (!dt) return;
        if (dt.files && dt.files.length) {
          depuisFichier(dt.files[0]);
          return;
        }
        const uri = (dt.getData("text/uri-list") || dt.getData("text/plain") || "").trim();
        // https uniquement : la Content-Security-Policy n'autorise pas les
        // images en http, une URL http déposée donnerait une image cassée.
        if (urlImageAcceptee(uri)) {
          if (urlInput) { urlInput.value = uri; effacerErreur(urlInput); }
          if (fileInput) fileInput.value = "";
          onChange({ type: "url", value: uri });
          if (statusEl) statusEl.textContent = "Image liée depuis une URL déposée.";
        } else if (statusEl) {
          statusEl.textContent = "Dépôt non reconnu — essayez un fichier image ou une URL https.";
        }
      });

      dropzone.addEventListener("click", () => fileInput && fileInput.click());
      dropzone.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          fileInput && fileInput.click();
        }
      });
    }
  }

  /* ---------- Modales : garder le focus à l'intérieur ---------- */

  /**
   * À brancher sur keydown. Tant qu'une modale est ouverte, la tabulation
   * boucle entre ses éléments au lieu de repartir dans la page derrière —
   * page que le lecteur d'écran annonce pourtant comme masquée, et où
   * l'on peut sinon activer des boutons qu'on ne voit pas.
   */
  function piegerFocus(conteneur, e) {
    if (e.key !== "Tab") return;
    const focusables = conteneur.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    );
    // offsetParent === null : élément masqué, donc non atteignable.
    const visibles = Array.from(focusables).filter((el) => el.offsetParent !== null);
    if (!visibles.length) return;
    const premier = visibles[0];
    const dernier = visibles[visibles.length - 1];
    if (e.shiftKey && document.activeElement === premier) {
      e.preventDefault();
      dernier.focus();
    } else if (!e.shiftKey && document.activeElement === dernier) {
      e.preventDefault();
      premier.focus();
    }
  }

  /* ---------- Afficher / masquer un mot de passe ---------- */

  function brancherToggleMotDePasse(racine = document) {
    racine.querySelectorAll(".toggle-password").forEach((btn) => {
      btn.addEventListener("click", () => {
        const champ = document.getElementById(btn.dataset.cible);
        if (!champ) return;
        const visible = champ.type === "text";
        champ.type = visible ? "password" : "text";
        btn.textContent = visible ? "👁️" : "🙈";
        btn.setAttribute("aria-label", visible ? "Afficher le mot de passe" : "Masquer le mot de passe");
      });
    });
  }

  /* ---------- Couverture cassée → pictogramme de repli ---------- */

  document.addEventListener(
    "error",
    (e) => {
      const img = e.target;
      if (!(img instanceof HTMLImageElement)) return;
      if (img.classList.contains("card-cover-img")) {
        const repli = document.createElement("span");
        repli.className = "no-cover";
        repli.textContent = "📕";
        img.replaceWith(repli);
      } else if (img.classList.contains("avatar-img") || img.classList.contains("settings-avatar-img")) {
        img.classList.add("hidden");
      }
    },
    true // capture : l'évènement « error » d'une image ne remonte pas
  );

  /* ---------- Quitter un champ sans passer par la touche « Entrée » ----------

     Sur téléphone, un champ focalisé garde le clavier ouvert tant qu'on
     ne touche pas explicitement autre chose — et l'étiquette du champ
     (« Titre * », par exemple) NE compte PAS comme « autre chose » : le
     navigateur la traite comme une extension du champ.

     Ce qui se passe RÉELLEMENT au tap est plus retors qu'il n'y paraît
     (vérifié en instrumentant un vrai clic, pas seulement lu dans la
     spec) : le navigateur retire déjà le focus du champ AVANT même de
     distribuer l'évènement « click » sur l'étiquette — à ce moment-là,
     « document.activeElement » est donc déjà vide, impossible à
     comparer à l'étiquette cliquée. Il fait ensuite comme si l'input
     avait été cliqué DIRECTEMENT, avec son propre évènement « click »
     séparé, qui le refocalise. Deux évènements, pas un.

     D'où la nécessité de mémoriser QUI vient de perdre le focus — via
     « focusout », qui se déclenche sur l'ANCIEN élément avant tout ce
     manège — plutôt que de se fier à « activeElement » au moment du
     clic sur l'étiquette. */

  let venaitDePerdreLeFocus = null;

  document.addEventListener(
    "focusout",
    (e) => {
      if (e.target.matches("input, textarea, select")) venaitDePerdreLeFocus = e.target;
    },
    true
  );

  document.addEventListener(
    "click",
    (e) => {
      // Cas particulier : l'étiquette du champ qui vient JUSTE de
      // perdre le focus — très probablement à cause de ce tap-ci. Le
      // comportement natif s'apprête à le refocaliser (un no-op
      // invisible côté écran, qui laisse le clavier ouvert) : on
      // l'empêche et on sort du champ pour de bon.
      //
      // L'étiquette d'un AUTRE champ, ou une étiquette après un tap sans
      // rapport, n'est pas concernée : `venaitDePerdreLeFocus` ne
      // correspond alors pas, et le focus continue de se déplacer
      // normalement.
      const etiquette = e.target.closest("label");
      if (etiquette && etiquette.htmlFor && venaitDePerdreLeFocus
          && etiquette.htmlFor === venaitDePerdreLeFocus.id) {
        e.preventDefault();
        venaitDePerdreLeFocus.blur(); // déjà fait en pratique ; ceinture et bretelles
        venaitDePerdreLeFocus = null;
        return;
      }

      // Le reste : le fond de page, un titre de section, une carte…
      // Rien de tout cela n'a de focus à prendre nativement. Cliquer sur
      // un AUTRE champ (ou son étiquette) n'entre pas ici : le focus s'y
      // déplace de lui-même, la ligne ci-dessous ne fait rien de plus
      // dans ce cas (elle vise l'ANCIEN champ, qui a déjà perdu le
      // focus par la mécanique native).
      const actif = document.activeElement;
      if (actif && actif.matches("input, textarea, select")
          && !actif.contains(e.target) && e.target !== actif) {
        actif.blur();
      }
    },
    true // capture : on veut voir le clic avant que quoi que ce soit
         // d'autre (fermeture de la modale, etc.) ne réagisse dessus.
  );

  /* Faire glisser la page (ou la modale, qui défile elle aussi) ferme le
     clavier de la même façon.
     « touchmove » et non « scroll » : le navigateur fait défiler la page
     de LUI-MÊME pour garder un champ visible au-dessus du clavier quand
     il s'ouvre — un « scroll » générique s'y serait donc déclenché à
     l'instant même où l'on vient de toucher le champ, le refermant
     aussitôt. « touchmove » ne se déclenche que sous un doigt qui glisse
     réellement, jamais pour un défilement programmatique.

     Mais tout glissement n'est pas un défilement : appuyer longuement
     dans le champ puis glisser, c'est SÉLECTIONNER du texte (ou déplacer
     le curseur, ou faire défiler un textarea). La première version
     fermait le champ au premier pixel, rendant toute sélection
     impossible. D'où trois conditions, toutes nécessaires :
       - le geste a commencé HORS du champ actif (décidé au touchstart,
         une fois pour tout le geste) ;
       - le doigt a parcouru une vraie distance, pas un tremblement ;
       - aucun texte n'est sélectionné dans le champ : les poignées de
         sélection débordent sous la ligne, donc hors de la boîte du
         champ — les attraper ne doit pas compter comme un défilement. */
  const GLISSEMENT_MIN_PX = 10;
  let departGlissement = null; // { x, y } si ce geste peut fermer le champ

  const texteSelectionne = (champ) => {
    try {
      return champ.selectionStart != null && champ.selectionStart !== champ.selectionEnd;
    } catch {
      return false; // types sans sélection (certains navigateurs lèvent)
    }
  };

  document.addEventListener(
    "touchstart",
    (e) => {
      departGlissement = null;
      const actif = document.activeElement;
      const doigt = e.touches[0];
      if (!doigt || !actif || !actif.matches("input, textarea, select")) return;
      if (actif.contains(e.target)) return; // geste né dans le champ : il lui appartient
      departGlissement = { x: doigt.clientX, y: doigt.clientY };
    },
    { passive: true }
  );

  document.addEventListener(
    "touchmove",
    (e) => {
      if (!departGlissement) return;
      const doigt = e.touches[0];
      if (!doigt) return;
      const distance = Math.hypot(doigt.clientX - departGlissement.x, doigt.clientY - departGlissement.y);
      if (distance < GLISSEMENT_MIN_PX) return;
      departGlissement = null; // décidé une fois pour tout le geste
      const actif = document.activeElement;
      if (!actif || !actif.matches("input, textarea, select")) return;
      if (texteSelectionne(actif)) return;
      actif.blur();
    },
    { passive: true }
  );

  /* ---------- Garder le champ touché visible au-dessus du clavier ----------

     Sur téléphone, toucher un champ placé bas dans la modale (« URL de
     l'image ») ouvrait le clavier PAR-DESSUS : on écrivait à l'aveugle
     jusqu'à la première lettre, où le navigateur se décidait enfin à
     décaler l'écran.

     La cause : le clavier ne réduit que la zone VISIBLE
     (visualViewport), pas la mise en page. La modale garde toute sa
     hauteur, son bas passe sous le clavier — et sous ce champ-là il ne
     reste presque rien à faire défiler : même en butée, elle ne peut pas
     le remonter assez.

     Quand un champ saisissable est caché par le clavier, donc :
       1. faire défiler ses conteneurs, du plus proche au plus lointain
          (un défilement qui n'a rien rapproché est annulé : pas question
          de faire bouger la liste derrière la modale pour rien) ;
       2. s'il reste caché, conteneur en butée, agrandir le rembourrage
          bas du conteneur défilant le plus proche de ce qui manque, puis
          défiler encore ;
       3. rendre ce rembourrage quand le clavier se referme.

     Rien ne bouge si le champ est déjà visible : un navigateur qui réduit
     la mise en page avec le clavier, ou qui s'est débrouillé seul, n'est
     pas touché. Écrans tactiles seulement : sans clavier virtuel, il n'y
     a rien à corriger. */

  const MARGE_CLAVIER_PX = 12;
  const CHAMP_SAISISSABLE = "textarea, input:not([type=checkbox]):not([type=radio])"
    + ":not([type=file]):not([type=hidden]):not([type=button]):not([type=submit])"
    + ":not([type=reset]):not([type=range]):not([type=color]):not([type=image])";

  const saisissable = (el) =>
    !!el && typeof el.matches === "function" && el.matches(CHAMP_SAISISSABLE)
    && !el.readOnly && !el.disabled;

  const conteneursDefilants = (champ) => {
    const liste = [];
    for (let el = champ.parentElement; el && el !== document.body; el = el.parentElement) {
      if (/(auto|scroll|overlay)/.test(getComputedStyle(el).overflowY)) liste.push(el);
    }
    return liste;
  };

  // Ce que les en-têtes collants masquent en haut d'un conteneur — la
  // barre « ✕ Titre ✓ » de la modale sur téléphone.
  const masqueEnHaut = (conteneur, hautInterieur) => {
    let masque = 0;
    for (const enfant of conteneur.children) {
      if (getComputedStyle(enfant).position !== "sticky") continue;
      const r = enfant.getBoundingClientRect();
      if (r.top <= hautInterieur + 1) masque = Math.max(masque, r.bottom - hautInterieur);
    }
    return masque;
  };

  // De combien défiler pour voir le champ : > 0 vers le bas, < 0 vers le
  // haut, 0 s'il est visible. Plus haut que la zone visible, on en montre
  // le haut plutôt que le bas.
  const ecartAuClavier = (champ, conteneurs) => {
    const vv = window.visualViewport;
    let haut = vv.offsetTop;
    let bas = vv.offsetTop + vv.height;
    for (const c of conteneurs) {
      const r = c.getBoundingClientRect();
      const hautInterieur = r.top + c.clientTop;
      haut = Math.max(haut, hautInterieur + masqueEnHaut(c, hautInterieur));
      bas = Math.min(bas, hautInterieur + c.clientHeight);
    }
    const r = champ.getBoundingClientRect();
    if (r.bottom + MARGE_CLAVIER_PX > bas) {
      return Math.max(0, Math.min(r.bottom + MARGE_CLAVIER_PX - bas, r.top - MARGE_CLAVIER_PX - haut));
    }
    if (r.top - MARGE_CLAVIER_PX < haut) return r.top - MARGE_CLAVIER_PX - haut;
    return 0;
  };

  let reserveClavier = null; // { conteneur, base, px } : le rembourrage prêté

  const rendreReserveClavier = () => {
    if (!reserveClavier) return;
    reserveClavier.conteneur.style.removeProperty("padding-bottom");
    reserveClavier = null;
  };

  const preterReserveClavier = (conteneur, px) => {
    if (reserveClavier && reserveClavier.conteneur !== conteneur) rendreReserveClavier();
    if (!reserveClavier) {
      const base = parseFloat(getComputedStyle(conteneur).paddingBottom) || 0;
      reserveClavier = { conteneur, base, px: 0 };
    }
    reserveClavier.px += px;
    // CSSOM, pas un attribut « style » : la CSP (style-src 'self') l'accepte.
    conteneur.style.setProperty("padding-bottom", reserveClavier.base + reserveClavier.px + "px");
  };

  const garderVisible = (champ) => {
    if (!window.visualViewport || document.activeElement !== champ) return;
    const conteneurs = conteneursDefilants(champ);
    const defileurs = [...conteneurs, document.scrollingElement].filter(Boolean);
    for (let tour = 0; tour < 3; tour++) {
      let ecart = ecartAuClavier(champ, conteneurs);
      if (Math.abs(ecart) < 1) return;
      for (const d of defileurs) {
        const avant = d.scrollTop;
        d.scrollTop = avant + ecart;
        const apres = ecartAuClavier(champ, conteneurs);
        if (Math.abs(apres - ecart) < 1) d.scrollTop = avant; // n'a rien rapproché
        else ecart = apres;
        if (Math.abs(ecart) < 1) return;
      }
      if (ecart < 0 || !conteneurs.length) return; // manque en haut : rien à prêter
      preterReserveClavier(conteneurs[0], Math.ceil(ecart));
    }
  };

  // Clavier ouvert : la zone visible a perdu une bonne part de la fenêtre.
  const clavierOuvert = () => window.visualViewport.height < window.innerHeight * 0.85;

  if (window.visualViewport && window.matchMedia && window.matchMedia("(pointer: coarse)").matches) {
    document.addEventListener("focusin", (e) => {
      if (!saisissable(e.target)) return;
      const champ = e.target;
      // Clavier déjà ouvert (on passe d'un champ à l'autre) : tout de
      // suite. Sinon c'est le « resize » ci-dessous qui s'en charge ; le
      // délai rattrape un navigateur qui ne l'enverrait pas.
      requestAnimationFrame(() => garderVisible(champ));
      setTimeout(() => garderVisible(champ), 400);
    });

    window.visualViewport.addEventListener("resize", () => {
      const actif = document.activeElement;
      if (saisissable(actif) && clavierOuvert()) garderVisible(actif);
      else rendreReserveClavier();
    });

    // Filet : un clavier refermé sans « resize », une modale fermée.
    document.addEventListener("focusout", () => {
      setTimeout(() => {
        if (!saisissable(document.activeElement) || !clavierOuvert()) rendreReserveClavier();
      }, 600);
    });
  }

  /* ---------- Retour arrière sur une page privée ----------

     Le navigateur peut garder une page entière en mémoire et la
     ressortir telle quelle au « Précédent » (bfcache), sans rien demander
     au serveur — « no-store » n'y suffit pas partout. Après une
     déconnexion ou la suppression du compte, la personne suivante
     retrouvait ainsi le profil ou la bibliothèque de celle qui venait de
     partir.

     Une page marquée data-prive est donc masquée dès qu'elle ressort de
     ce cache, le temps de demander au serveur si la session vit encore :
     oui, elle réapparaît ; non (ou pas de réponse), elle cède la place à
     la connexion, sans laisser d'entrée dans l'historique. */
  window.addEventListener("pageshow", (e) => {
    if (!e.persisted || document.body.dataset.prive !== "1") return;
    document.documentElement.classList.add("verification-session");
    const fd = new FormData();
    fd.set("action", "session.verifier");
    fd.set("csrf", csrf());
    fetch("api.php", { method: "POST", body: fd, credentials: "same-origin" })
      .then((r) => {
        if (!r.ok) throw new Error("session");
        document.documentElement.classList.remove("verification-session");
      })
      .catch(() => window.location.replace("connexion.php"));
  });

  return {
    csrf, api, toast, dureeToast, debounce, limite, tailleLisible,
    normalize, levenshtein, correspond, prepareRecherche, correspondPrepare,
    wireImagePicker, brancherToggleMotDePasse, piegerFocus,
    erreurChamp, effacerErreur, effacerErreurs, erreursSurChamps, urlImageAcceptee,
    MESSAGE_URL_REFUSEE,
  };
})();
