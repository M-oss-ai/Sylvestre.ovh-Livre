<?php
/* =====================================================================
   Page d'administration : tous les comptes, et ce qu'on peut leur faire.

   Réservée aux comptes `admin` (exiger_admin : « page introuvable » pour
   les autres). Les actions passent par api.php (`admin.*`), jamais par ce
   fichier — sauf UNE : la confirmation d'une suppression, qui arrive par
   le lien envoyé à ADMIN_EMAIL (?supprimer=…) et ne s'exécute qu'en POST.
   Un lien qui supprimait au simple GET serait déclenché par n'importe quel
   antivirus de messagerie qui « visite » les adresses des courriers.

   Un compte bloqué peut être administrateur : les deux sont indépendants.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/admin.php';

$moi    = exiger_admin();
$mon_id = (int) $moi['id'];
$csrf   = jeton_csrf();

/* ---------------------------------------------------------------------
   Confirmer une suppression (lien reçu à ADMIN_EMAIL)
   --------------------------------------------------------------------- */
$demande = null;   // la suppression qui attend un clic : ['jeton', 'cible', 'par', 'refus']

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exiger_csrf();
    $formulaire = (string) ($_POST['formulaire'] ?? '');

    if (in_array($formulaire, ['supprimer', 'annuler_suppression'], true)) {
        $j = jeton_action_valide((string) ($_POST['jeton'] ?? ''), 'suppression_admin');
        if (!$j) {
            flash("Ce lien n'est plus valable : demandez de nouveau la suppression depuis cette page.", 'erreur');
            header('Location: admin.php');
            exit;
        }
        if ($formulaire === 'annuler_suppression') {
            consommer_jeton_action((int) $j['jeton_id']);
            flash('Suppression annulée : le lien ne sert plus.');
            header('Location: admin.php');
            exit;
        }

        /* On relit la cible : entre la demande et le clic, elle a pu devenir
           administrateur, ou être supprimée par ailleurs. */
        $cible = admin_utilisateur($pdo, (int) $j['id']);
        $refus = $cible ? admin_refus_suppression($cible, $mon_id) : 'Ce compte n\'existe plus.';
        if ($refus !== '') {
            consommer_jeton_action((int) $j['jeton_id']);
            flash($refus, 'erreur');
            header('Location: admin.php');
            exit;
        }

        admin_supprimer_compte($pdo, $cible['id']);   // le jeton part avec la ligne (CASCADE)
        journal_securite('admin_compte_supprime', ['admin' => $mon_id, 'cible' => $cible['id'],
            'identifiant' => $cible['identifiant']]);

        /* Prévenu APRÈS, et seulement si l'effacement a eu lieu — et si
           l'adresse est confirmée (voir admin_message_forfait). */
        $suite = '.';
        if ($cible['confirme']) {
            $suite = avertir_compte_supprime_par_admin($cible['email'], $cible['identifiant'])
                ? ' ; un e-mail le lui annonce.'
                : " ; l'e-mail qui l'en prévient n'a pas pu partir.";
        }
        flash('Le compte de ' . $cible['identifiant'] . ' est supprimé' . $suite);
        header('Location: admin.php');
        exit;
    }
} elseif (($jeton_lien = (string) ($_GET['supprimer'] ?? '')) !== '') {
    $j = jeton_action_valide($jeton_lien, 'suppression_admin');
    $cible = $j ? admin_utilisateur($pdo, (int) $j['id']) : null;
    if ($cible) {
        $par = admin_utilisateur($pdo, (int) $j['donnee']);
        $demande = ['jeton' => $jeton_lien, 'cible' => $cible,
                    'par' => $par ? $par['identifiant'] : 'un administrateur',
                    'refus' => admin_refus_suppression($cible, $mon_id)];
    } else {
        flash("Ce lien n'est plus valable (expiré, déjà utilisé, ou le compte n'existe plus).", 'erreur');
    }
}

$info = flash_prendre($genre_info);

/* ---------------------------------------------------------------------
   La liste
   --------------------------------------------------------------------- */
$comptes = array_map(
    static fn (array $l): array => admin_pour_acteur($l, $mon_id),
    admin_utilisateurs($pdo)
);
$totaux   = admin_totaux($comptes);
$restant  = max(0, MAX_UTILISATEURS - $totaux['comptes']);

/* Les réglages : ce que la base porte, ce que le .env dit, ce qui est en
   vigueur. La constante est la valeur réellement appliquée (déjà bornée par
   config.php) ; la case montre ce qui a été saisi quand la base le porte. */
$reglages_dispo = reglages_disponibles($pdo);
$vues = [];
foreach (REGLAGES as $cle => $r) {
    $vues[$cle] = reglage_vue($cle, $GLOBALS['REGLAGES_BASE'], env_brut($cle), (int) constant($cle));
}
$etiquettes_source = ['base' => 'modifié', 'env' => '.env', 'defaut' => 'défaut'];

/** Une coche, ou un tiret : jamais le seul signe, un lecteur d'écran lit « oui » / « non ». */
function admin_oui_non(bool $oui, string $quoi): string
{
    return $oui
        ? '<span class="admin-oui" role="img" aria-label="' . e($quoi) . ' : oui">✓</span>'
        : '<span class="admin-non" role="img" aria-label="' . e($quoi) . ' : non">–</span>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Administration — Ma Bibliothèque Manga</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%93%9A%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= e(actif('css/style.css')) ?>">
</head>
<body data-csrf="<?= e($csrf) ?>" data-prive="1" data-compte="<?= $mon_id ?>"
      data-admin-email="<?= e(ADMIN_EMAIL) ?>">

<header class="topbar settings-topbar">
  <div class="topbar-row settings-topbar-row">
    <a href="index.php" class="back-btn" aria-label="Retour à la bibliothèque">←</a>
    <h1 class="settings-h1">Administration</h1>
    <span class="back-btn-spacer" aria-hidden="true"></span>
  </div>
</header>

<main class="admin-main">

  <?php if ($info): ?>
    <div class="alert <?= $genre_info === 'erreur' ? 'alert-error' : 'alert-info' ?>" role="<?= $genre_info === 'erreur' ? 'alert' : 'status' ?>"><?= e($info) ?></div>
  <?php endif; ?>

  <?php if ($demande): ?>
  <!-- ---------------- Suppression à confirmer ----------------
       Le lien reçu à ADMIN_EMAIL mène ici. Rien n'est supprimé avant le
       bouton : un GET ne supprime jamais. -->
  <section class="settings-card admin-suppression" aria-labelledby="admin-suppr-titre">
    <h2 class="settings-card-title" id="admin-suppr-titre"><span class="settings-icon" aria-hidden="true">🗑️</span> Confirmer la suppression</h2>
    <?php $c = $demande['cible']; ?>
    <p>
      <?= e($demande['par']) ?> demande la suppression du compte de
      <b><?= e($c['identifiant']) ?></b> (<?= e($c['email']) ?>), inscrit le <?= e($c['inscrit_le']) ?>,
      avec ses <b><?= (int) $c['series'] ?> série(s)</b> et ses images.
    </p>
    <?php if ($demande['refus'] !== ''): ?>
      <p class="alert alert-error" role="alert"><?= e($demande['refus']) ?></p>
    <?php else: ?>
      <p class="alert alert-error" role="alert">Cette suppression est définitive : rien ne pourra être restauré.</p>
      <div class="admin-confirmer">
        <form method="post" action="admin.php">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="formulaire" value="supprimer">
          <input type="hidden" name="jeton" value="<?= e($demande['jeton']) ?>">
          <button type="submit" class="btn btn-danger">Supprimer définitivement</button>
        </form>
        <form method="post" action="admin.php">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="formulaire" value="annuler_suppression">
          <input type="hidden" name="jeton" value="<?= e($demande['jeton']) ?>">
          <button type="submit" class="btn btn-ghost">Annuler</button>
        </form>
      </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- ---------------- Les chiffres ---------------- -->
  <section class="settings-card" aria-labelledby="admin-chiffres-titre">
    <h2 class="settings-card-title" id="admin-chiffres-titre"><span class="settings-icon" aria-hidden="true">📊</span> En un coup d'œil</h2>
    <dl class="admin-chiffres">
      <div><dt>Comptes</dt><dd><?= $totaux['comptes'] ?> <small>/ <?= MAX_UTILISATEURS ?></small></dd><dd class="admin-sous"><?= $restant ?> place(s) restante(s)</dd></div>
      <div><dt>Confirmés</dt><dd data-total="confirmes"><?= $totaux['confirmes'] ?></dd></div>
      <div><dt>Non confirmés</dt><dd data-total="nonConfirmes"><?= $totaux['non_confirmes'] ?></dd></div>
      <div><dt>Bloqués</dt><dd data-total="bloques"><?= $totaux['bloques'] ?></dd></div>
      <div><dt>Illimités</dt><dd data-total="illimites"><?= $totaux['illimites'] ?></dd></div>
      <div><dt>Administrateurs</dt><dd data-total="admins"><?= $totaux['admins'] ?></dd></div>
      <div><dt>Séries</dt><dd data-total="series"><?= $totaux['series'] ?></dd></div>
    </dl>
  </section>

  <!-- ---------------- Les comptes ---------------- -->
  <section class="settings-card" aria-labelledby="admin-comptes-titre">
    <h2 class="settings-card-title" id="admin-comptes-titre"><span class="settings-icon" aria-hidden="true">👥</span> Comptes</h2>

    <div class="admin-outils">
      <div class="field admin-recherche">
        <label for="admin-recherche">Chercher</label>
        <input id="admin-recherche" type="search" autocomplete="off"
               placeholder="Identifiant, e-mail, « admin », « non confirmé », « bloqué »…">
      </div>
      <p class="hint admin-compte" id="admin-compte" aria-live="polite"><?= $totaux['comptes'] ?> compte(s)</p>
    </div>

    <div class="admin-tableau" role="region" aria-labelledby="admin-comptes-titre" tabindex="0">
      <table class="admin-table" id="admin-table">
        <thead>
          <tr>
            <th scope="col"><button type="button" class="admin-tri" data-tri="id">ID</button></th>
            <th scope="col"><button type="button" class="admin-tri" data-tri="identifiant">Identifiant</button></th>
            <th scope="col"><button type="button" class="admin-tri" data-tri="email">E-mail</button></th>
            <th scope="col"><button type="button" class="admin-tri" data-tri="confirme">Confirmé</button></th>
            <th scope="col"><button type="button" class="admin-tri" data-tri="photo">Photo</button></th>
            <th scope="col"><button type="button" class="admin-tri" data-tri="forfait">Forfait</button></th>
            <th scope="col"><button type="button" class="admin-tri" data-tri="inscrit">Inscrit le</button></th>
            <th scope="col"><button type="button" class="admin-tri" data-tri="series">Séries</button></th>
            <th scope="col">Actions</th>
          </tr>
        </thead>
        <tbody id="admin-corps">
        <?php foreach ($comptes as $c): ?>
          <tr class="admin-ligne" data-id="<?= $c['id'] ?>"
              data-identifiant="<?= e($c['identifiant']) ?>" data-email="<?= e($c['email']) ?>"
              data-confirme="<?= $c['confirme'] ? '1' : '0' ?>" data-photo="<?= $c['photo'] ? '1' : '0' ?>"
              data-forfait="<?= e($c['forfait']) ?>" data-admin="<?= $c['admin'] ? '1' : '0' ?>"
              data-google="<?= $c['google'] ? '1' : '0' ?>" data-moi="<?= $c['moi'] ? '1' : '0' ?>"
              data-inscrit="<?= (int) strtotime($c['cree_le']) ?>" data-series="<?= $c['series'] ?>"
              data-refus-droits="<?= e($c['refus_droits']) ?>"
              data-refus-suppression="<?= e($c['refus_suppression']) ?>">
            <td class="c-id"><?= $c['id'] ?></td>
            <td class="c-identifiant">
              <b class="c-nom"><?= e($c['identifiant']) ?></b>
              <span class="admin-badge admin-badge-moi<?= $c['moi'] ? '' : ' hidden' ?>">vous</span>
              <span class="admin-badge admin-badge-admin<?= $c['admin'] ? '' : ' hidden' ?>">admin</span>
              <span class="admin-badge admin-badge-google<?= $c['google'] ? '' : ' hidden' ?>">Google</span>
            </td>
            <td class="c-email"><?= e($c['email']) ?></td>
            <td class="c-confirme"><?= admin_oui_non($c['confirme'], 'Adresse confirmée') ?></td>
            <td class="c-photo">
              <?php if ($c['photo_url'] !== ''): ?>
                <!-- La photo du compte. referrerpolicy : l'adresse de CETTE page ne part pas chez
                     l'hébergeur d'une image externe. L'adresse a passé url_image_sure() : https
                     ou un fichier de uploads/, rien d'autre. -->
                <button type="button" class="admin-photo-btn" data-photo="<?= e($c['photo_url']) ?>"
                        aria-label="Agrandir la photo de <?= e($c['identifiant']) ?>">
                  <img class="admin-photo" src="<?= e($c['photo_url']) ?>" alt="" width="40" height="40"
                       loading="lazy" decoding="async" referrerpolicy="no-referrer">
                </button>
              <?php elseif ($c['photo']): ?>
                <!-- La colonne n'est pas vide, mais le site refuse l'adresse (http://, chemin
                     étranger…) : un ✓ ferait croire qu'on peut la voir. -->
                <span class="admin-non" role="img" aria-label="Photo de profil : adresse refusée par le site"
                      title="Une photo est enregistrée, mais son adresse est refusée par le site : elle n'est pas affichée.">⚠</span>
              <?php else: ?>
                <?= admin_oui_non(false, 'Photo de profil') ?>
              <?php endif; ?>
            </td>
            <td class="c-forfait">
              <select class="admin-forfait" aria-label="Forfait de <?= e($c['identifiant']) ?>">
                <?php foreach (ADMIN_FORFAITS as $valeur => $libelle): ?>
                  <option value="<?= e($valeur) ?>"<?= $c['forfait'] === $valeur ? ' selected' : '' ?>><?= e($libelle) ?></option>
                <?php endforeach; ?>
              </select>
              <p class="admin-raison<?= $c['forfait'] === 'bloque' ? '' : ' hidden' ?>"
                 title="<?= e($c['raison_blocage']) ?>"><?php if ($c['forfait'] === 'bloque'): ?><?= $c['bloque_le'] !== '' ? e($c['bloque_le']) . ' — ' : '' ?><?= e($c['raison_blocage'] !== '' ? $c['raison_blocage'] : 'sans raison notée') ?><?php endif; ?></p>
            </td>
            <td class="c-inscrit"><time datetime="<?= e(substr($c['cree_le'], 0, 10)) ?>"><?= e($c['inscrit_le']) ?></time>
              <small class="admin-sous"><?= e(admin_depuis_texte($c['inscrit_jours'])) ?></small></td>
            <td class="c-series"><?= $c['series'] ?></td>
            <td class="c-actions">
              <button type="button" class="btn btn-ghost admin-droits"<?= $c['refus_droits'] !== '' ? ' disabled title="' . e($c['refus_droits']) . '"' : '' ?>><?= $c['admin'] ? 'Retirer admin' : 'Rendre admin' ?></button>
              <button type="button" class="btn btn-danger admin-suppr"<?= $c['refus_suppression'] !== '' ? ' disabled title="' . e($c['refus_suppression']) . '"' : '' ?>>Supprimer</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">
      Un changement de forfait envoie un e-mail à la personne (sauf si son adresse n'est pas confirmée).
      Bloquer demande une raison, qui figure dans l'e-mail et sur sa bibliothèque.
      Supprimer envoie d'abord un lien de confirmation à <?= e(ADMIN_EMAIL) ?>.
    </p>
  </section>

  <!-- ---------------- Les réglages ----------------
       Ils remplacent le .env, sans renvoyer de fichier : la page n'écrit que ce
       qu'on a changé (table `reglage`), et « .env » efface la ligne. Liste fermée,
       chaque valeur a ses bornes ; ce qui touche aux secrets, à l'adresse du
       site, à ADMIN_EMAIL ou aux mots de passe reste dans le .env. -->
  <section class="settings-card" id="reglages" aria-labelledby="admin-reglages-titre">
    <h2 class="settings-card-title" id="admin-reglages-titre"><span class="settings-icon" aria-hidden="true">⚙️</span> Réglages</h2>

    <?php if (!$reglages_dispo): ?>
      <p class="alert alert-error" role="alert">
        La table <b>reglage</b> manque : rejouez <code>livre.sql</code> (migration 12). En attendant, le
        <code>.env</code> gouverne seul.
      </p>
    <?php else: ?>
      <p class="hint">
        Une valeur enregistrée ici <b>remplace celle du .env</b> et s'applique dès la requête suivante, pour
        tout le monde. « ↩ .env » efface votre valeur et rend la main au fichier. Chaque réglage a ses bornes.
      </p>

      <?php foreach (REGLAGES_GROUPES as $groupe => $titre_groupe): ?>
        <h3 class="settings-sous-titre"><?= e($titre_groupe) ?></h3>
        <div class="admin-reglages">
        <?php foreach ($vues as $cle => $v): if ($v['groupe'] !== $groupe) { continue; } ?>
          <?php $champ = 'reg-' . strtolower($cle); ?>
          <form class="admin-reglage" data-cle="<?= e($cle) ?>" data-initial="<?= e($v['saisie']) ?>" novalidate>
            <div class="admin-reglage-texte">
              <label for="<?= e($champ) ?>"><?= e($v['libelle']) ?></label>
              <p class="hint">
                <span class="admin-cle">dans le .env : <code><?= e($cle) ?></code></span>
                <?= e($v['aide']) ?>
                <?php if ($v['plage'] !== ''): ?><span class="admin-plage">Entre <?= e(substr($v['plage'], 3)) ?>.</span><?php endif; ?>
                <span class="admin-sans-base">Sans ce réglage : <?= e(reglage_sans_base_texte($cle, env_brut($cle))) ?>.</span>
                <span class="admin-en-vigueur<?= $v['en_vigueur'] === null ? ' hidden' : '' ?>">En vigueur : <?= e((string) $v['en_vigueur']) ?> (borné par une autre règle).</span>
              </p>
            </div>
            <div class="admin-reglage-champ">
              <?php if (isset($v['choix'])): ?>
                <select id="<?= e($champ) ?>" name="valeur">
                  <?php foreach ($v['choix'] as $valeur => $libelle): ?>
                    <option value="<?= e((string) $valeur) ?>"<?= (string) $valeur === $v['saisie'] ? ' selected' : '' ?>><?= e($libelle) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input id="<?= e($champ) ?>" name="valeur" type="number" inputmode="numeric" step="1"
                       min="<?= e($v['min_saisie']) ?>" max="<?= e($v['max_saisie']) ?>" value="<?= e($v['saisie']) ?>">
                <?php if (($v['unite'] ?? '') !== ''): ?><span class="admin-unite"><?= e($v['unite']) ?></span><?php endif; ?>
              <?php endif; ?>
            </div>
            <span class="admin-badge admin-source" data-source="<?= e($v['source']) ?>"><?= e($etiquettes_source[$v['source']]) ?></span>
            <button type="submit" class="btn btn-primary admin-enregistrer" disabled>Enregistrer</button>
            <button type="button" class="btn btn-ghost admin-revenir" aria-label="Revenir à la valeur du .env : <?= e($v['libelle']) ?>"
                    <?= $v['source'] === 'base' ? '' : 'disabled' ?>>↩ .env</button>
          </form>
        <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

</main>

<!-- Une seule fenêtre pour les trois actions : js/admin.js y met le titre, le
     texte, et la raison quand on bloque. -->
<div id="admin-overlay" class="overlay hidden">
  <div class="modal small" role="alertdialog" aria-modal="true" aria-labelledby="admin-modal-titre" aria-describedby="admin-modal-texte">
    <h2 id="admin-modal-titre">Confirmer&nbsp;?</h2>
    <p id="admin-modal-texte" class="hint"></p>
    <p id="admin-modal-erreur" class="alert alert-error hidden" role="alert"></p>
    <div id="admin-modal-raison" class="field hidden">
      <label for="admin-raison">Raison du blocage</label>
      <textarea id="admin-raison" rows="3" maxlength="<?= ADMIN_RAISON_MAX ?>"
                placeholder="Elle sera envoyée par e-mail à la personne."></textarea>
    </div>
    <div class="modal-actions">
      <div class="grow"></div>
      <button type="button" id="admin-annuler" class="btn btn-ghost">Annuler</button>
      <button type="button" id="admin-valider" class="btn btn-primary">Confirmer</button>
    </div>
  </div>
</div>

<!-- La photo d'un compte, en grand. -->
<div id="admin-photo-overlay" class="overlay hidden">
  <div class="modal small" role="dialog" aria-modal="true" aria-labelledby="admin-photo-legende">
    <img id="admin-photo-grande" class="admin-photo-grande" src="" alt="" referrerpolicy="no-referrer">
    <p id="admin-photo-legende" class="hint admin-photo-legende"></p>
    <div class="modal-actions">
      <div class="grow"></div>
      <button type="button" id="admin-photo-fermer" class="btn btn-ghost">Fermer</button>
    </div>
  </div>
</div>

<div id="toast" class="toast hidden" role="status"></div>

<script src="<?= e(actif('js/double-appui.js')) ?>" defer></script>
<script src="<?= e(actif('js/commun.js')) ?>" defer></script>
<script src="<?= e(actif('js/admin.js')) ?>" defer></script>
</body>
</html>
