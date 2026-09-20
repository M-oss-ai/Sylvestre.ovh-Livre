<?php
/* =====================================================================
   Choix d'un nouveau mot de passe, depuis le lien reçu par e-mail.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/fonctions.php';

if (utilisateur_actuel()) {
    header('Location: index.php');
    exit;
}

$jeton   = (string) ($_GET['jeton'] ?? $_POST['jeton'] ?? '');
$compte  = jeton_action_valide($jeton, 'reinit');
$erreurs = [];
$reussi  = false;

if ($compte && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_csrf();

    $mdp  = (string) ($_POST['mot_de_passe'] ?? '');
    $mdp2 = (string) ($_POST['confirmation'] ?? '');

    $erreurs = valider_mot_de_passe($mdp, (string) $compte['identifiant']);
    if ($mdp !== $mdp2) {
        $erreurs[] = 'Les deux mots de passe ne correspondent pas.';
    }

    if (!$erreurs) {
        /* email_verifie passe à 1 : suivre ce lien prouve qu'on possède la
           boîte, c'est exactement ce que la confirmation d'inscription
           demande. Sans ça, quelqu'un qui n'a jamais confirmé son adresse
           réinitialiserait son mot de passe… et resterait bloqué à la
           connexion, sans comprendre pourquoi. */
        $pdo->prepare('UPDATE utilisateur SET mot_de_passe = ?, email_verifie = 1 WHERE id = ?')
            ->execute([password_hash($mdp, PASSWORD_DEFAULT), (int) $compte['id']]);

        /* Quelqu'un demande une réinitialisation parce qu'il a perdu son
           mot de passe — ou parce que son compte lui a échappé. Dans les
           deux cas, toutes les sessions ouvertes ailleurs doivent tomber :
           l'ancienne version ne supprimait que les jetons « se souvenir de
           moi », laissant une session PHP volée parfaitement utilisable. */
        invalider_sessions((int) $compte['id']);
        journal_securite('mot_de_passe_reinitialise', ['utilisateur' => (int) $compte['id']]);
        consommer_jeton_action((int) $compte['jeton_id']); // le lien ne doit plus jamais resservir
        $reussi = true;
    }
}

$csrf = jeton_csrf();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Nouveau mot de passe — Ma Bibliothèque Manga</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%93%9A%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= e(actif('css/style.css')) ?>">
</head>
<body class="auth-body">

<main class="auth-wrap">
  <section class="auth-card">
    <div class="auth-head">
      <p class="auth-logo" aria-hidden="true">📚</p>
      <h1>Nouveau mot de passe</h1>
    </div>

    <?php if (!$compte): ?>
      <div class="alert alert-error" role="alert">
        Ce lien est invalide, a expiré, ou a déjà été utilisé.
      </div>
      <p class="auth-switch"><a href="mot-de-passe-oublie.php">Demander un nouveau lien</a></p>

    <?php elseif ($reussi): ?>
      <div class="alert alert-info">Mot de passe modifié. Vous pouvez vous connecter.</div>
      <p class="auth-switch"><a href="connexion.php">Se connecter</a></p>

    <?php else: ?>
      <?php if ($erreurs): ?>
        <div class="alert alert-error" role="alert">
          <ul>
            <?php foreach ($erreurs as $msg): ?>
              <li><?= e($msg) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="post" action="reinitialiser-mot-de-passe.php" autocomplete="on" novalidate>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="jeton" value="<?= e($jeton) ?>">

        <div class="field">
          <label for="mot_de_passe">Nouveau mot de passe</label>
          <div class="password-wrap">
            <input id="mot_de_passe" name="mot_de_passe" type="password" required
                   autocomplete="new-password" minlength="<?= MDP_MIN ?>" maxlength="<?= MDP_MAX ?>" placeholder="<?= MDP_MIN ?> caractères minimum">
            <button type="button" class="icon-btn toggle-password" data-cible="mot_de_passe"
                    aria-label="Afficher le mot de passe">👁️</button>
          </div>
        </div>

        <div class="field">
          <label for="confirmation">Confirmer le mot de passe</label>
          <div class="password-wrap">
            <input id="confirmation" name="confirmation" type="password" required
                   autocomplete="new-password" minlength="<?= MDP_MIN ?>" maxlength="<?= MDP_MAX ?>">
            <button type="button" class="icon-btn toggle-password" data-cible="confirmation"
                    aria-label="Afficher le mot de passe">👁️</button>
          </div>
          <p class="hint"><?= e(MDP_REGLE) ?></p>
        </div>

        <button type="submit" class="btn btn-primary full">Changer le mot de passe</button>
      </form>
    <?php endif; ?>
  </section>
</main>

<script src="<?= e(actif('js/auth.js')) ?>" defer></script>
</body>
</html>
