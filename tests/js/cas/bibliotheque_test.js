/* =====================================================================
   js/app.js — la logique pure de la bibliothèque (window.Bibliotheque).

   Ce qui se décide sans toucher à la page : la carte voisine au clavier,
   le suivi du défilement qui cache et ramène les filtres, le groupe de
   filtres déplié, le moment où « Toutes » s allume, les bords qui ont de
   la suite quand une rangée coulisse, les textes des
   quotas, la touche qui supprime et les champs où elle efface du texte.
   Le reste d'app.js branche ces décisions sur la page ; il se
   vérifie dans un navigateur, sur une vraie bibliothèque.
   ===================================================================== */

const B = window.Bibliotheque;

/* Une grille de 4 colonnes, 10 cartes : 4 + 4 + 2.

      0  1  2  3
      4  5  6  7
      8  9            */
const GRILLE = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9].map((i) => ({
  haut: Math.floor(i / 4) * 471,
  gauche: (i % 4) * 250,
}));

groupe("Bibliotheque.voisine() — les flèches dans la grille");

test("gauche et droite suivent l ordre d affichage, d une rangée à l autre", () => {
  egale(1, B.voisine(GRILLE, 0, "suivante"), "0 → 1");
  egale(4, B.voisine(GRILLE, 3, "suivante"), "fin de rangée → début de la suivante");
  egale(3, B.voisine(GRILLE, 4, "precedente"), "et retour");
});

test("en butée, pas de voisine", () => {
  egale(-1, B.voisine(GRILLE, 9, "suivante"), "après la dernière");
  egale(-1, B.voisine(GRILLE, 0, "precedente"), "avant la première");
  egale(-1, B.voisine(GRILLE, 2, "haut"), "au-dessus de la première rangée");
  egale(-1, B.voisine(GRILLE, 8, "bas"), "sous la dernière");
});

test("Début et Fin vont aux extrémités", () => {
  egale(0, B.voisine(GRILLE, 6, "premiere"), "Début");
  egale(9, B.voisine(GRILLE, 6, "derniere"), "Fin");
});

test("haut et bas restent dans la colonne", () => {
  egale(5, B.voisine(GRILLE, 1, "bas"), "1 → 5");
  egale(9, B.voisine(GRILLE, 5, "bas"), "5 → 9");
  egale(2, B.voisine(GRILLE, 6, "haut"), "6 → 2");
});

test("vers une rangée incomplète : la carte la plus proche en abscisse", () => {
  egale(9, B.voisine(GRILLE, 6, "bas"), "sous la 3e colonne, la plus proche est la 2e");
  egale(9, B.voisine(GRILLE, 7, "bas"), "sous la 4e aussi");
  egale(8, B.voisine(GRILLE, 4, "bas"), "sous la 1re, la 1re");
});

test("sur téléphone, une seule colonne", () => {
  const liste = [0, 130, 260, 390].map((haut) => ({ haut, gauche: 12 }));
  egale(1, B.voisine(liste, 0, "bas"), "bas → la suivante");
  egale(2, B.voisine(liste, 3, "haut"), "haut → la précédente");
});

test("une carte qui n est plus affichée n a pas de voisine", () => {
  // indexOf() rend -1 quand un filtre vient de masquer la carte active.
  egale(-1, B.voisine(GRILLE, -1, "suivante"), "rang -1");
  egale(-1, B.voisine([], 0, "premiere"), "grille vide");
  egale(-1, B.voisine(GRILLE, 0, "diagonale"), "direction inconnue");
});

groupe("Bibliotheque.suiviDefilement() — les filtres reviennent quand on remonte");

const SEUIL = 10;
const GABARIT = 5000;

/** Enchaîne des positions comme autant de relevés : rend le dernier, et la suite des actions. */
function derouler(positions, depart = null, gabarit = GABARIT) {
  let etat = depart;
  const actions = [];
  let r = null;
  for (const y of positions) {
    r = B.suiviDefilement(etat, { y, max: 20000, gabarit }, SEUIL);
    etat = r.etat;
    actions.push(r.action);
  }
  return { r, actions };
}

test("tout en haut, les filtres sont à leur place", () => {
  const { r } = derouler([0]);
  egale("montrer", r.action, "montrés");
  faux(r.collee, "pas collés : ils sont dans la page");
});

test("page rechargée en cours de liste : rien ne bouge au premier relevé", () => {
  const { r } = derouler([3000]);
  estNul(r.action, "aucune action sans mouvement");
  vrai(r.collee, "collés sous la barre");
});

test("en descendant, ils s effacent passé le seuil", () => {
  const { actions } = derouler([1000, 1005, 1012]);
  egale([null, null, "cacher"], actions, "5 px : rien ; 12 px : cachés");
});

test("en remontant, ils reviennent passé le seuil", () => {
  const { actions } = derouler([1000, 1100, 1095, 1088]);
  egale([null, "cacher", null, "montrer"], actions, "5 px vers le haut : rien ; 12 px : de retour");
});

test("les petits allers-retours du pouce ne comptent pas", () => {
  // Chaque changement de sens repart de zéro : 8 px, puis 8 dans l'autre sens…
  const { actions } = derouler([1000, 1008, 1000, 1008, 1000]);
  egale([null, null, null, null, null], actions, "jamais le seuil dans un même sens");
});

test("un décalage dû à une hauteur qui change n est pas un geste", () => {
  /* Déplier « Image ▾ » agrandit les filtres : le navigateur décale la
     page d'autant pour garder la même chose sous les yeux. Pris pour une
     descente, ce décalage cachait les filtres qu'on venait d'ouvrir. */
  const avant = derouler([1500, 1460]).r.etat; // remontés : filtres montrés
  const r = B.suiviDefilement(avant, { y: 1507, max: 20000, gabarit: GABARIT + 47 }, SEUIL);
  estNul(r.action, "47 px de décalage : les filtres restent");
  egale(1507, r.etat.y, "le relevé repart de la nouvelle position");
  const suite = B.suiviDefilement(r.etat, { y: 1519, max: 20000, gabarit: GABARIT + 47 }, SEUIL);
  egale("cacher", suite.action, "une vraie descente ensuite compte normalement");
});

test("le rebond élastique en haut de page ne compte pas", () => {
  // Sur iPhone, la position passe sous zéro puis revient.
  const { actions } = derouler([30, -40, 0]);
  egale("montrer", actions[1], "sous zéro : comme tout en haut");
  egale("montrer", actions[2], "à zéro : montrés");
});

test("le rebond élastique en bas de page ne ramène pas les filtres", () => {
  // La position dépasse la fin de la page puis y revient : ce retour n'est pas une remontée.
  let etat = null;
  const releve = (y) => {
    const r = B.suiviDefilement(etat, { y, max: 8000, gabarit: GABARIT }, SEUIL);
    etat = r.etat;
    return r.action;
  };
  releve(7900);
  egale("cacher", releve(8000), "on descend jusqu en bas : cachés");
  estNul(releve(8060), "au-delà de la fin : bornée à la fin, aucun mouvement");
  estNul(releve(8000), "retour du rebond : toujours rien");
});

groupe("Bibliotheque.annonceQuota() — la limite de 150 séries");

test("sans limite (forfait illimité), rien à annoncer", () => {
  estNul(B.annonceQuota(290, 0), "quota 0");
});

test("en deçà de 90 %, rien", () => {
  estNul(B.annonceQuota(134, 150), "134 / 150");
  estNul(B.annonceQuota(0, 150), "bibliothèque vide");
});

test("dès 90 %, la place restante", () => {
  egale({ pleine: false, texte: "135 / 150 séries — encore 15 avant la limite du forfait standard." },
    B.annonceQuota(135, 150), "135 / 150");
  egale("149 / 150 séries — encore 1 avant la limite du forfait standard.",
    B.annonceQuota(149, 150).texte, "la dernière place");
});

test("pleine", () => {
  egale({ pleine: true, texte: "Bibliothèque pleine : 150 / 150 séries, la limite du forfait standard." },
    B.annonceQuota(150, 150), "150 / 150");
  vrai(B.annonceQuota(152, 150).pleine, "au-delà (forfait changé depuis) : pleine aussi");
});

groupe("Bibliotheque.texteQuotaRecherche() — le solde des recherches");

test("le solde, et quand il se reconstitue", () => {
  egale("Recherche auto : encore 27 sur 30 — le compteur repart à 30 toutes les 2 minutes.",
    B.texteQuotaRecherche({ restantes: 27, quota: 30, tranche: "2 minutes" }), "forfait standard");
  contient("encore 0 sur 120", B.texteQuotaRecherche({ restantes: 0, quota: 120, tranche: "2 minutes" }),
    "épuisé, forfait illimité");
});

groupe("Bibliotheque.ficheModifiee() — Échap ne jette plus une saisie");

const FICHE = { titre: "One Piece", auteur: "Oda", tome: "3", statut: "cours", couverture: "url:https://x/a.jpg" };

test("une fiche intacte se ferme sans question", () => {
  faux(B.ficheModifiee(FICHE, { ...FICHE }), "rien n a changé");
});

test("chaque champ compte", () => {
  vrai(B.ficheModifiee(FICHE, { ...FICHE, titre: "Naruto" }), "le titre — le cas relevé par l audit");
  vrai(B.ficheModifiee(FICHE, { ...FICHE, auteur: "" }), "l auteur");
  vrai(B.ficheModifiee(FICHE, { ...FICHE, tome: "4" }), "le tome");
  vrai(B.ficheModifiee(FICHE, { ...FICHE, statut: "termine" }), "le statut");
  vrai(B.ficheModifiee(FICHE, { ...FICHE, couverture: "" }), "l image retirée");
});

test("ce que le serveur ignorerait ne compte pas", () => {
  faux(B.ficheModifiee(FICHE, { ...FICHE, titre: "  One Piece " }), "des blancs autour du titre");
  faux(B.ficheModifiee({ ...FICHE, tome: "0" }, { ...FICHE, tome: "" }), "un tome vide vaut 0");
});

test("sans état d ouverture, pas de question", () => {
  faux(B.ficheModifiee(null, FICHE), "fiche jamais ouverte");
});

groupe("Bibliotheque.signatureCouverture() — ce qui distingue une image");

test("aucune image, une adresse, un fichier", () => {
  egale("", B.signatureCouverture(null), "pas d image");
  egale("url:https://x/a.jpg", B.signatureCouverture({ type: "url", value: "https://x/a.jpg" }), "une adresse");
  egale("fichier:a.png:10:5",
    B.signatureCouverture({ type: "file", file: { name: "a.png", size: 10, lastModified: 5 } }), "un fichier");
});

test("la même adresse, qu elle vienne de la base ou d une recherche", () => {
  // « existante » ne fait pas une image différente : rouvrir puis fermer
  // une fiche ne doit rien demander.
  egale(B.signatureCouverture({ type: "url", value: "https://x/a.jpg", existante: true }),
    B.signatureCouverture({ type: "url", value: "https://x/a.jpg" }), "même signature");
});

groupe("Bibliotheque.toucheSuppression() — Suppr sur la carte sélectionnée");

test("Suppr, et Retour arrière (la touche « delete » d un Mac)", () => {
  vrai(B.toucheSuppression({ key: "Delete" }), "Suppr");
  vrai(B.toucheSuppression({ key: "Backspace" }), "Retour arrière");
});

test("ni une autre touche, ni un raccourci", () => {
  faux(B.toucheSuppression({ key: "Enter" }), "Entrée ouvre la fiche");
  faux(B.toucheSuppression({ key: "d" }), "une lettre");
  faux(B.toucheSuppression({ key: "Delete", ctrlKey: true }), "Ctrl+Suppr");
  faux(B.toucheSuppression({ key: "Delete", shiftKey: true }), "Maj+Suppr");
  faux(B.toucheSuppression({ key: "Backspace", altKey: true }), "Alt+Retour arrière");
  faux(B.toucheSuppression({ key: "Backspace", metaKey: true }), "Cmd+Retour arrière");
});

groupe("Bibliotheque.champDeSaisie() — dans la fiche, Suppr y efface du texte");

/** Un élément de la balise voulue ; `type` pour un <input>. */
function element(balise, type) {
  const el = document.createElement(balise);
  if (type) el.type = type;
  return el;
}

test("un champ où l on écrit : la touche y efface un caractère", () => {
  vrai(B.champDeSaisie(element("input", "text")), "le Titre");
  vrai(B.champDeSaisie(element("input", "number")), "le tome lu");
  vrai(B.champDeSaisie(element("input", "url")), "l adresse de l image");
  vrai(B.champDeSaisie(element("input")), "un input sans type");
  vrai(B.champDeSaisie(element("textarea")), "une zone de texte");
  // Rattaché à la page : hors d elle, Chrome ne le dit pas modifiable.
  const editable = document.body.appendChild(element("div"));
  editable.contentEditable = "true";
  try {
    vrai(B.champDeSaisie(editable), "un contenu modifiable");
  } finally {
    editable.remove();
  }
});

test("partout ailleurs, elle supprime la série", () => {
  faux(B.champDeSaisie(element("h2")), "le titre de la fiche, focalisé à l ouverture");
  faux(B.champDeSaisie(element("button")), "un bouton");
  faux(B.champDeSaisie(element("select")), "le statut");
  faux(B.champDeSaisie(element("input", "file")), "le choix d un fichier");
  faux(B.champDeSaisie(element("input", "checkbox")), "une case");
  faux(B.champDeSaisie(document.body), "le focus rendu à la page");
  faux(B.champDeSaisie(null), "aucun élément");
});

groupe("Bibliotheque.panneauApres() — un seul groupe de filtres déplié");

test("cliquer un groupe replié l ouvre", () => {
  egale("statut", B.panneauApres("", "statut"), "Statut s ouvre");
  egale("image", B.panneauApres("", "image"), "Image s ouvre");
});

test("cliquer le groupe ouvert le referme", () => {
  egale("", B.panneauApres("statut", "statut"), "Statut");
  egale("", B.panneauApres("image", "image"), "Image");
});

test("ouvrir l autre groupe prend la place du premier", () => {
  egale("image", B.panneauApres("statut", "image"), "Statut cède à Image");
  egale("statut", B.panneauApres("image", "statut"), "Image cède à Statut");
});

test("« Toutes » replie le groupe ouvert, quel qu il soit", () => {
  egale("", B.panneauApres("statut", "toutes"), "Toutes referme Statut");
  egale("", B.panneauApres("image", "toutes"), "Toutes referme Image");
  egale("", B.panneauApres("", "toutes"), "rien d ouvert : rien ne s ouvre");
  egale("", B.panneauApres("n importe quoi", "toutes"), "un état mémorisé inconnu : replié aussi");
});

test("un autre bouton qui n est pas un groupe (Favoris) laisse le groupe comme il est", () => {
  egale("statut", B.panneauApres("statut", "favori"), "Favoris ne referme pas Statut");
  egale("image", B.panneauApres("image", "favori"), "ni Image");
  egale("", B.panneauApres("", "favori"), "rien d ouvert : rien ne s ouvre");
  egale("statut", B.panneauApres("statut", undefined), "pas de demande : rien ne change");
});

test("un état mémorisé inconnu vaut « tout replié »", () => {
  egale("image", B.panneauApres("n importe quoi", "image"), "un état mémorisé qui n existe pas");
  egale("", B.panneauApres("n importe quoi", "n importe quoi"), "ni l un ni l autre");
});

groupe("Bibliotheque.panneauMemorise() — le groupe rouvert au rechargement");

test("le groupe mémorisé revient tel quel", () => {
  egale("statut", B.panneauMemorise({ panneau: "statut" }), "Statut");
  egale("image", B.panneauMemorise({ panneau: "image" }), "Image");
  egale("", B.panneauMemorise({ panneau: "" }), "tout replié");
});

test("l ancienne mémoire (imageOuvert) rouvre Image", () => {
  egale("image", B.panneauMemorise({ statut: [], imageOuvert: true }), "ouvert");
  egale("", B.panneauMemorise({ statut: [], imageOuvert: false }), "replié");
  egale("statut", B.panneauMemorise({ panneau: "statut", imageOuvert: true }), "la nouvelle clé l emporte");
});

test("une mémoire illisible ou étrangère vaut « tout replié »", () => {
  egale("", B.panneauMemorise(null), "rien");
  egale("", B.panneauMemorise("image"), "pas un objet");
  egale("", B.panneauMemorise({ panneau: "autre" }), "un groupe qui n existe pas");
  egale("", B.panneauMemorise({ imageOuvert: "oui" }), "un booléen qui n en est pas un");
});

groupe("Bibliotheque.aucunFiltre() — quand « Toutes » s allume");

test("aucun filtre, de quelque genre que ce soit", () => {
  vrai(B.aucunFiltre({ statut: new Set(), image: new Set(), favoris: false }), "rien de posé");
});

test("un seul filtre, d un genre quelconque, l éteint", () => {
  faux(B.aucunFiltre({ statut: new Set(["cours"]), image: new Set(), favoris: false }), "un statut");
  faux(B.aucunFiltre({ statut: new Set(), image: new Set(["aucune"]), favoris: false }), "un type d image");
  faux(B.aucunFiltre({ statut: new Set(), image: new Set(), favoris: true }), "les favoris");
  faux(B.aucunFiltre({ statut: new Set(["envie"]), image: new Set(["lien"]), favoris: true }), "tous à la fois");
});

groupe("Bibliotheque.bordsDefilement() — le fondu des rangées qui coulissent");

test("une rangée qui tient en entier n a de suite d aucun côté", () => {
  const b = B.bordsDefilement({ gauche: 0, visible: 351, total: 351 });
  faux(b.gauche, "gauche");
  faux(b.droite, "droite");
  faux(B.bordsDefilement({ gauche: 0, visible: 351, total: 200 }).droite, "bien plus courte que la place");
});

test("au départ : de la suite à droite seulement", () => {
  const b = B.bordsDefilement({ gauche: 0, visible: 351, total: 495 });
  faux(b.gauche, "rien avant");
  vrai(b.droite, "« Pas d image » est plus loin");
});

test("au milieu : de la suite des deux côtés", () => {
  const b = B.bordsDefilement({ gauche: 70, visible: 351, total: 495 });
  vrai(b.gauche, "gauche");
  vrai(b.droite, "droite");
});

test("en butée à droite : de la suite à gauche seulement", () => {
  const b = B.bordsDefilement({ gauche: 144, visible: 351, total: 495 });
  vrai(b.gauche, "gauche");
  faux(b.droite, "plus rien après");
});

test("un pixel de tolérance : la butée décimale d un écran dense compte comme la butée", () => {
  faux(B.bordsDefilement({ gauche: 143.4, visible: 351, total: 495 }).droite, "à 0,6 px de la fin");
  faux(B.bordsDefilement({ gauche: 0.4, visible: 351, total: 495 }).gauche, "à 0,4 px du début");
  faux(B.bordsDefilement({ gauche: 0, visible: 351, total: 351.6 }).droite, "0,6 px de trop ne fait pas une rangée qui coulisse");
});

test("une mesure absente ou absurde ne pose aucun fondu", () => {
  faux(B.bordsDefilement(null).droite, "rien");
  faux(B.bordsDefilement({}).droite, "objet vide");
  faux(B.bordsDefilement({ gauche: NaN, visible: 351, total: 495 }).droite, "NaN");
  faux(B.bordsDefilement({ gauche: -5, visible: 351, total: 495 }).gauche, "rebond élastique négatif");
});

groupe("Bibliotheque.pagesSeries() — la bibliothèque par pages");

test("plus de séries qu'une page : le bouton se montre, avec ce qu'il ajoute", () => {
  const p = B.pagesSeries(120, 30, 1);
  egale([30, 30, 90, 30, true], [p.limite, p.affichees, p.reste, p.suivantes, p.visible], "120 séries, pages de 30, une page affichée");
  egale("Afficher 30 séries de plus", p.texte, "le texte du bouton");
  egale("30 sur 120 séries affichées", p.info, "le décompte");
});

test("chaque clic ajoute une page, la dernière n'ajoute que le reste", () => {
  egale([60, 60, 60], [B.pagesSeries(120, 30, 2).limite, B.pagesSeries(120, 30, 2).affichees, B.pagesSeries(120, 30, 2).reste], "deux pages");
  const derniere = B.pagesSeries(125, 30, 4);
  egale([120, 5, true], [derniere.affichees, derniere.reste, derniere.visible], "quatre pages sur cinq");
  egale("Afficher 5 séries de plus", derniere.texte, "il ne reste que 5 séries");
  egale("Afficher 1 série de plus", B.pagesSeries(121, 30, 4).texte, "une seule série : pas de pluriel");
  egale(false, B.pagesSeries(125, 30, 5).visible, "tout est montré : le bouton disparaît");
  egale("125 sur 125 séries affichées", B.pagesSeries(125, 30, 5).info, "le décompte final");
});

test("tout tient dans une page, ou rien à montrer : pas de bouton", () => {
  egale(false, B.pagesSeries(30, 30, 1).visible, "pile une page");
  egale(false, B.pagesSeries(12, 30, 1).visible, "moins qu'une page");
  egale(false, B.pagesSeries(0, 30, 1).visible, "aucune série");
  egale("0 sur 0 séries affichées", B.pagesSeries(0, 30, 1).info, "décompte à zéro");
});

test("sans taille de page (0) : aucun découpage", () => {
  const p = B.pagesSeries(120, 0, 1);
  egale([120, 120, 0, false], [p.limite, p.affichees, p.reste, p.visible], "tout est montré");
});

test("des nombres absurdes sont ramenés à du sens, jamais NaN", () => {
  egale(false, B.pagesSeries("x", "y", "z").visible, "du texte");
  egale(30, B.pagesSeries(120, 30, 0).affichees, "zéro page → une");
  egale(30, B.pagesSeries(120, 30, -3).affichees, "pages négatives → une");
  egale(60, B.pagesSeries("120", "30", "2").affichees, "des nombres en texte (attribut data-)");
  egale(30, B.pagesSeries(120, 30.9, 1).affichees, "décimaux : la partie entière");
});

groupe("Bibliotheque.placerBulle() — où poser la bulle d un ⓘ");

/* Un ⓘ de 24 px sur 20, et une bulle dont la hauteur dépend de sa largeur
   (le texte passe à la ligne : 3200 px² de texte). */
const ancreA = (left, top) => ({ left, right: left + 24, top, bottom: top + 20 });
const hauteurPour = (largeur) => Math.ceil(3200 / largeur / 17) * 17 + 16;
const ecran = { largeur: 375, hauteur: 812 };

test("à droite du ⓘ, son bas sur le bas du ⓘ : elle monte, comme dans le dessin", () => {
  const ancre = ancreA(100, 600);
  const p = Bibliotheque.placerBulle(ancre, ecran, hauteurPour);
  egale("droite", p.cote, "à droite");
  egale(ancre.right + 6, p.left, "à 6 px du ⓘ");
  egale(200, p.largeur, "la largeur maximale, il y a la place");
  egale(Math.round(ancre.bottom - hauteurPour(p.largeur)), p.top, "le bas de la bulle sur le bas du ⓘ");
});

test("elle ne dépasse jamais le bord droit : la largeur se réduit, dès 110 px de place", () => {
  const ancre = ancreA(199, 600);          // le cas du téléphone : 375 - 8 - 229 = 138 px à droite
  const p = Bibliotheque.placerBulle(ancre, ecran, hauteurPour);
  egale("droite", p.cote, "encore à droite : 138 px suffisent (le dessin de l utilisateur)");
  egale(138, p.largeur, "la place qui reste");
  vrai(p.left + p.largeur <= ecran.largeur - 8, "à 8 px du bord");
  const juste = Bibliotheque.placerBulle(ancreA(231, 600), ecran, hauteurPour); // 375 - 8 - 261 = 106 px
  egale("dessus", juste.cote, "106 px : trop étroit, elle passe au-dessus");
});

test("pas assez de place à droite : au-dessus du ⓘ, jamais sur la mention à sa gauche", () => {
  const ancre = ancreA(300, 600);          // 375 - 8 - 330 = 37 px à droite
  const p = Bibliotheque.placerBulle(ancre, ecran, hauteurPour);
  egale("dessus", p.cote, "au-dessus");
  egale(200, p.largeur, "largeur maximale : il y a la place");
  vrai(p.top + hauteurPour(p.largeur) <= ancre.top, "elle finit au-dessus du ⓘ : la ligne de la mention reste lisible");
  vrai(p.left >= 8 && p.left + p.largeur <= ecran.largeur - 8, "dans la fenêtre");
  vrai(p.left <= (ancre.left + ancre.right) / 2 && p.left + p.largeur >= (ancre.left + ancre.right) / 2, "au-dessus du ⓘ, pas à côté");
});

test("écran étroit : centrée sur le ⓘ autant que la fenêtre le permet", () => {
  const etroit = { largeur: 200, hauteur: 600 };
  const ancre = ancreA(90, 400);
  const p = Bibliotheque.placerBulle(ancre, etroit, hauteurPour);
  egale("dessus", p.cote, "au-dessus");
  egale(184, p.largeur, "tout l écran moins les marges");
  egale(8, p.left, "calée contre la marge gauche, pas au-delà");
  vrai(p.top + hauteurPour(p.largeur) <= ancre.top, "au-dessus du ⓘ, sans le recouvrir");
});

test("ⓘ tout en haut de la fenêtre et pas de place à droite : au-dessous du ⓘ", () => {
  const ancre = ancreA(300, 10);
  const p = Bibliotheque.placerBulle(ancre, ecran, hauteurPour);
  egale("dessous", p.cote, "au-dessous");
  vrai(p.top >= ancre.bottom, "sous le ⓘ : au-dessus, il n y a pas la place");
});
test("jamais hors de la fenêtre : ni au-dessus ni au-dessous", () => {
  const haut = Bibliotheque.placerBulle(ancreA(100, 5), ecran, hauteurPour);
  vrai(haut.top >= 8, "ⓘ collé en haut : la bulle reste à 8 px du haut (" + haut.top + ")");
  const bas = Bibliotheque.placerBulle(ancreA(100, 800), ecran, hauteurPour);
  vrai(bas.top + hauteurPour(bas.largeur) <= ecran.hauteur - 8, "ⓘ collé en bas : la bulle reste dans la fenêtre");
});

test("une bulle plus haute que la fenêtre se cale en haut, sans valeur négative", () => {
  const p = Bibliotheque.placerBulle(ancreA(100, 100), { largeur: 375, hauteur: 100 }, () => 500);
  egale(8, p.top, "calée sur la marge haute");
});

test("des valeurs absurdes ne donnent jamais NaN", () => {
  const p = Bibliotheque.placerBulle(ancreA(100, 100), { largeur: NaN, hauteur: undefined }, () => NaN);
  for (const cle of ["left", "top", "largeur"]) vrai(Number.isFinite(p[cle]), cle + " est un nombre fini");
  vrai(["droite", "dessus", "dessous"].includes(p.cote), "un côté connu");
});
