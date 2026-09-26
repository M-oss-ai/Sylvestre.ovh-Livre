<?php
/* =====================================================================
   Connexion

   Un compte dont l'adresse n'a pas été confirmée ne peut pas se
   connecter. Le message qui le dit n'apparaît QUE si le mot de passe
   est bon : quelqu'un qui connaît déjà le mot de passe n'apprend rien
   de nouveau en lisant « confirmez votre adresse », alors qu'afficher
   cette phrase sur un mot de passe faux révélerait l'existence du compte.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/fonctions.php';

if (utilisateur_actuel()) {
    header('Location: index.php');
    exit;
}

/* Deux limiteurs, pas un seul.
   Par IP : arrête un attaquant unique qui essaie beaucoup.
   Par compte : arrête une attaque distribuée, qu'un compteur par IP ne
   voit pas (une tentative par machine, mille machines). Le second est
   plus tolérant, pour qu'on ne puisse pas verrouiller le compte d'un
   tiers à volonté — il ralentit, il n'interdit pas.
   Les seuils et les durées se règlent dans le .env (voir config.php). */

$erreur      = '';
$info        = '';
/* Secondes restantes avant de pouvoir réessayer. Séparé du message :
   le gabarit en fait un compte à rebours, et le serveur le recalcule à
   chaque affichage — un rechargement au milieu d'une attente reprend
   donc au bon chiffre. */
$attente     = 0;
$identifiant = '';
/* Vrai quand les identifiants sont bons mais l'adresse non confirmée :
   c'est ce qui autorise l'affichage du bouton « renvoyer le lien ». */
$a_confirmer = false;

if (isset($_GET['deconnecte'])) {
    $info = 'Vous êtes déconnecté. À bientôt !';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_csrf();

    /* --- Renvoi du lien de confirmation ---
       L'identifiant du compte concerné vient de la SESSION, déposée lors
       d'une connexion réussie mais non confirmée — jamais d'un champ du
       formulaire. Sinon n'importe qui pourrait faire renvoyer des
       e-mails au compte de son choix. */
    if (($_POST['renvoyer'] ?? '') === '1') {
        $id = (int) ($_SESSION['verification_en_attente'] ?? 0);
        $bloque = limiteur_bloque_depuis('verif_renvoi');

        if ($id <= 0) {
            $erreur = 'Reconnectez-vous pour demander un nouveau lien.';
        } elseif ($bloque > 0) {
            $erreur = 'Trop de demandes. Réessayez dans';
            $attente = $bloque;
        } else {
            limiteur_echec('verif_renvoi', VERIF_RENVOI_MAX, VERIF_RENVOI_BLOCAGE);

            $req = $pdo->prepare('SELECT identifiant, email, email_verifie FROM utilisateur WHERE id = ?');
            $req->execute([$id]);
            $u = $req->fetch();

            if ($u && (int) $u['email_verifie'] === 0) {
                $jeton = generer_jeton_action($id, 'verification', 86400);
                envoyer_email(
                    $u['email'],
                    'Confirmez votre adresse e-mail',
                    "Bonjour {$u['identifiant']},\n\nConfirmez votre adresse e-mail en cliquant sur ce "
                    . "lien (valable 24 h) :\n" . url_publique('verifier-email.php?jeton=' . $jeton)
                );
            }
            $info = "Si ce compte attend une confirmation, un nouveau lien vient d'être envoyé.";
        }

    } else {
        $identifiant = texte($_POST['identifiant'] ?? '', 190);
        $mdp         = (string) ($_POST['mot_de_passe'] ?? '');

        $bloque = limiteur_bloque_depuis('connexion');

        if ($bloque > 0) {
            $erreur = 'Trop de tentatives. Réessayez dans';
            $attente = $bloque;
        } elseif ($identifiant === '' || $mdp === '') {
            $erreur = 'Veuillez remplir les deux champs.';
        } else {
            // On accepte l'identifiant OU l'e-mail
            $req = $pdo->prepare(
                'SELECT id, identifiant, email, mot_de_passe, email_verifie
                   FROM utilisateur WHERE identifiant = ? OR email = ? LIMIT 1'
            );
            $req->execute([$identifiant, $identifiant]);
            $u = $req->fetch();

            /* Le compte visé est-il déjà ralenti ? On le vérifie avant
               password_verify(), qui coûte ~60 ms de processeur. */
            $bloque_compte = $u ? limiteur_bloque_depuis('connexion_compte', 'compte:' . (int) $u['id']) : 0;

            if ($bloque_compte > 0) {
                $erreur = 'Trop de tentatives sur ce compte. Réessayez dans';
                $attente = $bloque_compte;
                journal_securite('connexion_bloquee_compte', ['utilisateur' => (int) $u['id']]);
            } elseif ($u && password_verify($mdp, $u['mot_de_passe'])) {
                // Ré-hachage si PHP a changé d'algorithme ou de coût entre-temps
                if (password_needs_rehash($u['mot_de_passe'], PASSWORD_DEFAULT)) {
                    $maj = $pdo->prepare('UPDATE utilisateur SET mot_de_passe = ? WHERE id = ?');
                    $maj->execute([password_hash($mdp, PASSWORD_DEFAULT), (int) $u['id']]);
                }

                if ((int) $u['email_verifie'] === 0) {
                    /* Mot de passe correct, adresse non confirmée. On ne
                       connecte pas, mais on mémorise de quel compte il
                       s'agit pour pouvoir lui renvoyer un lien. */
                    $_SESSION['verification_en_attente'] = (int) $u['id'];
                    $a_confirmer = true;
                    $erreur = "Votre adresse e-mail n'a pas encore été confirmée. "
                            . 'Ouvrez le lien reçu par e-mail pour activer votre compte.';
                } else {
                    // Aucune remise à zéro du compteur sur un succès, même sur ce
                    // compte : quiconque possède un compte valide pourrait sinon se
                    // fabriquer un reset à volonté (un échec exprès sur son propre
                    // compte juste avant d'y réussir) pour effacer des tentatives
                    // sur un AUTRE compte au même moment. Seule la fenêtre glissante
                    // de limiteur_echec() fait redescendre le compteur, avec le temps.
                    unset($_SESSION['verification_en_attente']);
                    connecter((int) $u['id']);
                    journal_securite('connexion_reussie', ['utilisateur' => (int) $u['id']]);
                    header('Location: index.php');
                    exit;
                }
            } else {
                /* Compte inexistant : on brûle quand même un tour de bcrypt,
                   pour que le temps de réponse ne trahisse pas son absence.
                   password_hash() fait le même travail que le
                   password_verify() du cas « compte existant » (1,8 % d'écart
                   mesuré) et suit le coût par défaut de la version de PHP.

                   L'ancien leurre était un hachage écrit en dur de coût 12
                   face à un PASSWORD_DEFAULT de coût 10 : un compte
                   inexistant répondait en 228 ms contre 60 ms. La parade
                   fabriquait l'écart qu'elle devait effacer. */
                if (!$u) {
                    password_hash($mdp, PASSWORD_DEFAULT);
                }

                limiteur_echec('connexion', CONNEXION_MAX_ESSAIS, CONNEXION_BLOCAGE);
                if ($u) {
                    limiteur_echec(
                        'connexion_compte',
                        CONNEXION_MAX_ESSAIS_COMPTE,
                        CONNEXION_BLOCAGE_COMPTE,
                        'compte:' . (int) $u['id']
                    );
                }

                // On note l'identifiant saisi, jamais le mot de passe essayé.
                journal_securite('connexion_echouee', ['saisie' => $identifiant]);

                $reste   = limiteur_bloque_depuis('connexion');
                $attente = $reste;
                $erreur  = $reste > 0
                    ? 'Trop de tentatives. Réessayez dans'
                    : 'Identifiant ou mot de passe incorrect.';
            }
        }
    }
}

/* Le blocage est relu à CHAQUE affichage, pas seulement après un envoi
   de formulaire. Sans cela, recharger la page effacerait le compte à
   rebours alors que l'attente, elle, court toujours — et l'utilisateur
   croirait pouvoir réessayer. C'est aussi ce qui fait qu'un
   rechargement reprend au bon chiffre : le serveur recalcule, rien
   n'est mémorisé côté navigateur. */
if ($attente === 0 && $erreur === '') {
    $attente = limiteur_bloque_depuis('connexion');
    if ($attente > 0) {
        $erreur = 'Trop de tentatives. Réessayez dans';
    }
}

$csrf = jeton_csrf();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Connexion — Ma Bibliothèque Manga</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%93%9A%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= e(actif('css/style.css')) ?>">
</head>
<body class="auth-body">

<main class="auth-wrap">
  <section class="auth-card">
    <div class="auth-head">
      <p class="auth-logo" aria-hidden="true">📚</p>
      <h1>Ma Bibliothèque</h1>
      <p class="hint">Connectez-vous pour retrouver vos séries.</p>
    </div>

    <?php if ($info): ?>
      <div class="alert alert-info"><?= e($info) ?></div>
    <?php endif; ?>

    <?php if ($erreur): ?>
      <div class="alert alert-error" role="alert" id="connexion-erreur">
        <?= e($erreur) ?><?php if ($attente > 0): ?> <b class="delai" data-restant="<?= (int) $attente ?>"><?= (int) $attente ?> secondes</b>.<?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($a_confirmer): ?>
      <form method="post" action="connexion.php">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="renvoyer" value="1">
        <button type="submit" class="btn btn-ghost full">📬 Renvoyer le lien de confirmation</button>
      </form>
      <div class="settings-divider"></div>
    <?php endif; ?>

    <form method="post" action="connexion.php" autocomplete="on" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

      <!-- L'erreur de connexion ne désigne volontairement aucun des deux
           champs (dire lequel est faux aiderait qui devine des comptes) :
           elle est donc reliée aux deux. Le focus va au mot de passe quand
           l'identifiant est déjà rempli — c'est lui qu'on retape. -->
      <?php $decrit = $erreur ? ' aria-describedby="connexion-erreur"' : ''; ?>
      <div class="field">
        <label for="identifiant">Identifiant ou e-mail</label>
        <input id="identifiant" name="identifiant" type="text" required autocomplete="username"
               value="<?= e($identifiant) ?>"<?= $decrit ?><?= $identifiant === '' ? ' autofocus' : '' ?>>
      </div>

      <div class="field">
        <label for="mot_de_passe">Mot de passe</label>
        <div class="password-wrap">
          <input id="mot_de_passe" name="mot_de_passe" type="password" required autocomplete="current-password"<?= $decrit ?><?= $identifiant !== '' ? ' autofocus' : '' ?>>
          <button type="button" class="icon-btn toggle-password" data-cible="mot_de_passe"
                  aria-label="Afficher le mot de passe">👁️</button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary full">Se connecter</button>
    </form>

    <p class="auth-switch"><a href="mot-de-passe-oublie.php">Mot de passe oublié ?</a></p>
    <p class="auth-switch">Pas encore de compte ? <a href="inscription.php">Créer un compte</a></p>
    <p class="auth-legal"><a href="mentions-legales.php">Mentions légales et confidentialité</a></p>
  </section>
</main>

<script src="<?= e(actif('js/delai.js')) ?>" defer></script>
<script src="<?= e(actif('js/auth.js')) ?>" defer></script>
</body>
</html>
