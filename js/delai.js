/* =========================================================
   Comptes à rebours des délais d'attente.

   Un « Réessayez dans 60 secondes » figé ne dit pas grand-chose : au
   bout d'une minute, rien ne bouge et on ne sait pas si l'attente est
   finie. Le chiffre descend donc en direct, et la mention disparaît
   d'elle-même quand elle n'a plus lieu d'être.

   Chaque élément porte simplement « data-restant », le nombre de
   secondes. Le serveur le recalcule à chaque affichage de page : un
   rechargement au milieu d'une attente de 60 s reprend donc à 33, puis
   32, 31… sans que rien ne soit mémorisé côté navigateur.
   ========================================================= */

window.Delai = (() => {
  "use strict";

  function accorder(n) {
    return n + (n > 1 ? " secondes" : " seconde");
  }

  /**
   * Fait descendre un élément jusqu'à zéro.
   *
   * On vise une ÉCHÉANCE absolue plutôt que de décrémenter un compteur :
   * les navigateurs ralentissent les minuteries des onglets en arrière-plan,
   * si bien qu'un compteur décrémenté dériverait et afficherait encore 40
   * quand l'attente est finie depuis longtemps.
   */
  function lancer(element, secondes, fini) {
    const restant = Math.max(0, parseInt(secondes, 10) || 0);
    if (restant === 0) {
      if (fini) fini();
      return;
    }

    const echeance = Date.now() + restant * 1000;
    element.textContent = accorder(restant);

    const battement = setInterval(() => {
      const reste = Math.ceil((echeance - Date.now()) / 1000);
      if (reste > 0) {
        element.textContent = accorder(reste);
        return;
      }
      clearInterval(battement);
      element.textContent = "maintenant";
      element.classList.add("delai-fini");
      if (fini) fini();
      // Les pages qui veulent réactiver un bouton écoutent cet évènement
      // plutôt que de se donner un rendez-vous chronométré de leur côté.
      element.dispatchEvent(new CustomEvent("delai-termine", { bubbles: true }));
    }, 250); // plus court qu'une seconde : l'affichage ne saute pas un chiffre

    return () => clearInterval(battement);
  }

  /** Démarre tous les compteurs présents dans la page (ou une partie). */
  function brancher(racine = document) {
    racine.querySelectorAll(".delai[data-restant]").forEach((el) => {
      if (el.dataset.lance === "1") return; // déjà en cours
      el.dataset.lance = "1";
      lancer(el, el.dataset.restant);
    });
  }

  document.addEventListener("DOMContentLoaded", () => brancher());

  return { lancer, brancher, accorder };
})();
