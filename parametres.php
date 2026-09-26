<?php
/* =====================================================================
   Page Paramètres : Profil · Sécurité · Données · À propos

   Les formulaires sont de VRAIS formulaires POST, avec des attributs
   « name » et « autocomplete ». Deux conséquences :
     - les gestionnaires de mots de passe (navigateur, Bitwarden, 1Password…)
       reconnaissent le formulaire de changement de mot de passe, proposent
       de remplir l'ancien et d'enregistrer le nouveau ;
     - la page reste utilisable si JavaScript ne se charge pas.
   Le JavaScript intercepte la soumission pour éviter le rechargement,
   mais il n'est plus indispensable.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/fonctions.php';
require_once __DIR__ . '/includes/couvertures.php';   // le quota de recherche, annoncé dans « Forfait »

$moi = exiger_connexion();

/* Les erreurs sont rangées par formulaire, puis par champ : chacune
   s'affiche dans SA carte, sous SON champ. Une liste unique en haut de
   la page laissait l'erreur du mot de passe au-dessus du Profil, loin du
   formulaire qu'elle concernait. */
$erreurs_profil = [];
$erreurs_mdp    = [];
$saisie_profil  = null;
$info    = '';

/* ---------------------------------------------------------------------
   Traitement sans JavaScript : les mêmes règles que api.php, appliquées
   ici pour que le formulaire fonctionne en POST classique. Les cas
   complexes (photo, import, suppression) restent réservés au JS ; le
   formulaire de mot de passe, lui, doit marcher sans, car c'est celui
   que les gestionnaires de mots de passe soumettent.
   --------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_csrf();
    $formulaire = (string) ($_POST['formulaire'] ?? '');

    if ($formulaire === 'motdepasse') {
        $actuel  = (string) ($_POST['mot_de_passe_actuel'] ?? '');
        $nouveau = (string) ($_POST['mot_de_passe_nouveau'] ?? '');
        $confirm = (string) ($_POST['mot_de_passe_confirmation'] ?? '');

        $attente = null;
        if (!verifier_mot_de_passe_limite((int) $moi['id'], $actuel, $attente)) {
            $erreurs_mdp['actuel'] = $attente > 0
                ? 'Trop de tentatives. Réessayez dans ' . $attente . ' secondes.'
                : 'Mot de passe actuel incorrect.';
        }
        if ($faiblesses = valider_mot_de_passe($nouveau, (string) $moi['identifiant'])) {
            $erreurs_mdp['nouveau'] = $faiblesses;
        }
        if ($nouveau !== $confirm) {
            $erreurs_mdp['confirmation'] = 'Les deux nouveaux mots de passe ne correspondent pas.';
        }

        if (!$erreurs_mdp) {
            $pdo->prepare('UPDATE utilisateur SET mot_de_passe = ? WHERE id = ?')
                ->execute([password_hash($nouveau, PASSWORD_DEFAULT), (int) $moi['id']]);
            invalider_sessions((int) $moi['id']);
            connecter((int) $moi['id']);
            journal_securite('mot_de_passe_change', ['utilisateur' => (int) $moi['id']]);
            avertir_mot_de_passe_change((string) $moi['email'], (string) $moi['identifiant']);
            // Redirection après POST : le rechargement de la page ne
            // redemande pas l'envoi du formulaire, et le gestionnaire de
            // mots de passe voit une navigation réussie — c'est ce qui
            // déclenche sa proposition d'enregistrement.
            header('Location: parametres.php?mdp=1');
            exit;
        }
    }

    if ($formulaire === 'profil') {
        $prenom      = texte($_POST['prenom'] ?? '', 80);
        $nom         = texte($_POST['nom'] ?? '', 80);
        $identifiant = texte($_POST['identifiant'] ?? '', 50);
        $email       = texte($_POST['email'] ?? '', 190);

        // En cas d'erreur, le formulaire réaffiche CE QUI A ÉTÉ SAISI :
        // un message « adresse invalide » sous l'ancienne adresse,
        // réaffichée à sa place, ne voudrait rien dire.
        $saisie_profil = [
            'prenom' => $prenom, 'nom' => $nom, 'identifiant' => $identifiant, 'email' => $email,
            'photo_url' => texte($_POST['photo_url'] ?? '', 500),
        ];

        /* Dans l'ordre du formulaire : c'est le premier champ fautif qui
           reçoit le focus (voir champ_aria). */
        if (url_image_refusee($_POST['photo_url'] ?? '')) {
            $erreurs_profil['photo_url'] = MESSAGE_URL_IMAGE_REFUSEE;
        }
        $erreurs_profil += valider_profil($identifiant, $email, (int) $moi['id']);

        /* Mêmes règles que api.php : l'adresse e-mail ET l'identifiant
           servent à reprendre le compte, donc tous deux demandent le mot
           de passe. Le reste du profil, non. */
        $email_change       = (strcasecmp($email, (string) $moi['email']) !== 0);
        $identifiant_change = ($identifiant !== (string) $moi['identifiant']);

        if ($email_change || $identifiant_change) {
            $attente = null;
            if (!verifier_mot_de_passe_limite((int) $moi['id'], (string) ($_POST['mot_de_passe'] ?? ''), $attente)) {
                $erreurs_profil['mot_de_passe'] = $attente > 0
                    ? 'Trop de tentatives. Réessayez dans ' . $attente . ' secondes.'
                    : "Pour changer votre identifiant ou votre adresse e-mail, saisissez votre mot de passe actuel.";
            }
        }
        // Après le mot de passe, jamais avant : sans lui, dire qu'une
        // adresse est prise révélerait qu'un compte existe.
        if (!$erreurs_profil && $email_change && !email_disponible($email, (int) $moi['id'])) {
            $erreurs_profil['email'] = 'Cette adresse e-mail est déjà utilisée.';
        }

        if (!$erreurs_profil) {
            // Photo : mêmes règles et mêmes priorités que api.php
            // (fichier envoyé > URL saisie > image retirée > image actuelle).
            $erreur_image = null;
            $fichier = enregistrer_image('photo_fichier', $erreur_image);
            if ($erreur_image !== null) {
                $erreurs_profil['photo'] = $erreur_image;
            }
        }

        if (!$erreurs_profil) {
            $ancienne  = (string) $moi['photo'];
            $photo_maj = photo_depuis_formulaire($fichier, $ancienne);

            $pdo->prepare('UPDATE utilisateur SET prenom = ?, nom = ?, identifiant = ?, photo = ? WHERE id = ?')
                ->execute([$prenom, $nom, $identifiant, $photo_maj, (int) $moi['id']]);

            if ($ancienne !== '' && $ancienne !== $photo_maj) {
                supprimer_image_locale($ancienne);
            }

            if ($email_change) {
                journal_securite('changement_email_demande', [
                    'utilisateur' => (int) $moi['id'], 'vers' => $email,
                ]);
                avertir_changement_email_demande((int) $moi['id'], (string) $moi['email'], $identifiant, $email);
                $jeton = generer_jeton_action((int) $moi['id'], 'changement_email', 86400, $email);
                envoyer_email(
                    $email,
                    'Confirmez votre nouvelle adresse e-mail',
                    "Bonjour {$identifiant},\n\n"
                    . "Une demande de changement d'adresse e-mail a été faite sur votre compte.\n"
                    . "Confirmez cette nouvelle adresse en cliquant sur ce lien (valable 24 h) :\n"
                    . url_publique('verifier-email.php?jeton=' . $jeton) . "\n\n"
                    . "Tant que vous n'aurez pas cliqué, votre compte conservera son adresse actuelle.\n"
                    . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message."
                );
            }
            header('Location: parametres.php?profil=' . ($email_change ? '2' : '1'));
            exit;
        }
    }

    /* Renoncer au changement d'adresse demandé : le lien envoyé à la
       nouvelle adresse cesse de fonctionner. Rien d'autre ne bouge — en
       particulier le lien de blocage reçu par l'ancienne adresse reste
       valable (voir avertir_changement_email_demande). */
    if ($formulaire === 'annuler_email') {
        $pdo->prepare("DELETE FROM jeton_action WHERE utilisateur_id = ? AND type = 'changement_email'")
            ->execute([(int) $moi['id']]);
        journal_securite('changement_email_annule', ['utilisateur' => (int) $moi['id']]);
        header('Location: parametres.php?profil=3');
        exit;
    }

    // Une erreur : on relit le compte pour réafficher des valeurs à jour.
    $moi = exiger_connexion();
}

if (isset($_GET['mdp'])) {
    $info = 'Mot de passe modifié ✅ — les autres appareils ont été déconnectés.';
}
if (($_GET['profil'] ?? '') === '1') {
    $info = 'Profil enregistré ✅';
}
if (($_GET['profil'] ?? '') === '2') {
    $info = "Profil enregistré ✅ — un lien de confirmation a été envoyé à votre nouvelle adresse. "
          . "Votre adresse actuelle reste active jusqu'au clic.";
}
if (($_GET['profil'] ?? '') === '3') {
    $info = "Demande de changement d'adresse annulée. Votre adresse actuelle reste celle du compte.";
}

$photo   = url_image_sure($moi['photo']);
$csrf    = jeton_csrf();
$attente_email = changement_email_en_attente((int) $moi['id']);

// Valeurs affichées dans le Profil : la saisie refusée, sinon le compte.
$v = $erreurs_profil && $saisie_profil ? $saisie_profil : [
    'prenom' => (string) $moi['prenom'], 'nom' => (string) $moi['nom'],
    'identifiant' => (string) $moi['identifiant'], 'email' => (string) $moi['email'],
    'photo_url' => preg_match('#^https://#i', $photo) ? $photo : '',
];

/* Le quota de recherche automatique de couverture n'était annoncé nulle
   part : on le découvrait en butant dessus. */
$quota_recherche = couverture_quota($moi);
$tranche         = couverture_tranche_lisible(COUVERTURE_FENETRE);

$req = $pdo->prepare('SELECT COUNT(*) FROM serie WHERE utilisateur_id = ?');
$req->execute([(int) $moi['id']]);
$nb_series = (int) $req->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Paramètres — Ma Bibliothèque Manga</title>
<meta name="description" content="Gérez votre profil, votre mot de passe et vos données.">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%93%9A%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= e(actif('css/style.css')) ?>">
</head>
<body data-csrf="<?= e($csrf) ?>" data-image-max="<?= IMAGE_TAILLE_MAX ?>"
      data-import-max="<?= IMPORT_TAILLE_MAX ?>" data-prive="1">

<header class="topbar settings-topbar">
  <div class="topbar-row settings-topbar-row">
    <a href="index.php" class="back-btn" aria-label="Retour à la bibliothèque">←</a>
    <h1 class="settings-h1">Paramètres</h1>
    <span class="back-btn-spacer" aria-hidden="true"></span>
  </div>
</header>

<main class="settings-main">

  <?php if ($info): ?>
    <div class="alert alert-info" role="status"><?= e($info) ?></div>
  <?php endif; ?>

  <!-- ---------------- Profil ---------------- -->
  <section class="settings-card" id="profil">
    <h2 class="settings-card-title"><span class="settings-icon" aria-hidden="true">👤</span> Profil</h2>

    <?php if (isset($erreurs_profil['photo'])): ?>
      <div class="alert alert-error" role="alert"><?= e($erreurs_profil['photo']) ?></div>
    <?php endif; ?>

    <div class="profile-avatar-row">
      <div id="settings-dropzone" class="settings-avatar-dropzone" tabindex="0" role="button" aria-label="Modifier la photo de profil">
        <img id="settings-avatar-preview" class="settings-avatar-img<?= $photo === '' ? ' hidden' : '' ?>"
             <?= $photo !== '' ? 'src="' . e($photo) . '"' : '' ?> alt="Photo de profil">
        <span id="settings-avatar-fallback" class="settings-avatar-fallback<?= $photo !== '' ? ' hidden' : '' ?>"><?= e(initiales($moi)) ?></span>
        <span class="avatar-edit-badge" aria-hidden="true">✏️</span>
      </div>
      <div class="profile-avatar-actions">
        <!-- Une étiquette, et pas seulement un texte indicatif : celui-ci
             disparaît à la première lettre, et un lecteur d'écran
             n'annonçait rien. -->
        <label for="a-image-url" class="sous-label">Adresse d'une image (https://…)</label>
        <input id="a-image-url" name="photo_url" type="url" placeholder="https://…" inputmode="url" maxlength="500"
               form="profil-form"<?= champ_aria($erreurs_profil, 'photo_url', 'a-image-url') ?>
               value="<?= e($v['photo_url']) ?>">
        <?= champ_erreur($erreurs_profil, 'photo_url', 'a-image-url') ?>
        <div class="cover-actions-buttons">
          <label class="btn btn-ghost small file-label">
            📁 Choisir un fichier
            <input id="a-image-file" name="photo_fichier" type="file" accept="image/*" class="visually-hidden" form="profil-form">
          </label>
          <button type="button" id="remove-photo" class="btn btn-ghost small danger-text">Retirer</button>
        </div>
        <p id="photo-status" class="hint" aria-live="polite"></p>
      </div>
    </div>

    <!-- novalidate : les bulles du navigateur s'effacent d'elles-mêmes ;
         les erreurs s'affichent sous le champ, et restent. Le serveur
         valide tout de toute façon. -->
    <form id="profil-form" method="post" action="parametres.php#profil" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="formulaire" value="profil">
      <input type="hidden" name="photo_retiree" id="a-photo-removed" value="0">

      <div class="field-row">
        <div class="field">
          <label for="a-firstname">Prénom</label>
          <input id="a-firstname" name="prenom" type="text" autocomplete="given-name" maxlength="80" value="<?= e($v['prenom']) ?>">
        </div>
        <div class="field">
          <label for="a-lastname">Nom</label>
          <input id="a-lastname" name="nom" type="text" autocomplete="family-name" maxlength="80" value="<?= e($v['nom']) ?>">
        </div>
      </div>

      <!-- data-compte : la valeur ENREGISTRÉE, à laquelle le JS compare la
           saisie. Après une erreur, le champ réaffiche la saisie refusée ;
           comparer à elle cacherait le champ du mot de passe. -->
      <div class="field">
        <label for="a-username">Identifiant</label>
        <input id="a-username" name="identifiant" type="text" autocomplete="username" maxlength="30" required
               data-compte="<?= e($moi['identifiant']) ?>" value="<?= e($v['identifiant']) ?>"<?= champ_aria($erreurs_profil, 'identifiant', 'a-username', 'a-username-aide') ?>>
        <?= champ_erreur($erreurs_profil, 'identifiant', 'a-username') ?>
        <p class="hint" id="a-username-aide">Il sert à vous connecter : le changer demande votre mot de passe.</p>
      </div>

      <div class="field">
        <label for="a-email">E-mail</label>
        <input id="a-email" name="email" type="email" autocomplete="email" maxlength="190" required
               data-compte="<?= e($moi['email']) ?>" value="<?= e($v['email']) ?>"<?= champ_aria($erreurs_profil, 'email', 'a-email', 'a-email-aide') ?>>
        <?= champ_erreur($erreurs_profil, 'email', 'a-email') ?>
        <p class="hint" id="a-email-aide">
          Changer d'adresse demande votre mot de passe, et la nouvelle adresse doit être
          confirmée par e-mail. L'adresse actuelle reste active jusque-là — une faute de
          frappe ne peut donc pas vous enfermer dehors.
        </p>
        <!-- La demande en cours, qui ne se voyait nulle part : le champ
             montre l'adresse ACTIVE, et ce bloc celle qui attend. -->
        <div id="email-attente" class="email-attente<?= $attente_email ? '' : ' hidden' ?>">
          <p>
            ✉️ Changement vers <b id="email-attente-adresse"><?= e($attente_email['adresse'] ?? '') ?></b>
            en attente : cliquez sur le lien envoyé à cette adresse (valable jusqu'au
            <span id="email-attente-expire"><?= e($attente_email['expire'] ?? '') ?></span>).
            D'ici là, votre adresse actuelle reste celle du compte.
          </p>
          <button type="submit" form="annuler-email-form" class="btn btn-ghost small">Annuler cette demande</button>
        </div>
      </div>

      <!-- Affiché par le JS quand l'identifiant ou l'adresse change ;
           toujours présent dans le HTML pour que la page fonctionne sans JS. -->
      <div class="field" id="email-password-field">
        <label for="a-email-password">Mot de passe actuel
          <span class="hint">(requis si vous changez d'identifiant ou d'adresse)</span></label>
        <div class="password-wrap">
          <input id="a-email-password" name="mot_de_passe" type="password" autocomplete="current-password"<?= champ_aria($erreurs_profil, 'mot_de_passe', 'a-email-password') ?>>
          <button type="button" class="icon-btn toggle-password" data-cible="a-email-password" aria-label="Afficher le mot de passe">👁️</button>
        </div>
        <?= champ_erreur($erreurs_profil, 'mot_de_passe', 'a-email-password') ?>
      </div>

      <!-- Les erreurs qui ne tiennent à aucun champ, à côté du bouton qui
           vient d'être pressé. -->
      <p id="profil-erreur" class="erreur-form hidden" role="alert" tabindex="-1"></p>

      <div class="settings-save-bar">
        <button type="submit" class="btn btn-primary full">Enregistrer les modifications</button>
      </div>
    </form>

    <!-- Hors du formulaire de profil (un formulaire ne peut pas en contenir
         un autre) : le bouton « Annuler cette demande » s'y rattache par
         son attribut « form ». -->
    <form id="annuler-email-form" method="post" action="parametres.php#profil" class="hidden">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="formulaire" value="annuler_email">
    </form>

    <?php if (!$moi['email_verifie']): ?>
      <div class="settings-divider"></div>
      <p class="hint">⚠️ Adresse e-mail non vérifiée.</p>
      <button id="btn-renvoyer-verification" type="button" class="btn btn-ghost full">Renvoyer l'e-mail de confirmation</button>
    <?php endif; ?>
  </section>

  <!-- ---------------- Sécurité ----------------
       « #securite » dans l'action : après un envoi refusé, la page revient
       sur CETTE carte, où l'erreur s'affiche — et non tout en haut. -->
  <section class="settings-card" id="securite">
    <h2 class="settings-card-title"><span class="settings-icon" aria-hidden="true">🔒</span> Sécurité</h2>

    <form id="mdp-form" method="post" action="parametres.php#securite" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="formulaire" value="motdepasse">

      <!-- Champ d'identifiant caché, en lecture seule.
           Sans lui, un gestionnaire de mots de passe voit trois champs
           « password » sans savoir à quel compte les rattacher : il ne
           propose ni le remplissage, ni l'enregistrement du nouveau.
           « hidden » au sens visuel (classe), pas type="hidden" : les
           gestionnaires ignorent les champs type="hidden". -->
      <input class="visually-hidden" type="text" name="identifiant_lecture" autocomplete="username"
             value="<?= e($moi['identifiant']) ?>" readonly tabindex="-1" aria-hidden="true">

      <div class="field">
        <label for="a-current">Mot de passe actuel</label>
        <div class="password-wrap">
          <input id="a-current" name="mot_de_passe_actuel" type="password" autocomplete="current-password" required<?= champ_aria($erreurs_mdp, 'actuel', 'a-current') ?>>
          <button type="button" class="icon-btn toggle-password" data-cible="a-current" aria-label="Afficher le mot de passe">👁️</button>
        </div>
        <?= champ_erreur($erreurs_mdp, 'actuel', 'a-current') ?>
      </div>

      <div class="field">
        <label for="a-new">Nouveau mot de passe</label>
        <div class="password-wrap">
          <input id="a-new" name="mot_de_passe_nouveau" type="password" autocomplete="new-password" required
                 minlength="<?= MDP_MIN ?>" maxlength="<?= MDP_MAX ?>" placeholder="<?= MDP_MIN ?> caractères minimum"<?= champ_aria($erreurs_mdp, 'nouveau', 'a-new', 'mdp-regle') ?>>
          <button type="button" class="icon-btn toggle-password" data-cible="a-new" aria-label="Afficher le mot de passe">👁️</button>
        </div>
        <?= champ_erreur($erreurs_mdp, 'nouveau', 'a-new') ?>
      </div>

      <div class="field">
        <label for="a-new2">Confirmer le nouveau mot de passe</label>
        <div class="password-wrap">
          <input id="a-new2" name="mot_de_passe_confirmation" type="password" autocomplete="new-password" required
                 minlength="<?= MDP_MIN ?>" maxlength="<?= MDP_MAX ?>"<?= champ_aria($erreurs_mdp, 'confirmation', 'a-new2') ?>>
          <button type="button" class="icon-btn toggle-password" data-cible="a-new2" aria-label="Afficher le mot de passe">👁️</button>
        </div>
        <?= champ_erreur($erreurs_mdp, 'confirmation', 'a-new2') ?>
        <p class="hint" id="mdp-regle"><?= e(MDP_REGLE) ?></p>
      </div>

      <button type="submit" class="btn btn-primary full">Changer le mot de passe</button>
      <p class="hint">
        Votre mot de passe est stocké haché (bcrypt) : même en ouvrant la base, il est illisible.
        Le changer déconnecte tous vos autres appareils.
      </p>
    </form>

    <div class="settings-divider"></div>

    <form method="post" action="deconnexion.php">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <button type="submit" class="btn btn-ghost full">🚪 Se déconnecter</button>
    </form>
  </section>

  <!-- ---------------- Forfait ---------------- -->
  <section class="settings-card">
    <h2 class="settings-card-title"><span class="settings-icon" aria-hidden="true">🎫</span> Forfait</h2>
    <?php if ($moi['forfait'] === 'illimite'): ?>
      <p class="hint"><b>Illimité ✨</b> — aucune limite de séries pour ce compte (<?= $nb_series ?> actuellement).</p>
    <?php else: ?>
      <p class="hint">
        <b>Standard</b> — <?= $nb_series ?> / <?= MAX_SERIES_PAR_UTILISATEUR ?> séries utilisées.
      </p>
      <p class="hint">
        Besoin de plus de place ? Veuillez contacter l'administrateur à
        <a href="mailto:<?= e(ADMIN_EMAIL) ?>"><?= e(ADMIN_EMAIL) ?></a> pour passer au forfait supérieur.
      </p>
    <?php endif; ?>
    <p class="hint">
      Recherche automatique de couverture : <b><?= $quota_recherche ?> recherches toutes les <?= e($tranche) ?></b>.
      <?php if ($moi['forfait'] !== 'illimite' && COUVERTURE_QUOTA_ILLIMITE > $quota_recherche): ?>
        Le forfait illimité en permet <?= COUVERTURE_QUOTA_ILLIMITE ?>.
      <?php endif; ?>
      Au-delà, il suffit d'attendre la fin des <?= e($tranche) ?> : le compteur repart de zéro.
    </p>
  </section>

  <!-- ---------------- Images sensibles ----------------
       Affichée seulement si l'administrateur a ouvert la possibilité dans
       le .env. Sinon la section n'existe pas : proposer un réglage sans
       effet ne ferait qu'égarer. -->
  <?php if (COUVERTURE_CONTENU_ADULTE): ?>
  <?php
    $majeur  = (int) ($moi['adulte_confirme'] ?? 0) === 1;
    $filtrer = (int) ($moi['filtre_sensible'] ?? 1) === 1;
  ?>
  <section class="settings-card">
    <h2 class="settings-card-title"><span class="settings-icon" aria-hidden="true">🛡️</span> Images sensibles</h2>

    <label class="bascule" for="filtre-sensible">
      <input type="checkbox" id="filtre-sensible" class="bascule-case"
             data-majeur="<?= $majeur ? '1' : '0' ?>"
             <?= $filtrer ? 'checked' : '' ?>>
      <span class="bascule-piste" aria-hidden="true"><span class="bascule-pastille"></span></span>
      <span class="bascule-libelle">Filtrer les images sensibles</span>
    </label>

    <p class="hint">
      La recherche automatique de couverture écarte les séries classées pour un
      public adulte par la source : nudité et thèmes sexuels marqués. Ce classement
      porte sur le contenu sexuel et non sur la violence, si bien que plusieurs
      seinen courants s'y trouvent et restent introuvables tant que le filtre est
      actif. Les contenus pornographiques ne sont jamais proposés, filtre actif ou non.
    </p>

    <!-- Toujours présent dans le HTML, masqué tant qu'il ne sert à rien :
         c'est le JavaScript qui le révèle quand on tente de lever le
         filtre sans avoir déclaré sa majorité. -->
    <!-- Masqué au chargement, quel que soit l'état : il n'apparaît qu'au
         moment où l'on tente de lever le filtre. L'afficher d'emblée
         reviendrait à proposer du contenu adulte à quelqu'un qui n'a rien
         demandé. -->
    <div id="bloc-majorite" class="majorite-demande hidden">
      <p class="hint">
        Désactiver ce filtre affichera des couvertures réservées à un public adulte.
      </p>
      <button id="btn-majorite" type="button" class="btn btn-primary full">J'ai 18 ans ou plus</button>
    </div>
  </section>
  <?php endif; ?>
  <!-- ---------------- Données ---------------- -->
  <section class="settings-card">
    <h2 class="settings-card-title"><span class="settings-icon" aria-hidden="true">💾</span> Données</h2>
    <p class="hint">
      <b><?= $nb_series ?></b> série(s) enregistrée(s) dans votre bibliothèque.
      Membre depuis le <?= e(date('d/m/Y', strtotime((string) $moi['cree_le']))) ?>.
    </p>

    <div class="settings-actions-row">
      <form method="post" action="api.php">
        <input type="hidden" name="action" value="donnees.exporter">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button type="submit" id="btn-export-all" class="btn btn-ghost">⬇️ Exporter mes données</button>
      </form>
      <label class="btn btn-ghost file-label">
        ⬆️ Importer
        <input id="btn-import-all" type="file" accept="application/json,.json" class="visually-hidden">
      </label>
    </div>
    <!-- Le résultat de l'import reste ici, lisible : il partait dans une
         notification de deux secondes, suivie d'une redirection. -->
    <p id="import-statut" class="import-statut hidden" role="status"></p>
    <p class="hint">
      Importer une sauvegarde n'ajoute que les séries absentes : une série déjà présente
      (même titre) n'est jamais dupliquée, elle récupère seulement l'image ou l'étoile qui
      lui manquent.
    </p>

    <button id="btn-clear-library" type="button" class="btn btn-danger full">🗑️ Vider ma bibliothèque</button>
    <p class="hint">Supprime toutes vos séries. Votre compte est conservé.</p>

    <div class="settings-divider"></div>

    <button id="btn-delete-account" type="button" class="btn btn-danger full">⚠️ Supprimer mon compte</button>
    <p class="hint">Supprime définitivement le compte et l'intégralité de la bibliothèque associée.</p>
  </section>

  <!-- ---------------- À propos ---------------- -->
  <section class="settings-card about-card">
    <h2 class="settings-card-title"><span class="settings-icon" aria-hidden="true">📚</span> À propos</h2>
    <p class="hint">
      <b>Ma Bibliothèque Manga</b> — chaque compte possède sa propre bibliothèque : suivez le
      tome en cours de chaque série, repérez d'un coup d'œil le prochain tome à emprunter grâce
      à sa couverture, et filtrez par statut de lecture.
    </p>
    <p class="hint">
      <a href="mentions-legales.php">Mentions légales et politique de confidentialité</a>
    </p>
  </section>

</main>

<!-- Confirmation des actions destructrices : le mot de passe y est
     redemandé, pour qu'une session volée ne suffise pas à tout effacer. -->
<div id="confirm-overlay" class="overlay hidden">
  <div class="modal small" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title">
    <h2 id="confirm-title">Confirmer ?</h2>
    <p id="confirm-text" class="hint">Cette action est définitive.</p>
    <div class="field">
      <label for="confirm-password">Saisissez votre mot de passe pour confirmer</label>
      <div class="password-wrap">
        <input id="confirm-password" type="password" autocomplete="current-password">
        <button type="button" class="icon-btn toggle-password" data-cible="confirm-password" aria-label="Afficher le mot de passe">👁️</button>
      </div>
    </div>
    <div class="modal-actions">
      <div class="grow"></div>
      <button type="button" id="confirm-cancel" class="btn btn-ghost">Annuler</button>
      <button type="button" id="confirm-ok" class="btn btn-danger">Confirmer</button>
    </div>
  </div>
</div>

<div id="toast" class="toast hidden" role="status"></div>

<script src="<?= e(actif('js/delai.js')) ?>" defer></script>
<script src="<?= e(actif('js/commun.js')) ?>" defer></script>
<script src="<?= e(actif('js/settings.js')) ?>" defer></script>
</body>
</html>
