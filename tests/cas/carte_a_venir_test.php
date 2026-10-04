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

groupe('carte_html() — la pastille, la couverture et la mention dans le corps de la carte');

test('« En attente » : la mention « Tome 44 en attente » est dans le corps, la couverture ne porte plus rien', function () {
    $html = carte_html(serie_en_attente());
    contient('<span class="card-fin-texte">Tome 44 en attente</span>', $html, 'la mention, dans le corps');
    sans('à emprunter', $html, 'plus de « à emprunter »');
    sans('class="next-tag', $html, 'et plus rien sur la couverture : le dernier tome paru est lu');
});

test('la mention est ENTRE « Vous avez lu le tome x » et les boutons (demande de l\'utilisateur)', function () {
    $html = carte_html(serie_en_attente());
    $progression = strpos($html, 'class="card-progress"');
    $mention     = strpos($html, 'class="card-fin"');
    $boutons     = strpos($html, 'class="card-actions"');
    vrai($progression !== false && $mention !== false && $boutons !== false, 'les trois sont là');
    vrai($progression < $mention && $mention < $boutons, 'dans cet ordre : progression, mention, boutons');
});

test('la pastille et le cadre portent le statut', function () {
    $html = carte_html(serie_en_attente());
    contient('<span class="badge">En attente</span>', $html, 'la pastille dit « En attente »');
    contient('class="card status-attente"', $html, 'la classe de statut, pour la couleur');
    contient('data-statut="attente"', $html, 'et le filtre lit le statut dans un attribut data-');
});

test('le texte alternatif de la couverture : le dernier tome lu, pas un tome qui n\'existe pas', function () {
    $html = carte_html(serie_en_attente());
    contient('Couverture de Berserk — dernier tome lu 43', $html, 'le lecteur d\'écran');
    sans('Couverture du tome 44', $html, 'la couverture n\'est pas celle du tome 44 : il n\'existe pas');
});

test('une série « En cours » sans rien de connu : la carte est INCHANGÉE', function () {
    $normale = carte_html(serie_en_attente(['statut' => 'cours', 'tome_actuel' => 42, 'publication' => '']));
    contient('<div class="next-tag">Tome 43 à emprunter</div>', $normale, 'toujours « à emprunter »');
    sans('card-fin', $normale, 'aucune mention : on ne sait rien');
    contient('Couverture du tome 43 de Berserk', $normale, 'texte alternatif d\'origine');
    contient('<span class="badge">En cours</span>', $normale, 'sa pastille');
});

test('une ligne sans les colonnes des nouveaux tomes est rendue comme avant', function () {
    $ancienne = ['id' => 7, 'titre' => 'Berserk', 'auteur' => '', 'tome_actuel' => 3, 'statut' => 'cours', 'couverture' => ''];
    $html = carte_html($ancienne);
    contient('Tome 4 à emprunter', $html, 'à emprunter');
    sans('card-fin', $html, 'sans mention');
});

test('terminée sans état connu, ou abandonnée : ni étiquette ni mention', function () {
    foreach (['termine', 'abandon'] as $statut) {
        $html = carte_html(serie_en_attente(['statut' => $statut]));
        sans('next-tag', $html, $statut . ' : rien sur la couverture');
        sans('card-fin', $html, $statut . ' : aucune mention, l\'état n\'est pas connu');
    }
});

test('le titre reste échappé dans le texte alternatif de la couverture', function () {
    $html = carte_html(serie_en_attente(['titre' => '"><img src=x onerror=alert(1)>']));
    sans('<img src=x', $html, 'aucune balise injectée');
    contient('&lt;img src=x onerror=alert(1)&gt;', $html, 'le titre est transformé en entités');
});

test('en lecture seule (compte bloqué), la mention se lit toujours', function () {
    $html = carte_html(serie_en_attente(), true);
    contient('<span class="card-fin-texte">Tome 44 en attente</span>', $html, 'c\'est une information, pas une commande');
    sans('data-action', $html, 'aucune commande pour le script : ni avancer, ni reculer, ni modifier');
    sans('role="button"', $html, 'la couverture n\'est pas un bouton');
    sans('card-info', $html, '« Tome 44 en attente » a un numéro : il se comprend seul, pas de « ⓘ »');
});

test('en lecture seule (compte bloqué), le « ⓘ » d\'une mention sans numéro reste : il ne change rien, il explique', function () {
    $html = carte_html(serie_en_attente(['statut' => 'cours', 'publication' => 'ongoing', 'dernier_tome' => 0, 'tome_final' => 0]), true);
    contient('class="card-info"', $html, 'le « ⓘ »');
    sans('data-action', $html, 'et toujours aucune commande');
});
groupe('serie_fin_etiquette() — la mention de la série liée à MangaDex, même au tome 2');

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

test('TOUTE série liée a son message, quel que soit son statut (demande de l\'utilisateur)', function () {
    foreach (['cours', 'attente', 'envie', 'termine', 'abandon'] as $statut) {
        egale('Se termine au tome 50', serie_fin_etiquette(serie_mangadex('completed', 2, 50, 50, $statut)), $statut . ' : finie');
        egale('Arrêtée au tome 43', serie_fin_etiquette(serie_mangadex('cancelled', 2, 43, 0, $statut)), $statut . ' : arrêtée');
        egale('En pause au tome 43', serie_fin_etiquette(serie_mangadex('hiatus', 2, 43, 0, $statut)), $statut . ' : en pause');
    }
    foreach (['cours', 'envie', 'termine', 'abandon'] as $statut) {
        egale('Tome 44 en attente', serie_fin_etiquette(serie_mangadex('ongoing', 2, 43, 0, $statut)), $statut . ' : en cours de publication');
    }
});

test('MangaDex est en retard sur le lecteur (il a lu PLUS de tomes que MangaDex n\'en connaît) : « MangaDex s\'arrête au tome X »', function () {
    /* HORION : lu le tome 5, MangaDex n'en connaît que 3. « Tome 4 en attente » serait faux. */
    egale('MangaDex s\'arrête au tome 3', serie_fin_etiquette(serie_mangadex('ongoing', 5, 3)), 'ongoing, tome 5 sur 3');
    egale('MangaDex s\'arrête au tome 43', serie_fin_etiquette(serie_mangadex('hiatus', 44, 43)), 'hiatus, tome 44 sur 43');
    egale('MangaDex s\'arrête au tome 50', serie_fin_etiquette(serie_mangadex('completed', 51, 45, 50)), 'completed, tome 51 sur 50');
    egale('MangaDex s\'arrête au tome 12', serie_fin_etiquette(serie_mangadex('cancelled', 13, 12)), 'cancelled, tome 13 sur 12');
    egale('MangaDex s\'arrête au tome 3', serie_fin_etiquette(serie_mangadex('', 5, 3)), 'même sans l\'état de publication : les couvertures suffisent');
    egale('Tome 4 en attente', serie_fin_etiquette(serie_mangadex('ongoing', 3, 3)), 'pile au dernier connu : la mention d\'état reste');
});

test('rien de connu : rien à dire', function () {
    egale('', serie_fin_etiquette(serie_mangadex('', 5, 0)), 'ni état de publication ni couverture : pas encore lue');
    egale('', serie_fin_etiquette(serie_mangadex('', 2, 43)), 'état pas encore lu, lecteur avant le bout');
    egale('', serie_fin_etiquette(serie_mangadex('inconnu', 2, 43)), 'un état qui n\'est pas l\'un des quatre');
    egale('', serie_fin_etiquette([]), 'une ligne vide');
});

test('l\'état est lu mais on ne connaît aucun tome : l\'état sans numéro, jamais une affirmation fausse', function () {
    egale('En cours de publication', serie_fin_etiquette(serie_mangadex('ongoing', 2, 0)), 'ongoing, aucune couverture connue');
    egale('En pause', serie_fin_etiquette(serie_mangadex('hiatus', 0, 0)), 'hiatus');
    egale('Série terminée', serie_fin_etiquette(serie_mangadex('completed', 2, 0)), 'completed, ni couverture ni dernier volume');
    egale('Série arrêtée', serie_fin_etiquette(serie_mangadex('cancelled', 2, 0)), 'cancelled');
});

test('« En attente » sans mention d\'état (à la main, ou pas encore lu) : « Tome N en attente », N = le suivant du tome lu', function () {
    egale('Tome 21 en attente', serie_fin_etiquette(serie_mangadex('', 20, 0, 0, 'attente')), 'choisie à la main : rien de connu');
    egale('Tome 6 en attente', serie_fin_etiquette(serie_mangadex('ongoing', 5, 3, 0, 'attente')), 'MangaDex en retard, la personne a choisi d\'attendre : son choix prime');
});

test('le statut ne change pas la mention : « Terminée » ou « En cours », la série se termine au tome 50', function () {
    foreach (['cours', 'termine', 'attente', 'abandon', 'envie'] as $statut) {
        egale('Se termine au tome 50', serie_fin_etiquette(serie_mangadex('completed', 50, 50, 50, $statut)), $statut);
    }
});

groupe('serie_au_bout() — a-t-on lu exactement tout ce que MangaDex connaît ?');

test('au dernier tome connu, ou au dernier volume déclaré : oui', function () {
    vrai(serie_au_bout(serie_mangadex('ongoing', 43, 43)), 'tome 43 sur 43');
    vrai(serie_au_bout(serie_mangadex('completed', 50, 45, 50)), 'tome 50 sur un dernier volume déclaré 50');
});

test('avant le bout, au-delà, ou sans état connu : non', function () {
    faux(serie_au_bout(serie_mangadex('ongoing', 2, 43)), 'tome 2 sur 43');
    faux(serie_au_bout(serie_mangadex('completed', 45, 45, 50)), 'tome 45, mais le volume final est le 50 : il reste à lire');
    faux(serie_au_bout(serie_mangadex('', 43, 43)), 'état inconnu : on ne sait pas qu\'on est au bout');
    faux(serie_au_bout(serie_mangadex('ongoing', 5, 3)), 'en retard : le tome suivant est à emprunter');
});

groupe('carte_html() — le bas de la couverture, et le message dans le corps');

test('au tome 2 : la couverture dit « Tome 3 à emprunter », le corps dit la mention', function () {
    $html = carte_html(serie_mangadex('completed', 2, 50, 50));
    contient('<div class="next-tag">Tome 3 à emprunter</div>', $html, 'la couverture : l\'action, une seule ligne');
    contient('<span class="card-fin-texte">Se termine au tome 50</span>', $html, 'le corps : la série');
    contient('Couverture du tome 3 de Berserk', $html, 'le lecteur d\'écran');
});

test('au bout des tomes : la couverture ne porte plus « à emprunter », le corps dit la mention', function () {
    $html = carte_html(serie_mangadex('ongoing', 43, 43));
    contient('<span class="card-fin-texte">Tome 44 en attente</span>', $html, 'la mention');
    sans('à emprunter', $html, 'plus de « à emprunter » : le suivant n\'existe pas encore');
    sans('class="next-tag', $html, 'rien sur la couverture');
});

test('en pause ou arrêtée au bout des tomes : même chose', function () {
    contient('<span class="card-fin-texte">En pause au tome 43</span>', carte_html(serie_mangadex('hiatus', 43, 43)), 'en pause');
    contient('<span class="card-fin-texte">Arrêtée au tome 43</span>', carte_html(serie_mangadex('cancelled', 43, 43)), 'arrêtée');
});

test('MangaDex en retard : la couverture garde « Tome N à emprunter », le corps dit où MangaDex s\'arrête', function () {
    $html = carte_html(serie_mangadex('ongoing', 5, 3));
    contient('<div class="next-tag">Tome 6 à emprunter</div>', $html, 'la couverture comme avant la fonction');
    contient('<span class="card-fin-texte">MangaDex s&#039;arrête au tome 3</span>', $html, 'et le corps explique (l\'apostrophe est échappée en HTML)');
    sans('en attente', $html, 'rien qui contredise la lecture');
});

test('sans rien de connu : la carte est INCHANGÉE (une ligne sur la couverture, pas de mention)', function () {
    $html = carte_html(serie_mangadex('', 2, 0));
    contient('<div class="next-tag">Tome 3 à emprunter</div>', $html, 'comme avant');
    sans('card-fin', $html, 'pas de mention');
});

test('« Terminée » : la mention dans le corps, la pastille « Terminée », rien sur la couverture', function () {
    $html = carte_html(serie_mangadex('completed', 34, 34, 34, 'termine'));
    contient('<span class="card-fin-texte">Se termine au tome 34</span>', $html, 'finie');
    contient('<span class="badge">Terminée</span>', $html, 'la pastille reste « Terminée »');
    sans('class="next-tag', $html, 'la couverture ne dit rien');
    contient('<span class="card-fin-texte">Arrêtée au tome 12</span>', carte_html(serie_mangadex('cancelled', 12, 12, 0, 'termine')), 'arrêtée');
});

test('un état inventé ne devient jamais du HTML (et ne dit rien)', function () {
    $html = carte_html(serie_mangadex('<script>alert(1)</script>', 2, 43));
    sans('<script>', $html, 'aucune balise injectée');
    sans('card-fin', $html, 'et ne dit rien');
    contient('<div class="next-tag">Tome 3 à emprunter</div>', $html, 'la carte reste celle d\'avant');
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

groupe('serie_fin_info() — l\'explication du « ⓘ »');

test('SEULES les mentions sans numéro de tome ont une explication (demande de l\'utilisateur)', function () {
    $sans_numero = [
        'ongoing sans tome'   => serie_mangadex('ongoing', 2, 0),
        'hiatus sans tome'    => serie_mangadex('hiatus', 2, 0),
        'completed sans tome' => serie_mangadex('completed', 2, 0),
        'cancelled sans tome' => serie_mangadex('cancelled', 2, 0),
    ];
    foreach ($sans_numero as $nom => $serie) {
        $i = serie_fin_info($serie);
        vrai($i['texte'] !== '', $nom . ' : une mention');
        vrai(mb_strlen($i['aide'], 'UTF-8') >= 30, $nom . ' : une explication, pas un mot');
        contient('MangaDex indique', $i['aide'], $nom . ' : on dit QUI parle');
        egale($i['texte'], serie_fin_etiquette($serie), $nom . ' : serie_fin_etiquette() est le texte de serie_fin_info()');
    }
    $avec_numero = [
        'ongoing, au bout'     => serie_mangadex('ongoing', 43, 43),
        'ongoing, tome 2'      => serie_mangadex('ongoing', 2, 43),
        'hiatus'               => serie_mangadex('hiatus', 2, 43),
        'completed'            => serie_mangadex('completed', 2, 50, 50),
        'cancelled'            => serie_mangadex('cancelled', 2, 43),
        'en retard'            => serie_mangadex('ongoing', 5, 3),
        'en attente à la main' => serie_mangadex('', 20, 0, 0, 'attente'),
    ];
    foreach ($avec_numero as $nom => $serie) {
        $i = serie_fin_info($serie);
        vrai($i['texte'] !== '', $nom . ' : une mention');
        egale('', $i['aide'], $nom . ' : un numéro de tome se comprend seul, pas d\'explication');
        egale($i['texte'], serie_fin_etiquette($serie), $nom . ' : serie_fin_etiquette() est le texte de serie_fin_info()');
    }
    egale(['texte' => '', 'aide' => ''], serie_fin_info(serie_mangadex('', 2, 43)), 'rien de connu : ni mention ni explication');
    egale(['texte' => '', 'aide' => ''], serie_fin_info([]), 'une ligne vide');
});

test('une aide n\'existe jamais sans mention, et une mention porte son numéro OU son aide', function () {
    foreach (['', 'ongoing', 'hiatus', 'completed', 'cancelled'] as $pub) {
        foreach ([[2, 0, 0], [2, 43, 0], [43, 43, 0], [5, 3, 0], [2, 45, 50], [1, 1, 1]] as [$lu, $dernier, $final]) {
            foreach (['cours', 'attente', 'termine', 'abandon', 'envie'] as $statut) {
                $i = serie_fin_info(serie_mangadex($pub, $lu, $dernier, $final, $statut));
                $nom = "$pub $lu/$dernier/$final $statut";
                if ($i['texte'] === '') {
                    egale('', $i['aide'], $nom . ' : pas de mention, pas d\'aide');
                } else {
                    vrai(preg_match('/\d/', $i['texte']) === 1 xor $i['aide'] !== '', $nom . ' : « ' . $i['texte'] . ' » a un numéro ou une aide, pas les deux, pas aucun');
                }
            }
        }
    }
});

test('« Série terminée » est expliquée par rapport au statut « Terminée » de la pastille (la confusion qu\'on a eue)', function () {
    $aide = serie_fin_info(serie_mangadex('completed', 2, 0))['aide'];
    contient('statut « Terminée »', $aide, 'on nomme l\'autre');
    contient('le vôtre', $aide, 'et on dit lequel est à qui');
});

test('« En cours de publication » dit pourquoi il n\'y a pas de numéro', function () {
    $i = serie_fin_info(serie_mangadex('ongoing', 2, 0));
    egale('En cours de publication', $i['texte'], 'la mention');
    contient('aucun tome numéroté', $i['aide'], 'la raison');
});

groupe('carte_html() — le « ⓘ »');

test('un bouton accessible, avec son explication cachée juste dessous', function () {
    $html = carte_html(serie_mangadex('completed', 2, 0));
    contient('<span class="card-fin-texte">Série terminée</span><button type="button" class="card-info" tabindex="-1" aria-expanded="false" aria-controls="aide-fin-7"', $html,
        'un <button> à côté de la mention, replié, relié à son explication (tabindex -1 : la carte active le rend)');
    contient('aria-label="Que veut dire « Série terminée » ?"', $html, 'le lecteur d\'écran sait de quoi il s\'agit');
    contient('title="Que veut dire ce message ?"', $html, 'et la souris aussi');
    contient('>ⓘ</button>', $html, 'le signe');
    contient('<p class="card-fin-aide hidden" id="aide-fin-7" role="note">MangaDex indique que la publication de la série est terminée', $html, 'l\'explication, cachée au départ');
    vrai(strpos($html, 'class="card-fin"') < strpos($html, 'class="card-fin-aide'), 'sous la mention');
    vrai(strpos($html, 'class="card-fin-aide') < strpos($html, 'class="card-actions"'), 'et avant les boutons');
});

test('les quatre mentions sans numéro ont leur « ⓘ »', function () {
    foreach (['ongoing' => 'En cours de publication', 'hiatus' => 'En pause', 'completed' => 'Série terminée', 'cancelled' => 'Série arrêtée'] as $etat => $texte) {
        $html = carte_html(serie_mangadex($etat, 2, 0));
        contient('<span class="card-fin-texte">' . $texte . '</span>', $html, $etat . ' : la mention');
        contient('class="card-info"', $html, $etat . ' : son « ⓘ »');
        contient('class="card-fin-aide hidden"', $html, $etat . ' : son explication');
    }
});

test('une mention avec un numéro de tome n\'a PAS de « ⓘ » (demande de l\'utilisateur)', function () {
    $cas = [
        'Tome 44 en attente'    => serie_mangadex('ongoing', 2, 43),
        'En pause au tome 43'   => serie_mangadex('hiatus', 2, 43),
        'Se termine au tome 50' => serie_mangadex('completed', 2, 50, 50),
        'Arrêtée au tome 43'    => serie_mangadex('cancelled', 2, 43),
        "MangaDex s'arrête au tome 3" => serie_mangadex('ongoing', 5, 3),
        'Tome 21 en attente'    => serie_mangadex('', 20, 0, 0, 'attente'),
    ];
    foreach ($cas as $mention => $serie) {
        $html = carte_html($serie);
        contient('<span class="card-fin-texte">' . e($mention) . '</span></p>', $html, $mention . ' : la mention, seule');
        sans('card-info', $html, $mention . ' : pas de bouton');
        sans('card-fin-aide', $html, $mention . ' : pas d\'explication cachée');
        sans('aria-controls', $html, $mention . ' : rien à relier');
    }
});

test('pas de mention, pas de « ⓘ »', function () {
    $html = carte_html(serie_mangadex('', 2, 0));
    sans('card-info', $html, 'rien à expliquer');
    sans('card-fin-aide', $html, 'et aucune explication vide');
});

test('l\'identifiant de l\'explication est celui de la série : deux cartes ne se confondent pas', function () {
    $a = carte_html(serie_mangadex('hiatus', 2, 0) + ['id' => 7]);
    $b = carte_html(['id' => 8] + serie_mangadex('hiatus', 2, 0));
    contient('id="aide-fin-7"', $a, 'série 7');
    contient('id="aide-fin-8"', $b, 'série 8');
});

test('tout est échappé : un titre ou une explication ne devient jamais du HTML', function () {
    $html = carte_html(serie_mangadex('completed', 2, 0) + ['titre' => '"><script>alert(1)</script>']);
    sans('<script>', $html, 'aucune balise injectée');
    contient('class="card-info"', $html, 'et le « ⓘ » est bien là : le test porte sur une carte qui en a un');
});

test('js/app.js : le « ⓘ » plie et déplie, sans rien envoyer au serveur ni sélectionner la carte', function () {
    $js = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/js/app.js'));
    contient('const info = e.target.closest(".card-info");', $js, 'l\'écouteur du « ⓘ »');
    contient('info.setAttribute("aria-expanded", ouvre ? "true" : "false");', $js, 'aria-expanded suit');
    contient('aide.classList.toggle("hidden", !ouvre);', $js, 'l\'explication se plie et se déplie');
    contient('e.target.closest("[data-action], .card-info")', $js, 'le clic ne sélectionne pas la carte');
    contient('const FOCUSABLES_CARTE = ".card-cover, .card-actions button, .card-info";', $js,
        'la carte active rend son « ⓘ » à la tabulation : un seul arrêt par carte, plus celui-là');
    $bloc = substr($js, (int) strpos($js, 'const info = e.target.closest(".card-info");'), 500);
    sans('L.api(', $bloc, 'aucun appel serveur : ce n\'est pas une commande');
    sans('BLOQUE', $bloc, 'et un compte bloqué s\'en sert aussi');
});

test('style.css : le « ⓘ » et son explication ont leur style', function () {
    $css = str_replace("\r\n", "\n", (string) file_get_contents(CHEMIN_SITE . '/css/style.css'));
    foreach (['.card-info {', '.card-info[aria-expanded="true"]', '.card-fin-aide {', '.card-fin-texte'] as $regle) {
        contient($regle, $css, $regle);
    }
});