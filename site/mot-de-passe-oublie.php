<?php
/* =====================================================================
   Mot de passe oublié : demande d'un lien de réinitialisation par e-mail.
   On se désigne par son identifiant OU son adresse, comme à la connexion ;
   le lien part toujours à l'adresse du compte, jamais à ce qui a été tapé.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/fonctions.php';

/* Ouverte aussi à une personne connectée : les Paramètres y mènent quand
   la fenêtre de confirmation demande un mot de passe oublié. Rien n'est
   donné de plus : le lien part à l'adresse saisie, comme pour quiconque,
   et c'est lui qui prouve qu'on la détient. On pré-remplit seulement la
   sienne. */
$moi = utilisateur_actuel();

$info    = '';
$erreur  = '';
$erreurs = [];   // par champ : l'erreur de saisie va sous « compte »
$attente = 0;
$saisie  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_csrf();

    $saisie = texte($_POST['compte'] ?? '', 190);
    $bloque = limiteur_bloque_depuis('mdp_oublie');

    if ($bloque > 0) {
        $erreur = 'Trop de demandes. Réessayez dans';
        $attente = $bloque;
    } elseif ($saisie === '') {
        $erreurs['compte'] = MESSAGE_CHAMP_OBLIGATOIRE;
    } elseif (str_contains($saisie, '@') && !filter_var($saisie, FILTER_VALIDATE_EMAIL)) {
        $erreurs['compte'] = 'Adresse e-mail invalide.';
    } else {
        limiteur_echec('mdp_oublie', MDP_OUBLIE_MAX, MDP_OUBLIE_BLOCAGE);

        /* Un identifiant ne peut pas contenir « @ » (forme_identifiant) :
           la saisie dit d'elle-même quelle colonne interroger. */
        $colonne = str_contains($saisie, '@') ? 'email' : 'identifiant';
        $req = $pdo->prepare(
            "SELECT id, identifiant, email, google_sub, (mot_de_passe <> '') AS a_mdp
               FROM utilisateur WHERE $colonne = ?"
        );
        $req->execute([$saisie]);
        $u = $req->fetch();

        if ($u) {
            /* Un compte Google sans mot de passe ne reçoit pas de lien : il
               n'a rien à réinitialiser, et en définir un demande de se
               reconnecter avec Google (Paramètres › Sécurité). */
            $acces = acces_compte($u['google_sub'], (int) $u['a_mdp'] === 1);
            $lien  = $acces === 'google' ? null
                : url_publique('reinitialiser-mot-de-passe.php?jeton='
                    . generer_jeton_action((int) $u['id'], 'reinit', 3600)); // 1 h
            [$sujet, $corps] = avis_mot_de_passe_oublie((string) $u['identifiant'], $acces, $lien);
            envoyer_email((string) $u['email'], $sujet, $corps);
        }

        // Même message que le compte existe ou non : on ne révèle jamais
        // quels identifiants ni quelles adresses sont enregistrés.
        $info = "Si ce compte existe, un e-mail de réinitialisation vient d'être envoyé à son adresse.";
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
      <p class="hint">Indiquez votre identifiant ou votre e-mail pour recevoir un lien de réinitialisation.</p>
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
        <label for="compte">Identifiant ou e-mail</label>
        <input id="compte" name="compte" type="text" required autocomplete="username"
               value="<?= e($saisie !== '' ? $saisie : ($moi ? (string) $moi['identifiant'] : '')) ?>"
               <?= $erreurs ? champ_aria($erreurs, 'compte', 'compte') : 'autofocus' ?>>
        <?= champ_erreur($erreurs, 'compte', 'compte') ?>
      </div>

      <button type="submit" class="btn btn-primary full">Envoyer le lien</button>
    </form>
    <?php endif; ?>

    <?php if ($moi): ?>
      <p class="auth-switch"><a href="parametres.php">Retour aux paramètres</a></p>
    <?php else: ?>
      <p class="auth-switch"><a href="connexion.php">Retour à la connexion</a></p>
    <?php endif; ?>
    <p class="auth-legal"><a href="mentions-legales.php">Mentions légales et confidentialité</a></p>
  </section>
</main>

<script src="<?= e(actif('js/double-appui.js')) ?>" defer></script>
<script src="<?= e(actif('js/delai.js')) ?>" defer></script>

</body>
</html>
