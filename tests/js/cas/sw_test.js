/* =====================================================================
   sw.js — le service worker des notifications push.

   Le banc le charge comme un script ordinaire : « self » y est la fenêtre,
   dont addEventListener reçoit ses écouteurs. Les tests lui envoient de
   faux évènements (« push », « notificationclick ») et regardent ce qu'il en
   fait — ce qu'il montre, où mène un clic. Ce que fait un VRAI navigateur
   d'un vrai message push, lui, ne se joue pas ici.
   ===================================================================== */

const PORTEE = "https://exemple.test/livre/";

/** Un faux self.registration, qui note ce qu'on lui fait montrer. */
const faireRegistration = () => {
  const appels = [];
  window.registration = {
    scope: PORTEE,
    showNotification: (titre, options) => { appels.push({ titre, options }); return Promise.resolve(); },
  };
  return appels;
};

/** Envoie un faux message push au worker, et rend ce qu'il a fait afficher. */
const recevoir = (donnees) => {
  const appels = faireRegistration();
  const attentes = [];
  const e = new Event("push");
  e.data = donnees === undefined ? null : { json: () => { if (donnees instanceof Error) throw donnees; return donnees; } };
  e.waitUntil = (p) => attentes.push(p);
  window.dispatchEvent(e);
  return { appels, attentes };
};

groupe("sw.js — un message push montre une notification");

test("le titre, le corps et le tag viennent du message", () => {
  const { appels, attentes } = recevoir({ titre: "Nouveau tome disponible", corps: "« Berserk » : tome 44", url: "index.php", tag: "nouveaux-tomes" });
  egale(1, appels.length, "une seule notification");
  egale("Nouveau tome disponible", appels[0].titre, "le titre");
  egale("« Berserk » : tome 44", appels[0].options.body, "le corps");
  egale("nouveaux-tomes", appels[0].options.tag, "le tag : la notification précédente est REMPLACÉE");
  vrai(appels[0].options.renotify, "et la nouvelle se fait remarquer");
  egale("index.php", appels[0].options.data.url, "où mène le clic");
  egale(1, attentes.length, "waitUntil : le worker reste en vie le temps de l affichage");
});

test("une icône du site est jointe", () => {
  const { appels } = recevoir({ titre: "x" });
  contient("img/icone-192.png", appels[0].options.icon, "l icône");
});

test("le contenu est du TEXTE : aucune balise n est interprétée par le worker", () => {
  const { appels } = recevoir({ titre: "<img src=x onerror=alert(1)>", corps: "<b>gras</b>" });
  egale("<img src=x onerror=alert(1)>", appels[0].titre, "tel quel : showNotification n interprète aucun HTML");
  egale("<b>gras</b>", appels[0].options.body, "le corps aussi");
});

groupe("sw.js — un message en désordre montre quand même quelque chose");

test("sans titre : un titre de repli. Un navigateur exige TOUJOURS une notification", () => {
  /* userVisibleOnly : un message qui n en affiche aucune fait en montrer une
     générique par le navigateur, de son cru, ce qui est pire. */
  for (const donnees of [{}, { corps: "x" }, { titre: "" }, { titre: 12 }, { titre: null }]) {
    const { appels } = recevoir(donnees);
    egale(1, appels.length, "une notification pour " + JSON.stringify(donnees));
    egale("Ma Bibliothèque", appels[0].titre, "le titre de repli pour " + JSON.stringify(donnees));
  }
});

test("sans données, ou avec des données illisibles : la notification de repli", () => {
  egale("Ma Bibliothèque", recevoir(undefined).appels[0].titre, "aucune donnée");
  egale("Ma Bibliothèque", recevoir(new Error("pas du JSON")).appels[0].titre, "json() qui échoue");
  egale("Ma Bibliothèque", recevoir("une chaîne").appels[0].titre, "du JSON qui n est pas un objet");
  egale("Ma Bibliothèque", recevoir(null).appels[0].titre, "null");
});

test("un corps ou une adresse du mauvais type ne cassent rien", () => {
  const { appels } = recevoir({ titre: "t", corps: 42, url: { a: 1 }, tag: 7 });
  egale("", appels[0].options.body, "corps non texte : vide");
  egale("index.php", appels[0].options.data.url, "adresse non texte : la bibliothèque");
  egale("bibliotheque", appels[0].options.tag, "tag non texte : un tag par défaut (renotify en exige un)");
});

groupe("sw.js — où mène le clic : une adresse DU SITE");

test("une adresse relative est résolue dans la portée du worker", () => {
  faireRegistration();
  egale(PORTEE + "index.php", adresseDuClic("index.php"), "index.php");
  egale(PORTEE + "parametres.php", adresseDuClic("parametres.php"), "parametres.php");
  egale(PORTEE + "index.php?a=1", adresseDuClic("index.php?a=1"), "avec une requête");
});

test("une adresse d ailleurs retombe sur la bibliothèque", () => {
  faireRegistration();
  const accueil = PORTEE + "index.php";
  egale(accueil, adresseDuClic("https://hameconnage.test/vite"), "autre origine");
  egale(accueil, adresseDuClic("//hameconnage.test/vite"), "autre origine, sans schéma");
  egale(accueil, adresseDuClic("javascript:alert(1)"), "schéma javascript");
  egale(accueil, adresseDuClic("data:text/html,<script>alert(1)</script>"), "schéma data");
  egale(accueil, adresseDuClic("https://exemple.test/autre-site/page"), "même origine, mais hors de la portée du site");
});

test("une adresse absente ou du mauvais type retombe sur la bibliothèque", () => {
  faireRegistration();
  const accueil = PORTEE + "index.php";
  egale(accueil, adresseDuClic(undefined), "undefined");
  egale(accueil, adresseDuClic(null), "null");
  egale(accueil, adresseDuClic(""), "vide");
  egale(accueil, adresseDuClic(42), "un nombre");
  egale(accueil, adresseDuClic({ a: 1 }), "un objet");
});

groupe("sw.js — le site est déjà ouvert quelque part ?");

test("on montre la fenêtre du site plutôt que d en ouvrir une autre", () => {
  const fenetres = [{ url: "https://exemple.test/ailleurs/" }, { url: PORTEE + "index.php" }, { url: PORTEE + "parametres.php" }];
  egale(fenetres[1], fenetreDuSite(fenetres, PORTEE), "la première qui est dans la portée");
});

test("aucune fenêtre du site : null (il faudra en ouvrir une)", () => {
  estNul(fenetreDuSite([{ url: "https://exemple.test/ailleurs/" }], PORTEE), "toutes ailleurs");
  estNul(fenetreDuSite([], PORTEE), "aucune fenêtre");
  estNul(fenetreDuSite([{ url: undefined }], PORTEE), "une fenêtre sans adresse");
});
