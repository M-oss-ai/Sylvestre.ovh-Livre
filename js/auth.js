/* Pages de connexion, d'inscription et de réinitialisation : afficher ou
   masquer un mot de passe, et effacer l'erreur d'un champ qu'on corrige. */
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

  /* Le message posé sous un champ par le serveur (champ_erreur, en PHP)
     disparaît dès qu'on corrige ce champ : il ne doit pas survivre à ce
     qui l'a causé. Même convention que js/commun.js. */
  document.addEventListener("input", (e) => {
    const champ = e.target;
    if (!champ.id || champ.getAttribute("aria-invalid") !== "true") return;
    const id = champ.id + "-erreur";
    const message = document.getElementById(id);
    if (message) message.remove();
    champ.removeAttribute("aria-invalid");
    const reste = (champ.getAttribute("aria-describedby") || "").split(/\s+/).filter((x) => x && x !== id);
    if (reste.length) champ.setAttribute("aria-describedby", reste.join(" "));
    else champ.removeAttribute("aria-describedby");
  });
})();
