<?php
/* =====================================================================
   carte_html() et serie_a_venir() — le statut « En attente ».

   Une série arrivée au dernier tome paru d'une série qui continue passe
   « En attente » (includes/nouveautes.php), et sa carte dit « Tome N pas
   encore paru » au lieu de proposer un tome « à emprunter » qui n'existe
   pas. C'est le STATUT qui le dit : rien d'autre ne le déduit. Le reste des
   cartes ne change pas — c'est ce que la moitié de ces tests garde.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

const LIEN = '801513ba-a712-498c-8f57-cae55b38cc92';

/** Une série « En attente » (tome 43 sur 43, suivie chez MangaDex), dont on ne change que ce que le test regarde. */
function serie_en_attente(array $modifications = []): array
{
    return $modifications + [
        'id'           => 7,
        'titre'        => 'Berserk',
        'auteur'       => 'Kentaro Miura',
        'tome_actuel'  => 43,
        'statut'       => 'attente',
        'couverture'   => 'https://uploads.mangadex.org/covers/' . LIEN . '/x.jpg.512.jpg',
        'mangadex_id'  => LIEN,
        'dernier_tome' => 43,
        'nouveau_tome' => 0,
    ];
}

groupe('serie_a_venir() — le statut « En attente », et rien d\'autre');

test('« En attente » : le tome suivant n\'existe pas encore', function () {
    vrai(serie_a_venir(serie_en_attente()), 'en attente');
    vrai(serie_a_venir(['statut' => 'attente']), 'le statut suffit');
});

test('tous les autres statuts : non', function () {
    foreach (['cours', 'envie', 'termine', 'abandon', '', 'inconnu', 'ATTENTE'] as $statut) {
        faux(serie_a_venir(serie_en_attente(['statut' => $statut])), var_export($statut, true));
    }
});

test('une série « En cours » qui serait au bout sans qu\'on le sache n\'est PAS dite en attente', function () {
    /* On ne prétend rien qu'on ne sache : tant que le statut n'est pas posé
       (par « → » ou par le relevé), la carte garde son libellé habituel. */
    faux(serie_a_venir(serie_en_attente(['statut' => 'cours'])), 'en cours, tome lu = dernier tome connu');
    faux(serie_a_venir(serie_en_attente(['statut' => 'cours', 'dernier_tome' => 0])), 'en cours, dernier tome inconnu');
});

test('une ligne sans statut ni les colonnes des nouveaux tomes : non', function () {
    faux(serie_a_venir([]), 'aucune clé');
    faux(serie_a_venir(['tome_actuel' => 3]), 'sans statut');
});

groupe('carte_html() — l\'étiquette, la pastille et la couverture');

test('« En attente » : « Tome 44 pas encore paru », et plus « à emprunter »', function () {
    $html = carte_html(serie_en_attente());
    contient('Tome 44 pas encore paru', $html, 'l\'étiquette dit « pas encore paru »');
    contient('next-tag next-tag-avenir', $html, 'avec sa classe, pour le style en retrait');
    sans('à emprunter', $html, 'plus de « à emprunter »');
});

test('la pastille et le cadre portent le statut', function () {
    $html = carte_html(serie_en_attente());
    contient('<span class="badge">En attente</span>', $html, 'la pastille dit « En attente »');
    contient('class="card status-attente"', $html, 'la classe de statut, pour la couleur');
    contient('data-statut="attente"', $html, 'et le filtre lit le statut dans un attribut data-');
});

test('le texte alternatif de la couverture dit la même chose', function () {
    $html = carte_html(serie_en_attente());
    contient('dernier tome lu 43, tome 44 pas encore paru', $html, 'le lecteur d\'écran aussi');
    sans('Couverture du tome 44', $html, 'la couverture n\'est pas celle du tome 44 : il n\'existe pas');
});

test('une série « En cours » : la carte est INCHANGÉE', function () {
    $normale = carte_html(serie_en_attente(['statut' => 'cours', 'tome_actuel' => 42]));
    contient('Tome 43 à emprunter', $normale, 'toujours « à emprunter »');
    contient('<div class="next-tag">', $normale, 'sans la classe « à venir » (next-tag-avenir)');
    sans('pas encore paru', $normale, 'aucune trace');
    contient('Couverture du tome 43 de Berserk', $normale, 'texte alternatif d\'origine');
    contient('<span class="badge">En cours</span>', $normale, 'sa pastille');
});

test('une ligne sans les colonnes des nouveaux tomes est rendue comme avant', function () {
    $ancienne = ['id' => 7, 'titre' => 'Berserk', 'auteur' => '', 'tome_actuel' => 3, 'statut' => 'cours', 'couverture' => ''];
    $html = carte_html($ancienne);
    contient('Tome 4 à emprunter', $html, 'à emprunter');
    sans('pas encore paru', $html, 'sans « pas encore paru »');
});

test('terminée ou abandonnée : pas d\'étiquette', function () {
    foreach (['termine', 'abandon'] as $statut) {
        $html = carte_html(serie_en_attente(['statut' => $statut]));
        sans('next-tag', $html, $statut . ' : aucune étiquette');
        sans('pas encore paru', $html, $statut . ' : pas de « pas encore paru »');
    }
});

test('le titre reste échappé dans le texte alternatif de la couverture', function () {
    $html = carte_html(serie_en_attente(['titre' => '"><img src=x onerror=alert(1)>']));
    sans('<img src=x', $html, 'aucune balise injectée');
    contient('&lt;img src=x onerror=alert(1)&gt;', $html, 'le titre est transformé en entités');
});

test('en lecture seule (compte bloqué), l\'étiquette « pas encore paru » se lit toujours', function () {
    $html = carte_html(serie_en_attente(), true);
    contient('Tome 44 pas encore paru', $html, 'c\'est une information, pas une commande');
    sans('<button', $html, 'et toujours aucun bouton');
});

groupe('compter_lignes() — le compteur du filtre « En attente »');

test('chaque statut a son compteur, « En attente » compris', function () {
    $c = compter_lignes([
        ['statut' => 'cours', 'favori' => 0, 'couverture' => ''],
        ['statut' => 'attente', 'favori' => 0, 'couverture' => ''],
        ['statut' => 'attente', 'favori' => 1, 'couverture' => ''],
        ['statut' => 'termine', 'favori' => 0, 'couverture' => ''],
    ]);
    egale(4, $c['all'], 'toutes');
    egale(1, $c['cours'], 'en cours');
    egale(2, $c['attente'], 'en attente');
    egale(1, $c['termine'], 'terminées');
    egale(0, $c['envie'], 'envie : 0, la clé existe');
    egale(0, $c['abandon'], 'abandon : 0, la clé existe');
    egale(1, $c['favori'], 'les favoris traversent les statuts');
});

test('aucune série : tous les compteurs à zéro, une clé par statut', function () {
    $c = compter_lignes([]);
    foreach (array_keys(STATUTS) as $statut) {
        egale(0, $c[$statut], 'compteur « ' . $statut . ' »');
    }
    egale(0, $c['all'], 'toutes');
});

test('un statut inconnu compte dans « toutes » et dans aucun filtre de statut', function () {
    $c = compter_lignes([['statut' => '', 'favori' => 0, 'couverture' => '']]);
    egale(1, $c['all'], 'toutes');
    egale(0, array_sum(array_intersect_key($c, STATUTS)), 'aucun statut');
});

groupe('Le filtre, la couleur et les pages');

test('index.php : un bouton de filtre par statut, lu dans STATUTS (donc « En attente » aussi)', function () {
    $page = (string) file_get_contents(CHEMIN_SITE . '/index.php');
    contient('foreach (STATUTS as $cle => $libelle)', $page, 'la page parcourt STATUTS');
    contient('id="count-<?= e($cle) ?>"', $page, 'avec son compteur count-<statut>');
});

test('style.css : le statut « En attente » a sa couleur partout où les autres ont la leur', function () {
    $css = (string) file_get_contents(CHEMIN_SITE . '/css/style.css');
    foreach (['--attente:', '--attente-bg:', '.filter-btn.status-attente.active', '.card.status-attente', '.status-attente .badge'] as $regle) {
        contient($regle, $css, $regle);
    }
    egale(substr_count($css, '.card.status-cours'), substr_count($css, '.card.status-attente'),
        'le cadre : autant de règles que « En cours » (haut, et côté gauche sur téléphone)');
});

test('la fiche propose le statut : le menu parcourt STATUTS', function () {
    $page = (string) file_get_contents(CHEMIN_SITE . '/index.php');
    $debut = (int) strpos($page, 'id="f-status"');
    contient('foreach (STATUTS as $cle => $libelle)', substr($page, $debut, 200), 'le menu de la fiche');
});
