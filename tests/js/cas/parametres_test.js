/* =====================================================================
   js/settings.js — la logique pure de la page Paramètres
   (window.Parametres).
   ===================================================================== */

const P = window.Parametres;

groupe("Parametres.texteSuppressionCompte() — dire ce qui sera perdu");

test("le nombre de séries est annoncé", () => {
  contient("vos 150 séries", P.texteSuppressionCompte(150, "standard"), "au pluriel");
  contient("votre série", P.texteSuppressionCompte(1, "standard"), "au singulier");
  contient("bibliothèque (vide)", P.texteSuppressionCompte(0, "standard"), "rien à perdre");
});

test("l illimité apprend que son forfait ne revient pas", () => {
  contient("forfait illimité", P.texteSuppressionCompte(300, "illimite"), "averti");
  sans("forfait illimité", P.texteSuppressionCompte(150, "standard"), "le standard n a rien à perdre de ce côté");
});

test("l irréversibilité reste dite", () => {
  contient("irréversible", P.texteSuppressionCompte(3, "standard"), "en fin de message");
});
