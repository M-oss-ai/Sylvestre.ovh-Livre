/* =====================================================================
   js/cle-acces.js — la logique pure des clés d'accès (window.CleAcces) :
   base64url, conversion des options du serveur vers ce que le navigateur
   attend, et de sa réponse vers les champs d'un POST.
   Le branchement sur la page de connexion (bouton, proposition dans le
   champ identifiant) ne se teste pas ici : il se vérifie à la main.
   ===================================================================== */

const CA = window.CleAcces;

/** Des octets à partir de nombres : octets(1, 2, 255). */
const octets = (...n) => Uint8Array.from(n);

/** Un ArrayBuffer de la même suite d'octets (ce que renvoie le navigateur). */
const tampon = (...n) => octets(...n).buffer;

/** Deux suites d'octets sont-elles identiques ? */
const memeOctets = (a, b) => a && b && a.length === b.length && a.every((v, i) => v === b[i]);

groupe("CleAcces.versTexte() et versOctets() — base64url");

test("un aller-retour rend les mêmes octets, sur toutes les longueurs", () => {
  for (let n = 0; n <= 70; n++) {
    const source = Uint8Array.from({ length: n }, (_, i) => (i * 37 + 11) % 256);
    const texte = CA.versTexte(source);
    vrai(memeOctets(CA.versOctets(texte), source), "longueur " + n + " : aller-retour");
  }
});

test("l alphabet est celui d une URL, sans remplissage", () => {
  const texte = CA.versTexte(octets(0xfb, 0xff, 0xfe));   // « +//+ » en base64 classique
  egale("-__-", texte, "- et _ à la place de + et /");
  sans("=", CA.versTexte(octets(1)), "pas de remplissage");
  sans("+", CA.versTexte(octets(0xfb, 0xef)), "pas de +");
});

test("versTexte accepte un ArrayBuffer comme un tableau typé, et une vue décalée", () => {
  egale("AQID", CA.versTexte(tampon(1, 2, 3)), "un ArrayBuffer");
  egale("AQID", CA.versTexte(octets(1, 2, 3)), "un tableau typé");
  const grand = octets(9, 9, 1, 2, 3, 9);
  egale("AQID", CA.versTexte(new Uint8Array(grand.buffer, 2, 3)), "une vue sur une partie d un tampon");
});

test("un texte qui n est pas du base64url est refusé", () => {
  estNul(CA.versOctets("ab+c"), "le + n est pas de l URL");
  estNul(CA.versOctets("ab/c"), "le / non plus");
  estNul(CA.versOctets("ab=="), "le remplissage n existe pas ici");
  estNul(CA.versOctets("a b"), "un espace");
  estNul(CA.versOctets(null), "null");
  estNul(CA.versOctets(12), "un nombre");
  estNul(CA.versOctets("a"), "une longueur impossible (un seul caractère)");
  vrai(CA.versOctets("").length === 0, "le texte vide donne zéro octet");
});

groupe("CleAcces.optionsCreation() — du serveur vers credentials.create()");

const optionsServeur = () => ({
  challenge: "AQID",
  rp: { name: "Ma Bibliothèque Manga", id: "livre.exemple.test" },
  user: { id: "BAUG", name: "lecteur", displayName: "lecteur" },
  pubKeyCredParams: [{ type: "public-key", alg: -7 }, { type: "public-key", alg: -257 }],
  timeout: 120000,
  attestation: "none",
  excludeCredentials: [{ type: "public-key", id: "BwgJ" }],
  authenticatorSelection: { residentKey: "required", requireResidentKey: true, userVerification: "preferred" },
});

test("le défi, l identifiant du compte et les clés exclues deviennent des octets", () => {
  const o = CA.optionsCreation(optionsServeur());
  vrai(o.challenge instanceof Uint8Array && memeOctets(o.challenge, octets(1, 2, 3)), "le défi");
  vrai(memeOctets(o.user.id, octets(4, 5, 6)), "l identifiant du compte");
  vrai(memeOctets(o.excludeCredentials[0].id, octets(7, 8, 9)), "la clé déjà enregistrée");
  egale("public-key", o.excludeCredentials[0].type, "son type est gardé");
});

test("le reste passe tel quel", () => {
  const o = CA.optionsCreation(optionsServeur());
  egale("livre.exemple.test", o.rp.id, "l adresse du site");
  egale("lecteur", o.user.name, "le nom");
  egale("none", o.attestation, "pas d attestation");
  egale("required", o.authenticatorSelection.residentKey, "clé retrouvable sans identifiant");
  egale(2, o.pubKeyCredParams.length, "les deux algorithmes");
  egale(120000, o.timeout, "le délai");
});

test("sans clé à exclure, la liste est vide", () => {
  const brut = optionsServeur();
  delete brut.excludeCredentials;
  egale(0, CA.optionsCreation(brut).excludeCredentials.length, "liste vide");
});

test("une valeur illisible du serveur est une erreur claire, pas une demande corrompue", () => {
  const brut = optionsServeur();
  brut.challenge = "pas du base64url !";
  let erreur = null;
  try { CA.optionsCreation(brut); } catch (e) { erreur = e; }
  vrai(erreur && /illisible/.test(erreur.message), "l erreur le dit");
});

groupe("CleAcces.optionsConnexion() — du serveur vers credentials.get()");

test("le défi devient des octets, le reste passe", () => {
  const o = CA.optionsConnexion({ challenge: "AQID", rpId: "livre.exemple.test", timeout: 120000, userVerification: "preferred" });
  vrai(memeOctets(o.challenge, octets(1, 2, 3)), "le défi");
  egale("livre.exemple.test", o.rpId, "l adresse du site");
  egale("preferred", o.userVerification, "la vérification laissée au gestionnaire");
  faux("allowCredentials" in o, "aucune liste : le gestionnaire propose les clés du site");
});

groupe("CleAcces.champsCreation() et champsConnexion() — vers le POST");

test("la création envoie le message du navigateur et l objet d attestation", () => {
  const c = CA.champsCreation({ response: { clientDataJSON: tampon(1, 2, 3), attestationObject: tampon(4, 5, 6) } });
  egale("AQID", c.client_data, "client_data");
  egale("BAUG", c.attestation, "attestation");
  egale(2, Object.keys(c).length, "rien d autre");
});

test("la connexion envoie l identifiant de la clé, la signature et l identifiant d utilisateur", () => {
  const c = CA.champsConnexion({
    rawId: tampon(1, 2, 3),
    response: {
      clientDataJSON: tampon(4, 5, 6), authenticatorData: tampon(7, 8, 9),
      signature: tampon(10, 11, 12), userHandle: tampon(13, 14, 15),
    },
  });
  egale("AQID", c.identifiant, "l identifiant de la clé (rawId)");
  egale("BAUG", c.client_data, "client_data");
  egale("BwgJ", c.authenticator, "authenticator");
  egale("CgsM", c.signature, "signature");
  egale("DQ4P", c.utilisateur, "l identifiant d utilisateur");
});

test("un identifiant d utilisateur absent ou vide donne un champ vide", () => {
  const base = { rawId: tampon(1), response: { clientDataJSON: tampon(1), authenticatorData: tampon(1), signature: tampon(1) } };
  egale("", CA.champsConnexion({ ...base, response: { ...base.response, userHandle: null } }).utilisateur, "null");
  egale("", CA.champsConnexion(base).utilisateur, "absent");
  egale("", CA.champsConnexion({ ...base, response: { ...base.response, userHandle: new ArrayBuffer(0) } }).utilisateur, "vide");
});

groupe("CleAcces.messageErreur() — dire ce qui s est passé, sans jargon");

const erreurNavigateur = (nom, message = "") => ({ name: nom, message });

test("fermer la fenêtre du gestionnaire n est pas une panne", () => {
  contient("annulé", CA.messageErreur(erreurNavigateur("NotAllowedError"), "creation"), "à l ajout");
  contient("mot de passe", CA.messageErreur(erreurNavigateur("NotAllowedError"), "connexion"), "à la connexion : le recours est dit");
});

test("une clé déjà présente se dit à l ajout seulement", () => {
  contient("déjà enregistrée", CA.messageErreur(erreurNavigateur("InvalidStateError"), "creation"), "à l ajout");
  sans("déjà enregistrée", CA.messageErreur(erreurNavigateur("InvalidStateError"), "connexion"), "pas à la connexion");
});

test("un appel que nous avons interrompu ne dit rien", () => {
  egale("", CA.messageErreur(erreurNavigateur("AbortError"), "connexion"), "silence");
});

test("une erreur du serveur garde son message", () => {
  egale("Trop de tentatives.", CA.messageErreur(new Error("Trop de tentatives."), "connexion"), "le message du serveur");
  egale("La demande a expiré.", CA.messageErreur({ message: "La demande a expiré." }, "creation"), "sans nom");
});

test("tout le reste a un message de repli", () => {
  contient("pas pu être utilisée", CA.messageErreur(erreurNavigateur("UnknownError"), "connexion"), "inconnue");
  contient("pas pu être utilisée", CA.messageErreur(null, "connexion"), "pas d erreur du tout");
  contient("adresse", CA.messageErreur(erreurNavigateur("SecurityError"), "creation"), "site refusé");
  contient("appareil", CA.messageErreur(erreurNavigateur("NotSupportedError"), "creation"), "appareil sans prise en charge");
});

groupe("CleAcces.disponible()");

test("renvoie un booléen, vrai quand le navigateur sait parler aux clés d accès", () => {
  egale("boolean", typeof CA.disponible(), "un booléen");
  egale(!!window.PublicKeyCredential && !!navigator.credentials, CA.disponible(), "cohérent avec le navigateur");
});
