/* =========================================================
   Notifications push — la logique pure, côté navigateur.

   Chargé par parametres.php AVANT js/settings.js, qui le lit au
   chargement (comme js/cle-acces.js). Ce fichier ne branche rien sur la
   page : il convertit, il diagnostique, il traduit les erreurs. Tout cela se
   teste dans le banc (tests/js). Le branchement — le service worker,
   l'autorisation, l'abonnement — est dans settings.js, et se vérifie à la
   main : il demande un vrai navigateur et un vrai service de notification.
   ========================================================= */

window.Push = (() => {
  "use strict";

  /** base64url (la clé VAPID du site) → octets, tel que pushManager.subscribe() les attend. */
  function cleServeur(texte) {
    const b64 = String(texte).replace(/-/g, "+").replace(/_/g, "/");
    const brut = atob(b64 + "=".repeat((4 - (b64.length % 4)) % 4));
    const octets = new Uint8Array(brut.length);
    for (let i = 0; i < brut.length; i++) octets[i] = brut.charCodeAt(i);
    return octets;
  }

  /**
   * L'abonnement a-t-il été fait avec CETTE clé du site ? Un abonnement garde
   * la clé qu'il a vue : si le site a changé de paire VAPID depuis, le service
   * refuserait tous nos messages. Il faut alors s'abonner de nouveau.
   * `tampon` : abonnement.options.applicationServerKey (un ArrayBuffer, ou null).
   */
  function memeCle(tampon, texte) {
    if (!tampon) return false;
    const a = new Uint8Array(tampon);
    const b = cleServeur(texte);
    if (a.length !== b.length) return false;
    for (let i = 0; i < a.length; i++) if (a[i] !== b[i]) return false;
    return true;
  }

  /**
   * Ce que le serveur range : l'adresse et les deux clés de l'abonnement,
   * déjà en base64url dans abonnement.toJSON(). null si une pièce manque.
   */
  function champs(abonnement) {
    const j = abonnement && typeof abonnement.toJSON === "function" ? abonnement.toJSON() : null;
    const cles = (j && j.keys) || {};
    if (!j || !j.endpoint || !cles.p256dh || !cles.auth) return null;
    return { endpoint: j.endpoint, p256dh: cles.p256dh, auth: cles.auth };
  }

  /** Ce que ce navigateur sait faire — la seule partie qui lit window. `win` : la fenêtre (ou un faux, dans les tests). */
  function detecter(win) {
    const nav = win.navigator || {};
    const ua = nav.userAgent || "";
    // Un iPad récent se dit « Mac » : seul l'écran tactile le trahit.
    const ios = /iPad|iPhone|iPod/.test(ua) || (nav.platform === "MacIntel" && nav.maxTouchPoints > 1);
    const standalone = !!nav.standalone || !!(win.matchMedia && win.matchMedia("(display-mode: standalone)").matches);
    const notification = "Notification" in win;
    return {
      serviceWorker: "serviceWorker" in nav,
      pushManager: "PushManager" in win,
      notification,
      permission: notification ? win.Notification.permission : "denied",
      ios,
      standalone,
    };
  }

  /**
   * Peut-on proposer les notifications ici, et sinon, que dire ?
   *   possible : true si l'interrupteur peut servir ;
   *   code     : 'ok', 'ios' (à installer d'abord), 'refuse' ou 'non-supporte' ;
   *   message  : la phrase à montrer, vide si tout va bien.
   *
   * Sur iPhone et iPad, Safari ne propose les notifications qu'à un site
   * AJOUTÉ à l'écran d'accueil et ouvert depuis son icône : dans un onglet,
   * PushManager n'existe tout simplement pas. « Ce navigateur ne gère pas les
   * notifications » serait faux, et sans issue — d'où une phrase à part.
   */
  function diagnostic(env) {
    if (!env.serviceWorker || !env.pushManager || !env.notification) {
      if (env.ios && !env.standalone) {
        return {
          possible: false,
          code: "ios",
          message:
            "Sur iPhone et iPad, ajoutez d'abord ce site à l'écran d'accueil (bouton Partager, puis « Sur l'écran d'accueil »), puis ouvrez-le depuis son icône.",
        };
      }
      return { possible: false, code: "non-supporte", message: "Ce navigateur ne gère pas les notifications." };
    }
    if (env.permission === "denied") {
      return {
        possible: false,
        code: "refuse",
        message:
          "Les notifications sont bloquées pour ce site : autorisez-les dans les réglages de votre navigateur, puis rechargez la page.",
      };
    }
    return { possible: true, code: "ok", message: "" };
  }

  /** Une erreur du navigateur (DOMException) en phrase, sans jargon. */
  function messageErreur(err) {
    switch (err && err.name) {
      case "NotAllowedError":
        return "L'autorisation a été refusée. Vous pouvez la redonner dans les réglages de votre navigateur.";
      case "NotSupportedError":
        return "Ce navigateur ne gère pas les notifications.";
      case "AbortError":
      case "NetworkError":
        return "Le service de notification du navigateur n'a pas répondu. Réessayez dans un instant.";
      case "InvalidStateError":
        return "Ce navigateur garde un ancien abonnement. Réessayez.";
      default:
        return (err && err.message) || "Les notifications n'ont pas pu être activées.";
    }
  }

  /** « 2 appareils enregistrés. » */
  function texteAppareils(n) {
    if (!(n > 0)) return "Aucun appareil enregistré.";
    return n + " appareil" + (n > 1 ? "s" : "") + " enregistré" + (n > 1 ? "s" : "") + ".";
  }

  return { cleServeur, memeCle, champs, detecter, diagnostic, messageErreur, texteAppareils };
})();
