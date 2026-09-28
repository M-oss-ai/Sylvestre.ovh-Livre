/* =========================================================
   Page Paramètres (parametres.php)

   Les formulaires fonctionnent déjà sans ce fichier (POST
   classique vers parametres.php). Le JavaScript ne fait
   qu'éviter le rechargement de page : s'il ne se charge pas,
   rien n'est cassé.
   ========================================================= */

/* ---------------- Logique pure ----------------
   Testée par tests/js, comme window.Bibliotheque dans app.js. */

window.Parametres = (() => {
  "use strict";

  /**
   * Ce que la suppression du compte emporte, dit AVANT de confirmer :
   * le nombre de séries, et pour l'illimité, que le forfait ne revient
   * pas avec un nouveau compte.
   */
  function texteSuppressionCompte(nbSeries, forfait) {
    const series = nbSeries > 0
      ? (nbSeries > 1 ? "vos " + nbSeries + " séries" : "votre série")
      : "votre bibliothèque (vide)";
    let texte = "Votre compte, votre profil et " + series + " seront définitivement effacés.";
    if (forfait === "illimite") {
      texte += " Le forfait illimité est attaché à ce compte : il ne reviendra pas si vous en créez un nouveau.";
    }
    return texte + " Cette action est irréversible.";
  }

  return { texteSuppressionCompte };
})();

(() => {
  "use strict";

  const L = window.Lib;
  const P = window.Parametres;

  // Hors de la page Paramètres — le banc de tests charge ce fichier pour
  // sa logique pure : rien à brancher.
  if (!document.getElementById("settings-dropzone")) return;

  L.brancherToggleMotDePasse();

  const PAR_GOOGLE = document.body.dataset.parGoogle === "1";

  /* Sans JavaScript, la preuve d'identité (bouton Google, ou mot de passe
     retapé) se fait EN PLACE, dans le Profil ou la Sécurité — c'est ce qui
     fait marcher ces deux cartes sans rien charger. Avec JavaScript, elle
     passe par la fenêtre de confirmation, ouverte au clic sur « Enregistrer »
     (ou « Changer le mot de passe »), pareille pour les deux comptes : ces
     blocs n'ont donc plus lieu d'être visibles, on les cache. */
  document.getElementById("email-password-field").classList.add("hidden");
  if (PAR_GOOGLE) {
    const banniereSecurite = document.getElementById("securite-google-banner");
    if (banniereSecurite) banniereSecurite.classList.add("hidden");
  } else {
    const champActuel = document.getElementById("mdp-actuel-field");
    if (champActuel) champActuel.classList.add("hidden");
  }

  /* ---------------- Photo de profil ---------------- */

  const $dropzone = document.getElementById("settings-dropzone");
  const $preview = document.getElementById("settings-avatar-preview");
  const $fallback = document.getElementById("settings-avatar-fallback");
  const $urlInput = document.getElementById("a-image-url");
  const $fileInput = document.getElementById("a-image-file");
  const $photoRemoved = document.getElementById("a-photo-removed");
  const $photoStatus = document.getElementById("photo-status");

  const photoInitiale = $preview.getAttribute("src") || "";
  let photoEnAttente = photoInitiale ? { type: "url", value: photoInitiale, existante: true } : null;

  function majApercuPhoto() {
    // Une adresse refusée (http://…) n'a pas d'aperçu : il serait cassé.
    const src = photoEnAttente && !photoEnAttente.refusee
      ? photoEnAttente.type === "file"
        ? photoEnAttente.apercu
        : photoEnAttente.value
      : "";
    if (src) {
      $preview.src = src;
      $preview.classList.remove("hidden");
      $fallback.classList.add("hidden");
    } else {
      $preview.classList.add("hidden");
      $preview.removeAttribute("src");
      $fallback.classList.remove("hidden");
    }
    // Reflété dans un champ du formulaire : le POST sans JavaScript doit
    // savoir, lui aussi, que la photo a été retirée.
    $photoRemoved.value = photoEnAttente ? "0" : "1";
  }

  L.wireImagePicker({
    dropzone: $dropzone,
    urlInput: $urlInput,
    fileInput: $fileInput,
    statusEl: $photoStatus,
    onChange: (photo) => {
      photoEnAttente = photo;
      majApercuPhoto();
    },
  });

  document.getElementById("remove-photo").addEventListener("click", () => {
    photoEnAttente = null;
    $urlInput.value = "";
    L.effacerErreur($urlInput);
    $fileInput.value = "";
    $photoStatus.textContent = "Photo retirée (pensez à enregistrer).";
    majApercuPhoto();
  });

  /* ---------------- Profil ----------------
     Changer l'identifiant ou l'adresse est une action à risque : elle passe
     par la fenêtre de confirmation (demanderConfirmation, plus bas), pareille
     pour un compte e-mail et un compte Google. Le reste du profil (photo)
     n'en a pas besoin, et s'enregistre directement. */

  const $profilForm = document.getElementById("profil-form");
  const $emailInput = document.getElementById("a-email");
  const $usernameInput = document.getElementById("a-username");
  const $emailPwd = document.getElementById("a-email-password");
  const $profilErreur = document.getElementById("profil-erreur");
  const $emailAttente = document.getElementById("email-attente");

  /* La comparaison se fait avec les valeurs ENREGISTRÉES (data-compte),
     pas avec celles du champ au chargement : après un envoi refusé, le
     champ réaffiche la saisie. */
  function profilChange() {
    const emailChange = $emailInput.value.trim().toLowerCase()
      !== ($emailInput.dataset.compte || "").trim().toLowerCase();
    const usernameChange = $usernameInput.value.trim() !== ($usernameInput.dataset.compte || "").trim();
    return emailChange || usernameChange;
  }

  function erreurProfil(message) {
    $profilErreur.textContent = message;
    $profilErreur.classList.remove("hidden");
    $profilErreur.focus();
  }

  function effacerErreursProfil() {
    $profilErreur.classList.add("hidden");
    $profilErreur.textContent = "";
    L.effacerErreurs($profilForm);
    L.effacerErreur($urlInput); // rattaché au formulaire, mais placé hors de lui
    $photoStatus.classList.remove("erreur");
  }

  /** Ce que le Profil envoie : ses champs, et la photo choisie. */
  function donneesProfil() {
    const fd = new FormData($profilForm);

    // FormData(form) reprend tous les champs nommés, y compris ceux
    // rattachés par l'attribut « form » (l'URL et le fichier de la photo).
    if (photoEnAttente && photoEnAttente.type === "file") {
      fd.set("photo_fichier", photoEnAttente.file);
      fd.delete("photo_url");
    } else if (photoEnAttente && photoEnAttente.type === "url" && !photoEnAttente.existante) {
      fd.set("photo_url", photoEnAttente.value);
    }
    return fd;
  }

  /** Le Profil vient d'être enregistré : la page reprend ce qui compte. */
  function profilEnregistre(r) {
    photoEnAttente = r.photo ? { type: "url", value: r.photo, existante: true } : null;
    $fallback.textContent = r.initiales || "🙂";
    majApercuPhoto();
    $photoStatus.textContent = "";
    $emailPwd.value = "";

    /* Le champ reprend l'adresse ACTIVE : il gardait la nouvelle, pas
       encore confirmée, et l'on ne savait plus laquelle comptait. La
       demande en cours s'affiche juste dessous, à part. */
    $emailInput.value = r.email;
    $emailInput.dataset.compte = r.email;
    $usernameInput.value = r.identifiant;
    $usernameInput.dataset.compte = r.identifiant;
    if (r.email_attente) {
      document.getElementById("email-attente-adresse").textContent = r.email_attente.adresse;
      document.getElementById("email-attente-expire").textContent = r.email_attente.expire;
      $emailAttente.classList.remove("hidden");
    }
    fermerConfirmation();
    L.toast(r.message);
  }

  /** Enregistre le Profil. Appelé directement (photo seule), ou depuis la
      fenêtre de confirmation quand l'identifiant ou l'adresse change. */
  async function finirProfil() {
    const bouton = $profilForm.querySelector('button[type="submit"]');
    bouton.disabled = true;
    try {
      profilEnregistre(await L.api("compte.profil", donneesProfil()));
    } catch (err) {
      /* Identité pas (ou plus) confirmée : la fenêtre gère elle-même ce
         cas (voir lancerAction) en revenant à la phase d'authentification —
         ce n'est pas une erreur du Profil lui-même. */
      if (err.attente || err.champ === "mot_de_passe" || (err.donnees && err.donnees.google)) {
        throw err;
      }
      erreursDuProfil(err);
    } finally {
      bouton.disabled = false;
    }
  }

  /** Ferme la fenêtre et pose chaque erreur sous son champ du Profil. */
  function erreursDuProfil(err) {
    fermerConfirmation();
    const champ = L.erreursSurChamps(err, {
      identifiant: $usernameInput, email: $emailInput, photo_url: $urlInput,
    });
    if (champ) {
      champ.focus();
    } else if (err.champ === "photo") {
      $photoStatus.textContent = err.message;
      $photoStatus.classList.add("erreur");
      $dropzone.focus();
    } else {
      erreurProfil(err.message);
    }
  }

  $profilForm.addEventListener("submit", (e) => {
    e.preventDefault();
    effacerErreursProfil();

    if (photoEnAttente && photoEnAttente.refusee) {
      L.erreurChamp($urlInput, L.MESSAGE_URL_REFUSEE);
      $urlInput.focus();
      return;
    }

    if (profilChange()) {
      demanderConfirmation("Enregistrer les modifications ?", "", "Enregistrer", "compte.profil", finirProfil, false);
    } else {
      finirProfil();
    }
  });

  /* ---------------- Renvoyer l'e-mail de confirmation ---------------- */

  const $btnRenvoyer = document.getElementById("btn-renvoyer-verification");
  if ($btnRenvoyer) {
    $btnRenvoyer.addEventListener("click", async () => {
      $btnRenvoyer.disabled = true;
      try {
        const r = await L.api("compte.renvoyer_verification");
        L.toast(r.message);
      } catch (err) {
        L.toast(err.message);
      } finally {
        $btnRenvoyer.disabled = false;
      }
    });
  }

  /* ---------------- Mot de passe ----------------
     Changer (ou définir) le mot de passe est toujours une action à risque :
     elle passe TOUJOURS par la fenêtre de confirmation.

     Une fois l'identité confirmée, le vrai formulaire part en POST
     classique, volontairement : c'est la navigation qui suit la soumission
     qui déclenche la proposition « Enregistrer ce mot de passe ? » des
     gestionnaires. L'intercepter en AJAX la ferait perdre — le coffre ne
     serait pas mis à jour.

     Mais un POST refusé revenait avec les trois champs VIDES, et l'erreur
     loin au-dessus. On vérifie donc d'abord, sans rien changer (« verifier »
     dans api.php) : une erreur s'affiche sous son champ et la saisie reste
     en place ; tout est bon, le vrai POST part — et le serveur revérifie
     tout. */

  const $mdpForm = document.getElementById("mdp-form");
  const $mdpSubmit = document.getElementById("mdp-submit");
  const $mdpActuel = document.getElementById("a-current");
  const $mdpNouveau = document.getElementById("a-new");
  const $mdpConfirmation = document.getElementById("a-new2");
  let mdpVerifie = false;

  async function finirMotDePasse() {
    try {
      await L.api("compte.motdepasse", {
        actuel: $mdpActuel ? $mdpActuel.value : "", // caché : l'identité est déjà confirmée
        nouveau: $mdpNouveau.value,
        confirmation: $mdpConfirmation.value,
        verifier: "1",
      });
    } catch (err) {
      const expiree = err.attente || (err.erreurs && err.erreurs.actuel) || (err.donnees && err.donnees.google);
      if (expiree) throw err; // la fenêtre revient à la phase d'authentification
      erreursDuMotDePasse(err);
      return;
    }
    fermerConfirmation();
    mdpVerifie = true;
    // requestSubmit() rejoue une soumission complète (évènement compris),
    // la plus proche d'un clic réel ; submit() sert de repli.
    if (typeof $mdpForm.requestSubmit === "function") $mdpForm.requestSubmit($mdpSubmit);
    else $mdpForm.submit();
  }

  /** Ferme la fenêtre et pose chaque erreur sous son champ du mot de passe. */
  function erreursDuMotDePasse(err) {
    fermerConfirmation();
    const champ = L.erreursSurChamps(err, {
      actuel: $mdpActuel || $mdpNouveau, nouveau: $mdpNouveau, confirmation: $mdpConfirmation,
    });
    if (champ) champ.focus();
    else L.erreurChamp($mdpActuel || $mdpNouveau, err.message);
  }

  if ($mdpForm) $mdpForm.addEventListener("submit", (e) => {
    if (mdpVerifie) return; // la fenêtre a déjà tout vérifié : le vrai POST part
    e.preventDefault();
    L.effacerErreurs($mdpForm);
    const libelle = ($mdpSubmit.textContent || "Changer le mot de passe").trim();
    demanderConfirmation(libelle + " ?", "", libelle, "compte.motdepasse", finirMotDePasse, false);
  });

  /* ---------------- Filtre des images sensibles ----------------
     Présent seulement si l'administrateur a ouvert la possibilité dans le
     .env : sans cela, la carte n'existe pas dans la page.

     Le filtre est actif par défaut. Le lever demande une déclaration de
     majorité, une seule fois : une fois acquise, l'interrupteur bascule
     librement dans les deux sens. */

  const $filtre = document.getElementById("filtre-sensible");
  if ($filtre) {
    const $demande = document.getElementById("bloc-majorite");
    const $btnMajeur = document.getElementById("btn-majorite");

    async function enregistrerFiltre(filtrer, majeur) {
      const r = await L.api("compte.filtre_sensible", {
        filtrer: filtrer ? "1" : "0",
        majeur: majeur ? "1" : "0",
      });
      $filtre.checked = r.filtrer;
      $filtre.dataset.majeur = r.majeur ? "1" : "0";
      $demande.classList.toggle("hidden", r.majeur || r.filtrer);
      L.toast(r.message);
    }

    $filtre.addEventListener("change", async () => {
      const veutLever = !$filtre.checked;
      const majeur = $filtre.dataset.majeur === "1";

      /* Lever le filtre sans avoir déclaré sa majorité : on repose
         l'interrupteur et on montre la demande. L'état visible ne doit
         jamais laisser croire que le filtre est tombé alors qu'il tient
         toujours côté serveur. */
      if (veutLever && !majeur) {
        $filtre.checked = true;
        $demande.classList.remove("hidden");
        $btnMajeur.focus();
        return;
      }

      $filtre.disabled = true;
      try {
        await enregistrerFiltre(!veutLever, majeur);
      } catch (err) {
        $filtre.checked = !$filtre.checked; // on rend à l'écran l'état réel
        L.toast(err.message);
      } finally {
        $filtre.disabled = false;
      }
    });

    $btnMajeur.addEventListener("click", async () => {
      $btnMajeur.disabled = true;
      try {
        // La déclaration accompagne la levée : c'est ce que l'utilisateur
        // vient de demander, inutile de lui faire rebasculer l'interrupteur.
        await enregistrerFiltre(false, true);
      } catch (err) {
        L.toast(err.message);
      } finally {
        $btnMajeur.disabled = false;
      }
    });
  }
  /* ---------------- Import d'une sauvegarde ---------------- */

  const $importInput = document.getElementById("btn-import-all");
  const $importStatut = document.getElementById("import-statut");

  /* Le résultat reste affiché dans la carte, lisible aussi longtemps
     qu'il faut : il partait dans une notification, et la page filait
     vers la bibliothèque au bout d'une seconde — personne n'avait le
     temps de lire « 150 ignorée(s) car la limite… ». */
  function statutImport(message, erreur = false, lien = false) {
    $importStatut.replaceChildren(document.createTextNode(message));
    if (lien) {
      const a = document.createElement("a");
      a.href = "index.php";
      a.textContent = "Voir la bibliothèque";
      $importStatut.append(" ", a);
    }
    $importStatut.classList.toggle("erreur", erreur);
    $importStatut.classList.remove("hidden");
  }

  $importInput.addEventListener("change", async () => {
    const file = $importInput.files && $importInput.files[0];
    if (!file) return;

    if (!/\.json$/i.test(file.name || "") && file.type !== "application/json") {
      statutImport("Choisissez un fichier .json exporté depuis cette application.", true);
      $importInput.value = "";
      return;
    }
    const maxImport = L.limite("importMax", 5 * 1024 * 1024);
    if (file.size > maxImport) {
      statutImport("Fichier trop volumineux (" + L.tailleLisible(maxImport) + " maximum).", true);
      $importInput.value = "";
      return;
    }

    statutImport("Import de « " + file.name + " » en cours…");
    const fd = new FormData();
    fd.set("sauvegarde", file);
    try {
      const r = await L.api("donnees.importer", fd);
      statutImport(r.message, false, r.importees > 0 || r.presentes > 0);
    } catch (err) {
      statutImport(err.message, true);
    } finally {
      $importInput.value = "";
    }
  });

  /* ---------------- Fenêtre de confirmation ----------------
     Une même fenêtre, en deux phases, pour les quatre actions à risque
     (Profil, Sécurité, Vider, Supprimer) — pareille pour un compte e-mail
     et un compte Google, ce qui répond à la demande : seule la manière de
     prouver son identité diffère (mot de passe ICI, ou Google).

       Phase « identité » (#confirm-phase-identite) : un compte e-mail
     retape son mot de passe et clique « Confirmer mon identité » — cela
     NOTE une confirmation côté serveur (compte.authentifier) sans rien
     faire d'autre. Un compte Google se reconnecte (Google, puis son mot
     de passe s'il en a un) : une vraie navigation, qui revient sur cette
     page — cette même fenêtre se rouvre alors d'elle-même, déjà à la
     phase suivante (voir BOUTON_ACTION plus bas).

       Phase « action » (#confirm-phase-action) : identité confirmée,
     CONFIRMATION_DUREE secondes pour valider CETTE action précise.
     Le bouton final fait le travail réel (finirAction, propre à chaque
     action) : mettre à jour le Profil, changer le mot de passe, vider la
     bibliothèque, ou supprimer le compte. */

  const $confirmOverlay = document.getElementById("confirm-overlay");
  const $confirmTitle = document.getElementById("confirm-title");
  const $confirmText = document.getElementById("confirm-text");
  const $confirmOk = document.getElementById("confirm-ok");
  const $confirmIdentiteOk = document.getElementById("confirm-identite-ok"); // absent pour un compte Google
  const $confirmPwd = document.getElementById("confirm-password"); // absent pour un compte Google
  const $confirmExport = document.getElementById("confirm-export");
  const $phaseIdentite = document.getElementById("confirm-phase-identite");
  const $phaseAction = document.getElementById("confirm-phase-action");
  const $confirmDelai = document.getElementById("confirm-delai");
  const NB_SERIES = parseInt(document.body.dataset.series || "0", 10) || 0;

  /* La confirmation en cours, telle que le serveur la connaît au moment du
     chargement de la page : posée sur <body> par confirmation_en_cours(),
     pour UN compte, UNE action, UNE fois — plus de distinction Google/e-mail
     ici, voir includes/fonctions.php. */
  const CONFIRME = {
    action: document.body.dataset.confirmeAction || "",
    echeance: Date.now() + (parseInt(document.body.dataset.confirmeRestant || "0", 10) || 0) * 1000,
  };

  let actionEnAttente = null;
  let finirAction = null;
  let elementDeclencheur = null;
  let arreterDelai = null;

  /** Bascule la fenêtre sur la bonne phase pour $action, et démarre (ou
      arrête) le compte à rebours de la phase « action ». */
  function preparerPhase(action) {
    if (arreterDelai) { arreterDelai(); arreterDelai = null; }
    const reste = Math.ceil((CONFIRME.echeance - Date.now()) / 1000);
    const confirmee = CONFIRME.action === action && reste > 0;

    $phaseIdentite.classList.toggle("hidden", confirmee);
    $phaseAction.classList.toggle("hidden", !confirmee);
    if ($confirmIdentiteOk) $confirmIdentiteOk.classList.toggle("hidden", confirmee);
    $confirmOk.classList.toggle("hidden", !confirmee);
    $confirmOk.disabled = !confirmee;

    if (PAR_GOOGLE) {
      const lien = $phaseIdentite.querySelector("a.btn-google");
      if (lien) lien.href = "google.php?retour=parametres&action=" + encodeURIComponent(action);
    }

    if (confirmee) {
      // Délai écoulé pendant que la fenêtre était ouverte : c'est un
      // « Annuler » — la fenêtre se ferme, la confirmation et les
      // modifications qui attendaient s'effacent.
      arreterDelai = window.Delai.lancer($confirmDelai, reste, annulerConfirmation) || null;
    }
    /* Jamais sur le bouton qui agit (Confirmer mon identité, ou Confirmer) :
       une touche Entrée ou un second appui suffisait alors à tout
       supprimer. */
    if (confirmee || PAR_GOOGLE) document.getElementById("confirm-cancel").focus();
    else $confirmPwd.focus();
  }

  /**
   * Ouvre la fenêtre pour $action. $onConfirme est appelé au clic sur le
   * bouton final (phase « action ») : à lui de faire le travail et de
   * fermer la fenêtre en cas de succès (fermerConfirmation) ; toute erreur
   * qu'il laisse remonter — délai, identité expirée ou déjà servie — est
   * traitée ici, de la même façon pour les quatre actions.
   */
  function demanderConfirmation(titre, texte, libelle, action, onConfirme, destructif) {
    $confirmTitle.textContent = titre;
    if (texte) {
      $confirmText.textContent = (destructif ? "⚠️ " : "") + texte;
      $confirmText.classList.remove("hidden");
    } else {
      $confirmText.classList.add("hidden");
    }
    $confirmText.classList.toggle("alert-error", !!destructif);
    $confirmText.classList.toggle("alert-info", !destructif);
    $confirmOk.textContent = libelle;
    $confirmOk.classList.toggle("btn-danger", !!destructif);
    $confirmOk.classList.toggle("btn-primary", !destructif);
    // Rien à sauvegarder dans une bibliothèque vide, et rien à sauvegarder
    // du tout pour le Profil ou le mot de passe.
    $confirmExport.classList.toggle("hidden", !destructif || NB_SERIES === 0);
    if ($confirmPwd) {
      $confirmPwd.value = "";
      L.effacerErreur($confirmPwd);
    }
    actionEnAttente = action;
    finirAction = onConfirme;
    elementDeclencheur = document.activeElement;
    $confirmOverlay.classList.remove("hidden");
    preparerPhase(action);
  }

  function fermerConfirmation() {
    if (arreterDelai) { arreterDelai(); arreterDelai = null; }
    $confirmOverlay.classList.add("hidden");
    if ($confirmPwd) $confirmPwd.value = "";
    actionEnAttente = null;
    finirAction = null;
    // Le focus revient là où il était : sans ça, la navigation au clavier
    // repart du début de la page après chaque fermeture.
    if (elementDeclencheur && elementDeclencheur.focus) elementDeclencheur.focus();
    elementDeclencheur = null;
  }

  /* Annuler (bouton, clic à côté, Échap) : pour un compte qui venait de
     confirmer son identité pour CETTE action (Google ou mot de passe), le
     délai s'arrête là. La refaire demandera de prouver à nouveau son
     identité — une fenêtre refermée ne doit pas laisser derrière elle une
     action prête à partir. */
  function annulerConfirmation() {
    if (CONFIRME.action && CONFIRME.action === actionEnAttente) {
      CONFIRME.action = "";
      L.api("compte.annuler_confirmation", {}).catch(() => {});
    }
    fermerConfirmation();
  }

  document.getElementById("confirm-cancel").addEventListener("click", annulerConfirmation);
  $confirmOverlay.addEventListener("click", (e) => { if (e.target === $confirmOverlay) annulerConfirmation(); });
  document.addEventListener("keydown", (e) => {
    if ($confirmOverlay.classList.contains("hidden")) return;
    // Cette modale demande un mot de passe : laisser la tabulation filer
    // derrière elle est exactement ce qu'il ne faut pas faire.
    if (e.key === "Tab") L.piegerFocus($confirmOverlay, e);
    else if (e.key === "Escape") annulerConfirmation();
  });

  /** Le compte à rebours d'un blocage (« attente »), dans la fenêtre. */
  function afficherAttente(secondes) {
    $confirmText.textContent = "Trop de tentatives. Réessayez dans ";
    $confirmText.classList.remove("hidden");
    const compteur = document.createElement("b");
    compteur.className = "delai";
    $confirmText.appendChild(compteur);
    $confirmText.appendChild(document.createTextNode("."));
    compteur.addEventListener("delai-termine", () => { $confirmOk.disabled = false; });
    window.Delai.lancer(compteur, secondes);
  }

  /* Phase « identité », compte e-mail : retape son mot de passe, sans que
     rien ne soit encore fait — seule la confirmation est notée. Absent
     pour un compte Google (il n'y a pas de bouton : le lien Google fait
     tout le travail, via une vraie navigation). */
  async function confirmerIdentite() {
    if (!actionEnAttente || !$confirmIdentiteOk) return;
    const action = actionEnAttente;
    const motDePasse = $confirmPwd.value;
    if (!motDePasse) {
      L.erreurChamp($confirmPwd, "Saisissez votre mot de passe pour confirmer.");
      $confirmPwd.focus();
      return;
    }
    $confirmIdentiteOk.disabled = true;
    try {
      const r = await L.api("compte.authentifier", { pour: action, mot_de_passe: motDePasse });
      CONFIRME.action = action;
      CONFIRME.echeance = Date.now() + r.restant * 1000;
      preparerPhase(action);
    } catch (err) {
      if (err.attente) {
        L.erreurChamp($confirmPwd, "Trop de tentatives. Réessayez dans " + err.attente + " secondes.");
        return;
      }
      if (err.champ === "mot_de_passe") L.erreurChamp($confirmPwd, err.message);
      else L.toast(err.message);
      $confirmPwd.select();
    } finally {
      $confirmIdentiteOk.disabled = false;
    }
  }

  if ($confirmIdentiteOk) {
    $confirmIdentiteOk.addEventListener("click", confirmerIdentite);
    $confirmPwd.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        confirmerIdentite();
      }
    });
  }

  /* Phase « action » : identité confirmée, on fait le travail réel. */
  async function lancerAction() {
    if (!actionEnAttente || !finirAction) return;
    const action = actionEnAttente;
    $confirmOk.disabled = true;
    try {
      await finirAction();
    } catch (err) {
      if (err.attente) {
        afficherAttente(err.attente);
        return; // le bouton reste désactivé, le compte à rebours le réactivera
      }
      // L'identité s'est périmée (ou a déjà servi) pendant l'attente : il
      // faut la reconfirmer, la fenêtre reste ouverte pour ça.
      L.toast(err.message);
      CONFIRME.action = "";
      preparerPhase(action);
      $confirmOk.disabled = false;
      return;
    }
    $confirmOk.disabled = false;
  }

  $confirmOk.addEventListener("click", lancerAction);

  /* ---------------- Se reconnecter sans perdre sa saisie ----------------
     Pour un compte Google, prouver son identité est une vraie navigation :
     au retour, la page repart de zéro. Pour le Profil et le mot de passe,
     la saisie part donc d'abord au serveur (« preparer » dans api.php), qui
     la vérifie et la range dans la session — c'est seulement ensuite qu'on
     va chez Google. Une erreur (identifiant pris, mot de passe trop
     court…) se dit tout de suite, sous son champ, sans aller-retour. Au
     retour, la fenêtre propose d'enregistrer ce qui attendait (plus bas). */
  function preparerProfil() {
    const fd = donneesProfil();
    fd.set("preparer", "1");
    return L.api("compte.profil", fd).catch((err) => { erreursDuProfil(err); throw err; });
  }

  function preparerMotDePasse() {
    return L.api("compte.motdepasse", {
      nouveau: $mdpNouveau.value, confirmation: $mdpConfirmation.value, preparer: "1",
    }).catch((err) => { erreursDuMotDePasse(err); throw err; });
  }

  const PREPARER = { "compte.profil": preparerProfil, "compte.motdepasse": preparerMotDePasse };
  const $lienGoogle = PAR_GOOGLE ? $phaseIdentite.querySelector("a.btn-google") : null;
  if ($lienGoogle) {
    $lienGoogle.addEventListener("click", async (e) => {
      const preparer = PREPARER[actionEnAttente];
      if (!preparer) return; // Vider, Supprimer : rien à garder, le lien part tel quel
      e.preventDefault();
      if ($lienGoogle.getAttribute("aria-disabled") === "true") return;
      $lienGoogle.setAttribute("aria-disabled", "true");
      try {
        await preparer();
      } catch (err) {
        $lienGoogle.removeAttribute("aria-disabled");
        return; // l'erreur est déjà sous son champ
      }
      window.location.href = $lienGoogle.href;
    });
  }

  /* Retour de Google avec des modifications rangées avant de partir
     (data-en-attente, posé par parametres.php) : la fenêtre les rappelle
     et propose de les enregistrer, déjà à la phase « action ». */
  let enAttente = null;
  try {
    enAttente = JSON.parse(document.body.dataset.enAttente || "null");
  } catch (e) {
    enAttente = null;
  }

  async function finirEnAttente() {
    const attente = enAttente;
    try {
      if (attente.action === "compte.profil") {
        profilEnregistre(await L.api("compte.profil", { en_attente: "1" }));
      } else {
        const r = await L.api("compte.motdepasse", { en_attente: "1" });
        fermerConfirmation();
        L.toast(r.message);
        // La carte Sécurité change (« Supprimer le mot de passe »…).
        setTimeout(() => window.location.reload(), 900);
      }
      enAttente = null;
    } catch (err) {
      /* Plus rien ne peut partir tel quel (identifiant pris entre-temps,
         délai dépassé…) : la saisie revient dans les champs, avec l'erreur
         sous le sien. Le mot de passe, lui, n'est jamais revenu au
         navigateur : il reste à le retaper. */
      enAttente = null;
      if (attente.action === "compte.profil") {
        if (attente.identifiant !== null) $usernameInput.value = attente.identifiant;
        if (attente.email !== null) $emailInput.value = attente.email;
        erreursDuProfil(err);
      } else {
        erreursDuMotDePasse(err);
      }
    }
  }

  /* ---------------- Vider / Supprimer ---------------- */

  async function finirVider() {
    const r = await L.api("donnees.vider", {});
    fermerConfirmation();
    L.toast(r.message);
    setTimeout(() => window.location.reload(), 900);
  }

  async function finirSupprimer() {
    const r = await L.api("compte.supprimer", {});
    fermerConfirmation();
    L.toast(r.message);
    // Les filtres mémorisés de ce compte ne serviront plus à personne.
    L.oublierFiltres(document.body.dataset.compte);
    if (r.redirection) {
      /* replace() et non une navigation ordinaire : la page du compte
         supprimé quitte l'historique, « Précédent » ne peut plus y
         ramener. */
      setTimeout(() => window.location.replace(r.redirection), 900);
    } else {
      setTimeout(() => window.location.reload(), 900);
    }
  }

  document.getElementById("btn-clear-library").addEventListener("click", () => {
    demanderConfirmation(
      "Vider la bibliothèque ?",
      "Toutes vos séries seront supprimées. Votre compte et votre profil sont conservés.",
      "Tout vider",
      "donnees.vider",
      finirVider,
      true
    );
  });

  document.getElementById("btn-delete-account").addEventListener("click", () => {
    demanderConfirmation(
      "Supprimer le compte ?",
      P.texteSuppressionCompte(NB_SERIES, document.body.dataset.forfait),
      "Supprimer définitivement",
      "compte.supprimer",
      finirSupprimer,
      true
    );
  });

  /* Retour de Google : la fenêtre se rouvre d'elle-même, avec le temps qui
     reste pour cliquer le bouton final. Vider et Supprimer n'ont rien à
     garder ; le Profil et le mot de passe retrouvent ce qui avait été saisi
     avant de partir (enAttente, plus haut). */
  const BOUTON_ACTION = { "donnees.vider": "btn-clear-library", "compte.supprimer": "btn-delete-account" };
  if (PAR_GOOGLE && BOUTON_ACTION[CONFIRME.action]) {
    document.getElementById(BOUTON_ACTION[CONFIRME.action]).click();
  } else if (PAR_GOOGLE && enAttente && enAttente.action === CONFIRME.action) {
    demanderConfirmation(
      enAttente.action === "compte.profil" ? "Enregistrer ces modifications ?" : "Enregistrer ce mot de passe ?",
      enAttente.resume, "Enregistrer", enAttente.action, finirEnAttente, false
    );
  }

  /* Délai écoulé sur un bloc « Identité confirmée » de la page (Supprimer
     le mot de passe, seul endroit qui en garde un — voir parametres.php) :
     on recharge, et le bouton « Se reconnecter » revient à sa place. */
  document.addEventListener("delai-termine", (e) => {
    if (e.target.closest("[data-fin-confirmation]")) window.location.reload();
  });
})();
