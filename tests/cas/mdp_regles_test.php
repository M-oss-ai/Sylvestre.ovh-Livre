<?php
/* =====================================================================
   La politique de mot de passe réglable (demande de l'utilisateur) :
     MDP_MIN        de 1 à 200 caractères (défaut 8)
     MDP_MAJ, MDP_MINUSCULE, MDP_CHIFFRE, MDP_SPE
                    1 = exigée (défaut), 0 = non

   Les constantes sont figées au chargement : chaque politique se juge dans
   son propre processus (tests/outils/valider-mdp.php), avec l'environnement
   qu'on lui impose. On vérifie que chaque classe se désactive SEULE, que
   les trois autres restent exigées, que la longueur citée dans le message
   est la vraie, et que ce que la page dit au navigateur (attributs_regles_mdp)
   est ce que le serveur applique.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

/** Juge $mdp sous la politique $env ; rend ['erreurs' => …, 'attributs' => …, 'constantes' => …]. */
function juger(string $mdp, array $env = [], string $identifiant = ''): array
{
    // Une variable vide ne compte pas comme absente : on la pose vide pour que le .env local n'interfère pas.
    $defauts = ['MDP_MIN' => '', 'MDP_MAJ' => '', 'MDP_MINUSCULE' => '', 'MDP_CHIFFRE' => '', 'MDP_SPE' => ''];
    $r = executer_php(CHEMIN_PROJET . '/tests/outils/valider-mdp.php', [$mdp, $identifiant], $env + $defauts);
    vrai(preg_match('/##MDP##(\{.*\})/', $r['sortie'], $m) === 1, 'le sous-processus répond : ' . substr($r['sortie'], 0, 300));
    return json_decode($m[1] ?? '{}', true) ?: [];
}

function contient_message(array $erreurs, string $morceau): bool
{
    foreach ($erreurs as $e) {
        if (str_contains($e, $morceau)) {
            return true;
        }
    }
    return false;
}

const TOUT_DESACTIVE = ['MDP_MIN' => '1', 'MDP_MAJ' => '0', 'MDP_MINUSCULE' => '0', 'MDP_CHIFFRE' => '0', 'MDP_SPE' => '0'];

groupe('Par défaut — rien ne change : longueur 8 et les quatre classes exigées');

test('les défauts', function () {
    $r = juger('Abcdef1!');
    $c = $r['constantes'];
    egale([8, true, true, true, true], [$c['MDP_MIN'], $c['MDP_MAJ'], $c['MDP_MINUSCULE'], $c['MDP_CHIFFRE'], $c['MDP_SPE']], 'les constantes');
    egale([], $r['erreurs'], 'un mot de passe complet passe');
    $nu = juger('abcdefgh');
    vrai(contient_message($nu['erreurs'], 'majuscule') && contient_message($nu['erreurs'], 'chiffre') && contient_message($nu['erreurs'], 'caractère spécial'),
        'une majuscule, un chiffre et un caractère spécial manquent : ' . implode(' | ', $nu['erreurs']));
    faux(contient_message($nu['erreurs'], 'minuscule'), 'la minuscule est là');
});

groupe('Chaque classe se désactive SEULE — les trois autres restent exigées');

test('MDP_MAJ=0 : plus de majuscule exigée', function () {
    egale([], juger('abcdefg1!', ['MDP_MAJ' => '0'])['erreurs'], 'sans majuscule, ça passe');
    $autres = juger('abcdefgh', ['MDP_MAJ' => '0']);
    faux(contient_message($autres['erreurs'], 'majuscule'), 'la majuscule n\'est plus dite');
    vrai(contient_message($autres['erreurs'], 'chiffre') && contient_message($autres['erreurs'], 'spécial'), 'le chiffre et le spécial le sont toujours');
});

test('MDP_MINUSCULE=0 : plus de minuscule exigée', function () {
    egale([], juger('ABCDEFG1!', ['MDP_MINUSCULE' => '0'])['erreurs'], 'sans minuscule, ça passe');
    $autres = juger('ABCDEFGH', ['MDP_MINUSCULE' => '0']);
    faux(contient_message($autres['erreurs'], 'minuscule'), 'la minuscule n\'est plus dite');
    vrai(contient_message($autres['erreurs'], 'chiffre') && contient_message($autres['erreurs'], 'spécial'), 'le chiffre et le spécial le sont toujours');
});

test('MDP_CHIFFRE=0 : plus de chiffre exigé', function () {
    egale([], juger('Abcdefgh!', ['MDP_CHIFFRE' => '0'])['erreurs'], 'sans chiffre, ça passe');
    $autres = juger('abcdefgh', ['MDP_CHIFFRE' => '0']);
    faux(contient_message($autres['erreurs'], 'chiffre'), 'le chiffre n\'est plus dit');
    vrai(contient_message($autres['erreurs'], 'majuscule') && contient_message($autres['erreurs'], 'spécial'), 'la majuscule et le spécial le sont toujours');
});

test('MDP_SPE=0 : plus de caractère spécial exigé', function () {
    egale([], juger('Abcdefgh1', ['MDP_SPE' => '0'])['erreurs'], 'sans caractère spécial, ça passe');
    $autres = juger('abcdefgh', ['MDP_SPE' => '0']);
    faux(contient_message($autres['erreurs'], 'spécial'), 'le spécial n\'est plus dit');
    vrai(contient_message($autres['erreurs'], 'majuscule') && contient_message($autres['erreurs'], 'chiffre'), 'la majuscule et le chiffre le sont toujours');
});

groupe('Seul « 0 » désactive : une faute de frappe ne desserre rien');

test('vide, « non », « false », « 2 » : la classe reste exigée', function () {
    foreach (['', 'non', 'false', '2', 'abc'] as $valeur) {
        $r = juger('abcdefg1!', ['MDP_MAJ' => $valeur]);
        vrai($r['constantes']['MDP_MAJ'], "« $valeur » laisse la majuscule exigée");
        vrai(contient_message($r['erreurs'], 'majuscule'), "« $valeur » : le serveur la réclame");
    }
});

test('« 0 » entouré d\'espaces désactive aussi', function () {
    faux(juger('Abcdefgh1', ['MDP_SPE' => ' 0 '])['constantes']['MDP_SPE'], 'les espaces de bord sont ignorés');
});

groupe('Tout désactivé : seule la longueur compte');

test('un mot de passe d\'un seul caractère avec MDP_MIN=1', function () {
    egale([], juger('a', TOUT_DESACTIVE)['erreurs'], 'un caractère suffit');
    egale([MESSAGE_CHAMP_OBLIGATOIRE], juger('', TOUT_DESACTIVE)['erreurs'], 'vide : « obligatoire », rien d\'autre');
});

test('les règles qui ne sont pas des classes restent en place', function () {
    vrai(contient_message(juger(' a', TOUT_DESACTIVE)['erreurs'], 'commencer ni se terminer par un espace'), 'les espaces de bord restent refusés');
    vrai(contient_message(juger('marcmarc', TOUT_DESACTIVE, 'marc')['erreurs'], 'votre identifiant'), 'l\'identifiant dans le mot de passe aussi');
    $trop = juger(str_repeat('a', 300), TOUT_DESACTIVE);
    vrai(contient_message($trop['erreurs'], 'trop long'), 'le maximum aussi (' . $trop['constantes']['MDP_MAX'] . ')');
});

groupe('MDP_MIN : de 1 à 200');

test('le message cite le vrai minimum', function () {
    foreach (['12', '200'] as $min) {
        $r = juger('A1!', ['MDP_MIN' => $min]);
        egale((int) $min, $r['constantes']['MDP_MIN'], "MDP_MIN=$min");
        vrai(contient_message($r['erreurs'], 'au moins ' . $min . ' caractères'), "le message dit $min : " . implode(' | ', $r['erreurs']));
    }
    $r = juger('A1!', ['MDP_MIN' => '3']);
    faux(contient_message($r['erreurs'], 'au moins 3 caractères'), 'trois caractères utiles suffisent à MDP_MIN=3');
});

test('le plancher est 1 (l\'ancien 8 est levé), le plafond 200', function () {
    egale(1, juger('Abcdef1!', ['MDP_MIN' => '0'])['constantes']['MDP_MIN'], '0 devient 1');
    egale(1, juger('Abcdef1!', ['MDP_MIN' => '-5'])['constantes']['MDP_MIN'], 'négatif : 1');
    egale(200, juger('Abcdef1!', ['MDP_MIN' => '5000'])['constantes']['MDP_MIN'], '5000 devient 200');
    egale(1, juger('Abcdef1!', ['MDP_MIN' => 'abc'])['constantes']['MDP_MIN'], 'un texte vaut 0, donc 1 : jamais NaN');
});

test('MDP_MAX reste toujours au-dessus de MDP_MIN, même à 200', function () {
    $r = juger('Abcdef1!', ['MDP_MIN' => '200']);
    vrai($r['constantes']['MDP_MAX'] > $r['constantes']['MDP_MIN'], 'le maximum (' . $r['constantes']['MDP_MAX'] . ') dépasse le minimum');
    egale([], juger(str_repeat('Ab1!', 50), ['MDP_MIN' => '200'])['erreurs'], 'un mot de passe de 200 caractères passe sous MDP_MIN=200');
});

groupe('attributs_regles_mdp() — ce que la page dit au navigateur est ce que le serveur applique');

test('par défaut : la longueur, le maximum et les quatre classes à 1', function () {
    egale('data-regles-mdp data-mdp-min="8" data-mdp-max="200" data-mdp-maj="1" data-mdp-minuscule="1" data-mdp-chiffre="1" data-mdp-spe="1"',
        juger('x')['attributs'], 'les attributs');
});

test('les classes désactivées sont dites à 0, la longueur réglée est citée', function () {
    $a = juger('x', ['MDP_MIN' => '5', 'MDP_MAJ' => '0', 'MDP_SPE' => '0'])['attributs'];
    contient('data-mdp-min="5"', $a, 'la longueur');
    contient('data-mdp-maj="0"', $a, 'majuscule non exigée');
    contient('data-mdp-minuscule="1"', $a, 'minuscule exigée');
    contient('data-mdp-chiffre="1"', $a, 'chiffre exigé');
    contient('data-mdp-spe="0"', $a, 'spécial non exigé');
});

test('chaque page qui crée un mot de passe pose ces attributs, par la même fonction', function () {
    foreach (['inscription.php', 'google-inscription.php', 'parametres.php', 'reinitialiser-mot-de-passe.php'] as $page) {
        $source = (string) file_get_contents(CHEMIN_SITE . '/' . $page);
        contient('<?= attributs_regles_mdp() ?>', $source, $page . ' : les attributs viennent de la fonction');
        sans('data-mdp-min="<?=', $source, $page . ' : plus de copie à la main, qui oublierait une règle');
    }
});
