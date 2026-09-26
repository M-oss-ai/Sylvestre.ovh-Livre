<?php
/* =====================================================================
   Choix d'un nouveau mot de passe, depuis un lien reçu par e-mail.
   Deux types de jeton :
     - « reinit »        : « Mot de passe oublié » ;
     - « blocage_email » : l'alerte envoyée à l'ancienne adresse quand un
       changement d'adresse est demandé. Le même formulaire bloque alors
       ce changement — ou le défait s'il a déjà été confirmé — en plus de
       changer le mot de passe. Bloquer sans changer le mot de passe ne
       servirait à rien : l'attaquant le connaît, il recommencerait.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/fonctions.php';

$jeton  = (string) ($_GET['jeton'] ?? $_POST['jeton'] ?? '');
$compte = null;
$type   = '';
foreach (['reinit', 'blocage_email'] as $candidat) {
    if ($trouve = jeton_action_valide($jeton, $candidat)) {
        $compte = $trouve;
        $type   = $candidat;
        break;
    }
}
$bloquer = ($type === 'blocage_email');

/* Une session ouverte n'écarte pas le lien de blocage : la victime peut
   très bien être encore connectée, et c'est justement l'attaquant qu'on
   veut déconnecter. */
if (!$bloquer && utilisateur_actuel()) {
    header('Location: index.php');
    exit;
}

$erreurs      = [];
$reussi       = false;
$retablie     = '';   // l'adresse rendue au compte, s'il a fallu la rétablir
$non_retablie = '';   // celle qu'on n'a pas pu rétablir (prise entre-temps)

if ($compte && $_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_csrf();

    $mdp  = (string) ($_POST['mot_de_passe'] ?? '');
    $mdp2 = (string) ($_POST['confirmation'] ?? '');

    // Rangées par champ : chaque message s'affiche sous le sien.
    if ($faiblesses = valider_mot_de_passe($mdp, (string) $compte['identifiant'])) {
        $erreurs['mot_de_passe'] = $faiblesses;
    }
    if ($mdp !== $mdp2) {
        $erreurs['confirmation'] = 'Les deux mots de passe ne correspondent pas.';
    }

    if (!$erreurs && $bloquer) {
        $id       = (int) $compte['id'];
        $ancienne = (string) ($compte['donnee'] ?? '');

        /* Le changement a déjà été confirmé : l'adresse à laquelle
           l'alerte a été envoyée redevient celle du compte. Sans ça,
           l'attaquant n'aurait qu'à passer par « Mot de passe oublié »
           depuis sa boîte pour reprendre la main. */
        if (strcasecmp($ancienne, (string) $compte['email']) !== 0) {
            if (filter_var($ancienne, FILTER_VALIDATE_EMAIL) && email_disponible($ancienne, $id)) {
                $pdo->prepare('UPDATE utilisateur SET email = ? WHERE id = ?')->execute([$ancienne, $id]);
                $retablie = $ancienne;
            } else {
                $non_retablie = $ancienne;
            }
        }

        /* Tombent : le changement en attente, les réinitialisations que
           l'attaquant aurait demandées depuis sa boîte, et les liens de
           blocage PLUS RÉCENTS que celui-ci. Ceux-là, l'attaquant a pu
           les recevoir : un second changement fait depuis son adresse
           lui en envoie un, qui « rétablirait » la sienne. Les plus
           anciens restent : le lien le plus ancien a toujours le dernier
           mot, et c'est celui du titulaire d'origine. Celui-ci est
           consommé du même coup. */
        $pdo->prepare(
            "DELETE FROM jeton_action
              WHERE utilisateur_id = ?
                AND (type IN ('changement_email', 'reinit') OR (type = 'blocage_email' AND id >= ?))"
        )->execute([$id, (int) $compte['jeton_id']]);

        journal_securite('changement_email_bloque', [
            'utilisateur' => $id, 'retablie' => $retablie !== '' ? 'oui' : 'non',
        ]);
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
           moi », laissant une session PHP volée parfaitement utilisable.
           Un changement d'adresse en attente tombe avec elles. */
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
<title><?= $bloquer ? "Bloquer le changement d'adresse" : 'Nouveau mot de passe' ?> — Ma Bibliothèque Manga</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%93%9A%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= e(actif('css/style.css')) ?>">
</head>
<body class="auth-body">

<main class="auth-wrap">
  <section class="auth-card">
    <div class="auth-head">
      <p class="auth-logo" aria-hidden="true">📚</p>
      <h1><?= $bloquer ? "Bloquer le changement d'adresse" : 'Nouveau mot de passe' ?></h1>
      <?php if ($bloquer && !$reussi): ?>
        <p class="hint">Choisissez un nouveau mot de passe : le changement d'adresse sera annulé,
          même s'il a déjà été confirmé, le compte gardera l'adresse <?= e((string) $compte['donnee']) ?>,
          et tous les appareils connectés seront déconnectés.</p>
      <?php endif; ?>
    </div>

    <?php if (!$compte): ?>
      <div class="alert alert-error" role="alert">
        Ce lien est invalide, a expiré, ou a déjà été utilisé.
      </div>
      <p class="auth-switch"><a href="mot-de-passe-oublie.php">Demander un nouveau lien</a></p>

    <?php elseif ($reussi && $bloquer): ?>
      <div class="alert alert-info">
        Changement d'adresse bloqué, mot de passe modifié, et tous les appareils déconnectés.
        <?php if ($retablie !== ''): ?>
          Le changement avait déjà été confirmé : votre compte utilise de nouveau <?= e($retablie) ?>.
        <?php endif; ?>
        Votre identifiant est <b><?= e((string) $compte['identifiant']) ?></b>.
      </div>
      <?php if ($non_retablie !== ''): ?>
        <div class="alert alert-error" role="alert">
          <?= e($non_retablie) ?> est désormais utilisée par un autre compte : elle n'a pas pu être
          rendue au vôtre. Écrivez à <?= e(ADMIN_EMAIL) ?> pour la récupérer.
        </div>
      <?php endif; ?>
      <p class="auth-switch"><a href="connexion.php">Se connecter</a></p>

    <?php elseif ($reussi): ?>
      <div class="alert alert-info">Mot de passe modifié. Vous pouvez vous connecter.</div>
      <p class="auth-switch"><a href="connexion.php">Se connecter</a></p>

    <?php else: ?>
      <form method="post" action="reinitialiser-mot-de-passe.php" autocomplete="on" novalidate>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="jeton" value="<?= e($jeton) ?>">

        <div class="field">
          <label for="mot_de_passe">Nouveau mot de passe</label>
          <div class="password-wrap">
            <input id="mot_de_passe" name="mot_de_passe" type="password" required
                   autocomplete="new-password" minlength="<?= MDP_MIN ?>" maxlength="<?= MDP_MAX ?>" placeholder="<?= MDP_MIN ?> caractères minimum"<?= champ_aria($erreurs, 'mot_de_passe', 'mot_de_passe', 'mdp-regle') ?>>
            <button type="button" class="icon-btn toggle-password" data-cible="mot_de_passe"
                    aria-label="Afficher le mot de passe">👁️</button>
          </div>
          <?= champ_erreur($erreurs, 'mot_de_passe', 'mot_de_passe') ?>
        </div>

        <div class="field">
          <label for="confirmation">Confirmer le mot de passe</label>
          <div class="password-wrap">
            <input id="confirmation" name="confirmation" type="password" required
                   autocomplete="new-password" minlength="<?= MDP_MIN ?>" maxlength="<?= MDP_MAX ?>"<?= champ_aria($erreurs, 'confirmation', 'confirmation') ?>>
            <button type="button" class="icon-btn toggle-password" data-cible="confirmation"
                    aria-label="Afficher le mot de passe">👁️</button>
          </div>
          <?= champ_erreur($erreurs, 'confirmation', 'confirmation') ?>
          <p class="hint" id="mdp-regle"><?= e(MDP_REGLE) ?></p>
        </div>

        <button type="submit" class="btn btn-primary full"><?= $bloquer ? 'Bloquer et changer le mot de passe' : 'Changer le mot de passe' ?></button>
      </form>
    <?php endif; ?>
  </section>
</main>

<script src="<?= e(actif('js/delai.js')) ?>" defer></script>
<script src="<?= e(actif('js/auth.js')) ?>" defer></script>
</body>
</html>
