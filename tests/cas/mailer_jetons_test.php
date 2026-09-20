<?php
/* =====================================================================
   jeton_action_valide() — le garde-fou de forme, avant toute requête.

   Les jetons de confirmation d adresse, de réinitialisation de mot de
   passe et de changement d e-mail ne sont jamais stockés en clair :
   seule leur empreinte sha256 va en base. La vérification commence donc
   par un contrôle de FORME — 64 caractères hexadécimaux — et ce contrôle
   rend « null » sans interroger la base.

   C est ce court-circuit qui est testé ici, et c est lui qui permet de
   le faire sans base de données : un jeton mal formé est refusé avant
   la moindre requête. Il évite aussi qu une valeur arbitraire venue de
   l URL n aille jusqu au calcul d empreinte et à une requête, à chaque
   tentative d un robot qui balaie le site.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('jeton_action_valide() — les formes refusées d emblée');

test('un jeton vide est refusé', function () {
    estNul(jeton_action_valide('', 'reinitialisation'), 'aucun compte rendu');
});

test('un jeton trop court est refusé', function () {
    estNul(jeton_action_valide(str_repeat('a', 63), 'reinitialisation'), '63 caractères');
});

test('un jeton trop long est refusé', function () {
    estNul(jeton_action_valide(str_repeat('a', 65), 'reinitialisation'), '65 caractères');
});

test('un jeton en majuscules est refusé', function () {
    /* bin2hex() ne produit que des minuscules : accepter les majuscules
       élargirait la surface sans aucun bénéfice. */
    estNul(jeton_action_valide(str_repeat('A', 64), 'reinitialisation'), 'hexadécimal majuscule');
});

test('un jeton contenant autre chose que de l hexadécimal est refusé', function () {
    estNul(jeton_action_valide(str_repeat('z', 64), 'reinitialisation'), 'des lettres hors hexa');
    estNul(jeton_action_valide(str_repeat('a', 63) . '!', 'reinitialisation'), 'un caractère spécial');
    estNul(jeton_action_valide(str_repeat('a', 63) . ' ', 'reinitialisation'), 'un espace');
});

test('une tentative d injection SQL est refusée par la forme', function () {
    /* Les requêtes sont préparées — l injection ne passerait pas de
       toute façon — mais elle n atteint même pas la base : elle échoue
       sur la longueur et le jeu de caractères. */
    estNul(jeton_action_valide("' OR 1=1 --", 'reinitialisation'), 'refusé avant toute requête');
});

test('un jeton contenant un retour à la ligne est refusé', function () {
    estNul(jeton_action_valide(str_repeat('a', 32) . "\n" . str_repeat('a', 31), 'reinitialisation'),
        'le motif est ancré aux deux extrémités');
});

test('un jeton bien formé mais inconnu ne trouve rien', function () {
    /* Celui-ci franchit le contrôle de forme et va jusqu à la base. La
       base de test ne porte pas les tables de l application : la requête
       échoue, et cet échec est le signe que le contrôle de forme a bien
       laissé passer une valeur valide. Le comportement avec une vraie
       base — « aucune ligne, donc null » — relève d un test
       d intégration, hors du périmètre de cette suite. */
    leve(
        PDOException::class,
        static fn () => jeton_action_valide(str_repeat('a', 64), 'reinitialisation'),
        'la forme est acceptée et la requête est bien tentée'
    );
});
