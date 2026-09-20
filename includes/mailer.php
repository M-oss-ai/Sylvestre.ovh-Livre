<?php
/* =====================================================================
   Envoi d'e-mail par SMTP, en PHP natif (pas de Composer/dépendance —
   même esprit que le reste du projet). Pensé pour un petit volume
   (confirmation de compte, mot de passe oublié), pas pour du mailing.
   ===================================================================== */

declare(strict_types=1);

/**
 * Envoi SMTP brut. Ne passe par AUCUN quota et ne rattrape rien :
 * appelez envoyer_email(), pas cette fonction — sauf depuis le
 * dépileur de la file (traiter_file_mail()).
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

    $entetes = implode("\r\n", [
        'From: ' . $nom . ' <' . $user . '>',
        'To: <' . $destinataire . '>',
        'Subject: =?UTF-8?B?' . base64_encode($sujet) . '?=',
        // Message-ID : plusieurs filtres anti-spam (dont ceux de Microsoft)
        // pénalisent un message qui n'en porte pas. Le domaine est celui du
        // site, pour rester cohérent avec l'adresse d'expédition.
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domaineClient . '>',
        'Auto-Submitted: auto-generated',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'Date: ' . date('r'),
    ]);
    $corpsEncode = chunk_split(base64_encode($corps));

    fwrite($flux, $entetes . "\r\n\r\n" . $corpsEncode . "\r\n.\r\n");
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
   File de rattrapage

   Un envoi raté était autrefois perdu pour de bon : l'utilisateur
   attendait un lien de confirmation qui ne viendrait jamais. Les échecs
   atterrissent désormais ici et purger.php les repasse.

   ⚠️ Il n'y a AUCUN plafond d'envoi applicatif. Le seul frein restant est
   le limiteur par IP des pages qui déclenchent un envoi (inscription,
   mot de passe oublié, renvoi de confirmation) — qu'une attaque
   distribuée contourne par construction. La limite d'envoi journalière
   de la boîte OVH est donc devenue la seule borne réelle, et quand elle
   est atteinte, plus RIEN ne part : ni inscription, ni réinitialisation,
   ni avis de sécurité. Surveillez le journal d'erreurs.
   --------------------------------------------------------------------- */

/** Dépose un message dans la file, pour que le cron le repasse. */
function empiler_mail(string $destinataire, string $sujet, string $corps): void
{
    global $pdo;
    try {
        $pdo->prepare('INSERT INTO mail_file (destinataire, sujet, corps) VALUES (?, ?, ?)')
            ->execute([$destinataire, mb_substr($sujet, 0, 255, 'UTF-8'), $corps]);
    } catch (Throwable $e) {
        error_log('empiler_mail: ' . $e->getMessage());
    }
}

/**
 * Envoie un e-mail — c'est CETTE fonction que le reste du site appelle.
 *
 * Déroulé : tentative immédiate (l'utilisateur doit recevoir son lien de
 * confirmation tout de suite, pas à la prochaine heure), et si le serveur
 * SMTP ne répond pas, le message part en file. purger.php la vide au
 * passage suivant du cron.
 */
function envoyer_email(string $destinataire, string $sujet, string $corps): bool
{
    if (!filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if (envoyer_email_smtp($destinataire, $sujet, $corps)) {
        return true;
    }

    empiler_mail($destinataire, $sujet, $corps);
    return false;
}

/**
 * Rejoue les messages en attente. Appelée par purger.php (cron).
 * Retourne [envoyés, abandonnés].
 *
 * $max borne le travail d'une exécution : la file est censée rester
 * vide, mais si le serveur SMTP a été indisponible une journée entière,
 * il ne faut pas que le cron y passe son temps d'exécution maximum.
 */
function traiter_file_mail(int $max = 50): array
{
    global $pdo;

    $req = $pdo->prepare(
        'SELECT id, destinataire, sujet, corps, essais
           FROM mail_file ORDER BY id LIMIT ' . max(1, $max)
    );
    $req->execute();
    $lignes = $req->fetchAll();

    $envoyes = 0;
    $abandons = 0;

    foreach ($lignes as $m) {
        if (envoyer_email_smtp($m['destinataire'], $m['sujet'], $m['corps'])) {
            $pdo->prepare('DELETE FROM mail_file WHERE id = ?')->execute([(int) $m['id']]);
            $envoyes++;
            continue;
        }
        if ((int) $m['essais'] + 1 >= MAIL_FILE_MAX_ESSAIS) {
            $pdo->prepare('DELETE FROM mail_file WHERE id = ?')->execute([(int) $m['id']]);
            error_log('traiter_file_mail: message abandonné après '
                . MAIL_FILE_MAX_ESSAIS . ' essais (id=' . (int) $m['id'] . ')');
            $abandons++;
            continue;
        }
        $pdo->prepare('UPDATE mail_file SET essais = essais + 1 WHERE id = ?')
            ->execute([(int) $m['id']]);
        // Le serveur SMTP est visiblement en panne : inutile d'insister
        // sur les suivants dans la même exécution.
        break;
    }

    return [$envoyes, $abandons];
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

function avertir_mot_de_passe_change(string $email, string $identifiant): void
{
    envoyer_email(
        $email,
        'Votre mot de passe a été modifié',
        "Bonjour {$identifiant},

"
        . "Le mot de passe de votre compte Ma Bibliothèque Manga vient d'être modifié, "
        . "et tous vos autres appareils ont été déconnectés.

"
        . "Si vous êtes à l'origine de ce changement, vous n'avez rien à faire.

"
        . "SINON, votre compte est compromis : reprenez-en le contrôle immédiatement "
        . "en demandant un nouveau mot de passe ici :
"
        . url_publique('mot-de-passe-oublie.php')
    );
}

/**
 * Prévient qu'un compte vient d'être supprimé.
 *
 * C'est l'action la plus irréversible du site, et c'était la seule
 * sensible qui ne laissait aucune trace chez le titulaire : changer son
 * mot de passe le prévient, demander un changement d'adresse aussi, mais
 * tout effacer se faisait en silence.
 *
 * Envoyé APRÈS la suppression, et seulement si elle a réussi. La file de
 * rattrapage (mail_file) ne référence aucun compte : un envoi différé
 * survit donc à la disparition de celui-ci.
 */
function avertir_compte_supprime(string $email, string $identifiant): void
{
    envoyer_email(
        $email,
        'Votre compte a été supprimé',
        "Bonjour {$identifiant},

"
        . "Le compte Ma Bibliothèque Manga associé à cette adresse vient d'être supprimé, "
        . "ainsi que l'intégralité de la bibliothèque qui lui était rattachée.

"
        . "Cette suppression est définitive : rien n'a été conservé, et le contenu ne peut "
        . "pas être restauré.

"
        . "Si vous êtes à l'origine de cette suppression, vous n'avez rien à faire. Vous "
        . "pouvez créer un nouveau compte à tout moment :
"
        . url_publique('inscription.php') . "

"
        . "SINON, quelqu'un connaissait votre mot de passe — la suppression l'exige. Le "
        . "compte étant effacé, il n'y a plus rien à sécuriser ici, mais si vous utilisiez "
        . "ce mot de passe ailleurs, changez-le sur ces autres sites sans attendre."
    );
}

function avertir_changement_email_demande(string $ancien_email, string $identifiant, string $nouveau_email): void
{
    // L'adresse visée n'est que partiellement affichée : cet e-mail peut
    // finir sous d'autres yeux que ceux du titulaire.
    $masque = preg_replace('/^(.).*(.@)/u', '$1***$2', $nouveau_email) ?? '***';

    envoyer_email(
        $ancien_email,
        "Demande de changement d'adresse e-mail",
        "Bonjour {$identifiant},

"
        . "Quelqu'un vient de demander à remplacer l'adresse e-mail de votre compte "
        . "par {$masque}.

"
        . "Cette adresse-ci reste active tant que la nouvelle n'a pas été confirmée : "
        . "si vous êtes à l'origine de la demande, ouvrez simplement le lien envoyé à "
        . "la nouvelle adresse.

"
        . "SINON, quelqu'un a votre mot de passe. Changez-le tout de suite :
"
        . url_publique('mot-de-passe-oublie.php')
    );
}

/* ---------------------------------------------------------------------
   Jetons à usage unique : confirmation d'e-mail, réinitialisation de mot
   de passe, changement d'adresse.

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
 * $donnee porte la valeur en attente de validation — pour
 * « changement_email », la nouvelle adresse.
 *
 * Retourne le jeton en clair, à mettre dans le lien.
 */
function generer_jeton_action(int $utilisateur_id, string $type, int $duree_secondes, ?string $donnee = null): string
{
    global $pdo;

    $pdo->prepare('DELETE FROM jeton_action WHERE utilisateur_id = ? AND type = ?')
        ->execute([$utilisateur_id, $type]);

    $jeton  = bin2hex(random_bytes(32));
    $expire = date('Y-m-d H:i:s', time() + $duree_secondes);

    $pdo->prepare(
        'INSERT INTO jeton_action (utilisateur_id, jeton_hash, type, donnee, expire)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$utilisateur_id, hash('sha256', $jeton), $type, $donnee, $expire]);

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
