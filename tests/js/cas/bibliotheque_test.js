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
