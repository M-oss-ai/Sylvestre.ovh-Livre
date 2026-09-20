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
      throw erreur;
    }
    return data;
  }

  /* ---------- Toast ---------- */

  function toast(msg, ms = 2600) {
    const el = document.getElementById("toast");
    if (!el) return;
    el.textContent = msg; // textContent : jamais d'interprétation HTML
    el.classList.remove("hidden");
    clearTimeout(toast._t);
    toast._t = setTimeout(() => el.classList.add("hidden"), ms);
  }

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
        if (m.startsWith(qm) || qm.startsWith(m)) return true;
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
      if (urlInput) urlInput.value = "";
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
        if (/^https:\/\//i.test(uri)) {
          if (urlInput) urlInput.value = uri;
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

  return {
    csrf, api, toast, debounce, limite, tailleLisible,
    normalize, levenshtein, correspond, prepareRecherche, correspondPrepare,
    wireImagePicker, brancherToggleMotDePasse, piegerFocus,
  };
})();
