/* =====================================================================
   js/mdp.js — les règles du mot de passe, dites pendant la saisie.

   Miroir de valider_mot_de_passe() (PHP) : les messages attendus ici
   sont les mêmes que dans tests/cas/fonctions_mot_de_passe_test.php.
   ===================================================================== */

const R = window.ReglesMdp;

groupe("ReglesMdp.manques() — seules les règles non respectées");

test("vide : seulement « obligatoire »", () => {
  egale(JSON.stringify(["Ce champ est obligatoire."]), JSON.stringify(R.manques("")), "un seul message");
});

test("il ne manque que la majuscule : un seul message", () => {
  egale(JSON.stringify(["Le mot de passe doit contenir au moins une majuscule."]),
    JSON.stringify(R.manques("abcdefgh1!")), "le même texte que le serveur");
  egale(0, R.manques("Abcdefgh1!").length, "tout y est : rien à dire");
});

test("chaque règle, une par une", () => {
  const un = (mdp, bout, desc) => contient(bout, R.manques(mdp).join(" | "), desc);
  un("Ab1!", "au moins 8 caractères", "trop court");
  un("Ab1!    ", "au moins 8 caractères", "les espaces ne comptent pas dans la longueur");
  un(" Abcdef1!", "commencer ni se terminer par un espace", "espace au début");
  un("ABCDEFG1!", "une minuscule", "sans minuscule");
  un("Abcdefgh!", "un chiffre", "sans chiffre");
  un("Abcdefgh1", "caractère spécial", "sans caractère spécial");
  un("Abcdefgh1 x", "caractère spécial", "un espace insécable ne compte pas comme spécial");
});

test("les bornes viennent du .env", () => {
  contient("au moins 12 caractères", R.manques("Abcdefgh1!", { min: 12 }).join(" "), "minimum à 12");
  contient("trop long (9", R.manques("Abcdefgh1!", { max: 9 }).join(" "), "maximum à 9");
});

test("l identifiant ne doit pas s y trouver, casse ignorée", () => {
  contient("votre identifiant", R.manques("Lecteur92!", { identifiant: "lecteur" }).join(" "), "refusé");
  egale(0, R.manques("Abcdefgh1!", { identifiant: "ab" }).length, "moins de 3 lettres : ignoré");
});

test("Unicode : É est une majuscule, un emoji compte pour un", () => {
  egale(0, R.manques("Élégant1!").length, "É majuscule, é minuscule");
  contient("au moins 8 caractères", R.manques("Aa1!😀😀").join(" "), "6 caractères, pas 8 unités");
});

groupe("ReglesMdp.brancher() — quand les dire");

function champMdp() {
  terrain('<div class="password-wrap"><input id="essai-mdp" type="password" data-regles-mdp data-mdp-min="8" data-mdp-max="200"></div>');
  const champ = document.getElementById("essai-mdp");
  R.brancher(champ);
  return champ;
}
const saisir = (champ, v) => { champ.value = v; champ.dispatchEvent(new Event("input", { bubbles: true })); };
const quitter = (champ) => champ.dispatchEvent(new Event("blur"));
const message = () => document.getElementById("essai-mdp-erreur");

test("rien pendant la première saisie", () => {
  const champ = champMdp();
  saisir(champ, "a");
  estNul(message(), "pas de mur de règles à la première lettre");
});

test("en quittant le champ : les règles manquantes, puis elles s effacent une à une", () => {
  const champ = champMdp();
  saisir(champ, "abcdefgh1!");
  quitter(champ);
  contient("majuscule", message().textContent, "la règle manquante est dite");
  egale("true", champ.getAttribute("aria-invalid"), "le champ se déclare invalide");
  saisir(champ, "Abcdefgh1!");
  estNul(message(), "règle respectée : le message disparaît pendant la frappe");
  faux(champ.hasAttribute("aria-invalid"), "et le champ redevient valide");
});

test("quitter un champ vide ne dit rien", () => {
  const champ = champMdp();
  quitter(champ);
  estNul(message(), "« obligatoire » ne se dit qu à l envoi");
});

test("un message posé par le serveur se met à jour pendant la frappe", () => {
  terrain('<div class="password-wrap"><input id="essai-mdp" type="password" data-regles-mdp aria-invalid="true"></div>'
    + '<p class="erreur-champ" id="essai-mdp-erreur">Le mot de passe doit contenir au moins une majuscule.</p>');
  const champ = document.getElementById("essai-mdp");
  R.brancher(champ);
  saisir(champ, "abc");
  contient("au moins 8 caractères", message().textContent, "recalculé dès la première lettre");
});

groupe("ReglesMdp.manques() — les classes exigées sont réglables (MDP_MAJ, MDP_MINUSCULE, MDP_CHIFFRE, MDP_SPE)");

test("par défaut, les quatre classes sont exigées", () => {
  const manques = R.manques("abcdefgh").join(" | ");
  contient("une majuscule", manques, "la majuscule manque");
  contient("un chiffre", manques, "le chiffre manque");
  contient("caractère spécial", manques, "le caractère spécial manque");
  contient("une minuscule", R.manques("ABCDEFG1!").join(" | "), "la minuscule manque");
});

test("une classe désactivée ne se dit plus — et elle seule", () => {
  const sans = (option, extrait) => R.manques("abcdefgh", { [option]: false }).filter((m) => extrait.test(m)).length;
  egale(0, sans("maj", /majuscule/), "majuscule non exigée : rien à dire");
  egale(1, sans("maj", /chiffre/), "mais le chiffre manque toujours");
  egale(0, R.manques("ABCDEFG1!", { minuscule: false }).filter((m) => /minuscule/.test(m)).length, "minuscule non exigée");
  egale(0, R.manques("Abcdefgh!", { chiffre: false }).filter((m) => /chiffre/.test(m)).length, "chiffre non exigé");
  egale(0, R.manques("Abcdefgh1", { spe: false }).filter((m) => /spécial/.test(m)).length, "caractère spécial non exigé");
});

test("tout désactivé : seule la longueur compte", () => {
  const tout = { maj: false, minuscule: false, chiffre: false, spe: false };
  egale(0, R.manques("abcdefgh", tout).length, "huit lettres suffisent");
  contient("au moins 8 caractères", R.manques("abc", tout).join(" "), "mais pas trois");
  egale(0, R.manques("a", { ...tout, min: 1 }).length, "à 1, un seul caractère passe");
});

test("une classe désactivée n'efface pas les autres règles", () => {
  const tout = { maj: false, minuscule: false, chiffre: false, spe: false };
  contient("commencer ni se terminer par un espace", R.manques(" abcdefgh", tout).join(" "), "les espaces de bord restent refusés");
  contient("trop long", R.manques("a".repeat(30), { ...tout, max: 20 }).join(" "), "et le maximum aussi");
  contient("votre identifiant", R.manques("marcmarcmarc", { ...tout, identifiant: "marc" }).join(" "), "et l'identifiant interdit");
});

test("le minimum réglé est cité dans le message : de 1 à 200", () => {
  contient("au moins 12 caractères", R.manques("Abcdef1!", { min: 12 }).join(" "), "12");
  contient("au moins 200 caractères", R.manques("Abcdef1!", { min: 200, max: 201 }).join(" "), "200");
});

groupe("ReglesMdp.exigee() et options() — ce que la page dit du serveur");

test("seul « 0 » désactive une classe : absent, vide ou illisible la laisse exigée", () => {
  faux(R.exigee("0"), "« 0 »");
  faux(R.exigee(" 0 "), "« 0 » avec des espaces");
  for (const v of ["1", "", "non", "false", undefined, null, "00", "2"]) vrai(R.exigee(v), String(v) + " : exigée");
});

test("options() lit les attributs data- du champ", () => {
  terrain('<input id="o1" type="password" data-regles-mdp data-mdp-min="12" data-mdp-max="150"'
    + ' data-mdp-maj="0" data-mdp-minuscule="1" data-mdp-chiffre="0" data-mdp-spe="1">');
  const o = R.options(document.getElementById("o1"));
  egale([12, 150, false, true, false, true], [o.min, o.max, o.maj, o.minuscule, o.chiffre, o.spe], "tout est lu");
});

test("un champ sans les nouveaux attributs garde les quatre règles", () => {
  terrain('<input id="o2" type="password" data-regles-mdp data-mdp-min="8" data-mdp-max="200">');
  const o = R.options(document.getElementById("o2"));
  egale([true, true, true, true], [o.maj, o.minuscule, o.chiffre, o.spe], "une vieille page ne desserre rien");
});

test("le champ branché dit les règles selon ses attributs", () => {
  terrain('<div class="password-wrap"><input id="essai-mdp" type="password" data-regles-mdp data-mdp-min="3" data-mdp-max="200"'
    + ' data-mdp-maj="0" data-mdp-minuscule="0" data-mdp-chiffre="0" data-mdp-spe="0"></div>');
  const champ = document.getElementById("essai-mdp");
  R.brancher(champ);
  champ.value = "abc";
  champ.dispatchEvent(new Event("input", { bubbles: true }));
  champ.dispatchEvent(new Event("blur"));
  egale(null, document.getElementById("essai-mdp-erreur"), "3 lettres, rien d'autre d'exigé : aucun message");
});
