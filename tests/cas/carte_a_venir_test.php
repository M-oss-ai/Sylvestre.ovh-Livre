<?php
/* =====================================================================
   carte_html(), serie_a_venir() et serie_fin_etiquette() — le bout des tomes.

   Une série arrivée au dernier tome paru passe « En attente » (elle continue)
   ou « Terminée » (finie, ou arrêtée) — includes/nouveautes.php — et sa carte
   le dit en toutes lettres au lieu de proposer un tome « à emprunter » qui
   n'existe pas :
       « Tome N en attente »    (la série continue)
       « En pause au tome N »   (en pause chez MangaDex)
       « Se termine au tome N » (finie)
       « Arrêtée au tome N »    (arrêtée)
   C'est le STATUT et l'état de publication rangé en base (serie.publication)
   qui le disent : rien d'autre ne le déduit. Le reste des cartes ne change pas
   — c'est ce que la moitié de ces tests garde.
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

test('« En attente » : « Tome 44 en attente », et plus « à emprunter »', function () {
    $html = carte_html(serie_en_attente());
    contient('Tome 44 en attente', $html, 'l\'étiquette dit « en attente »');
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
    contient('dernier tome lu 43 (Tome 44 en attente)', $html, 'le lecteur d\'écran aussi');
    sans('Couverture du tome 44', $html, 'la couverture n\'est pas celle du tome 44 : il n\'existe pas');
});

test('une série « En cours » : la carte est INCHANGÉE', function () {
    $normale = carte_html(serie_en_attente(['statut' => 'cours', 'tome_actuel' => 42]));
    contient('Tome 43 à emprunter', $normale, 'toujours « à emprunter »');
    contient('<div class="next-tag">', $normale, 'sans la classe « à venir » (next-tag-avenir)');
    sans('en attente', $normale, 'aucune trace');
    contient('Couverture du tome 43 de Berserk', $normale, 'texte alternatif d\'origine');
    contient('<span class="badge">En cours</span>', $normale, 'sa pastille');
});

test('une ligne sans les colonnes des nouveaux tomes est rendue comme avant', function () {
    $ancienne = ['id' => 7, 'titre' => 'Berserk', 'auteur' => '', 'tome_actuel' => 3, 'statut' => 'cours', 'couverture' => ''];
    $html = carte_html($ancienne);
    contient('Tome 4 à emprunter', $html, 'à emprunter');
    sans('en attente', $html, 'sans mention d\'attente');
});

test('terminée sans état connu, ou abandonnée : pas d\'étiquette', function () {
    foreach (['termine', 'abandon'] as $statut) {
        $html = carte_html(serie_en_attente(['statut' => $statut]));
        sans('next-tag', $html, $statut . ' : aucune étiquette');
        sans('en attente', $html, $statut . ' : rien sur l\'attente');
    }
});

test('le titre reste échappé dans le texte alternatif de la couverture', function () {
    $html = carte_html(serie_en_attente(['titre' => '"><img src=x onerror=alert(1)>']));
    sans('<img src=x', $html, 'aucune balise injectée');
    contient('&lt;img src=x onerror=alert(1)&gt;', $html, 'le titre est transformé en entités');
});

test('en lecture seule (compte bloqué), l\'étiquette « en attente » se lit toujours', function () {
    $html = carte_html(serie_en_attente(), true);
    contient('Tome 44 en attente', $html, 'c\'est une information, pas une commande');
    sans('<button', $html, 'et toujours aucun bouton');
});

groupe('serie_fin_etiquette() — la mention de la série, même au tome 2');

/** Une série suivie chez MangaDex : ce qu'il en dit, et le tome qu'on en a lu. */
function serie_mangadex(string $publication, int $lu, int $dernier, int $final = 0, string $statut = 'cours'): array
{
    return serie_en_attente([
        'statut' => $statut, 'tome_actuel' => $lu, 'dernier_tome' => $dernier, 'tome_final' => $final, 'publication' => $publication,
    ]);
}

test('les quatre mentions demandées, au bout des tomes', function () {
    egale('Tome 44 en attente', serie_fin_etiquette(serie_mangadex('ongoing', 43, 43)), 'en cours de publication : le tome QUI N\'EST PAS PARU');
    egale('En pause au tome 43', serie_fin_etiquette(serie_mangadex('hiatus', 43, 43)), 'en pause chez MangaDex');
    egale('Se termine au tome 50', serie_fin_etiquette(serie_mangadex('completed', 50, 50)), 'finie');
    egale('Arrêtée au tome 43', serie_fin_etiquette(serie_mangadex('cancelled', 43, 43)), 'arrêtée');
});

test('MÊME AU TOME 2 : la mention ne dépend pas de la position du lecteur (demande de l\'utilisateur)', function () {
    egale('Tome 44 en attente', serie_fin_etiquette(serie_mangadex('ongoing', 2, 43)), 'ongoing, tome 2 sur 43');
    egale('En pause au tome 43', serie_fin_etiquette(serie_mangadex('hiatus', 2, 43)), 'hiatus, tome 2 sur 43');
    egale('Se termine au tome 50', serie_fin_etiquette(serie_mangadex('completed', 2, 50, 50)), 'completed, tome 2 sur 50');
    egale('Arrêtée au tome 43', serie_fin_etiquette(serie_mangadex('cancelled', 2, 43)), 'cancelled, tome 2 sur 43');
});

test('le dernier volume DÉCLARÉ donne le « 50 » quand les dernières couvertures manquent', function () {
    egale('Se termine au tome 50', serie_fin_etiquette(serie_mangadex('completed', 2, 45, 50)), 'couvertures jusqu\'au 45, lastVolume 50');
    egale('Se termine au tome 50', serie_fin_etiquette(serie_mangadex('completed', 2, 0, 50)), 'aucune couverture numérotée, lastVolume 50');
    egale('Arrêtée au tome 12', serie_fin_etiquette(serie_mangadex('cancelled', 2, 10, 12)), 'arrêtée : même règle');
});

test('MangaDex est en retard sur le lecteur (il a lu PLUS de tomes que MangaDex n\'en connaît) : la mention se tait', function () {
    /* HORION : lu le tome 5, MangaDex n'en connaît que 3. « Tome 4 en attente » serait faux. */
    egale('', serie_fin_etiquette(serie_mangadex('ongoing', 5, 3)), 'ongoing, tome 5 sur 3');
    egale('', serie_fin_etiquette(serie_mangadex('hiatus', 44, 43)), 'hiatus, tome 44 sur 43');
    egale('', serie_fin_etiquette(serie_mangadex('completed', 51, 45, 50)), 'completed, tome 51 sur 50');
    egale('', serie_fin_etiquette(serie_mangadex('cancelled', 13, 12)), 'cancelled, tome 13 sur 12');
    egale('Tome 4 en attente', serie_fin_etiquette(serie_mangadex('ongoing', 3, 3)), 'pile au dernier connu : la mention reste');
});

test('rien de connu : rien à dire', function () {
    egale('', serie_fin_etiquette(serie_mangadex('', 5, 43)), 'état de publication pas encore lu');
    egale('', serie_fin_etiquette(serie_mangadex('inconnu', 5, 43)), 'un état qui n\'est pas l\'un des quatre');
    egale('', serie_fin_etiquette(serie_mangadex('ongoing', 5, 0)), 'ongoing sans aucune couverture : de quel tome parler ?');
    egale('', serie_fin_etiquette(serie_mangadex('hiatus', 5, 0)), 'hiatus sans aucun tome connu');
    egale('', serie_fin_etiquette([]), 'une ligne vide');
});

test('« En attente » sans mention (à la main, ou rien de connu) : « Tome N en attente », N = le suivant du tome lu', function () {
    egale('Tome 21 en attente', serie_fin_etiquette(serie_mangadex('', 20, 0, 0, 'attente')), 'choisie à la main : rien de connu');
    egale('Tome 6 en attente', serie_fin_etiquette(serie_mangadex('ongoing', 5, 3, 0, 'attente')), 'MangaDex en retard, la personne a choisi d\'attendre');
});

test('le statut ne change pas la mention : « Terminée » ou « En cours », la série se termine au tome 50', function () {
    foreach (['cours', 'termine', 'attente', 'abandon'] as $statut) {
        egale('Se termine au tome 50', serie_fin_etiquette(serie_mangadex('completed', 50, 50, 50, $statut)), $statut);
    }
});

groupe('serie_au_bout() — a-t-on lu tout ce que MangaDex connaît ?');

test('au dernier tome connu, ou au-delà d\'un dernier volume déclaré : oui — seulement quand il y a une mention', function () {
    vrai(serie_au_bout(serie_mangadex('ongoing', 43, 43)), 'tome 43 sur 43');
    vrai(serie_au_bout(serie_mangadex('completed', 50, 45, 50)), 'tome 50 sur un dernier volume déclaré 50');
});

test('avant le bout, ou sans mention, ou en retard : non', function () {
    faux(serie_au_bout(serie_mangadex('ongoing', 2, 43)), 'tome 2 sur 43');
    faux(serie_au_bout(serie_mangadex('completed', 45, 45, 50)), 'tome 45, mais le volume final est le 50 : il reste à lire');
    faux(serie_au_bout(serie_mangadex('', 43, 43)), 'état inconnu : on ne sait pas qu\'on est au bout');
    faux(serie_au_bout(serie_mangadex('ongoing', 5, 3)), 'en retard : la mention se tait, le tome suivant est à emprunter');
});

groupe('carte_html() — les lignes du bas de la couverture');

test('au tome 2 : « Tome 3 à emprunter » ET la mention, sur deux lignes', function () {
    $html = carte_html(serie_mangadex('completed', 2, 50, 50));
    contient('<div class="next-tag next-tag-deux">', $html, 'deux lignes');
    contient('<span class="next-tag-ligne">Tome 3 à emprunter</span>', $html, 'la première : l\'action');
    contient('<span class="next-tag-ligne next-tag-serie">Se termine au tome 50</span>', $html, 'la seconde : la série');
    contient('Couverture du tome 3 de Berserk — Se termine au tome 50', $html, 'le lecteur d\'écran aussi');
});

test('au bout des tomes : une seule ligne, la mention, à la place de « à emprunter »', function () {
    $html = carte_html(serie_mangadex('ongoing', 43, 43));
    contient('<div class="next-tag next-tag-avenir">Tome 44 en attente</div>', $html, 'une ligne');
    sans('à emprunter', $html, 'plus de « à emprunter » : le suivant n\'existe pas encore');
    sans('next-tag-deux', $html, 'pas de seconde ligne');
    contient('dernier tome lu 43 (Tome 44 en attente)', $html, 'le lecteur d\'écran');
});

test('en pause ou arrêtée au bout des tomes : une ligne aussi', function () {
    contient('<div class="next-tag next-tag-avenir">En pause au tome 43</div>', carte_html(serie_mangadex('hiatus', 43, 43)), 'en pause');
    contient('<div class="next-tag next-tag-avenir">Arrêtée au tome 43</div>', carte_html(serie_mangadex('cancelled', 43, 43)), 'arrêtée');
});

test('MangaDex en retard : la carte revient à « Tome N à emprunter », sans mention', function () {
    $html = carte_html(serie_mangadex('ongoing', 5, 3));
    contient('<div class="next-tag">Tome 6 à emprunter</div>', $html, 'comme avant la fonction');
    sans('en attente', $html, 'aucune mention qui contredirait la lecture');
    sans('next-tag-deux', $html, 'une seule ligne');
});

test('sans mention connue : la carte est INCHANGÉE (une ligne, « à emprunter »)', function () {
    $html = carte_html(serie_mangadex('', 2, 43));
    contient('<div class="next-tag">Tome 3 à emprunter</div>', $html, 'comme avant');
    sans('next-tag-deux', $html, 'pas de seconde ligne');
});

test('« Terminée » : la mention seule, avec la pastille « Terminée »', function () {
    $html = carte_html(serie_mangadex('completed', 34, 34, 34, 'termine'));
    contient('<div class="next-tag next-tag-avenir">Se termine au tome 34</div>', $html, 'finie');
    contient('<span class="badge">Terminée</span>', $html, 'la pastille reste « Terminée »');
    contient('<div class="next-tag next-tag-avenir">Arrêtée au tome 12</div>', carte_html(serie_mangadex('cancelled', 12, 12, 0, 'termine')), 'arrêtée');
});

test('un état inventé ne devient jamais du HTML (et ne dit rien)', function () {
    $html = carte_html(serie_mangadex('<script>alert(1)</script>', 2, 43));
    sans('<script>', $html, 'aucune balise injectée');
    contient('<div class="next-tag">Tome 3 à emprunter</div>', $html, 'et la carte reste celle d\'avant');
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
