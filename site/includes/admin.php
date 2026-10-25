<?php
/* =====================================================================
   Administration : la liste des comptes et ce qu'on peut leur faire.

   Inclus par admin.php (la page) et api.php (les actions `admin.*`) — jamais
   par fonctions.php, comme couvertures.php : un test qui s'en sert doit le
   demander explicitement.

   Pur, sauf les fonctions qui prennent un PDO (admin_utilisateurs,
   admin_changer_forfait, admin_changer_droits, admin_supprimer_compte).
   Les décisions — ce qui est permis, ce qui est refusé et pourquoi, la
   forme du motif d'un blocage, les dates — sont des fonctions PURES, testées :
   api.php n'a plus qu'à les appeler.

   Qui est administrateur, et ce que cela ouvre : voir « L'administrateur »
   dans fonctions.php (est_admin, exiger_admin, ACTIONS_ADMIN).
   ===================================================================== */

declare(strict_types=1);
require_once __DIR__ . '/fonctions.php';

/* Les forfaits qu'un administrateur peut donner, et leur libellé. L'ordre est
   celui du menu de la page, du plus haut au plus bas : illimité, standard,
   bloqué (demande de l'utilisateur) — et celui du tri de la colonne « Forfait »
   (js/admin.js). Ce n'est PAS celui de l'ENUM de la base, qui n'a pas à le
   suivre. La liste est FERMÉE : api.php refuse tout le reste. */
const ADMIN_FORFAITS = [
    'illimite' => 'Illimité',
    'standard' => 'Standard',
    'bloque'   => 'Bloqué',
];

/* Le motif d'un blocage tient dans utilisateur.raison_blocage (VARCHAR(500)). */
const ADMIN_RAISON_MAX = 500;

/* ---------------------------------------------------------------------
   Décisions pures
   --------------------------------------------------------------------- */

/** Un forfait que l'administrateur a le droit de donner ? */
function admin_forfait_valide(mixed $forfait): bool
{
    return is_string($forfait) && array_key_exists($forfait, ADMIN_FORFAITS);
}

/**
 * Le motif saisi en bloquant un compte, remis en forme : retours à la ligne
 * unifiés, caractères de contrôle retirés (ils n'ont rien à faire dans un
 * e-mail ni dans une cellule de tableau), lignes vides en rafale ramenées à
 * une, espaces de bord retirés. Un texte qui n'est pas de l'UTF-8 valide
 * devient '' : il sera refusé comme un motif absent.
 */
function admin_raison(mixed $saisie): string
{
    $texte = is_scalar($saisie) ? (string) $saisie : '';
    $texte = preg_replace('/\r\n?/', "\n", $texte);
    if ($texte === null) {
        return '';
    }
    // Les caractères de la catégorie « Autre » (contrôles, formats), sauf le retour à la ligne.
    $texte = preg_replace('/[^\P{C}\n]/u', '', $texte);
    if ($texte === null) {
        return '';
    }
    return trim((string) preg_replace("/\n{3,}/", "\n\n", $texte));
}

/** '' si le motif convient, sinon le message à afficher sous le champ. */
function admin_raison_erreur(string $raison): string
{
    if ($raison === '') {
        return 'Indiquez la raison du blocage : elle sera envoyée à la personne.';
    }
    if (mb_strlen($raison) > ADMIN_RAISON_MAX) {
        return 'La raison est trop longue (' . ADMIN_RAISON_MAX . ' caractères au plus).';
    }
    return '';
}

/**
 * Un administrateur ne change pas ses propres droits : un autre doit le
 * faire. Sans cette règle, la page permettrait de se retirer le droit sans
 * le vouloir — et de laisser le site sans administrateur. '' si permis.
 */
function admin_refus_droits(int $cible_id, int $acteur_id): string
{
    return $cible_id === $acteur_id
        ? 'Vous ne pouvez pas modifier vos propres droits : un autre administrateur doit le faire.'
        : '';
}

/**
 * Peut-on demander la suppression de ce compte depuis la page ? '' si oui,
 * sinon pourquoi pas.
 *   - le sien : par ses Paramètres, avec son mot de passe ;
 *   - celui d'un administrateur : d'abord lui retirer ses droits, sans quoi
 *     un clic de trop effacerait un compte qui garde le site.
 */
function admin_refus_suppression(array $cible, int $acteur_id): string
{
    if ((int) ($cible['id'] ?? 0) === $acteur_id) {
        return 'Pour supprimer votre propre compte, passez par vos Paramètres.';
    }
    if (est_admin($cible)) {
        return "Retirez d'abord les droits d'administrateur de ce compte.";
    }
    return '';
}

/**
 * Ce que la page dit après un changement de forfait. `$mail` : « envoye »,
 * « echec » (le forfait a changé, le message n'est pas parti) ou
 * « non_confirme » (adresse jamais confirmée : on n'écrit pas à une adresse
 * dont personne n'a prouvé qu'elle est à la bonne personne).
 */
function admin_message_forfait(string $identifiant, string $forfait, string $mail): string
{
    $fait = $identifiant . ' est maintenant « ' . (ADMIN_FORFAITS[$forfait] ?? $forfait) . ' ».';
    return $fait . ' ' . match ($mail) {
        'envoye'       => 'Un e-mail le lui annonce.',
        'echec'        => "Mais l'e-mail n'a pas pu partir : prévenez-le vous-même.",
        'non_confirme' => 'Aucun e-mail : son adresse n\'a jamais été confirmée.',
        default        => '',
    };
}

/** Ce que la page dit après avoir nommé ou révoqué un administrateur. */
function admin_message_droits(string $identifiant, bool $admin): string
{
    return $admin
        ? $identifiant . ' est maintenant administrateur.'
        : $identifiant . " n'est plus administrateur.";
}

/** 2026-09-27 14:03:11 → 27/09/2026 (la date en jour, sans l'heure). '' si illisible. */
function admin_date_jour(?string $date): string
{
    $t = ($date !== null && $date !== '') ? strtotime($date) : false;
    return $t === false ? '' : date('d/m/Y', $t);
}

/** Jours entiers écoulés depuis `$date`, ou null si elle est illisible. Jamais négatif. */
function admin_jours_depuis(?string $date, ?int $maintenant = null): ?int
{
    $t = ($date !== null && $date !== '') ? strtotime($date) : false;
    if ($t === false) {
        return null;
    }
    return max(0, intdiv(($maintenant ?? time()) - $t, 86400));
}

/** 0 → « aujourd'hui », 1 → « hier », 12 → « il y a 12 j ». */
function admin_depuis_texte(?int $jours): string
{
    return match (true) {
        $jours === null => '',
        $jours === 0    => "aujourd'hui",
        $jours === 1    => 'hier',
        default         => 'il y a ' . $jours . ' j',
    };
}

/**
 * Une ligne de la table, avec des types qu'on peut comparer : la base rend
 * tout en chaînes. Ce que la page affiche et ce que son JavaScript trie
 * vient de là, et de nulle part ailleurs.
 */
function admin_ligne(array $r, ?int $maintenant = null): array
{
    $forfait = array_key_exists((string) ($r['forfait'] ?? ''), ADMIN_FORFAITS) ? (string) $r['forfait'] : 'standard';
    return [
        'id'             => (int) ($r['id'] ?? 0),
        'identifiant'    => (string) ($r['identifiant'] ?? ''),
        'email'          => (string) ($r['email'] ?? ''),
        'confirme'       => (int) ($r['email_verifie'] ?? 0) === 1,
        'photo'          => (int) ($r['a_photo'] ?? 0) === 1,
        // L'adresse À AFFICHER : celle de la colonne si le site l'accepte, sinon '' (url_image_sure).
        // `photo` dit seulement que la colonne n'est pas vide ; une valeur refusée (http://,
        // javascript:, ../) donne photo = true et photo_url = '', et la page n'affiche rien.
        'photo_url'      => url_image_sure((string) ($r['photo'] ?? '')),
        'forfait'        => $forfait,
        'admin'          => (int) ($r['admin'] ?? 0) === 1,
        'google'         => (int) ($r['google'] ?? 0) === 1,
        'raison_blocage' => (string) ($r['raison_blocage'] ?? ''),
        'bloque_le'      => admin_date_jour($r['bloque_le'] ?? null),
        'cree_le'        => (string) ($r['cree_le'] ?? ''),
        'inscrit_le'     => admin_date_jour($r['cree_le'] ?? null),
        'inscrit_jours'  => admin_jours_depuis($r['cree_le'] ?? null, $maintenant),
        'series'         => (int) ($r['nb_series'] ?? 0),
    ];
}

/**
 * Une ligne, avec ce que l'acteur a le droit d'en faire : « moi » (c'est son
 * propre compte), et pour chaque action le motif du refus, ou '' si elle est
 * permise. La page en tire l'état de ses boutons, et api.php le renvoie après
 * chaque action pour que le navigateur les remette à jour sans les deviner.
 * Le serveur reste juge : il applique les mêmes règles à chaque appel.
 */
function admin_pour_acteur(array $ligne, int $acteur_id): array
{
    return $ligne + [
        'moi'               => $ligne['id'] === $acteur_id,
        'refus_droits'      => admin_refus_droits($ligne['id'], $acteur_id),
        'refus_suppression' => admin_refus_suppression($ligne, $acteur_id),
    ];
}

/**
 * Les chiffres du bandeau, comptés sur les lignes déjà normalisées
 * (admin_ligne) : une seule requête pour toute la page.
 */
function admin_totaux(array $lignes): array
{
    $t = ['comptes' => 0, 'confirmes' => 0, 'non_confirmes' => 0, 'bloques' => 0,
          'illimites' => 0, 'admins' => 0, 'series' => 0];
    foreach ($lignes as $l) {
        $t['comptes']++;
        $t[$l['confirme'] ? 'confirmes' : 'non_confirmes']++;
        $t['bloques']   += $l['forfait'] === 'bloque' ? 1 : 0;
        $t['illimites'] += $l['forfait'] === 'illimite' ? 1 : 0;
        $t['admins']    += $l['admin'] ? 1 : 0;
        $t['series']    += $l['series'];
    }
    return $t;
}

/* ---------------------------------------------------------------------
   Base de données
   --------------------------------------------------------------------- */

/** Tous les comptes, normalisés par admin_ligne(), du plus ancien au plus récent. */
function admin_utilisateurs(PDO $pdo): array
{
    $lignes = $pdo->query(
        "SELECT u.id, u.identifiant, u.email, u.email_verifie, u.photo, (u.photo <> '') AS a_photo,
                u.forfait, u.admin, u.raison_blocage, u.bloque_le, u.cree_le,
                (u.google_sub IS NOT NULL AND u.google_sub <> '') AS google,
                (SELECT COUNT(*) FROM serie s WHERE s.utilisateur_id = u.id) AS nb_series
           FROM utilisateur u
          ORDER BY u.id"
    )->fetchAll();
    $maintenant = time();
    return array_map(static fn (array $r): array => admin_ligne($r, $maintenant), $lignes);
}

/** Un compte, tel que la page le montre, ou null s'il n'existe pas (ou plus). */
function admin_utilisateur(PDO $pdo, int $id): ?array
{
    $req = $pdo->prepare(
        "SELECT u.id, u.identifiant, u.email, u.email_verifie, u.photo, (u.photo <> '') AS a_photo,
                u.forfait, u.admin, u.raison_blocage, u.bloque_le, u.cree_le,
                (u.google_sub IS NOT NULL AND u.google_sub <> '') AS google,
                (SELECT COUNT(*) FROM serie s WHERE s.utilisateur_id = u.id) AS nb_series
           FROM utilisateur u WHERE u.id = ?"
    );
    $req->execute([$id]);
    $r = $req->fetch();
    return $r ? admin_ligne($r) : null;
}

/** Un compte vu par l'acteur (admin_pour_acteur), ou null s'il n'existe pas. */
function admin_compte(PDO $pdo, int $id, int $acteur_id): ?array
{
    $ligne = admin_utilisateur($pdo, $id);
    return $ligne ? admin_pour_acteur($ligne, $acteur_id) : null;
}

/**
 * Pose le forfait d'un compte. Le motif et la date du blocage vont avec : ils
 * se posent en bloquant et s'effacent dans tout autre cas.
 *
 * Relit ce qui a été écrit, dans une transaction annulée si cela diffère :
 * hors mode strict, une valeur absente de l'ENUM n'est pas une erreur, MySQL
 * range '' — le compte, loin d'être bloqué, resterait libre sans qu'aucun
 * message ne le dise (migration 10 de livre.sql pas rejouée). Même garde que
 * generer_jeton_action().
 */
function admin_changer_forfait(PDO $pdo, int $id, string $forfait, string $raison): void
{
    $bloque = $forfait === FORFAIT_BLOQUE;
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE utilisateur SET forfait = ?, raison_blocage = ?, bloque_le = '
            . ($bloque ? 'NOW()' : 'NULL') . ' WHERE id = ?'
        )->execute([$forfait, $bloque ? $raison : '', $id]);

        $lu = $pdo->prepare('SELECT forfait FROM utilisateur WHERE id = ?');
        $lu->execute([$id]);
        if ($lu->fetchColumn() !== $forfait) {
            throw new RuntimeException("utilisateur.forfait ne retient pas « {$forfait} » : rejouez livre.sql.");
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Nomme ou révoque un administrateur. */
function admin_changer_droits(PDO $pdo, int $id, bool $admin): void
{
    $pdo->prepare('UPDATE utilisateur SET admin = ? WHERE id = ?')->execute([$admin ? 1 : 0, $id]);
}

/**
 * Supprime un compte, sa bibliothèque (CASCADE) et ses images sur le disque.
 * Les images sont relevées AVANT : la ligne partie, on ne sait plus lesquelles
 * étaient à lui. Même ordre que « compte.supprimer » (api.php).
 */
function admin_supprimer_compte(PDO $pdo, int $id): void
{
    $req = $pdo->prepare('SELECT couverture FROM serie WHERE utilisateur_id = ?');
    $req->execute([$id]);
    $images = array_column($req->fetchAll(), 'couverture');

    $req = $pdo->prepare('SELECT photo FROM utilisateur WHERE id = ?');
    $req->execute([$id]);
    $images[] = (string) $req->fetchColumn();

    $pdo->prepare('DELETE FROM utilisateur WHERE id = ?')->execute([$id]);
    supprimer_images_locales($images);
}
