<?php
/* =====================================================================
   exiger_csrf() — la barrière posée sur CHAQUE POST du site.

   Aucune requête GET ne modifie de données, et tout POST passe par ici :
   un jeton de session est exigé, y compris pour la déconnexion. La
   fonction se termine par exit dès qu elle refuse, d où le passage par
   un sous-processus (tests/outils/appeler-exiger-csrf.php).
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

/** Lance le scénario $cas et rend ['sortie' => ..., 'code' => ...]. */
function barriere(string $cas): array
{
    $resultat = executer_php(__DIR__ . '/../outils/appeler-exiger-csrf.php', [$cas]);
    $sortie   = $resultat['sortie'];
    $code     = preg_match('/^##CODE##(\d+)$/m', $sortie, $m) ? $m[1] : '';

    return [
        'sortie' => trim(explode('##CODE##', $sortie)[0]),
        'code'   => $code,
    ];
}

groupe('exiger_csrf() — le jeton valide passe');

test('un POST avec le bon jeton est accepté', function () {
    /* Garde-fou : si même le bon jeton était refusé, tous les tests de
       refus ci-dessous passeraient sans rien prouver. */
    $r = barriere('bon');
    contient('REQUETE-ACCEPTEE', $r['sortie'], 'la requête poursuit son cours');
    egale('200', $r['code'], 'aucun code d erreur');
});

groupe('exiger_csrf() — les refus');

test('un POST sans jeton est refusé en 403', function () {
    $r = barriere('absent');
    egale('403', $r['code'], 'accès refusé');
    sans('REQUETE-ACCEPTEE', $r['sortie'], 'la requête est interrompue');
});

test('un POST avec un mauvais jeton est refusé en 403', function () {
    $r = barriere('mauvais');
    egale('403', $r['code'], 'accès refusé');
    sans('REQUETE-ACCEPTEE', $r['sortie'], 'la requête est interrompue');
});

test('un jeton envoyé sous forme de tableau est refusé proprement', function () {
    /* « csrf[]=x » fait arriver un tableau. Sans le is_string() de
       csrf_valide(), hash_equals() lèverait une erreur de type et la
       page finirait en 500 — un refus, mais pour la mauvaise raison, et
       avec une trace dans le journal à chaque tentative. */
    $r = barriere('tableau');
    egale('403', $r['code'], 'un refus propre, pas une erreur serveur');
    sans('REQUETE-ACCEPTEE', $r['sortie'], 'la requête est interrompue');
});

test('le message de refus dit quoi faire', function () {
    // « Rechargez la page » : sans ça, l utilisateur dont la session a
    // expiré pendant qu il remplissait un formulaire reste bloqué.
    $r = barriere('absent');
    contient('Rechargez la page', $r['sortie'], 'une conduite à tenir est donnée');
});

groupe('exiger_csrf() — le fichier trop lourd, et non « jeton invalide »');

test('un corps rejeté par PHP donne un 413, pas un 403', function () {
    /* Quand le corps dépasse post_max_size, PHP vide $_POST : le jeton
       manque, et le contrôle CSRF échouerait en premier. L utilisateur
       qui a simplement choisi une image trop lourde lirait alors
       « jeton de sécurité invalide » — un message sans rapport, qu aucun
       rechargement ne corrigera, et qui le laisse sans solution. */
    $r = barriere('trop-gros');
    egale('413', $r['code'], 'le code dit « contenu trop volumineux »');
});

test('le message parle bien de la taille de l image', function () {
    $r = barriere('trop-gros');
    contient('trop volumineux', $r['sortie'], 'la vraie cause est nommée');
    contient('image plus légère', $r['sortie'], 'une action concrète est proposée');
    sans('jeton de sécurité', $r['sortie'], 'le message trompeur n apparaît pas');
});
