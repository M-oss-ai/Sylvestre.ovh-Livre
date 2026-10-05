/* =====================================================================
   js/app.js — la logique pure de la bibliothèque (window.Bibliotheque).

   Ce qui se décide sans toucher à la page : la carte voisine au clavier,
   la barre des filtres qui suit le défilement (et se cache pendant la saisie sur téléphone), le groupe de
   filtres déplié, le moment où « Toutes » s allume, les bords qui ont de
   la suite quand une rangée coulisse, les textes des
   quotas, la touche qui supprime et les champs où elle efface du texte,
   le tri (plus récent, tome, tomes restants, et la flèche de chaque bouton) et le total « N séries · N tomes lus ».
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

groupe("Bibliotheque.positionBarre() — la barre suit le défilement au pixel près");

const GABARIT = 5000;
const HAUTEUR = 106; // la barre : deux rangées de filtres, filet compris

/** Enchaîne des positions comme autant de relevés : rend le dernier résultat et la suite des décalages. */
function derouler(positions, depart = null, gabarit = GABARIT, hauteur = HAUTEUR) {
  let etat = depart;
  const decalages = [];
  let r = null;
  for (const y of positions) {
    r = B.positionBarre(etat, { y, max: 20000, gabarit, hauteur });
    etat = r.etat;
    decalages.push(r.decalage);
  }
  return { r, decalages };
}

test("tout en haut, la barre est à sa place : entière, et pas collée", () => {
  const { r } = derouler([0]);
  egale(0, r.decalage, "aucun décalage");
  faux(r.collee, "pas collée : elle est dans la page");
});

test("page rechargée en cours de liste : la barre est entière au premier relevé", () => {
  const { r } = derouler([3000]);
  egale(0, r.decalage, "rien n a bougé encore");
  vrai(r.collee, "collée sous la barre du haut");
});

test("en descendant, elle remonte exactement de la distance parcourue", () => {
  const { decalages } = derouler([1000, 1030, 1070, 1071]);
  egale([0, 30, 70, 71], decalages, "30 px de défilement : 30 px ; puis 40 de plus : 70 ; puis 1 : 71");
});

test("elle ne remonte pas plus que sa hauteur : cachée, elle reste cachée", () => {
  const { decalages } = derouler([1000, 1100, 1500, 3000]);
  egale([0, 100, HAUTEUR, HAUTEUR], decalages, "au plus la hauteur de la barre");
});

test("en remontant, elle revient à la même vitesse que la page", () => {
  const { decalages } = derouler([1000, 1300, 1280, 1250]);
  egale([0, HAUTEUR, HAUTEUR - 20, HAUTEUR - 50], decalages, "20 px de remontée : 20 px de barre ; 30 de plus : 50");
});

test("descendre beaucoup puis remonter un peu : seul le bas de la barre se voit, « Trier par » et pas les filtres", () => {
  const { r } = derouler([1000, 2000, 1960]);
  egale(66, r.decalage, "remontée de 40 px sur 106");
  egale(40, HAUTEUR - r.decalage, "il n en reste que 40 px de visibles : le bas de la barre, la rangée du tri");
  const tout = derouler([1000, 2000, 1960, 1894]);
  egale(0, tout.r.decalage, "il faut remonter de toute sa hauteur pour revoir les filtres");
});

test("arrêtée en route, elle reste à moitié visible", () => {
  const { decalages } = derouler([1000, 1050, 1050, 1050]);
  egale([0, 50, 50, 50], decalages, "pas de retour automatique à tout caché ni tout montré");
});

test("un petit mouvement fait bouger la barre d autant, dans les deux sens", () => {
  const { decalages } = derouler([1000, 1003, 1000, 1008, 1000]);
  egale([0, 3, 0, 8, 0], decalages, "plus de seuil de dix pixels : chaque pixel compte");
});

test("elle ne remonte jamais plus que la page : pas de trou entre elle et les séries", () => {
  // Une hauteur change (liste filtrée, page raccourcie) : le défilement est ramené, la barre suit.
  const avant = { y: 100, decalage: 100, gabarit: GABARIT };
  const r = B.positionBarre(avant, { y: 30, max: 20000, gabarit: GABARIT + 900, hauteur: HAUTEUR });
  egale(30, r.decalage, "décalage ≤ position");
  const haut = B.positionBarre(avant, { y: 30, max: 20000, gabarit: GABARIT, hauteur: HAUTEUR });
  egale(30, haut.decalage, "même sans changement de hauteur : 100 − 70 = 30, et jamais plus que y");
});

test("un décalage dû à une hauteur qui change n est pas un geste", () => {
  /* Déplier « Image ▾ » agrandit les filtres : le navigateur décale la page d'autant pour
     garder la même chose sous les yeux. La barre ne doit pas bouger avec ce décalage. */
  const avant = derouler([1500, 1550]).r.etat; // descendus de 50 px : la barre est remontée de 50
  const r = B.positionBarre(avant, { y: 1597, max: 20000, gabarit: GABARIT + 47, hauteur: HAUTEUR });
  egale(50, r.decalage, "47 px de décalage : la barre reste où elle est");
  egale(1597, r.etat.y, "le relevé repart de la nouvelle position");
  const suite = B.positionBarre(r.etat, { y: 1607, max: 20000, gabarit: GABARIT + 47, hauteur: HAUTEUR });
  egale(60, suite.decalage, "une vraie descente ensuite compte normalement");
});

test("le rebond élastique en haut de page ne compte pas", () => {
  // Sur iPhone, la position passe sous zéro puis revient.
  const { decalages, r } = derouler([60, 100, -40, 0]);
  egale([0, 40, 0, 0], decalages, "sous zéro : comme tout en haut, la barre est entière");
  faux(r.collee, "et dans la page");
});

test("le rebond élastique en bas de page ne ramène pas la barre", () => {
  // La position dépasse la fin de la page puis y revient : ce retour n'est pas une remontée.
  let etat = null;
  const releve = (y) => {
    const r = B.positionBarre(etat, { y, max: 8000, gabarit: GABARIT, hauteur: HAUTEUR });
    etat = r.etat;
    return r.decalage;
  };
  releve(7900);
  egale(100, releve(8000), "on descend jusqu en bas : la barre a suivi");
  egale(100, releve(8060), "au-delà de la fin : bornée à la fin, aucun mouvement");
  egale(100, releve(8000), "retour du rebond : toujours rien");
});

test("la barre qui rétrécit ramène son décalage à sa nouvelle hauteur", () => {
  const cachee = derouler([1000, 2000]).r.etat; // décalage 106
  const r = B.positionBarre(cachee, { y: 2000, max: 20000, gabarit: GABARIT, hauteur: 56 });
  egale(56, r.decalage, "elle reste cachée sans dépasser");
});

test("sans hauteur connue, rien ne remonte", () => {
  for (const hauteur of [0, undefined, NaN, -5, "abc"]) {
    const r = B.positionBarre(null, { y: 500, max: 20000, gabarit: GABARIT, hauteur });
    const s = B.positionBarre(r.etat, { y: 700, max: 20000, gabarit: GABARIT, hauteur });
    egale(0, s.decalage, "hauteur " + String(hauteur));
  }
});

groupe("Bibliotheque.positionSaisie() — taper une recherche cache la barre");

test("tout en haut : la barre toute remontée, et la page défile de sa hauteur", () => {
  egale({ decalage: HAUTEUR, cible: HAUTEUR }, B.positionSaisie(0, HAUTEUR), "y = 0");
});

test("un peu défilé : on complète jusqu à la hauteur de la barre", () => {
  egale({ decalage: HAUTEUR, cible: HAUTEUR }, B.positionSaisie(40, HAUTEUR), "y = 40 : la place de la barre n est pas encore sortie");
});

test("déjà plus loin que sa hauteur : on ne redescend pas", () => {
  egale({ decalage: HAUTEUR, cible: 900 }, B.positionSaisie(900, HAUTEUR), "la page reste où elle est");
  egale({ decalage: HAUTEUR, cible: HAUTEUR }, B.positionSaisie(HAUTEUR, HAUTEUR), "pile à sa hauteur");
});

test("une valeur illisible ne donne jamais NaN", () => {
  egale({ decalage: 0, cible: 0 }, B.positionSaisie(undefined, undefined), "rien de connu");
  egale({ decalage: 0, cible: 50 }, B.positionSaisie(50, "abc"), "hauteur illisible : rien à cacher");
  egale({ decalage: HAUTEUR, cible: HAUTEUR }, B.positionSaisie("x", HAUTEUR), "position illisible : 0");
});

test("la barre cachée par la saisie reste cachée au défilement suivant, puis revient en remontant", () => {
  // Le même enchaînement que js/app.js : positionSaisie, puis des relevés.
  const s = B.positionSaisie(0, HAUTEUR);
  let r = B.positionBarre({ y: s.cible, decalage: s.decalage, gabarit: GABARIT }, { y: s.cible, max: 20000, gabarit: GABARIT, hauteur: HAUTEUR });
  egale(HAUTEUR, r.decalage, "cachée une fois la page défilée");
  r = B.positionBarre(r.etat, { y: s.cible + 80, max: 20000, gabarit: GABARIT, hauteur: HAUTEUR });
  egale(HAUTEUR, r.decalage, "en descendant : toujours cachée");
  r = B.positionBarre(r.etat, { y: s.cible + 80 - 30, max: 20000, gabarit: GABARIT, hauteur: HAUTEUR });
  egale(HAUTEUR - 30, r.decalage, "en remontant : elle revient au fil du doigt");
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

groupe("Bibliotheque.triValide() — un tri connu, ou le défaut");

test("les six tris, et les trois critères des boutons de index.php (dans l ordre : Plus récent, Tome, Tomes restants)", () => {
  egale(["recentes", "recentes-asc", "tome-desc", "tome-asc", "restants-asc", "restants-desc"], B.TRIS, "les tris mémorisés");
  egale(["recentes", "tome", "restants"], B.CRITERES, "les critères (un test PHP les compare aux boutons de index.php)");
  for (const t of B.TRIS) egale(t, B.triValide(t), t + " reste tel quel");
});

test("une mémoire illisible ou étrangère vaut « recentes », l ordre du serveur", () => {
  for (const v of [undefined, null, "", "Restants-asc", "titre", 3, {}, ["recentes"]]) {
    egale("recentes", B.triValide(v), "valeur " + JSON.stringify(v));
  }
});

groupe("Bibliotheque.critereTri(), sensTri(), triInverse(), triApres() — les boutons et leur flèche");

test("le critère d un tri, et « recentes » pour tout ce qui est inconnu", () => {
  egale("recentes", B.critereTri("recentes"), "recentes");
  egale("recentes", B.critereTri("recentes-asc"), "recentes-asc : le même bouton « Plus récent »");
  egale("tome", B.critereTri("tome-desc"), "tome-desc");
  egale("tome", B.critereTri("tome-asc"), "tome-asc");
  egale("restants", B.critereTri("restants-asc"), "restants-asc");
  egale("restants", B.critereTri("restants-desc"), "restants-desc");
  egale("recentes", B.critereTri("n importe quoi"), "un tri inconnu");
  egale("recentes", B.critereTri(undefined), "rien");
  for (const t of B.TRIS) vrai(B.CRITERES.includes(B.critereTri(t)), t + " a un bouton");
});

test("le sens : ↑ « asc » du plus petit au plus grand, ↓ « desc » du plus grand au plus petit", () => {
  egale("desc", B.sensTri("recentes"), "les plus récentes d abord : ↓");
  egale("asc", B.sensTri("recentes-asc"), "les plus anciennes d abord : ↑");
  egale("desc", B.sensTri("tome-desc"), "tome le plus haut d abord : ↓");
  egale("asc", B.sensTri("tome-asc"), "tome le plus bas d abord : ↑");
  egale("asc", B.sensTri("restants-asc"), "le moins de tomes restants d abord : ↑");
  egale("desc", B.sensTri("restants-desc"), "le plus de tomes restants d abord : ↓");
  egale("desc", B.sensTri("inconnu"), "un tri inconnu vaut le défaut, donc ↓");
});

test("Inverser : le même critère dans l autre sens, deux fois = le tri d origine", () => {
  egale("recentes-asc", B.triInverse("recentes"), "recentes → recentes-asc");
  egale("recentes", B.triInverse("recentes-asc"), "et retour");
  egale("restants-desc", B.triInverse("restants-asc"), "restants asc → desc");
  egale("restants-asc", B.triInverse("restants-desc"), "restants desc → asc");
  egale("tome-asc", B.triInverse("tome-desc"), "tome desc → asc");
  egale("tome-desc", B.triInverse("tome-asc"), "tome asc → desc");
  for (const t of B.TRIS) egale(t, B.triInverse(B.triInverse(t)), t + " : aller-retour");
  for (const t of B.TRIS) egale(B.critereTri(t), B.critereTri(B.triInverse(t)), t + " : le critère ne change pas");
  for (const t of B.TRIS) differe(B.sensTri(t), B.sensTri(B.triInverse(t)), t + " : le sens change");
  egale("recentes-asc", B.triInverse("n importe quoi"), "un tri inconnu vaut le défaut : on l inverse");
});

test("choisir un autre bouton démarre dans le sens le plus parlant", () => {
  egale("tome-desc", B.triApres("recentes", "tome"), "Tome : le plus haut d abord");
  egale("restants-asc", B.triApres("recentes", "restants"), "Tomes restants : le moins d abord (les séries presque finies)");
  egale("restants-asc", B.triApres("tome-asc", "restants"), "depuis un autre critère, quel que soit son sens");
  egale("tome-desc", B.triApres("restants-desc", "tome"), "idem");
  egale("recentes", B.triApres("restants-desc", "recentes"), "Plus récent revient à l ordre du serveur, ↓");
  egale("recentes", B.triApres("tome-asc", "recentes"), "idem");
});

test("recliquer le bouton DÉJÀ allumé inverse le sens (plus de bouton Inverser : la flèche suffit)", () => {
  for (const t of B.TRIS) egale(B.triInverse(t), B.triApres(t, B.critereTri(t)), t + " : le sens s inverse");
  egale("restants-desc", B.triApres("restants-asc", "restants"), "Tomes restants ↑ → ↓");
  egale("recentes-asc", B.triApres("recentes", "recentes"), "Plus récent ↓ → ↑ (« Plus ancien »)");
  egale("recentes", B.triApres(B.triApres("recentes", "recentes"), "recentes"), "et un clic de plus revient");
});

test("un critère inconnu ne change rien", () => {
  for (const t of B.TRIS) egale(t, B.triApres(t, "titre"), t);
  egale("restants-asc", B.triApres("restants-asc", undefined), "sans critère");
  egale("recentes", B.triApres("mémoire illisible", "titre"), "et une mémoire illisible retombe sur le défaut");
});

groupe("Bibliotheque.etatBoutonTri() — le nom, la flèche et l infobulle de chaque bouton");

test("au départ : « Plus récent ↓ » allumé, les deux autres sans flèche", () => {
  const r = B.etatBoutonTri("recentes", "recentes");
  vrai(r.actif, "allumé");
  egale("Plus récent", r.libelle, "son nom");
  egale("↓", r.fleche, "les plus récentes d abord : ↓");
  for (const c of ["tome", "restants"]) {
    const e = B.etatBoutonTri(c, "recentes");
    faux(e.actif, c + " éteint");
    egale("", e.fleche, c + " : aucune flèche sur un bouton éteint");
  }
  egale("Tome", B.etatBoutonTri("tome", "recentes").libelle, "Tome");
  egale("Tomes restants", B.etatBoutonTri("restants", "recentes").libelle, "Tomes restants");
});

test("la flèche suit le sens du bouton allumé, et seulement le sien", () => {
  egale("↑", B.etatBoutonTri("restants", "restants-asc").fleche, "Tomes restants, le moins d abord : ↑");
  egale("↓", B.etatBoutonTri("restants", "restants-desc").fleche, "le plus d abord : ↓");
  egale("↓", B.etatBoutonTri("tome", "tome-desc").fleche, "Tome, le plus haut d abord : ↓");
  egale("↑", B.etatBoutonTri("tome", "tome-asc").fleche, "le plus bas d abord : ↑");
  egale("", B.etatBoutonTri("recentes", "restants-asc").fleche, "les autres boutons n en ont pas");
  egale("", B.etatBoutonTri("tome", "restants-asc").fleche, "idem");
});

test("« Plus récent » devient « Plus ancien » quand on l inverse, et seulement lui", () => {
  const r = B.etatBoutonTri("recentes", "recentes-asc");
  vrai(r.actif, "toujours allumé");
  egale("Plus ancien", r.libelle, "le nom suit le sens");
  egale("↑", r.fleche, "les plus anciennes d abord : ↑");
  egale("Plus récent", B.etatBoutonTri("recentes", "tome-desc").libelle, "éteint : son nom habituel");
  egale("Plus récent", B.etatBoutonTri("recentes", "recentes").libelle, "allumé dans le sens habituel");
  egale("Tome", B.etatBoutonTri("tome", "tome-asc").libelle, "Tome garde son nom, seule la flèche change");
  egale("Tomes restants", B.etatBoutonTri("restants", "restants-desc").libelle, "idem");
});

test("l infobulle dit l ordre en clair : un bouton allumé dit son ordre et qu un clic l inverse", () => {
  const a = B.etatBoutonTri("restants", "restants-asc").aide;
  contient("le moins de tomes restants d'abord", a, "son ordre");
  contient("Cliquer pour inverser", a, "et ce que fait un clic");
  contient("le plus de tomes restants d'abord", B.etatBoutonTri("restants", "restants-desc").aide, "l autre sens");
  contient("le tome lu le plus haut d'abord", B.etatBoutonTri("tome", "tome-desc").aide, "tome ↓");
  contient("le tome lu le plus bas d'abord", B.etatBoutonTri("tome", "tome-asc").aide, "tome ↑");
  contient("les plus anciennes d'abord", B.etatBoutonTri("recentes", "recentes-asc").aide, "plus ancien");
});

test("un bouton éteint dit l ordre qu un clic donnerait, sans parler d inverser", () => {
  const e = B.etatBoutonTri("restants", "recentes").aide;
  contient("le moins de tomes restants d'abord", e, "il démarre par le moins de tomes");
  faux(e.includes("inverser"), "rien à inverser : il n est pas allumé");
  contient("le tome lu le plus haut d'abord", B.etatBoutonTri("tome", "recentes").aide, "Tome démarre par le plus haut");
  contient("les plus récentes d'abord", B.etatBoutonTri("recentes", "tome-asc").aide, "Plus récent");
});

test("chaque bouton nomme la chose dans son infobulle (lue aussi par les lecteurs d écran)", () => {
  for (const c of B.CRITERES) {
    for (const t of B.TRIS) {
      const e = B.etatBoutonTri(c, t);
      vrai(e.aide.startsWith(e.libelle + " : "), c + " / " + t + " : l aide commence par le nom du bouton");
      vrai(e.aide.endsWith("."), c + " / " + t + " : une phrase qui finit");
    }
  }
});

test("un critère inconnu n a rien à montrer", () => {
  egale({ actif: false, libelle: "", fleche: "", aide: "" }, B.etatBoutonTri("titre", "tome-asc"), "inconnu");
  egale({ actif: false, libelle: "", fleche: "", aide: "" }, B.etatBoutonTri(undefined, "tome-asc"), "rien");
});

test("un tri inconnu se montre comme le défaut", () => {
  egale(B.etatBoutonTri("recentes", "recentes"), B.etatBoutonTri("recentes", "n importe quoi"), "inconnu");
  egale(B.etatBoutonTri("tome", "recentes"), B.etatBoutonTri("tome", undefined), "rien");
});
groupe("Bibliotheque.comparerTri() — trier par tomes restants ou par tome lu");

const serie = (tome, restants) => ({ tome, restants });

/** Le tri d une liste de séries, ex aequo départagés par leur rang d origine (ce que fait appliquerVue). */
const trier = (tri, series) => series
  .map((s, rang) => [s, rang])
  .sort((a, b) => B.comparerTri(tri, a[0], b[0]) || a[1] - b[1])
  .map(([s]) => s.nom);

const SERIES = [
  { nom: "A", tome: 10, restants: 5, ordre: 0 },
  { nom: "B", tome: 3, restants: 0, ordre: 1 },
  { nom: "C", tome: 25, restants: null, ordre: 2 },
  { nom: "D", tome: 7, restants: 12, ordre: 3 },
  { nom: "E", tome: 3, restants: 5, ordre: 4 },
  { nom: "F", tome: 40, restants: null, ordre: 5 },
];

test("par défaut : aucune préférence, l ordre du serveur décide", () => {
  egale(0, B.comparerTri("recentes", serie(1, 9), serie(50, 0)), "recentes");
  egale(0, B.comparerTri("inconnu", serie(1, 9), serie(50, 0)), "un tri inconnu vaut le défaut");
  egale(["A", "B", "C", "D", "E", "F"], trier("recentes", SERIES), "la liste ne bouge pas");
});

test("les plus anciennes d abord : l ordre du serveur à l envers, y compris pour des rangs négatifs (séries créées depuis le chargement)", () => {
  egale(["F", "E", "D", "C", "B", "A"], trier("recentes-asc", SERIES), "du dernier rang au premier");
  vrai(B.comparerTri("recentes-asc", { ordre: 0 }, { ordre: 3 }) > 0, "la plus récente (rang 0) passe après");
  vrai(B.comparerTri("recentes-asc", { ordre: -2 }, { ordre: 0 }) > 0, "une série créée à l instant (rang négatif) est la plus récente : en dernier");
  egale(0, B.comparerTri("recentes-asc", { ordre: 4 }, { ordre: 4 }), "même rang : égalité");
  egale(0, B.comparerTri("recentes-asc", {}, {}), "des rangs absents ne donnent jamais NaN");
});

test("tomes restants, le moins d abord : à jour (0) en tête, puis 5, 5, 12 ; l inconnu à la fin", () => {
  egale(["B", "A", "E", "D", "C", "F"], trier("restants-asc", SERIES), "A et E (5 chacun) restent dans leur ordre d origine");
});

test("tomes restants, le plus d abord : 12, 5, 5, 0 ; l inconnu AUSSI à la fin", () => {
  egale(["D", "A", "E", "B", "C", "F"], trier("restants-desc", SERIES), "l inconnu ne remonte pas en tête dans ce sens");
});

test("une série dont on ne sait pas les tomes restants passe toujours après, dans les deux sens", () => {
  for (const tri of ["restants-asc", "restants-desc"]) {
    vrai(B.comparerTri(tri, serie(1, null), serie(1, 0)) > 0, tri + " : inconnu après 0 (0 est une vraie réponse, pas un inconnu)");
    vrai(B.comparerTri(tri, serie(1, 0), serie(1, null)) < 0, tri + " : et dans l autre sens");
    egale(0, B.comparerTri(tri, serie(1, null), serie(9, null)), tri + " : deux inconnus sont à égalité");
  }
});

test("restants vide, non numérique ou absent : inconnu, jamais 0", () => {
  for (const v of [null, undefined, "", "abc", NaN]) {
    vrai(B.comparerTri("restants-asc", serie(1, v), serie(1, 0)) > 0, "valeur " + String(v) + " : après une série à jour");
  }
  egale(0, B.comparerTri("restants-asc", serie(1, "4"), serie(1, 4)), "un nombre lu d un attribut (texte) vaut le même nombre");
});

test("tome lu, le plus haut d abord / le plus bas d abord ; les ex aequo gardent leur ordre d origine", () => {
  egale(["F", "C", "A", "D", "B", "E"], trier("tome-desc", SERIES), "40, 25, 10, 7, puis 3 et 3 (B avant E)");
  egale(["B", "E", "D", "A", "C", "F"], trier("tome-asc", SERIES), "3 et 3 (B avant E), 7, 10, 25, 40");
});

test("le tome lu ne dépend pas des tomes restants : même les séries pas liées se trient", () => {
  vrai(B.comparerTri("tome-desc", serie(30, null), serie(2, 1)) < 0, "30 avant 2, quoi qu on sache de la fin");
  vrai(B.comparerTri("tome-asc", serie(30, null), serie(2, 1)) > 0, "et l inverse");
  egale(0, B.comparerTri("tome-desc", serie(4, null), serie(4, 9)), "à égalité de tome : 0");
});

test("un tome absent ou absurde vaut 0, jamais NaN", () => {
  for (const t of ["tome-asc", "tome-desc"]) {
    const r = B.comparerTri(t, serie(undefined, null), serie("x", null));
    vrai(Number.isFinite(r) && r === 0, t + " : deux tomes illisibles sont à égalité (" + r + ")");
  }
});

test("antisymétrique : a avant b si et seulement si b après a", () => {
  for (const tri of B.TRIS) {
    for (const a of SERIES) {
      for (const b of SERIES) {
        egale(Math.sign(B.comparerTri(tri, a, b)), -Math.sign(B.comparerTri(tri, b, a)), tri + " : " + a.nom + "/" + b.nom);
      }
    }
  }
});

groupe("Bibliotheque.comparerVue() — pertinence, puis tri, puis rang d origine");

const vue = (pertinence, tome, restants, ordre) => ({ pertinence, tome, restants, ordre });

/** Le rangement d une liste (ce que fait appliquerVue quand la vue change). */
const ranger = (tri, series) => series.slice().sort((a, b) => B.comparerVue(tri, a, b)).map((s) => s.nom);

test("la pertinence passe avant le tri", () => {
  vrai(B.comparerVue("tome-desc", vue(0, 1, null, 5), vue(1, 99, null, 1)) < 0, "titre exact avant titre approchant, même au plus petit tome");
  vrai(B.comparerVue("restants-asc", vue(2, 1, 0, 0), vue(1, 1, 9, 9)) > 0, "et dans l autre sens");
});

test("à pertinence égale, le tri choisi décide", () => {
  vrai(B.comparerVue("tome-desc", vue(0, 30, null, 9), vue(0, 2, null, 0)) < 0, "30 avant 2 sous « Tome ↓ »");
  vrai(B.comparerVue("tome-asc", vue(0, 30, null, 0), vue(0, 2, null, 9)) > 0, "et l inverse sous « Tome ↑ »");
});

test("à égalité de tout, le rang d origine départage : le résultat ne change jamais d un affichage à l autre", () => {
  vrai(B.comparerVue("tome-desc", vue(0, 4, null, 2), vue(0, 4, null, 5)) < 0, "le rang 2 avant le rang 5");
  egale(0, B.comparerVue("tome-desc", vue(0, 4, null, 2), vue(0, 4, null, 2)), "même rang : égalité");
});

test("« Plus récent » : le rang d origine seul, et une série modifiée (rang négatif) passe devant", () => {
  const liste = [
    { nom: "A", tome: 1, restants: null, ordre: 0, pertinence: 0 },
    { nom: "B", tome: 2, restants: null, ordre: 1, pertinence: 0 },
    { nom: "C", tome: 3, restants: null, ordre: -1, pertinence: 0 }, // modifiée depuis le chargement
  ];
  egale(["C", "A", "B"], ranger("recentes", liste), "la modifiée en tête, comme le serveur au rechargement");
  egale(["B", "A", "C"], ranger("recentes-asc", liste), "« Plus ancien » : l inverse");
});

test("sous un autre tri, une série modifiée prend la place que son nouveau tome lui donne, au prochain rangement", () => {
  const liste = [
    { nom: "A", tome: 10, restants: 5, ordre: 0, pertinence: 0 },
    { nom: "B", tome: 3, restants: 0, ordre: 1, pertinence: 0 },
    { nom: "C", tome: 25, restants: null, ordre: 2, pertinence: 0 },
  ];
  egale(["C", "A", "B"], ranger("tome-desc", liste), "avant");
  liste[1] = { nom: "B", tome: 40, restants: 0, ordre: -1, pertinence: 0 }; // → jusqu au tome 40
  egale(["B", "C", "A"], ranger("tome-desc", liste), "après : B est première, d après son nouveau tome");
});

test("les séries aux tomes restants inconnus restent à la fin, dans les deux sens, recherche comprise", () => {
  const liste = [
    { nom: "A", tome: 1, restants: null, ordre: 0, pertinence: 0 },
    { nom: "B", tome: 1, restants: 3, ordre: 1, pertinence: 0 },
    { nom: "C", tome: 1, restants: 0, ordre: 2, pertinence: 0 },
  ];
  egale(["C", "B", "A"], ranger("restants-asc", liste), "↑");
  egale(["B", "C", "A"], ranger("restants-desc", liste), "↓");
});

groupe("Bibliotheque.empreinteVue() — ce qui fait « une autre vue »");

const E = (statuts = [], images = [], favoris = false, q = "", tri = "recentes") => B.empreinteVue(statuts, images, favoris, q, tri);

test("deux vues égales ont la même empreinte, quel que soit l ordre où les filtres ont été posés", () => {
  egale(E(["cours", "termine"]), E(["termine", "cours"]), "statuts");
  egale(E([], ["mangadex", "lien"]), E([], ["lien", "mangadex"]), "images");
  egale(E(new Set(["a", "b"])), E(new Set(["b", "a"])), "des Set, comme dans app.js");
  egale(E(), E(), "la vue de départ");
});

test("chaque élément de la vue change l empreinte : un filtre, les favoris, la recherche, le tri", () => {
  const base = E();
  vrai(base !== E(["cours"]), "un statut");
  vrai(base !== E([], ["lien"]), "une image");
  vrai(base !== E([], [], true), "les favoris");
  vrai(base !== E([], [], false, "ber"), "la recherche");
  for (const t of B.TRIS.filter((x) => x !== "recentes")) vrai(base !== E([], [], false, "", t), "le tri " + t);
});

test("les favoris sont un oui ou un non, pas la valeur qu on a posée", () => {
  egale(E([], [], true), E([], [], 1), "true et 1");
  egale(E([], [], false), E([], [], 0), "false et 0");
});

test("l empreinte ne mélange pas les éléments : un statut n est pas une image", () => {
  vrai(E(["x"], []) !== E([], ["x"]), "même mot, autre filtre");
  vrai(E([], [], false, "a b") !== E([], [], false, "ab"), "la recherche garde ses espaces");
});
groupe("Bibliotheque.statistiques() — le total de la bibliothèque");

test("le nombre de séries et la somme des tomes lus", () => {
  const s = B.statistiques([5, 3, 0]);
  egale(3, s.series, "trois séries");
  egale(8, s.tomes, "5 + 3 + 0");
  egale("3 séries · 8 tomes lus", s.texte, "le texte, au pluriel");
});

test("0 et 1 sont au singulier, en français — les mêmes cas que statistiques_series() côté PHP", () => {
  egale("0 série · 0 tome lu", B.statistiques([]).texte, "bibliothèque vide");
  egale("1 série · 1 tome lu", B.statistiques([1]).texte, "une série, un tome");
  egale("1 série · 0 tome lu", B.statistiques([0]).texte, "une série pas commencée");
  egale("2 séries · 1 tome lu", B.statistiques([1, 0]).texte, "deux séries, un tome");
  egale("1 série · 12 tomes lus", B.statistiques([12]).texte, "une série, douze tomes");
});

test("un tome négatif, absent ou illisible ne retire rien et ne donne jamais NaN", () => {
  const s = B.statistiques([5, -9, undefined, "abc", null, NaN]);
  egale(5, s.tomes, "seul ce qui est lu compte");
  egale(6, s.series, "mais chaque série compte");
  egale(12, B.statistiques(["12"]).tomes, "un nombre lu d un attribut (texte)");
  egale(2, B.statistiques([1.9, 1.2]).tomes, "un tome est un entier : on tronque (1 + 1)");
});

test("autre chose qu une liste : rien", () => {
  for (const v of [undefined, null, "abc", 5, {}]) {
    egale("0 série · 0 tome lu", B.statistiques(v).texte, JSON.stringify(v));
  }
});