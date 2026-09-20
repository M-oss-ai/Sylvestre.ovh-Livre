<?php
/* =====================================================================
   carte_html()

   C est le SEUL endroit du projet où le HTML d une carte est écrit :
   index.php le sert au chargement, api.php le renvoie après chaque
   action AJAX. Côté navigateur, c est aussi le seul HTML inséré en
   innerHTML — tout ce qui en sort doit donc être irréprochable.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

/** Une série complète, dont on ne change que ce que le test regarde. */
function serie(array $modifications = []): array
{
    return $modifications + [
        'id'          => 7,
        'titre'       => 'Fullmetal Alchemist',
        'auteur'      => 'Hiromu Arakawa',
        'tome_actuel' => 3,
        'statut'      => 'cours',
        'couverture'  => '',
    ];
}

groupe('carte_html() — échappement');

test('un titre porteur de HTML ne peut pas ouvrir de balise', function () {
    /* Le texte de la charge utile reste lisible à l écran — c est normal
       et voulu : l utilisateur a le droit d appeler sa série « <img> ».
       Ce qui compte est qu aucun CHEVRON brut ne subsiste, donc qu aucune
       balise ne puisse s ouvrir. */
    $html = carte_html(serie(['titre' => '<img src=x onerror=alert(1)>']));
    sans('<img src=x', $html, 'la balise injectée ne s ouvre pas');
    contient('&lt;img src=x onerror=alert(1)&gt;', $html,
        'la charge utile est entièrement transformée en entités');
});

test('un titre ne peut pas s échapper de son attribut', function () {
    /* Le titre est écrit dans data-titre="…" ET dans aria-label="…".
       Une apostrophe ou un guillemet mal échappé y ouvrirait un nouvel
       attribut — par exemple onmouseover. */
    $html = carte_html(serie(['titre' => 'a" onmouseover="alert(1)']));
    sans('onmouseover="alert', $html, 'aucun attribut n a été greffé');
    contient('&quot;', $html, 'le guillemet est bien encodé');
});

test('une apostrophe dans un titre est encodée', function () {
    // ENT_QUOTES : les attributs du gabarit peuvent être écrits avec
    // l une ou l autre sorte de guillemets.
    $html = carte_html(serie(['titre' => "L'Attaque des Titans"]));
    contient('&#039;', $html, 'l apostrophe devient une entité');
    sans("L'Attaque", $html, 'elle ne subsiste pas telle quelle');
});

test('un auteur porteur de HTML est échappé lui aussi', function () {
    $html = carte_html(serie(['auteur' => '<script>alert(1)</script>']));
    sans('<script>', $html, 'aucune balise script');
});

test('un statut inventé ne se glisse pas dans le HTML', function () {
    /* Le statut vient de la base, mais il sert à composer une classe CSS
       (« status-… ») et un attribut data- : il passe donc par la liste
       blanche STATUTS avant d être écrit. */
    $html = carte_html(serie(['statut' => '" onload="alert(1)']));
    sans('onload="alert', $html, 'aucune injection par le statut');
    contient('status-cours', $html, 'un statut inconnu retombe sur « cours »');
});

groupe('carte_html() — le tome et le repli des valeurs');

test('le tome suivant est annoncé pour une série en cours', function () {
    $html = carte_html(serie(['tome_actuel' => 3, 'statut' => 'cours']));
    contient('Tome 4 à emprunter', $html, 'le tome suivant, pas le tome courant');
    contient('Vous en êtes au tome', $html, 'et le libellé de progression correspond');
});

test('une série « envie » annonce elle aussi un tome à emprunter', function () {
    $html = carte_html(serie(['tome_actuel' => 0, 'statut' => 'envie']));
    contient('Tome 1 à emprunter', $html, 'on commence par le tome 1');
});

test('une série terminée n annonce plus rien à emprunter', function () {
    /* « Tome à emprunter » n a de sens que pour une série qu on
       continue. */
    $html = carte_html(serie(['tome_actuel' => 27, 'statut' => 'termine']));
    sans('à emprunter', $html, 'aucune étiquette de tome suivant');
    contient('Dernier tome lu', $html, 'le libellé change');
});

test('une série abandonnée non plus', function () {
    $html = carte_html(serie(['statut' => 'abandon']));
    sans('à emprunter', $html, 'aucune étiquette');
    contient('status-abandon', $html, 'la classe du statut est posée');
});

test('un tome négatif est ramené à zéro', function () {
    // La colonne est INT UNSIGNED, mais une sauvegarde importée peut
    // contenir n importe quoi.
    $html = carte_html(serie(['tome_actuel' => -5]));
    contient('Tome 1 à emprunter', $html, 'le tome suivant part de 0');
});

test('un tome envoyé sous forme de texte est converti', function () {
    // PDO rend des chaînes pour les entiers selon le pilote.
    $html = carte_html(serie(['tome_actuel' => '12']));
    contient('Tome 13 à emprunter', $html, 'la conversion a eu lieu');
});

test('le bouton « annuler » est désactivé au tome zéro', function () {
    /* Il n y a rien à annuler : laisser le bouton actif enverrait une
       requête qui ne peut qu échouer. */
    $html = carte_html(serie(['tome_actuel' => 0]));
    contient('disabled', $html, 'le bouton est désactivé');
});

test('le bouton « annuler » est actif dès le tome 1', function () {
    $html = carte_html(serie(['tome_actuel' => 1]));
    sans('disabled', $html, 'le bouton est utilisable');
});

test('un auteur absent ne casse pas le gabarit', function () {
    // La colonne est nullable.
    $html = carte_html(serie(['auteur' => null]));
    contient('card-subtitle', $html, 'le bloc est présent, simplement vide');
});

groupe('carte_html() — la couverture');

test('une couverture uploads/ est affichée', function () {
    $html = carte_html(serie(['couverture' => 'uploads/abc.webp']));
    contient('src="uploads/abc.webp"', $html, 'l image est servie');
    contient('loading="lazy"', $html, 'et chargée paresseusement');
});

test('une couverture https externe est affichée', function () {
    $html = carte_html(serie(['couverture' => 'https://exemple.test/c.png']));
    contient('src="https://exemple.test/c.png"', $html, 'le lien externe passe');
});

test('une couverture dangereuse est remplacée par le pictogramme', function () {
    /* url_image_sure() est appliquée ICI aussi, et pas seulement à
       l enregistrement : une valeur douteuse déjà présente en base ne
       doit pas ressortir dans le HTML. */
    $html = carte_html(serie(['couverture' => 'javascript:alert(1)']));
    sans('javascript:', $html, 'la charge utile a disparu');
    contient('no-cover', $html, 'le pictogramme de repli est affiché');
});

test('sans couverture, le pictogramme est affiché', function () {
    $html = carte_html(serie(['couverture' => '']));
    contient('no-cover', $html, 'le repli');
    sans('<img', $html, 'aucune balise image vide');
});

test('une couverture absente (null) est tolérée', function () {
    $html = carte_html(serie(['couverture' => null]));
    contient('no-cover', $html, 'le repli est affiché');
});

groupe('carte_html() — accessibilité');

test('le texte alternatif décrit le tome à emprunter', function () {
    // Le alt n existe que s il y a une image : sans couverture, c est le
    // pictogramme de repli, marqué aria-hidden.
    $html = carte_html(serie(['titre' => 'Berserk', 'tome_actuel' => 40, 'statut' => 'cours',
                              'couverture' => 'uploads/a.webp']));
    contient('alt="Couverture du tome 41 de Berserk"', $html, 'un alt utile');
});

test('le pictogramme de repli est masqué aux lecteurs d écran', function () {
    /* Il ne porte aucune information : le faire lire ajouterait du bruit
       à la place d un texte utile. */
    $html = carte_html(serie(['couverture' => '']));
    contient('aria-hidden="true"', $html, 'le pictogramme est ignoré');
});

test('le texte alternatif change pour une série terminée', function () {
    $html = carte_html(serie(['titre' => 'Berserk', 'tome_actuel' => 40, 'statut' => 'termine',
                              'couverture' => 'uploads/a.webp']));
    contient('dernier tome lu 40', $html, 'le alt décrit le dernier tome lu');
});

test('le texte alternatif est échappé comme le reste', function () {
    $html = carte_html(serie(['titre' => 'a"b', 'couverture' => 'uploads/a.webp']));
    sans('alt="Couverture du tome 4 de a"b"', $html, 'l attribut alt reste bien formé');
});

test('les boutons portent un libellé pour les lecteurs d écran', function () {
    $html = carte_html(serie());
    contient('aria-label="Annuler la dernière lecture"', $html, 'le bouton annuler');
    contient('aria-label="Marquer le tome 4 comme lu"', $html, 'le bouton avancer');
});

groupe('carte_html() — les attributs lus par le JavaScript');

test('l identifiant est forcé en entier', function () {
    /* data-id sert à composer la requête AJAX : il ne doit en aucun cas
       transporter autre chose qu un nombre. */
    $html = carte_html(serie(['id' => '7abc']));
    contient('data-id="7"', $html, 'la partie non numérique est perdue');
});

test('les données de la carte sont présentes pour la recherche côté navigateur', function () {
    $html = carte_html(serie());
    contient('data-titre="Fullmetal Alchemist"', $html, 'le titre');
    contient('data-auteur="Hiromu Arakawa"', $html, 'l auteur');
    contient('data-tome="3"', $html, 'le tome');
    contient('data-statut="cours"', $html, 'le statut');
});
