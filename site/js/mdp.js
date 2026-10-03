/* =========================================================
   Règles du mot de passe, dites pendant la saisie.

   La politique n'est plus affichée d'avance sous le champ : seules les
   règles NON respectées apparaissent, et chacune disparaît dès qu'elle
   l'est. C'est le miroir de valider_mot_de_passe() (includes/
   fonctions.php) — mêmes règles, mêmes messages. Le serveur reste seul
   juge : ceci ne fait que prévenir plus tôt.

   Branché sur tout champ portant data-regles-mdp :
     data-mdp-min / data-mdp-max   les bornes du .env
     data-identifiant              l'id du champ identifiant, ou
     data-identifiant-valeur       l'identifiant lui-même (compte connu)
   ========================================================= */

window.ReglesMdp = (() => {
  "use strict";

  const OBLIGATOIRE = "Ce champ est obligatoire.";

  /** Les règles que `mdp` ne respecte pas, dans l'ordre du serveur. */
  function manques(mdp, { min = 8, max = 200, identifiant = "" } = {}) {
    if (mdp === "") return [OBLIGATOIRE];
    const m = [];
    // Les espaces (y compris insécables, \p{Z}) ne comptent pas dans la longueur.
    const utiles = [...mdp.replace(/[\p{Z}\s]/gu, "")].length;
    if (utiles < min) m.push("Le mot de passe doit faire au moins " + min + " caractères, espaces non comptés.");
    if ([...mdp].length > max) m.push("Le mot de passe est trop long (" + max + " caractères maximum).");
    if (/^[\p{Z}\s]|[\p{Z}\s]$/u.test(mdp)) m.push("Le mot de passe ne doit pas commencer ni se terminer par un espace.");
    if (!/\p{Lu}/u.test(mdp)) m.push("Le mot de passe doit contenir au moins une majuscule.");
    if (!/\p{Ll}/u.test(mdp)) m.push("Le mot de passe doit contenir au moins une minuscule.");
    if (!/\p{Nd}/u.test(mdp)) m.push("Le mot de passe doit contenir au moins un chiffre.");
    if (!/[^\p{L}\p{N}\p{Z}\s]/u.test(mdp)) {
      m.push("Le mot de passe doit contenir au moins un caractère spécial (par exemple ! ? * - _ #) — l'espace ne compte pas.");
    }
    const id = String(identifiant || "").trim();
    if ([...id].length >= 3 && mdp.toLocaleLowerCase().includes(id.toLocaleLowerCase())) {
      m.push("Le mot de passe ne doit pas contenir votre identifiant.");
    }
    return m;
  }

  /** Pose (ou retire) la liste sous le champ, avec la convention <id>-erreur. */
  function afficher(champ, lignes) {
    const id = champ.id + "-erreur";
    let p = document.getElementById(id);
    if (!lignes.length) {
      if (p) p.remove();
      champ.removeAttribute("aria-invalid");
      const reste = (champ.getAttribute("aria-describedby") || "").split(/\s+/).filter((x) => x && x !== id);
      if (reste.length) champ.setAttribute("aria-describedby", reste.join(" "));
      else champ.removeAttribute("aria-describedby");
      return;
    }
    if (!p) {
      p = document.createElement("p");
      p.className = "erreur-champ";
      p.id = id;
      (champ.closest(".password-wrap") || champ).after(p);
    }
    p.replaceChildren();
    lignes.forEach((l, i) => {
      if (i) p.appendChild(document.createElement("br"));
      p.appendChild(document.createTextNode(l));
    });
    champ.setAttribute("aria-invalid", "true");
    const decrit = (champ.getAttribute("aria-describedby") || "").split(/\s+/).filter(Boolean);
    if (!decrit.includes(id)) champ.setAttribute("aria-describedby", [id, ...decrit].join(" "));
  }

  function options(champ) {
    const d = champ.dataset;
    const source = d.identifiant ? document.getElementById(d.identifiant) : null;
    return {
      min: parseInt(d.mdpMin, 10) || 8,
      max: parseInt(d.mdpMax, 10) || 200,
      identifiant: source ? source.value : d.identifiantValeur || "",
    };
  }

  /**
   * Quand dire les règles :
   *   - en quittant le champ, s'il n'est pas vide (vide, c'est l'envoi
   *     qui dira « Ce champ est obligatoire ») ;
   *   - pendant la frappe, dès qu'un message est affiché — y compris
   *     celui posé par le serveur — pour qu'il se vide règle après règle.
   * Jamais à la première lettre : afficher cinq règles à quelqu'un qui
   * commence à taper serait exactement le mur que l'on vient de retirer.
   */
  function brancher(champ) {
    const verifier = () => afficher(champ, champ.value === "" ? [] : manques(champ.value, options(champ)));
    /* Une erreur posée par le serveur compte comme « déjà vue ». Il faut
       le noter maintenant : à la première frappe, l'effacement générique
       (js/auth.js, js/commun.js, en phase de capture) la retire avant que
       ce champ n'entende l'évènement. */
    if (champ.getAttribute("aria-invalid") === "true") champ.dataset.reglesVues = "1";
    champ.addEventListener("blur", () => {
      if (champ.value !== "") champ.dataset.reglesVues = "1";
      verifier();
    });
    champ.addEventListener("input", () => {
      if (champ.dataset.reglesVues === "1") verifier();
    });
  }

  document.querySelectorAll("input[data-regles-mdp]").forEach(brancher);

  return { manques, brancher, OBLIGATOIRE };
})();
