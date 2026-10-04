<?php
/* =====================================================================
   carte_html() et serie_a_venir() — « Tome N pas encore paru ».

   Quand le tome lu est le dernier que MangaDex connaît d'une série qui
   continue, le tome suivant n'existe pas encore : la carte le dit au lieu
   de proposer un tome « à emprunter ». Rien n'y change pour une série qui
   n'est pas dans ce cas — c'est ce que la moitié de ces tests garde.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

const LIEN = '801513ba-a712-498c-8f57-cae55b38cc92';

/** Une série à jour (tome 43 sur 43, suivie chez MangaDex), dont on ne change que ce que le test regarde. */
function serie_a_jour(array $modifications = []): array
{
    return $modifications + [
        'id'           => 7,
        'titre'        => 'Berserk',
        'auteur'       => 'Kentaro Miura',
        'tome_actuel'  => 43,
        'statut'       => 'cours',
        'couverture'   => 'https://uploads.mangadex.org/covers/' . LIEN . '/x.jpg.512.jpg',
        'mangadex_id'  => LIEN,
        'dernier_tome' => 43,
        'nouveau_tome' => 0,
    ];
}

groupe('serie_a_venir() — quand le tome suivant n\'existe pas encore');

test('en cours, liée, tome lu = dernier tome connu : à jour', function () {
    vrai(serie_a_venir(serie_a_jour()), 'à jour');
    vrai(serie_a_venir(serie_a_jour(['tome_actuel' => 50])), 'tome lu au-delà du dernier connu : on n\'a rien de plus à lui proposer');
});

test('des tomes restent à lire : pas à jour', function () {
    faux(serie_a_venir(serie_a_jour(['tome_actuel' => 42])), 'un tome de retard');
    faux(serie_a_venir(serie_a_jour(['tome_actuel' => 0])), 'série non commencée');
});

test('dernier tome inconnu : on ne prétend rien', function () {
    // 0 = MangaDex n'a pas été interrogé (série d'avant la fonction, ou panne).
    faux(serie_a_venir(serie_a_jour(['dernier_tome' => 0])), 'dernier_tome à 0');
    $sans = serie_a_jour();
    unset($sans['dernier_tome']);
    faux(serie_a_venir($sans), 'colonne absente de la ligne (lignes d\'avant la migration, autres appelants)');
});

test('pas liée à MangaDex : jamais « pas encore paru »', function () {
    faux(serie_a_venir(serie_a_jour(['mangadex_id' => ''])), 'lien vide');
    $sans = serie_a_jour();
    unset($sans['mangadex_id']);
    faux(serie_a_venir($sans), 'lien absent');
});

test('seule une série « En cours » attend un tome', function () {
    foreach (['termine', 'abandon', 'envie'] as $statut) {
        faux(serie_a_venir(serie_a_jour(['statut' => $statut])), $statut);
    }
    faux(serie_a_venir(serie_a_jour(['statut' => ''])), 'statut vide');
});

groupe('carte_html() — l\'étiquette et la couverture');

test('à jour : « Tome 44 pas encore paru », et plus « à emprunter »', function () {
    $html = carte_html(serie_a_jour());
    contient('Tome 44 pas encore paru', $html, 'l\'étiquette dit « pas encore paru »');
    contient('next-tag next-tag-avenir', $html, 'avec sa classe, pour le style en retrait');
    sans('à emprunter', $html, 'plus de « à emprunter »');
});

test('à jour : le texte alternatif de la couverture dit la même chose', function () {
    $html = carte_html(serie_a_jour());
    contient('dernier tome lu 43, tome 44 pas encore paru', $html, 'le lecteur d\'écran aussi');
    sans('Couverture du tome 44', $html, 'la couverture n\'est pas celle du tome 44 : il n\'existe pas');
});

test('pas à jour : la carte est INCHANGÉE', function () {
    $normale = carte_html(serie_a_jour(['tome_actuel' => 42]));
    contient('Tome 43 à emprunter', $normale, 'toujours « à emprunter »');
    contient('<div class="next-tag">', $normale, 'sans la classe « à venir » (next-tag-avenir)');
    sans('pas encore paru', $normale, 'aucune trace');
    contient('Couverture du tome 43 de Berserk', $normale, 'texte alternatif d\'origine');
});

test('une ligne sans les colonnes des nouveaux tomes est rendue comme avant', function () {
    // Les appelants qui n'ont pas encore ces colonnes (ou des tests anciens) ne doivent rien voir changer.
    $ancienne = ['id' => 7, 'titre' => 'Berserk', 'auteur' => '', 'tome_actuel' => 3, 'statut' => 'cours', 'couverture' => ''];
    $html = carte_html($ancienne);
    contient('Tome 4 à emprunter', $html, 'à emprunter');
    sans('pas encore paru', $html, 'sans « pas encore paru »');
});

test('terminée ou abandonnée : pas d\'étiquette, même avec un dernier tome connu', function () {
    foreach (['termine', 'abandon'] as $statut) {
        $html = carte_html(serie_a_jour(['statut' => $statut]));
        sans('next-tag', $html, $statut . ' : aucune étiquette');
        sans('pas encore paru', $html, $statut . ' : pas de « pas encore paru »');
    }
});

test('le titre reste échappé dans le texte alternatif de la couverture', function () {
    $html = carte_html(serie_a_jour(['titre' => '"><img src=x onerror=alert(1)>']));
    sans('<img src=x', $html, 'aucune balise injectée');
    contient('&lt;img src=x onerror=alert(1)&gt;', $html, 'le titre est transformé en entités');
});

test('en lecture seule (compte bloqué), l\'étiquette « pas encore paru » se lit toujours', function () {
    $html = carte_html(serie_a_jour(), true);
    contient('Tome 44 pas encore paru', $html, 'c\'est une information, pas une commande');
    sans('<button', $html, 'et toujours aucun bouton');
});

test('les attributs data- ne changent pas : le filtre lit un statut, jamais une étiquette', function () {
    $html = carte_html(serie_a_jour());
    contient('data-statut="cours"', $html, 'le statut reste « cours »');
    contient('data-tome="43"', $html, 'le tome lu');
    sans('data-avenir', $html, 'aucun nouvel attribut : le script n\'a rien à y réinterpréter');
});
