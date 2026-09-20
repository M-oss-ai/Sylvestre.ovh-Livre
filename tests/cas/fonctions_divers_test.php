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

test('prénom et nom donnent deux initiales', function () {
    egale('MB', initiales(['prenom' => 'Marc', 'nom' => 'Bonvin']), 'la forme habituelle');
});

test('un seul des deux suffit', function () {
    egale('M', initiales(['prenom' => 'Marc', 'nom' => '']), 'prénom seul');
    egale('B', initiales(['prenom' => '', 'nom' => 'Bonvin']), 'nom seul');
});

test('le résultat est en majuscules', function () {
    egale('MB', initiales(['prenom' => 'marc', 'nom' => 'bonvin']), 'saisie en minuscules');
});

test('les accents sont conservés et mis en majuscule', function () {
    /* mb_strtoupper() et non strtoupper() : ce dernier laisserait « é »
       intact et l avatar afficherait « éO » au lieu de « ÉÔ ». */
    egale('ÉÔ', initiales(['prenom' => 'éva', 'nom' => 'ôte']), 'É et Ô');
});

test('un prénom non latin n est pas tronqué au milieu d un caractère', function () {
    // mb_substr() compte des caractères, pas des octets.
    egale('荒川', initiales(['prenom' => '荒', 'nom' => '川']), 'des idéogrammes');
});

test('sans nom ni prénom, on retombe sur l identifiant', function () {
    egale('LE', initiales(['identifiant' => 'lecteur92']), 'les deux premières lettres');
});

test('les espaces ne comptent pas pour un prénom', function () {
    /* Sans le trim(), un prénom fait d un seul espace produirait une
       initiale vide au lieu de laisser la place à l identifiant. */
    egale('LE', initiales(['prenom' => '   ', 'nom' => '', 'identifiant' => 'lecteur92']),
        'on passe bien à l identifiant');
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

groupe('STATUTS — les quatre états d une série');

test('les quatre statuts sont définis', function () {
    egale(['cours', 'envie', 'termine', 'abandon'], array_keys(STATUTS), 'les clés attendues');
});

test('chaque statut a un libellé lisible', function () {
    foreach (STATUTS as $cle => $libelle) {
        differe('', $libelle, "le statut « {$cle} » a un libellé");
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
