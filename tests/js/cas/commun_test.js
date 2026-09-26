/* =====================================================================
   js/commun.js — les erreurs rattachées à leur champ, l'adresse d'image
   en https, la durée des notifications.

   Les erreurs passaient par des notifications de 2,6 s, loin du champ
   concerné. Elles s'affichent maintenant SOUS le champ, qui les désigne
   (aria-describedby) et se déclare invalide (aria-invalid) — même
   convention d'identifiant (« <id>-erreur ») que champ_erreur() en PHP.
   ===================================================================== */

groupe("Lib.urlImageAcceptee() — https uniquement");

test("une adresse https est acceptée", () => {
  vrai(Lib.urlImageAcceptee("https://exemple.test/couverture.png"), "https");
  vrai(Lib.urlImageAcceptee("HTTPS://EXEMPLE.TEST/A.PNG"), "en majuscules");
});

test("une adresse en http:// est refusée", () => {
  /* La Content-Security-Policy n'accepte que https pour les images : en
     http, l'aperçu était cassé, puis la série s'enregistrait sans elle. */
  faux(Lib.urlImageAcceptee("http://exemple.test/couverture.png"), "http");
});

test("une adresse sans nom de domaine est refusée", () => {
  faux(Lib.urlImageAcceptee("https://"), "https:// seul");
  faux(Lib.urlImageAcceptee("https:///chemin.png"), "domaine vide");
});

test("les schémas dangereux sont refusés", () => {
  faux(Lib.urlImageAcceptee("javascript:alert(1)"), "javascript:");
  faux(Lib.urlImageAcceptee("data:image/png;base64,AAAA"), "data:");
});

test("l adresse se vérifie telle qu on la donne : à l appelant d ôter les espaces", () => {
  faux(Lib.urlImageAcceptee(" https://exemple.test/a.png"), "un espace devant");
});

groupe("Lib.dureeToast() — le temps de lire");

test("quatre secondes au moins", () => {
  egale(4000, Lib.dureeToast("OK ✅"), "un message court");
  egale(4000, Lib.dureeToast(""), "un message vide");
});

test("60 ms par caractère au-delà", () => {
  // La durée était fixe (2,6 s) : un message de deux lignes disparaissait avant d'être lu.
  egale(6000, Lib.dureeToast("x".repeat(100)), "100 caractères");
  egale(12000, Lib.dureeToast("x".repeat(200)), "200 caractères");
});

groupe("Lib.erreurChamp() — le message sous son champ");

test("le message est posé sous le champ, avec l id que le champ désigne", () => {
  terrain('<input id="a-email">');
  const champ = document.getElementById("a-email");
  Lib.erreurChamp(champ, "Adresse invalide.");
  const p = champ.nextElementSibling;
  egale("a-email-erreur", p && p.id, "juste après le champ, id <champ>-erreur");
  egale("Adresse invalide.", p.textContent, "le texte");
  vrai(p.classList.contains("erreur-champ"), "la classe de style");
  egale("true", champ.getAttribute("aria-invalid"), "le champ se déclare invalide");
  egale("a-email-erreur", champ.getAttribute("aria-describedby"), "et désigne son message");
});

test("le message passe avant l aide, qui reste désignée", () => {
  terrain('<input id="a-mdp" aria-describedby="a-mdp-aide"><p id="a-mdp-aide">8 caractères.</p>');
  const champ = document.getElementById("a-mdp");
  Lib.erreurChamp(champ, "Trop court.");
  egale("a-mdp-erreur a-mdp-aide", champ.getAttribute("aria-describedby"), "l erreur est lue en premier");
});

test("un second message remplace le premier, sans doublon", () => {
  terrain('<input id="a-titre">');
  const champ = document.getElementById("a-titre");
  Lib.erreurChamp(champ, "Premier.");
  Lib.erreurChamp(champ, "Second.");
  egale(1, document.querySelectorAll("#a-titre-erreur").length, "un seul message");
  egale("Second.", document.getElementById("a-titre-erreur").textContent, "le dernier");
  egale("a-titre-erreur", champ.getAttribute("aria-describedby"), "désigné une seule fois");
});

test("le message posé par PHP est repris, pas doublé", () => {
  // Même convention d'identifiant que champ_erreur() : le script retrouve le message du serveur.
  terrain('<input id="a-username" aria-invalid="true" aria-describedby="a-username-erreur">'
    + '<p class="erreur-champ" id="a-username-erreur">Identifiant déjà pris.</p>');
  Lib.erreurChamp(document.getElementById("a-username"), "Trop court.");
  egale(1, document.querySelectorAll("#a-username-erreur").length, "un seul message");
  egale("Trop court.", document.getElementById("a-username-erreur").textContent, "mis à jour");
});

test("un mot de passe : le message va après le bouton œil, pas entre les deux", () => {
  terrain('<div class="password-wrap"><input id="a-nouveau" type="password">'
    + '<button type="button" class="toggle-password">👁️</button></div>');
  Lib.erreurChamp(document.getElementById("a-nouveau"), "Trop court.");
  egale("a-nouveau-erreur", document.querySelector(".password-wrap").nextElementSibling.id, "après l enveloppe");
});

test("le message est du texte, jamais du HTML", () => {
  terrain('<input id="a-x">');
  Lib.erreurChamp(document.getElementById("a-x"), '<img src=x onerror="window.pirate=1">');
  estNul(document.querySelector("#a-x-erreur img"), "aucune balise créée");
  contient("<img", document.getElementById("a-x-erreur").textContent, "le texte s affiche tel quel");
});

test("sans champ, rien ne se passe", () => {
  Lib.erreurChamp(null, "x");
  vrai(true, "aucune exception");
});

groupe("Lib.effacerErreur() / effacerErreurs()");

test("effacer retire le message et rend le champ valide", () => {
  terrain('<input id="a-email">');
  const champ = document.getElementById("a-email");
  Lib.erreurChamp(champ, "Adresse invalide.");
  Lib.effacerErreur(champ);
  estNul(document.getElementById("a-email-erreur"), "le message a disparu");
  estNul(champ.getAttribute("aria-invalid"), "aria-invalid retiré");
  estNul(champ.getAttribute("aria-describedby"), "plus rien à désigner");
});

test("effacer garde l aide", () => {
  terrain('<input id="a-mdp" aria-describedby="a-mdp-aide"><p id="a-mdp-aide">Aide.</p>');
  const champ = document.getElementById("a-mdp");
  Lib.erreurChamp(champ, "Trop court.");
  Lib.effacerErreur(champ);
  egale("a-mdp-aide", champ.getAttribute("aria-describedby"), "l aide reste désignée");
});

test("effacer un message posé par PHP", () => {
  terrain('<input id="a-u" aria-invalid="true" aria-describedby="a-u-erreur"><p id="a-u-erreur">Pris.</p>');
  Lib.effacerErreur(document.getElementById("a-u"));
  estNul(document.getElementById("a-u-erreur"), "le message du serveur disparaît aussi");
});

test("effacerErreurs() nettoie tout un formulaire", () => {
  const t = terrain('<form><input id="a-1"><input id="a-2"><input id="a-3"></form>');
  Lib.erreurChamp(document.getElementById("a-1"), "Un.");
  Lib.erreurChamp(document.getElementById("a-3"), "Trois.");
  Lib.effacerErreurs(t.querySelector("form"));
  egale(0, t.querySelectorAll(".erreur-champ, [aria-invalid]").length, "plus aucune erreur");
});

test("un champ sans id ne fait pas planter", () => {
  Lib.effacerErreur(terrain("<input>").querySelector("input"));
  Lib.effacerErreur(null);
  vrai(true, "aucune exception");
});

test("corriger le champ efface son erreur", () => {
  /* L'erreur ne doit pas survivre à ce qui l'a causée : commun.js écoute
     « input » sur tout le document, en phase de capture. */
  terrain('<input id="a-titre">');
  const champ = document.getElementById("a-titre");
  Lib.erreurChamp(champ, "Le titre est obligatoire.");
  champ.value = "One Piece";
  champ.dispatchEvent(new Event("input", { bubbles: true }));
  estNul(document.getElementById("a-titre-erreur"), "le message disparaît à la frappe");
  estNul(champ.getAttribute("aria-invalid"), "le champ redevient valide");
});

groupe("Lib.erreursSurChamps() — les erreurs d une réponse de l API");

test("un message par champ, et le premier champ est rendu pour le focus", () => {
  terrain('<input id="a-nouveau"><input id="a-confirm">');
  const err = Object.assign(new Error("…"), {
    erreurs: { nouveau: ["Trop court.", "Il manque un chiffre."], confirmation: "Ils diffèrent." },
  });
  const premier = Lib.erreursSurChamps(err, {
    nouveau: document.getElementById("a-nouveau"),
    confirmation: document.getElementById("a-confirm"),
  });
  egale("a-nouveau", premier && premier.id, "le premier champ fautif");
  egale("Trop court. Il manque un chiffre.", document.getElementById("a-nouveau-erreur").textContent,
    "plusieurs messages d un champ, à la suite");
  egale("Ils diffèrent.", document.getElementById("a-confirm-erreur").textContent, "le second champ");
});

test("un seul champ nommé : le message de l erreur va dessous", () => {
  terrain('<input id="f-title">');
  const err = Object.assign(new Error("Le titre est obligatoire."), { champ: "titre" });
  const champ = Lib.erreursSurChamps(err, { titre: document.getElementById("f-title") });
  egale("f-title", champ && champ.id, "le champ désigné");
  egale("Le titre est obligatoire.", document.getElementById("f-title-erreur").textContent, "son message");
});

test("un champ inconnu de la page est ignoré", () => {
  terrain('<input id="a-x">');
  const err = Object.assign(new Error("x"), { erreurs: { inconnu: "Message." } });
  estNul(Lib.erreursSurChamps(err, { x: document.getElementById("a-x") }), "aucun champ marqué");
  estNul(document.getElementById("a-x-erreur"), "aucun message posé");
});

test("une erreur sans champ ne marque rien", () => {
  // Réseau coupé, session expirée… : c'est à l'appelant de l'afficher ailleurs.
  estNul(Lib.erreursSurChamps(new Error("Réseau indisponible."), {}), "rien à marquer");
});
