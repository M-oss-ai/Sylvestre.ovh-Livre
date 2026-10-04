/* =====================================================================
   Service worker : il ne fait QU'UNE chose, recevoir les notifications
   push et les afficher. Aucun cache, aucun mode hors ligne, aucune
   interception des requêtes : le site reste exactement ce qu'il est.

   Il vit à la racine du site, et non dans js/ : la portée d'un service
   worker est son dossier, et celle-ci doit couvrir index.php, parametres.php…
   Il n'a donc pas non plus d'empreinte dans son adresse (actif()) : le
   navigateur le revérifie de lui-même à chaque visite, et sw.js est servi
   sans cache (voir .htaccess).

   Le serveur envoie un JSON chiffré (includes/push.php) :
     { titre, corps, url, tag }
   Tout est du TEXTE : showNotification() n'interprète aucun HTML.
   ===================================================================== */

"use strict";

// Prend la main tout de suite : sans cela, une première visite ne recevrait
// rien avant le rechargement suivant.
self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", (e) => e.waitUntil(self.clients.claim()));

self.addEventListener("push", (event) => {
  let m = {};
  try {
    m = event.data ? event.data.json() : {};
  } catch (e) {
    m = {};
  }
  if (!m || typeof m !== "object") m = {};

  /* Un navigateur exige qu'un message push montre TOUJOURS une notification
     (userVisibleOnly) : sans titre lisible, il en affiche une générique de son
     cru, ce qui est pire. D'où ces valeurs de repli. */
  const titre = typeof m.titre === "string" && m.titre ? m.titre : "Ma Bibliothèque";
  const tag = typeof m.tag === "string" && m.tag ? m.tag : "bibliotheque";

  event.waitUntil(
    self.registration.showNotification(titre, {
      body: typeof m.corps === "string" ? m.corps : "",
      // Le même tag remplace la notification précédente, qui reste sinon à l'écran ; renotify la refait sonner.
      tag,
      renotify: true,
      icon: "img/icone-192.png",
      badge: "img/icone-192.png",
      data: { url: typeof m.url === "string" ? m.url : "index.php" },
    })
  );
});

/**
 * Où mène le clic : une adresse DU SITE, relative à la portée du worker. Toute
 * autre (autre origine, schéma étranger) retombe sur la bibliothèque : le
 * contenu du message vient du serveur, mais une adresse qu'on ouvre mérite le
 * même soin que celles d'une page.
 */
function adresseDuClic(url) {
  const accueil = new URL("index.php", self.registration.scope);
  if (typeof url !== "string" || url === "") return accueil.href;
  try {
    const cible = new URL(String(url || ""), self.registration.scope);
    return cible.origin === accueil.origin && cible.href.startsWith(self.registration.scope) ? cible.href : accueil.href;
  } catch (e) {
    return accueil.href;
  }
}

/** La fenêtre du site déjà ouverte, s'il y en a une : on y va plutôt que d'ouvrir un onglet de plus. */
function fenetreDuSite(fenetres, portee) {
  return fenetres.find((f) => typeof f.url === "string" && f.url.startsWith(portee)) || null;
}

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const cible = adresseDuClic(event.notification.data && event.notification.data.url);

  event.waitUntil(
    (async () => {
      const fenetres = await self.clients.matchAll({ type: "window", includeUncontrolled: true });
      const f = fenetreDuSite(fenetres, self.registration.scope);
      if (!f) return self.clients.openWindow(cible);
      try {
        if (f.url !== cible && "navigate" in f) await f.navigate(cible);
      } catch (e) {
        /* Une fenêtre qu'on ne peut pas diriger : on la montre telle quelle. */
      }
      return "focus" in f ? f.focus() : undefined;
    })()
  );
});
