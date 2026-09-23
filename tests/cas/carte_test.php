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

groupe('carte_html() — les favoris');

test('une série en favori porte le drapeau et l étoile allumée', function () {
    $html = carte_html(serie(['favori' => 1]));
    contient('data-favori="1"', $html, 'le drapeau lu par le filtre');
    contient('btn-favori actif', $html, 'l étoile est allumée');
    contient('aria-pressed="true"', $html, 'annoncée comme enfoncée');
});

test('une série ordinaire porte le drapeau à zéro', function () {
    $html = carte_html(serie(['favori' => 0]));
    contient('data-favori="0"', $html, 'le drapeau');
    sans('btn-favori actif', $html, 'l étoile reste éteinte');
});

test('la valeur « 0 » venue de MySQL ne passe pas pour vraie', function () {
    /* PDO rend les TINYINT en CHAÎNES : la carte recevait donc « "0" »,
       qui est vrai pour un test naïf. Toute série aurait été en favori. */
    $html = carte_html(serie(['favori' => '0']));
    contient('data-favori="0"', $html, 'la chaîne « 0 » vaut faux');
    sans('btn-favori actif', $html, 'étoile éteinte');

    $html = carte_html(serie(['favori' => '1']));
    contient('data-favori="1"', $html, 'la chaîne « 1 » vaut vrai');
});

test('une série d avant la migration est traitée comme non favorite', function () {
    /* La clé manque tant que la colonne n a pas été ajoutée, ou pour une
       ligne relue par un chemin qui ne la sélectionne pas. Le défaut doit
       être le plus discret, jamais « tout en favori ». */
    $serie = serie();
    unset($serie['favori']);
    $html = carte_html($serie);
    contient('data-favori="0"', $html, 'clé absente = pas favori');
});

test('l étoile porte son action et son libellé', function () {
    contient('data-action="favori"', carte_html(serie()), 'le gestionnaire de la grille s y raccroche');

    contient('Mettre en favori', carte_html(serie(['favori' => 0])), 'le libellé quand elle est éteinte');
    contient('Retirer des favoris', carte_html(serie(['favori' => 1])), 'et quand elle est allumée');
});

test('un titre à guillemets ne casse pas le libellé de l étoile', function () {
    /* Le titre est inséré dans l attribut aria-label de l étoile : un
       guillemet non échappé y ouvrirait un attribut à lui. */
    /* « <b> » ne ferait pas un bon témoin : le gabarit en contient un
       pour le numéro de tome. On prend une balise qui n a aucune
       raison d apparaître. */
    $html = carte_html(serie(['titre' => 'Ça "va" <script>']));
    sans('aria-label="Mettre "', $html, 'l attribut n est pas refermé trop tôt');
    sans('<script>', $html, 'aucune balise ne passe');
    contient('&quot;va&quot;', $html, 'les guillemets sont encodés');
});

groupe('carte_html() — la provenance de l image, pour le filtre avancé');

test('les quatre provenances sont annoncées', function () {
    contient('data-image="aucune"', carte_html(serie(['couverture' => ''])),
        'sans couverture');
    egale(true, str_contains(carte_html(serie(['couverture' => 'uploads/a1b2c3.webp'])),
        'data-image="importee"'), 'fichier envoyé');
    egale(true, str_contains(carte_html(serie(['couverture' =>
        'https://uploads.mangadex.org/covers/801513ba-a712-498c-8f57-cae55b38cc92/x.jpg'])),
        'data-image="mangadex"'), 'couverture MangaDex');
    egale(true, str_contains(carte_html(serie(['couverture' => 'https://ailleurs.test/x.jpg'])),
        'data-image="lien"'), 'lien externe');
});

test('une couverture refusée est classée « aucune »', function () {
    /* Le classement porte sur ce qui est RÉELLEMENT affiché : une URL
       rejetée par url_image_sure() ne montre aucune image, la série doit
       donc apparaître sous « Pas d image » et pas ailleurs — sans quoi
       elle serait introuvable par le filtre censé la débusquer. */
    $html = carte_html(serie(['couverture' => 'javascript:alert(1)']));
    contient('data-image="aucune"', $html, 'classée comme sans image');
    sans('javascript:', $html, 'et l URL ne sort pas');
});

groupe('carte_html() — le lien MangaDex');

test('le lien est porté jusqu au DOM', function () {
    $id = '801513ba-a712-498c-8f57-cae55b38cc92';
    contient('data-mangadex="' . $id . '"', carte_html(serie(['mangadex_id' => $id])),
        'le JavaScript s en sert pour savoir si la série se rafraîchit seule');
});

test('une série non liée porte un lien vide', function () {
    contient('data-mangadex=""', carte_html(serie()), 'clé absente');
    contient('data-mangadex=""', carte_html(serie(['mangadex_id' => ''])), 'clé vide');
});

test('un lien inattendu est échappé comme le reste', function () {
    /* Il vient de la base, donc d une URL qu on a nous-même analysée —
       mais le gabarit ne doit rien supposer de ce qu on lui donne. */
    /* Le texte « onload= » subsiste dans la valeur, et c est sans
       danger : ce qui compte est qu aucun GUILLEMET brut ne vienne
       refermer data-mangadex pour en rouvrir un autre. */
    $html = carte_html(serie(['mangadex_id' => '" onload="alert(1)']));
    sans(' onload="alert', $html, 'aucun attribut ne s ouvre');
    contient('data-mangadex="&quot; onload=&quot;alert(1)"', $html,
        'tout est resté dans la valeur, encodé');
});