<?php
/* =====================================================================
   includes/mailer.php — l'e-mail des nouveaux tomes.

   Une fonction PURE qui rend [sujet, corps]. Ce qu'on vérifie : UN message
   quel que soit le nombre de séries, le sujet sans aucun titre (donnée du
   compte, qui n'a rien à faire dans un en-tête), les titres ramenés sur
   une ligne, et ce que le HTML en fait — un titre ne devient jamais un
   lien ni une balise dans un message authentique du site.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('avis_nouveaux_tomes() — un tome');

test('le sujet et le corps disent ce qui est arrivé', function () {
    [$sujet, $corps] = avis_nouveaux_tomes('marco', [['titre' => 'Berserk', 'tome' => 44]]);
    egale('Un nouveau tome est disponible', $sujet, 'le sujet, au singulier');
    contient('Bonjour marco', $corps, 'adressé à la personne');
    contient('- « Berserk » : tome 44', $corps, 'la série et le tome à emprunter');
    contient('Sa couverture est déjà en place', $corps, 'singulier aussi dans le corps');
    contient(url_publique('index.php'), $corps, 'le lien vers la bibliothèque');
});

test('le message dit comment ne plus en recevoir, avec le lien des Paramètres', function () {
    [, $corps] = avis_nouveaux_tomes('marco', [['titre' => 'Berserk', 'tome' => 44]]);
    contient('Désactivez-les dans vos Paramètres', $corps, 'la sortie est dite');
    contient(url_publique('parametres.php#notifications'), $corps, 'et le lien mène à la bonne carte');
});

groupe('avis_nouveaux_tomes() — plusieurs séries : un seul message');

test('le sujet compte les tomes, le corps les liste tous', function () {
    [$sujet, $corps] = avis_nouveaux_tomes('marco', [
        ['titre' => 'Berserk', 'tome' => 44],
        ['titre' => 'One Piece', 'tome' => 115],
        ['titre' => 'Vagabond', 'tome' => 38],
    ]);
    egale('3 nouveaux tomes sont disponibles', $sujet, 'le pluriel, avec le compte');
    foreach (['- « Berserk » : tome 44', '- « One Piece » : tome 115', '- « Vagabond » : tome 38'] as $ligne) {
        contient($ligne, $corps, $ligne);
    }
    contient('Leurs couvertures sont déjà en place', $corps, 'pluriel dans le corps');
    contient('De nouveaux tomes', $corps, 'et dans l\'introduction');
});

groupe('avis_nouveaux_tomes() — ce qui vient du compte');

test('le sujet ne porte aucun titre', function () {
    [$sujet] = avis_nouveaux_tomes('marco', [['titre' => "Titre\r\nBcc: victime@exemple.test", 'tome' => 1]]);
    sans('Titre', $sujet, 'aucune donnée du compte dans un en-tête');
    sans("\n", $sujet, 'une seule ligne');
    sans("\r", $sujet, 'pas de retour chariot');
});

test('un titre sur plusieurs lignes est ramené sur une seule', function () {
    [, $corps] = avis_nouveaux_tomes('marco', [['titre' => "Une\r\nautre\n\nligne", 'tome' => 2]]);
    contient('- « Une autre ligne » : tome 2', $corps, 'espaces et sauts ramenés à un espace');
    sans("Une\r\n", $corps, 'aucun saut au milieu du titre');
});

test('en HTML, un titre est échappé et ne devient jamais un lien', function () {
    [$sujet, $corps] = avis_nouveaux_tomes('marco', [
        ['titre' => '<script>alert(1)</script> https://hameconnage.test/vite', 'tome' => 9],
    ]);
    $html = corps_html($corps, $sujet);
    sans('<script>', $html, 'aucune balise venue du titre');
    contient('&lt;script&gt;', $html, 'elle est échappée');
    sans('href="https://hameconnage.test', $html, 'une adresse étrangère n\'est jamais cliquable');
    contient('href="' . url_publique('index.php') . '"', $html, 'seules les adresses du site le sont');
});

test('une entrée sans titre ni tome ne casse rien', function () {
    [$sujet, $corps] = avis_nouveaux_tomes('marco', [[]]);
    egale('Un nouveau tome est disponible', $sujet, 'le sujet reste correct');
    contient('- «  » : tome 0', $corps, 'la ligne est vide plutôt que fausse');
});

test('avertir_nouveaux_tomes() n\'envoie rien sans SMTP configuré, et le dit par false', function () {
    // L'amorce vide SMTP_HOST : aucun e-mail ne peut partir pendant les tests.
    faux(avertir_nouveaux_tomes('marco@exemple.test', 'marco', [['titre' => 'Berserk', 'tome' => 44]]), 'rien n\'est parti');
    faux(avertir_nouveaux_tomes('pas-une-adresse', 'marco', [['titre' => 'Berserk', 'tome' => 44]]), 'adresse invalide');
});
