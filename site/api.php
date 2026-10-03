<?php
/* =====================================================================
   API JSON appelée par le navigateur (fetch).
   Règles appliquées à CHAQUE action :
     - session valide (401 sinon)
     - jeton CSRF valide (403 sinon)
     - toutes les requêtes filtrent sur utilisateur_id → un compte ne peut
       jamais lire ni modifier les séries d'un autre (404 sinon)
     - le HTML renvoyé est produit par carte.php, donc déjà échappé
     - les actions irréversibles ou qui déplacent le contrôle du compte
       (changement d'e-mail, suppression) exigent le mot de passe : une
       session volée ne doit pas suffire
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/includes/carte.php';
require_once __DIR__ . '/includes/couvertures.php';
require_once __DIR__ . '/includes/google.php';   // les modifications en attente d'un compte Google
require_once __DIR__ . '/includes/cle_acces.php';   // les clés d'accès (ajout, retrait)
require_once __DIR__ . '/includes/admin.php';        // les actions `admin.*`

$moi    = exiger_connexion_api();
$mon_id = (int) $moi['id'];

/* L'action est lue dans le corps POST et non dans $_REQUEST : ce dernier
   fusionne GET, POST et (selon la configuration php.ini « request_order »)
   les cookies, ce qui rend la valeur lue dépendante de l'hébergeur. */
$action = (string) ($_POST['action'] ?? '');

/* --------- Toutes les actions (y compris l'export) : POST + CSRF ---------
   Le jeton voyage dans le corps POST, jamais dans l'URL : il n'apparaît
   donc ni dans l'historique du navigateur, ni dans les journaux du
   serveur, ni dans un éventuel en-tête Referer. */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reponse_json(['ok' => false, 'erreur' => 'Méthode non autorisée.'], 405);
}
/* Fichier plus gros que « post_max_size » : PHP a vidé $_POST, donc le
   jeton CSRF manque. Sans ce test, l'utilisateur lirait « jeton de
   sécurité invalide » au lieu de comprendre que son image est trop
   lourde. À vérifier AVANT le contrôle du jeton, forcément. */
if (post_trop_gros()) {
    reponse_json(['ok' => false, 'erreur' => message_post_trop_gros()], 413);
}
if (!csrf_valide($_POST['csrf'] ?? null)) {
    reponse_json(['ok' => false, 'erreur' => 'Jeton de sécurité invalide. Rechargez la page.'], 403);
}
/* Forfait « bloqué » : consultation seule. Refusé ICI, avant tout
   traitement, pour toute action de ACTIONS_BLOQUEES — ce que les pages
   cachent n'engage que le navigateur. L'export, la gestion du compte et sa
   suppression restent ouverts. */
if (action_bloquee($moi, $action)) {
    reponse_json(['ok' => false, 'bloque' => true,
        'erreur' => message_compte_bloque((string) ($moi['raison_blocage'] ?? ''))], 403);
}
/* Actions d'administration : réservées aux comptes `admin`, refusées ICI
   avant le switch — la page admin.php n'est qu'une politesse. Relu en base à
   chaque requête (utilisateur_actuel) : un droit retiré s'éteint aussitôt. */
if (action_admin($action) && !est_admin($moi)) {
    reponse_json(['ok' => false, 'erreur' => 'Accès réservé aux administrateurs.'], 403);
}

/**
 * Exige le mot de passe du compte, ou répond 403 — en comptant les
 * échecs. Sans ce comptage, une session volée permettait de deviner le
 * mot de passe par essais illimités, et chaque essai coûtait au serveur
 * un password_verify() volontairement lent : de quoi saturer le
 * processeur d'un hébergement mutualisé avec quelques requêtes.
 *
 * Un compte Google, lui, doit s'être reconnecté (Google, puis son mot de
 * passe s'il en a un) pour CETTE action : voir verifier_mot_de_passe_limite.
 */
function exiger_mot_de_passe(int $mon_id, string $action): void
{
    $attente = null;
    if (verifier_mot_de_passe_limite($mon_id, (string) ($_POST['mot_de_passe'] ?? ''), $attente, $action)) {
        return;
    }
    if (compte_google($mon_id)) {
        reponse_json(['ok' => false, 'champ' => 'mot_de_passe', 'google' => true,
            'erreur' => message_reconnexion_google($action)], 403);
    }
    if ($attente > 0) {
        /* « attente » accompagne le message : le navigateur en fait un
           compte à rebours, le message reste lisible sans JavaScript. */
        reponse_json(['ok' => false, 'attente' => $attente, 'champ' => 'mot_de_passe', 'erreur' =>
            'Trop de tentatives. Réessayez dans ' . $attente . ' secondes.'], 429);
    }
    // « champ » : le navigateur affiche le message sous le champ concerné.
    reponse_json(['ok' => false, 'champ' => 'mot_de_passe', 'erreur' => 'Mot de passe incorrect.'], 403);
}

/* --------- Export : seule action qui ne répond pas en JSON ---------
   Le JSON est écrit au fil de l'eau plutôt que construit en mémoire.
   L'ancienne version chargeait TOUTES les couvertures puis les encodait
   en base64 dans un seul tableau : 150 séries suffisaient à dépasser la
   mémoire allouée par un hébergement mutualisé, et l'export échouait
   silencieusement sur une page blanche. Ici, une seule image est en
   mémoire à la fois.

   Version 2 : les favoris sont exportés. Le lien MangaDex, lui, ne l'est
   pas à part : il se déduit de l'URL de la couverture, à l'import comme
   partout ailleurs (mangadex_id_depuis_url). */
if ($action === 'donnees.exporter') {
    $req = $pdo->prepare(
        'SELECT titre, auteur, tome_actuel, statut, favori, couverture, cree_le, maj_le
           FROM serie WHERE utilisateur_id = ? ORDER BY titre'
    );
    $req->execute([$mon_id]);

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="bibliotheque-' . date('Y-m-d') . '.json"');
    header('Cache-Control: no-store, private');

    $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    echo '{', "\n";
    echo '  "application": "Ma Bibliothèque Manga",', "\n";
    echo '  "version": 2,', "\n";
    echo '  "exporte_le": ', json_encode(date('c'), $options), ",\n";
    echo '  "profil": ', json_encode([
        'identifiant' => $moi['identifiant'],
        'email'       => $moi['email'],
        'photo'       => url_image_sure($moi['photo']),
    ], $options), ",\n";
    echo '  "series": [';

    $premiere = true;
    while ($s = $req->fetch()) {
        $couverture = url_image_sure($s['couverture'] ?? '');
        $s['couverture'] = $couverture;
        $s['favori']     = (int) $s['favori'] === 1;

        // Les couvertures envoyées comme fichier n'existent que sur ce
        // serveur : on embarque leurs données pour que l'import les
        // restitue ailleurs. Les couvertures en lien https n'ont pas
        // besoin de ça, le lien suffit.
        if ($couverture !== '' && str_starts_with($couverture, 'uploads/')) {
            $chemin = CHEMIN_RACINE . '/' . $couverture;
            $contenu = is_file($chemin) ? @file_get_contents($chemin) : false;
            if ($contenu !== false) {
                $ext  = strtolower((string) pathinfo($couverture, PATHINFO_EXTENSION));
                $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                         'gif' => 'image/gif', 'webp' => 'image/webp'][$ext] ?? 'image/jpeg';
                $s['couverture_donnees'] = 'data:' . $mime . ';base64,' . base64_encode($contenu);
                unset($contenu);
            }
        }

        echo $premiere ? "\n    " : ",\n    ";
        echo json_encode($s, $options);
        $premiere = false;

        unset($s);
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    echo "\n  ]\n}\n";
    exit;
}

/** Relit une série en s'assurant qu'elle appartient bien au compte connecté. */
function ma_serie(PDO $pdo, int $mon_id, int $id): array
{
    $req = $pdo->prepare(
        'SELECT id, titre, auteur, tome_actuel, statut, couverture, mangadex_id, favori
           FROM serie WHERE id = ? AND utilisateur_id = ?'
    );
    $req->execute([$id, $mon_id]);
    $s = $req->fetch();
    if (!$s) {
        reponse_json(['ok' => false, 'erreur' => 'Série introuvable.'], 404);
    }
    return $s;
}

switch ($action) {

    /* ---------------- La session vit-elle encore ? ----------------
       Posée par une page que le navigateur ressort de son cache de
       navigation arrière (voir « pageshow » dans js/commun.js). Arrivée
       jusqu'ici, la réponse est oui : exiger_connexion_api() a déjà
       répondu 401 sinon. */
    case 'session.verifier': {
        reponse_json(['ok' => true]);
    }

    /* ---------------- Confirmer son identité pour une action ----------------
       Première moitié du parcours en deux temps d'un compte e-mail : entrer
       son mot de passe SANS agir encore, pour que la page montre ensuite
       « Identité confirmée : il vous reste N secondes », exactement comme un
       compte Google au retour de sa reconnexion. exiger_mot_de_passe() note
       la confirmation elle-même (verifier_mot_de_passe_limite()) ; l'action
       demandée la consomme au moment où elle a lieu, un peu plus tard. */
    case 'compte.authentifier': {
        $pour = (string) ($_POST['pour'] ?? '');
        if (!isset(ACTIONS_SENSIBLES[$pour])) {
            reponse_json(['ok' => false, 'erreur' => 'Action inconnue.'], 422);
        }
        exiger_mot_de_passe($mon_id, $pour);
        reponse_json(['ok' => true, 'restant' => CONFIRMATION_DUREE]);
    }

    /* ---------------- Ajout / modification ---------------- */
    case 'serie.enregistrer': {
        $id     = (int) ($_POST['id'] ?? 0);
        $titre  = texte($_POST['titre'] ?? '', 190);
        $auteur = texte($_POST['auteur'] ?? '', 190);
        $tome   = max(0, min(TOME_MAX, (int) ($_POST['tome_actuel'] ?? 0)));
        $statut = (string) ($_POST['statut'] ?? 'cours');

        if ($titre === '') {
            reponse_json(['ok' => false, 'champ' => 'titre', 'erreur' => MESSAGE_CHAMP_OBLIGATOIRE], 422);
        }
        if (!isset(STATUTS[$statut])) {
            $statut = 'cours';
        }
        /* Une adresse refusée était ignorée sans un mot : la série
           s'enregistrait « ✅ »… sans l'image que l'on croyait avoir mise. */
        if (url_image_refusee($_POST['couverture_url'] ?? '')) {
            reponse_json(['ok' => false, 'champ' => 'couverture_url', 'erreur' => MESSAGE_URL_IMAGE_REFUSEE], 422);
        }

        $ancienne = '';
        if ($id > 0) {
            $ancienne = (string) ma_serie($pdo, $mon_id, $id)['couverture'];
        } elseif ($moi['forfait'] !== 'illimite') {
            /* Contrôle préalable, pour ne pas décompresser et ré-encoder une
               image que l'insertion va refuser de toute façon. Ce n'est
               PAS le garde-fou : celui-ci est dans l'INSERT plus bas, qui
               seul résiste à deux requêtes simultanées. */
            $req = $pdo->prepare('SELECT COUNT(*) FROM serie WHERE utilisateur_id = ?');
            $req->execute([$mon_id]);
            if ((int) $req->fetchColumn() >= MAX_SERIES_PAR_UTILISATEUR) {
                reponse_json(['ok' => false, 'erreur' =>
                    'Vous avez atteint la limite de ' . MAX_SERIES_PAR_UTILISATEUR . ' séries par compte. '
                    . "Merci de contacter l'administrateur à " . ADMIN_EMAIL . '.'], 422);
            }
        }

        // Priorité : fichier envoyé > URL saisie > image retirée > image actuelle
        $erreur_image = null;
        $fichier = enregistrer_image('couverture_fichier', $erreur_image);
        if ($erreur_image !== null) {
            reponse_json(['ok' => false, 'champ' => 'couverture', 'erreur' => $erreur_image], 422);
        }

        $url_saisie = url_image_sure($_POST['couverture_url'] ?? '');
        $retiree    = (($_POST['couverture_retiree'] ?? '0') === '1');

        if ($fichier !== null) {
            $couverture = $fichier;
        } elseif ($url_saisie !== '') {
            $couverture = $url_saisie;
        } elseif ($retiree) {
            $couverture = '';
        } else {
            $couverture = url_image_sure($ancienne);
        }

        /* Le lien vers MangaDex se LIT dans l'image retenue. Rien à
           transmettre depuis le formulaire, rien qui puisse diverger :
           une image envoyée ou une URL d'ailleurs donne une chaîne vide,
           et le lien disparaît de lui-même. */
        $lien = mangadex_id_depuis_url($couverture);

        if ($id > 0) {
            $req = $pdo->prepare(
                'UPDATE serie SET titre = ?, auteur = ?, tome_actuel = ?, statut = ?,
                        couverture = ?, mangadex_id = ?
                  WHERE id = ? AND utilisateur_id = ?'
            );
            $req->execute([$titre, $auteur, $tome, $statut, $couverture, $lien, $id, $mon_id]);
            $message = 'Série mise à jour ✅';
        } else {
            /* Le quota est appliqué PAR LA BASE, dans l'insertion elle-même.
               Un COUNT suivi d'un INSERT laissait passer deux requêtes
               simultanées : toutes deux comptaient 149 et écrivaient, et le
               compte finissait à 151. Ici MySQL compte et insère d'un seul
               tenant, la course n'existe plus. */
            $req = $pdo->prepare(
                'INSERT INTO serie (utilisateur_id, titre, auteur, tome_actuel, statut, couverture, mangadex_id)
                 SELECT ?, ?, ?, ?, ?, ?, ?
                   FROM DUAL
                  WHERE ? = 1
                     OR (SELECT n FROM (SELECT COUNT(*) AS n FROM serie WHERE utilisateur_id = ?) AS c) < ?'
            );
            $req->execute([
                $mon_id, $titre, $auteur, $tome, $statut, $couverture, $lien,
                $moi['forfait'] === 'illimite' ? 1 : 0,
                $mon_id, MAX_SERIES_PAR_UTILISATEUR,
            ]);

            if ($req->rowCount() === 0) {
                // Refusé par le quota : l'image qu'on vient d'écrire n'a
                // plus aucune ligne pour la référencer.
                if ($fichier !== null) {
                    supprimer_image_locale($fichier);
                }
                reponse_json(['ok' => false, 'erreur' =>
                    'Vous avez atteint la limite de ' . MAX_SERIES_PAR_UTILISATEUR . ' séries par compte. '
                    . 'Merci de contacter l\'administrateur à ' . ADMIN_EMAIL . '.'], 422);
            }

            $id = (int) $pdo->lastInsertId();
            $message = 'Série ajoutée ✅';
        }

        // L'ancienne image locale devenue inutile est effacée du disque
        if ($ancienne !== '' && $ancienne !== $couverture) {
            supprimer_image_locale($ancienne);
        }

        $s = ma_serie($pdo, $mon_id, $id);
        reponse_json([
            'ok'      => true,
            'id'      => $id,
            'carte'   => carte_html($s),
            'compte'  => compter_series($pdo, $mon_id),
            'message' => $message,
        ]);
    }

    /* ---------------- Avancer d'un tome ---------------- */
    case 'serie.avancer': {
        $id = (int) ($_POST['id'] ?? 0);
        $s  = ma_serie($pdo, $mon_id, $id);

        // Même borne que serie.enregistrer : sans elle, cliquer assez
        // longtemps finissait par dépasser la capacité de la colonne.
        $tome = min(TOME_MAX, (int) $s['tome_actuel'] + 1);
        // Dès qu'on avance dans une série « à commencer / envie »,
        // elle passe automatiquement « En cours ».
        $demarre = ($s['statut'] === 'envie');
        $statut  = $demarre ? 'cours' : $s['statut'];

        $req = $pdo->prepare('UPDATE serie SET tome_actuel = ?, statut = ? WHERE id = ? AND utilisateur_id = ?');
        $req->execute([$tome, $statut, $id, $mon_id]);

        $s['tome_actuel'] = $tome;
        $s['statut']      = $statut;
        reponse_json([
            'ok'      => true,
            'carte'   => carte_html($s),
            'compte'  => compter_series($pdo, $mon_id),
            'message' => $demarre
                ? '« ' . $s['titre'] . ' » → tome ' . $tome . ' 📖 (passée en « En cours »)'
                : '« ' . $s['titre'] . ' » → tome ' . $tome . ' 📖',
        ]);
    }

    /* ---------------- Annuler (revenir d'un tome) ---------------- */
    case 'serie.reculer': {
        $id = (int) ($_POST['id'] ?? 0);
        $s  = ma_serie($pdo, $mon_id, $id);

        $tome = max(0, (int) $s['tome_actuel'] - 1);
        $req = $pdo->prepare('UPDATE serie SET tome_actuel = ? WHERE id = ? AND utilisateur_id = ?');
        $req->execute([$tome, $id, $mon_id]);

        $s['tome_actuel'] = $tome;
        reponse_json([
            'ok'      => true,
            'carte'   => carte_html($s),
            'compte'  => compter_series($pdo, $mon_id),
            'message' => '« ' . $s['titre'] . ' » → retour au tome ' . $tome . ' ↩️',
        ]);
    }

    /* ---------------- Suppression ---------------- */
    case 'serie.supprimer': {
        $id = (int) ($_POST['id'] ?? 0);
        $s  = ma_serie($pdo, $mon_id, $id);

        $req = $pdo->prepare('DELETE FROM serie WHERE id = ? AND utilisateur_id = ?');
        $req->execute([$id, $mon_id]);
        supprimer_image_locale($s['couverture']);

        reponse_json([
            'ok'      => true,
            'id'      => $id,
            'compte'  => compter_series($pdo, $mon_id),
            'message' => '« ' . $s['titre'] . ' » supprimée',
        ]);
    }

    /* ---------------- Profil ---------------- */
    /* « preparer = 1 » (compte Google) : tout vérifier, puis RANGER la
       saisie dans la session au lieu de l'enregistrer — le navigateur part
       ensuite se reconnecter chez Google, et la page repartira de zéro.
       « en_attente = 1 » : au retour, enregistrer ce qui a été rangé (voir
       google_attente). Le reste du chemin est le même. */
    case 'compte.profil': {
        $preparer = ($_POST['preparer'] ?? '') === '1';
        $attente  = null;
        if (($_POST['en_attente'] ?? '') === '1') {
            $attente = google_attente($_SESSION['google_attente'] ?? null, $mon_id, 'compte.profil', time());
            if ($attente === null) {
                reponse_json(['ok' => false, 'erreur' => 'Ces modifications ne sont plus en attente : saisissez-les à nouveau.'], 409);
            }
        }
        $identifiant = $attente ? (string) $attente['identifiant'] : texte($_POST['identifiant'] ?? '', 50);
        $email       = $attente ? (string) $attente['email'] : texte($_POST['email'] ?? '', 190);

        /* « erreurs » range les messages par champ : le navigateur les pose
           chacun sous le sien. « erreur » les reprend tous, pour qui n'en
           lit qu'un. */
        if ($faiblesses = valider_profil($identifiant, $email, $mon_id)) {
            reponse_json(['ok' => false, 'erreurs' => $faiblesses, 'erreur' => implode(' ', $faiblesses)], 422);
        }
        if (!$attente && url_image_refusee($_POST['photo_url'] ?? '')) {
            reponse_json(['ok' => false, 'champ' => 'photo_url', 'erreur' => MESSAGE_URL_IMAGE_REFUSEE], 422);
        }

        $email_change       = (strcasecmp($email, (string) $moi['email']) !== 0);
        $identifiant_change = ($identifiant !== (string) $moi['identifiant']);
        $ancienne           = (string) $moi['photo'];

        $photo_saisie = static function () use ($ancienne): string {
            $erreur_image = null;
            $fichier = enregistrer_image('photo_fichier', $erreur_image);
            if ($erreur_image !== null) {
                reponse_json(['ok' => false, 'champ' => 'photo', 'erreur' => $erreur_image], 422);
            }
            return photo_depuis_formulaire($fichier, $ancienne);
        };

        if ($preparer) {
            if (!compte_google($mon_id) || !($email_change || $identifiant_change)) {
                reponse_json(['ok' => false, 'erreur' => 'Rien à mettre en attente.'], 422);
            }
            /* Pas de email_disponible() ici : dire qu'une adresse est prise
               AVANT la preuve d'identité révélerait qu'un compte existe. Elle
               est vérifiée à l'enregistrement. Une photo envoyée est déjà
               écrite : jamais adoptée, purger.php l'effacera. */
            $_SESSION['google_attente'] = [
                'id' => $mon_id, 'action' => 'compte.profil', 'le' => time(),
                'donnees' => ['identifiant' => $identifiant, 'email' => $email, 'photo' => $photo_saisie()],
            ];
            reponse_json(['ok' => true]);
        }

        /* Ces deux champs servent à se connecter et à récupérer le compte :
           qui les contrôle contrôle le compte. Le mot de passe est donc
           exigé pour l'un comme pour l'autre — sinon une session volée
           suffit à renommer le compte (la victime ne peut plus se
           connecter) ou à détourner l'adresse de récupération.
           La photo ne demande rien. */
        if ($email_change || $identifiant_change) {
            exiger_mot_de_passe($mon_id, 'compte.profil');
        }
        if ($email_change && !email_disponible($email, $mon_id)) {
            reponse_json(['ok' => false, 'champ' => 'email', 'erreur' => 'Cette adresse e-mail est déjà utilisée.'], 422);
        }

        $photo = $attente ? (string) $attente['photo'] : $photo_saisie();

        /* La nouvelle adresse n'est PAS écrite ici. Elle attend dans le
           jeton, et ne remplacera l'ancienne qu'au clic sur le lien de
           confirmation envoyé à cette nouvelle adresse (voir
           verifier-email.php). Deux raisons :
             - une session volée ne peut pas détourner l'adresse de
               récupération sans accès à la boîte visée ;
             - une simple faute de frappe ne rend pas le compte
               irrécupérable, puisque l'ancienne adresse reste active. */
        $req = $pdo->prepare(
            'UPDATE utilisateur SET identifiant = ?, photo = ? WHERE id = ?'
        );
        $req->execute([$identifiant, $photo, $mon_id]);
        if ($email_change || $identifiant_change) {
            oublier_confirmation();   // elle ne sert qu'une fois
        }
        unset($_SESSION['google_attente']);

        if ($ancienne !== '' && $ancienne !== $photo) {
            supprimer_image_locale($ancienne);
        }

        $message = 'Profil enregistré ✅';
        if ($email_change) {
            journal_securite('changement_email_demande', [
                'utilisateur' => $mon_id, 'vers' => $email,
            ]);
            avertir_changement_email_demande($mon_id, (string) $moi['email'], $identifiant, $email, acces_compte($moi['google_sub'], (int) $moi['sans_mot_de_passe'] === 0));

            $jeton = generer_jeton_action($mon_id, 'changement_email', 86400, $email);
            $envoye = envoyer_email(
                $email,
                'Confirmez votre nouvelle adresse e-mail',
                "Bonjour {$identifiant},\n\n"
                . "Une demande de changement d'adresse e-mail a été faite sur votre compte.\n"
                . "Confirmez cette nouvelle adresse en cliquant sur ce lien (valable 24 h) :\n"
                . url_publique('verifier-email.php?jeton=' . $jeton) . "\n\n"
                . "Tant que vous n'aurez pas cliqué, votre compte conservera son adresse actuelle.\n"
                . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message."
            );
            $message .= $envoye
                ? " — un lien de confirmation vient d'être envoyé à {$email}. Votre adresse actuelle reste active jusqu'au clic."
                : " — mais l'e-mail de confirmation n'a pas pu être envoyé. Votre adresse actuelle reste active.";
        }

        reponse_json([
            'ok'        => true,
            'photo'     => $photo,
            /* L'adresse ACTIVE, que le champ doit réafficher : il montrait
               la nouvelle, pas encore confirmée, et l'on ne savait plus
               laquelle comptait. La demande en cours s'affiche à part. */
            'email'     => $moi['email'],
            'email_attente' => $email_change ? changement_email_en_attente($mon_id) : null,
            'identifiant' => $identifiant,
            'initiales' => initiales(['identifiant' => $identifiant]),
            'message'   => $message,
        ]);
    }

    /* ---------------- Mot de passe ----------------
       « verifier = 1 » contrôle tout sans rien changer. Les Paramètres
       s'en servent AVANT de laisser partir leur formulaire : celui-ci doit
       rester un vrai POST (c'est la navigation qui fait proposer au
       gestionnaire de mots de passe d'enregistrer le nouveau), mais un
       POST refusé revenait avec les trois champs vides. Vérifié d'abord,
       il ne part que s'il va réussir, et une erreur laisse la saisie en
       place. Le POST, lui, revérifie tout. */
    /* « preparer » et « en_attente » : comme pour le Profil. Seule
       l'empreinte du nouveau mot de passe attend dans la session, jamais
       le mot de passe lui-même. */
    case 'compte.motdepasse': {
        $actuel   = (string) ($_POST['actuel'] ?? '');
        $nouveau  = (string) ($_POST['nouveau'] ?? '');
        $confirm  = (string) ($_POST['confirmation'] ?? '');
        $preparer = ($_POST['preparer'] ?? '') === '1';
        $en_attente = null;
        if (($_POST['en_attente'] ?? '') === '1') {
            $en_attente = google_attente($_SESSION['google_attente'] ?? null, $mon_id, 'compte.motdepasse', time());
            if ($en_attente === null) {
                reponse_json(['ok' => false, 'erreur' => 'Ce mot de passe n\'est plus en attente : saisissez-le à nouveau.'], 409);
            }
        }

        $erreurs = [];
        $attente = null;
        if (!$preparer && !verifier_mot_de_passe_limite($mon_id, $actuel, $attente, 'compte.motdepasse')) {
            $erreurs['actuel'] = $attente > 0
                ? 'Trop de tentatives. Réessayez dans ' . $attente . ' secondes.'
                : ((string) $moi['google_sub'] !== ''
                    ? message_reconnexion_google('compte.motdepasse')
                    : 'Mot de passe actuel incorrect.');
        }
        if (!$en_attente) {
            if ($faiblesses = valider_mot_de_passe($nouveau, (string) $moi['identifiant'])) {
                $erreurs['nouveau'] = $faiblesses;
            }
            if ($confirm === '') {
                $erreurs['confirmation'] = MESSAGE_CHAMP_OBLIGATOIRE;
            } elseif ($nouveau !== $confirm) {
                $erreurs['confirmation'] = 'Les deux nouveaux mots de passe ne correspondent pas.';
            }
        }
        if ($erreurs) {
            $tous = [];
            array_walk_recursive($erreurs, static function ($m) use (&$tous) { $tous[] = $m; });
            reponse_json(['ok' => false, 'attente' => $attente, 'erreurs' => $erreurs,
                'erreur' => implode(' ', $tous)], $attente > 0 ? 429 : 422);
        }
        if (($_POST['verifier'] ?? '') === '1') {
            reponse_json(['ok' => true]);
        }
        if ($preparer) {
            if (!compte_google($mon_id)) {
                reponse_json(['ok' => false, 'erreur' => 'Rien à mettre en attente.'], 422);
            }
            $_SESSION['google_attente'] = [
                'id' => $mon_id, 'action' => 'compte.motdepasse', 'le' => time(),
                'donnees' => ['empreinte' => password_hash($nouveau, PASSWORD_DEFAULT)],
            ];
            reponse_json(['ok' => true]);
        }

        $req = $pdo->prepare('UPDATE utilisateur SET mot_de_passe = ? WHERE id = ?');
        $req->execute([$en_attente ? (string) $en_attente['empreinte'] : password_hash($nouveau, PASSWORD_DEFAULT), $mon_id]);
        unset($_SESSION['google_attente']);

        /* Toutes les sessions du compte sont invalidées : les autres
           appareils (et un éventuel attaquant déjà connecté) sont
           réellement déconnectés, pas seulement privés du « se souvenir
           de moi ». On se re-connecte ensuite pour ne pas s'éjecter
           soi-même de la page en cours. */
        invalider_sessions($mon_id);
        connecter($mon_id);
        oublier_confirmation();   // elle ne sert qu'une fois
        journal_securite('mot_de_passe_change', ['utilisateur' => $mon_id]);
        avertir_mot_de_passe_change((string) $moi['email'], (string) $moi['identifiant'], acces_compte($moi['google_sub'], (int) $moi['sans_mot_de_passe'] === 0));

        reponse_json([
            'ok'      => true,
            'csrf'    => jeton_csrf(),
            'message' => (int) $moi['sans_mot_de_passe'] === 1
                ? 'Mot de passe défini ✅ — il vous sera demandé après Google, à chaque connexion.'
                : 'Mot de passe modifié ✅ — les autres appareils ont été déconnectés.',
        ]);
    }

    /* ---------------- Clés d'accès ----------------
       Ajouter une clé est une action sensible (ACTIONS_SENSIBLES) : elle
       ouvre le compte sans mot de passe, il faut donc avoir prouvé son
       identité pour CETTE action. Deux appels, comme la fenêtre de
       confirmation les enchaîne :
         cle.options  le défi et les options de création (ne consomme rien) ;
         cle.creer    la réponse du gestionnaire de mots de passe : vérifiée,
                      enregistrée, et c'est seulement alors que la
                      confirmation est consommée.
       Retirer une clé n'ouvre rien : pas de confirmation, un compte ne
       perd jamais l'accès (il garde son mot de passe ou Google). */
    case 'cle.options': {
        if (!confirmation_recente($mon_id, 'compte.cle_creer')) {
            reponse_json(['ok' => false, 'reconfirmer' => true,
                'erreur' => "Confirmez de nouveau votre identité pour ajouter une clé d'accès."], 403);
        }
        $existantes = cle_lister($mon_id);
        if (count($existantes) >= CLE_ACCES_MAX) {
            reponse_json(['ok' => false, 'erreur' => 'Vous avez atteint la limite de ' . CLE_ACCES_MAX
                . " clés d'accès. Retirez-en une pour en ajouter une autre."], 422);
        }
        $defi = cle_defi_creer('creation', $mon_id);
        reponse_json(['ok' => true,
            'options' => cle_options_creation($defi, $moi, array_column($existantes, 'cle_id'), cle_rp_id())]);
    }

    case 'cle.creer': {
        $defi = cle_defi_prendre('creation', $mon_id);   // consommé d'abord : il ne sert qu'une fois
        if (!confirmation_recente($mon_id, 'compte.cle_creer')) {
            reponse_json(['ok' => false, 'reconfirmer' => true,
                'erreur' => "Confirmez de nouveau votre identité pour ajouter une clé d'accès."], 403);
        }
        if ($defi === null) {
            reponse_json(['ok' => false, 'renouveler' => true,
                'erreur' => "La demande a expiré. Cliquez de nouveau pour ajouter la clé d'accès."], 409);
        }
        $client      = base64url_decoder((string) ($_POST['client_data'] ?? ''));
        $attestation = base64url_decoder((string) ($_POST['attestation'] ?? ''));
        if ($client === null || $attestation === null || strlen($client) > 4096 || strlen($attestation) > CLE_CBOR_TAILLE_MAX) {
            reponse_json(['ok' => false, 'erreur' => "La réponse du gestionnaire de mots de passe est illisible."], 422);
        }

        $verdict = cle_verifier_creation($client, $attestation, $defi, cle_origine(), cle_rp_id());
        if (!$verdict['ok']) {
            journal_securite('cle_acces_refusee', ['utilisateur' => $mon_id, 'raison' => $verdict['raison']]);
            reponse_json(['ok' => false, 'erreur' => "Cette clé d'accès n'a pas pu être vérifiée : elle n'a pas été ajoutée."], 422);
        }

        $nom      = cle_nom($_POST['nom'] ?? '');
        $resultat = cle_ajouter($mon_id, $verdict['identifiant'], $verdict['cle_pem'], $verdict['compteur'], $nom);
        if ($resultat === 'deja') {
            reponse_json(['ok' => false, 'erreur' => "Cette clé d'accès est déjà enregistrée."], 409);
        }
        if ($resultat === 'limite') {
            reponse_json(['ok' => false, 'erreur' => 'Vous avez atteint la limite de ' . CLE_ACCES_MAX . " clés d'accès."], 422);
        }

        oublier_confirmation();   // elle ne sert qu'une fois
        journal_securite('cle_acces_ajoutee', ['utilisateur' => $mon_id]);
        avertir_cle_acces_ajoutee((string) $moi['email'], (string) $moi['identifiant'], $nom,
            acces_compte($moi['google_sub'], (int) $moi['sans_mot_de_passe'] === 0));
        reponse_json(['ok' => true, 'message' => "Clé d'accès ajoutée ✅"]);
    }

    case 'cle.supprimer': {
        if (!cle_supprimer((int) ($_POST['id'] ?? 0), $mon_id)) {
            reponse_json(['ok' => false, 'erreur' => "Clé d'accès introuvable."], 404);
        }
        journal_securite('cle_acces_retiree', ['utilisateur' => $mon_id]);
        reponse_json(['ok' => true, 'message' => "Clé d'accès retirée ✅"]);
    }

    /* ---------------- Renoncer à l'action confirmée ----------------
       « Annuler » dans la fenêtre de confirmation d'un compte Google :
       le délai s'arrête là, et la refaire demandera de se reconnecter. */
    case 'compte.annuler_confirmation': {
        oublier_confirmation();
        unset($_SESSION['google_attente']);   // les modifications qui attendaient partent avec elle
        reponse_json(['ok' => true]);
    }

    /* ---------------- Renvoyer l'e-mail de confirmation ---------------- */
    case 'compte.renvoyer_verification': {
        if ((int) $moi['email_verifie'] === 1) {
            reponse_json(['ok' => true, 'message' => 'Votre e-mail est déjà vérifié.']);
        }

        $jeton   = generer_jeton_action($mon_id, 'verification', 86400);
        $envoye  = envoyer_email(
            $moi['email'],
            'Confirmez votre adresse e-mail',
            "Bonjour {$moi['identifiant']},\n\nConfirmez votre adresse e-mail en cliquant sur ce lien "
            . "(valable 24 h) :\n" . url_publique('verifier-email.php?jeton=' . $jeton)
        );

        reponse_json([
            'ok'      => $envoye,
            'message' => $envoye
                ? 'E-mail de confirmation renvoyé ✅'
                : "Échec de l'envoi. Réessayez plus tard ou contactez l'administrateur à " . ADMIN_EMAIL . '.',
        ]);
    }

    /* ---------------- Import d'une sauvegarde ---------------- */
    case 'donnees.importer': {
        if (empty($_FILES['sauvegarde']) || $_FILES['sauvegarde']['error'] !== UPLOAD_ERR_OK) {
            reponse_json(['ok' => false, 'erreur' => 'Aucun fichier reçu.'], 422);
        }
        if ($_FILES['sauvegarde']['size'] > IMPORT_TAILLE_MAX) {
            reponse_json(['ok' => false, 'erreur' => 'Fichier trop volumineux ('
                . taille_lisible(IMPORT_TAILLE_MAX) . ' maximum).'], 422);
        }
        if (!is_uploaded_file($_FILES['sauvegarde']['tmp_name'])) {
            reponse_json(['ok' => false, 'erreur' => 'Fichier invalide.'], 422);
        }
        $brut = file_get_contents($_FILES['sauvegarde']['tmp_name']);
        $data = json_decode((string) $brut, true);
        unset($brut);

        if (!is_array($data) || !isset($data['series']) || !is_array($data['series'])) {
            reponse_json(['ok' => false, 'erreur' => "Ce fichier n'est pas une sauvegarde valide."], 422);
        }

        $importees  = 0;
        $plafonnees = 0;
        $presentes  = 0;   // déjà dans la bibliothèque : jamais dupliquées
        $completees = 0;   // … parmi elles, celles à qui l'import a rendu une image ou l'étoile
        $images_orphelines = [];
        /* Les images recréées sur disque pendant l'import ne font pas
           partie de la transaction : un ROLLBACK annule les lignes, pas
           les fichiers. On les suit pour pouvoir les effacer nous-mêmes
           si l'import échoue, au lieu de les laisser traîner. */
        $images_creees = [];

        /* Une image envoyée comme fichier (et non comme lien https) a été
           transmise en base64 par l'export : on la recrée sur disque ici,
           sinon le chemin « uploads/xxx » d'origine ne pointe vers rien sur
           ce serveur. Recréer la même image redonne le même fichier (son
           nom est l'empreinte de son contenu) : rien ne s'accumule. */
        $couverture_de = static function (array $s) use (&$images_creees): string {
            if ($s['donnees'] === '') {
                return $s['couverture'];   // une adresse https, ou rien (voir import_serie())
            }
            $chemin = enregistrer_image_depuis_donnees($s['donnees']) ?? '';
            if ($chemin !== '') {
                $images_creees[] = $chemin;
            }
            return $chemin;
        };

        /* La transaction doit être refermée quoi qu'il arrive : sans ce
           try/catch, une seule ligne invalide laissait la transaction
           ouverte et la connexion dans un état incohérent pour le reste
           de la requête. */
        try {
            /* « Même série » = même titre, à la casse et aux accents près
               (c'est la collation de la colonne qui compare). Réimporter
               une sauvegarde ne crée donc plus de doublons — le même
               fichier importé deux fois donnait 300 séries dont 150 en
               double. La série déjà là n'est pas écrasée : elle est
               peut-être plus avancée que la sauvegarde. Elle reçoit
               seulement ce qui lui MANQUE — l'image si elle n'en a pas,
               l'étoile si la sauvegarde la portait. */
            $existante = $pdo->prepare(
                'SELECT id, couverture, favori FROM serie
                  WHERE utilisateur_id = ? AND titre = ? ORDER BY id LIMIT 1'
            );
            $completer = $pdo->prepare(
                'UPDATE serie SET couverture = ?, mangadex_id = ?, favori = ?
                  WHERE id = ? AND utilisateur_id = ?'
            );
            /* Le quota est appliqué par l'insertion elle-même, comme pour
               un ajout à la main : un décompte fait en PHP se laisse
               doubler par deux imports simultanés. */
            $inserer = $pdo->prepare(
                'INSERT INTO serie (utilisateur_id, titre, auteur, tome_actuel, statut, favori, couverture, mangadex_id)
                 SELECT ?, ?, ?, ?, ?, ?, ?, ?
                   FROM DUAL
                  WHERE ? = 1
                     OR (SELECT n FROM (SELECT COUNT(*) AS n FROM serie WHERE utilisateur_id = ?) AS c) < ?'
            );
            $pdo->beginTransaction();

            foreach ($data['series'] as $brute) {
                $s = import_serie($brute);
                if ($s === null) {
                    continue;
                }

                $existante->execute([$mon_id, $s['titre']]);
                if ($deja = $existante->fetch()) {
                    $presentes++;
                    $complement = import_complement(
                        (string) $deja['couverture'],
                        (int) $deja['favori'],
                        (string) $deja['couverture'] === '' ? $couverture_de($s) : '',
                        $s['favori']
                    );
                    if ($complement !== null) {
                        $completer->execute([
                            $complement['couverture'], mangadex_id_depuis_url($complement['couverture']),
                            $complement['favori'], (int) $deja['id'], $mon_id,
                        ]);
                        $completees++;
                    }
                    continue;
                }

                // Une fois la limite atteinte, la suite l'atteindra aussi :
                // inutile de recréer des images pour des lignes refusées.
                if ($plafonnees > 0) {
                    $plafonnees++;
                    continue;
                }
                $couverture = $couverture_de($s);
                $inserer->execute([
                    $mon_id,
                    $s['titre'],
                    $s['auteur'],
                    $s['tome'],
                    $s['statut'],
                    $s['favori'],
                    $couverture,
                    // Le suivi MangaDex revient avec l'image : il s'en déduit.
                    mangadex_id_depuis_url($couverture),
                    $moi['forfait'] === 'illimite' ? 1 : 0,
                    $mon_id,
                    MAX_SERIES_PAR_UTILISATEUR,
                ]);
                if ($inserer->rowCount() === 0) {
                    $plafonnees++;
                    $images_orphelines[] = $couverture;
                    continue;
                }
                $importees++;
            }

            $pdo->commit();
            // L'image de la ligne refusée par le quota n'a personne pour la
            // référencer (sauf si une autre série a la même : conservée).
            supprimer_images_locales($images_orphelines);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Les lignes sont annulées : les fichiers écrits pour elles
            // n'ont plus de raison d'être. supprimer_images_locales()
            // conserve ceux qu'une autre série référence encore — la
            // déduplication par empreinte fait qu'une image importée peut
            // être exactement celle d'une série déjà présente.
            supprimer_images_locales($images_creees);
            error_log('donnees.importer: ' . $e->getMessage());
            reponse_json(['ok' => false, 'erreur' => "L'import a échoué. Vérifiez le fichier et réessayez."], 500);
        }

        $phrases = [$importees . ' série(s) ajoutée(s) ✅'];
        if ($presentes > 0) {
            $phrases[] = $presentes . ' déjà présente(s) dans la bibliothèque, non dupliquée(s)'
                . ($completees > 0 ? ' — ' . $completees . ' complétée(s) (image ou favori)' : '');
        }
        if ($plafonnees > 0) {
            $phrases[] = $plafonnees . ' ignorée(s) car la limite de ' . MAX_SERIES_PAR_UTILISATEUR
                . ' séries par compte est atteinte. Contactez l\'administrateur à ' . ADMIN_EMAIL . ' si besoin';
        }

        reponse_json([
            'ok'         => true,
            'importees'  => $importees,
            'presentes'  => $presentes,
            'plafonnees' => $plafonnees,
            'message'    => implode('. ', $phrases) . '.',
        ]);
    }

    /* ---------------- Vider la bibliothèque ---------------- */
    case 'donnees.vider': {
        exiger_mot_de_passe($mon_id, 'donnees.vider');
        oublier_confirmation();   // elle ne sert qu'une fois

        $req = $pdo->prepare('SELECT couverture FROM serie WHERE utilisateur_id = ?');
        $req->execute([$mon_id]);
        $couvertures = array_column($req->fetchAll(), 'couverture');

        // Les lignes sont supprimées AVANT le nettoyage des fichiers : une
        // même image peut être partagée par plusieurs séries (voir
        // enregistrer_image), supprimer_images_locales() ne doit donc
        // l'effacer du disque qu'une fois qu'il n'y a plus aucune ligne
        // (dans toute l'appli) pour la référencer.
        $req = $pdo->prepare('DELETE FROM serie WHERE utilisateur_id = ?');
        $req->execute([$mon_id]);

        supprimer_images_locales($couvertures);
        journal_securite('bibliotheque_videe', ['utilisateur' => $mon_id, 'series' => count($couvertures)]);

        reponse_json(['ok' => true, 'message' => 'Bibliothèque vidée 🗑️']);
    }

    /* ---------------- Suppression complète du compte ---------------- */
    case 'compte.supprimer': {
        exiger_mot_de_passe($mon_id, 'compte.supprimer');

        $req = $pdo->prepare('SELECT couverture FROM serie WHERE utilisateur_id = ?');
        $req->execute([$mon_id]);
        $couvertures = array_column($req->fetchAll(), 'couverture');
        $couvertures[] = (string) $moi['photo'];

        $req = $pdo->prepare('DELETE FROM utilisateur WHERE id = ?');  // CASCADE supprime les séries
        $req->execute([$mon_id]);

        supprimer_images_locales($couvertures);
        revoquer_session_persistante();
        journal_securite('compte_supprime', ['utilisateur' => $mon_id, 'identifiant' => $moi['identifiant']]);

        /* Après la suppression, pas avant : on ne prévient que d'un
           effacement qui a réellement eu lieu. $moi est déjà en mémoire,
           la ligne n'a plus besoin d'exister pour qu'on sache où écrire. */
        avertir_compte_supprime((string) $moi['email'], (string) $moi['identifiant'], acces_compte($moi['google_sub'], (int) $moi['sans_mot_de_passe'] === 0));

        /* Une session vidée et renouvelée plutôt que détruite : la page
           de connexion qui suit doit pouvoir dire que c'est fait. Elle
           s'affichait sans un mot, et l'on se demandait si ça avait marché. */
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Votre compte et votre bibliothèque ont été supprimés. '
            . 'Un e-mail de confirmation a été envoyé à ' . $moi['email'] . '.');

        reponse_json(['ok' => true, 'message' => 'Compte supprimé.', 'redirection' => 'connexion.php']);
    }

    /* ---------------- Recherche automatique de couverture ----------------
       L'appel part du serveur et non du navigateur : MangaDex n'envoie
       pas d'en-tête « Access-Control-Allow-Origin », un fetch direct
       serait donc refusé. Voir includes/couvertures.php. */
    case 'couverture.chercher': {
        $titre = texte($_POST['titre'] ?? '', 190);
        if ($titre === '') {
            reponse_json(['ok' => false, 'erreur' => "Saisissez d'abord un titre."], 422);
        }
        $tome = max(1, min(TOME_MAX, (int) ($_POST['tome'] ?? 1)));

        /* Deux protections distinctes, et il faut les distinguer.

           Celle-ci encadre le COMPTE : une règle fixe et annoncée, que
           l'utilisateur peut vérifier lui-même. La dépasser n'est pas
           une faute et n'entraîne aucune sanction — ni blocage qui
           double, ni compteur de récidive : seulement l'attente de la
           tranche suivante.

           Celle qui protège le SERVEUR vit dans mangadex_get(), sous
           forme de file d'attente : elle ne compte personne. */
        $quota   = couverture_quota($moi);   // 0 : forfait illimité, rien n'est décompté
        $attente = $quota > 0 ? couverture_consommer($mon_id, $quota, COUVERTURE_FENETRE) : 0;
        if ($attente > 0) {
            // « limite » : c'est le quota du COMPTE, pas l'encombrement du site.
            reponse_json(['ok' => false, 'attente' => $attente, 'limite' => 'compte', 'erreur' =>
                'Limite atteinte : ' . $quota . ' recherches par '
                . secondes_lisibles(COUVERTURE_FENETRE)
                . '. Nouvelle recherche dans ' . $attente . ' secondes.'], 429);
        }

        /* Trois conditions, toutes nécessaires : le site l'autorise,
           l'utilisateur a déclaré sa majorité, et il a effectivement
           désactivé le filtre. La déclaration seule ne suffit pas — on
           peut être majeur et vouloir garder le filtre. */
        $adulte = COUVERTURE_CONTENU_ADULTE
            && (int) ($moi['adulte_confirme'] ?? 0) === 1
            && (int) ($moi['filtre_sensible'] ?? 1) === 0;
        $resultats = chercher_couvertures($titre, $tome, $adulte);

        /* Une liste vide peut vouloir dire deux choses très différentes :
           la série n'existe pas, ou l'appel n'est jamais parti — file
           trop longue, ou 429 renvoyé par MangaDex. Dans le second cas
           l'utilisateur n'y est pour rien : on lui dit quand revenir, et
           le compte à rebours de js/delai.js s'en charge. */
        $retard = mangadex_attente_suggeree();
        if ($resultats === [] && $retard > 0) {
            /* Rendue, puisqu'elle n'a rien donné et que l'utilisateur
               n'y est pour rien : son quota ne doit pas payer une
               indisponibilité du site. */
            if ($quota > 0) {
                couverture_rendre($mon_id);
            }
            reponse_json(['ok' => false, 'attente' => $retard, 'erreur' =>
                'Trop de recherches en cours sur le site. Réessayez dans '
                . $retard . ' secondes.'], 429);
        }

        reponse_json([
            'ok'        => true,
            'tome'      => $tome,
            'resultats' => $resultats,
            // Pas de solde à annoncer sans quota (forfait illimité).
            'recherches' => $quota > 0 ? [
                'restantes' => couverture_restantes($mon_id, $quota),
                'quota'     => $quota,
                'tranche'   => secondes_lisibles(COUVERTURE_FENETRE),
            ] : null,
            /* Signaler le filtre seulement quand il a pu retirer quelque
               chose ET que l'utilisateur peut y faire quelque chose. Si
               le site n'autorise pas la levée, le mentionner ne serait
               qu'une frustration sans issue. */
            'filtre'    => COUVERTURE_CONTENU_ADULTE && !$adulte,
            'message'   => $resultats
                ? count($resultats) . ' résultat(s) pour le tome ' . $tome
                : 'Aucune série trouvée pour « ' . $titre . ' ».',
        ]);
    }

    /* ---------------- Favori ----------------
       Un simple drapeau, que l'utilisateur pose et retire. Le serveur
       décide de la valeur finale plutôt que de la recevoir : deux
       frappes rapides sur l'étoile ne peuvent pas laisser l'affichage
       et la base en désaccord. */
    case 'serie.favori': {
        $id = (int) ($_POST['id'] ?? 0);
        $s  = ma_serie($pdo, $mon_id, $id);

        $pdo->prepare('UPDATE serie SET favori = 1 - favori WHERE id = ? AND utilisateur_id = ?')
            ->execute([$id, $mon_id]);
        $s['favori'] = empty($s['favori']) ? 1 : 0;

        reponse_json([
            'ok'      => true,
            'favori'  => (bool) $s['favori'],
            'carte'   => carte_html($s),
            // Le compteur du filtre « Favoris » suit tout de suite.
            'compte'  => compter_series($pdo, $mon_id),
            'message' => $s['favori']
                ? '« ' . $s['titre'] . ' » ajoutée aux favoris ★'
                : '« ' . $s['titre'] . ' » retirée des favoris',
        ]);
    }

    /* ---------------- Couverture d'une série déjà liée ----------------
       Une fois la série désignée chez MangaDex, retrouver la couverture
       du tome suivant ne demande plus de chercher ni de deviner : un
       seul appel, et il ne se trompe pas.

       Cette action ne consomme PAS le quota de recherche. Un quota sert
       à répartir équitablement une ressource coûteuse ; ici il s'agit
       d'un appel unique, souvent déclenché automatiquement en avançant
       d'un tome. Le faire payer au tarif d'une recherche — dix-huit
       appels — bloquerait la recherche manuelle de quelqu'un qui ne fait
       que rattraper sa série. La file d'attente, elle, s'applique : c'est
       elle qui protège l'adresse du serveur, et c'est ce qui compte. */
    case 'couverture.rafraichir': {
        $id = (int) ($_POST['id'] ?? 0);
        $s  = ma_serie($pdo, $mon_id, $id);

        $lien = (string) ($s['mangadex_id'] ?? '');
        if ($lien === '') {
            reponse_json(['ok' => false, 'erreur' => "Cette série n'est liée à aucune série MangaDex."], 422);
        }

        /* « repli » distingue les deux appelants : le rafraîchissement
           automatique n'accepte que la couverture du tome exact et laisse
           l'image en place sinon ; le bouton « Image MangaDex » accepte
           n'importe quelle couverture de la série, puisque c'est
           précisément ce qu'on lui demande. */
        $repli = ((string) ($_POST['repli'] ?? '0')) === '1';

        /* Le tome et le statut du FORMULAIRE OUVERT priment sur ceux
           enregistrés. On vient peut-être de passer du tome 1 au tome 10
           sans avoir encore validé : demander la couverture du tome 1
           renverrait l'image qu'on cherche justement à remplacer, et
           obligerait à enregistrer d'abord pour pouvoir la voir.

           Le rafraîchissement automatique, lui, n'envoie rien : il part
           de la base, qui est bien son état de référence. */
        $tome_vu = isset($_POST['tome_actuel'])
            ? max(0, min(TOME_MAX, (int) $_POST['tome_actuel']))
            : (int) $s['tome_actuel'];

        $statut_vu = (string) ($_POST['statut'] ?? $s['statut']);
        if (!isset(STATUTS[$statut_vu])) {
            $statut_vu = (string) $s['statut'];
        }

        $tome = couverture_tome_vise($statut_vu, $tome_vu);
        $url  = couverture_liee($lien, $tome, $repli);

        if ($url === '') {
            $attente = mangadex_attente_suggeree();
            reponse_json([
                'ok'      => false,
                'attente' => $attente,
                'erreur'  => $attente > 0
                    ? 'Trop de recherches en cours. Réessayez dans ' . $attente . ' secondes.'
                    : 'Aucune couverture trouvée pour le tome ' . $tome . '.',
            ], $attente > 0 ? 429 : 404);
        }

        /* Le rafraîchissement automatique écrit en base : sans cela la
           couverture reviendrait en arrière au prochain chargement.

           Le bouton du formulaire, lui, n'écrit rien — il se contente de
           proposer l'URL, que l'utilisateur garde ou non en validant la
           modale. Écrire tout de suite ferait écraser ce choix par ce que
           le formulaire encore ouvert renverrait ensuite. */
        $persister = ((string) ($_POST['enregistrer'] ?? '1')) === '1';

        if ($persister && $url !== url_image_sure((string) $s['couverture'])) {
            $pdo->prepare('UPDATE serie SET couverture = ? WHERE id = ? AND utilisateur_id = ?')
                ->execute([$url, $id, $mon_id]);
            $s['couverture'] = $url;
        }

        reponse_json([
            'ok'    => true,
            'url'   => $url,
            'carte' => carte_html($s),
            'tome'  => $tome,
        ]);
    }

    /* ---------------- Délier une image de MangaDex ----------------
       L'image reste affichée à l'identique, mais cesse d'être suivie
       automatiquement : on la rapatrie en local (includes/images.php),
       ce qui lui fait perdre son URL MangaDex. Le lien se déduisant de
       cette URL (mangadex_id_depuis_url()), il disparaît alors de
       lui-même au prochain enregistrement — sans code dédié pour
       « couper le lien », la même règle que choisir un fichier ou coller
       une URL d'ailleurs.

       Comme le bouton « Image MangaDex », ceci ne PROPOSE l'image que
       pour le formulaire : rien n'est écrit tant que la modale n'est
       pas validée, pour ne pas écraser un choix que l'utilisateur
       ferait entre-temps. */
    case 'couverture.delier': {
        $id = (int) ($_POST['id'] ?? 0);
        $s  = ma_serie($pdo, $mon_id, $id);

        $couverture = url_image_sure((string) $s['couverture']);
        if ($couverture === '' || mangadex_id_depuis_url($couverture) === '') {
            reponse_json(['ok' => false, 'erreur' =>
                "Cette série n'a pas d'image MangaDex à dissocier."], 422);
        }

        $erreur = null;
        $locale = mangadex_image_locale($couverture, $erreur);
        if ($locale === null) {
            $attente = mangadex_attente_suggeree();
            reponse_json([
                'ok'      => false,
                'attente' => $attente,
                'erreur'  => $attente > 0
                    ? 'Trop de requêtes en cours. Réessayez dans ' . $attente . ' secondes.'
                    : ($erreur ?? 'Téléchargement impossible.'),
            ], $attente > 0 ? 429 : 422);
        }

        reponse_json(['ok' => true, 'url' => $locale]);
    }

    /* ---------------- Filtre des images sensibles ----------------
       Le filtre est actif par défaut. Le désactiver exige d'avoir
       déclaré sa majorité — déclaration sur l'honneur, jamais une
       vérification : elle ne vaut que parce qu'elle est faite
       sciemment, d'où sa trace au journal.

       Le réglage vient du client, mais il ne décide que de ce que CE
       compte voit dans SA recherche. Aucun autre droit n'en dépend, et
       le contenu pornographique reste exclu quoi qu'il arrive. */
    case 'compte.filtre_sensible': {
        if (!COUVERTURE_CONTENU_ADULTE) {
            reponse_json(['ok' => false, 'erreur' => 'Option désactivée sur ce site.'], 403);
        }
        $filtrer  = ((string) ($_POST['filtrer'] ?? '1')) === '1';
        $declare  = ((string) ($_POST['majeur'] ?? '0')) === '1';
        $confirme = (int) ($moi['adulte_confirme'] ?? 0) === 1;

        /* La déclaration accompagne la première levée du filtre. Une
           fois acquise, on ne la redemande plus : réactiver puis
           relever le filtre ne doit pas rejouer la formalité. */
        if ($declare && !$confirme) {
            $pdo->prepare('UPDATE utilisateur SET adulte_confirme = 1 WHERE id = ?')
                ->execute([$mon_id]);
            journal_securite('majorite_declaree', ['utilisateur' => $mon_id]);
            $confirme = true;
        }

        /* Refus net plutôt que correction silencieuse : lever le filtre
           sans déclaration est une incohérence côté client, pas une
           préférence à interpréter. */
        if (!$filtrer && !$confirme) {
            reponse_json(['ok' => false, 'erreur' =>
                'Déclarez d\'abord être majeur pour désactiver le filtre.'], 403);
        }

        $pdo->prepare('UPDATE utilisateur SET filtre_sensible = ? WHERE id = ?')
            ->execute([$filtrer ? 1 : 0, $mon_id]);
        journal_securite($filtrer ? 'filtre_sensible_actif' : 'filtre_sensible_leve',
            ['utilisateur' => $mon_id]);

        reponse_json([
            'ok'       => true,
            'filtrer'  => $filtrer,
            'majeur'   => $confirme,
            'message'  => $filtrer
                ? 'Les images sensibles sont filtrées.'
                : 'Le filtre est désactivé.',
        ]);
    }

    /* ---------------- Administration (admin.php) ----------------
       Réservé aux comptes `admin` : la garde est plus haut, avant le switch.
       Ces actions portent sur d'AUTRES comptes, jamais sur la bibliothèque de
       celui qui les lance : un administrateur bloqué garde donc la page (voir
       ACTIONS_ADMIN dans fonctions.php). Chacune relit sa cible en base. */

    /* Changer le forfait d'un compte, et le prévenir par e-mail. Bloquer exige
       une raison : elle est rangée avec le compte et dite dans le message. */
    case 'admin.forfait': {
        $cible_id = (int) ($_POST['id'] ?? 0);
        $forfait  = $_POST['forfait'] ?? '';
        $cible    = admin_utilisateur($pdo, $cible_id);
        if (!$cible) {
            reponse_json(['ok' => false, 'erreur' => 'Compte introuvable.'], 404);
        }
        if (!admin_forfait_valide($forfait)) {
            reponse_json(['ok' => false, 'erreur' => 'Forfait inconnu.'], 422);
        }
        if ($forfait === $cible['forfait']) {
            reponse_json(['ok' => false, 'erreur' => 'Ce compte a déjà ce forfait.'], 422);
        }

        $raison = '';
        if ($forfait === FORFAIT_BLOQUE) {
            $raison = admin_raison($_POST['raison'] ?? '');
            if ($erreur = admin_raison_erreur($raison)) {
                reponse_json(['ok' => false, 'champ' => 'raison', 'erreur' => $erreur], 422);
            }
        }

        $ancien = $cible['forfait'];
        admin_changer_forfait($pdo, $cible_id, $forfait, $raison);
        journal_securite('admin_forfait', ['admin' => $mon_id, 'cible' => $cible_id,
            'de' => $ancien, 'vers' => $forfait, 'raison' => $raison]);

        /* Le forfait a changé : un envoi raté ne l'annule pas, il se DIT
           (« un envoi raté est perdu, l'appelant le dit »). Pas de message à
           une adresse que personne n'a confirmée. */
        $mail = !$cible['confirme']
            ? 'non_confirme'
            : (avertir_forfait_change($cible['email'], $cible['identifiant'], $forfait, $ancien, $raison) ? 'envoye' : 'echec');

        reponse_json([
            'ok'      => true,
            'compte'  => admin_compte($pdo, $cible_id, $mon_id),
            'mail'    => $mail,
            'message' => admin_message_forfait($cible['identifiant'], $forfait, $mail),
        ]);
    }

    /* Nommer ou révoquer un administrateur. Jamais pour soi-même (voir
       admin_refus_droits) : il en reste donc toujours un, celui qui agit. */
    case 'admin.admin': {
        $cible_id = (int) ($_POST['id'] ?? 0);
        $devient  = ((string) ($_POST['admin'] ?? '')) === '1';
        $cible    = admin_utilisateur($pdo, $cible_id);
        if (!$cible) {
            reponse_json(['ok' => false, 'erreur' => 'Compte introuvable.'], 404);
        }
        if ($refus = admin_refus_droits($cible_id, $mon_id)) {
            reponse_json(['ok' => false, 'erreur' => $refus], 403);
        }
        if ($cible['admin'] === $devient) {
            reponse_json(['ok' => false, 'erreur' => $devient
                ? 'Ce compte est déjà administrateur.' : "Ce compte n'est pas administrateur."], 422);
        }

        admin_changer_droits($pdo, $cible_id, $devient);
        journal_securite($devient ? 'admin_nomme' : 'admin_revoque',
            ['admin' => $mon_id, 'cible' => $cible_id, 'identifiant' => $cible['identifiant']]);

        reponse_json([
            'ok'      => true,
            'compte'  => admin_compte($pdo, $cible_id, $mon_id),
            'message' => admin_message_droits($cible['identifiant'], $devient),
        ]);
    }

    /* DEMANDER la suppression d'un compte. Rien n'est supprimé ici : un lien
       part vers ADMIN_EMAIL, et c'est la page admin.php qui, au clic puis à la
       validation, efface. Le courrier est la seconde clé : une session
       d'administrateur volée ne suffit pas à effacer des comptes. */
    case 'admin.supprimer': {
        $cible_id = (int) ($_POST['id'] ?? 0);
        $cible    = admin_utilisateur($pdo, $cible_id);
        if (!$cible) {
            reponse_json(['ok' => false, 'erreur' => 'Compte introuvable.'], 404);
        }
        if ($refus = admin_refus_suppression($cible, $mon_id)) {
            reponse_json(['ok' => false, 'erreur' => $refus], 403);
        }

        $jeton = generer_jeton_action($cible_id, 'suppression_admin', ADMIN_SUPPRESSION_DUREE, (string) $mon_id);
        if (!demander_confirmation_suppression((string) $moi['identifiant'], $cible['identifiant'], $cible['email'], $cible['series'], $jeton)) {
            // Un lien qu'on n'a pas pu envoyer ne doit pas rester valable.
            $pdo->prepare('DELETE FROM jeton_action WHERE jeton_hash = ?')->execute([hash('sha256', $jeton)]);
            reponse_json(['ok' => false,
                'erreur' => "L'e-mail de confirmation n'a pas pu partir : rien n'a été supprimé."], 502);
        }
        journal_securite('admin_suppression_demandee', ['admin' => $mon_id, 'cible' => $cible_id,
            'identifiant' => $cible['identifiant']]);

        reponse_json([
            'ok'      => true,
            'message' => 'Un e-mail de confirmation a été envoyé à ' . ADMIN_EMAIL . '. '
                . "Le compte n'est supprimé qu'après un clic sur le lien (valable "
                . secondes_lisibles(ADMIN_SUPPRESSION_DUREE) . ').',
        ]);
    }

    default:
        reponse_json(['ok' => false, 'erreur' => 'Action inconnue.'], 400);
}
