/* =====================================================================
   Micro-lanceur de tests JavaScript — le pendant de tests/lanceur.php :
   mêmes noms, même esprit. Aucune dépendance, et pas besoin de Node.

   Les tests tournent dans un vrai navigateur, sur la page banc.html : à
   la main, en l'ouvrant, ou sans fenêtre par « php tests/lancer.php »,
   qui lance Edge ou Chrome en mode headless et relit le compte rendu
   déposé dans la page.

       groupe("Lib.urlImageAcceptee()");

       test("une adresse https est acceptée", () => {
         vrai(Lib.urlImageAcceptee("https://exemple.test/a.png"), "https");
       });

   Comme en PHP, une assertion qui échoue interrompt SON test, et lui
   seul. Chargé AVANT le code testé : une erreur au chargement de
   commun.js ou d'app.js est ainsi relevée, et compte comme un échec.
   ===================================================================== */

"use strict";

const Banc = (() => {
  const lignes = [];
  const fichiersVus = new Set();
  let fichierCourant = "";
  let total = 0;
  let echecs = 0;

  class EchecAssertion extends Error {}

  const nomDe = (src) => String(src || "").split("/").pop().replace(/\.js$/, "");

  /** Un en-tête par fichier de cas, comme lancer.php le fait en PHP. */
  function suivreFichier() {
    const nom = document.currentScript ? nomDe(document.currentScript.src) : "?";
    if (nom === fichierCourant) return;
    fichierCourant = nom;
    fichiersVus.add(nom);
    lignes.push("", "-- " + nom + " " + "-".repeat(Math.max(1, 55 - nom.length)));
  }

  /** Une valeur lisible dans un message d'échec. */
  function montrer(v) {
    if (typeof v === "string") return JSON.stringify(v.length > 160 ? v.slice(0, 160) + "…" : v);
    if (v instanceof Element) return "<" + v.tagName.toLowerCase() + (v.id ? "#" + v.id : "") + ">";
    if (v === undefined) return "undefined";
    try {
      return JSON.stringify(v);
    } catch (e) {
      return String(v);
    }
  }

  /** La ligne du fichier de cas d'où l'assertion a été appelée (ou d'où
      l'erreur est partie, si on lui passe sa pile). */
  function ou(pile = new Error().stack) {
    const cadre = String(pile || "").split("\n").find((l) => /\/cas\/[^/]+\.js:\d+/.test(l));
    const m = cadre && cadre.match(/\/cas\/([^/]+\.js):(\d+)/);
    return m ? m[1] + ":" + m[2] : "?";
  }

  function echouer(description, detail = "") {
    throw new EchecAssertion("      -> " + description + "\n" + detail + "      " + ou());
  }

  /* Une erreur hors de tout test : au chargement du code testé, d'un
     fichier de cas (erreur de syntaxe), ou d'un script introuvable. */
  window.addEventListener("error", (e) => {
    echecs++;
    if (e.target && e.target.tagName === "SCRIPT") {
      lignes.push("", "    [INTROUVABLE] " + e.target.getAttribute("src"));
      return;
    }
    lignes.push("", "    [ERREUR HORS TEST] " + e.message + " (" + nomDe(e.filename) + ".js:" + e.lineno + ")");
  }, true);

  return {
    EchecAssertion, suivreFichier, montrer, echouer,

    groupe(titre) {
      suivreFichier();
      lignes.push("", "  " + titre);
    },

    test(nom, corps) {
      suivreFichier();
      total++;
      document.getElementById("terrain").replaceChildren(); // un terrain vierge pour chaque test
      try {
        corps();
        lignes.push("    [ok]     " + nom);
      } catch (e) {
        echecs++;
        if (e instanceof EchecAssertion) {
          lignes.push("    [ECHEC]  " + nom, e.message);
        } else {
          lignes.push("    [ERREUR] " + nom, "      " + e.name + " : " + e.message + "  (" + ou(e.stack) + ")");
        }
      }
    },

    /** Dernier script de banc.html : le compte rendu, lu par lancer.php. */
    terminer() {
      /* Chaque fichier de cas chargé doit avoir exécuté au moins un test.
         Sinon il est introuvable, ou une erreur de syntaxe l'a empêché de
         tourner : ses tests disparaîtraient sans que le total le dise. */
      document.querySelectorAll('script[src^="cas/"]').forEach((s) => {
        const nom = nomDe(s.src);
        if (fichiersVus.has(nom)) return;
        echecs++;
        lignes.push("", "-- " + nom, "    [VIDE] aucun test exécuté : fichier introuvable, "
          + "erreur de syntaxe, ou arrêt avant le premier test.");
      });
      if (total === 0) echecs++;

      lignes.push("", "##RESULTAT##" + total + "|" + echecs);
      document.getElementById("compte-rendu").textContent = lignes.join("\n").replace(/^\n+/, "");
      document.title = (echecs ? "ÉCHEC" : "OK") + " — " + total + " test(s) JavaScript";
    },
  };
})();

/* ---------------------------------------------------------------------
   Ce qu'utilisent les fichiers de cas
   --------------------------------------------------------------------- */

const groupe = Banc.groupe;
const test = Banc.test;

/** Le conteneur où poser le HTML d'un test. Vidé avant chaque test. */
function terrain(html = "") {
  const t = document.getElementById("terrain");
  t.innerHTML = html; // HTML écrit par le test lui-même, jamais une donnée
  return t;
}

/** Égalité stricte ; pour un tableau ou un objet, égalité de contenu. */
function egale(attendu, obtenu, description) {
  const pareil = attendu !== null && typeof attendu === "object"
    ? JSON.stringify(attendu) === JSON.stringify(obtenu)
    : attendu === obtenu;
  if (!pareil) {
    Banc.echouer(description, "      attendu : " + Banc.montrer(attendu) + "\n"
      + "      obtenu  : " + Banc.montrer(obtenu) + "\n");
  }
}

function differe(interdit, obtenu, description) {
  if (interdit === obtenu) Banc.echouer(description, "      valeur interdite obtenue : " + Banc.montrer(obtenu) + "\n");
}

function vrai(condition, description) {
  if (condition !== true) Banc.echouer(description, "      obtenu  : " + Banc.montrer(condition) + "\n");
}

function faux(condition, description) {
  if (condition !== false) Banc.echouer(description, "      obtenu  : " + Banc.montrer(condition) + "\n");
}

function contient(aiguille, botte, description) {
  if (!String(botte).includes(aiguille)) {
    Banc.echouer(description, "      cherché : " + Banc.montrer(aiguille) + "\n"
      + "      dans    : " + Banc.montrer(botte) + "\n");
  }
}

function sans(aiguille, botte, description) {
  if (String(botte).includes(aiguille)) {
    Banc.echouer(description, "      ne devait pas contenir : " + Banc.montrer(aiguille) + "\n"
      + "      dans                   : " + Banc.montrer(botte) + "\n");
  }
}

function estNul(valeur, description) {
  if (valeur !== null) Banc.echouer(description, "      obtenu  : " + Banc.montrer(valeur) + "\n");
}
