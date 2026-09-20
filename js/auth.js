/* Pages connexion.php / inscription.php : afficher ou masquer un mot de passe. */
(() => {
  "use strict";
  document.querySelectorAll(".toggle-password").forEach((btn) => {
    btn.addEventListener("click", () => {
      const champ = document.getElementById(btn.dataset.cible);
      if (!champ) return;
      const visible = champ.type === "text";
      champ.type = visible ? "password" : "text";
      btn.textContent = visible ? "👁️" : "🙈";
      btn.setAttribute("aria-label", visible ? "Afficher le mot de passe" : "Masquer le mot de passe");
    });
  });
})();
