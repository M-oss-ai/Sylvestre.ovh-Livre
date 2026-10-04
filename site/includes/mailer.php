<?php
/* =====================================================================
   Envoi d'e-mail par SMTP, en PHP natif (pas de Composer/dépendance —
   même esprit que le reste du projet). Pensé pour un petit volume
   (confirmation de compte, mot de passe oublié), pas pour du mailing.
   ===================================================================== */

declare(strict_types=1);

/**
 * Envoi SMTP brut. Appelez envoyer_email(), pas cette fonction — sauf
 * pour le rapport du cron, qui n'a pas de destinataire à revalider.
 *
 * Ne lève jamais d'exception : un envoi qui échoue est consigné
 * (error_log) et renvoie false, pour ne jamais casser une page.
 */
function envoyer_email_smtp(string $destinataire, string $sujet, string $corps): bool
{
    $hote = env('SMTP_HOST', '');
    $port = (int) env('SMTP_PORT', '587');
    $user = env('SMTP_USER', '');
    $pass = env('SMTP_PASSWORD', '');
    $nom  = env('SMTP_EXPEDITEUR_NOM', 'Ma Bibliothèque Manga');

    if ($hote === '' || $user === '' || $pass === '') {
        error_log('envoyer_email: SMTP non configuré (.env) — e-mail non envoyé.');
        return false;
    }

    /* Une adresse contenant un retour à la ligne permettrait d'ajouter des
       en-têtes SMTP arbitraires (Bcc:, To: supplémentaires…). Les appelants
       valident déjà avec FILTER_VALIDATE_EMAIL, mais cette fonction est le
       dernier rempart avant le réseau : on ne lui fait pas confiance. */
    if (!filter_var($destinataire, FILTER_VALIDATE_EMAIL)
        || preg_match('/[\r\n]/', $destinataire . $sujet)) {
        error_log('envoyer_email: destinataire ou sujet invalide.');
        return false;
    }

    /* Vérification du certificat du serveur SMTP.
       Sans ce contexte, PHP ouvre la session TLS sans contrôler à qui il
       parle : n'importe qui en position d'interception sur le réseau
       (Wi-Fi public, opérateur, hébergeur voisin) peut se faire passer
       pour le serveur et récupérer en clair l'identifiant et le mot de
       passe de la boîte mail — donc le droit d'envoyer du courrier en
       votre nom. On impose aussi TLS 1.2 minimum : TLS 1.0 et 1.1 sont
       obsolètes et cassés. */
    $contexte = stream_context_create([
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
            'peer_name'         => $hote,
            'SNI_enabled'       => true,
            'disable_compression' => true,
        ],
    ]);

    $adresse = ($port === 465 ? 'ssl://' : 'tcp://') . $hote . ':' . $port;
    $flux = @stream_socket_client(
        $adresse,
        $errno,
        $errstr,
        SMTP_TIMEOUT,
        STREAM_CLIENT_CONNECT,
        $contexte
    );
    if ($flux === false) {
        error_log("envoyer_email: connexion SMTP échouée vers {$hote}:{$port} ({$errstr})");
        return false;
    }
    stream_set_timeout($flux, SMTP_TIMEOUT);

    // Lit une réponse SMTP complète (gère les réponses multi-lignes du
    // type "250-..." / "250 ..." : seule la dernière ligne a un tiret
    // remplacé par un espace en 4ᵉ caractère).
    $lireReponse = static function () use ($flux): int {
        $code = 0;
        do {
            $ligne = fgets($flux, 515);
            if ($ligne === false) {
                break;
            }
            $code = (int) substr($ligne, 0, 3);
        } while (isset($ligne[3]) && $ligne[3] === '-');
        return $code;
    };
    $envoyerCommande = static function (string $commande) use ($flux, $lireReponse): int {
        fwrite($flux, $commande . "\r\n");
        return $lireReponse();
    };

    $echec = static function (string $raison) use ($flux): bool {
        error_log('envoyer_email: ' . $raison);
        fclose($flux);
        return false;
    };

    if ($lireReponse() !== 220) {
        return $echec('bannière SMTP inattendue');
    }

    // Le nom annoncé dans EHLO vient de la configuration, pas de la
    // requête : SERVER_NAME peut être influencé par l'en-tête Host.
    $domaineClient = parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost';

    if ($envoyerCommande('EHLO ' . $domaineClient) !== 250) {
        return $echec('EHLO refusé');
    }

    if ($port !== 465) {
        if ($envoyerCommande('STARTTLS') !== 220) {
            return $echec('STARTTLS refusé par le serveur');
        }
        // TLS 1.2 minimum. STREAM_CRYPTO_METHOD_TLS_CLIENT accepterait
        // aussi TLS 1.0/1.1, dépréciés depuis 2021.
        $methodes = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $methodes |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if (!@stream_socket_enable_crypto($flux, true, $methodes)) {
            return $echec('négociation TLS échouée (certificat du serveur SMTP invalide ?)');
        }
        if ($envoyerCommande('EHLO ' . $domaineClient) !== 250) {
            return $echec('EHLO (après STARTTLS) refusé');
        }
    }

    /* Les deux 334 sont vérifiés : ce sont les invites « envoyez le nom »
       puis « envoyez le mot de passe ». Sans ce contrôle, un serveur qui
       n'annonce pas AUTH LOGIN répond autre chose, le dialogue se
       désynchronise, et les commandes suivantes sont interprétées de
       travers — l'identifiant et le mot de passe de la boîte partant
       alors n'importe où dans la conversation. */
    if ($envoyerCommande('AUTH LOGIN') !== 334) {
        return $echec('le serveur SMTP ne propose pas AUTH LOGIN');
    }
    if ($envoyerCommande(base64_encode($user)) !== 334) {
        return $echec('identifiant SMTP refusé');
    }
    if ($envoyerCommande(base64_encode($pass)) !== 235) {
        return $echec('authentification refusée (identifiants SMTP corrects ?)');
    }

    if ($envoyerCommande('MAIL FROM:<' . $user . '>') !== 250) {
        return $echec('MAIL FROM refusé');
    }
    $codeRcpt = $envoyerCommande('RCPT TO:<' . $destinataire . '>');
    if ($codeRcpt !== 250 && $codeRcpt !== 251) {
        return $echec('destinataire refusé par le serveur');
    }

    if ($envoyerCommande('DATA') !== 354) {
        return $echec('DATA refusé');
    }

    fwrite($flux, composer_message($destinataire, $sujet, $corps, $user, $nom, $domaineClient) . "\r\n.\r\n");
    $codeFinal = $lireReponse();

    $envoyerCommande('QUIT');
    fclose($flux);

    if ($codeFinal !== 250) {
        error_log('envoyer_email: message refusé (code ' . $codeFinal . ')');
        return false;
    }
    return true;
}

/* ---------------------------------------------------------------------
   Le message lui-même

   Composé à part du dialogue SMTP, pour être vérifié sans réseau.

   Le corps part en deux versions : le texte brut, et le même texte en
   HTML. Un e-mail en texte brut ne contient aucun lien — l'adresse n'y
   est que du texte, et c'est la messagerie qui décide d'en faire un lien.
   Proton, sur ordinateur, ne le faisait pas : il fallait copier chaque
   lien de confirmation à la main. La version HTML en porte de vrais.

   Ces fonctions ne se servent pas de e() : purger.php, qui rejoue la
   file d'attente, ne charge pas fonctions.php.
   --------------------------------------------------------------------- */

/**
 * Le message tel qu'il part après la commande DATA : les en-têtes, une
 * ligne vide, puis le corps (voir message_mime()).
 */
function composer_message(string $destinataire, string $sujet, string $corps, string $expediteur, string $nom, string $domaine): string
{
    // Sépare les deux versions du corps. « =_ » n'existe pas en base64 :
    // aucune ligne du contenu ne peut être prise pour elle.
    $frontiere = '=_livre_' . bin2hex(random_bytes(8));

    $entetes = implode("\r\n", [
        // Le nom est encodé comme le sujet : « Bibliothèque » porte un
        // accent, qu'un en-tête ne peut pas contenir tel quel.
        'From: ' . mots_encodes($nom) . ' <' . $expediteur . '>',
        'To: <' . $destinataire . '>',
        'Subject: ' . mots_encodes($sujet),
        // Message-ID : plusieurs filtres anti-spam (dont ceux de Microsoft)
        // pénalisent un message qui n'en porte pas. Le domaine est celui du
        // site, pour rester cohérent avec l'adresse d'expédition.
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domaine . '>',
        'Auto-Submitted: auto-generated',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $frontiere . '"',
        'Date: ' . date('r'),
    ]);

    return $entetes . "\r\n\r\n" . message_mime($corps, $sujet, $frontiere);
}

/**
 * Un texte en « mots encodés » (RFC 2047) : la seule forme sous laquelle
 * un en-tête accepte autre chose que de l'ASCII.
 *
 * Coupé tous les 42 octets au plus — entre deux caractères, jamais au
 * milieu d'une lettre accentuée — et replié sur plusieurs lignes : un mot
 * encodé ne dépasse pas 75 caractères, une ligne d'en-tête pas 78. Le
 * sujet du rapport du cron, d'un seul tenant, en faisait plus de 120.
 */
function mots_encodes(string $texte): string
{
    $mots = [];
    $mot  = '';
    foreach (mb_str_split($texte, 1, 'UTF-8') as $caractere) {
        if ($mot !== '' && strlen($mot . $caractere) > 42) {
            $mots[] = $mot;
            $mot    = '';
        }
        $mot .= $caractere;
    }
    $mots[] = $mot;

    // L'espace qui sépare deux mots encodés ne compte pas : le texte se
    // relit d'un seul tenant.
    return implode("\r\n ", array_map(
        static fn (string $m): string => '=?UTF-8?B?' . base64_encode($m) . '?=',
        $mots
    ));
}

/**
 * Le corps en « multipart/alternative » : le texte brut d'abord, sa
 * version HTML ensuite. La messagerie affiche la DERNIÈRE qu'elle sait
 * lire : le HTML presque partout, le texte ailleurs.
 */
function message_mime(string $corps, string $sujet, string $frontiere): string
{
    $partie = static fn (string $type, string $contenu): string =>
        '--' . $frontiere . "\r\n"
        . 'Content-Type: ' . $type . "; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($contenu));

    // Fins de ligne canoniques (CRLF) : c'est la forme qu'attend un texte
    // encodé en base64, où rien ne les convertit plus en route.
    $texte = preg_replace('/\r\n|\r|\n/', "\r\n", $corps) ?? $corps;

    return $partie('text/plain', $texte)
        . $partie('text/html', corps_html($corps, $sujet))
        . '--' . $frontiere . "--\r\n";
}

/**
 * La version HTML d'un e-mail, tirée de son texte : les paragraphes
 * restent des paragraphes, et les adresses du site deviennent des liens.
 *
 * Celles du SITE seulement — qui commencent par APP_URL. Tout le reste est
 * du texte échappé, même s'il a l'air d'une adresse : un identifiant ou
 * une adresse e-mail choisis par quelqu'un d'autre ne doivent pas devenir
 * un lien cliquable dans un message authentique de ce site, l'appât rêvé
 * d'un hameçonnage.
 *
 * Le lien montre l'adresse elle-même, pas « cliquez ici » : on voit où
 * il mène avant de cliquer.
 */
function corps_html(string $texte, string $sujet = ''): string
{
    $echapper = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $du_site  = static fn (string $url): bool => $url === APP_URL || str_starts_with($url, APP_URL . '/');

    $texte = preg_replace('/\r\n|\r/', "\n", $texte) ?? $texte;
    $html  = '';
    foreach (preg_split('/\n\s*\n/', trim($texte)) ?: [] as $paragraphe) {
        /* Une adresse s'arrête avant la ponctuation qui la suit dans la
           phrase (« … ici : https://…/page. ») : le point final n'en fait
           pas partie. Les indices impairs sont les adresses capturées. */
        $morceaux = preg_split('~(https?://[^\s<>"]*[^\s<>".,;:!?)\]])~u', $paragraphe, -1, PREG_SPLIT_DELIM_CAPTURE)
            ?: [$paragraphe];
        $contenu = '';
        foreach ($morceaux as $i => $morceau) {
            $contenu .= $i % 2 === 1 && $du_site($morceau)
                ? '<a href="' . $echapper($morceau) . '" style="color:#8a5a1c;word-break:break-all">'
                  . $echapper($morceau) . '</a>'
                : $echapper($morceau);
        }
        $html .= '<p style="margin:0 0 16px">' . nl2br($contenu, false) . "</p>\n";
    }

    return "<!DOCTYPE html>\n<html lang=\"fr\">\n<head>\n<meta charset=\"UTF-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . '<title>' . $echapper($sujet) . "</title>\n</head>\n"
        . '<body style="margin:0;padding:24px 16px;background:#ffffff;color:#1f1a14;'
        . "font-family:-apple-system,'Segoe UI',Roboto,Arial,sans-serif;font-size:16px;line-height:1.5\">\n"
        . "<div style=\"max-width:560px;margin:0 auto\">\n" . $html . "</div>\n</body>\n</html>\n";
}

/* ---------------------------------------------------------------------
   Envoi

   Un envoi raté n'est PAS retenté (choix de l'utilisateur) : l'ancienne
   file de rattrapage (mail_file, rejouée par purger.php) gardait
   indéfiniment en tête un message à une adresse fausse, et ceux qui
   suivaient ne partaient jamais. L'appelant reçoit false et le dit à
   l'utilisateur, qui recommence.

   ⚠️ Il n'y a AUCUN plafond d'envoi applicatif. Le seul frein restant est
   le limiteur par IP des pages qui déclenchent un envoi (inscription,
   mot de passe oublié, renvoi de confirmation) — qu'une attaque
   distribuée contourne par construction. La limite d'envoi journalière
   de la boîte OVH est donc devenue la seule borne réelle, et quand elle
   est atteinte, plus RIEN ne part : ni inscription, ni réinitialisation,
   ni avis de sécurité. Surveillez le journal d'erreurs.
   --------------------------------------------------------------------- */

/**
 * Envoie un e-mail — c'est CETTE fonction que le reste du site appelle.
 *
 * Une seule tentative, immédiate : false si elle échoue (le détail est
 * dans le journal d'erreurs, voir envoyer_email_smtp()).
 */
function envoyer_email(string $destinataire, string $sujet, string $corps): bool
{
    if (!filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    return envoyer_email_smtp($destinataire, $sujet, $corps);
}

/**
 * Reconstruit une URL absolue (nécessaire dans un e-mail, hors contexte web).
 *
 * L'adresse vient de APP_URL (.env) et JAMAIS de l'en-tête Host de la
 * requête, qui est choisi par le client. Avec Host, un attaquant pouvait
 * demander une réinitialisation pour le compte de quelqu'un d'autre en
 * envoyant « Host: son-serveur » : la victime recevait un e-mail
 * authentique, expédié par ce site, dont le lien pointait chez lui — et
 * il récupérait le jeton dès qu'elle cliquait.
 */
function url_publique(string $chemin): string
{
    return APP_URL . '/' . ltrim($chemin, '/');
}

/* ---------------------------------------------------------------------
   Avis de sécurité

   Envoyés à l'adresse DÉJÀ enregistrée sur le compte, jamais à une
   adresse saisie dans un formulaire. C'est le seul signal qu'a le
   titulaire légitime quand quelqu'un d'autre a mis la main sur son mot
   de passe : sans eux, une prise de contrôle est silencieuse.
   --------------------------------------------------------------------- */

/**
 * Comment ce compte s'ouvre. Un e-mail ne parle que de ce qui existe : un
 * compte Google sans mot de passe n'en a aucun à changer, à oublier, ni à
 * s'être fait voler.
 *   « email »      : l'adresse et le mot de passe ;
 *   « google »     : Google seul ;
 *   « google_mdp » : Google, puis le mot de passe du site.
 */
function acces_compte(?string $google_sub, bool $a_mot_de_passe): string
{
    if ((string) $google_sub === '') {
        return 'email';
    }
    return $a_mot_de_passe ? 'google_mdp' : 'google';
}

/**
 * Le mot de passe vient d'être changé — ou défini, pour un compte Google
 * qui n'en avait pas. $acces décrit le compte AVANT le changement : c'est
 * ce qu'a dû franchir celui qui l'a fait.
 *
 * @return array{0: string, 1: string} le sujet et le corps
 */
function avis_mot_de_passe_change(string $identifiant, string $acces): array
{
    $sujet = 'Votre mot de passe a été modifié';
    $fait  = "Le mot de passe de votre compte Ma Bibliothèque Manga vient d'être modifié, "
           . "et tous vos autres appareils ont été déconnectés.";
    if ($acces === 'google') {
        $sujet = 'Un mot de passe a été ajouté à votre compte';
        $fait  = "Un mot de passe vient d'être ajouté à votre compte Ma Bibliothèque Manga : il vous "
               . "sera demandé après Google, à chaque connexion. Tous vos autres appareils ont été "
               . "déconnectés.";
    }

    $recours = match ($acces) {
        'google'     => "SINON, quelqu'un a accès à votre compte Google : ce changement exige de s'y "
                      . "reconnecter. Sécurisez-le d'abord (changez son mot de passe chez Google), puis "
                      . "choisissez-en un vous-même ici :\n",
        'google_mdp' => "SINON, quelqu'un a accès à votre compte Google et connaissait votre mot de passe "
                      . "du site : ce changement exige les deux. Sécurisez d'abord votre compte Google "
                      . "(changez son mot de passe chez Google), puis demandez un nouveau mot de passe "
                      . "ici :\n",
        default      => "SINON, votre compte est compromis : reprenez-en le contrôle immédiatement "
                      . "en demandant un nouveau mot de passe ici :\n",
    };

    return [$sujet, "Bonjour {$identifiant},\n\n"
        . $fait . "\n\n"
        . "Si vous êtes à l'origine de ce changement, vous n'avez rien à faire.\n\n"
        . $recours . url_publique('mot-de-passe-oublie.php')];
}

function avertir_mot_de_passe_change(string $email, string $identifiant, string $acces): void
{
    [$sujet, $corps] = avis_mot_de_passe_change($identifiant, $acces);
    envoyer_email($email, $sujet, $corps);
}

/**
 * Prévient qu'un compte créé avec Google vient de supprimer son mot de
 * passe : la connexion ne passe plus que par Google. Il faut pour cela
 * avoir repassé par Google à l'instant — si ce n'est pas le titulaire,
 * c'est son compte Google qui est entre d'autres mains.
 */
function avertir_mot_de_passe_supprime(string $email, string $identifiant): void
{
    envoyer_email(
        $email,
        'Votre mot de passe a été supprimé',
        "Bonjour {$identifiant},\n\n"
        . "Le mot de passe de votre compte Ma Bibliothèque Manga vient d'être supprimé : "
        . "vous vous connectez désormais avec Google seul.\n\n"
        . "Si vous êtes à l'origine de ce changement, vous n'avez rien à faire.\n\n"
        . "SINON, quelqu'un a accès à votre compte Google : sécurisez-le d'abord (changez son "
        . "mot de passe chez Google), puis redéfinissez un mot de passe dans les Paramètres du site :\n"
        . url_publique('parametres.php#securite')
    );
}

/**
 * Une clé d'accès vient d'être ajoutée : elle ouvre le compte SANS mot de
 * passe. L'ajout a exigé de prouver son identité (mot de passe retapé, ou
 * reconnexion à Google puis mot de passe) : si ce n'est pas le titulaire,
 * c'est que quelqu'un tient ces accès — le recours est donc de retirer la
 * clé ET de sécuriser ce qui a servi à la poser.
 *
 * $nom est choisi par la personne qui l'ajoute : il n'est qu'écrit, jamais
 * transformé en lien (voir corps_html()).
 *
 * @return array{0: string, 1: string} le sujet et le corps
 */
function avis_cle_acces_ajoutee(string $identifiant, string $nom, string $acces): array
{
    $recours = match ($acces) {
        'google'     => "SINON, quelqu'un a accès à votre compte Google : l'ajout exige de s'y "
                      . "reconnecter. Sécurisez-le d'abord (changez son mot de passe chez Google), puis "
                      . "retirez cette clé d'accès dans les Paramètres :\n"
                      . url_publique('parametres.php#securite'),
        'google_mdp' => "SINON, quelqu'un a accès à votre compte Google et connaissait votre mot de passe "
                      . "du site : l'ajout exige les deux. Sécurisez d'abord votre compte Google (changez "
                      . "son mot de passe chez Google), retirez cette clé d'accès dans les Paramètres, "
                      . "puis changez votre mot de passe :\n"
                      . url_publique('parametres.php#securite'),
        default      => "SINON, quelqu'un connaît votre mot de passe : l'ajout l'exige. Demandez-en un "
                      . "nouveau ici — cela retire AUSSI toutes les clés d'accès du compte :\n"
                      . url_publique('mot-de-passe-oublie.php'),
    };

    return ["Une clé d'accès a été ajoutée à votre compte", "Bonjour {$identifiant},\n\n"
        . "Une clé d'accès vient d'être ajoutée à votre compte Ma Bibliothèque Manga : « {$nom} ».\n\n"
        . "Elle permet de se connecter sans taper de mot de passe, depuis l'appareil ou le gestionnaire "
        . "de mots de passe qui la garde.\n\n"
        . "Si vous êtes à l'origine de cet ajout, vous n'avez rien à faire.\n\n"
        . $recours];
}

function avertir_cle_acces_ajoutee(string $email, string $identifiant, string $nom, string $acces): void
{
    [$sujet, $corps] = avis_cle_acces_ajoutee($identifiant, $nom, $acces);
    envoyer_email($email, $sujet, $corps);
}

/**
 * Prévient qu'un compte vient d'être supprimé.
 *
 * C'est l'action la plus irréversible du site, et c'était la seule
 * sensible qui ne laissait aucune trace chez le titulaire : changer son
 * mot de passe le prévient, demander un changement d'adresse aussi, mais
 * tout effacer se faisait en silence.
 *
 * Envoyé APRÈS la suppression, et seulement si elle a réussi.
 *
 * @return array{0: string, 1: string} le sujet et le corps
 */
function avis_compte_supprime(string $identifiant, string $acces): array
{
    $recours = match ($acces) {
        'google'     => "SINON, quelqu'un a accès à votre compte Google — la suppression exige de s'y "
                      . "reconnecter. Le compte étant effacé, il n'y a plus rien à sécuriser ici, mais "
                      . "sécurisez votre compte Google sans attendre (changez son mot de passe chez Google).",
        'google_mdp' => "SINON, quelqu'un a accès à votre compte Google et connaissait votre mot de passe "
                      . "du site — la suppression exige les deux. Le compte étant effacé, il n'y a plus "
                      . "rien à sécuriser ici, mais sécurisez votre compte Google sans attendre (changez "
                      . "son mot de passe chez Google), et si vous utilisiez ce mot de passe ailleurs, "
                      . "changez-le sur ces autres sites.",
        default      => "SINON, quelqu'un connaissait votre mot de passe — la suppression l'exige. Le "
                      . "compte étant effacé, il n'y a plus rien à sécuriser ici, mais si vous utilisiez "
                      . "ce mot de passe ailleurs, changez-le sur ces autres sites sans attendre.",
    };

    return ['Votre compte a été supprimé', "Bonjour {$identifiant},\n\n"
        . "Le compte Ma Bibliothèque Manga associé à cette adresse vient d'être supprimé, "
        . "ainsi que l'intégralité de la bibliothèque qui lui était rattachée.\n\n"
        . "Cette suppression est définitive : rien n'a été conservé, et le contenu ne peut "
        . "pas être restauré.\n\n"
        . "Si vous êtes à l'origine de cette suppression, vous n'avez rien à faire. Vous "
        . "pouvez créer un nouveau compte à tout moment :\n"
        . url_publique('inscription.php') . "\n\n"
        . $recours];
}

function avertir_compte_supprime(string $email, string $identifiant, string $acces): void
{
    [$sujet, $corps] = avis_compte_supprime($identifiant, $acces);
    envoyer_email($email, $sujet, $corps);
}

/**
 * Prévient l'ancienne adresse d'une demande de changement, avec un lien
 * qui bloque ce changement et fait choisir un nouveau mot de passe (voir
 * reinitialiser-mot-de-passe.php).
 *
 * Ce lien sert celui qui a encore sa boîte, mais plus l'exclusivité de
 * son mot de passe. Il ne donne rien de plus à qui aurait volé la boîte :
 * « Mot de passe oublié » lui ouvrait déjà le compte. Pour un compte Google
 * sans mot de passe, il en fait choisir un : demandé après Google, c'est
 * lui qui tiendra l'intrus à l'écart.
 *
 * Il reste valable 7 jours, MÊME une fois le changement confirmé :
 * l'attaquant tient la nouvelle boîte et confirme en quelques secondes,
 * bien avant que sa victime ne lise ce message. Suivi après coup, il rend
 * donc au compte l'adresse que voici. Aucune autre demande ne le remplace
 * (voir generer_jeton_action()) : l'attaquant pourrait les faire lui-même.
 */
function avertir_changement_email_demande(int $utilisateur_id, string $ancien_email, string $identifiant, string $nouveau_email, string $acces): void
{
    /* Si le jeton ne peut pas être créé (livre.sql pas encore rejoué),
       l'avis part quand même, avec l'ancien conseil : c'est le seul signal
       qu'a le titulaire, il ne doit pas dépendre d'une migration. */
    try {
        $lien = url_publique('reinitialiser-mot-de-passe.php?jeton='
            . generer_jeton_action($utilisateur_id, 'blocage_email', 7 * 86400, $ancien_email, false));
    } catch (Throwable $e) {
        error_log('avertir_changement_email_demande: ' . $e->getMessage());
        $lien = null;
    }

    [$sujet, $corps] = avis_changement_email($identifiant, $nouveau_email, $acces, $lien);
    envoyer_email($ancien_email, $sujet, $corps);
}

/**
 * L'avis de changement d'adresse. $lien est celui du blocage, ou null s'il
 * n'a pas pu être créé.
 *
 * @return array{0: string, 1: string} le sujet et le corps
 */
function avis_changement_email(string $identifiant, string $nouveau_email, string $acces, ?string $lien): array
{
    // L'adresse visée n'est que partiellement affichée : cet e-mail peut
    // finir sous d'autres yeux que ceux du titulaire.
    $masque = preg_replace('/^(.).*(.@)/u', '$1***$2', $nouveau_email) ?? '***';

    $qui = match ($acces) {
        'google'     => "SINON, quelqu'un a accès à votre compte Google : ce changement exige de s'y "
                      . "reconnecter. Sécurisez-le d'abord (changez son mot de passe chez Google), puis ",
        'google_mdp' => "SINON, quelqu'un a accès à votre compte Google et connaît votre mot de passe du "
                      . "site : ce changement exige les deux. Sécurisez d'abord votre compte Google "
                      . "(changez son mot de passe chez Google), puis ",
        default      => "SINON, quelqu'un a votre mot de passe. ",
    };

    if ($lien !== null) {
        $recours = $qui . match ($acces) {
            'google'     => "bloquez le changement d'adresse ici (lien valable 7 jours). Vous y "
                          . "choisirez un mot de passe, qui vous sera demandé après Google à chaque "
                          . "connexion :\n",
            'google_mdp' => "bloquez le changement d'adresse et changez de mot de passe ici (lien "
                          . "valable 7 jours) :\n",
            default      => "Bloquez le changement d'adresse et changez de mot de passe ici (lien "
                          . "valable 7 jours) :\n",
        } . $lien . "\n\n"
          . "Ce lien fonctionne même si le changement a déjà été confirmé : il rend alors "
          . "cette adresse-ci au compte. Tous les appareils connectés seront déconnectés.";
    } else {
        $recours = $qui . match ($acces) {
            'google'     => "reconnectez-vous au site avec Google et remettez cette adresse-ci dans "
                          . "les Paramètres :\n" . url_publique('parametres.php#profil'),
            'google_mdp' => "changez de mot de passe ici :\n" . url_publique('mot-de-passe-oublie.php'),
            default      => "Changez-le tout de suite :\n" . url_publique('mot-de-passe-oublie.php'),
        };
    }

    return ["Demande de changement d'adresse e-mail", "Bonjour {$identifiant},\n\n"
        . "Quelqu'un vient de demander à remplacer l'adresse e-mail de votre compte "
        . "par {$masque}.\n\n"
        . "Cette adresse-ci reste active tant que la nouvelle n'a pas été confirmée : "
        . "si vous êtes à l'origine de la demande, ouvrez simplement le lien envoyé à "
        . "la nouvelle adresse.\n\n"
        . $recours];
}

/**
 * « Mot de passe oublié ». Un compte Google sans mot de passe n'a rien à
 * réinitialiser : il reçoit le chemin de Google, pas de lien ($lien est
 * alors ignoré). En créer un par ce biais contournerait la reconnexion
 * que Google exige pour définir un mot de passe.
 *
 * @return array{0: string, 1: string} le sujet et le corps
 */
function avis_mot_de_passe_oublie(string $identifiant, string $acces, ?string $lien): array
{
    if ($acces === 'google' || $lien === null) {
        return ['Connexion à votre compte', "Bonjour {$identifiant},\n\n"
            . "Une réinitialisation de mot de passe a été demandée pour ce compte, mais il n'en a "
            . "pas : vous vous connectez avec Google.\n"
            . url_publique('connexion.php') . "\n\n"
            . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : "
            . "rien n'a changé sur votre compte."];
    }

    return ['Réinitialisation de votre mot de passe', "Bonjour {$identifiant},\n\n"
        . "Une réinitialisation de mot de passe a été demandée pour ce compte. "
        . "Cliquez sur ce lien pour choisir un nouveau mot de passe (valable 1 heure) :\n"
        . $lien . "\n\n"
        . ($acces === 'google_mdp'
            ? "Google restera demandé avant lui, à chaque connexion.\n\n"
            : '')
        . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : "
        . "rien ne sera changé sur votre compte."];
}

/**
 * Quelqu'un a voulu s'inscrire avec l'adresse d'un compte existant : son
 * titulaire l'apprend, avec le chemin de SA connexion.
 *
 * @return array{0: string, 1: string} le sujet et le corps
 */
function avis_tentative_inscription(string $identifiant, string $acces): array
{
    $chemin = match ($acces) {
        'google'     => "Si c'était vous : connectez-vous avec Google, comme d'habitude.\n"
                      . url_publique('connexion.php') . "\n\n",
        'google_mdp' => "Si c'était vous : connectez-vous avec Google, puis votre mot de passe, "
                      . "comme d'habitude.\n"
                      . url_publique('connexion.php') . "\n"
                      . "Mot de passe oublié ?\n"
                      . url_publique('mot-de-passe-oublie.php') . "\n\n",
        default      => "Si c'était vous : connectez-vous simplement avec votre compte existant.\n"
                      . url_publique('connexion.php') . "\n"
                      . "Mot de passe oublié ?\n"
                      . url_publique('mot-de-passe-oublie.php') . "\n\n",
    };

    return ['Tentative de création de compte avec votre adresse', "Bonjour {$identifiant},\n\n"
        . "Quelqu'un vient d'essayer de créer un compte Ma Bibliothèque Manga avec votre "
        . "adresse e-mail. Comme un compte existe déjà, rien n'a été créé et rien n'a changé.\n\n"
        . $chemin
        . "Si ce n'était pas vous, vous n'avez rien à faire : votre compte n'a pas été touché."];
}

/* ---------------------------------------------------------------------
   Jetons à usage unique : confirmation d'e-mail, réinitialisation de mot
   de passe, changement d'adresse, et blocage de ce changement.

   Le jeton envoyé par e-mail n'est jamais stocké : seule son empreinte
   sha256 va en base, exactement comme un mot de passe. Quelqu'un qui
   lirait la table (sauvegarde égarée, injection ailleurs) ne pourrait
   donc réinitialiser aucun mot de passe.
   --------------------------------------------------------------------- */

/**
 * Crée un jeton pour $type, valable $duree_secondes, et remplace les
 * jetons du même type pour ce compte (une demande annule la précédente,
 * mais n'affecte pas les autres types : demander une confirmation
 * d'e-mail n'annule plus une réinitialisation en cours).
 *
 * $remplacer = false garde les précédents. C'est le cas de
 * « blocage_email » : chaque alerte porte son propre lien, et une
 * seconde demande de changement — faite par l'attaquant — ne doit pas
 * effacer celui qu'a reçu la victime.
 *
 * $donnee porte la valeur en attente de validation — pour
 * « changement_email », la nouvelle adresse ; pour « blocage_email »,
 * l'ancienne, à rétablir.
 *
 * Retourne le jeton en clair, à mettre dans le lien.
 */
function generer_jeton_action(int $utilisateur_id, string $type, int $duree_secondes, ?string $donnee = null, bool $remplacer = true): string
{
    global $pdo;

    if ($remplacer) {
        $pdo->prepare('DELETE FROM jeton_action WHERE utilisateur_id = ? AND type = ?')
            ->execute([$utilisateur_id, $type]);
    }

    $jeton  = bin2hex(random_bytes(32));
    $expire = date('Y-m-d H:i:s', time() + $duree_secondes);

    $pdo->prepare(
        'INSERT INTO jeton_action (utilisateur_id, jeton_hash, type, donnee, expire)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$utilisateur_id, hash('sha256', $jeton), $type, $donnee, $expire]);

    /* Un type absent de l'ENUM (livre.sql pas rejoué) n'échoue pas hors
       mode strict : MySQL range '' avec un simple avertissement, et le
       lien envoyé est mort dès sa naissance — c'est arrivé en production
       avec « blocage_email ». On relit donc ce qui a été écrit. */
    $id = (int) $pdo->lastInsertId();
    $ecrit = $pdo->prepare('SELECT type FROM jeton_action WHERE id = ?');
    $ecrit->execute([$id]);
    if ($ecrit->fetchColumn() !== $type) {
        $pdo->prepare('DELETE FROM jeton_action WHERE id = ?')->execute([$id]);
        throw new RuntimeException("jeton_action.type ne connaît pas « {$type} » : rejouez livre.sql.");
    }

    return $jeton;
}

/**
 * Vérifie un jeton pour $type (non expiré) et retourne le compte associé
 * sans le consommer — utile pour afficher un formulaire (ex. nouveau mot
 * de passe) avant de valider la saisie.
 *
 * La clé 'jeton_id' du tableau retourné sert à consommer précisément ce
 * jeton-là, et la clé 'donnee' porte la valeur en attente.
 */
function jeton_action_valide(string $jeton, string $type): ?array
{
    global $pdo;
    if (!preg_match('/^[a-f0-9]{64}$/', $jeton)) {
        return null;
    }
    $req = $pdo->prepare(
        'SELECT u.id, u.identifiant, u.email, j.id AS jeton_id, j.donnee
           FROM jeton_action j
           JOIN utilisateur u ON u.id = j.utilisateur_id
          WHERE j.jeton_hash = ? AND j.type = ? AND j.expire > NOW()'
    );
    $req->execute([hash('sha256', $jeton), $type]);
    return $req->fetch() ?: null;
}

/** Consomme (invalide) un jeton précis, une fois l'action effectuée. */
function consommer_jeton_action(int $jeton_id): void
{
    global $pdo;
    $pdo->prepare('DELETE FROM jeton_action WHERE id = ?')->execute([$jeton_id]);
}

/* ---------------------------------------------------------------------
   Avis de l'administration (admin.php)

   Chaque avis est une fonction PURE qui rend [sujet, corps], testée comme
   les avis de sécurité ; les avertir_*() qui suivent ne font qu'envoyer.

   Le motif d'un blocage est du texte tapé par un humain : corps_html() ne
   le rend jamais en lien (seules les adresses du site le deviennent), même
   s'il contient une adresse. Rien ici ne dit « cliquez » vers autre chose
   que le site.
   --------------------------------------------------------------------- */

/**
 * Le compte passe en consultation seule. Le motif est dit tel quel : c'est
 * ce que l'administrateur a écrit POUR la personne, et ce qu'elle lira aussi
 * sur sa bibliothèque.
 *
 * @return array{0: string, 1: string} le sujet et le corps
 */
function avis_compte_bloque(string $identifiant, string $raison): array
{
    return ['Votre compte est passé en consultation seule', "Bonjour {$identifiant},\n\n"
        . "L'administrateur a placé votre compte Ma Bibliothèque Manga en consultation seule.\n\n"
        . 'Raison : ' . trim($raison) . "\n\n"
        . "Ce que cela change : vous pouvez toujours vous connecter, consulter votre "
        . "bibliothèque, l'exporter et gérer votre compte. Vous ne pouvez plus ajouter, "
        . "modifier ni supprimer de séries, ni importer de sauvegarde, ni chercher de "
        . "couvertures.\n\n"
        . "Si vous pensez qu'il s'agit d'une erreur, écrivez à l'administrateur : " . ADMIN_EMAIL];
}

/**
 * Le compte devient « illimité ». Un message pour des amis : le ton est
 * voulu, il ne sert à rien d'y chercher du sérieux.
 */
function avis_forfait_illimite(string $identifiant): array
{
    return ['Bienvenue dans la dynastie sylvestrique', "Salutations, noble {$identifiant},\n\n"
        . "Par la présente, vous avez l'honneur d'intégrer la dynastie sylvestrique.\n\n"
        . "Soyez heureux : ce jour marque le commencement d'une nouvelle ère sylvique. Par la "
        . "volonté de la grande déesse du Sylve, vous êtes devenu un être supérieur.\n\n"
        . "Vos privilèges, gravés dans l'écorce du plus ancien des chênes :\n"
        . "- plus aucune limite de séries : votre bibliothèque s'étendra comme une forêt sans lisière ;\n"
        . "- le respect éternel de vos tomes en cours, de vos étagères et de vos feuilles mortes.\n\n"
        . "(Aucun sacrifice n'est exigé. Un petit merci à la déesse suffira.)\n\n"
        . "Votre bibliothèque vous attend, au pied des grands arbres :\n"
        . url_publique('index.php') . "\n\n"
        . "Que la sève vous soit propice,\n"
        . 'Le Conseil des Anciens du Sylve'];
}

/**
 * Retour au forfait standard : soit le compte est rétabli (il était bloqué),
 * soit il perd l'illimité. $ancien est le forfait d'AVANT.
 */
function avis_forfait_standard(string $identifiant, string $ancien): array
{
    if ($ancien === 'bloque') {
        return ['Votre compte est rétabli', "Bonjour {$identifiant},\n\n"
            . "Bonne nouvelle : l'administrateur a rétabli votre compte Ma Bibliothèque Manga. "
            . "Vous pouvez de nouveau ajouter, modifier et supprimer des séries, importer une "
            . "sauvegarde et chercher des couvertures.\n\n"
            . url_publique('index.php')];
    }
    return ['Votre compte repasse au forfait standard', "Bonjour {$identifiant},\n\n"
        . 'Votre compte Ma Bibliothèque Manga repasse au forfait standard : la limite est de '
        . MAX_SERIES_PAR_UTILISATEUR . " séries.\n\n"
        . "Vous conservez toutes celles que vous avez déjà : la limite ne joue que pour les "
        . "ajouts.\n\n"
        . "Une question ? Écrivez à l'administrateur : " . ADMIN_EMAIL];
}

/** L'avis qui correspond au forfait donné ($nouveau), par rapport à celui d'avant ($ancien). */
function avis_forfait(string $identifiant, string $nouveau, string $ancien, string $raison = ''): array
{
    return match ($nouveau) {
        'bloque'   => avis_compte_bloque($identifiant, $raison),
        'illimite' => avis_forfait_illimite($identifiant),
        default    => avis_forfait_standard($identifiant, $ancien),
    };
}

/**
 * Le message envoyé à ADMIN_EMAIL, qui porte le lien de confirmation d'une
 * suppression demandée depuis admin.php. Rien n'est supprimé avant le clic.
 *
 * L'identifiant, l'adresse et le nom de l'administrateur sont des données de
 * comptes : ils restent du texte (corps_html ne lie que les adresses du site).
 * Seul $lien — fabriqué par url_publique() — devient cliquable.
 */
function avis_suppression_a_confirmer(string $admin, string $identifiant, string $email, int $nb_series, string $lien, int $duree): array
{
    return ['Confirmer la suppression du compte ' . $identifiant, "Bonjour,\n\n"
        . "{$admin} demande la suppression du compte « {$identifiant} » ({$email}, {$nb_series} série(s)) "
        . "depuis l'administration de Ma Bibliothèque Manga.\n\n"
        . "Rien n'a encore été supprimé. Pour confirmer, ouvrez ce lien en étant connecté "
        . "comme administrateur, puis validez la page qui s'affiche :\n"
        . $lien . "\n\n"
        . 'Il est valable ' . secondes_lisibles($duree) . " et ne sert qu'une fois. La suppression "
        . "est définitive : le compte, sa bibliothèque et ses images seront effacés.\n\n"
        . "Si vous n'êtes pas à l'origine de cette demande, ne cliquez pas : le lien s'éteindra "
        . "tout seul. Quelqu'un utilise peut-être un compte administrateur qui n'est pas le sien."];
}

/** Le compte vient d'être supprimé par l'administrateur : on le dit à son titulaire. */
function avis_compte_supprime_par_admin(string $identifiant): array
{
    return ['Votre compte a été supprimé', "Bonjour {$identifiant},\n\n"
        . "L'administrateur a supprimé votre compte Ma Bibliothèque Manga, ainsi que "
        . "l'intégralité de la bibliothèque qui lui était rattachée.\n\n"
        . "Cette suppression est définitive : rien n'a été conservé.\n\n"
        . "Si vous pensez qu'il s'agit d'une erreur, écrivez à l'administrateur : " . ADMIN_EMAIL];
}

/**
 * Un ou plusieurs nouveaux tomes sont disponibles pour ce compte : UN seul
 * message, quel que soit le nombre de séries. Envoyé par le cron
 * (purger.php), à l'adresse confirmée du compte, s'il n'a pas désactivé ces
 * messages dans ses Paramètres.
 *
 * $tomes : une entrée par série, ['titre' => …, 'tome' => le tome à emprunter].
 * Le sujet ne porte AUCUN titre : c'est une donnée du compte, et un en-tête
 * n'a pas à en dépendre. Les titres sont du texte dans le corps (corps_html ne
 * fait des liens que des adresses du site), ramenés sur une seule ligne.
 *
 * @return array{0: string, 1: string} le sujet et le corps
 */
function avis_nouveaux_tomes(string $identifiant, array $tomes): array
{
    $n      = count($tomes);
    $sujet  = $n === 1 ? 'Un nouveau tome est disponible' : $n . ' nouveaux tomes sont disponibles';
    $lignes = '';
    foreach ($tomes as $t) {
        $titre   = trim((string) preg_replace('/\s+/u', ' ', (string) ($t['titre'] ?? '')));
        $lignes .= '- « ' . $titre . ' » : tome ' . (int) ($t['tome'] ?? 0) . "\n";
    }

    return [$sujet, "Bonjour {$identifiant},\n\n"
        . ($n === 1
            ? "Un nouveau tome est disponible pour une série de votre bibliothèque :\n"
            : "De nouveaux tomes sont disponibles pour des séries de votre bibliothèque :\n")
        . $lignes . "\n"
        . ($n === 1 ? "Sa couverture est déjà en place :\n" : "Leurs couvertures sont déjà en place :\n")
        . url_publique('index.php') . "\n\n"
        . "Vous ne voulez plus recevoir ces messages ? Désactivez-les dans vos Paramètres :\n"
        . url_publique('parametres.php#notifications')];
}

/** Prévient le titulaire que de nouveaux tomes sont disponibles. true si le message est parti. */
function avertir_nouveaux_tomes(string $email, string $identifiant, array $tomes): bool
{
    [$sujet, $corps] = avis_nouveaux_tomes($identifiant, $tomes);
    return envoyer_email($email, $sujet, $corps);
}

/** Prévient le titulaire que son forfait change. true si le message est parti. */
function avertir_forfait_change(string $email, string $identifiant, string $nouveau, string $ancien, string $raison = ''): bool
{
    [$sujet, $corps] = avis_forfait($identifiant, $nouveau, $ancien, $raison);
    return envoyer_email($email, $sujet, $corps);
}

/** Envoie à ADMIN_EMAIL le lien qui confirme une suppression. true si le message est parti. */
function demander_confirmation_suppression(string $admin, string $identifiant, string $email, int $nb_series, string $jeton): bool
{
    [$sujet, $corps] = avis_suppression_a_confirmer(
        $admin, $identifiant, $email, $nb_series,
        url_publique('admin.php?supprimer=' . $jeton), ADMIN_SUPPRESSION_DUREE
    );
    return envoyer_email(ADMIN_EMAIL, $sujet, $corps);
}

/** Prévient le titulaire que l'administrateur a supprimé son compte. true si le message est parti. */
function avertir_compte_supprime_par_admin(string $email, string $identifiant): bool
{
    [$sujet, $corps] = avis_compte_supprime_par_admin($identifiant);
    return envoyer_email($email, $sujet, $corps);
}
