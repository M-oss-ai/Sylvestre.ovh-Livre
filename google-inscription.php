<?php
/* =====================================================================
   Dernière étape d'une inscription avec Google.

   Google a confirmé l'adresse (google.php l'a rangée dans la session) :
   il ne reste qu'à choisir un identifiant, et, si on le souhaite, un mot
   de passe : il sera alors demandé après Google, à chaque connexion
   (google-mot-de-passe.php).

   L'adresse est celle du compte Google, et elle n'est pas modifiable
   ici : Google l'a vérifiée, aucun e-mail de confirmation n'est donc à
   attendre. Elle se change ensuite dans les Paramètres, avec le lien de
   confirmation habituel.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/fonctions.php';
require_once __DIR__ . '/includes/google.php';

if (!google_actif()) {
    http_response_code(404);
    exit('Not found');
}
if (utilisateur_actuel()) {
    header('Location: index.php');
    exit;
}

$en_cours = $_SESSION['google_inscription'] ?? null;
if (isset($_GET['annuler'])) {
    unset($_SESSION['google_inscription']);
    header('Location: inscription.php');
    exit;
}
if (!is_array($en_cours) || (int) ($en_cours['le'] ?? 0) < time() - GOOGLE_PARCOURS_MAX) {
    unset($_SESSION['google_inscription']);
    flash('La création du compte avec Google a expiré. Recommencez.', 'erreur');
    header('Location: connexion.php');
    exit;
}

$sub   = (string) $en_cours['sub'];
$email = (string) $en_cours['email'];

$erreurs     = [];
$identifiant = identifiant_depuis_google($email);   // une proposition, que l'on peut changer

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_csrf();
    $identifiant = texte($_POST['identifiant'] ?? '', 50);
    $mdp         = (string) ($_POST['mot_de_passe'] ?? '');
    $mdp2        = (string) ($_POST['confirmation'] ?? '');

    $bloque = limiteur_bloque_depuis('inscription');
    if ($bloque > 0) {
        $erreurs[''] = 'Trop de comptes créés depuis cette adresse. Réessayez dans ' . $bloque . ' secondes.';
    } else {
        if ($message = forme_identifiant($identifiant)) {
            $erreurs['identifiant'] = $message;
        } else {
            $req = $pdo->prepare('SELECT 1 FROM utilisateur WHERE identifiant = ?');
            $req->execute([$identifiant]);
            if ($req->fetchColumn()) {
                $erreurs['identifiant'] = 'Cet identifiant est déjà pris, choisissez-en un autre.';
            }
        }
        // Le mot de passe est facultatif ; s'il est donné, mêmes règles qu'ailleurs.
        if ($mdp !== '' || $mdp2 !== '') {
            if ($faiblesses = valider_mot_de_passe($mdp, $identifiant)) {
                $erreurs['mot_de_passe'] = $faiblesses;
            }
            if ($mdp2 === '') {
                $erreurs['confirmation'] = MESSAGE_CHAMP_OBLIGATOIRE;
            } elseif ($mdp !== $mdp2) {
                $erreurs['confirmation'] = 'Les deux mots de passe ne correspondent pas.';
            }
            /* Un mot de passe n'est jamais réaffiché : après une erreur
               ailleurs (l'identifiant pris), il faut le dire, sinon le
               compte serait créé sans lui au prochain envoi. */
            if ($erreurs && !isset($erreurs['mot_de_passe'])) {
                $erreurs['mot_de_passe'] = 'Retapez votre mot de passe.';
            }
        }
    }

    if (!$erreurs) {
        limiteur_echec('inscription', INSCRIPTION_MAX, INSCRIPTION_BLOCAGE);

        /* Un compte e-mail jamais confirmé qui porterait cette adresse cède
           la place : n'importe qui a pu le créer avec l'adresse d'un autre,
           et Google vient de prouver à qui elle est. Jamais activé, il n'a
           jamais pu être ouvert (la connexion exige l'adresse confirmée). */
        $pdo->prepare('DELETE FROM utilisateur WHERE email = ? AND email_verifie = 0 AND google_sub IS NULL')
            ->execute([$email]);

        try {
            // Le plafond de comptes est appliqué par l'insertion elle-même.
            $req = $pdo->prepare(
                'INSERT INTO utilisateur (identifiant, email, mot_de_passe, email_verifie, google_sub)
                 SELECT ?, ?, ?, 1, ?
                   FROM DUAL
                  WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM utilisateur) AS c) < ?'
            );
            $req->execute([
                $identifiant, $email,
                $mdp !== '' ? password_hash($mdp, PASSWORD_DEFAULT) : '',   // '' : pas de mot de passe
                $sub, MAX_UTILISATEURS,
            ]);
            if ($req->rowCount() === 0) {
                $erreurs[''] = 'Le nombre maximum de comptes (' . MAX_UTILISATEURS . ') a été atteint. '
                    . "Merci de contacter l'administrateur à " . ADMIN_EMAIL . '.';
            }
        } catch (PDOException $e) {
            /* Pris entre la vérification et l'écriture (deux onglets) : le
               plus souvent l'identifiant, sinon l'adresse ou le compte Google. */
            error_log('google-inscription: ' . $e->getMessage());
            if (str_contains($e->getMessage(), 'uk_utilisateur_identifiant')) {
                $erreurs['identifiant'] = 'Cet identifiant est déjà pris, choisissez-en un autre.';
            } else {
                unset($_SESSION['google_inscription']);
                flash('Un compte existe déjà pour ce compte Google ou cette adresse : connectez-vous.', 'erreur');
                header('Location: connexion.php');
                exit;
            }
        }

        if (!$erreurs) {
            $id = (int) $pdo->lastInsertId();
            unset($_SESSION['google_inscription']);
            connecter($id);
            journal_securite('google_compte_cree', ['utilisateur' => $id]);
            flash('Bienvenue ' . $identifiant . ' ! Votre compte a été créé avec Google ✅');
            header('Location: index.php');
            exit;
        }
    }
}

/* Les champs du mot de passe restent repliés derrière « Ajouter un mot de
   passe », sauf s'il en a été saisi un : il faut alors le retaper. */
$mdp_ouvert = isset($erreurs['mot_de_passe']) || isset($erreurs['confirmation']);

$csrf = jeton_csrf();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Créer votre compte — Ma Bibliothèque Manga</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%93%9A%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= e(actif('css/style.css')) ?>">
</head>
<body class="auth-body">

<main class="auth-wrap">
  <section class="auth-card">
    <div class="auth-head">
      <p class="auth-logo" aria-hidden="true">📚</p>
      <h1>Presque terminé</h1>
      <p class="hint">Choisissez votre identifiant sur le site.</p>
    </div>

    <?php if (($erreurs[''] ?? '') !== ''): ?>
      <div class="alert alert-error" role="alert"><?= e($erreurs['']) ?></div>
    <?php endif; ?>

    <p class="hint adresse-google">
      Adresse du compte : <b><?= e($email) ?></b>, fournie par Google.
      Vous pourrez la changer ensuite dans les Paramètres.
    </p>

    <form method="post" action="google-inscription.php" autocomplete="on" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

      <div class="field">
        <label for="identifiant">Identifiant</label>
        <input id="identifiant" name="identifiant" type="text" required autocomplete="username"
               value="<?= e($identifiant) ?>"<?= champ_aria($erreurs, 'identifiant', 'identifiant') ?><?= $erreurs ? '' : ' autofocus' ?>>
        <?= champ_erreur($erreurs, 'identifiant', 'identifiant') ?>
      </div>

      <!-- Facultatif : sans mot de passe, on se connecte avec Google seul.
           Avec, il est demandé en seconde étape, après Google. Un <details>
           plutôt qu'un script : le bouton marche aussi sans JavaScript.
           Replié, il vide ses champs (js/auth.js) : on ne crée pas un mot de
           passe auquel on vient de renoncer. -->
      <details class="ajout-mdp"<?= $mdp_ouvert ? ' open' : '' ?>>
        <summary>
          <span class="btn btn-ghost full"><span class="si-ferme">🔑 Ajouter un mot de passe</span><span class="si-ouvert">Ne pas ajouter de mot de passe</span></span>
          <span class="hint" id="mdp-facultatif">Facultatif : il vous sera demandé après Google, à chaque connexion.</span>
        </summary>

      <div class="field">
        <label for="mot_de_passe">Mot de passe</label>
        <div class="password-wrap">
          <input id="mot_de_passe" name="mot_de_passe" type="password" autocomplete="new-password" maxlength="<?= MDP_MAX ?>"
                 data-regles-mdp data-mdp-min="<?= MDP_MIN ?>" data-mdp-max="<?= MDP_MAX ?>" data-identifiant="identifiant"
                 <?= champ_aria($erreurs, 'mot_de_passe', 'mot_de_passe', 'mdp-facultatif') ?>>
          <button type="button" class="icon-btn toggle-password" data-cible="mot_de_passe"
                  aria-label="Afficher le mot de passe">👁️</button>
        </div>
        <?= champ_erreur($erreurs, 'mot_de_passe', 'mot_de_passe') ?>
      </div>

      <div class="field">
        <label for="confirmation">Confirmer le mot de passe</label>
        <div class="password-wrap">
          <input id="confirmation" name="confirmation" type="password" autocomplete="new-password" maxlength="<?= MDP_MAX ?>"
                 placeholder="Retapez le mot de passe"<?= champ_aria($erreurs, 'confirmation', 'confirmation') ?>>
          <button type="button" class="icon-btn toggle-password" data-cible="confirmation"
                  aria-label="Afficher le mot de passe">👁️</button>
        </div>
        <?= champ_erreur($erreurs, 'confirmation', 'confirmation') ?>
      </div>
      </details>

      <button type="submit" class="btn btn-primary full">Créer mon compte</button>
    </form>

    <p class="auth-switch"><a href="google-inscription.php?annuler=1">Annuler</a></p>
    <p class="auth-legal"><a href="mentions-legales.php">Mentions légales et confidentialité</a></p>
  </section>
</main>

<script src="<?= e(actif('js/auth.js')) ?>" defer></script>
<script src="<?= e(actif('js/mdp.js')) ?>" defer></script>
</body>
</html>
