/* =========================================================
   Page d'administration (admin.php) : chercher, trier, et les trois
   actions sur un compte — changer son forfait, le nommer administrateur
   (ou non), demander sa suppression.

   `window.Admin` porte la logique PURE (ce que la recherche trouve, l'ordre
   des colonnes, les phrases des fenêtres), testée par tests/js. Le reste du
   fichier la branche sur la page, et ne branche rien ailleurs que sur
   admin.php : c'est ce qui permet au banc de charger ce fichier tel quel.

   Aucune ligne n'est fabriquée en HTML à partir de données : on met à jour
   celles que PHP a déjà rendues, par textContent et par attributs. Le
   serveur reste le seul juge de ce qui est permis (api.php, « admin.* ») :
   ici, on n'en désactive les boutons que par politesse.
   ========================================================= */

window.Admin = (() => {
  "use strict";

  const FORFAITS = { standard: "Standard", illimite: "Illimité", bloque: "Bloqué" };
  const ORDRE_FORFAIT = { standard: 0, illimite: 1, bloque: 2 };

  function normaliser(texte) {
    const t = String(texte == null ? "" : texte);
    return window.Lib && window.Lib.normalize ? window.Lib.normalize(t) : t.toLowerCase();
  }

  /* ---------- Chercher ----------
     On cherche dans ce que la ligne DIT d'elle : l'identifiant, l'adresse,
     le numéro, le forfait, et des mots qui tiennent lieu de filtres — « admin »,
     « Google », « non confirmé », « sans photo », « sans série ». Taper
     « non confirmé » suffit à retrouver les comptes jamais confirmés : pas de
     boutons de filtre de plus, la même case fait tout. Tous les mots tapés
     doivent se retrouver, accents et casse ignorés. */

  function texteRecherche(l) {
    const etiquettes = [FORFAITS[l.forfait] || l.forfait];
    if (l.admin) etiquettes.push("admin", "administrateur");
    if (l.google) etiquettes.push("google");
    if (!l.confirme) etiquettes.push("non confirme");
    if (!l.photo) etiquettes.push("sans photo");
    if (l.series === 0) etiquettes.push("sans serie");
    return normaliser([l.id, l.identifiant, l.email, ...etiquettes].join(" "));
  }

  function correspond(ligne, requete) {
    const mots = normaliser(requete).split(/\s+/).filter(Boolean);
    if (!mots.length) return true;
    const texte = texteRecherche(ligne);
    return mots.every((m) => texte.includes(m));
  }

  /* ---------- Trier ---------- */

  const COLONNES = {
    id: (l) => l.id,
    identifiant: (l) => normaliser(l.identifiant),
    email: (l) => normaliser(l.email),
    confirme: (l) => (l.confirme ? 1 : 0),
    photo: (l) => (l.photo ? 1 : 0),
    forfait: (l) => (l.forfait in ORDRE_FORFAIT ? ORDRE_FORFAIT[l.forfait] : 9),
    inscrit: (l) => l.inscrit,
    series: (l) => l.series,
  };

  /** Une copie triée sur la colonne `cle` (sens 1 ou -1). À égalité : l'ancienneté du compte. */
  function trier(lignes, cle, sens = 1) {
    const valeur = COLONNES[cle];
    if (!valeur) return lignes.slice();
    const signe = sens < 0 ? -1 : 1;
    return lignes.slice().sort((a, b) => {
      const x = valeur(a);
      const y = valeur(b);
      const c = x < y ? -1 : x > y ? 1 : 0;
      return c * signe || a.id - b.id;
    });
  }

  /** Cliquer une colonne la trie ; cliquer la même colonne inverse le sens. */
  function sensApres(etat, cle) {
    return etat && etat.cle === cle ? { cle, sens: -etat.sens } : { cle, sens: 1 };
  }

  /* ---------- Les réglages ---------- */

  /* D'où vient la valeur affichée : le mot que porte la pastille. */
  const SOURCES = { base: "modifié ici", env: ".env", defaut: "défaut" };

  function sourceTexte(source) {
    return source in SOURCES ? SOURCES[source] : "";
  }

  /** La saisie diffère-t-elle de la valeur enregistrée ? Une case vidée n'est pas une valeur. */
  function reglageChange(saisie, initiale) {
    const s = String(saisie == null ? "" : saisie).trim();
    return s !== "" && s !== String(initiale == null ? "" : initiale).trim();
  }

  /** Les chiffres du bandeau, comptés sur les lignes de la page (même calcul que admin_totaux() côté PHP). */
  function totaux(lignes) {
    const t = { comptes: 0, confirmes: 0, nonConfirmes: 0, bloques: 0, illimites: 0, admins: 0, series: 0 };
    lignes.forEach((l) => {
      t.comptes++;
      t[l.confirme ? "confirmes" : "nonConfirmes"]++;
      if (l.forfait === "bloque") t.bloques++;
      if (l.forfait === "illimite") t.illimites++;
      if (l.admin) t.admins++;
      t.series += l.series;
    });
    return t;
  }

  /* ---------- Lire une ligne de la page ---------- */

  function lireLigne(tr) {
    const d = tr.dataset;
    return {
      id: parseInt(d.id, 10) || 0,
      identifiant: d.identifiant || "",
      email: d.email || "",
      confirme: d.confirme === "1",
      photo: d.photo === "1",
      forfait: d.forfait || "standard",
      admin: d.admin === "1",
      google: d.google === "1",
      moi: d.moi === "1",
      inscrit: parseInt(d.inscrit, 10) || 0,
      series: parseInt(d.series, 10) || 0,
      refusDroits: d.refusDroits || "",
      refusSuppression: d.refusSuppression || "",
    };
  }

  /* ---------- Ce que disent les fenêtres ---------- */

  /** Une adresse jamais confirmée ne reçoit rien : la fenêtre le dit AVANT. */
  function noteMail(l) {
    return l.confirme ? "" : " Son adresse n'est pas confirmée : aucun e-mail ne partira.";
  }

  function texteForfait(l, nouveau) {
    const nom = "« " + l.identifiant + " »";
    if (nouveau === "bloque") {
      return (
        "Bloquer " + nom + " ? Il ne pourra plus créer, modifier ni supprimer de séries, " +
        "ni importer de sauvegarde. Il en sera prévenu par e-mail, avec la raison ci-dessous." +
        noteMail(l)
      );
    }
    if (nouveau === "illimite") {
      return "Passer " + nom + " en illimité ? Un e-mail lui annoncera la bonne nouvelle." + noteMail(l);
    }
    return l.forfait === "bloque"
      ? "Rétablir " + nom + " ? Un e-mail le lui annoncera." + noteMail(l)
      : "Repasser " + nom + " au forfait standard ? Un e-mail l'en préviendra." + noteMail(l);
  }

  function texteDroits(l) {
    const nom = "« " + l.identifiant + " »";
    return l.admin
      ? "Retirer à " + nom + " ses droits d'administrateur ?"
      : "Donner à " + nom + " les droits d'administrateur ? Il pourra voir tous les comptes, " +
        "changer leur forfait et demander leur suppression.";
  }

  function texteSuppression(l, adminEmail) {
    return (
      "Supprimer le compte de « " + l.identifiant + " » (" + l.email + ", " + l.series + " série(s)) ? " +
      "Rien n'est supprimé tout de suite : un e-mail de confirmation part vers " + adminEmail +
      ", et le compte n'est effacé qu'après un clic sur son lien."
    );
  }

  /* =========================================================
     Branchement sur la page (admin.php seulement)
     ========================================================= */

  function brancher() {
    const L = window.Lib;
    const table = document.getElementById("admin-table");
    const corps = document.getElementById("admin-corps");
    const recherche = document.getElementById("admin-recherche");
    const compteur = document.getElementById("admin-compte");
    const adminEmail = document.body.dataset.adminEmail || "";

    const overlay = document.getElementById("admin-overlay");
    const titre = document.getElementById("admin-modal-titre");
    const texte = document.getElementById("admin-modal-texte");
    const blocRaison = document.getElementById("admin-modal-raison");
    const champRaison = document.getElementById("admin-raison");
    const erreurModale = document.getElementById("admin-modal-erreur");
    const btnValider = document.getElementById("admin-valider");
    const btnAnnuler = document.getElementById("admin-annuler");

    const lignes = () => Array.from(corps.querySelectorAll("tr.admin-ligne"));
    let tri = { cle: "id", sens: 1 };

    /* ---- recherche ---- */
    function filtrer() {
      const toutes = lignes();
      let visibles = 0;
      toutes.forEach((tr) => {
        const ok = correspond(lireLigne(tr), recherche.value);
        tr.classList.toggle("hidden", !ok);
        if (ok) visibles++;
      });
      compteur.textContent =
        visibles === toutes.length ? toutes.length + " compte(s)" : visibles + " sur " + toutes.length + " compte(s)";
    }
    recherche.addEventListener("input", filtrer);

    /* ---- tri ---- */
    function ordonner() {
      const lues = lignes().map((tr) => Object.assign(lireLigne(tr), { tr }));
      trier(lues, tri.cle, tri.sens).forEach((l) => corps.appendChild(l.tr));
      table.querySelectorAll("th").forEach((th) => {
        const bouton = th.querySelector(".admin-tri");
        if (!bouton) return;
        th.setAttribute(
          "aria-sort",
          bouton.dataset.tri === tri.cle ? (tri.sens > 0 ? "ascending" : "descending") : "none"
        );
      });
    }
    table.querySelectorAll(".admin-tri").forEach((bouton) => {
      bouton.addEventListener("click", () => {
        tri = sensApres(tri, bouton.dataset.tri);
        ordonner();
      });
    });

    /* ---- une ligne remise à jour d'après ce que le serveur a répondu ---- */
    function majBoutons(tr) {
      const d = tr.dataset;
      const droits = tr.querySelector(".admin-droits");
      const suppr = tr.querySelector(".admin-suppr");
      droits.textContent = d.admin === "1" ? "Retirer admin" : "Rendre admin";
      droits.disabled = d.refusDroits !== "";
      droits.title = d.refusDroits;
      suppr.disabled = d.refusSuppression !== "";
      suppr.title = d.refusSuppression;
    }

    function majLigne(tr, c) {
      const d = tr.dataset;
      d.forfait = c.forfait;
      d.admin = c.admin ? "1" : "0";
      d.series = String(c.series);
      d.refusDroits = c.refus_droits || "";
      d.refusSuppression = c.refus_suppression || "";

      tr.querySelector(".admin-forfait").value = c.forfait;
      tr.querySelector(".admin-badge-admin").classList.toggle("hidden", !c.admin);
      tr.querySelector(".c-series").textContent = String(c.series);

      const raison = tr.querySelector(".admin-raison");
      const bloque = c.forfait === "bloque";
      raison.classList.toggle("hidden", !bloque);
      const detail = c.raison_blocage || "sans raison notée";
      raison.textContent = bloque ? (c.bloque_le ? c.bloque_le + " — " : "") + detail : "";
      raison.title = bloque ? c.raison_blocage || "" : "";

      majBoutons(tr);
      majTotaux();
      filtrer();   // « bloqué », « admin » : la ligne a peut-être changé de côté
    }

    /* Le bandeau suit les actions : sans cela, « Bloqués : 2 » restait affiché
       au-dessus d'une liste qui en montre 3. */
    function majTotaux() {
      const t = totaux(lignes().map(lireLigne));
      document.querySelectorAll("[data-total]").forEach((el) => {
        if (el.dataset.total in t) el.textContent = String(t[el.dataset.total]);
      });
    }

    /* ---- la fenêtre unique ---- */
    let courant = null;   // { valider, annuler, retour }

    function ouvrir(o) {
      courant = o;
      titre.textContent = o.titre;
      texte.textContent = o.texte;
      blocRaison.classList.toggle("hidden", !o.raison);
      champRaison.value = "";
      L.effacerErreur(champRaison);
      erreurModale.classList.add("hidden");
      erreurModale.textContent = "";
      btnValider.textContent = o.bouton;
      btnValider.className = "btn " + (o.danger ? "btn-danger" : "btn-primary");
      btnValider.disabled = false;
      overlay.classList.remove("hidden");
      /* Le focus ne va jamais au bouton qui agit : une touche Entrée de
         trop suffirait à bloquer ou supprimer quelqu'un. */
      (o.raison ? champRaison : btnAnnuler).focus();
    }

    function fermer(annule) {
      if (!courant) return;
      const fin = courant;
      courant = null;
      overlay.classList.add("hidden");
      if (annule && fin.annuler) fin.annuler();
      if (fin.retour && fin.retour.isConnected) fin.retour.focus();
    }

    async function valider() {
      if (!courant) return;
      btnValider.disabled = true;
      try {
        await courant.valider();
        fermer(false);
      } catch (err) {
        btnValider.disabled = false;
        if (err && err.champ === "raison") {
          L.erreurChamp(champRaison, err.message);
          champRaison.focus();
        } else {
          // Une erreur qui ne vise pas un champ reste DANS la fenêtre, lisible
          // avec le reste : un toast se serait éteint avant d'être lu.
          erreurModale.textContent = (err && err.message) || "Une erreur est survenue.";
          erreurModale.classList.remove("hidden");
        }
      }
    }

    btnValider.addEventListener("click", valider);
    btnAnnuler.addEventListener("click", () => fermer(true));
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) fermer(true);
    });
    champRaison.addEventListener("input", () => L.effacerErreur(champRaison));
    document.addEventListener("keydown", (e) => {
      if (!courant) return;
      if (e.key === "Escape") fermer(true);
      else if (e.key === "Tab") L.piegerFocus(overlay, e);
    });

    /* ---- les trois actions ---- */

    // Forfait : le menu change, la fenêtre demande confirmation (et la raison pour bloquer).
    corps.addEventListener("change", (e) => {
      const menu = e.target.closest("select.admin-forfait");
      if (!menu) return;
      const tr = menu.closest("tr");
      const l = lireLigne(tr);
      const nouveau = menu.value;
      if (nouveau === l.forfait) return;
      ouvrir({
        titre: "Forfait de " + l.identifiant,
        texte: texteForfait(l, nouveau),
        raison: nouveau === "bloque",
        bouton: nouveau === "bloque" ? "Bloquer" : "Confirmer",
        danger: nouveau === "bloque",
        retour: menu,
        annuler: () => { menu.value = l.forfait; },   // le menu ne doit pas mentir
        valider: async () => {
          const r = await L.api("admin.forfait", { id: l.id, forfait: nouveau, raison: champRaison.value });
          majLigne(tr, r.compte);
          L.toast(r.message);
        },
      });
    });

    corps.addEventListener("click", (e) => {
      const droits = e.target.closest(".admin-droits");
      const suppr = e.target.closest(".admin-suppr");
      const bouton = droits || suppr;
      if (!bouton || bouton.disabled) return;
      const tr = bouton.closest("tr");
      const l = lireLigne(tr);

      if (droits) {
        ouvrir({
          titre: "Droits de " + l.identifiant,
          texte: texteDroits(l),
          bouton: l.admin ? "Retirer" : "Nommer administrateur",
          danger: l.admin,
          retour: bouton,
          valider: async () => {
            const r = await L.api("admin.admin", { id: l.id, admin: l.admin ? "0" : "1" });
            majLigne(tr, r.compte);
            L.toast(r.message);
          },
        });
      } else {
        ouvrir({
          titre: "Supprimer " + l.identifiant,
          texte: texteSuppression(l, adminEmail),
          bouton: "Envoyer le lien de confirmation",
          danger: true,
          retour: bouton,
          valider: async () => {
            const r = await L.api("admin.supprimer", { id: l.id });
            L.toast(r.message);
          },
        });
      }
    });

    lignes().forEach(majBoutons);
    filtrer();
  }

  /* ---- les réglages : un formulaire par ligne ---- */
  const CLE_MESSAGE = "livre.admin.message";

  function brancherReglages() {
    const L = window.Lib;

    // Le message de « ↩ .env », mis de côté avant le rechargement de la page.
    try {
      const message = sessionStorage.getItem(CLE_MESSAGE);
      if (message) {
        sessionStorage.removeItem(CLE_MESSAGE);
        L.toast(message);
      }
    } catch (e) {
      /* Stockage indisponible : rien à montrer. */
    }

    document.querySelectorAll("form.admin-reglage").forEach((form) => {
      const champ = form.querySelector("input, select");
      const enregistrer = form.querySelector(".admin-enregistrer");
      const revenir = form.querySelector(".admin-revenir");
      const pastille = form.querySelector(".admin-source");
      const cle = form.dataset.cle;

      const majBouton = () => {
        enregistrer.disabled = !reglageChange(champ.value, form.dataset.initial);
      };
      champ.addEventListener("input", () => { L.effacerErreur(champ); majBouton(); });
      champ.addEventListener("change", majBouton);

      form.addEventListener("submit", async (e) => {
        e.preventDefault();
        if (enregistrer.disabled) return;
        enregistrer.disabled = true;
        try {
          const r = await L.api("admin.reglage", { cle, valeur: champ.value });
          form.dataset.initial = r.saisie;
          champ.value = r.saisie;
          pastille.dataset.source = r.source;
          pastille.textContent = sourceTexte(r.source);
          revenir.disabled = false;
          // « En vigueur » ne se recalcule pas ici : seule config.php sait quelle
          // valeur borne l'autre. La note disparaît, la page la remontrait au rechargement.
          const note = form.querySelector(".admin-en-vigueur");
          if (note) note.classList.add("hidden");
          L.effacerErreur(champ);
          L.toast(r.message);
        } catch (err) {
          majBouton();
          // Une erreur de réglage va sous SA case, jamais dans une notification.
          L.erreurChamp(champ, (err && err.message) || "Une erreur est survenue.");
          champ.focus();
        }
      });

      revenir.addEventListener("click", async () => {
        if (revenir.disabled) return;
        revenir.disabled = true;
        try {
          const r = await L.api("admin.reglage_retablir", { cle });
          // La valeur réellement en vigueur, config.php est seul à la connaître :
          // la page se recharge et la montre. Le message attend le rechargement
          // (sessionStorage) : une notification posée maintenant disparaîtrait avec la page.
          if (r.recharger) {
            try {
              sessionStorage.setItem(CLE_MESSAGE, r.message || "");
            } catch (e) {
              /* Stockage indisponible : la pastille « .env » dit déjà ce qui s'est passé. */
            }
            window.location.reload();
          }
        } catch (err) {
          revenir.disabled = false;
          L.erreurChamp(champ, (err && err.message) || "Une erreur est survenue.");
        }
      });
    });
  }

  if (document.getElementById("admin-table")) brancher();
  if (document.getElementById("reglages")) brancherReglages();

  return { FORFAITS, normaliser, correspond, trier, sensApres, totaux, lireLigne, texteForfait, texteDroits, texteSuppression, sourceTexte, reglageChange };
})();
