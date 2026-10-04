/* =====================================================================
   js/admin.js — la page d'administration : chercher, trier, et ce que
   disent les fenêtres de confirmation.

   Le branchement sur la page (écouteurs, appels à l'API) reste hors de
   portée : il se vérifie à la main. Ici, la logique pure de window.Admin.
   ===================================================================== */

const A = window.Admin;

function compte(m = {}) {
  return Object.assign(
    { id: 1, identifiant: "marco", email: "marco@exemple.test", confirme: true, photo: true,
      forfait: "standard", admin: false, google: false, moi: false, inscrit: 1000, series: 5 },
    m
  );
}

const ids = (lignes) => lignes.map((l) => l.id).join(",");

groupe("Admin.correspond() — chercher");

test("une recherche vide trouve tout le monde", () => {
  vrai(A.correspond(compte(), ""), "vide");
  vrai(A.correspond(compte(), "   "), "des espaces");
});

test("dans l identifiant, l adresse et le numéro", () => {
  vrai(A.correspond(compte({ id: 42 }), "42"), "le numéro");
  vrai(A.correspond(compte(), "marc"), "un bout d identifiant");
  vrai(A.correspond(compte(), "exemple.test"), "un bout d adresse");
  faux(A.correspond(compte(), "zoé"), "ce qui n y est pas");
});

test("accents et casse ignorés", () => {
  vrai(A.correspond(compte({ identifiant: "Élodie" }), "elodie"), "É → e");
  vrai(A.correspond(compte({ identifiant: "élodie" }), "ÉLODIE"), "la casse");
});

test("tous les mots doivent se retrouver, dans n importe quel ordre", () => {
  const l = compte({ identifiant: "marco", email: "marco@exemple.test" });
  vrai(A.correspond(l, "marco exemple"), "deux mots présents");
  vrai(A.correspond(l, "exemple marco"), "dans l autre ordre");
  faux(A.correspond(l, "marco absent"), "un mot manque : pas de résultat");
});

test("le forfait se cherche par son nom : « bloqué », « illimité »", () => {
  vrai(A.correspond(compte({ forfait: "bloque" }), "bloqué"), "bloqué, avec l accent");
  vrai(A.correspond(compte({ forfait: "illimite" }), "illimite"), "illimité, sans");
  faux(A.correspond(compte({ forfait: "standard" }), "bloqué"), "un compte standard n est pas bloqué");
});

test("des mots tiennent lieu de filtres : admin, Google, non confirmé, sans image, sans série", () => {
  vrai(A.correspond(compte({ admin: true }), "admin"), "admin");
  faux(A.correspond(compte({ admin: false }), "admin"), "pas admin");
  vrai(A.correspond(compte({ google: true }), "google"), "Google");
  vrai(A.correspond(compte({ confirme: false }), "non confirmé"), "non confirmé");
  faux(A.correspond(compte({ confirme: true }), "non confirmé"), "confirmé : pas dans « non confirmé »");
  vrai(A.correspond(compte({ photo: false }), "sans image"), "sans image");
  faux(A.correspond(compte({ photo: true }), "sans image"), "avec image");
  vrai(A.correspond(compte({ photo: false }), "sans photo"), "l'ancien mot, « sans photo », reste compris");
  faux(A.correspond(compte({ photo: true }), "sans photo"), "et ne trouve pas ceux qui en ont une");
  vrai(A.correspond(compte({ series: 0 }), "sans série"), "sans série");
  faux(A.correspond(compte({ series: 3 }), "sans série"), "avec des séries");
});

test("on cumule : les non confirmés dont l adresse est chez un domaine donné", () => {
  const spam = compte({ confirme: false, email: "x@jetable.test" });
  vrai(A.correspond(spam, "non confirmé jetable"), "les deux conditions");
  faux(A.correspond(compte({ email: "x@jetable.test" }), "non confirmé jetable"), "confirmé : écarté");
});

groupe("Admin.trier() — l ordre des colonnes");

test("par numéro, croissant puis décroissant", () => {
  const l = [compte({ id: 3 }), compte({ id: 1 }), compte({ id: 2 })];
  egale("1,2,3", ids(A.trier(l, "id", 1)), "croissant");
  egale("3,2,1", ids(A.trier(l, "id", -1)), "décroissant");
});

test("la liste d origine n est pas modifiée", () => {
  const l = [compte({ id: 2 }), compte({ id: 1 })];
  A.trier(l, "id", 1);
  egale("2,1", ids(l), "intacte");
});

test("par identifiant, accents et casse ignorés", () => {
  const l = [compte({ id: 1, identifiant: "zoé" }), compte({ id: 2, identifiant: "Élodie" }), compte({ id: 3, identifiant: "alex" })];
  egale("3,2,1", ids(A.trier(l, "identifiant", 1)), "alex, Élodie, zoé");
});

test("par forfait : illimité, standard, bloqué — du plus haut au plus bas", () => {
  const l = [compte({ id: 1, forfait: "bloque" }), compte({ id: 2, forfait: "standard" }), compte({ id: 3, forfait: "illimite" })];
  egale("3,2,1", ids(A.trier(l, "forfait", 1)), "dans l ordre du menu");
  egale("1,2,3", ids(A.trier(l, "forfait", -1)), "et en sens inverse");
});

test("Admin.FORFAITS : le menu garde cet ordre", () => {
  egale("illimite,standard,bloque", Object.keys(A.FORFAITS).join(","), "illimité, standard, bloqué");
});

test("par inscription et par nombre de séries", () => {
  const l = [compte({ id: 1, inscrit: 300, series: 1 }), compte({ id: 2, inscrit: 100, series: 9 }), compte({ id: 3, inscrit: 200, series: 5 })];
  egale("2,3,1", ids(A.trier(l, "inscrit", 1)), "du plus ancien au plus récent");
  egale("2,3,1", ids(A.trier(l, "series", -1)), "du plus de séries au moins");
});

test("les booléens : confirmés d abord en décroissant", () => {
  const l = [compte({ id: 1, confirme: false }), compte({ id: 2, confirme: true }), compte({ id: 3, photo: false })];
  egale("2,3,1", ids(A.trier(l, "confirme", -1)), "confirmés avant les autres, ancienneté à égalité");
  egale("3,1,2", ids(A.trier(l, "photo", 1)), "sans image avant ceux qui en ont");
});

test("à égalité, le plus ancien compte passe d abord — quel que soit le sens", () => {
  const l = [compte({ id: 5, series: 2 }), compte({ id: 4, series: 2 }), compte({ id: 6, series: 2 })];
  egale("4,5,6", ids(A.trier(l, "series", 1)), "croissant");
  egale("4,5,6", ids(A.trier(l, "series", -1)), "décroissant : le tri est stable, pas inversé");
});

test("une colonne inconnue ne trie pas, sans casser", () => {
  const l = [compte({ id: 2 }), compte({ id: 1 })];
  egale("2,1", ids(A.trier(l, "inconnue", 1)), "ordre d origine");
});

groupe("Admin.sensApres() — cliquer une colonne");

test("une nouvelle colonne se trie croissant, la même s inverse", () => {
  const a = A.sensApres({ cle: "id", sens: 1 }, "email");
  egale("email:1", a.cle + ":" + a.sens, "nouvelle colonne : croissant");
  const b = A.sensApres(a, "email");
  egale("email:-1", b.cle + ":" + b.sens, "même colonne : inversé");
  const c = A.sensApres(b, "email");
  egale("email:1", c.cle + ":" + c.sens, "et de nouveau");
});

test("sans état précédent, on part croissant", () => {
  const a = A.sensApres(null, "id");
  egale("id:1", a.cle + ":" + a.sens, "premier clic");
});

groupe("Admin.totaux() — le bandeau suit les actions");

test("les comptes, la confirmation, les forfaits, les administrateurs, les séries", () => {
  const t = A.totaux([
    compte({ id: 1, admin: true, series: 10 }),
    compte({ id: 2, confirme: false, series: 0 }),
    compte({ id: 3, forfait: "bloque", series: 3 }),
    compte({ id: 4, forfait: "illimite", series: 200 }),
    compte({ id: 5, forfait: "bloque", admin: true, confirme: false, series: 1 }),
  ]);
  egale("5,3,2,2,1,2,214", [t.comptes, t.confirmes, t.nonConfirmes, t.bloques, t.illimites, t.admins, t.series].join(","),
    "comptes, confirmés, non confirmés, bloqués (dont un administrateur), illimités, administrateurs, séries");
});

test("aucun compte : des zéros", () => {
  egale("0,0,0,0,0,0,0", Object.values(A.totaux([])).join(","), "tout à zéro");
});

groupe("Réglages — la pastille et le bouton Enregistrer");

test("sourceTexte() : le mot de la pastille", () => {
  egale("modifié", A.sourceTexte("base"), "changé dans la page");
  egale(".env", A.sourceTexte("env"), "le .env le fixe");
  egale("défaut", A.sourceTexte("defaut"), "rien nulle part");
  egale("", A.sourceTexte("inconnue"), "une origine inconnue ne dit rien");
});

test("reglageChange() : le bouton ne s'allume que pour une vraie différence", () => {
  vrai(A.reglageChange("6", "5"), "une autre valeur");
  faux(A.reglageChange("5", "5"), "la même");
  faux(A.reglageChange(" 5 ", "5"), "la même, avec des espaces");
  faux(A.reglageChange("", "5"), "une case vidée n'est pas une valeur");
  faux(A.reglageChange("   ", "5"), "des espaces non plus");
  vrai(A.reglageChange("0", "5"), "zéro est une valeur");
  faux(A.reglageChange(5, "5"), "un nombre et sa chaîne");
  faux(A.reglageChange(null, "5"), "null");
});

groupe("Admin.lireLigne() — ce que la page porte dans ses attributs");

test("les attributs deviennent des types", () => {
  terrain(
    '<table><tbody><tr id="t" data-id="12" data-identifiant="marco" data-email="m@exemple.test" ' +
    'data-confirme="1" data-photo="0" data-forfait="bloque" data-admin="1" data-google="0" data-moi="1" ' +
    'data-inscrit="1790000000" data-series="7" data-refus-droits="non" data-refus-suppression=""></tr></tbody></table>'
  );
  const l = A.lireLigne(document.getElementById("t"));
  egale(12, l.id, "id");
  egale("marco", l.identifiant, "identifiant");
  vrai(l.confirme === true && l.photo === false, "confirmé, sans image");
  egale("bloque", l.forfait, "forfait");
  vrai(l.admin === true && l.google === false && l.moi === true, "admin, pas Google, c est moi");
  egale(1790000000, l.inscrit, "inscription");
  egale(7, l.series, "séries");
  egale("non", l.refusDroits, "le refus des droits");
  egale("", l.refusSuppression, "et la suppression est permise");
});

test("des attributs absents donnent des valeurs sûres", () => {
  terrain('<table><tbody><tr id="t"></tr></tbody></table>');
  const l = A.lireLigne(document.getElementById("t"));
  egale(0, l.id, "id");
  egale("standard", l.forfait, "forfait");
  vrai(l.confirme === false && l.admin === false, "rien n est vrai par défaut");
});

groupe("Les phrases des fenêtres");

test("bloquer : ce qui change, l e-mail, la raison", () => {
  const t = A.texteForfait(compte(), "bloque");
  contient("« marco »", t, "de qui on parle");
  contient("ne pourra plus créer, modifier ni supprimer", t, "ce qui change");
  contient("prévenu par e-mail", t, "il en sera prévenu");
  contient("raison", t, "avec la raison");
});

test("passer en illimité", () => {
  contient("illimité", A.texteForfait(compte(), "illimite"), "le forfait");
  contient("e-mail", A.texteForfait(compte(), "illimite"), "un e-mail part");
});

test("rétablir un compte bloqué, ou repasser au standard", () => {
  contient("Rétablir", A.texteForfait(compte({ forfait: "bloque" }), "standard"), "depuis bloqué");
  contient("forfait standard", A.texteForfait(compte({ forfait: "illimite" }), "standard"), "depuis illimité");
});

test("une adresse non confirmée : la fenêtre dit qu aucun e-mail ne partira", () => {
  for (const f of ["bloque", "illimite", "standard"]) {
    contient("aucun e-mail ne partira", A.texteForfait(compte({ confirme: false }), f), f);
    sans("aucun e-mail ne partira", A.texteForfait(compte({ confirme: true }), f), f + " confirmé");
  }
});

test("les droits : nommer, ou retirer", () => {
  contient("Donner à « marco »", A.texteDroits(compte({ admin: false })), "nommer");
  contient("changer leur forfait", A.texteDroits(compte({ admin: false })), "ce que cela permet");
  contient("Retirer à « marco »", A.texteDroits(compte({ admin: true })), "retirer");
});

test("la suppression : un e-mail part vers ADMIN_EMAIL, rien n est supprimé tout de suite", () => {
  const t = A.texteSuppression(compte({ series: 12 }), "admin@exemple.test");
  contient("« marco »", t, "de qui on parle");
  contient("12 série(s)", t, "ce qui sera perdu");
  contient("admin@exemple.test", t, "où part le lien");
  contient("Rien n'est supprimé tout de suite", t, "et que rien n'est fait encore");
});

groupe("Admin.groupesReplies() et basculerGroupe() — replier un groupe de réglages");

test("la liste mémorisée se lit, et un texte absent ou cassé donne une liste vide", () => {
  egale(["quotas", "durees"], A.groupesReplies('["quotas","durees"]'), "une liste");
  egale([], A.groupesReplies(null), "rien de mémorisé (getItem rend null)");
  egale([], A.groupesReplies(""), "vide");
  egale([], A.groupesReplies("{pas du json"), "cassé");
  egale([], A.groupesReplies('{"quotas":true}'), "un objet : pas une liste");
  egale([], A.groupesReplies('"quotas"'), "un texte : pas une liste");
});

test("seuls des noms de groupe sont gardés", () => {
  egale(["quotas"], A.groupesReplies('["quotas", 3, null, "", {"a":1}, ["x"]]'), "des nombres, null, vide, objets : écartés");
});

test("replier ajoute le groupe, rouvrir le retire", () => {
  egale(["quotas"], A.basculerGroupe([], "quotas", true), "replié");
  egale(["quotas", "durees"], A.basculerGroupe(["quotas"], "durees", true), "un second");
  egale(["durees"], A.basculerGroupe(["quotas", "durees"], "quotas", false), "rouvert");
});

test("jamais deux fois le même groupe", () => {
  egale(["quotas"], A.basculerGroupe(["quotas"], "quotas", true), "replier deux fois");
  egale([], A.basculerGroupe([], "quotas", false), "rouvrir ce qui est ouvert");
});

test("la liste d'origine n'est pas modifiée", () => {
  const avant = ["quotas"];
  A.basculerGroupe(avant, "durees", true);
  A.basculerGroupe(avant, "quotas", false);
  egale(["quotas"], avant, "intacte");
});

test("une liste absurde ne casse rien", () => {
  egale(["quotas"], A.basculerGroupe(null, "quotas", true), "null");
  egale(["quotas"], A.basculerGroupe(undefined, "quotas", true), "undefined");
  egale([], A.basculerGroupe("quotas", "quotas", false), "un texte");
});