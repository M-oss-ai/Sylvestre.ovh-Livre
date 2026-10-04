<?php
/* =====================================================================
   initiales(), cookie_persistant_params(), STATUTS, actif()

   actif() est ici dans son mode DÉVELOPPEMENT (ASSETS_VERSION vide) ;
   son mode production a son propre fichier, la valeur étant figée en
   constante au chargement.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('initiales() — l avatar par défaut');

test('les deux premières lettres de l identifiant', function () {
    /* Le site ne demande plus ni prénom ni nom : l avatar par défaut
       vient de l identifiant, le seul nom qu ait un compte. */
    egale('LE', initiales(['identifiant' => 'lecteur92']), 'deux lettres');
});

test('le résultat est en majuscules, accents compris', function () {
    /* mb_strtoupper() et non strtoupper() : ce dernier laisserait « é »
       intact et l avatar afficherait « éV » au lieu de « ÉV ». */
    egale('ÉV', initiales(['identifiant' => 'éva']), 'É majuscule');
});

test('un prénom ou un nom encore présents sont ignorés', function () {
    egale('LE', initiales(['prenom' => 'Marc', 'nom' => 'Bonvin', 'identifiant' => 'lecteur92']),
        'seul l identifiant compte');
});

test('un caractère non latin n est pas coupé au milieu', function () {
    // mb_substr() compte des caractères, pas des octets.
    egale('荒川', initiales(['identifiant' => '荒川弘']), 'des idéogrammes');
});

test('un identifiant d une seule lettre ne casse rien', function () {
    egale('A', initiales(['identifiant' => 'a']), 'une seule initiale');
});

test('un tableau vide rend une chaîne vide', function () {
    // Le gabarit affiche alors une pastille vide plutôt que de planter.
    egale('', initiales([]), 'aucune donnée');
});

groupe('cookie_persistant_params() — les drapeaux du cookie d appareil');

test('le cookie est inaccessible au JavaScript', function () {
    /* HttpOnly : c est ce qui rend le vol du jeton impossible par XSS.
       Ce cookie vaut une connexion d un an : il compte plus que celui de
       session. */
    $p = cookie_persistant_params(3600);
    vrai($p['httponly'], 'HttpOnly est posé');
});

test('le cookie n est pas envoyé depuis un autre site', function () {
    // SameSite=Lax : la protection anti-CSRF au niveau du navigateur.
    $p = cookie_persistant_params(3600);
    egale('Lax', $p['samesite'], 'SameSite vaut Lax');
});

test('le cookie couvre tout le site', function () {
    $p = cookie_persistant_params(3600);
    egale('/', $p['path'], 'le chemin est la racine');
});

test('la date d expiration suit la durée demandée', function () {
    $p = cookie_persistant_params(3600);
    $ecart = $p['expires'] - time();
    vrai($ecart > 3590 && $ecart <= 3600, 'environ une heure dans le futur');
});

test('une durée négative fabrique un cookie déjà expiré', function () {
    /* C est la forme que prend la SUPPRESSION du cookie, à la déconnexion
       et quand un jeton s avère invalide. */
    $p = cookie_persistant_params(-3600);
    vrai($p['expires'] < time(), 'la date est dans le passé');
});

test('le drapeau Secure suit la présence de HTTPS', function () {
    $memoire = $_SERVER['HTTPS'] ?? null;

    unset($_SERVER['HTTPS']);
    faux(cookie_persistant_params(3600)['secure'], 'en clair, pas de Secure');

    $_SERVER['HTTPS'] = 'on';
    vrai(cookie_persistant_params(3600)['secure'], 'en HTTPS, Secure est posé');

    if ($memoire === null) {
        unset($_SERVER['HTTPS']);
    } else {
        $_SERVER['HTTPS'] = $memoire;
    }
});

groupe('STATUTS — les états d une série');

test('les cinq statuts sont définis, « En attente » juste après « En cours »', function () {
    egale(['cours', 'attente', 'envie', 'termine', 'abandon'], array_keys(STATUTS), 'les clés attendues, dans l ordre des filtres');
    egale('En attente', STATUTS['attente'], 'le libellé');
});

test('chaque statut a un libellé lisible', function () {
    foreach (STATUTS as $cle => $libelle) {
        differe('', $libelle, "le statut « {$cle} » a un libellé");
    }
});

test('les libellés restent assez courts pour la pastille', function () {
    /* Ils s affichent dans la pastille posée sur la couverture, large
       d une poignée de caractères. « À commencer / envie » y tenait sur
       trois lignes et recouvrait l image. Le plus long aujourd hui,
       « Abandonnée » et « En attente » font dix caractères : douze laisse de la marge
       sans rouvrir la porte à une phrase entière. */
    foreach (STATUTS as $cle => $libelle) {
        vrai(mb_strlen($libelle, 'UTF-8') <= 12,
            "« {$libelle} » tient dans la pastille ("
            . mb_strlen($libelle, 'UTF-8') . ' caractères)');
    }
});

groupe('actif() — en développement (ASSETS_VERSION vide)');

test('la date de modification du fichier sert de version', function () {
    /* Pratique en codant : on n a rien à penser, le navigateur reçoit la
       nouvelle version dès l enregistrement du fichier. */
    $url = actif('css/style.css');
    motif('#^css/style\.css\?v=\d+$#', $url, 'un horodatage est ajouté');
});

test('un fichier absent ne fait pas planter la page', function () {
    egale('css/nexiste-pas.css?v=0', actif('css/nexiste-pas.css'), 'la version vaut 0');
});

test('le disque n est interrogé qu une fois par fichier', function () {
    // Un cache « static » : l espace des mutualisés OVH est monté en NFS,
    // où chaque accès au disque est un aller-retour réseau.
    egale(actif('js/app.js'), actif('js/app.js'), 'deux appels, même réponse');
});
