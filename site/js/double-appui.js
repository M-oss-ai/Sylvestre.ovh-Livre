/* =========================================================
   Le double-appui ne zoome sur AUCUNE page (demande de l'utilisateur).

   Safari sur iPhone (iOS 13 et plus) attend un second appui — et zoome —
   sur tout ce qu'il croit sans réaction au toucher : le fond de page, un
   texte, l'espace à côté d'un champ. Dès qu'un gestionnaire de « click »
   est posé sur `document`, TOUTE la page réagit : le clic part tout de
   suite et le double-appui n'est plus attendu (WebKit, bug 205158).

   index.php et parametres.php en avaient un sans le savoir : celui de
   commun.js, qui fait quitter un champ en touchant ailleurs. Les autres
   pages n'en avaient aucun, et zoomaient (connexion, inscription, mentions
   légales). `touch-action: manipulation` (css/style.css) n'y changeait
   rien, et un script qui annulait le second appui n'était qu'un détour.

   Ce gestionnaire ne fait RIEN : c'est sa présence qui compte. Ne pas le
   retirer, ni le « nettoyer » : le double-appui zoommerait de nouveau.
   Chargé par TOUTES les pages (tests/cas/tactile_test.php le vérifie) ; le
   pincement reste permis, c'est le recours de qui lit mal.
   ========================================================= */

document.addEventListener("click", () => {});
