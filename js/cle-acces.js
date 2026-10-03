/* =========================================================
   Clés d'accès (WebAuthn) — la part qui parle au navigateur.

   Deux morceaux :
     - window.CleAcces : des fonctions PURES (base64url, conversion des
       options du serveur vers ce que navigator.credentials attend, et de
       la réponse vers les champs d'un POST). Testées dans le banc
       (tests/js/cas/cle_acces_test.js), chargées par la page de connexion
       ET par les Paramètres ;
     - la page de connexion : le bouton « Se connecter avec une clé
       d'accès », et la proposition des clés dans le champ identifiant
       (« conditional mediation » : le gestionnaire de mots de passe liste
       les clés du site quand on touche le champ). N'agit que si le bouton
       #btn-cle-acces est dans la page.

   Le serveur reste maître : il tire le défi, vérifie la signature,
   l'origine et l'adresse du site (includes/cle_acces.php). Rien ici
   n'ouvre une session.
   ========================================================= */

window.CleAcces = (() => {
  "use strict";

  /** base64url (sans remplissage) → octets. null si le texte n'en est pas. */
  function versOctets(texte) {
    if (typeof texte !== "string" || !/^[A-Za-z0-9_-]*$/.test(texte)) return null;
    const b64 = texte.replace(/-/g, "+").replace(/_/g, "/") + "=".repeat((4 - (texte.length % 4)) % 4);
    let binaire;
    try {
      binaire = atob(b64);
    } catch (e) {
      return null;
    }
    const octets = new Uint8Array(binaire.length);
    for (let i = 0; i < binaire.length; i++) octets[i] = binaire.charCodeAt(i);
    return octets;
  }

  /** ArrayBuffer ou tableau typé → base64url sans remplissage. */
  function versTexte(tampon) {
    const octets = tampon instanceof ArrayBuffer ? new Uint8Array(tampon)
      : new Uint8Array(tampon.buffer, tampon.byteOffset, tampon.byteLength);
    let binaire = "";
    for (let i = 0; i < octets.length; i++) binaire += String.fromCharCode(octets[i]);
    return btoa(binaire).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
  }

  /** Une valeur base64url du serveur → les octets, ou une erreur claire. */
  function exiger(texte, quoi) {
    const octets = versOctets(texte);
    if (!octets) throw new Error("Réponse du serveur illisible (" + quoi + ").");
    return octets;
  }

  /** Les options de CRÉATION du serveur → l'argument « publicKey » de credentials.create(). */
  function optionsCreation(o) {
    return {
      challenge: exiger(o.challenge, "défi"),
      rp: o.rp,
      user: { id: exiger(o.user.id, "compte"), name: o.user.name, displayName: o.user.displayName },
      pubKeyCredParams: o.pubKeyCredParams,
      timeout: o.timeout,
      attestation: o.attestation,
      authenticatorSelection: o.authenticatorSelection,
      excludeCredentials: (o.excludeCredentials || []).map((c) => ({
        type: c.type, id: exiger(c.id, "clé existante"),
      })),
    };
  }

  /** Les options de CONNEXION du serveur → l'argument « publicKey » de credentials.get(). */
  function optionsConnexion(o) {
    return {
      challenge: exiger(o.challenge, "défi"),
      rpId: o.rpId,
      timeout: o.timeout,
      userVerification: o.userVerification,
    };
  }

  /** La clé fabriquée → les champs du POST « cle.creer ». */
  function champsCreation(credential) {
    return {
      client_data: versTexte(credential.response.clientDataJSON),
      attestation: versTexte(credential.response.attestationObject),
    };
  }

  /** La signature rendue → les champs du POST de connexion. */
  function champsConnexion(credential) {
    const r = credential.response;
    return {
      identifiant: versTexte(credential.rawId),
      client_data: versTexte(r.clientDataJSON),
      authenticator: versTexte(r.authenticatorData),
      signature: versTexte(r.signature),
      utilisateur: r.userHandle && r.userHandle.byteLength ? versTexte(r.userHandle) : "",
    };
  }

  /** Ce navigateur sait-il parler à un gestionnaire de clés d'accès ? */
  function disponible() {
    return !!(window.PublicKeyCredential && navigator.credentials
      && typeof navigator.credentials.create === "function"
      && typeof navigator.credentials.get === "function");
  }

  /**
   * Le message pour une erreur du navigateur. « annulee » : la personne a
   * fermé la fenêtre du gestionnaire (ou le délai a passé) — le navigateur
   * ne distingue pas les deux, et ne dit pas non plus s'il n'avait aucune clé.
   * Rend "" quand il n'y a rien à dire (appel interrompu par nous-mêmes).
   */
  function messageErreur(err, usage) {
    const nom = err && err.name;
    if (nom === "AbortError") return "";
    if (nom === "InvalidStateError" && usage === "creation") {
      return "Cette clé d'accès est déjà enregistrée sur ce compte.";
    }
    if (nom === "NotAllowedError") {
      return usage === "creation"
        ? "L'ajout a été annulé, ou le délai est dépassé. Réessayez."
        : "Aucune clé d'accès n'a été utilisée. Réessayez, ou connectez-vous avec votre mot de passe.";
    }
    if (nom === "SecurityError") return "Ce navigateur refuse les clés d'accès sur cette adresse.";
    if (nom === "NotSupportedError") return "Votre appareil ne prend pas en charge ce type de clé d'accès.";
    // Une erreur de NOTRE code ou du serveur (un « Error » ordinaire) porte déjà son message.
    if (err && err.message && (!nom || nom === "Error")) return err.message;
    return "La clé d'accès n'a pas pu être utilisée.";
  }

  return {
    versOctets, versTexte, optionsCreation, optionsConnexion,
    champsCreation, champsConnexion, disponible, messageErreur,
  };
})();

/* =========================================================
   Page de connexion
   ========================================================= */
(() => {
  "use strict";
  const $bouton = document.getElementById("btn-cle-acces");
  if (!$bouton || !window.CleAcces.disponible()) return;   // sans clés d'accès : la page reste telle quelle

  const C = window.CleAcces;
  const $erreur = document.getElementById("btn-cle-acces-erreur");
  const $identifiant = document.getElementById("identifiant");
  $bouton.classList.remove("hidden");

  let options = null;        // les options en cours, avec leur défi (valable tant qu'il n'a pas servi)
  let conditionnel = null;   // l'AbortController de la demande « dans le champ identifiant »

  function dire(message) {
    $erreur.textContent = message;
    $erreur.classList.toggle("hidden", message === "");
  }

  /** Un appel à connexion-cle.php. Les erreurs du serveur lèvent une Error portant son message. */
  async function appeler(etape, champs) {
    const fd = new FormData();
    fd.set("etape", etape);
    fd.set("csrf", document.body.dataset.csrf || "");
    Object.entries(champs || {}).forEach(([k, v]) => fd.set(k, v));
    const res = await fetch("connexion-cle.php", { method: "POST", body: fd, credentials: "same-origin" });
    let data;
    try {
      data = await res.json();
    } catch (e) {
      throw new Error("Réponse inattendue du serveur.");
    }
    if (!res.ok || !data.ok) {
      const erreur = new Error(data.erreur || "Une erreur est survenue.");
      erreur.renouveler = !!data.renouveler;
      throw erreur;
    }
    return data;
  }

  async function preparer() {
    const r = await appeler("options");
    options = C.optionsConnexion(r.options);
  }

  /** Envoie la signature. Le défi est consommé quoi qu'il arrive : on en reprend un après. */
  async function terminer(credential) {
    options = null;
    const r = await appeler("verifier", C.champsConnexion(credential));
    window.location.replace(r.redirection || "index.php");
  }

  /** La clé s'offre dans la liste du champ identifiant (quand le navigateur sait faire). */
  async function proposerDansLeChamp() {
    if (!$identifiant || conditionnel || !window.PublicKeyCredential
      || typeof PublicKeyCredential.isConditionalMediationAvailable !== "function") return;
    try {
      if (!(await PublicKeyCredential.isConditionalMediationAvailable())) return;
      if (!options) await preparer();
    } catch (e) {
      return;   // pas de proposition dans le champ : le bouton reste là
    }
    const demande = new AbortController();
    conditionnel = demande;
    let credential;
    try {
      credential = await navigator.credentials.get({
        publicKey: options, mediation: "conditional", signal: demande.signal,
      });
    } catch (err) {
      /* Personne n'a rien demandé : la liste a été fermée, le bouton a pris
         la main, ou le navigateur n'a pas de clé à proposer. Silence — et
         surtout aucune relance, qui bouclerait si l'échec est immédiat. */
      if (conditionnel === demande) conditionnel = null;
      return;
    }
    conditionnel = null;
    if (!credential) return;
    try {
      await terminer(credential);
    } catch (err) {
      /* Ici une clé a été CHOISIE puis refusée par le serveur : cela se dit. */
      dire(err.message);
      options = null;
      relancer();
    }
  }

  /** Reprend la proposition dans le champ avec un défi neuf. */
  function relancer() {
    preparer().then(proposerDansLeChamp).catch(() => {});
  }

  $bouton.addEventListener("click", async () => {
    dire("");
    if (conditionnel) {
      conditionnel.abort();
      conditionnel = null;
    }
    try {
      /* Le défi est déjà prêt (chargé avec la page) : la demande part tout
         de suite, dans le geste du clic — certains navigateurs l'exigent. */
      if (!options) await preparer();
      /* Si la personne ferme la fenêtre du gestionnaire, rien n'est parti au
         serveur : `options` garde son défi, qui resservira. */
      const credential = await navigator.credentials.get({ publicKey: options });
      await terminer(credential);
    } catch (err) {
      dire(C.messageErreur(err, "connexion"));
      if (err && err.renouveler) options = null;
      if (!options) relancer(); else proposerDansLeChamp();
    }
  });

  relancer();
})();
