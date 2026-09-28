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
require_once __DIR__ . '/includes/google.php';     // « Continuer avec Google »
require_once __DIR__ . '/includes/couvertures.php';   // le quota de recherche, annoncé dans « Forfait »

$moi = exiger_connexion();

/* Les erreurs sont rangées par formulaire, puis par champ : chacune
   s'affiche dans SA carte, sous SON champ. Une liste unique en haut de
   la page laissait l'erreur du mot de passe au-dessus du Profil, loin du
   formulaire qu'elle concernait. */
$erreurs_profil = [];
$erreurs_mdp    = [];
$erreurs_suppr  = [];   // « Supprimer le mot de passe » (compte Google)
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
        if (!verifier_mot_de_passe_limite((int) $moi['id'], $actuel, $attente, 'compte.motdepasse')) {
            $erreurs_mdp['actuel'] = $attente > 0
                ? 'Trop de tentatives. Réessayez dans ' . $attente . ' secondes.'
                : ((string) $moi['google_sub'] !== ''
                    ? message_reconnexion_google('compte.motdepasse')
                    : 'Mot de passe actuel incorrect.');
        }
        if ($faiblesses = valider_mot_de_passe($nouveau, (string) $moi['identifiant'])) {
            $erreurs_mdp['nouveau'] = $faiblesses;
        }
        if ($confirm === '') {
            $erreurs_mdp['confirmation'] = MESSAGE_CHAMP_OBLIGATOIRE;
        } elseif ($nouveau !== $confirm) {
            $erreurs_mdp['confirmation'] = 'Les deux nouveaux mots de passe ne correspondent pas.';
        }

        if (!$erreurs_mdp) {
            $pdo->prepare('UPDATE utilisateur SET mot_de_passe = ? WHERE id = ?')
                ->execute([password_hash($nouveau, PASSWORD_DEFAULT), (int) $moi['id']]);
            invalider_sessions((int) $moi['id']);
            connecter((int) $moi['id']);
            oublier_confirmation();   // elle ne sert qu'une fois
            journal_securite('mot_de_passe_change', ['utilisateur' => (int) $moi['id']]);
            avertir_mot_de_passe_change((string) $moi['email'], (string) $moi['identifiant'], acces_compte($moi['google_sub'], (int) $moi['sans_mot_de_passe'] === 0));
            // Redirection après POST : le rechargement de la page ne
            // redemande pas l'envoi du formulaire, et le gestionnaire de
            // mots de passe voit une navigation réussie — c'est ce qui
            // déclenche sa proposition d'enregistrement.
            flash((int) $moi['sans_mot_de_passe'] === 1
                ? 'Mot de passe défini ✅ — il vous sera demandé après Google, à chaque connexion.'
                : 'Mot de passe modifié ✅ — les autres appareils ont été déconnectés.');
            header('Location: parametres.php#securite');
            exit;
        }
    }

    if ($formulaire === 'profil') {
        $identifiant = texte($_POST['identifiant'] ?? '', 50);
        $email       = texte($_POST['email'] ?? '', 190);

        // En cas d'erreur, le formulaire réaffiche CE QUI A ÉTÉ SAISI :
        // un message « adresse invalide » sous l'ancienne adresse,
        // réaffichée à sa place, ne voudrait rien dire.
        $saisie_profil = [
            'identifiant' => $identifiant, 'email' => $email,
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
            if (!verifier_mot_de_passe_limite((int) $moi['id'], (string) ($_POST['mot_de_passe'] ?? ''), $attente, 'compte.profil')) {
                $erreurs_profil['mot_de_passe'] = $attente > 0
                    ? 'Trop de tentatives. Réessayez dans ' . $attente . ' secondes.'
                    : ((string) $moi['google_sub'] !== ''
                        ? message_reconnexion_google('compte.profil')
                        : "Pour changer votre identifiant ou votre adresse e-mail, saisissez votre mot de passe actuel.");
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

            $pdo->prepare('UPDATE utilisateur SET identifiant = ?, photo = ? WHERE id = ?')
                ->execute([$identifiant, $photo_maj, (int) $moi['id']]);
            if ($email_change || $identifiant_change) {
                oublier_confirmation();   // elle ne sert qu'une fois
            }

            if ($ancienne !== '' && $ancienne !== $photo_maj) {
                supprimer_image_locale($ancienne);
            }

            if ($email_change) {
                journal_securite('changement_email_demande', [
                    'utilisateur' => (int) $moi['id'], 'vers' => $email,
                ]);
                avertir_changement_email_demande((int) $moi['id'], (string) $moi['email'], $identifiant, $email, acces_compte($moi['google_sub'], (int) $moi['sans_mot_de_passe'] === 0));
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
            flash($email_change
                ? "Profil enregistré ✅ — un lien de confirmation a été envoyé à votre nouvelle adresse. "
                  . "Votre adresse actuelle reste active jusqu'au clic."
                : 'Profil enregistré ✅');
            header('Location: parametres.php#profil');
            exit;
        }
    }

    /* Supprimer son mot de passe : un compte créé avec Google revient à
       Google seul. Comme toute action à risque d'un compte Google, cela
       demande de s'être reconnecté (Google, puis ce mot de passe) pour
       cette action-là. Un compte e-mail, lui, ne peut pas se passer de
       son mot de passe : c'est sa seule clé. */
    if ($formulaire === 'supprimer_mdp' && (string) $moi['google_sub'] !== '' && (int) $moi['sans_mot_de_passe'] === 0) {
        $attente = null;
        if (!verifier_mot_de_passe_limite((int) $moi['id'], '', $attente, 'compte.supprimer_mdp')) {
            $erreurs_suppr['mot_de_passe'] = message_reconnexion_google('compte.supprimer_mdp');
        } else {
            $pdo->prepare("UPDATE utilisateur SET mot_de_passe = '' WHERE id = ? AND google_sub IS NOT NULL")
                ->execute([(int) $moi['id']]);
            oublier_confirmation();   // elle ne sert qu'une fois
            journal_securite('mot_de_passe_supprime', ['utilisateur' => (int) $moi['id']]);
            avertir_mot_de_passe_supprime((string) $moi['email'], (string) $moi['identifiant']);
            flash('Mot de passe supprimé ✅ — vous vous connectez désormais avec Google seul.');
            header('Location: parametres.php#securite');
            exit;
        }
    }

    /* Renoncer au changement d'adresse demandé : le lien envoyé à la
       nouvelle adresse cesse de fonctionner. Rien d'autre ne bouge — en
       particulier le lien de blocage reçu par l'ancienne adresse reste
       valable (voir avertir_changement_email_demande). */
    /* Renoncer à l'action confirmée : le délai s'arrête là, et la refaire
       demandera de se reconnecter. */
    if ($formulaire === 'annuler_confirmation') {
        $en_cours = confirmation_en_cours((int) $moi['id']);
        oublier_confirmation();
        flash('Action annulée : pour la refaire, il faudra vous reconnecter avec Google.');
        header('Location: ' . google_page_action((string) ($en_cours['action'] ?? 'compte.motdepasse')));
        exit;
    }

    if ($formulaire === 'annuler_email') {
        $pdo->prepare("DELETE FROM jeton_action WHERE utilisateur_id = ? AND type = 'changement_email'")
            ->execute([(int) $moi['id']]);
        journal_securite('changement_email_annule', ['utilisateur' => (int) $moi['id']]);
        flash("Demande de changement d'adresse annulée. Votre adresse actuelle reste celle du compte.");
        header('Location: parametres.php#profil');
        exit;
    }

    // Une erreur : on relit le compte pour réafficher des valeurs à jour.
    $moi = exiger_connexion();
}

// Laissé par la redirection qui suit un enregistrement : affiché une fois.
$info = flash_prendre($genre_info);

/* Chaque action à risque (Profil, Sécurité, Vider, Supprimer) demande de
   prouver son identité : un compte e-mail retape son mot de passe, un
   compte Google se reconnecte (Google, puis son mot de passe s'il en a
   un) — dans les deux cas via la fenêtre de confirmation (voir
   js/settings.js), pas dans le formulaire lui-même. Une fois prouvée,
   l'identité vaut pour CETTE action, ce compte, et le délai en cours :
   voir confirmation_en_cours(). Seule « Supprimer le mot de passe »
   reste affichée à part plus bas : elle n'existe que pour un compte
   Google, il n'y a rien à unifier. */
$par_google = (string) $moi['google_sub'] !== '';
$sans_mdp   = (int) $moi['sans_mot_de_passe'] === 1;
$confirme   = confirmation_en_cours((int) $moi['id']);
$confirme_pour = static fn (string $action): bool => ($confirme['action'] ?? '') === $action;

/**
 * « ✅ Identité confirmée : il vous reste 9 min 58 s pour … », et de quoi
 * y renoncer. Le bouton se rattache par « form » au formulaire caché
 * #annuler-confirmation-form : ce bloc peut se trouver DANS un autre
 * formulaire (le Profil), qui ne peut pas en contenir un second.
 */
function bloc_confirme(?array $confirme, string $pour): string
{
    $n = (int) ($confirme['restant'] ?? 0);
    return '<div class="bloc-confirme" data-fin-confirmation>'
        . '<p class="hint confirmation-active">✅ Identité confirmée : il vous reste '
        . '<b class="delai" data-restant="' . $n . '">' . $n . ' secondes</b> pour ' . e($pour) . '.</p>'
        . '<button type="submit" form="annuler-confirmation-form" class="btn btn-ghost small">Annuler</button>'
        . '</div>';
}

/** Le bouton qui part se reconnecter pour $action, et ce qu'il faudra faire. */
function bloc_reconnexion(string $action, string $libelle, bool $avec_mdp): string
{
    return '<p class="hint">Il faut d\'abord vous reconnecter avec Google'
        . ($avec_mdp ? ', puis avec votre mot de passe' : '') . '. Vous aurez ensuite '
        . intdiv(GOOGLE_CONFIRMATION_DUREE, 60) . ' minutes pour ' . e(ACTIONS_SENSIBLES[$action]) . '.</p>'
        . bouton_google($libelle, 'parametres', $action);
}

$photo   = url_image_sure($moi['photo']);
$csrf    = jeton_csrf();
$attente_email = changement_email_en_attente((int) $moi['id']);

// Valeurs affichées dans le Profil : la saisie refusée, sinon le compte.
$v = $erreurs_profil && $saisie_profil ? $saisie_profil : [
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
      data-import-max="<?= IMPORT_TAILLE_MAX ?>" data-prive="1"
      data-compte="<?= (int) $moi['id'] ?>" data-series="<?= $nb_series ?>"
      data-forfait="<?= e((string) $moi['forfait']) ?>" data-par-google="<?= $par_google ? '1' : '0' ?>"
      data-confirme-action="<?= e($confirme['action'] ?? '') ?>" data-confirme-restant="<?= (int) ($confirme['restant'] ?? 0) ?>">

<header class="topbar settings-topbar">
  <div class="topbar-row settings-topbar-row">
    <a href="index.php" class="back-btn" aria-label="Retour à la bibliothèque">←</a>
    <h1 class="settings-h1">Paramètres</h1>
    <span class="back-btn-spacer" aria-hidden="true"></span>
  </div>
</header>

<main class="settings-main">

  <?php if ($info): ?>
    <div class="alert <?= $genre_info === 'erreur' ? 'alert-error' : 'alert-info' ?>" role="<?= $genre_info === 'erreur' ? 'alert' : 'status' ?>"><?= e($info) ?></div>
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

      <!-- data-compte : la valeur ENREGISTRÉE, à laquelle le JS compare la
           saisie. Après une erreur, le champ réaffiche la saisie refusée ;
           comparer à elle cacherait le champ du mot de passe. -->
      <div class="field">
        <label for="a-username">Identifiant</label>
        <input id="a-username" name="identifiant" type="text" autocomplete="username" maxlength="30" required
               data-compte="<?= e($moi['identifiant']) ?>" value="<?= e($v['identifiant']) ?>"<?= champ_aria($erreurs_profil, 'identifiant', 'a-username', 'a-username-aide') ?>>
        <?= champ_erreur($erreurs_profil, 'identifiant', 'a-username') ?>
        <p class="hint" id="a-username-aide">Il sert à vous connecter : le changer demande de confirmer votre identité.</p>
      </div>

      <div class="field">
        <label for="a-email">E-mail</label>
        <input id="a-email" name="email" type="email" autocomplete="email" maxlength="190" required
               data-compte="<?= e($moi['email']) ?>" value="<?= e($v['email']) ?>"<?= champ_aria($erreurs_profil, 'email', 'a-email', 'a-email-aide') ?>>
        <?= champ_erreur($erreurs_profil, 'email', 'a-email') ?>
        <p class="hint" id="a-email-aide">
          Changer d'adresse demande de confirmer votre identité, et la nouvelle adresse doit être
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

      <!-- Sans JavaScript, c'est ICI que se prouve l'identité (Google se
           reconnecte, un compte e-mail retape son mot de passe) : ce bloc
           reste donc TOUJOURS dans le HTML. Avec JavaScript, js/settings.js
           le cache dès le chargement : la preuve d'identité passe alors
           par la fenêtre de confirmation, ouverte au clic sur
           « Enregistrer », et pareille pour les deux — c'est elle qui rend
           la page identique d'un compte à l'autre. -->
      <div class="field" id="email-password-field">
        <?php if ($par_google): ?>
          <!-- Compte Google : rien à retaper ici, il se reconnecte pour cette
               action. Le champ reste (vide, caché) pour que le script et
               l'affichage des erreurs n'aient qu'un seul cas à connaître. -->
          <input id="a-email-password" name="mot_de_passe" type="hidden" value="">
          <?php if ($confirme_pour('compte.profil')): ?>
            <?= bloc_confirme($confirme, ACTIONS_SENSIBLES['compte.profil']) ?>
          <?php else: ?>
            <?= bloc_reconnexion('compte.profil', 'Se reconnecter avec Google', !$sans_mdp) ?>
          <?php endif; ?>
        <?php else: ?>
        <label for="a-email-password">Mot de passe actuel
          <span class="hint">(requis si vous changez d'identifiant ou d'adresse)</span></label>
        <div class="password-wrap">
          <input id="a-email-password" name="mot_de_passe" type="password" autocomplete="current-password"<?= champ_aria($erreurs_profil, 'mot_de_passe', 'a-email-password') ?>>
          <button type="button" class="icon-btn toggle-password" data-cible="a-email-password" aria-label="Afficher le mot de passe">👁️</button>
        </div>
        <?php endif; ?>
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
    <!-- « Annuler » d'un bloc « Identité confirmée » (bloc_confirme). -->
    <form id="annuler-confirmation-form" method="post" action="parametres.php" class="hidden">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="formulaire" value="annuler_confirmation">
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

    <?php if ($par_google): ?>
      <!-- Compte créé avec Google : il le reste (il ne se délie pas, et un
           compte e-mail ne s'y relie pas, voir google_decision). Son mot de
           passe, s'il en a un, est demandé APRÈS Google, à chaque connexion
           (google-mot-de-passe.php). Le définir, le changer ou le supprimer
           demande de se reconnecter pour cette action-là. -->
      <p class="hint intro-securite"><?= $sans_mdp
          ? 'Vous vous connectez avec Google. Vous pouvez ajouter un mot de passe : il vous sera alors demandé après Google, à chaque connexion.'
          : 'Vous vous connectez avec Google, puis avec votre mot de passe.' ?></p>
      <!-- Sans JavaScript, la preuve d'identité se fait ICI, avant même
           d'atteindre le formulaire plus bas (le lien y ramène ensuite).
           Avec JavaScript, js/settings.js cache ce bloc dès le chargement :
           la fenêtre de confirmation, ouverte au clic sur le bouton du
           formulaire, fait le même travail — et pareillement pour un
           compte e-mail (mot de passe, dans la même fenêtre), ce qui rend
           la carte identique d'un compte à l'autre. -->
      <div id="securite-google-banner">
        <?php if ($confirme_pour('compte.motdepasse')): ?>
          <?= bloc_confirme($confirme, ACTIONS_SENSIBLES['compte.motdepasse']) ?>
        <?php else: ?>
          <?php if (isset($erreurs_mdp['actuel'])): ?>
            <p class="erreur-form" role="alert"><?= e($erreurs_mdp['actuel']) ?></p>
          <?php endif; ?>
          <?= bloc_reconnexion('compte.motdepasse', $sans_mdp ? 'Définir un mot de passe' : 'Changer le mot de passe', !$sans_mdp) ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Toujours présent, y compris pour un compte Google pas encore
         confirmé (sans JavaScript, une tentative sans confirmation revient
         avec l'erreur ci-dessus) : c'est ce qui permet à js/settings.js de
         montrer la même carte aux deux, et de tout faire passer par la
         fenêtre de confirmation. -->
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

      <?php if (!$par_google): ?>
      <div class="field" id="mdp-actuel-field">
        <label for="a-current">Mot de passe actuel</label>
        <div class="password-wrap">
          <input id="a-current" name="mot_de_passe_actuel" type="password" autocomplete="current-password" required<?= champ_aria($erreurs_mdp, 'actuel', 'a-current') ?>>
          <button type="button" class="icon-btn toggle-password" data-cible="a-current" aria-label="Afficher le mot de passe">👁️</button>
        </div>
        <?= champ_erreur($erreurs_mdp, 'actuel', 'a-current') ?>
      </div>
      <?php endif; ?>

      <div class="field">
        <label for="a-new">Nouveau mot de passe</label>
        <div class="password-wrap">
          <input id="a-new" name="mot_de_passe_nouveau" type="password" autocomplete="new-password" required
                 maxlength="<?= MDP_MAX ?>"
                 data-regles-mdp data-mdp-min="<?= MDP_MIN ?>" data-mdp-max="<?= MDP_MAX ?>"
                 data-identifiant-valeur="<?= e($moi['identifiant']) ?>"<?= champ_aria($erreurs_mdp, 'nouveau', 'a-new') ?>>
          <button type="button" class="icon-btn toggle-password" data-cible="a-new" aria-label="Afficher le mot de passe">👁️</button>
        </div>
        <?= champ_erreur($erreurs_mdp, 'nouveau', 'a-new') ?>
      </div>

      <div class="field">
        <label for="a-new2">Confirmer le nouveau mot de passe</label>
        <div class="password-wrap">
          <input id="a-new2" name="mot_de_passe_confirmation" type="password" autocomplete="new-password" required
                 maxlength="<?= MDP_MAX ?>" placeholder="Retapez le mot de passe"<?= champ_aria($erreurs_mdp, 'confirmation', 'a-new2') ?>>
          <button type="button" class="icon-btn toggle-password" data-cible="a-new2" aria-label="Afficher le mot de passe">👁️</button>
        </div>
        <?= champ_erreur($erreurs_mdp, 'confirmation', 'a-new2') ?>
      </div>

      <button type="submit" id="mdp-submit" class="btn btn-primary full"><?= $sans_mdp ? 'Définir le mot de passe' : 'Changer le mot de passe' ?></button>
      <p class="hint">
        Votre mot de passe est stocké haché (bcrypt) : même en ouvrant la base, il est illisible.
        Le changer déconnecte tous vos autres appareils.
      </p>
    </form>

    <?php if ($par_google && !$sans_mdp): ?>
      <!-- Revenir à Google seul : se reconnecter pour CETTE action, puis
           la valider ici dans le délai. -->
      <div class="form-supprimer-mdp">
        <h3 class="settings-sous-titre">Supprimer le mot de passe</h3>
        <p class="hint">Vous vous connecterez alors avec Google seul.</p>
        <?php if (isset($erreurs_suppr['mot_de_passe'])): ?>
          <p class="erreur-form" role="alert"><?= e($erreurs_suppr['mot_de_passe']) ?></p>
        <?php endif; ?>
        <?php if ($confirme_pour('compte.supprimer_mdp')): ?>
          <?= bloc_confirme($confirme, ACTIONS_SENSIBLES['compte.supprimer_mdp']) ?>
          <form method="post" action="parametres.php#securite">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="formulaire" value="supprimer_mdp">
            <button type="submit" class="btn btn-danger full">Oui, supprimer mon mot de passe</button>
          </form>
        <?php else: ?>
          <?= bloc_reconnexion('compte.supprimer_mdp', 'Supprimer le mot de passe', true) ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

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
    <?php if ($quota_recherche > 0): ?>
      <p class="hint">
        Recherche automatique de couverture : <b><?= $quota_recherche ?> recherches toutes les <?= e($tranche) ?></b>.
        Au-delà, il suffit d'attendre la fin des <?= e($tranche) ?> : le compteur repart de zéro.
        <?php if ($moi['forfait'] !== 'illimite'): ?>
          <?= COUVERTURE_QUOTA_ILLIMITE === 0
              ? "Le forfait illimité n'a pas cette limite."
              : (COUVERTURE_QUOTA_ILLIMITE > $quota_recherche
                  ? 'Le forfait illimité en permet ' . COUVERTURE_QUOTA_ILLIMITE . '.' : '') ?>
        <?php endif; ?>
      </p>
    <?php else: ?>
      <p class="hint">Recherche automatique de couverture : <b>sans limite</b>.</p>
    <?php endif; ?>
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
  <section class="settings-card" id="donnees">
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

<!-- Confirmation des actions sensibles (Profil, Sécurité, Vider, Supprimer) :
     une même fenêtre en deux phases, pareille pour un compte e-mail et un
     compte Google — seule la façon de prouver son identité diffère.
       Phase 1, #confirm-phase-identite : un compte e-mail retape son mot
     de passe ICI, sans que rien ne soit encore fait (compte.authentifier
     note la confirmation) ; un compte Google se reconnecte (Google, puis
     son mot de passe s'il en a un) et revient sur cette page, où la
     fenêtre se rouvre d'elle-même, déjà à la phase 2.
       Phase 2, #confirm-phase-action : identité confirmée, GOOGLE_CONFIRMATION_DUREE
     secondes pour valider CETTE action précise — une autre en demanderait
     une nouvelle. js/settings.js bascule de l'une à l'autre. -->
<div id="confirm-overlay" class="overlay hidden">
  <div class="modal small" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title">
    <h2 id="confirm-title">Confirmer ?</h2>

    <div id="confirm-phase-identite">
      <p class="hint">Confirmez d'abord votre identité<?= $par_google && !$sans_mdp ? ', puis votre mot de passe' : '' ?>.
        Vous aurez ensuite <?= intdiv(GOOGLE_CONFIRMATION_DUREE, 60) ?> minutes pour confirmer.</p>
      <?php if ($par_google): ?>
        <?= bouton_google('Se reconnecter avec Google', 'parametres') ?>
      <?php else: ?>
        <div class="field">
          <label for="confirm-password">Mot de passe actuel</label>
          <div class="password-wrap">
            <input id="confirm-password" type="password" autocomplete="current-password">
            <button type="button" class="icon-btn toggle-password" data-cible="confirm-password" aria-label="Afficher le mot de passe">👁️</button>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div id="confirm-phase-action" class="hidden">
      <!-- En alerte, pas en simple indication : c'est ce que la personne doit
           lire avant tout le reste. La classe (alerte ou simple info) est
           posée par js/settings.js selon l'action. -->
      <p id="confirm-text" class="alert" role="alert">Cette action est définitive.</p>
      <p class="hint confirmation-active">✅ Identité confirmée : il vous reste
        <b id="confirm-delai" class="delai"></b> pour confirmer.</p>
      <!-- Dernière occasion de sauvegarder, dans la fenêtre même qui efface :
           un « pensez à exporter » ne servait à rien sans le bouton à côté.
           Le fichier se télécharge sans quitter la page. -->
      <form id="confirm-export" method="post" action="api.php" class="confirm-export hidden">
        <input type="hidden" name="action" value="donnees.exporter">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="btn btn-ghost full">⬇️ Exporter d'abord mes <?= $nb_series ?> série(s)</button>
      </form>
    </div>

    <div class="modal-actions">
      <div class="grow"></div>
      <button type="button" id="confirm-cancel" class="btn btn-ghost">Annuler</button>
      <?php if (!$par_google): ?>
        <button type="button" id="confirm-identite-ok" class="btn btn-primary">Confirmer mon identité</button>
      <?php endif; ?>
      <button type="button" id="confirm-ok" class="btn btn-danger hidden" disabled>Confirmer</button>
    </div>
  </div>
</div>

<div id="toast" class="toast hidden" role="status"></div>

<script src="<?= e(actif('js/delai.js')) ?>" defer></script>
<script src="<?= e(actif('js/commun.js')) ?>" defer></script>
<script src="<?= e(actif('js/mdp.js')) ?>" defer></script>
<script src="<?= e(actif('js/settings.js')) ?>" defer></script>
</body>
</html>
