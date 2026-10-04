<?php
/* =====================================================================
   Génère la paire de clés VAPID du site (notifications push).

       php outils/vapid.php

   À lancer UNE fois, sur un poste qui a PHP (C:\xampp\php\php.exe sous
   Windows), puis à recopier dans le .env — celui du serveur compris :

       VAPID_PUBLIC=…
       VAPID_PRIVATE=…

   Ce dossier est à la racine du dépôt, hors de site/ : il ne monte jamais
   sur le serveur, et le .htaccess de développement ne le sert pas.

   ⚠️ La clé PRIVÉE est un secret, comme CRON_TOKEN : ne la collez ni dans un
   message, ni dans un fichier suivi par git. Régénérer la paire invalide
   tous les appareils déjà enregistrés : chacun est lié à la clé publique qu'il
   a vue, et devra réactiver ses notifications.
   ===================================================================== */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not found');
}

/* Sous Windows (XAMPP), OpenSSL ne trouve pas son openssl.cnf sans qu'on le
   lui dise : on le cherche à côté de PHP (voir push_options_cle()). */
$options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
$conf = getenv('OPENSSL_CONF');
if ($conf === false || $conf === '' || !is_readable($conf)) {
    foreach (['/extras/ssl/openssl.cnf', '/../apache/conf/openssl.cnf'] as $relatif) {
        if (is_readable(dirname(PHP_BINARY) . $relatif)) {
            $options['config'] = dirname(PHP_BINARY) . $relatif;
            break;
        }
    }
}
$cle = openssl_pkey_new($options);
$d   = $cle === false ? false : openssl_pkey_get_details($cle);
if ($d === false || !isset($d['ec']['x'], $d['ec']['y'], $d['ec']['d'])) {
    fwrite(STDERR, "Impossible de générer la paire de clés : l'extension openssl de PHP est-elle active ?\n");
    exit(1);
}

$b64u = static fn (string $o): string => rtrim(strtr(base64_encode($o), '+/', '-_'), '=');
$public = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
$prive  = str_pad($d['ec']['d'], 32, "\0", STR_PAD_LEFT);

echo "Collez ces deux lignes dans le .env (local ET serveur) :\n\n";
echo 'VAPID_PUBLIC=' . $b64u($public) . "\n";
echo 'VAPID_PRIVATE=' . $b64u($prive) . "\n\n";
echo "La clé privée est un secret. Ne la partagez pas.\n";
