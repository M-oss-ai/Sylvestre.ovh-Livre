<?php
/* =====================================================================
   Informations légales et politique de confidentialité — droit SUISSE
   (nLPD, en vigueur depuis le 1er septembre 2023).

   La Suisse n'a pas d'équivalent de la LCEN française : aucune
   obligation générale de « mentions légales ». Ce qui est exigé ici
   vient de l'art. 19 nLPD (devoir d'informer lors de la collecte) :
   identité et coordonnées du responsable, finalités, destinataires, et
   l'État vers lequel les données partent. Le reste est facultatif et
   s'efface de la page quand le .env ne le renseigne pas.

   Seule page publique du site : elle doit rester lisible sans compte.
   Tout le contenu variable vient du .env (constantes LEGAL_*), il n'y a
   rien à retoucher ici.
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/fonctions.php';

/* Un champ non renseigné est signalé à l'écran plutôt qu'affiché vide :
   une mention légale incomplète ne remplit pas son rôle, autant que ça
   se voie tout de suite. */
function legal(string $valeur, string $quoi): string
{
    return $valeur !== ''
        ? e($valeur)
        : '<span class="legal-manquant">[à renseigner dans le .env : ' . e($quoi) . ']</span>';
}

$connecte = utilisateur_actuel() !== null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Mentions légales — Ma Bibliothèque Manga</title>
<meta name="description" content="Mentions légales et politique de confidentialité.">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%F0%9F%93%9A%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= e(actif('css/style.css')) ?>">
</head>
<body>

<header class="topbar settings-topbar">
  <div class="topbar-row settings-topbar-row">
    <a href="<?= $connecte ? 'index.php' : 'connexion.php' ?>" class="back-btn" aria-label="Retour">←</a>
    <h1 class="settings-h1">Mentions légales</h1>
    <span class="back-btn-spacer" aria-hidden="true"></span>
  </div>
</header>

<main class="settings-main legal-main">

  <section class="settings-card">
    <h2 class="settings-card-title"><span class="settings-icon" aria-hidden="true">📇</span> Éditeur du site</h2>
    <dl class="legal-liste">
      <dt>Responsable du traitement</dt>
      <dd><?= legal(LEGAL_EDITEUR, 'LEGAL_EDITEUR') ?></dd>

      <?php if (LEGAL_STATUT !== ''): ?>
      <dt>Statut</dt>
      <dd><?= e(LEGAL_STATUT) ?></dd>
      <?php endif; ?>

      <?php /* Facultatif en droit suisse pour un site gratuit et non
               commercial : la ligne disparaît si le .env ne la remplit
               pas, au lieu d'afficher un avertissement en rouge. */ ?>
      <?php if (LEGAL_ADRESSE !== ''): ?>
      <dt>Adresse</dt>
      <dd><?= e(LEGAL_ADRESSE) ?></dd>
      <?php endif; ?>

      <dt>Contact</dt>
      <dd>
        <?php if (LEGAL_CONTACT !== ''): ?>
          <a href="mailto:<?= e(LEGAL_CONTACT) ?>"><?= e(LEGAL_CONTACT) ?></a>
        <?php else: ?>
          <?= legal('', 'LEGAL_CONTACT') ?>
        <?php endif; ?>
      </dd>
    </dl>
  </section>

  <section class="settings-card">
    <h2 class="settings-card-title"><span class="settings-icon" aria-hidden="true">🗄️</span> Hébergement</h2>
    <dl class="legal-liste">
      <dt>Hébergeur</dt>
      <dd><?= legal(LEGAL_HEBERGEUR, 'LEGAL_HEBERGEUR') ?></dd>
      <dt>Adresse</dt>
      <dd><?= legal(LEGAL_HEBERGEUR_ADRESSE, 'LEGAL_HEBERGEUR_ADRESSE') ?></dd>
      <?php if (LEGAL_HEBERGEUR_TEL !== ''): ?>
      <dt>Téléphone</dt>
      <dd><?= e(LEGAL_HEBERGEUR_TEL) ?></dd>
      <?php endif; ?>

      <dt>Pays d'hébergement</dt>
      <dd><?= legal(LEGAL_HEBERGEUR_PAYS, 'LEGAL_HEBERGEUR_PAYS') ?></dd>
    </dl>
  </section>

  <section class="settings-card">
    <h2 class="settings-card-title"><span class="settings-icon" aria-hidden="true">🔐</span> Données personnelles</h2>

    <h3 class="legal-h3">Ce qui est collecté</h3>
    <ul class="legal-puces">
      <li><b>Votre compte</b> — identifiant, adresse e-mail, prénom et nom (facultatifs),
          photo de profil (facultative), mot de passe (jamais en clair : seule une
          empreinte bcrypt est conservée).</li>
      <li><b>Votre bibliothèque</b> — titres, auteurs, tomes, statuts de lecture et
          couvertures que vous enregistrez.</li>
      <li><b>Journaux techniques</b> — adresse IP et horodatage des connexions, des
          changements de mot de passe et des tentatives échouées.</li>
    </ul>

    <h3 class="legal-h3">Pourquoi</h3>
    <ul class="legal-puces">
      <li>Faire fonctionner le service et vous permettre de retrouver votre bibliothèque
          d'un appareil à l'autre (exécution du service demandé).</li>
      <li>Vérifier votre adresse e-mail et vous permettre de récupérer votre compte.</li>
      <li>Détecter et bloquer les tentatives d'accès frauduleuses (intérêt légitime :
          sécurité du service).</li>
    </ul>
    <p class="hint">
      Aucune donnée n'est vendue, louée ni transmise à des fins publicitaires, et aucun
      traceur publicitaire n'est déposé.
    </p>

    <h3 class="legal-h3">Où sont vos données</h3>
    <p class="hint">
      Le site est hébergé par <b><?= e(LEGAL_HEBERGEUR) ?></b><?= LEGAL_HEBERGEUR_ADRESSE !== '' ? ' (' . e(LEGAL_HEBERGEUR_ADRESSE) . ')' : '' ?>.
      Pays d'hébergement : <b><?= e(LEGAL_HEBERGEUR_PAYS) ?></b>. Vos données sont donc
      stockées hors de Suisse.
    </p>
    <p class="hint">
      Ce pays figure sur la liste des États dont la législation assure une protection
      adéquate (annexe 1 de l'ordonnance sur la protection des données) : ce transfert
      ne requiert aucune garantie contractuelle supplémentaire.
    </p>

    <h3 class="legal-h3">Combien de temps</h3>
    <ul class="legal-puces">
      <li><b>Compte et bibliothèque</b> — tant que le compte existe. Sa suppression depuis
          les paramètres efface immédiatement et définitivement l'ensemble.</li>
      <li><b>Compteurs de tentatives</b> — 30 jours.</li>
      <li><b>Liens de confirmation et de réinitialisation</b> — 24 h et 1 h respectivement.</li>
      <li><b>Lien de blocage d'un changement d'adresse</b> — 7 jours. Il conserve pendant ce
          délai l'ancienne adresse, pour pouvoir la rendre au compte.</li>
      <li><b>Journaux du serveur</b> — conservés par l'hébergeur selon sa propre politique.</li>
    </ul>

    <h3 class="legal-h3">Vos droits</h3>
    <p class="hint">
      Vous disposez d'un droit d'accès, de rectification, d'effacement, de limitation et
      de portabilité. Deux d'entre eux s'exercent directement depuis la page
      <?php if ($connecte): ?><a href="parametres.php">Paramètres</a><?php else: ?>Paramètres<?php endif; ?> :
      <b>« Exporter mes données »</b> (portabilité, au format JSON) et
      <b>« Supprimer mon compte »</b> (effacement). Pour le reste, écrivez à
      <?php if (LEGAL_CONTACT !== ''): ?>
        <a href="mailto:<?= e(LEGAL_CONTACT) ?>"><?= e(LEGAL_CONTACT) ?></a>.
      <?php else: ?>
        <?= legal('', 'LEGAL_CONTACT') ?>.
      <?php endif; ?>
      Vous pouvez également vous adresser au Préposé fédéral à la protection des données
      et à la transparence (<a href="https://www.edoeb.admin.ch" rel="noopener">PFPDT</a>),
      autorité de surveillance compétente en Suisse.
    </p>
  </section>

  <section class="settings-card">
    <h2 class="settings-card-title"><span class="settings-icon" aria-hidden="true">🍪</span> Cookies</h2>
    <p class="hint">
      Ce site ne dépose que des cookies <b>strictement nécessaires</b> à son
      fonctionnement, qui ne demandent donc pas de consentement :
    </p>
    <ul class="legal-puces">
      <li><code>LIVRE_SESSION</code> — maintient votre session le temps de votre visite.</li>
      <li><code>LIVRE_REMEMBER</code> — uniquement sur les comptes au forfait illimité,
          pour éviter de se reconnecter à chaque visite. Supprimé à la déconnexion.</li>
    </ul>
    <p class="hint">Aucun cookie de mesure d'audience ni de publicité.</p>
  </section>

  <section class="settings-card">
    <h2 class="settings-card-title"><span class="settings-icon" aria-hidden="true">🌐</span> Services tiers</h2>
    <ul class="legal-puces">
      <li><b>MangaDex</b> — la « recherche auto » de couverture envoie le titre que vous
          avez saisi et le numéro du tome à <code>api.mangadex.org</code>. La requête part
          de <b>notre serveur, pas de votre navigateur</b> : ce service voit donc le titre
          recherché, mais <b>pas votre adresse IP</b>. Les vignettes proposées, elles, sont
          bien chargées depuis <code>uploads.mangadex.org</code> par votre navigateur ; si
          vous en retenez une, l'adresse de l'image est conservée et rechargée depuis ce
          site à chaque affichage de la carte. Toutes les autres fonctions marchent sans.</li>
      <li><b>Couvertures liées par URL</b> — si vous enregistrez une couverture pointant
          vers un autre site, votre navigateur la télécharge chez lui à chaque
          affichage. Les images envoyées en fichier, elles, restent sur ce serveur.</li>
    </ul>
  </section>

  <section class="settings-card">
    <h2 class="settings-card-title"><span class="settings-icon" aria-hidden="true">©️</span> Propriété intellectuelle</h2>
    <p class="hint">
      Les titres, couvertures et noms d'auteurs enregistrés restent la propriété de leurs
      ayants droit. Ce site est un outil de suivi personnel : il n'héberge aucune œuvre et
      ne diffuse publiquement aucun contenu — chaque bibliothèque n'est visible que par
      son propre titulaire.
    </p>
  </section>

</main>

<footer class="app-footer">
  <a href="<?= $connecte ? 'index.php' : 'connexion.php' ?>">Retour à l'application</a>
</footer>

</body>
</html>
