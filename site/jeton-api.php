<?php
/* =====================================================================
   Jetons de l'API JSON : création, liste, révocation.

   Page à part (et non une section de parametres.php) pour que Paramètres
   reste strictement ce qu'il était. Formulaires POST classiques, sans
   JavaScript : la CSP n'en autorise pas en ligne, et rien ici n'en a
   besoin.

   Créer un jeton exige le mot de passe (comme tout ce qui ouvre un accès
   au compte : voir exiger_mot_de_passe() dans api.php). Le révoquer, non :
   c'est le geste de secours, il ne doit jamais être difficile.

   Le jeton n'est affiché qu'UNE fois, juste après sa création — la base
   n'en garde que l'empreinte.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/fonctions.php';
require_once __DIR__ . '/includes/google.php';     // bouton_google(), pour un compte Google
require_once __DIR__ . '/includes/api_v1.php';

$moi    = exiger_connexion();
$mon_id = (int) $moi['id'];

$erreurs  = [];
$info     = '';
$nouveau  = null;   // le jeton qui vient d'être créé, en clair, une seule fois

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_csrf();
    $formulaire = (string) ($_POST['formulaire'] ?? '');

    if ($formulaire === 'creer') {
        $nom = texte($_POST['nom'] ?? '', 60);
        if ($nom === '') {
            $nom = 'Mon application';
        }

        /* Même règle que les autres actions à risque (ACTIONS_SENSIBLES) :
           un compte e-mail retape son mot de passe ; un compte Google doit
           s'être reconnecté (Google, puis son mot de passe s'il en a un)
           pour CETTE action, ce qui lui donne CONFIRMATION_DUREE secondes. */
        $attente = null;
        if (!verifier_mot_de_passe_limite($mon_id, (string) ($_POST['mot_de_passe'] ?? ''), $attente, 'api.jeton')) {
            $erreurs['mot_de_passe'] = $attente > 0
                ? 'Trop de tentatives. Réessayez dans ' . $attente . ' secondes.'
                : (compte_google($mon_id)
                    ? message_reconnexion_google('api.jeton')
                    : 'Mot de passe incorrect.');
        } else {
            $nouveau = api_jeton_creer($pdo, $mon_id, $nom);
            if ($nouveau === null) {
                $erreurs['nom'] = 'Vous avez déjà ' . API_JETONS_MAX . ' jetons actifs. Révoquez-en un d\'abord.';
            } else {
                oublier_confirmation();   // elle ne sert qu'une fois
                journal_securite('api_jeton_cree', ['utilisateur' => $mon_id, 'jeton_id' => $nouveau['id']]);
            }
        }
    } elseif ($formulaire === 'revoquer') {
        $req = $pdo->prepare(
            'UPDATE jeton_api SET revoque_le = NOW()
              WHERE id = ? AND utilisateur_id = ? AND revoque_le IS NULL'
        );
        $req->execute([(int) ($_POST['id'] ?? 0), $mon_id]);
        if ($req->rowCount() > 0) {
            journal_securite('api_jeton_revoque', ['utilisateur' => $mon_id, 'jeton_id' => (int) $_POST['id']]);
            $info = 'Jeton révoqué : il est refusé dès maintenant.';
        }
    }
}

$req = $pdo->prepare(
    'SELECT id, nom, cree_le, dernier_usage, revoque_le
       FROM jeton_api WHERE utilisateur_id = ? ORDER BY revoque_le IS NOT NULL, id DESC LIMIT 50'
);
$req->execute([$mon_id]);
$jetons = $req->fetchAll();

/* Message laissé par le retour de Google (« Identité confirmée ✅ »). */
$flash = flash_prendre($genre_flash);

$par_google = compte_google($mon_id);
$confirme   = confirmation_en_cours($mon_id);
$confirme_ici = ($confirme['action'] ?? '') === 'api.jeton';

$csrf = jeton_csrf();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Jetons d'API — Ma Bibliothèque Manga</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%93%9A%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= e(actif('css/style.css')) ?>">
</head>
<body class="auth-body" data-prive="1">

<main class="auth-wrap">
  <section class="auth-card">
    <div class="auth-head">
      <p class="auth-logo" aria-hidden="true">🔑</p>
      <h1>Jetons d'API</h1>
      <p class="hint">Un jeton permet à une application de lire et modifier vos séries, sans jamais les supprimer. Il ne vaut que pour votre compte.</p>
    </div>

    <?php if ($info): ?>
      <div class="alert alert-info"><?= e($info) ?></div>
    <?php endif; ?>

    <?php if ($flash): ?>
      <div class="alert <?= $genre_flash === 'erreur' ? 'alert-error' : 'alert-info' ?>" role="status"><?= e($flash) ?></div>
    <?php endif; ?>

    <?php if ($nouveau): ?>
      <div class="alert alert-info" role="status">
        <p><b>Copiez ce jeton maintenant.</b> Il ne sera plus jamais affiché : si vous le perdez, créez-en un autre.</p>
        <div class="field">
          <label for="jeton-neuf">Jeton</label>
          <input id="jeton-neuf" type="text" readonly value="<?= e($nouveau['jeton']) ?>" autocomplete="off" spellcheck="false">
        </div>
        <p class="hint">Rangez-le dans le fichier de configuration de votre serveur, jamais dans le code ni dans un navigateur.</p>
      </div>
    <?php endif; ?>

    <form method="post" action="jeton-api.php" autocomplete="off" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="formulaire" value="creer">

      <div class="field">
        <label for="nom">Nom du jeton</label>
        <input id="nom" name="nom" type="text" maxlength="60" placeholder="Mon application">
        <?= champ_erreur($erreurs, 'nom', 'nom') ?>
      </div>

      <?php if ($par_google && !$confirme_ici): ?>
        <?= champ_erreur($erreurs, 'mot_de_passe', 'mot_de_passe') ?>
      <?php elseif ($par_google): ?>
        <p class="hint">✅ Identité confirmée : il vous reste <?= (int) $confirme['restant'] ?> secondes pour <?= e(ACTIONS_SENSIBLES['api.jeton']) ?>.</p>
      <?php else: ?>
      <div class="field">
        <label for="mot_de_passe">Votre mot de passe</label>
        <input id="mot_de_passe" name="mot_de_passe" type="password" required autocomplete="current-password"<?= champ_aria($erreurs, 'mot_de_passe', 'mot_de_passe') ?>>
        <?= champ_erreur($erreurs, 'mot_de_passe', 'mot_de_passe') ?>
      </div>
      <?php endif; ?>

      <?php if ($par_google && !$confirme_ici): ?>
        <p class="hint">Il faut d'abord vous reconnecter avec Google. Vous aurez ensuite <?= e(secondes_lisibles(CONFIRMATION_DUREE)) ?> pour <?= e(ACTIONS_SENSIBLES['api.jeton']) ?>.</p>
        <?= bouton_google('Se reconnecter avec Google', 'parametres', 'api.jeton') ?>
      <?php else: ?>
      <button type="submit" class="btn btn-primary full">Créer un jeton</button>
      <?php endif; ?>
    </form>

    <h2>Vos jetons</h2>
    <?php if (!$jetons): ?>
      <p class="hint">Aucun jeton pour le moment.</p>
    <?php endif; ?>
    <?php foreach ($jetons as $j): ?>
      <form method="post" action="jeton-api.php" class="field">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="formulaire" value="revoquer">
        <input type="hidden" name="id" value="<?= (int) $j['id'] ?>">
        <p>
          <b><?= e($j['nom']) ?></b>
          <?= $j['revoque_le'] ? '— révoqué' : '— actif' ?><br>
          <span class="hint">
            Créé le <?= e(date('d/m/Y H:i', strtotime((string) $j['cree_le']))) ?>
            · <?= $j['dernier_usage']
                ? 'dernier appel le ' . e(date('d/m/Y H:i', strtotime((string) $j['dernier_usage'])))
                : 'jamais utilisé' ?>
          </span>
        </p>
        <?php if (!$j['revoque_le']): ?>
          <button type="submit" class="btn full">Révoquer</button>
        <?php endif; ?>
      </form>
    <?php endforeach; ?>

    <p class="auth-switch"><a href="parametres.php">Retour aux paramètres</a></p>
  </section>
</main>

<script src="<?= e(actif('js/double-appui.js')) ?>" defer></script>
</body>
</html>
