/* =========================================================
   Page Paramètres (parametres.php)

   Les formulaires fonctionnent déjà sans ce fichier (POST
   classique vers parametres.php). Le JavaScript ne fait
   qu'éviter le rechargement de page : s'il ne se charge pas,
   rien n'est cassé.
   ========================================================= */

(() => {
  "use strict";

  const L = window.Lib;

  L.brancherToggleMotDePasse();

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
     Le champ « mot de passe actuel » n'apparaît que si l'identifiant ou
     l'adresse change — ces deux-là servent à reprendre le compte. Un
     changement de prénom ne demande rien. Sans JavaScript, le champ
     reste visible en permanence. */

  const $profilForm = document.getElementById("profil-form");
  const $emailInput = document.getElementById("a-email");
  const $usernameInput = document.getElementById("a-username");
  const $emailPwdField = document.getElementById("email-password-field");
  const $emailPwd = document.getElementById("a-email-password");
  const $profilErreur = document.getElementById("profil-erreur");
  const $emailAttente = document.getElementById("email-attente");

  /* La comparaison se fait avec les valeurs ENREGISTRÉES (data-compte),
     pas avec celles du champ au chargement : après un envoi refusé, le
     champ réaffiche la saisie, et le champ du mot de passe — où se
     trouve peut-être justement l'erreur — se serait caché. */
  function majChampMotDePasse() {
    const emailChange = $emailInput.value.trim().toLowerCase()
      !== ($emailInput.dataset.compte || "").trim().toLowerCase();
    const usernameChange = $usernameInput.value.trim() !== ($usernameInput.dataset.compte || "").trim();
    const change = emailChange || usernameChange;
    $emailPwdField.classList.toggle("hidden", !change);
    $emailPwd.required = change;
  }
  $emailInput.addEventListener("input", majChampMotDePasse);
  $usernameInput.addEventListener("input", majChampMotDePasse);
  majChampMotDePasse();

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

  $profilForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    const bouton = $profilForm.querySelector('button[type="submit"]');
    effacerErreursProfil();

    if (photoEnAttente && photoEnAttente.refusee) {
      L.erreurChamp($urlInput, L.MESSAGE_URL_REFUSEE);
      $urlInput.focus();
      return;
    }

    // FormData(form) reprend tous les champs nommés, y compris ceux
    // rattachés par l'attribut « form » (l'URL et le fichier de la photo).
    const fd = new FormData($profilForm);

    // Le sélecteur d'image garde le fichier en mémoire ; on le réinjecte
    // pour couvrir le cas du glisser-déposer, qui ne passe pas par l'input.
    if (photoEnAttente && photoEnAttente.type === "file") {
      fd.set("photo_fichier", photoEnAttente.file);
      fd.delete("photo_url");
    } else if (photoEnAttente && photoEnAttente.type === "url" && !photoEnAttente.existante) {
      fd.set("photo_url", photoEnAttente.value);
    }

    bouton.disabled = true;
    try {
      const r = await L.api("compte.profil", fd);
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
      $usernameInput.dataset.compte = $usernameInput.value.trim();
      majChampMotDePasse();
      if (r.email_attente) {
        document.getElementById("email-attente-adresse").textContent = r.email_attente.adresse;
        document.getElementById("email-attente-expire").textContent = r.email_attente.expire;
        $emailAttente.classList.remove("hidden");
      }
      L.toast(r.message);
    } catch (err) {
      // Sous le champ concerné : une notification de deux secondes disait
      // l'erreur sans dire où.
      const champ = L.erreursSurChamps(err, {
        identifiant: $usernameInput, email: $emailInput, mot_de_passe: $emailPwd, photo_url: $urlInput,
      });
      if (champ) {
        if (champ === $emailPwd) $emailPwdField.classList.remove("hidden");
        champ.focus();
      } else if (err.champ === "photo") {
        $photoStatus.textContent = err.message;
        $photoStatus.classList.add("erreur");
        $dropzone.focus();
      } else {
        erreurProfil(err.message);
      }
    } finally {
      bouton.disabled = false;
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
     Le formulaire part en POST classique, volontairement : c'est la
     navigation qui suit la soumission qui déclenche la proposition
     « Enregistrer ce mot de passe ? » des gestionnaires. L'intercepter
     en AJAX la ferait perdre — le coffre ne serait pas mis à jour.

     Mais un POST refusé revenait avec les trois champs VIDES, et
     l'erreur loin au-dessus. On vérifie donc d'abord, sans rien changer
     (« verifier » dans api.php) : une erreur s'affiche sous son champ et
     la saisie reste en place ; tout est bon, le vrai POST part — et le
     serveur revérifie tout. */

  const $mdpForm = document.getElementById("mdp-form");
  const $mdpActuel = document.getElementById("a-current");
  const $mdpNouveau = document.getElementById("a-new");
  const $mdpConfirmation = document.getElementById("a-new2");
  let mdpVerifie = false;

  $mdpForm.addEventListener("submit", async (e) => {
    if (mdpVerifie) return; // la vérification est passée : le POST part
    e.preventDefault();
    const bouton = $mdpForm.querySelector('button[type="submit"]');
    L.effacerErreurs($mdpForm);

    bouton.disabled = true;
    try {
      await L.api("compte.motdepasse", {
        actuel: $mdpActuel.value,
        nouveau: $mdpNouveau.value,
        confirmation: $mdpConfirmation.value,
        verifier: "1",
      });
      mdpVerifie = true;
      bouton.disabled = false;
      // requestSubmit() rejoue une soumission complète (évènement compris),
      // la plus proche d'un clic réel ; submit() sert de repli.
      if (typeof $mdpForm.requestSubmit === "function") $mdpForm.requestSubmit(bouton);
      else $mdpForm.submit();
    } catch (err) {
      bouton.disabled = false;
      const champ = L.erreursSurChamps(err, {
        actuel: $mdpActuel, nouveau: $mdpNouveau, confirmation: $mdpConfirmation,
      });
      if (champ) champ.focus();
      else L.erreurChamp($mdpActuel, err.message);
    }
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

  /* ---------------- Confirmations (vider / supprimer) ----------------
     Ces deux actions sont irréversibles : le serveur exige le mot de
     passe (voir exiger_mot_de_passe dans api.php), on le demande donc ici. */

  const $confirmOverlay = document.getElementById("confirm-overlay");
  const $confirmTitle = document.getElementById("confirm-title");
  const $confirmText = document.getElementById("confirm-text");
  const $confirmOk = document.getElementById("confirm-ok");
  const $confirmPwd = document.getElementById("confirm-password");
  let actionEnAttente = null;
  let elementDeclencheur = null;

  function demanderConfirmation(titre, texte, libelle, action) {
    $confirmTitle.textContent = titre;
    $confirmText.textContent = texte;
    $confirmOk.textContent = libelle;
    $confirmPwd.value = "";
    L.effacerErreur($confirmPwd);
    actionEnAttente = action;
    elementDeclencheur = document.activeElement;
    $confirmOverlay.classList.remove("hidden");
    $confirmPwd.focus();
  }

  function fermerConfirmation() {
    $confirmOverlay.classList.add("hidden");
    $confirmPwd.value = "";
    actionEnAttente = null;
    // Le focus revient là où il était : sans ça, la navigation au clavier
    // repart du début de la page après chaque fermeture.
    if (elementDeclencheur && elementDeclencheur.focus) elementDeclencheur.focus();
    elementDeclencheur = null;
  }

  document.getElementById("confirm-cancel").addEventListener("click", fermerConfirmation);
  $confirmOverlay.addEventListener("click", (e) => { if (e.target === $confirmOverlay) fermerConfirmation(); });
  document.addEventListener("keydown", (e) => {
    if ($confirmOverlay.classList.contains("hidden")) return;
    // Cette modale demande un mot de passe : laisser la tabulation filer
    // derrière elle est exactement ce qu'il ne faut pas faire.
    if (e.key === "Tab") L.piegerFocus($confirmOverlay, e);
    else if (e.key === "Escape") fermerConfirmation();
  });

  async function lancerAction() {
    if (!actionEnAttente) return;
    const action = actionEnAttente;
    const motDePasse = $confirmPwd.value;
    if (!motDePasse) {
      L.erreurChamp($confirmPwd, "Saisissez votre mot de passe pour confirmer.");
      $confirmPwd.focus();
      return;
    }
    $confirmOk.disabled = true;
    try {
      const r = await L.api(action, { mot_de_passe: motDePasse });
      L.toast(r.message);
      fermerConfirmation();
      if (r.redirection) {
        /* replace() et non une navigation ordinaire : la page du compte
           supprimé quitte l'historique, « Précédent » ne peut plus y
           ramener. */
        setTimeout(() => window.location.replace(r.redirection), 900);
      } else {
        setTimeout(() => window.location.reload(), 900);
      }
    } catch (err) {
      // Mot de passe refusé : la modale reste ouverte pour réessayer.
      if (err.attente) {
        /* Une notification disparaît en trois secondes, or l'attente en
           dure soixante : elle s'affiche donc sous le champ, où elle
           reste visible aussi longtemps qu'elle s'applique. */
        $confirmText.textContent = "Trop de tentatives. Réessayez dans ";
        const compteur = document.createElement("b");
        compteur.className = "delai";
        $confirmText.appendChild(compteur);
        $confirmText.appendChild(document.createTextNode("."));
        $confirmOk.disabled = true;
        compteur.addEventListener("delai-termine", () => { $confirmOk.disabled = false; });
        window.Delai.lancer(compteur, err.attente);
        return;
      }
      // Sous le champ du mot de passe, là où l'on corrige.
      if (err.champ === "mot_de_passe") L.erreurChamp($confirmPwd, err.message);
      else L.toast(err.message);
      $confirmPwd.select();
      /* Réactivé ici, et seulement ici. L'ancien « finally » testait
         « s'il n'est pas désactivé » — il l'était toujours à ce stade :
         après un mot de passe refusé, le bouton restait grisé et seule
         la touche Entrée permettait de réessayer. Pendant un compte à
         rebours (plus haut), c'est la fin du délai qui le rend. */
      $confirmOk.disabled = false;
    }
  }

  $confirmOk.addEventListener("click", lancerAction);
  $confirmPwd.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      lancerAction();
    }
  });

  document.getElementById("btn-clear-library").addEventListener("click", () => {
    demanderConfirmation(
      "Vider la bibliothèque ?",
      "Toutes vos séries seront supprimées. Votre compte et votre profil sont conservés. Pensez à exporter une sauvegarde avant.",
      "Tout vider",
      "donnees.vider"
    );
  });

  document.getElementById("btn-delete-account").addEventListener("click", () => {
    demanderConfirmation(
      "Supprimer le compte ?",
      "Votre compte, votre profil et l'intégralité de votre bibliothèque seront définitivement supprimés. Cette action est irréversible.",
      "Supprimer définitivement",
      "compte.supprimer"
    );
  });
})();
