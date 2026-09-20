<?php
/* =====================================================================
   Création d'un compte.

   L'adresse e-mail doit être confirmée avant la première connexion.
   Conséquence volontaire : la page répond EXACTEMENT la même chose que
   l'adresse soit déjà prise ou non, et ne connecte jamais directement.
   Sans ça, il suffisait de saisir l'adresse de quelqu'un pour savoir
   s'il avait un compte ici — de quoi préparer un hameçonnage crédible.

   L'identifiant, lui, fait l'objet d'un message explicite : il faut bien
   pouvoir en choisir un autre, et il ne révèle aucune adresse.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/fonctions.php';

if (utilisateur_actuel()) {
    header('Location: index.php');
    exit;
}

$erreurs = [];
$envoye  = false;
$attente = 0;
$valeurs = ['identifiant' => '', 'email' => '', 'prenom' => '', 'nom' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_csrf();

    $bloque = limiteur_bloque_depuis('inscription');
    if ($bloque > 0) {
        $attente = $bloque;   // affiché en compte à rebours par le gabarit
    } else {
        // Chaque tentative compte (échec ou réussite) : c'est le nombre de
        // comptes créés rapidement depuis une même adresse qu'on limite.
        limiteur_echec('inscription', INSCRIPTION_MAX, INSCRIPTION_BLOCAGE);
    }

    $valeurs['identifiant'] = texte($_POST['identifiant'] ?? '', 50);
    $valeurs['email']       = texte($_POST['email'] ?? '', 190);
    $valeurs['prenom']      = texte($_POST['prenom'] ?? '', 80);
    $valeurs['nom']         = texte($_POST['nom'] ?? '', 80);
    $mdp                    = (string) ($_POST['mot_de_passe'] ?? '');
    $mdp2                   = (string) ($_POST['confirmation'] ?? '');

    if ($bloque === 0) {
        if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $valeurs['identifiant'])) {
            $erreurs[] = "L'identifiant doit faire 3 à 30 caractères (lettres, chiffres, . _ -).";
        }
        if (!filter_var($valeurs['email'], FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = "L'adresse e-mail n'est pas valide.";
        }
        $erreurs = array_merge($erreurs, valider_mot_de_passe($mdp, $valeurs['identifiant']));
        if ($mdp !== $mdp2) {
            $erreurs[] = 'Les deux mots de passe ne correspondent pas.';
        }
    }

    if (!$erreurs) {
        $nb_utilisateurs = (int) $pdo->query('SELECT COUNT(*) FROM utilisateur')->fetchColumn();
        if ($nb_utilisateurs >= MAX_UTILISATEURS) {
            $erreurs[] = 'Le nombre maximum de comptes (' . MAX_UTILISATEURS . ') a été atteint. '
                . 'Merci de contacter l\'administrateur à ' . ADMIN_EMAIL . '.';
        }
    }

    /* L'identifiant est vérifié à part, et signalé : c'est une donnée
       publique au sein du site, et l'utilisateur doit pouvoir en choisir
       un autre. Cette vérification ne révèle aucune adresse e-mail. */
    if (!$erreurs) {
        $req = $pdo->prepare('SELECT id FROM utilisateur WHERE identifiant = ?');
        $req->execute([$valeurs['identifiant']]);
        if ($req->fetch()) {
            $erreurs[] = 'Cet identifiant est déjà pris, choisissez-en un autre.';
        }
    }

    /* À partir d'ici, quoi qu'il arrive, l'utilisateur voit le même écran
       et n'est jamais connecté : c'est ce qui rend les deux cas
       (adresse libre / adresse déjà inscrite) indiscernables. */
    if (!$erreurs) {
        $req = $pdo->prepare('SELECT id, identifiant FROM utilisateur WHERE email = ?');
        $req->execute([$valeurs['email']]);
        $existant = $req->fetch();

        if ($existant) {
            // On ne crée rien, et on prévient le propriétaire légitime :
            // c'est lui, et lui seul, qui apprend qu'on a tenté quelque
            // chose avec son adresse.
            envoyer_email(
                $valeurs['email'],
                'Tentative de création de compte avec votre adresse',
                "Bonjour {$existant['identifiant']},\n\n"
                . "Quelqu'un vient d'essayer de créer un compte Ma Bibliothèque Manga avec votre "
                . "adresse e-mail. Comme un compte existe déjà, rien n'a été créé et rien n'a changé.\n\n"
                . "Si c'était vous : connectez-vous simplement avec votre compte existant.\n"
                . url_publique('connexion.php') . "\n"
                . "Mot de passe oublié ?\n"
                . url_publique('mot-de-passe-oublie.php') . "\n\n"
                . "Si ce n'était pas vous, vous n'avez rien à faire : votre compte n'a pas été touché."
            );
        } else {
            try {
                /* Le quota de comptes est appliqué par l'insertion, pour la
                   même raison que celui des séries : un COUNT séparé se
                   fait doubler par deux inscriptions simultanées. */
                $req = $pdo->prepare(
                    'INSERT INTO utilisateur (identifiant, email, mot_de_passe, prenom, nom, email_verifie)
                     SELECT ?, ?, ?, ?, ?, 0
                       FROM DUAL
                      WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM utilisateur) AS c) < ?'
                );
                $req->execute([
                    $valeurs['identifiant'],
                    $valeurs['email'],
                    password_hash($mdp, PASSWORD_DEFAULT),   // jamais de mot de passe en clair en base
                    $valeurs['prenom'],
                    $valeurs['nom'],
                    MAX_UTILISATEURS,
                ]);

                // Quota atteint entre la vérification et l'écriture : on
                // reste sur l'écran neutre, comme dans tous les autres cas.
                if ($req->rowCount() === 0) {
                    throw new RuntimeException('quota de comptes atteint');
                }

                $id = (int) $pdo->lastInsertId();

                $jeton = generer_jeton_action($id, 'verification', 86400); // 24 h
                envoyer_email(
                    $valeurs['email'],
                    'Confirmez votre adresse e-mail',
                    "Bonjour {$valeurs['identifiant']},\n\n"
                    . "Merci de votre inscription à Ma Bibliothèque Manga. Confirmez votre adresse "
                    . "e-mail en cliquant sur ce lien (valable 24 h) — c'est nécessaire pour vous "
                    . "connecter :\n"
                    . url_publique('verifier-email.php?jeton=' . $jeton) . "\n\n"
                    . "Si vous n'êtes pas à l'origine de cette inscription, ignorez ce message."
                );
            } catch (Throwable $e) {
                // Collision au dernier moment (deux inscriptions simultanées
                // sur la même adresse). On reste sur le message neutre :
                // signaler l'erreur ici rouvrirait exactement la fuite que
                // toute cette page cherche à fermer.
                error_log('inscription: ' . $e->getMessage());
            }
        }

        $envoye = true;
    }
}

/* Le blocage est relu à CHAQUE affichage, pas seulement après un envoi
   de formulaire. Sans cela, recharger la page effacerait le compte à
   rebours alors que l'attente, elle, court toujours — et l'utilisateur
   croirait pouvoir réessayer. C'est aussi ce qui fait qu'un
   rechargement reprend au bon chiffre : le serveur recalcule, rien
   n'est mémorisé côté navigateur. */
if ($attente === 0 && !$envoye) {
    $attente = limiteur_bloque_depuis('inscription');
}

$csrf = jeton_csrf();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Créer un compte — Ma Bibliothèque Manga</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%93%9A%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= e(actif('css/style.css')) ?>">
</head>
<body class="auth-body">

<main class="auth-wrap">
  <section class="auth-card">

  <?php if ($envoye): ?>

    <div class="auth-head">
      <p class="auth-logo" aria-hidden="true">📬</p>
      <h1>Vérifiez vos e-mails</h1>
      <p class="hint">
        Un message vient d'être envoyé à <b><?= e($valeurs['email']) ?></b>.
        Cliquez sur le lien qu'il contient pour activer votre compte, puis connectez-vous.
      </p>
    </div>
    <p class="hint">
      Le lien est valable 24 heures. Pensez à regarder dans vos courriers indésirables.
    </p>
    <p class="auth-switch"><a href="connexion.php">Aller à la connexion</a></p>

  <?php else: ?>

    <div class="auth-head">
      <p class="auth-logo" aria-hidden="true">📚</p>
      <h1>Créer un compte</h1>
      <p class="hint">Votre bibliothèque vous suit d'un appareil à l'autre.</p>
    </div>

    <?php if ($erreurs || $attente > 0): ?>
      <div class="alert alert-error" role="alert">
        <ul>
          <?php if ($attente > 0): ?>
            <li>Trop de tentatives depuis cette adresse. Réessayez dans <b class="delai" data-restant="<?= (int) $attente ?>"><?= (int) $attente ?> secondes</b>.</li>
          <?php endif; ?>
          <?php foreach ($erreurs as $msg): ?>
            <li><?= e($msg) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" action="inscription.php" autocomplete="on" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

      <div class="field-row">
        <div class="field">
          <label for="prenom">Prénom</label>
          <input id="prenom" name="prenom" type="text" autocomplete="given-name" value="<?= e($valeurs['prenom']) ?>">
        </div>
        <div class="field">
          <label for="nom">Nom</label>
          <input id="nom" name="nom" type="text" autocomplete="family-name" value="<?= e($valeurs['nom']) ?>">
        </div>
      </div>

      <div class="field">
        <label for="identifiant">Identifiant *</label>
        <input id="identifiant" name="identifiant" type="text" required autocomplete="username"
               placeholder="Ex. lecteur-manga" value="<?= e($valeurs['identifiant']) ?>">
      </div>

      <div class="field">
        <label for="email">E-mail *</label>
        <input id="email" name="email" type="email" required autocomplete="email"
               placeholder="vous@exemple.com" value="<?= e($valeurs['email']) ?>">
        <p class="hint">Vous recevrez un lien de confirmation : il faut le suivre pour activer le compte.</p>
      </div>

      <div class="field">
        <label for="mot_de_passe">Mot de passe *</label>
        <div class="password-wrap">
          <input id="mot_de_passe" name="mot_de_passe" type="password" required
                 autocomplete="new-password" minlength="<?= MDP_MIN ?>" maxlength="<?= MDP_MAX ?>" placeholder="<?= MDP_MIN ?> caractères minimum">
          <button type="button" class="icon-btn toggle-password" data-cible="mot_de_passe"
                  aria-label="Afficher le mot de passe">👁️</button>
        </div>
      </div>

      <div class="field">
        <label for="confirmation">Confirmer le mot de passe *</label>
        <div class="password-wrap">
          <input id="confirmation" name="confirmation" type="password" required
                 autocomplete="new-password" minlength="<?= MDP_MIN ?>" maxlength="<?= MDP_MAX ?>" placeholder="••••••••">
          <button type="button" class="icon-btn toggle-password" data-cible="confirmation"
                  aria-label="Afficher le mot de passe">👁️</button>
        </div>
        <p class="hint"><?= e(MDP_REGLE) ?></p>
      </div>

      <button type="submit" class="btn btn-primary full">Créer mon compte</button>
    </form>

    <p class="auth-switch">Déjà un compte ? <a href="connexion.php">Se connecter</a></p>
    <p class="auth-legal"><a href="mentions-legales.php">Mentions légales et confidentialité</a></p>

  <?php endif; ?>

  </section>
</main>

<script src="<?= e(actif('js/delai.js')) ?>" defer></script>
<script src="<?= e(actif('js/auth.js')) ?>" defer></script>
</body>
</html>
