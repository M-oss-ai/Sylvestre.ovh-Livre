<?php
/* =====================================================================
   L'ordre figé, la barre qui suit le défilement, la saisie sur téléphone
   (demande de l'utilisateur).

   Trois demandes, dans js/app.js et css/style.css :
     1. « L'ordre des séries doit se mettre à jour uniquement quand on change le
        tri » : une série modifiée (→, ←, la fiche, l'étoile) reste où elle est ;
        la grille ne se range qu'au changement de VUE (filtre, recherche, tri) ou
        au rechargement. Une série modifiée reçoit un rang de tête, pour que
        « Plus récent » la mène devant au prochain rangement.
     2. La barre des filtres avance de la distance qu'on fait défiler (au lieu de
        disparaître ou revenir d'un bloc après dix pixels).
     3. Sur téléphone, toucher le champ de recherche cache la barre.

   La logique pure (positionBarre, positionSaisie, comparerVue, empreinteVue) est
   testée dans tests/js. Ce fichier lit les SOURCES : ce qui branche cette logique
   sur la page ne se teste pas autrement que dans un navigateur, mais ses
   promesses se gardent — surtout les retours en arrière qui se font passer pour
   des corrections.
   ===================================================================== */

declare(strict_types=1);

require __DIR__ . '/../lanceur.php';

function source_ordre_barre(string $chemin): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/' . $chemin));
}

$js  = source_ordre_barre('js/app.js');
$css = source_ordre_barre('css/style.css');

/** Le corps d'une fonction du client, de son entête jusqu'à `$longueur` caractères plus loin. */
function bloc_ordre_barre(string $js, string $entete, int $longueur): string
{
    $i = strpos($js, $entete);
    return $i === false ? '' : substr($js, $i, $longueur);
}

groupe('L\'ordre ne se recalcule qu\'au changement de vue');

test('appliquerVue() range la grille quand l\'empreinte de la vue change, pas à chaque appel', function () use ($js) {
    $vue = bloc_ordre_barre($js, 'function appliquerVue()', 5200);
    contient('const signature = B.empreinteVue(filtresStatut, filtresImage, favorisSeuls, prep.q, tri);', $vue,
        'l\'empreinte vient de la fonction pure, testée');
    contient('const vueChangee = signature !== signatureVue;', $vue, 'une autre vue ?');
    motif('~if \(vueChangee\) \{\s*const rangees = toutes~', $vue, 'le rangement est gardé par ce test, et par rien d\'autre');
    contient('B.comparerVue(tri, a, b)', $vue, 'pertinence, tri, rang d\'origine : la comparaison pure et testée');
    contient('.appendChild(c)', $vue, 'les cartes se déplacent dans le DOM…');
    vrai(strpos($vue, 'if (vueChangee) {') < strpos($vue, '.appendChild(c)'), '…seulement dans ce bloc');
    contient('toutes.forEach((c, i) => { if (c._ordre === undefined) c._ordre = i; });', $vue,
        'le rang d\'origine est noté une fois : un appel de plus ne le réécrit plus (il effaçait le rang de tête d\'une série modifiée)');
});

test('l\'ancien mécanisme est parti : plus d\'« ordre bousculé » recalculé à chaque appel', function () use ($js) {
    sans('ordreBouscule', $js, 'ni la variable, ni ses deux branches');
    sans('c._ordre = i; });
    }', $js, 'ni la réécriture du rang à chaque appel');
});

test('les pages repartent de la première quand la vue change, après le rangement', function () use ($js) {
    $vue = bloc_ordre_barre($js, 'function appliquerVue()', 5200);
    motif('~if \(vueChangee\) \{\s*signatureVue = signature;\s*pagesAffichees = 1;~', $vue, 'la même condition');
    vrai(strpos($vue, 'const rangees') < strpos($vue, 'B.pagesSeries('), 'on garde les PREMIÈRES séries dans l\'ordre définitif');
});

test('poserCarte() : une série modifiée garde sa place mais reçoit un rang de tête', function () use ($js) {
    contient('function poserCarte(html, id, focus = null, remonte = true) {', $js, 'le paramètre `remonte`, vrai par défaut');
    contient('nouvelle._ordre = remonte ? ordreNouveau-- : ancienne._ordre;', $js,
        'rang de tête si modifiée (« Plus récent » la mènera devant au prochain rangement), son ancien rang si rien ne l\'a modifiée');
    sans('nouvelle._ordre = ancienne._ordre;', $js, 'plus d\'ancien rang gardé d\'office : « Plus récent » la laissait à sa place jusqu\'au rechargement');
    $bloc = bloc_ordre_barre($js, 'function poserCarte(', 3500);
    contient('$grid.prepend(nouvelle);', $bloc, 'une série NOUVELLE se met toujours en tête, quel que soit le tri');
});

test('le relevé des nouveaux tomes ne déplace une série que sous « Plus récent », sans recherche', function () use ($js) {
    $bloc = bloc_ordre_barre($js, 'async function verifierNouveautes()', 2200);
    contient('const ordreDuServeur = tri === "recentes" && !L.prepareRecherche(recherche).q;', $bloc,
        'en tête seulement là où c\'est SA place (celle que le serveur lui donnerait au rechargement)');
    contient('if (carte && ordreDuServeur) $grid.prepend(carte);', $bloc, 'sous un autre tri, ou pendant une recherche : elle ne bouge pas');
    sans('carte._ordre = ordreNouveau--;', $bloc, 'son rang de tête est posé par poserCarte()');
    contient('poserCarte(c.carte, c.id, null, false)', $bloc,
        'un statut qui change (« En attente », « Terminée ») ne modifie pas la série côté serveur : elle garde son rang, et sa place');
});

groupe('La barre suit le défilement');

test('js/app.js : la position vient de la fonction pure, posée en propriété CSS (CSSOM : la CSP refuse l\'attribut style)', function () use ($js) {
    contient('B.positionBarre(suivi, mesureBarre())', $js, 'la décision est pure et testée');
    contient('$barreFiltres.style.setProperty("--decalage", d + "px");', $js, 'posée en CSSOM');
    contient('hauteur: hauteurBarre(),', $js, 'la hauteur de la barre, mesurée');
    contient('return $barreFiltres.offsetHeight + 2;', $js, 'le filet du bas compris : cachée, rien ne dépasse sous la barre du haut');
    sans('escamotee', $js, 'plus de classe tout-ou-rien');
    sans('suiviDefilement', $js, 'ni son ancienne fonction');
    sans('SEUIL_SENS', $js, 'ni le seuil de dix pixels');
    sans('style="', $js, 'aucun style en ligne');
});

test('js/app.js : Maj+Tab dans la barre la montre entière', function () use ($js) {
    $bloc = bloc_ordre_barre($js, '$barreFiltres.addEventListener("focusin"', 220);
    contient('if (suivi) suivi.decalage = 0;', $bloc, 'l\'état suit, sinon le défilement suivant la recacherait');
    contient('poserDecalage(0);', $bloc, 'et elle se montre');
});

test('style.css : la barre se décale de --decalage, sans transition', function () use ($css) {
    $regle = bloc_ordre_barre($css, ".barre-filtres {\n  position: sticky;", 1200);
    $regle = substr($regle, 0, (int) strpos($regle, "\n}\n") + 3);
    contient('transform: translateY(calc(var(--decalage, 0px) * -1));', $regle, 'au pixel près');
    sans('transition:', $regle, 'aucune transition : elle suit la page, pas le temps (un délai ferait traîner la barre derrière le doigt)');
    sans('.escamotee', $css, 'l\'ancienne classe tout-ou-rien est partie');
});

groupe('Téléphone : la saisie cache la barre, sans aucun défilement forcé');

test('js/app.js : toucher le champ pose html.saisie, seulement sur écran tactile', function () use ($js) {
    contient('const ecranTactile = window.matchMedia("(pointer: coarse)");', $js, 'même condition que les autres gestes tactiles du site');
    $bloc = bloc_ordre_barre($js, '$search.addEventListener("focus"', 200);
    contient('if (ecranTactile.matches) racine.classList.add("saisie");', $bloc, 'rien ne change sur ordinateur');
    sans('scrollTo', $bloc, 'AUCUN défilement forcé : un vrai téléphone fait défiler la page de son côté au focus, et les deux se battaient (barre cachée mais champ non choisi, ou champ choisi mais barre revenue)');
    sans('scrollBy', $bloc, 'idem');
    sans('positionSaisie', $js, 'l\'ancienne fonction qui défilait la page est partie');
});

test('js/app.js : pendant la saisie la barre n\'est plus suivie : rien ne peut la ramener', function () use ($js) {
    $bloc = bloc_ordre_barre($js, 'function suivreDefilement()', 400);
    contient('if (racine.classList.contains("saisie")) return;', $bloc, 'le défilement du navigateur ne la fait plus revenir');
});

test('js/app.js : quitter le champ ramène la barre, sans que les séries bougent', function () use ($js) {
    $bloc = bloc_ordre_barre($js, '$search.addEventListener("blur"', 1700);
    contient('racine.classList.add("retour-saisie");', $bloc, 'le temps du retour, pas d\'ancrage du défilement : Chrome compense seul, Safari non, on ne compense qu\'une fois');
    contient('setTimeout(() => racine.classList.remove("retour-saisie"), 150);', $bloc, 'puis il revient');
    contient('racine.classList.remove("saisie");', $bloc, 'la barre revient dans la page');
    contient('B.compensationRetour(window.scrollY, $barreFiltres.offsetHeight, marge)', $bloc, 'de combien défiler : la fonction pure, testée');
    contient('window.scrollBy({ top: delta, behavior: "instant" })', $bloc, 'sa place revient et pousse les séries : on défile d\'autant (d\'un bond : html défile en douceur)');
    contient('suivi = null;', $bloc, 'elle repart d\'un relevé neuf, entière');
    contient('suivreDefilement();', $bloc, 'et suit le défilement dès maintenant');
});

test('style.css : pendant la saisie, la barre sort de la mise en page', function () use ($css) {
    contient('html.saisie .barre-filtres { display: none; }', $css, 'plus de place prise : les séries montent d\'elles-mêmes');
    sans('min-height: calc(100vh', $css, 'plus de place réservée pour défiler : il n\'y a plus de défilement');
    contient('html.retour-saisie { overflow-anchor: none; }', $css, 'au retour, c\'est nous qui compensons la hauteur, pas le navigateur');
});
test('le geste qui ferme le clavier existe toujours (commun.js) : c\'est lui qui fait revenir les filtres au défilement', function () {
    $commun = source_ordre_barre('js/commun.js');
    contient('touchmove', $commun, 'un glissement hors du champ le quitte');
    contient('GLISSEMENT_MIN_PX', $commun, 'au-delà d\'un seuil');
});
