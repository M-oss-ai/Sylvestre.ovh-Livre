<?php
/* =====================================================================
   La fiche d'une série (js/app.js) ne se ferme PAS au clic à côté d'elle.

   Demande de l'utilisateur : « quand on modifie une série et qu'on clique
   ailleurs, ça ne fait rien — on ne la ferme pas ». Le fond sombre autour de
   la fiche ne réagit plus : ni fermeture, ni question « Abandonner les
   modifications ? ». On ne la quitte que par « ✕ », « Annuler » ou Échap
   (qui, lui, demande confirmation quand la fiche a changé).

   Ce test lit les SOURCES : le branchement n'est pas joignable par les
   tests unitaires, mais l'absence d'un écouteur se prouve en le cherchant.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

$js = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/js/app.js'));
/** Le code sans ses commentaires : on regarde ce qu'il fait, pas ce qu'il raconte. */
$code = (string) preg_replace('~/\*.*?\*/|(?<![:"\'])//[^\n]*~s', '', $js);

groupe('La fiche — un clic sur le fond ne la ferme pas');

test('aucun écouteur de clic sur le fond de la fiche', function () use ($code) {
    sans('$overlay.addEventListener("click"', $code, 'le fond de la fiche n\'écoute plus le clic');
    sans('e.target === $overlay', $code, 'et rien ne compare plus la cible au fond');
});

test('« ✕ », « Annuler » et Échap la ferment toujours', function () use ($code) {
    contient('document.getElementById("btn-close").addEventListener("click", fermerModale);', $code, '« ✕ »');
    contient('document.getElementById("btn-cancel").addEventListener("click", fermerModale);', $code, '« Annuler »');
    contient('else if (modaleOuverte) fermerSiRienNeChange();', $code, 'Échap, avec la confirmation quand la fiche a changé');
});

test('les autres fenêtres gardent leur comportement', function () use ($code) {
    contient('$abandonOverlay.addEventListener("click", (e) => { if (e.target === $abandonOverlay) fermerAbandon(false); });', $code,
        'la question « Abandonner ? » : un clic à côté reste « non », donc on reste dans la fiche');
});

test('le code dit pourquoi, pour que personne ne remette l\'écouteur en croyant réparer un oubli', function () use ($js) {
    contient('UN CLIC À CÔTÉ DE LA FICHE', $js, 'la règle est écrite dans app.js');
    contient('Aucun écouteur sur le fond', $js, 'et l\'absence est signalée à l\'endroit où l\'écouteur était');
});
