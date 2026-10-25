/* =====================================================================
   js/push.js — la logique pure des notifications push (window.Push) :
   la clé du site en octets, le diagnostic (iPhone, autorisation refusée…),
   les phrases d'erreur.
   Le branchement sur la page (service worker, autorisation, abonnement) ne
   se teste pas ici : il demande un vrai navigateur et un vrai service de
   notification, et se vérifie à la main.
   ===================================================================== */

const PN = window.Push;

/** Un environnement de navigateur complet, dont on change ce que le test regarde. */
const env = (modifs = {}) => Object.assign(
  { serviceWorker: true, pushManager: true, notification: true, permission: "default", ios: false, standalone: false },
  modifs
);

groupe("Push.cleServeur() — la clé du site, en octets");

test("un texte base64url donne les mêmes octets, sur toutes les longueurs", () => {
  /* La clé VAPID fait 65 octets (65 mod 4 = 1) : le remplissage manquant doit
     se refaire, sinon atob() échoue. On essaie les quatre restes. */
  for (let n = 1; n <= 70; n++) {
    const source = Uint8Array.from({ length: n }, (_, i) => (i * 53 + 7) % 256);
    let texte = btoa(String.fromCharCode(...source)).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
    const octets = PN.cleServeur(texte);
    vrai(octets instanceof Uint8Array, "un Uint8Array, longueur " + n);
    egale(Array.from(source), Array.from(octets), "longueur " + n);
  }
});

test("l alphabet de l URL est compris", () => {
  egale([0xfb, 0xff, 0xfe], Array.from(PN.cleServeur("-__-")), "- et _ valent + et /");
});

groupe("Push.memeCle() — l abonnement a-t-il la clé actuelle du site ?");

test("une même clé est reconnue, une autre non", () => {
  const cle = "AQIDBA"; // 1, 2, 3, 4
  vrai(PN.memeCle(Uint8Array.from([1, 2, 3, 4]).buffer, cle), "même clé");
  faux(PN.memeCle(Uint8Array.from([1, 2, 3, 5]).buffer, cle), "un octet change");
  faux(PN.memeCle(Uint8Array.from([1, 2, 3]).buffer, cle), "plus courte");
  faux(PN.memeCle(Uint8Array.from([1, 2, 3, 4, 5]).buffer, cle), "plus longue");
});

test("sans clé dans l abonnement : pas la même (il faut se réabonner)", () => {
  faux(PN.memeCle(null, "AQIDBA"), "null");
  faux(PN.memeCle(undefined, "AQIDBA"), "undefined");
});

groupe("Push.champs() — ce que le serveur range");

test("l adresse et les deux clés d un abonnement", () => {
  const abonnement = { toJSON: () => ({ endpoint: "https://fcm.googleapis.com/fcm/send/x", keys: { p256dh: "P", auth: "A" } }) };
  egale({ endpoint: "https://fcm.googleapis.com/fcm/send/x", p256dh: "P", auth: "A" }, PN.champs(abonnement), "les trois pièces");
});

test("une pièce manquante : null, jamais un abonnement à moitié", () => {
  estNul(PN.champs({ toJSON: () => ({ endpoint: "https://x", keys: { p256dh: "P" } }) }), "pas de auth");
  estNul(PN.champs({ toJSON: () => ({ endpoint: "https://x" }) }), "pas de clés");
  estNul(PN.champs({ toJSON: () => ({ keys: { p256dh: "P", auth: "A" } }) }), "pas d adresse");
  estNul(PN.champs(null), "null");
  estNul(PN.champs({}), "sans toJSON");
});

groupe("Push.detecter() — ce que ce navigateur sait faire");

/** Une fausse fenêtre. */
const fenetre = (o = {}) => {
  const w = {
    navigator: Object.assign({ userAgent: "Mozilla/5.0 (Windows NT 10.0) Chrome/120", serviceWorker: {}, platform: "Win32", maxTouchPoints: 0 }, o.navigator),
    matchMedia: o.matchMedia || (() => ({ matches: false })),
  };
  if (o.pushManager !== false) w.PushManager = function () {};
  if (o.notification !== false) w.Notification = { permission: o.permission || "default" };
  if (o.navigator && o.navigator.serviceWorker === null) delete w.navigator.serviceWorker;
  return w;
};

test("un navigateur complet", () => {
  const e = PN.detecter(fenetre());
  vrai(e.serviceWorker && e.pushManager && e.notification, "les trois API");
  egale("default", e.permission, "l autorisation courante");
  faux(e.ios, "pas iOS");
});

test("l autorisation est celle de Notification.permission", () => {
  egale("granted", PN.detecter(fenetre({ permission: "granted" })).permission, "accordée");
  egale("denied", PN.detecter(fenetre({ permission: "denied" })).permission, "refusée");
});

test("sans l API Notification, l autorisation vaut « denied »", () => {
  const e = PN.detecter(fenetre({ notification: false }));
  faux(e.notification, "pas de Notification");
  egale("denied", e.permission, "rien à demander");
});

test("un iPhone, un iPad, et un iPad qui se dit Mac", () => {
  vrai(PN.detecter(fenetre({ navigator: { userAgent: "Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)" } })).ios, "iPhone");
  vrai(PN.detecter(fenetre({ navigator: { userAgent: "Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)" } })).ios, "iPad");
  vrai(PN.detecter(fenetre({ navigator: { userAgent: "Mozilla/5.0 (Macintosh)", platform: "MacIntel", maxTouchPoints: 5 } })).ios,
    "un iPad récent se dit Mac : seul l écran tactile le trahit");
  faux(PN.detecter(fenetre({ navigator: { userAgent: "Mozilla/5.0 (Macintosh)", platform: "MacIntel", maxTouchPoints: 0 } })).ios,
    "un vrai Mac n a pas d écran tactile");
});

test("installé sur l écran d accueil : navigator.standalone, ou le mode d affichage", () => {
  vrai(PN.detecter(fenetre({ navigator: { standalone: true } })).standalone, "navigator.standalone (Safari)");
  vrai(PN.detecter(fenetre({ matchMedia: (q) => ({ matches: q === "(display-mode: standalone)" }) })).standalone, "display-mode");
  faux(PN.detecter(fenetre()).standalone, "dans un onglet");
});

groupe("Push.diagnostic() — peut-on proposer les notifications, et sinon que dire ?");

test("tout est là : possible, sans message", () => {
  const d = PN.diagnostic(env());
  vrai(d.possible, "possible");
  egale("ok", d.code, "code");
  egale("", d.message, "rien à dire");
});

test("l autorisation « default » ou « granted » laisse faire", () => {
  vrai(PN.diagnostic(env({ permission: "granted" })).possible, "accordée");
  vrai(PN.diagnostic(env({ permission: "default" })).possible, "pas encore demandée");
});

test("autorisation refusée : impossible, et la phrase dit où la redonner", () => {
  const d = PN.diagnostic(env({ permission: "denied" }));
  faux(d.possible, "impossible");
  egale("refuse", d.code, "code");
  contient("réglages de votre navigateur", d.message, "où la redonner");
});

test("iPhone hors de l écran d accueil : la phrase dit d installer le site d abord", () => {
  /* Dans un onglet Safari, PushManager n existe pas. « Ce navigateur ne gère
     pas les notifications » serait faux — et sans issue. */
  const d = PN.diagnostic(env({ ios: true, standalone: false, pushManager: false, notification: false }));
  faux(d.possible, "impossible ici");
  egale("ios", d.code, "code");
  contient("écran d'accueil", d.message, "ajouter à l écran d accueil");
  contient("icône", d.message, "et l ouvrir depuis son icône");
});

test("iPhone installé mais sans les API : navigateur non pris en charge, pas la phrase de l écran d accueil", () => {
  const d = PN.diagnostic(env({ ios: true, standalone: true, pushManager: false }));
  egale("non-supporte", d.code, "installé : la consigne n aurait aucun sens");
});

test("un navigateur sans service worker, sans push ou sans notifications : non pris en charge", () => {
  for (const manque of ["serviceWorker", "pushManager", "notification"]) {
    const d = PN.diagnostic(env({ [manque]: false }));
    faux(d.possible, manque + " absent : impossible");
    egale("non-supporte", d.code, manque + " absent : code");
    contient("ne gère pas", d.message, manque + " absent : message");
  }
});

groupe("Push.messageErreur() — une erreur du navigateur, en phrase");

test("les erreurs connues ont chacune leur phrase", () => {
  contient("refusée", PN.messageErreur({ name: "NotAllowedError" }), "autorisation refusée");
  contient("ne gère pas", PN.messageErreur({ name: "NotSupportedError" }), "non pris en charge");
  contient("n'a pas répondu", PN.messageErreur({ name: "AbortError" }), "service muet");
  contient("n'a pas répondu", PN.messageErreur({ name: "NetworkError" }), "réseau");
  contient("ancien abonnement", PN.messageErreur({ name: "InvalidStateError" }), "ancien abonnement");
});

test("une erreur inconnue garde son message, sinon une phrase générale", () => {
  egale("Quelque chose a cassé", PN.messageErreur({ name: "Autre", message: "Quelque chose a cassé" }), "son propre message");
  contient("n'ont pas pu être activées", PN.messageErreur({ name: "Autre" }), "sans message");
  contient("n'ont pas pu être activées", PN.messageErreur(null), "null");
  contient("n'ont pas pu être activées", PN.messageErreur(undefined), "undefined");
});

groupe("Push.texteAppareils() — combien d appareils");

test("aucun, un, plusieurs", () => {
  egale("Aucun appareil enregistré.", PN.texteAppareils(0), "zéro");
  egale("1 appareil enregistré.", PN.texteAppareils(1), "un : au singulier");
  egale("3 appareils enregistrés.", PN.texteAppareils(3), "plusieurs : au pluriel");
  egale("Aucun appareil enregistré.", PN.texteAppareils(-2), "négatif");
  egale("Aucun appareil enregistré.", PN.texteAppareils(NaN), "pas un nombre");
});
