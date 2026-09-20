<?php
/* =====================================================================
   erreur_fatale() et reponse_json()

   Les deux fonctions du projet qui se terminent par exit. Rien ne
   s exécute après elles : on les appelle donc dans un sous-processus
   (tests/outils/) dont on lit la sortie.

   erreur_fatale() est la dernière chose que voit un utilisateur quand
   tout va mal. Deux exigences opposées se rejoignent là : lui dire
   quelque chose d utile, et ne rien lui révéler du serveur.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

/** Lance un des scripts de tests/outils/ et rend sa sortie. */
function appeler_outil(string $nom, array $arguments = []): string
{
    $resultat = executer_php(__DIR__ . '/../outils/' . $nom, $arguments);
    return $resultat['sortie'];
}

/** Idem, mais en rendant aussi le code de retour du processus. */
function appeler_outil_complet(string $nom, array $arguments = []): array
{
    return executer_php(__DIR__ . '/../outils/' . $nom, $arguments);
}

/** Extrait la valeur d un marqueur « ##NOM##valeur » de la sortie. */
function marqueur(string $nom, string $sortie): string
{
    return preg_match('/^##' . $nom . '##(.*)$/m', $sortie, $m) ? trim($m[1]) : '';
}

/** Ce que la fonction a réellement écrit, sans les marqueurs de l outil. */
function corps(string $sortie): string
{
    return trim(explode('##CODE##', $sortie)[0]);
}

groupe('erreur_fatale() — la page d erreur en HTML');

test('la réponse est un 500', function () {
    $sortie = appeler_outil('appeler-erreur-fatale.php', ['html']);
    egale('500', marqueur('CODE', $sortie), 'le code de réponse annonce une erreur serveur');
});

test('le message reste neutre et exploitable', function () {
    $sortie = appeler_outil('appeler-erreur-fatale.php', ['html']);
    contient('Service temporairement indisponible', corps($sortie), 'un message compréhensible');
    contient('réessayer dans quelques instants', corps($sortie), 'une conduite à tenir');
});

test('l adresse de l administrateur est proposée', function () {
    // Sinon l utilisateur bloqué n a aucun recours.
    $sortie = appeler_outil('appeler-erreur-fatale.php', ['html']);
    contient(ADMIN_EMAIL, corps($sortie), 'un contact est donné');
});

test('AUCUN détail technique n arrive à l écran', function () {
    /* Une trace PHP affichée révèle les chemins du serveur, les requêtes
       SQL et parfois des valeurs de configuration. Le détail part au
       journal, jamais à l utilisateur. */
    $page = corps(appeler_outil('appeler-erreur-fatale.php', ['html']));
    sans('DETAIL-CONFIDENTIEL', $page, 'le détail transmis ne fuit pas');
    sans('mot de passe base', $page, 'ni la valeur de configuration');
    sans('/home/site/includes/config.php', $page, 'ni le chemin du serveur');
});

test('le détail part bien dans le journal', function () {
    // L information n est pas perdue : elle va là où seul
    // l administrateur la lit.
    $sortie = appeler_outil('appeler-erreur-fatale.php', ['html']);
    contient('DETAIL-CONFIDENTIEL', marqueur('JOURNAL', $sortie), 'le journal a reçu le détail');
});

groupe('erreur_fatale() — la réponse aux requêtes AJAX');

test('une requête qui attend du JSON reçoit du JSON', function () {
    /* Lui renvoyer du HTML produisait « Réponse inattendue du serveur »
       côté navigateur, à la place du message. */
    $sortie = appeler_outil('appeler-erreur-fatale.php', ['json']);
    $donnees = json_decode(corps($sortie), true);
    vrai(is_array($donnees), 'la réponse se décode en JSON');
    faux($donnees['ok'], 'le drapeau ok est à false');
    differe('', (string) ($donnees['erreur'] ?? ''), 'un message est fourni');
});

test('l en-tête X-Requested-With suffit à déclencher le JSON', function () {
    $sortie = appeler_outil('appeler-erreur-fatale.php', ['ajax']);
    vrai(is_array(json_decode(corps($sortie), true)), 'l autre signe d une requête AJAX est reconnu');
});

test('la réponse JSON ne fuit rien non plus', function () {
    $sortie = appeler_outil('appeler-erreur-fatale.php', ['json']);
    sans('DETAIL-CONFIDENTIEL', corps($sortie), 'aucun détail dans le corps');
});

test('les accents du message JSON sont lisibles', function () {
    // JSON_UNESCAPED_UNICODE : sinon « é » arrive sous la forme d une
    // séquence d échappement, illisible dans le journal du navigateur.
    $sortie = appeler_outil('appeler-erreur-fatale.php', ['json']);
    sans('\\u00', corps($sortie), 'aucune séquence d échappement Unicode');
});

groupe('erreur_fatale() — en ligne de commande (le cron)');

test('le script se termine avec un code de retour NON NUL', function () {
    /* Le cœur du sujet. purger.php se termine volontairement avec un code
       non nul quand quelque chose mérite l attention de l administrateur,
       parce que c est ce qui déclenche le réglage « envoyer le journal
       uniquement en cas d erreur » de l hébergeur.

       Or une base injoignable coupait le script AVANT tout ça, par
       erreur_fatale(), qui se terminait par exit("<html>…") — donc avec
       un code de retour de ZÉRO. Le cron voyait un succès, le journal ne
       partait pas, et une panne totale de base de données pouvait durer
       des semaines sans que personne ne le sache. */
    $r = appeler_outil_complet('appeler-erreur-fatale.php', ['cron']);
    egale(1, $r['code'], 'le cron voit bien un échec');
});

test('aucune page HTML n est écrite dans le journal du cron', function () {
    /* Une page HTML dans le journal d une tâche planifiée est illisible,
       et surtout trompeuse : elle parle de « réessayer dans quelques
       instants » à quelqu un qui n a rien à réessayer. */
    $sortie = appeler_outil('appeler-erreur-fatale.php', ['cron']);
    sans('<!doctype html', $sortie, 'pas de page web');
    sans('Service temporairement indisponible', $sortie, 'pas de message destiné à un visiteur');
});

test('le message dit ce qui s est passé, et le détail est donné', function () {
    /* Ici, contrairement au web, le seul lecteur est l administrateur :
       lui cacher le détail ne protège personne et lui coûte le
       diagnostic. */
    $sortie = appeler_outil('appeler-erreur-fatale.php', ['cron']);
    contient("s'arrête sans avoir fait son travail", $sortie, 'la situation est nommée');
    contient('DETAIL-CONFIDENTIEL', $sortie, 'le détail technique est fourni');
});

test('une vraie requête HTTP garde la page web', function () {
    /* Garde-fou : la branche « ligne de commande » ne doit surtout pas
       se déclencher pour un visiteur. REQUEST_METHOD, que tout serveur
       web renseigne, fait la différence. */
    $r = appeler_outil_complet('appeler-erreur-fatale.php', ['html']);
    contient('Service temporairement indisponible', corps($r['sortie']), 'la page est bien rendue');
});

groupe('reponse_json() — les réponses de l API');

test('le code HTTP demandé est appliqué', function () {
    /* Le JavaScript du site distingue 401 (session expirée), 403 (jeton
       ou mot de passe), 404, 413, 422 et 429 : un code générique rendrait
       tous ces cas indiscernables. */
    egale('422', marqueur('CODE', appeler_outil('appeler-reponse-json.php', ['422'])),
        'un 422 pour une saisie invalide');
    egale('200', marqueur('CODE', appeler_outil('appeler-reponse-json.php', ['200'])),
        'un 200 par défaut');
});

test('le corps est du JSON valide', function () {
    $sortie = appeler_outil('appeler-reponse-json.php', ['200']);
    $donnees = json_decode(corps($sortie), true);

    vrai(is_array($donnees), 'la réponse se décode');
    egale('Le titre est obligatoire.', $donnees['erreur'] ?? null, 'le message traverse intact');
});

test('les accents ne sont pas échappés', function () {
    $sortie = appeler_outil('appeler-reponse-json.php', ['200']);
    contient('accentué', corps($sortie), 'les accents restent lisibles dans le corps');
});

test('les slashs des chemins d images ne sont pas échappés', function () {
    /* JSON_UNESCAPED_SLASHES : sans lui, « uploads/a.webp » arrive en
       « uploads\/a.webp ». C est valide, mais illisible dans le journal
       du navigateur et inutilement plus lourd. */
    $json = corps(appeler_outil('appeler-reponse-json.php', ['200']));
    contient('uploads/a.webp', $json, 'le chemin est écrit tel quel');
    sans('uploads\\/a.webp', $json, 'aucun slash échappé');
});
