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
require_once __DIR__ . '/includes/google.php';     // « Continuer avec Google »

if (utilisateur_actuel()) {
    header('Location: index.php');
    exit;
}

$erreurs = [];
$envoye  = false;
$attente = 0;
$valeurs = ['identifiant' => '', 'email' => ''];

/* On choisit d'abord COMMENT s'inscrire (Google ou adresse e-mail), puis
   on remplit le formulaire. Sans Google configuré, il n'y a rien à
   choisir : le formulaire s'affiche aussitôt. */
$avec_email = !google_actif() || ($_GET['avec'] ?? '') === 'email' || $_SERVER['REQUEST_METHOD'] === 'POST';

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
    $mdp                    = (string) ($_POST['mot_de_passe'] ?? '');
    $mdp2                   = (string) ($_POST['confirmation'] ?? '');

    /* Rangées par champ, dans l'ordre du formulaire : chaque message
       s'affiche sous son champ, et le premier champ fautif prend le focus
       (voir champ_aria). La clé '' porte ce qui ne tient à aucun champ.

       Bloqué, on s'ARRÊTE là. Les contrôles ci-dessous étaient sautés,
       mais la liste d'erreurs restait vide, si bien que la suite créait
       le compte quand même : sans aucune vérification (un mot de passe
       « x » passait) et sans rien décompter. Le limiteur ne limitait
       plus rien du tout. */
    if ($bloque > 0) {
        $erreurs[''] = '';   // le message est celui du compte à rebours, plus bas
    } else {
        /* Un champ vide : « Ce champ est obligatoire. », rien d'autre. Aucun
           « * » ne l'annonce d'avance. */
        if ($message = forme_identifiant($valeurs['identifiant'])) {
            $erreurs['identifiant'] = $message;
        }
        if ($message = forme_email($valeurs['email'])) {
            $erreurs['email'] = $message;
        }
        // Seules les règles NON respectées (valider_mot_de_passe).
        if ($faiblesses = valider_mot_de_passe($mdp, $valeurs['identifiant'])) {
            $erreurs['mot_de_passe'] = $faiblesses;
        }
        if ($mdp2 === '') {
            $erreurs['confirmation'] = MESSAGE_CHAMP_OBLIGATOIRE;
        } elseif ($mdp !== $mdp2) {
            $erreurs['confirmation'] = 'Les deux mots de passe ne correspondent pas.';
        }
    }

    if (!$erreurs) {
        $nb_utilisateurs = (int) $pdo->query('SELECT COUNT(*) FROM utilisateur')->fetchColumn();
        if ($nb_utilisateurs >= MAX_UTILISATEURS) {
            $erreurs[''] = 'Le nombre maximum de comptes (' . MAX_UTILISATEURS . ') a été atteint. '
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
            $erreurs['identifiant'] = 'Cet identifiant est déjà pris, choisissez-en un autre.';
        }
    }

    /* À partir d'ici, quoi qu'il arrive, l'utilisateur voit le même écran
       et n'est jamais connecté : c'est ce qui rend les deux cas
       (adresse libre / adresse déjà inscrite) indiscernables. */
    if (!$erreurs) {
        /* Une adresse que son titulaire peut encore rétablir (lien de
           blocage en cours de validité, voir reinitialiser-mot-de-passe.php)
           compte comme prise. Sinon l'attaquant, une fois son changement
           d'adresse confirmé, inscrirait un compte avec celle de sa victime :
           le lien de blocage heurterait alors la contrainte d'unicité, et
           l'adresse ne pourrait plus être rendue. */
        $req = $pdo->prepare(
            "SELECT id, identifiant, google_sub, (mot_de_passe <> '') AS a_mdp FROM utilisateur WHERE email = ?
             UNION
             SELECT u.id, u.identifiant, u.google_sub, (u.mot_de_passe <> '') AS a_mdp
               FROM jeton_action j
               JOIN utilisateur u ON u.id = j.utilisateur_id
              WHERE j.type = 'blocage_email' AND j.donnee = ? AND j.expire > NOW()
             LIMIT 1"
        );
        $req->execute([$valeurs['email'], $valeurs['email']]);
        $existant = $req->fetch();

        if ($existant) {
            // On ne crée rien, et on prévient le propriétaire légitime :
            // c'est lui, et lui seul, qui apprend qu'on a tenté quelque
            // chose avec son adresse.
            [$sujet, $corps] = avis_tentative_inscription(
                (string) $existant['identifiant'],
                acces_compte($existant['google_sub'], (int) $existant['a_mdp'] === 1)
            );
            envoyer_email($valeurs['email'], $sujet, $corps);
        } else {
            try {
                /* Le quota de comptes est appliqué par l'insertion, pour la
                   même raison que celui des séries : un COUNT séparé se
                   fait doubler par deux inscriptions simultanées. */
                $req = $pdo->prepare(
                    'INSERT INTO utilisateur (identifiant, email, mot_de_passe, email_verifie)
                     SELECT ?, ?, ?, 0
                       FROM DUAL
                      WHERE (SELECT n FROM (SELECT COUNT(*) AS n FROM utilisateur) AS c) < ?'
                );
                $req->execute([
                    $valeurs['identifiant'],
                    $valeurs['email'],
                    password_hash($mdp, PASSWORD_DEFAULT),   // jamais de mot de passe en clair en base
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

  <?php elseif (!$avec_email): ?>

    <!-- Étape 1 : COMMENT s'inscrire. Le formulaire ne vient qu'après. -->
    <nav class="auth-onglets" aria-label="Compte">
      <a href="connexion.php">Se connecter</a>
      <a href="inscription.php" aria-current="page">Créer un compte</a>
    </nav>
    <div class="auth-head">
      <p class="auth-logo" aria-hidden="true">✨</p>
      <h1>Créer un compte</h1>
      <p class="hint">Votre bibliothèque vous suit d'un appareil à l'autre.</p>
    </div>

    <div class="choix-methode">
      <?= bouton_google("S'inscrire avec Google") ?>
      <a class="btn btn-ghost full" href="inscription.php?avec=email">✉️ S'inscrire avec une adresse e-mail</a>
    </div>

    <p class="auth-legal"><a href="mentions-legales.php">Mentions légales et confidentialité</a></p>

  <?php else: ?>

    <div class="auth-head">
      <p class="auth-logo" aria-hidden="true">📚</p>
      <h1>Créer un compte</h1>
      <p class="hint">Votre bibliothèque vous suit d'un appareil à l'autre.</p>
    </div>

    <!-- Seules les erreurs qui ne tiennent à aucun champ restent ici ; les
         autres s'affichent sous leur champ. -->
    <?php if (($erreurs[''] ?? '') !== '' || $attente > 0): ?>
      <div class="alert alert-error" role="alert">
        <?php if ($attente > 0): ?>
          Trop de tentatives depuis cette adresse. Réessayez dans <b class="delai" data-restant="<?= (int) $attente ?>"><?= (int) $attente ?> secondes</b>.
        <?php endif; ?>
        <?= e($erreurs[''] ?? '') ?>
      </div>
    <?php endif; ?>

    <!-- Aucun « * » ni consigne d'avance : un champ oublié le dit à l'envoi
         (« Ce champ est obligatoire. »), le mot de passe ne cite que les
         règles qui manquent (js/mdp.js), et ce qui concerne le lien de
         confirmation s'affiche à l'étape suivante, quand il est parti. -->
    <form method="post" action="inscription.php" autocomplete="on" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

      <div class="field">
        <label for="identifiant">Identifiant</label>
        <input id="identifiant" name="identifiant" type="text" required autocomplete="username"
               placeholder="Ex. lecteur-manga" value="<?= e($valeurs['identifiant']) ?>"<?= champ_aria($erreurs, 'identifiant', 'identifiant') ?><?= $erreurs ? '' : ' autofocus' ?>>
        <?= champ_erreur($erreurs, 'identifiant', 'identifiant') ?>
      </div>

      <div class="field">
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" required autocomplete="email"
               placeholder="vous@exemple.com" value="<?= e($valeurs['email']) ?>"<?= champ_aria($erreurs, 'email', 'email') ?>>
        <?= champ_erreur($erreurs, 'email', 'email') ?>
      </div>

      <div class="field">
        <label for="mot_de_passe">Mot de passe</label>
        <div class="password-wrap">
          <input id="mot_de_passe" name="mot_de_passe" type="password" required
                 autocomplete="new-password" maxlength="<?= MDP_MAX ?>"
                 data-regles-mdp data-mdp-min="<?= MDP_MIN ?>" data-mdp-max="<?= MDP_MAX ?>" data-identifiant="identifiant"<?= champ_aria($erreurs, 'mot_de_passe', 'mot_de_passe') ?>>
          <button type="button" class="icon-btn toggle-password" data-cible="mot_de_passe"
                  aria-label="Afficher le mot de passe">👁️</button>
        </div>
        <?= champ_erreur($erreurs, 'mot_de_passe', 'mot_de_passe') ?>
      </div>

      <div class="field">
        <label for="confirmation">Confirmer le mot de passe</label>
        <div class="password-wrap">
          <input id="confirmation" name="confirmation" type="password" required
                 autocomplete="new-password" maxlength="<?= MDP_MAX ?>" placeholder="Retapez le mot de passe"<?= champ_aria($erreurs, 'confirmation', 'confirmation') ?>>
          <button type="button" class="icon-btn toggle-password" data-cible="confirmation"
                  aria-label="Afficher le mot de passe">👁️</button>
        </div>
        <?= champ_erreur($erreurs, 'confirmation', 'confirmation') ?>
      </div>

      <button type="submit" class="btn btn-primary full">Créer mon compte</button>
    </form>

    <?php if (google_actif()): ?>
      <p class="auth-switch"><a href="inscription.php">← Autres façons de s'inscrire</a></p>
    <?php endif; ?>
    <p class="auth-switch">Déjà un compte ? <a href="connexion.php">Se connecter</a></p>
    <p class="auth-legal"><a href="mentions-legales.php">Mentions légales et confidentialité</a></p>

  <?php endif; ?>

  </section>
</main>

<script src="<?= e(actif('js/delai.js')) ?>" defer></script>
<script src="<?= e(actif('js/auth.js')) ?>" defer></script>
<script src="<?= e(actif('js/mdp.js')) ?>" defer></script>
</body>
</html>
