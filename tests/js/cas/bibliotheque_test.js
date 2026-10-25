/* =====================================================================
   js/app.js — la logique pure de la bibliothèque (window.Bibliotheque).

   Ce qui se décide sans toucher à la page : la carte voisine au clavier,
   le suivi du défilement qui cache et ramène les filtres, les textes des
   quotas. Le reste d'app.js branche ces décisions sur la page ; il se
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

groupe("Bibliotheque.positionBarre() — la barre des filtres suit la page");

const GABARIT = 5000;
const HAUTEUR = 100;

/** Enchaîne des positions comme autant de relevés : rend le dernier, et la suite des décalages. */
function derouler(positions, depart = null, gabarit = GABARIT) {
  let etat = depart;
  const decalages = [];
  let r = null;
  for (const y of positions) {
    r = B.positionBarre(etat, { y, max: 20000, gabarit, hauteur: HAUTEUR });
    etat = r.etat;
    decalages.push(r.decalage);
  }
  return { r, decalages };
}

test("tout en haut, la barre est à sa place", () => {
  const { r } = derouler([0]);
  egale(0, r.decalage, "entière");
  faux(r.collee, "pas collée : elle est dans la page");
});

test("page rechargée en cours de liste : rien ne bouge au premier relevé", () => {
  const { r } = derouler([3000]);
  egale(0, r.decalage, "aucun décalage sans mouvement");
  vrai(r.collee, "collée sous la barre du haut");
});

test("en descendant, elle remonte de la distance parcourue", () => {
  const { decalages } = derouler([1000, 1030, 1070]);
  egale([0, 30, 70], decalages, "ni plus ni moins que la page");
});

test("elle ne dépasse jamais sa hauteur : cachée, elle reste cachée", () => {
  const { decalages } = derouler([1000, 1300]);
  egale([0, HAUTEUR], decalages, "plafonnée à la hauteur de la barre");
});

test("en remontant, elle redescend à la même vitesse", () => {
  const { decalages } = derouler([1000, 1300, 1260, 1200]);
  egale([0, HAUTEUR, 60, 0], decalages, "40 px vers le haut : 40 px montrés");
});

test("à moitié montrée, elle le reste là où l on s arrête", () => {
  const { decalages } = derouler([1000, 1040, 1040, 1040]);
  egale([0, 40, 40, 40], decalages, "pas de mouvement, pas de changement");
});

test("jamais plus remontée que la page ne l est", () => {
  const { decalages } = derouler([0, 30]);
  egale([0, 30], decalages, "decalage <= y : pas de trou sous elle");
});

test("un décalage dû à une hauteur qui change n est pas un geste", () => {
  /* Déplier « Image ▾ » agrandit les filtres : le navigateur décale la
     page d'autant pour garder la même chose sous les yeux. Pris pour une
     descente, ce décalage cachait les filtres qu'on venait d'ouvrir. */
  const avant = derouler([1500, 1460]).r.etat;
  const r = B.positionBarre(avant, { y: 1507, max: 20000, gabarit: GABARIT + 47, hauteur: HAUTEUR });
  egale(avant.decalage, r.decalage, "47 px de décalage : la barre ne bouge pas");
  egale(1507, r.etat.y, "le relevé repart de la nouvelle position");
  const suite = B.positionBarre(r.etat, { y: 1519, max: 20000, gabarit: GABARIT + 47, hauteur: HAUTEUR });
  egale(r.decalage + 12, suite.decalage, "une vraie descente ensuite compte normalement");
});

test("le rebond élastique en haut de page ne compte pas", () => {
  // Sur iPhone, la position passe sous zéro puis revient.
  const { decalages, r } = derouler([30, -40, 0]);
  egale(0, decalages[1], "sous zéro : comme tout en haut");
  egale(0, decalages[2], "à zéro : entière");
  faux(r.collee, "à zéro : pas collée");
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
  egale(HAUTEUR, releve(8000), "on descend jusqu en bas : cachée");
  egale(HAUTEUR, releve(8060), "au-delà de la fin : bornée, aucun mouvement");
  egale(HAUTEUR, releve(8000), "retour du rebond : toujours cachée");
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
