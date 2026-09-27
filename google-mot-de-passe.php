<?php
/* =====================================================================
   Seconde étape d'une connexion avec Google : le mot de passe.

   Un compte créé avec Google peut définir un mot de passe dans les
   Paramètres. Il lui est alors demandé ICI, après Google, à chaque
   connexion : Google prouve l'identité, le mot de passe est la seconde
   clé. google.php a rangé le compte dans la session (google_etape_mdp) ;
   rien n'est ouvert avant que le mot de passe soit bon.

   Mêmes freins que connexion.php : par adresse IP et par compte. Sans
   eux, qui tient le compte Google pourrait essayer des mots de passe
   sans fin.
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
header('Cache-Control: no-store, private');

if (isset($_GET['annuler'])) {
    unset($_SESSION['google_mdp']);
    header('Location: connexion.php');
    exit;
}

$id = google_etape_mdp($_SESSION['google_mdp'] ?? null, time());
$u  = null;
if ($id > 0) {
    $req = $pdo->prepare('SELECT id, identifiant, mot_de_passe FROM utilisateur WHERE id = ? AND google_sub IS NOT NULL');
    $req->execute([$id]);
    $u = $req->fetch() ?: null;
}
if (!$u) {
    unset($_SESSION['google_mdp']);
    flash('La connexion avec Google a expiré. Recommencez.', 'erreur');
    header('Location: connexion.php');
    exit;
}

/** Ouvre le compte et quitte la page : l'étape est franchie. */
function google_mdp_ouvrir(int $id): never
{
    $destination = (string) ($_SESSION['google_mdp']['destination'] ?? 'index.php');
    unset($_SESSION['google_mdp']);
    connecter($id);
    google_noter_confirmation($id);
    journal_securite('connexion_google', ['utilisateur' => $id]);
    header('Location: ' . $destination);   // valeur de la liste fermée de google.php
    exit;
}

/* Le mot de passe a pu être supprimé entre-temps (depuis un autre
   appareil) : il n'y a alors plus rien à demander. */
if ((string) $u['mot_de_passe'] === '') {
    google_mdp_ouvrir($id);
}

$erreurs = [];
$attente = 0;
$cle     = 'compte:' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_csrf();
    $mdp = (string) ($_POST['mot_de_passe'] ?? '');

    $attente = max(limiteur_bloque_depuis('connexion'), limiteur_bloque_depuis('connexion_compte', $cle));
    if ($attente > 0) {
        $erreurs['mot_de_passe'] = 'Trop de tentatives. Réessayez dans';
    } elseif ($mdp === '') {
        $erreurs['mot_de_passe'] = MESSAGE_CHAMP_OBLIGATOIRE;
    } elseif (password_verify($mdp, (string) $u['mot_de_passe'])) {
        if (password_needs_rehash((string) $u['mot_de_passe'], PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE utilisateur SET mot_de_passe = ? WHERE id = ?')
                ->execute([password_hash($mdp, PASSWORD_DEFAULT), $id]);
        }
        google_mdp_ouvrir($id);
    } else {
        limiteur_echec('connexion', CONNEXION_MAX_ESSAIS, CONNEXION_BLOCAGE);
        limiteur_echec('connexion_compte', CONNEXION_MAX_ESSAIS_COMPTE, CONNEXION_BLOCAGE_COMPTE, $cle);
        journal_securite('connexion_google_mdp_echouee', ['utilisateur' => $id]);
        $attente = max(limiteur_bloque_depuis('connexion'), limiteur_bloque_depuis('connexion_compte', $cle));
        $erreurs['mot_de_passe'] = $attente > 0 ? 'Trop de tentatives. Réessayez dans' : 'Mot de passe incorrect.';
    }
}

$csrf = jeton_csrf();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Mot de passe — Ma Bibliothèque Manga</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%93%9A%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= e(actif('css/style.css')) ?>">
</head>
<body class="auth-body">

<main class="auth-wrap">
  <section class="auth-card">
    <div class="auth-head">
      <p class="auth-logo" aria-hidden="true">📚</p>
      <h1>Bonjour <?= e($u['identifiant']) ?></h1>
      <p class="hint">Google a confirmé votre identité. Saisissez maintenant votre mot de passe.</p>
    </div>

    <form method="post" action="google-mot-de-passe.php" autocomplete="on" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <!-- Pour le gestionnaire de mots de passe : à quel compte rattacher
           le champ (voir parametres.php). -->
      <input class="visually-hidden" type="text" name="identifiant_lecture" autocomplete="username"
             value="<?= e($u['identifiant']) ?>" readonly tabindex="-1" aria-hidden="true">

      <div class="field">
        <label for="mot_de_passe">Mot de passe</label>
        <div class="password-wrap">
          <input id="mot_de_passe" name="mot_de_passe" type="password" required autocomplete="current-password"
                 <?= $erreurs ? champ_aria($erreurs, 'mot_de_passe', 'mot_de_passe') : 'autofocus' ?>>
          <button type="button" class="icon-btn toggle-password" data-cible="mot_de_passe"
                  aria-label="Afficher le mot de passe">👁️</button>
        </div>
        <?php if ($attente > 0): ?>
          <p class="erreur-champ" id="mot_de_passe-erreur"><?= e($erreurs['mot_de_passe']) ?>
            <b class="delai" data-restant="<?= $attente ?>"><?= $attente ?> secondes</b>.</p>
        <?php else: ?>
          <?= champ_erreur($erreurs, 'mot_de_passe', 'mot_de_passe') ?>
        <?php endif; ?>
      </div>

      <button type="submit" class="btn btn-primary full">Se connecter</button>
    </form>

    <p class="auth-switch"><a href="mot-de-passe-oublie.php">Mot de passe oublié ?</a></p>
    <p class="auth-switch"><a href="google-mot-de-passe.php?annuler=1">Annuler</a></p>
    <p class="auth-legal"><a href="mentions-legales.php">Mentions légales et confidentialité</a></p>
  </section>
</main>

<script src="<?= e(actif('js/delai.js')) ?>" defer></script>
<script src="<?= e(actif('js/auth.js')) ?>" defer></script>
</body>
</html>
