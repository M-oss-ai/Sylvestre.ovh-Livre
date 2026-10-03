/* =========================================================
   Le double-appui ne zoome sur AUCUNE page (demande de l'utilisateur).

   Première ligne : « touch-action: manipulation » sur tous les éléments
   (css/style.css). Un navigateur qui la respecte n'a besoin de rien
   d'autre. Celle-ci double : si un double-appui zoomait malgré elle
   (Safari sur iPhone), il est neutralisé à la levée du second doigt —
   l'astuce connue de « touchend », qui ne dépend d'aucune règle CSS.

   Le revers de cette astuce : le navigateur n'envoie plus le « click » du
   second appui, et un bouton « → » touché deux fois vite ne comptait plus
   qu'une fois. On le rejoue donc nous-mêmes, sur le même élément, de sorte
   que SEUL le zoom disparaisse.

   Ce qu'on ne touche pas :
     - le pincement, le recours de qui lit mal : deux doigts, jamais un
       « appui » (un seul doigt, posé puis levé sur place) ;
     - un champ de saisie, où le double-appui sélectionne un mot ;
     - un geste qui n'est pas un appui : défilement, appui long.

   Chargé par TOUTES les pages, avant leurs autres scripts : une page qui
   l'oublie zoomerait là où il compte (tests/cas/tactile_test.php).
   ========================================================= */

window.Tactile = (() => {
  "use strict";

  // Un appui : le doigt reste en place, et ne s'attarde pas (au-delà, c'est
  // un appui long — la sélection d'un texte).
  const APPUI_DUREE_MAX_MS = 500;
  const APPUI_DEPLACEMENT_MAX_PX = 12;

  // Un double-appui : deux appuis rapprochés dans le temps et dans l'espace.
  // Le délai est celui de iOS, une fraction de seconde.
  const DOUBLE_DELAI_MAX_MS = 350;
  const DOUBLE_DISTANCE_MAX_PX = 45;

  // Les <input> qui ne reçoivent pas de texte : un double-appui y est un clic de plus.
  const SANS_TEXTE = ["button", "submit", "reset", "checkbox", "radio", "file", "image", "color", "range", "hidden"];

  /**
   * Le doigt posé (`debut`) puis levé (`fin`) forme-t-il un APPUI ? Chaque
   * point : { temps, x, y } (millisecondes, pixels). Un glissement est un
   * défilement ; un doigt qui s'attarde est un appui long.
   */
  function estAppui(debut, fin) {
    if (!debut || !fin) return false;
    const duree = fin.temps - debut.temps;
    if (!(duree >= 0 && duree <= APPUI_DUREE_MAX_MS)) return false;
    return Math.hypot(fin.x - debut.x, fin.y - debut.y) <= APPUI_DEPLACEMENT_MAX_PX;
  }

  /**
   * `courant` complète-t-il un double-appui commencé par `precedent` ?
   * Deux appuis (levée du doigt : { temps, x, y }) proches dans le temps et
   * dans l'espace — la condition qui fait zoomer Safari.
   */
  function estDoubleAppui(precedent, courant) {
    if (!precedent || !courant) return false;
    const ecart = courant.temps - precedent.temps;
    if (!(ecart >= 0 && ecart <= DOUBLE_DELAI_MAX_MS)) return false;
    return Math.hypot(courant.x - precedent.x, courant.y - precedent.y) <= DOUBLE_DISTANCE_MAX_PX;
  }

  /** L'élément (ou l'un de ses parents) reçoit-il du texte ? Le double-appui y sélectionne un mot. */
  function zoneDeSaisie(el) {
    if (!el || typeof el.closest !== "function") return false;
    if (el.isContentEditable) return true;
    const champ = el.closest("input, textarea, select");
    if (!champ) return false;
    return champ.tagName !== "INPUT" || !SANS_TEXTE.includes(champ.type);
  }

  /** Le « click » que le navigateur n'enverra pas, au même endroit et sur le même élément. */
  function rejouerClic(cible, doigt) {
    if (!cible || typeof cible.dispatchEvent !== "function") return;
    cible.dispatchEvent(new MouseEvent("click", {
      bubbles: true, cancelable: true, composed: true, view: window, detail: 2,
      clientX: doigt.clientX, clientY: doigt.clientY, screenX: doigt.screenX, screenY: doigt.screenY,
    }));
  }

  const point = (e, doigt) => ({ temps: e.timeStamp, x: doigt.clientX, y: doigt.clientY });

  /**
   * Surveille les doigts qui touchent `racine`. Un second appui qui suit un
   * premier de près n'a plus de suite pour le navigateur : pas de zoom.
   * Appelée pour `document` au chargement ; les tests l'appellent sur un
   * élément à part.
   */
  function brancher(racine) {
    let debut = null;    // le doigt posé, pas encore levé
    let dernier = null;  // le dernier appui complet

    racine.addEventListener("touchstart", (e) => {
      if (e.touches.length === 1) {
        debut = point(e, e.touches[0]);
      } else {         // un pincement : ni un appui, ni le début d'un double-appui
        debut = null;
        dernier = null;
      }
    }, { passive: true });

    racine.addEventListener("touchcancel", () => {
      debut = null;
      dernier = null;
    }, { passive: true });

    // Non passif : sans cela preventDefault() n'aurait aucun effet.
    racine.addEventListener("touchend", (e) => {
      const pose = debut;
      debut = null;
      if (e.touches.length !== 0 || e.changedTouches.length !== 1) {
        dernier = null;
        return;
      }
      const doigt = e.changedTouches[0];
      const appui = point(e, doigt);
      if (!estAppui(pose, appui)) {
        dernier = null;
        return;
      }
      const double = estDoubleAppui(dernier, appui);
      dernier = appui;   // un troisième appui rapproché forme un nouveau double-appui avec celui-ci
      if (!double || zoneDeSaisie(e.target)) return;
      if (e.cancelable) e.preventDefault();
      rejouerClic(e.target, doigt);
    }, { passive: false });
  }

  brancher(document);

  return { estAppui, estDoubleAppui, zoneDeSaisie, brancher };
})();
