<?php
/* =====================================================================
   Réglages modifiables depuis l'administration (admin.php).

   Chargé par config.php, AVANT que celui-ci ne fige ses constantes : une
   valeur saisie dans la page remplace celle du .env, sans renvoyer de
   fichier. Le .env reste la valeur de départ ; la table `reglage` ne porte
   que ce que l'administrateur a changé, et « revenir au .env » efface la
   ligne.

   Pur, sauf les quatre fonctions qui prennent un PDO. Ne dépend d'aucune
   constante : config.php l'inclut avant d'en définir une seule, et
   purger.php (qui ne charge que config.php et mailer.php) en dépend aussi.

   LISTE FERMÉE. Seules les clés de REGLAGES sont lues dans la base, et
   chacune a ses bornes — celles que l'utilisateur a validées. Rien de ce
   qui touche aux secrets (SMTP, base, jetons, Google), à l'adresse du site
   ou à l'identité de l'administrateur (ADMIN_EMAIL, seconde clé de la
   suppression d'un compte), ni aux images n'y figure : ce sont des réglages
   de déploiement, qui restent dans le .env. Exception demandée par
   l'utilisateur : la POLITIQUE de mot de passe (groupe « Mots de passe » :
   longueur minimale et classes de caractères exigées). MDP_MAX, borne
   technique, n'y est pas.

   Deux jeux de bornes, à ne pas confondre :
     - celles d'ici (min/max) valent pour ce qu'on saisit dans la page, et
       pour ce qu'on relit de la base (une valeur tapée à la main en SQL est
       ramenée dans l'intervalle) ;
     - celles de config.php valent pour le .env et s'appliquent AUSSI à ce
       qui vient d'ici : la valeur passe par le même « max(…, min(…)) » que
       si elle venait du .env.
   Les bornes d'ici sont toujours dans celles de config.php.
   ===================================================================== */

declare(strict_types=1);

const REGLAGES_GROUPES = [
    'quotas'     => 'Quotas',
    'couverture' => 'Recherche de couverture',
    'durees'     => 'Durées',
    'mdp'        => 'Mots de passe',
    'freins'     => 'Freins anti-force-brute',
];

/* Chaque réglage :
     groupe, libelle, aide  — ce que la page affiche
     min, max               — bornes, dans l'unité NATIVE (celle du .env)
     defaut                 — la valeur que prend config.php sans .env
                              (tests/cas/reglages_test.php la compare au code)
     facteur, unite         — la page saisit « jours » ou « min » là où le .env
                              compte en secondes : native = saisi × facteur
     zero                   — ce que veut dire 0, quand ce n'est pas une valeur
     choix                  — un menu (valeur => libellé) plutôt qu'un nombre

   Les freins : 3 à 20 tentatives, 60 à 600 s de blocage — la demande de
   l'utilisateur. Quatre défauts dépassent 600 s : LIMITEUR_FENETRE (900),
   LIMITEUR_BLOCAGE_MAX (3600), LIMITEUR_OUBLI (86 400, et config.php le
   relève à 3 600 au minimum) et MDP_OUBLIE_BLOCAGE (900). Les plafonner à 600
   les aurait fait baisser sans que personne l'ait demandé : leur défaut est
   leur plafond. On peut les resserrer, pas les desserrer au-delà d'aujourd'hui. */
const REGLAGES = [
    // ---- Quotas ----
    'MAX_UTILISATEURS' => [
        'groupe' => 'quotas', 'libelle' => 'Comptes au maximum',
        'aide' => "Au-delà, les inscriptions sont refusées. Mettre ici le nombre actuel de comptes les ferme.",
        'min' => 1, 'max' => 10000, 'defaut' => 100, 'unite' => 'comptes',
    ],
    'MAX_SERIES_PAR_UTILISATEUR' => [
        'groupe' => 'quotas', 'libelle' => 'Séries par compte (forfait standard)',
        'aide' => "Le forfait illimité n'a pas cette limite.",
        'min' => 1, 'max' => 10000, 'defaut' => 150, 'unite' => 'séries',
    ],
    'MAX_APPAREILS' => [
        'groupe' => 'quotas', 'libelle' => 'Appareils par compte',
        'aide' => "Appareils mémorisés (compte illimité : au-delà, le plus ancien est oublié) et appareils notifiés (au-delà, l'ajout est refusé).",
        'min' => 1, 'max' => 100, 'defaut' => 30, 'unite' => 'appareils',
    ],

    'SERIES_PAGES_MAX' => [
        'groupe' => 'quotas', 'libelle' => 'Séries affichées par page',
        'aide' => "La bibliothèque montre ce nombre de séries, puis un bouton « Afficher plus » pour les suivantes. La recherche et les filtres portent sur toutes les séries, pas seulement sur la page affichée.",
        'min' => 0, 'max' => 100, 'defaut' => 30, 'unite' => 'séries', 'zero' => 'toutes sur une seule page',
    ],

    // ---- Recherche de couverture ----
    'COUVERTURE_QUOTA' => [
        'groupe' => 'couverture', 'libelle' => 'Recherches par compte et par tranche',
        'aide' => "La durée d'une tranche est réglée plus bas. Les recherches ne coûtent rien au serveur : la file d'attente vers MangaDex le protège quoi qu'on écrive ici.",
        'min' => 0, 'max' => 1000, 'defaut' => 0, 'unite' => 'recherches', 'zero' => 'sans limite',
    ],
    'COUVERTURE_QUOTA_ILLIMITE' => [
        'groupe' => 'couverture', 'libelle' => 'Idem, forfait illimité',
        'aide' => "Jamais en dessous du quota ordinaire ; sans quota ordinaire, l'illimité n'en a pas non plus.",
        'min' => 0, 'max' => 5000, 'defaut' => 0, 'unite' => 'recherches', 'zero' => 'sans limite',
    ],
    'COUVERTURE_FENETRE' => [
        'groupe' => 'couverture', 'libelle' => "Durée d'une tranche de recherches",
        'aide' => "Passé ce délai, le compteur de chaque compte repart de zéro.",
        'min' => 10, 'max' => 3600, 'defaut' => 120, 'unite' => 's',
    ],
    'COUVERTURE_MAX_SERIES' => [
        'groupe' => 'couverture', 'libelle' => 'Séries proposées par recherche',
        'aide' => "Chaque série proposée coûte un appel de plus vers MangaDex.",
        'min' => 0, 'max' => 100, 'defaut' => 0, 'unite' => 'séries', 'zero' => 'toutes celles examinées',
    ],
    'COUVERTURE_CANDIDATS' => [
        'groupe' => 'couverture', 'libelle' => 'Séries examinées par recherche',
        'aide' => "Élargir ne coûte aucun appel de plus. C'est ce réglage qui borne le coût d'une recherche sans limite d'affichage.",
        'min' => 1, 'max' => 100, 'defaut' => 25, 'unite' => 'séries',
    ],
    'COUVERTURE_CONTENU_ADULTE' => [
        'groupe' => 'couverture', 'libelle' => 'Séries classées « erotica »',
        'aide' => "Ouvre la possibilité, sans l'accorder : il faut encore que la personne déclare sa majorité et lève le filtre. Un choix qui engage l'éditeur du site.",
        'min' => 0, 'max' => 1, 'defaut' => 0,
        'choix' => ['0' => 'Bloquées', '1' => 'Autorisées'],
    ],

    // ---- Durées ----
    'ADMIN_SUPPRESSION_DUREE' => [
        'groupe' => 'durees', 'libelle' => 'Validité du lien de suppression de compte',
        'aide' => "Le lien envoyé à ADMIN_EMAIL pour confirmer la suppression d'un compte.",
        'min' => 300, 'max' => 86400, 'defaut' => 3600, 'facteur' => 60, 'unite' => 'min',
    ],
    'RAPPORT_HEURES' => [
        'groupe' => 'durees', 'libelle' => "Rapport d'activité, tous les",
        'aide' => "Jamais plus souvent que le cron ne passe.",
        'min' => 0, 'max' => 720, 'defaut' => 0, 'unite' => 'h', 'zero' => 'à chaque passage',
    ],
    'CRON_HEURES' => [
        'groupe' => 'durees', 'libelle' => 'Passage de la tâche planifiée, tous les',
        'aide' => "Doit rester l'espacement réglé chez l'hébergeur : ce réglage ne le change pas, il dit au rapport de combien il est.",
        'min' => 1, 'max' => 168, 'defaut' => 24, 'unite' => 'h',
    ],
    'NOUVEAUTE_MINUTES' => [
        'groupe' => 'durees', 'libelle' => "Nouveaux tomes : vérification d'une même série, au plus toutes les",
        'aide' => "Chaque vérification est un appel à MangaDex. De 60 min (une heure) à 10 080 min (une semaine).",
        'min' => 60, 'max' => 10080, 'defaut' => 60, 'unite' => 'min',
    ],
    'SESSION_DUREE' => [
        'groupe' => 'durees', 'libelle' => 'Durée de la session',
        'aide' => "Combien de temps on reste connecté sans « se souvenir de moi ». Un changement ne touche que les connexions suivantes.",
        'min' => 0, 'max' => 31536000, 'defaut' => 0, 'facteur' => 86400, 'unite' => 'jours',
        'zero' => 'jusqu\'à la fermeture du navigateur',
    ],
    'REMEMBER_DUREE_VIP' => [
        'groupe' => 'durees', 'libelle' => 'Connexion mémorisée (forfait illimité)',
        'aide' => "Combien de temps un appareil reste reconnu. Un changement ne touche que les connexions suivantes.",
        'min' => 0, 'max' => 31536000, 'defaut' => 31536000, 'facteur' => 86400, 'unite' => 'jours',
        'zero' => 'désactivée',
    ],
    'CLE_ACCES_MAX' => [
        'groupe' => 'durees', 'libelle' => "Clés d'accès par compte",
        'aide' => "Un téléphone, un ordinateur, un gestionnaire de mots de passe…",
        'min' => 1, 'max' => 50, 'defaut' => 10, 'unite' => 'clés',
    ],

    // ---- Mots de passe ----
    'MDP_MIN' => [
        'groupe' => 'mdp', 'libelle' => 'Longueur minimale',
        'aide' => "Espaces non comptés. Un mot de passe court se devine vite : en dessous de 8, la protection s'affaiblit nettement.",
        'min' => 1, 'max' => 200, 'defaut' => 8, 'unite' => 'caractères',
    ],
    'MDP_MAJ' => [
        'groupe' => 'mdp', 'libelle' => 'Majuscule',
        'aide' => "Au moins une lettre majuscule (« É » compte).",
        'min' => 0, 'max' => 1, 'defaut' => 1,
        'choix' => ['0' => 'Non exigée', '1' => 'Exigée'],
    ],
    'MDP_MINUSCULE' => [
        'groupe' => 'mdp', 'libelle' => 'Minuscule',
        'aide' => "Au moins une lettre minuscule (« é » compte).",
        'min' => 0, 'max' => 1, 'defaut' => 1,
        'choix' => ['0' => 'Non exigée', '1' => 'Exigée'],
    ],
    'MDP_CHIFFRE' => [
        'groupe' => 'mdp', 'libelle' => 'Chiffre',
        'aide' => "Au moins un chiffre.",
        'min' => 0, 'max' => 1, 'defaut' => 1,
        'choix' => ['0' => 'Non exigé', '1' => 'Exigé'],
    ],
    'MDP_SPE' => [
        'groupe' => 'mdp', 'libelle' => 'Caractère spécial',
        'aide' => "Ni lettre, ni chiffre, ni espace (par exemple ! ? * - _ #). Valable pour les nouveaux mots de passe : ceux qui existent déjà ne sont pas revérifiés.",
        'min' => 0, 'max' => 1, 'defaut' => 1,
        'choix' => ['0' => 'Non exigé', '1' => 'Exigé'],
    ],

    // ---- Freins anti-force-brute ----
    'LIMITEUR_FENETRE' => [
        'groupe' => 'freins', 'libelle' => 'Fenêtre de comptage des échecs',
        'aide' => "Un échec plus ancien ne compte plus.",
        'min' => 60, 'max' => 900, 'defaut' => 900, 'unite' => 's',
    ],
    'LIMITEUR_BLOCAGE_MAX' => [
        'groupe' => 'freins', 'libelle' => 'Plafond du blocage, quelles que soient les récidives',
        'aide' => "La durée de blocage double à chaque récidive, jusqu'à ce plafond.",
        'min' => 60, 'max' => 3600, 'defaut' => 3600, 'unite' => 's',
    ],
    'LIMITEUR_OUBLI' => [
        'groupe' => 'freins', 'libelle' => 'Oubli des récidives après',
        'aide' => "Sans tentative pendant ce délai, la durée de blocage repart de sa valeur de base.",
        'min' => 3600, 'max' => 86400, 'defaut' => 86400, 'facteur' => 3600, 'unite' => 'h',
    ],
    'CONNEXION_MAX_ESSAIS' => [
        'groupe' => 'freins', 'libelle' => 'Connexion, par adresse IP : tentatives tolérées',
        'aide' => "Arrête l'attaquant unique qui essaie beaucoup.",
        'min' => 3, 'max' => 20, 'defaut' => 5, 'unite' => 'tentatives',
    ],
    'CONNEXION_BLOCAGE' => [
        'groupe' => 'freins', 'libelle' => 'Connexion, par adresse IP : blocage',
        'aide' => "Durée de base, doublée à chaque récidive.",
        'min' => 60, 'max' => 600, 'defaut' => 60, 'unite' => 's',
    ],
    'CONNEXION_MAX_ESSAIS_COMPTE' => [
        'groupe' => 'freins', 'libelle' => 'Connexion, par compte : tentatives tolérées',
        'aide' => "Arrête l'attaque distribuée. Volontairement plus tolérant : qui connaît un identifiant pourrait sinon verrouiller le compte de son titulaire.",
        'min' => 3, 'max' => 20, 'defaut' => 12, 'unite' => 'tentatives',
    ],
    'CONNEXION_BLOCAGE_COMPTE' => [
        'groupe' => 'freins', 'libelle' => 'Connexion, par compte : blocage',
        'aide' => "Durée de base, doublée à chaque récidive.",
        'min' => 60, 'max' => 600, 'defaut' => 120, 'unite' => 's',
    ],
    'INSCRIPTION_MAX' => [
        'groupe' => 'freins', 'libelle' => 'Inscriptions, par adresse IP : tentatives tolérées',
        'aide' => "Créations de compte.",
        'min' => 3, 'max' => 20, 'defaut' => 5, 'unite' => 'tentatives',
    ],
    'INSCRIPTION_BLOCAGE' => [
        'groupe' => 'freins', 'libelle' => 'Inscriptions, par adresse IP : blocage',
        'aide' => "Durée de base, doublée à chaque récidive.",
        'min' => 60, 'max' => 600, 'defaut' => 600, 'unite' => 's',
    ],
    'MDP_OUBLIE_MAX' => [
        'groupe' => 'freins', 'libelle' => '« Mot de passe oublié », par adresse IP : demandes tolérées',
        'aide' => "Le SEUL frein sur les e-mails de réinitialisation : à ne pas desserrer à la légère.",
        'min' => 3, 'max' => 20, 'defaut' => 3, 'unite' => 'demandes',
    ],
    'MDP_OUBLIE_BLOCAGE' => [
        'groupe' => 'freins', 'libelle' => '« Mot de passe oublié », par adresse IP : blocage',
        'aide' => "Durée de base, doublée à chaque récidive.",
        'min' => 60, 'max' => 900, 'defaut' => 900, 'unite' => 's',
    ],
    'VERIF_RENVOI_MAX' => [
        'groupe' => 'freins', 'libelle' => 'Renvoi du lien de confirmation, par adresse IP : demandes tolérées',
        'aide' => "Même remarque : un frein sur les e-mails.",
        'min' => 3, 'max' => 20, 'defaut' => 3, 'unite' => 'demandes',
    ],
    'VERIF_RENVOI_BLOCAGE' => [
        'groupe' => 'freins', 'libelle' => 'Renvoi du lien de confirmation, par adresse IP : blocage',
        'aide' => "Durée de base, doublée à chaque récidive.",
        'min' => 60, 'max' => 600, 'defaut' => 600, 'unite' => 's',
    ],
    'MDP_CONFIRM_MAX' => [
        'groupe' => 'freins', 'libelle' => 'Confirmation par mot de passe, tentatives tolérées',
        'aide' => "Changement d'adresse, vidage, suppression du compte : sans limite, une session volée devinerait le mot de passe sans trace.",
        'min' => 3, 'max' => 20, 'defaut' => 5, 'unite' => 'tentatives',
    ],
    'MDP_CONFIRM_BLOCAGE' => [
        'groupe' => 'freins', 'libelle' => 'Confirmation par mot de passe, blocage',
        'aide' => "Durée de base, doublée à chaque récidive.",
        'min' => 60, 'max' => 600, 'defaut' => 60, 'unite' => 's',
    ],
];

/* ---------------------------------------------------------------------
   Décisions pures
   --------------------------------------------------------------------- */

/** Le facteur entre ce qu'on saisit et ce que le .env compte (1 le plus souvent). */
function reglage_facteur(string $cle): int
{
    return (int) (REGLAGES[$cle]['facteur'] ?? 1);
}

/**
 * Une valeur native affichée dans l'unité de la page : 86400 → « 1 »
 * (jours), 3600 → « 60 » (minutes), 7 → « 7 ». Jamais de zéros inutiles.
 */
function reglage_vers_saisie(string $cle, int $native): string
{
    $facteur = reglage_facteur($cle);
    if ($facteur <= 1) {
        return (string) $native;
    }
    $q = $native / $facteur;
    return $q == floor($q) ? (string) (int) $q : rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
}

/** « de 60 à 600 s », « de 0 à 365 jours (0 : jusqu'à la fermeture du navigateur) ». */
function reglage_plage_texte(string $cle): string
{
    $r = REGLAGES[$cle] ?? null;
    if ($r === null) {
        return '';
    }
    if (isset($r['choix'])) {
        return '';
    }
    $unite = (string) ($r['unite'] ?? '');
    $texte = 'de ' . reglage_vers_saisie($cle, $r['min']) . ' à ' . reglage_vers_saisie($cle, $r['max'])
        . ($unite !== '' ? ' ' . $unite : '');
    if (isset($r['zero']) && $r['min'] === 0) {
        $texte .= ' (0 : ' . $r['zero'] . ')';
    }
    return $texte;
}

/**
 * Ce qu'on saisit, jugé : [valeur native en chaîne, ''] s'il convient, sinon
 * [null, message à afficher sous le champ]. La valeur native est ce qui part
 * en base — celle du .env, jamais celle de la page.
 *
 * @return array{0: ?string, 1: string}
 */
function reglage_valider(string $cle, mixed $saisie): array
{
    $r = REGLAGES[$cle] ?? null;
    if ($r === null) {
        return [null, 'Réglage inconnu.'];
    }
    $texte = is_scalar($saisie) && !is_bool($saisie) ? trim((string) $saisie) : '';

    if (isset($r['choix'])) {
        return array_key_exists($texte, $r['choix'])
            ? [$texte, '']
            : [null, 'Choix inconnu.'];
    }

    if (!preg_match('/^\d{1,9}$/', $texte)) {
        return [null, 'Entrez un nombre entier ' . reglage_plage_texte($cle) . '.'];
    }
    $native = (int) $texte * reglage_facteur($cle);
    if ($native < $r['min'] || $native > $r['max']) {
        $unite = (string) ($r['unite'] ?? '');
        return [null, 'Entre ' . reglage_vers_saisie($cle, $r['min']) . ' et '
            . reglage_vers_saisie($cle, $r['max']) . ($unite !== '' ? ' ' . $unite : '') . '.'];
    }
    return [(string) $native, ''];
}

/**
 * Ce que la base porte, ramené à ce qui est permis : seules les clés de la
 * liste, valeurs entières ramenées dans leurs bornes (une ligne tapée à la
 * main en SQL ne dépasse jamais l'intervalle), choix reconnus. Le reste est
 * ignoré — le .env reprend alors la main, sans erreur.
 *
 * @param  array<string, mixed> $lignes clé => valeur, telles que la base les rend
 * @return array<string, string>
 */
function reglages_filtrer(array $lignes): array
{
    $retenus = [];
    foreach ($lignes as $cle => $valeur) {
        $r = REGLAGES[$cle] ?? null;
        if ($r === null || !is_scalar($valeur)) {
            continue;
        }
        $valeur = trim((string) $valeur);
        if (isset($r['choix'])) {
            if (array_key_exists($valeur, $r['choix'])) {
                $retenus[$cle] = $valeur;
            }
            continue;
        }
        if (preg_match('/^\d{1,10}$/', $valeur)) {
            $retenus[$cle] = (string) min($r['max'], max($r['min'], (int) $valeur));
        }
    }
    return $retenus;
}

/** D'où vient la valeur : « base » (changée ici), « env » (le .env la fixe), « defaut ». */
function reglage_source(string $cle, array $base, string $env_brut): string
{
    if (isset($base[$cle])) {
        return 'base';
    }
    return trim($env_brut) !== '' ? 'env' : 'defaut';
}

/**
 * Ce que la page montre d'un réglage. `$actuel` : la valeur réellement en
 * vigueur (la constante, déjà bornée par config.php) ; `$base` : ce que la base
 * porte ; `$env_brut` : le .env, tel quel.
 *
 * La case montre ce qui a été SAISI quand la base le porte, et la valeur en
 * vigueur sinon. Elles diffèrent parfois — c'est alors dit en clair
 * (`en_vigueur`) : un quota « illimité » ne passe jamais sous le quota
 * ordinaire, un rapport ne part pas plus souvent que le cron ne passe.
 */
function reglage_vue(string $cle, array $base, string $env_brut, int $actuel): array
{
    $r      = REGLAGES[$cle];
    $source = reglage_source($cle, $base, $env_brut);
    $natif  = $source === 'base' ? (int) $base[$cle] : $actuel;
    $vue = $r + [
        'cle'        => $cle,
        'source'     => $source,
        'saisie'     => isset($r['choix']) ? (string) $natif : reglage_vers_saisie($cle, $natif),
        'plage'      => reglage_plage_texte($cle),
        'min_saisie' => reglage_vers_saisie($cle, $r['min']),
        'max_saisie' => reglage_vers_saisie($cle, $r['max']),
        'en_vigueur' => null,
    ];
    if ($source === 'base' && $natif !== $actuel && !isset($r['choix'])) {
        $vue['en_vigueur'] = reglage_vers_saisie($cle, $actuel)
            . (($r['unite'] ?? '') !== '' ? ' ' . $r['unite'] : '');
    }
    return $vue;
}

/**
 * Le texte « sans ce réglage » : ce qui s'applique si on revient au .env.
 * Le .env tel qu'il est écrit, ou la valeur par défaut du code.
 */
function reglage_sans_base_texte(string $cle, string $env_brut): string
{
    $r = REGLAGES[$cle];
    $brut = trim($env_brut);
    if (isset($r['choix'])) {
        $v = $brut !== '' ? $brut : (string) $r['defaut'];
        return ($r['choix'][$v] ?? $v) . ($brut !== '' ? ' (.env)' : ' (défaut)');
    }
    $unite = (($r['unite'] ?? '') !== '' ? ' ' . $r['unite'] : '');
    if ($brut !== '' && preg_match('/^\d{1,10}$/', $brut)) {
        return reglage_vers_saisie($cle, (int) $brut) . $unite . ' (.env)';
    }
    return reglage_vers_saisie($cle, (int) $r['defaut']) . $unite . ' (défaut)';
}

/* ---------------------------------------------------------------------
   Base de données
   --------------------------------------------------------------------- */

/**
 * Ce que la base porte, filtré (reglages_filtrer). Une table absente
 * (migration 12 de livre.sql pas encore rejouée, ou la base « vide » des
 * tests) n'est pas une erreur : le .env gouverne seul, sans bruit. Toute
 * autre panne est consignée — une fois par requête, pas par réglage — et ne
 * coupe rien : mieux vaut tourner sur le .env que de ne plus tourner.
 *
 * @return array<string, string>
 */
function reglages_lire(PDO $pdo): array
{
    try {
        $lignes = $pdo->query('SELECT cle, valeur FROM reglage')->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S02') {
            error_log('reglages_lire: ' . $e->getMessage());
        }
        return [];
    }
    return reglages_filtrer($lignes);
}

/** La table `reglage` existe-t-elle ? La page n'offre l'édition que si oui. */
function reglages_disponibles(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM reglage LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Range une valeur NATIVE (déjà validée par reglage_valider). */
function reglage_ecrire(PDO $pdo, string $cle, string $valeur, int $par): void
{
    $pdo->prepare(
        'INSERT INTO reglage (cle, valeur, modifie_par) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), modifie_par = VALUES(modifie_par)'
    )->execute([$cle, $valeur, $par]);
}

/** Revient au .env : la ligne disparaît. */
function reglage_effacer(PDO $pdo, string $cle): void
{
    $pdo->prepare('DELETE FROM reglage WHERE cle = ?')->execute([$cle]);
}
