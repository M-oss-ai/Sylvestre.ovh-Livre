<?php
/* =====================================================================
   Confirmation d'une adresse e-mail. Deux types de jeton :
     - « verification »      : l'adresse du compte, à l'inscription ;
     - « changement_email » : une NOUVELLE adresse demandée depuis les
       paramètres. Elle n'est écrite dans le compte qu'ici : tant que ce
       lien n'est pas suivi, l'ancienne reste la clé de récupération.

   Le lien reçu par e-mail ne fait qu'AFFICHER un bouton ; c'est ce
   bouton qui valide, en POST. Les antivirus de messagerie (Outlook Safe
   Links, Proofpoint…) visitent les URL des e-mails avant leur
   destinataire : avec une validation sur simple GET, le jeton était
   consommé par le robot, et l'utilisateur lisait « lien invalide » sur
   une confirmation qui avait pourtant réussi.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/fonctions.php';

$jeton = (string) ($_GET['jeton'] ?? $_POST['jeton'] ?? '');

/* Un jeton valide, et de quel type ? On regarde sans rien consommer. */
$compte = null;
$type   = '';
foreach (['verification', 'changement_email'] as $candidat) {
    if ($trouve = jeton_action_valide($jeton, $candidat)) {
        $compte = $trouve;
        $type   = $candidat;
        break;
    }
}

$titre   = 'Lien invalide';
$message = 'Ce lien de confirmation est invalide, a expiré, ou a déjà été utilisé.';
$a_confirmer = false;

if (!$compte) {
    // rien à faire : le message par défaut convient

} elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    /* Premier affichage : on montre le bouton, on ne touche à rien. */
    $a_confirmer = true;
    $titre   = $type === 'changement_email'
        ? "Confirmer votre nouvelle adresse"
        : 'Confirmer votre adresse e-mail';
    $message = $type === 'changement_email'
        ? 'Votre compte utilisera cette adresse après confirmation.'
        : 'Un clic, et votre compte est actif.';

} else {
    exiger_csrf();

    if ($type === 'verification') {
        $pdo->prepare('UPDATE utilisateur SET email_verifie = 1 WHERE id = ?')
            ->execute([(int) $compte['id']]);
        consommer_jeton_action((int) $compte['jeton_id']);
        $titre   = 'E-mail confirmé ✅';
        $message = 'Votre adresse e-mail est confirmée. Vous pouvez vous connecter.';

    } else {
        $nouvelle = (string) ($compte['donnee'] ?? '');

        // L'adresse a pu être prise entre la demande et le clic : on
        // revérifie plutôt que de heurter la contrainte d'unicité.
        $req = $pdo->prepare('SELECT id FROM utilisateur WHERE email = ? AND id <> ?');
        $req->execute([$nouvelle, (int) $compte['id']]);

        consommer_jeton_action((int) $compte['jeton_id']);

        if (!filter_var($nouvelle, FILTER_VALIDATE_EMAIL)) {
            $titre   = 'Lien invalide';
            $message = "Ce lien ne contient pas d'adresse exploitable. Refaites la demande depuis vos paramètres.";
        } elseif ($req->fetch()) {
            $titre   = 'Adresse déjà utilisée';
            $message = 'Un autre compte utilise désormais cette adresse. Votre adresse actuelle reste inchangée.';
        } else {
            $pdo->prepare('UPDATE utilisateur SET email = ?, email_verifie = 1 WHERE id = ?')
                ->execute([$nouvelle, (int) $compte['id']]);
            journal_securite('email_change', ['utilisateur' => (int) $compte['id']]);
            $titre   = 'Adresse e-mail modifiée ✅';
            $message = 'Votre compte utilise désormais cette adresse, et elle est confirmée.';
        }
    }
}

$csrf = jeton_csrf();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= e($titre) ?> — Ma Bibliothèque Manga</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%93%9A%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= e(actif('css/style.css')) ?>">
</head>
<body class="auth-body">

<main class="auth-wrap">
  <section class="auth-card">
    <div class="auth-head">
      <p class="auth-logo" aria-hidden="true">📚</p>
      <h1><?= e($titre) ?></h1>
      <p class="hint"><?= e($message) ?></p>
    </div>

    <?php if ($a_confirmer): ?>
      <form method="post" action="verifier-email.php">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="jeton" value="<?= e($jeton) ?>">
        <button type="submit" class="btn btn-primary full">Confirmer mon adresse</button>
      </form>
    <?php endif; ?>

    <p class="auth-switch">
      <a href="<?= utilisateur_actuel() ? 'index.php' : 'connexion.php' ?>">Retour à l'application</a>
    </p>
  </section>
</main>

</body>
</html>
