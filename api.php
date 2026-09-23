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

/**
 * Exige le mot de passe du compte, ou répond 403 — en comptant les
 * échecs. Sans ce comptage, une session volée permettait de deviner le
 * mot de passe par essais illimités, et chaque essai coûtait au serveur
 * un password_verify() volontairement lent : de quoi saturer le
 * processeur d'un hébergement mutualisé avec quelques requêtes.
 */
function exiger_mot_de_passe(int $mon_id): void
{
    $attente = null;
    if (verifier_mot_de_passe_limite($mon_id, (string) ($_POST['mot_de_passe'] ?? ''), $attente)) {
        return;
    }
    if ($attente > 0) {
        /* « attente » accompagne le message : le navigateur en fait un
           compte à rebours, le message reste lisible sans JavaScript. */
        reponse_json(['ok' => false, 'attente' => $attente, 'erreur' =>
            'Trop de tentatives. Réessayez dans ' . $attente . ' secondes.'], 429);
    }
    reponse_json(['ok' => false, 'erreur' => 'Mot de passe incorrect.'], 403);
}

/* --------- Export : seule action qui ne répond pas en JSON ---------
   Le JSON est écrit au fil de l'eau plutôt que construit en mémoire.
   L'ancienne version chargeait TOUTES les couvertures puis les encodait
   en base64 dans un seul tableau : 150 séries suffisaient à dépasser la
   mémoire allouée par un hébergement mutualisé, et l'export échouait
   silencieusement sur une page blanche. Ici, une seule image est en
   mémoire à la fois. */
if ($action === 'donnees.exporter') {
    $req = $pdo->prepare(
        'SELECT titre, auteur, tome_actuel, statut, couverture, cree_le, maj_le
           FROM serie WHERE utilisateur_id = ? ORDER BY titre'
    );
    $req->execute([$mon_id]);

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="bibliotheque-' . date('Y-m-d') . '.json"');
    header('Cache-Control: no-store, private');

    $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    echo '{', "\n";
    echo '  "application": "Ma Bibliothèque Manga",', "\n";
    echo '  "version": 1,', "\n";
    echo '  "exporte_le": ', json_encode(date('c'), $options), ",\n";
    echo '  "profil": ', json_encode([
        'identifiant' => $moi['identifiant'],
        'email'       => $moi['email'],
        'prenom'      => $moi['prenom'],
        'nom'         => $moi['nom'],
        'photo'       => url_image_sure($moi['photo']),
    ], $options), ",\n";
    echo '  "series": [';

    $premiere = true;
    while ($s = $req->fetch()) {
        $couverture = url_image_sure($s['couverture'] ?? '');
        $s['couverture'] = $couverture;

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

    /* ---------------- Ajout / modification ---------------- */
    case 'serie.enregistrer': {
        $id     = (int) ($_POST['id'] ?? 0);
        $titre  = texte($_POST['titre'] ?? '', 190);
        $auteur = texte($_POST['auteur'] ?? '', 190);
        $tome   = max(0, min(TOME_MAX, (int) ($_POST['tome_actuel'] ?? 0)));
        $statut = (string) ($_POST['statut'] ?? 'cours');

        if ($titre === '') {
            reponse_json(['ok' => false, 'erreur' => 'Le titre est obligatoire.'], 422);
        }
        if (!isset(STATUTS[$statut])) {
            $statut = 'cours';
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
            reponse_json(['ok' => false, 'erreur' => $erreur_image], 422);
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
    case 'compte.profil': {
        $prenom      = texte($_POST['prenom'] ?? '', 80);
        $nom         = texte($_POST['nom'] ?? '', 80);
        $identifiant = texte($_POST['identifiant'] ?? '', 50);
        $email       = texte($_POST['email'] ?? '', 190);

        if ($faiblesses = valider_profil($identifiant, $email, $mon_id)) {
            reponse_json(['ok' => false, 'erreur' => implode(' ', $faiblesses)], 422);
        }

        $email_change       = (strcasecmp($email, (string) $moi['email']) !== 0);
        $identifiant_change = ($identifiant !== (string) $moi['identifiant']);

        /* Ces deux champs servent à se connecter et à récupérer le compte :
           qui les contrôle contrôle le compte. Le mot de passe est donc
           exigé pour l'un comme pour l'autre — sinon une session volée
           suffit à renommer le compte (la victime ne peut plus se
           connecter) ou à détourner l'adresse de récupération.
           Le prénom, le nom et la photo ne demandent rien. */
        if ($email_change || $identifiant_change) {
            exiger_mot_de_passe($mon_id);
        }
        if ($email_change && !email_disponible($email, $mon_id)) {
            reponse_json(['ok' => false, 'erreur' => 'Cette adresse e-mail est déjà utilisée.'], 422);
        }

        $erreur_image = null;
        $fichier = enregistrer_image('photo_fichier', $erreur_image);
        if ($erreur_image !== null) {
            reponse_json(['ok' => false, 'erreur' => $erreur_image], 422);
        }
        $ancienne = (string) $moi['photo'];
        $photo    = photo_depuis_formulaire($fichier, $ancienne);

        /* La nouvelle adresse n'est PAS écrite ici. Elle attend dans le
           jeton, et ne remplacera l'ancienne qu'au clic sur le lien de
           confirmation envoyé à cette nouvelle adresse (voir
           verifier-email.php). Deux raisons :
             - une session volée ne peut pas détourner l'adresse de
               récupération sans accès à la boîte visée ;
             - une simple faute de frappe ne rend pas le compte
               irrécupérable, puisque l'ancienne adresse reste active. */
        $req = $pdo->prepare(
            'UPDATE utilisateur SET prenom = ?, nom = ?, identifiant = ?, photo = ? WHERE id = ?'
        );
        $req->execute([$prenom, $nom, $identifiant, $photo, $mon_id]);

        if ($ancienne !== '' && $ancienne !== $photo) {
            supprimer_image_locale($ancienne);
        }

        $message = 'Profil enregistré ✅';
        if ($email_change) {
            journal_securite('changement_email_demande', [
                'utilisateur' => $mon_id, 'vers' => $email,
            ]);
            avertir_changement_email_demande((string) $moi['email'], $identifiant, $email);

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
            'email'     => $moi['email'],   // inchangée tant que non confirmée
            'initiales' => initiales(['prenom' => $prenom, 'nom' => $nom, 'identifiant' => $identifiant]),
            'message'   => $message,
        ]);
    }

    /* ---------------- Mot de passe ---------------- */
    case 'compte.motdepasse': {
        $actuel  = (string) ($_POST['actuel'] ?? '');
        $nouveau = (string) ($_POST['nouveau'] ?? '');
        $confirm = (string) ($_POST['confirmation'] ?? '');

        $attente = null;
        if (!verifier_mot_de_passe_limite($mon_id, $actuel, $attente)) {
            reponse_json(['ok' => false, 'attente' => $attente, 'erreur' => $attente > 0
                ? 'Trop de tentatives. Réessayez dans ' . $attente . ' secondes.'
                : 'Mot de passe actuel incorrect.'], $attente > 0 ? 429 : 422);
        }
        if ($faiblesses = valider_mot_de_passe($nouveau, (string) $moi['identifiant'])) {
            reponse_json(['ok' => false, 'erreur' => implode(' ', $faiblesses)], 422);
        }
        if ($nouveau !== $confirm) {
            reponse_json(['ok' => false, 'erreur' => 'Les deux nouveaux mots de passe ne correspondent pas.'], 422);
        }

        $req = $pdo->prepare('UPDATE utilisateur SET mot_de_passe = ? WHERE id = ?');
        $req->execute([password_hash($nouveau, PASSWORD_DEFAULT), $mon_id]);

        /* Toutes les sessions du compte sont invalidées : les autres
           appareils (et un éventuel attaquant déjà connecté) sont
           réellement déconnectés, pas seulement privés du « se souvenir
           de moi ». On se re-connecte ensuite pour ne pas s'éjecter
           soi-même de la page en cours. */
        invalider_sessions($mon_id);
        connecter($mon_id);
        journal_securite('mot_de_passe_change', ['utilisateur' => $mon_id]);
        avertir_mot_de_passe_change((string) $moi['email'], (string) $moi['identifiant']);

        reponse_json([
            'ok'      => true,
            'csrf'    => jeton_csrf(),
            'message' => 'Mot de passe modifié ✅ — les autres appareils ont été déconnectés.',
        ]);
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

        if ($moi['forfait'] === 'illimite') {
            $place_restante = PHP_INT_MAX;
        } else {
            $req_compte = $pdo->prepare('SELECT COUNT(*) FROM serie WHERE utilisateur_id = ?');
            $req_compte->execute([$mon_id]);
            $place_restante = MAX_SERIES_PAR_UTILISATEUR - (int) $req_compte->fetchColumn();
        }

        $importees  = 0;
        $plafonnees = 0;
        /* Les images recréées sur disque pendant l'import ne font pas
           partie de la transaction : un ROLLBACK annule les lignes, pas
           les fichiers. On les suit pour pouvoir les effacer nous-mêmes
           si l'import échoue, au lieu de les laisser traîner. */
        $images_creees = [];

        /* La transaction doit être refermée quoi qu'il arrive : sans ce
           try/catch, une seule ligne invalide laissait la transaction
           ouverte et la connexion dans un état incohérent pour le reste
           de la requête. */
        try {
            $req = $pdo->prepare(
                'INSERT INTO serie (utilisateur_id, titre, auteur, tome_actuel, statut, couverture)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $pdo->beginTransaction();

            foreach ($data['series'] as $s) {
                if (!is_array($s)) {
                    continue;
                }
                if ($place_restante <= 0) {
                    $plafonnees++;
                    continue;
                }
                $titre = texte($s['titre'] ?? ($s['title'] ?? ''), 190);
                if ($titre === '') {
                    continue;
                }
                $statut = (string) ($s['statut'] ?? ($s['status'] ?? 'cours'));
                if (!isset(STATUTS[$statut])) {
                    $statut = 'cours';
                }

                // Une image envoyée comme fichier (et non comme lien https) a été
                // transmise en base64 par l'export : on la recrée sur disque ici, sinon
                // le chemin « uploads/xxx » d'origine ne pointe vers rien sur ce serveur.
                $couverture_brute = is_array($s['cover'] ?? null) ? ($s['cover']['value'] ?? '') : ($s['couverture'] ?? '');
                $couverture_donnees = (string) ($s['couverture_donnees'] ?? '');
                if ($couverture_donnees !== '') {
                    $couverture = enregistrer_image_depuis_donnees($couverture_donnees) ?? '';
                    if ($couverture !== '') {
                        $images_creees[] = $couverture;
                    }
                } else {
                    $couverture = url_image_sure(is_string($couverture_brute) ? $couverture_brute : '');
                    // Un chemin local ("uploads/…") venant d'un autre export n'a aucune
                    // chance d'exister ici sans les données ci-dessus : on l'ignore plutôt
                    // que d'afficher une image cassée.
                    if ($couverture !== '' && !preg_match('#^https://#i', $couverture)) {
                        $couverture = '';
                    }
                }

                $req->execute([
                    $mon_id,
                    $titre,
                    texte($s['auteur'] ?? ($s['subtitle'] ?? ''), 190),
                    max(0, min(TOME_MAX, (int) ($s['tome_actuel'] ?? ($s['volume'] ?? 0)))),
                    $statut,
                    $couverture,
                ]);
                $importees++;
                $place_restante--;
            }

            $pdo->commit();
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

        $message = $importees . ' série(s) importée(s) ✅';
        if ($plafonnees > 0) {
            $message = $importees . ' série(s) importée(s), ' . $plafonnees . ' ignorée(s) car la limite de '
                . MAX_SERIES_PAR_UTILISATEUR . ' séries par compte est atteinte. '
                . 'Contactez l\'administrateur à ' . ADMIN_EMAIL . ' si besoin.';
        }

        reponse_json([
            'ok'        => true,
            'importees' => $importees,
            'message'   => $message,
        ]);
    }

    /* ---------------- Vider la bibliothèque ---------------- */
    case 'donnees.vider': {
        exiger_mot_de_passe($mon_id);

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
        exiger_mot_de_passe($mon_id);

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
        avertir_compte_supprime((string) $moi['email'], (string) $moi['identifiant']);

        $_SESSION = [];
        session_destroy();

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
        $quota   = couverture_quota($moi);
        $attente = couverture_consommer($mon_id, $quota, COUVERTURE_FENETRE);
        if ($attente > 0) {
            reponse_json(['ok' => false, 'attente' => $attente, 'erreur' =>
                'Limite atteinte : ' . $quota . ' recherches par '
                . couverture_tranche_lisible(COUVERTURE_FENETRE)
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
            couverture_rendre($mon_id);
            reponse_json(['ok' => false, 'attente' => $retard, 'erreur' =>
                'Trop de recherches en cours sur le site. Réessayez dans '
                . $retard . ' secondes.'], 429);
        }

        reponse_json([
            'ok'        => true,
            'tome'      => $tome,
            'resultats' => $resultats,
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

    default:
        reponse_json(['ok' => false, 'erreur' => 'Action inconnue.'], 400);
}
