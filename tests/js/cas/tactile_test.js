/* =====================================================================
   js/tactile.js — le double-appui ne zoome pas.

   Deux étages : les fonctions pures qui décident ce qu'est un appui et un
   double-appui, puis le branchement sur de vrais évènements tactiles
   (Touch et TouchEvent existent dans Edge et Chrome, sans écran tactile).
   Ce que le navigateur fait ensuite du preventDefault() — ne pas zoomer —
   ne se mesure pas ici : il faut un iPhone.
   ===================================================================== */

const T = window.Tactile;

/** Un point du doigt : au temps t (ms), en (x, y). */
const pt = (temps, x = 10, y = 10) => ({ temps, x, y });

groupe("Tactile.estAppui() — un appui n est ni un glissement ni un appui long");

test("un doigt posé puis levé sur place, vite, est un appui", () => {
  vrai(T.estAppui(pt(1000), pt(1080)), "80 ms, sur place");
  vrai(T.estAppui(pt(1000), pt(1080, 15, 14)), "quelques pixels de tremblement");
  vrai(T.estAppui(pt(1000), pt(1000)), "instantané");
});

test("un glissement n est pas un appui : c est un défilement", () => {
  faux(T.estAppui(pt(1000), pt(1080, 10, 60)), "50 px plus bas");
  faux(T.estAppui(pt(1000), pt(1080, 60, 10)), "50 px à côté");
});

test("un doigt qui s attarde n est pas un appui : c est un appui long (sélection)", () => {
  faux(T.estAppui(pt(1000), pt(1700)), "700 ms");
  vrai(T.estAppui(pt(1000), pt(1500)), "500 ms : encore un appui");
});

test("des points absents ou incohérents ne font pas un appui", () => {
  faux(T.estAppui(null, pt(1000)), "pas de pose");
  faux(T.estAppui(pt(1000), null), "pas de levée");
  faux(T.estAppui(pt(2000), pt(1000)), "levé avant d être posé");
  faux(T.estAppui(pt(NaN), pt(1000)), "temps illisible");
});

groupe("Tactile.estDoubleAppui() — deux appuis proches dans le temps et l espace");

test("deux appuis rapprochés, au même endroit : un double-appui", () => {
  vrai(T.estDoubleAppui(pt(1000), pt(1200)), "200 ms, sur place");
  vrai(T.estDoubleAppui(pt(1000), pt(1350)), "350 ms : la limite");
  vrai(T.estDoubleAppui(pt(1000), pt(1100, 40, 10)), "un peu à côté");
});

test("trop espacés dans le temps : deux appuis, pas un double", () => {
  faux(T.estDoubleAppui(pt(1000), pt(1400)), "400 ms");
  faux(T.estDoubleAppui(pt(1000), pt(3000)), "deux secondes");
});

test("trop éloignés : deux boutons différents, pas un double-appui", () => {
  faux(T.estDoubleAppui(pt(1000, 10, 10), pt(1100, 200, 10)), "190 px à côté");
  faux(T.estDoubleAppui(pt(1000, 10, 10), pt(1100, 10, 300)), "290 px plus bas");
});

test("rien avant, ou un ordre impossible : pas de double-appui", () => {
  faux(T.estDoubleAppui(null, pt(1000)), "premier appui de la série");
  faux(T.estDoubleAppui(pt(1000), null), "pas d appui courant");
  faux(T.estDoubleAppui(pt(1200), pt(1000)), "le second avant le premier");
});

groupe("Tactile.zoneDeSaisie() — où le double-appui sélectionne un mot");

test("un champ de texte, une zone multiligne, une liste : oui", () => {
  const t = terrain(`<input id="a" type="text"><input id="b" type="password"><input id="c" type="url">
    <input id="d" type="search"><input id="e" type="number"><textarea id="f"></textarea>
    <select id="g"><option>x</option></select>`);
  ["a", "b", "c", "d", "e", "f", "g"].forEach((id) => {
    vrai(T.zoneDeSaisie(t.querySelector("#" + id)), "#" + id);
  });
});

test("un élément posé dans un champ éditable compte aussi", () => {
  const t = terrain(`<div id="e" contenteditable="true"><b id="dedans">texte</b></div>`);
  vrai(T.zoneDeSaisie(t.querySelector("#e")), "le champ");
  vrai(T.zoneDeSaisie(t.querySelector("#dedans")), "ce qu il contient");
});

test("un bouton, une case, un lien, un texte : non, ce sont des clics comme les autres", () => {
  const t = terrain(`<button id="a" type="button">Ok</button><input id="b" type="checkbox">
    <input id="c" type="radio"><input id="d" type="submit" value="x"><input id="e" type="file">
    <a id="f" href="#x">lien</a><p id="g">texte</p><label id="h">libellé</label>`);
  ["a", "b", "c", "d", "e", "f", "g", "h"].forEach((id) => {
    faux(T.zoneDeSaisie(t.querySelector("#" + id)), "#" + id);
  });
});

test("rien du tout : non", () => {
  faux(T.zoneDeSaisie(null), "null");
  faux(T.zoneDeSaisie(undefined), "undefined");
  faux(T.zoneDeSaisie({}), "un objet qui n est pas un élément");
});

groupe("Tactile.brancher() — de vrais évènements tactiles");

/* Une racine À PART, hors du document : le branchement automatique sur
   `document` n'y voit rien, les évènements ne sont traités qu'une fois. */
function banc(html) {
  const racine = document.createElement("div");
  racine.innerHTML = html;
  T.brancher(racine);
  return racine;
}

/**
 * Un appui au temps `temps` : doigt posé, puis levé `duree` ms plus tard,
 * `dx` pixels à côté. Rend l'évènement de la levée — c'est lui que le
 * navigateur annule (ou non) pour empêcher le zoom. timeStamp est posé à
 * la main : on ne peut pas attendre 400 ms dans un test.
 */
function appuyer(cible, { temps, x = 10, y = 10, dx = 0, duree = 60 }) {
  const doigt = (cx) => new Touch({ identifier: 1, target: cible, clientX: cx, clientY: y, screenX: cx, screenY: y });
  const evenement = (type, touches, changes, t) => {
    const e = new TouchEvent(type, { bubbles: true, cancelable: true, touches, changedTouches: changes });
    Object.defineProperty(e, "timeStamp", { value: t });
    return e;
  };
  const pose = doigt(x);
  const leve = doigt(x + dx);
  cible.dispatchEvent(evenement("touchstart", [pose], [pose], temps));
  const fin = evenement("touchend", [], [leve], temps + duree);
  cible.dispatchEvent(fin);
  return fin;
}

/** Les clics reçus par `cible` : la valeur de `detail` de chacun. */
function ecouterClics(cible) {
  const recus = [];
  cible.addEventListener("click", (e) => recus.push(e.detail));
  return recus;
}

test("le premier appui est laissé au navigateur : rien d annulé, rien de rejoué", () => {
  const racine = banc(`<button id="b" type="button">→</button>`);
  const bouton = racine.querySelector("#b");
  const clics = ecouterClics(bouton);
  const fin = appuyer(bouton, { temps: 1000 });
  faux(fin.defaultPrevented, "un seul appui : aucun zoom à empêcher");
  egale(0, clics.length, "le click natif viendrait du navigateur, pas de nous");
});

test("le second appui rapproché est annulé : c est lui qui ferait zoomer", () => {
  const racine = banc(`<p id="t">du texte</p>`);
  const texte = racine.querySelector("#t");
  appuyer(texte, { temps: 1000 });
  const second = appuyer(texte, { temps: 1200 });
  vrai(second.defaultPrevented, "le preventDefault de la levée du second doigt");
});

test("…et son clic est rejoué, deux fois vite sur un bouton comptent deux fois", () => {
  const racine = banc(`<button id="b" type="button">→</button>`);
  const bouton = racine.querySelector("#b");
  const clics = ecouterClics(bouton);
  appuyer(bouton, { temps: 1000 });
  appuyer(bouton, { temps: 1200 });
  egale(1, clics.length, "le navigateur ne l envoie plus : nous le rejouons");
  egale(2, clics[0], "marqué comme second clic (detail 2), comme le ferait un navigateur");
});

test("le clic rejoué suit son chemin : il remonte jusqu à ceux qui écoutent plus haut", () => {
  const racine = banc(`<div id="grille"><button id="b" type="button">→</button></div>`);
  const recus = [];
  racine.querySelector("#grille").addEventListener("click", (e) => recus.push(e.target.id));
  const bouton = racine.querySelector("#b");
  appuyer(bouton, { temps: 1000 });
  appuyer(bouton, { temps: 1150 });
  egale(JSON.stringify(["b"]), JSON.stringify(recus), "la grille voit le bouton, comme pour un vrai clic");
});

test("un appui tardif n est pas un double-appui : rien n est annulé", () => {
  const racine = banc(`<button id="b" type="button">→</button>`);
  const bouton = racine.querySelector("#b");
  const clics = ecouterClics(bouton);
  appuyer(bouton, { temps: 1000 });
  const second = appuyer(bouton, { temps: 1600 });
  faux(second.defaultPrevented, "600 ms plus tard");
  egale(0, clics.length, "rien de rejoué");
});

test("deux appuis en deux endroits éloignés ne font pas un double-appui", () => {
  const racine = banc(`<button id="a" type="button">A</button><button id="b" type="button">B</button>`);
  appuyer(racine.querySelector("#a"), { temps: 1000, x: 10 });
  const second = appuyer(racine.querySelector("#b"), { temps: 1100, x: 250 });
  faux(second.defaultPrevented, "deux boutons, pas un double-appui");
});

test("un champ de saisie garde son double-appui : il y sélectionne un mot", () => {
  const racine = banc(`<input id="c" type="text" value="un mot">`);
  const champ = racine.querySelector("#c");
  const clics = ecouterClics(champ);
  appuyer(champ, { temps: 1000 });
  const second = appuyer(champ, { temps: 1200 });
  faux(second.defaultPrevented, "rien d annulé dans un champ");
  egale(0, clics.length, "rien de rejoué");
});

test("un défilement entre deux appuis remet à zéro", () => {
  const racine = banc(`<p id="t">du texte</p>`);
  const texte = racine.querySelector("#t");
  appuyer(texte, { temps: 1000 });
  appuyer(texte, { temps: 1100, dx: 80 });   // un glissement, pas un appui
  const apres = appuyer(texte, { temps: 1200 });
  faux(apres.defaultPrevented, "le glissement a interrompu la série");
});

test("un appui long entre deux appuis remet à zéro", () => {
  const racine = banc(`<p id="t">du texte</p>`);
  const texte = racine.querySelector("#t");
  appuyer(texte, { temps: 1000 });
  appuyer(texte, { temps: 1100, duree: 800 });   // sélection de texte, pas un appui
  const apres = appuyer(texte, { temps: 2000 });
  faux(apres.defaultPrevented, "l appui long a interrompu la série");
});

test("un troisième appui rapproché est aussi annulé", () => {
  const racine = banc(`<p id="t">du texte</p>`);
  const texte = racine.querySelector("#t");
  appuyer(texte, { temps: 1000 });
  appuyer(texte, { temps: 1200 });
  vrai(appuyer(texte, { temps: 1400 }).defaultPrevented, "le troisième forme un double avec le second");
});

test("un pincement ne déclenche rien : deux doigts ne sont pas un appui", () => {
  const racine = banc(`<p id="t">du texte</p>`);
  const texte = racine.querySelector("#t");
  const un = new Touch({ identifier: 1, target: texte, clientX: 10, clientY: 10 });
  const deux = new Touch({ identifier: 2, target: texte, clientX: 60, clientY: 10 });
  const evenement = (type, touches, changes, t) => {
    const e = new TouchEvent(type, { bubbles: true, cancelable: true, touches, changedTouches: changes });
    Object.defineProperty(e, "timeStamp", { value: t });
    return e;
  };
  appuyer(texte, { temps: 1000 });
  // Un second doigt se pose : le geste devient un pincement…
  texte.dispatchEvent(evenement("touchstart", [un], [un], 1100));
  texte.dispatchEvent(evenement("touchstart", [un, deux], [deux], 1110));
  // … et les deux se lèvent l un après l autre.
  texte.dispatchEvent(evenement("touchend", [un], [deux], 1180));
  const dernier = evenement("touchend", [], [un], 1200);
  texte.dispatchEvent(dernier);
  faux(dernier.defaultPrevented, "le pincement n est jamais annulé : c est le recours de qui lit mal");
});

test("un geste interrompu (touchcancel) remet à zéro", () => {
  const racine = banc(`<p id="t">du texte</p>`);
  const texte = racine.querySelector("#t");
  appuyer(texte, { temps: 1000 });
  texte.dispatchEvent(new TouchEvent("touchcancel", { bubbles: true }));
  faux(appuyer(texte, { temps: 1100 }).defaultPrevented, "la série est repartie de zéro");
});
