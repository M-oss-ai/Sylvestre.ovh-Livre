<?php
/* =====================================================================
   Mot de passe oublié : demande d'un lien de réinitialisation par e-mail.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/fonctions.php';

if (utilisateur_actuel()) {
    header('Location: index.php');
    exit;
}

$info    = '';
$erreur  = '';
$attente = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_csrf();

    $email  = texte($_POST['email'] ?? '', 190);
    $bloque = limiteur_bloque_depuis('mdp_oublie');

    if ($bloque > 0) {
        $erreur = 'Trop de demandes. Réessayez dans';
        $attente = $bloque;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreur = "Adresse e-mail invalide.";
    } else {
        limiteur_echec('mdp_oublie', MDP_OUBLIE_MAX, MDP_OUBLIE_BLOCAGE);

        $req = $pdo->prepare('SELECT id, identifiant FROM utilisateur WHERE email = ?');
        $req->execute([$email]);
        $u = $req->fetch();

        if ($u) {
            $jeton = generer_jeton_action((int) $u['id'], 'reinit', 3600); // 1 h
            envoyer_email(
                $email,
                'Réinitialisation de votre mot de passe',
                "Bonjour {$u['identifiant']},\n\n"
                . "Une réinitialisation de mot de passe a été demandée pour ce compte. "
                . "Cliquez sur ce lien pour choisir un nouveau mot de passe (valable 1 heure) :\n"
                . url_publique('reinitialiser-mot-de-passe.php?jeton=' . $jeton) . "\n\n"
                . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : "
                . "rien ne sera changé sur votre compte."
            );
        }

        // Même message que le compte existe ou non : on ne révèle jamais
        // quelles adresses sont enregistrées.
        $info = "Si un compte existe avec cette adresse, un e-mail de réinitialisation vient d'être envoyé.";
    }
}

/* Le blocage est relu à CHAQUE affichage, pas seulement après un envoi
   de formulaire. Sans cela, recharger la page effacerait le compte à
   rebours alors que l'attente, elle, court toujours — et l'utilisateur
   croirait pouvoir réessayer. C'est aussi ce qui fait qu'un
   rechargement reprend au bon chiffre : le serveur recalcule, rien
   n'est mémorisé côté navigateur. */
if ($attente === 0 && $erreur === '' && $info === '') {
    $attente = limiteur_bloque_depuis('mdp_oublie');
    if ($attente > 0) {
        $erreur = 'Trop de demandes. Réessayez dans';
    }
}

$csrf = jeton_csrf();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Mot de passe oublié — Ma Bibliothèque Manga</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%93%9A%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= e(actif('css/style.css')) ?>">
</head>
<body class="auth-body">

<main class="auth-wrap">
  <section class="auth-card">
    <div class="auth-head">
      <p class="auth-logo" aria-hidden="true">📚</p>
      <h1>Mot de passe oublié</h1>
      <p class="hint">Indiquez votre e-mail pour recevoir un lien de réinitialisation.</p>
    </div>

    <?php if ($info): ?>
      <div class="alert alert-info"><?= e($info) ?></div>
    <?php endif; ?>

    <?php if ($erreur): ?>
      <div class="alert alert-error" role="alert">
        <?= e($erreur) ?><?php if ($attente > 0): ?> <b class="delai" data-restant="<?= (int) $attente ?>"><?= (int) $attente ?> secondes</b>.<?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!$info): ?>
    <form method="post" action="mot-de-passe-oublie.php" autocomplete="on" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

      <div class="field">
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" required autocomplete="email" autofocus>
      </div>

      <button type="submit" class="btn btn-primary full">Envoyer le lien</button>
    </form>
    <?php endif; ?>

    <p class="auth-switch"><a href="connexion.php">Retour à la connexion</a></p>
    <p class="auth-legal"><a href="mentions-legales.php">Mentions légales et confidentialité</a></p>
  </section>
</main>

<script src="<?= e(actif('js/delai.js')) ?>" defer></script>

</body>
</html>
