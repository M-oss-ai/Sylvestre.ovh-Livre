<?php
/* =====================================================================
   valider_mot_de_passe()

   Une seule définition pour les quatre endroits qui créent ou changent
   un mot de passe (inscription, paramètres avec et sans JavaScript,
   réinitialisation). Ces tests sont donc la description de la politique
   du site — et le filet qui empêche de la desserrer sans s en rendre
   compte.

   Retourne la LISTE des manquements : un tableau vide veut dire accepté.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

/** Y a-t-il, parmi les erreurs, un message qui parle de $sujet ? */
function erreur_mentionne(array $erreurs, string $sujet): bool
{
    foreach ($erreurs as $message) {
        if (mb_stripos($message, $sujet, 0, 'UTF-8') !== false) {
            return true;
        }
    }
    return false;
}

groupe('valider_mot_de_passe() — ce qui est accepté');

test('un mot de passe conforme passe', function () {
    vide(valider_mot_de_passe('Abcdef1!'), 'huit caractères, les quatre classes');
});

test('les accents comptent comme des lettres', function () {
    /* \p{Lu} et \p{Ll} plutôt que [A-Z] et [a-z] : refuser les accents à
       des utilisateurs francophones n aurait aucun sens. */
    vide(valider_mot_de_passe('Église2024!'), 'une majuscule accentuée');
    vide(valider_mot_de_passe('Àbcdef1!'), 'À compte comme majuscule');
});

test('un mot de passe long et complexe passe', function () {
    vide(valider_mot_de_passe('Un-Mot-De-Passe-Tres-Long-2024!'), 'aucune limite gênante');
});

groupe('valider_mot_de_passe() — les quatre classes de caractères');

test('une majuscule est exigée', function () {
    vrai(erreur_mentionne(valider_mot_de_passe('abcdef1!'), 'majuscule'), 'manque signalé');
});

test('une minuscule est exigée', function () {
    vrai(erreur_mentionne(valider_mot_de_passe('ABCDEF1!'), 'minuscule'), 'manque signalé');
});

test('un chiffre est exigé', function () {
    vrai(erreur_mentionne(valider_mot_de_passe('Abcdefg!'), 'chiffre'), 'manque signalé');
});

test('un caractère spécial est exigé', function () {
    vrai(erreur_mentionne(valider_mot_de_passe('Abcdefg1'), 'spécial'), 'manque signalé');
});

test('les chiffres non latins comptent comme des chiffres', function () {
    // \p{Nd} couvre les chiffres décimaux de toutes les écritures.
    vide(valider_mot_de_passe('Abcdefg٣!'), 'un chiffre arabo-indien');
});

groupe('valider_mot_de_passe() — la longueur, espaces non comptés');

test('trop court est refusé', function () {
    vrai(erreur_mentionne(valider_mot_de_passe('Abc1!'), 'au moins'), 'cinq caractères');
});

test('le bourrage à l espace ne remplit pas la longueur', function () {
    /* « Aa1! » suivi de quatre espaces fait huit caractères et passerait
       la règle, alors qu il n y a que quatre caractères utiles : le
       minimum se compte donc SANS les espaces. */
    $erreurs = valider_mot_de_passe('Aa1!    ');
    vrai(erreur_mentionne($erreurs, 'au moins'), 'la longueur utile est bien de 4');
    vrai(erreur_mentionne($erreurs, 'espaces non comptés'), 'et le message l explique');
});

test('exactement MDP_MIN caractères utiles suffisent', function () {
    // La borne exacte : un « > » écrit à la place d un « >= » décalerait
    // toute la politique d un cran.
    vide(valider_mot_de_passe('Abcdef1!'), 'huit caractères pile');
});

test('un mot de passe trop long est refusé', function () {
    /* Borne technique et non exigence de sécurité : le coût du hachage
       croît avec la longueur, et un envoi de plusieurs mégaoctets
       occuperait le processeur pour rien. */
    $trop_long = 'Abcdef1!' . str_repeat('x', MDP_MAX);
    vrai(erreur_mentionne(valider_mot_de_passe($trop_long), 'trop long'), 'le maximum s applique');
});

test('le maximum compte TOUS les caractères, espaces inclus', function () {
    // Contrairement au minimum : c est une borne technique, pas une
    // exigence de complexité.
    $avec_espaces = 'Abcdef1!' . str_repeat(' ', MDP_MAX);
    vrai(erreur_mentionne(valider_mot_de_passe($avec_espaces), 'trop long'), 'les espaces comptent ici');
});

groupe('valider_mot_de_passe() — les espaces aux extrémités');

test('un espace en début est refusé, pas rogné en silence', function () {
    /* Le rogner reviendrait à enregistrer autre chose que ce qui a été
       tapé : l utilisateur ne parviendrait plus à se reconnecter et ne
       comprendrait pas pourquoi. Un clavier de téléphone en ajoute un
       après l autocomplétion. */
    vrai(erreur_mentionne(valider_mot_de_passe(' Abcdef1!'), 'espace'), 'signalé explicitement');
});

test('un espace en fin est refusé aussi', function () {
    vrai(erreur_mentionne(valider_mot_de_passe('Abcdef1! '), 'espace'), 'signalé explicitement');
});

test('un espace au milieu est parfaitement accepté', function () {
    // Une phrase de passe est un bon mot de passe : rien ne justifie de
    // l interdire.
    vide(valider_mot_de_passe('Ma phrase 2024!'), 'les espaces internes ne gênent pas');
});

groupe('valider_mot_de_passe() — les espaces invisibles');

test('un espace insécable ne compte pas comme caractère spécial', function () {
    /* \p{Z} en plus de \s : les espaces Unicode — insécable, cadratin,
       idéographique — sont invisibles à l écran et se glissent
       facilement dans un copier-coller. Sans ce cas, en coller un
       suffirait à satisfaire la règle du caractère spécial sans qu aucun
       caractère spécial n ait été réellement saisi. */
    $erreurs = valider_mot_de_passe("Abcdefg1\u{00A0}h");
    vrai(erreur_mentionne($erreurs, 'spécial'), 'l espace insécable ne fait pas l affaire');
});

test('un cadratin ne compte pas davantage', function () {
    $erreurs = valider_mot_de_passe("Abcdefg1\u{2003}h");
    vrai(erreur_mentionne($erreurs, 'spécial'), 'l espace cadratin non plus');
});

groupe('valider_mot_de_passe() — le mot de passe bâti sur l identifiant');

test('un mot de passe qui contient l identifiant est refusé', function () {
    /* « lecteur92 » donne « Lecteur92! », qui coche pourtant toutes les
       cases : c est la toute première chose que teste quiconque s en
       prend à un compte précis. */
    $erreurs = valider_mot_de_passe('Lecteur92!', 'lecteur92');
    vrai(erreur_mentionne($erreurs, 'identifiant'), 'la ressemblance est repérée');
});

test('la comparaison ignore la casse', function () {
    vrai(
        erreur_mentionne(valider_mot_de_passe('MonLECTEURx1!', 'lecteur'), 'identifiant'),
        'LECTEUR est reconnu malgré la casse'
    );
});

test('un identifiant de moins de trois caractères n est pas cherché', function () {
    /* Sinon un identifiant « ab » interdirait tout mot de passe contenant
       « ab » — soit une bonne partie des mots de passe corrects. */
    vide(valider_mot_de_passe('Fabuleux1!', 'ab'), 'un identifiant trop court est ignoré');
});

test('sans identifiant fourni, la règle ne s applique pas', function () {
    // Le cas de la réinitialisation par lien, où l appelant n a pas
    // forcément l identifiant sous la main.
    vide(valider_mot_de_passe('Abcdef1!'), 'aucun identifiant passé');
    vide(valider_mot_de_passe('Abcdef1!', '   '), 'un identifiant vide après trim');
});

groupe('valider_mot_de_passe() — entrée inexploitable');

test('de l UTF-8 invalide est refusé d un seul message', function () {
    /* preg_match() avec /u rend false sur une entrée invalide : sans ce
       garde-fou en tête de fonction, TOUTES les vérifications passeraient
       silencieusement à côté et n importe quoi serait accepté. */
    $erreurs = valider_mot_de_passe("Abcdef1!\xC3\x28");
    egale(1, count($erreurs), 'un seul message, pas une avalanche');
    vrai(erreur_mentionne($erreurs, 'non reconnus'), 'le message explique la cause');
});

test('une chaîne vide accumule les manquements', function () {
    $erreurs = valider_mot_de_passe('');
    vrai(count($erreurs) >= 5, 'longueur et les quatre classes sont signalées');
});
