<?php
/* =====================================================================
   includes/mailer.php — les messages de l'administration.

   Chacun est une fonction PURE qui rend [sujet, corps] : on teste ce
   qu'il dit, ce qu'il ne dit pas, et ce qu'en fait le HTML — le motif d'un
   blocage et les identifiants sont du texte choisi par des humains, qui ne
   doit jamais devenir un lien ni une balise dans un message authentique du
   site.
   ===================================================================== */

declare(strict_types=1);
require __DIR__ . '/../lanceur.php';

groupe('avis_compte_bloque() — la raison est dite');

test('le message dit ce qui change, pourquoi, et à qui écrire', function () {
    [$sujet, $corps] = avis_compte_bloque('marco', 'Publicité répétée dans les titres.');
    contient('consultation seule', $sujet, 'le sujet nomme la situation');
    contient('Bonjour marco', $corps, 'adressé à la personne');
    contient('Raison : Publicité répétée dans les titres.', $corps, 'la raison saisie, telle quelle');
    contient('ne pouvez plus ajouter', $corps, 'ce qui change');
    contient('vous pouvez toujours', $corps, 'et ce qui reste');
    contient(ADMIN_EMAIL, $corps, 'le recours : écrire à l\'administrateur');
    sans("\r", $sujet . "\n", 'un sujet sur une seule ligne');
    sans("\n", $sujet, 'un sujet sur une seule ligne');
});

test('la raison est nettoyée de ses espaces de bord', function () {
    [, $corps] = avis_compte_bloque('marco', "  Spam.\n");
    contient("Raison : Spam.\n", $corps, 'sans espace ni retour en trop');
});

test('en HTML, la raison est échappée et jamais rendue en lien', function () {
    [$sujet, $corps] = avis_compte_bloque('marco', '<script>alert(1)</script> voir https://hameconnage.test/vite');
    $html = corps_html($corps, $sujet);
    sans('<script>', $html, 'aucune balise venue de la raison');
    contient('&lt;script&gt;', $html, 'elle est échappée');
    sans('href="https://hameconnage.test', $html, 'une adresse étrangère n\'est jamais un lien cliquable');
});

test('une raison sur plusieurs lignes garde ses retours', function () {
    [, $corps] = avis_compte_bloque('marco', "Première ligne\nDeuxième ligne");
    contient("Raison : Première ligne\nDeuxième ligne", $corps, 'les deux lignes');
});

groupe('avis_forfait_illimite() — le message pour les amis');

test('la dynastie sylvestrique', function () {
    [$sujet, $corps] = avis_forfait_illimite('marco');
    contient('dynastie sylvestrique', $sujet, 'dans le sujet');
    contient('Salutations, noble marco', $corps, 'on salue la personne');
    contient("avez l'honneur d'intégrer la dynastie sylvestrique", $corps, 'l\'honneur');
    contient("nouvelle ère sylvique", $corps, 'la nouvelle ère');
    contient('grande déesse du Sylve', $corps, 'la déesse');
    contient('être supérieur', $corps, 'et la personne devient un être supérieur');
});

test('et malgré tout, il dit ce qui change vraiment', function () {
    [, $corps] = avis_forfait_illimite('marco');
    contient('plus aucune limite de séries', $corps, 'plus de limite de séries');
    contient(url_publique('index.php'), $corps, 'un lien vers la bibliothèque');
    sans('mot de passe', $corps, 'rien sur un mot de passe, que le compte n\'a peut-être pas');
});

test('le lien est celui du site, et lui seul devient cliquable', function () {
    [$sujet, $corps] = avis_forfait_illimite('marco');
    $html = corps_html($corps, $sujet);
    contient('<a href="' . url_publique('index.php') . '"', $html, 'le lien du site');
    egale(1, substr_count($html, '<a href='), 'un seul lien');
});

groupe('avis_forfait_standard() — rétabli, ou retour au standard');

test('un compte bloqué qui est rétabli', function () {
    [$sujet, $corps] = avis_forfait_standard('marco', 'bloque');
    contient('rétabli', $sujet, 'le sujet');
    contient('de nouveau ajouter', $corps, 'ce qui revient');
    sans('forfait standard', $corps, 'sans parler de forfait : il n\'a rien perdu d\'autre');
});

test('un illimité qui repasse au standard', function () {
    [$sujet, $corps] = avis_forfait_standard('marco', 'illimite');
    contient('forfait standard', $sujet, 'le sujet');
    contient((string) MAX_SERIES_PAR_UTILISATEUR . ' séries', $corps, 'la limite qui s\'applique');
    contient('conservez toutes', $corps, 'et ses séries ne disparaissent pas');
    contient(ADMIN_EMAIL, $corps, 'à qui écrire');
});

groupe('avis_forfait() — le bon message pour le bon changement');

test('le forfait donné choisit le message', function () {
    egale(avis_compte_bloque('m', 'raison'), avis_forfait('m', 'bloque', 'standard', 'raison'), 'bloque');
    egale(avis_forfait_illimite('m'), avis_forfait('m', 'illimite', 'standard'), 'illimite');
    egale(avis_forfait_illimite('m'), avis_forfait('m', 'illimite', 'bloque'), 'illimite, depuis bloqué : la bonne nouvelle prime');
    egale(avis_forfait_standard('m', 'bloque'), avis_forfait('m', 'standard', 'bloque'), 'standard, depuis bloqué');
    egale(avis_forfait_standard('m', 'illimite'), avis_forfait('m', 'standard', 'illimite'), 'standard, depuis illimité');
});

test('un forfait inconnu ne fabrique pas un message de blocage', function () {
    [$sujet] = avis_forfait('m', 'gratuit', 'standard');
    sans('consultation seule', $sujet, 'jamais « bloqué » par défaut');
});

groupe('avis_suppression_a_confirmer() — le lien vers ADMIN_EMAIL');

test('rien n\'a été supprimé, et le message le dit', function () {
    $lien = url_publique('admin.php?supprimer=' . str_repeat('ab', 32));
    [$sujet, $corps] = avis_suppression_a_confirmer('marco', 'spammeur42', 'spam@exemple.test', 12, $lien, 3600);
    contient('spammeur42', $sujet, 'le sujet nomme le compte');
    contient("Rien n'a encore été supprimé", $corps, 'rien n\'est fait');
    contient('marco demande la suppression', $corps, 'qui la demande');
    contient('spam@exemple.test', $corps, 'l\'adresse du compte');
    contient('12 série(s)', $corps, 'ce qui sera perdu');
    contient($lien, $corps, 'le lien de confirmation');
    contient(secondes_lisibles(3600), $corps, 'sa durée de validité, dite comme partout ailleurs');
    contient('définitive', $corps, 'et son caractère définitif');
    contient('ne cliquez pas', $corps, 'et quoi faire si on n\'y est pour rien');
});

test('seul le lien du site est cliquable : identifiants et adresses restent du texte', function () {
    $lien = url_publique('admin.php?supprimer=' . str_repeat('ab', 32));
    [$sujet, $corps] = avis_suppression_a_confirmer('https://pirate.test', 'x', 'victime@pirate.test', 0, $lien, 600);
    $html = corps_html($corps, $sujet);
    contient('<a href="' . $lien . '"', $html, 'le lien du site');
    egale(1, substr_count($html, '<a href='), 'et lui seul : ni le nom de l\'administrateur, ni l\'adresse');
});

test('la durée dite est celle du réglage', function () {
    [, $corps] = avis_suppression_a_confirmer('m', 'x', 'x@exemple.test', 0, 'https://exemple.test/', 300);
    contient(secondes_lisibles(300), $corps, '5 minutes');
});

groupe('avis_compte_supprime_par_admin()');

test('le titulaire apprend que c\'est fait, et à qui écrire', function () {
    [$sujet, $corps] = avis_compte_supprime_par_admin('marco');
    contient('supprimé', $sujet, 'le sujet');
    contient('Bonjour marco', $corps, 'adressé à la personne');
    contient("L'administrateur a supprimé", $corps, 'qui l\'a fait');
    contient('définitive', $corps, 'irréversible');
    contient(ADMIN_EMAIL, $corps, 'à qui écrire en cas d\'erreur');
});

groupe('avertir_*() — un envoi raté se dit, il ne casse rien');

test('sans SMTP configuré (ici), chaque envoi répond false', function () {
    faux(avertir_forfait_change('marco@exemple.test', 'marco', 'bloque', 'standard', 'Spam'), 'forfait');
    faux(demander_confirmation_suppression('admin', 'marco', 'marco@exemple.test', 3, str_repeat('ab', 32)), 'confirmation de suppression');
    faux(avertir_compte_supprime_par_admin('marco@exemple.test', 'marco'), 'suppression faite');
});

test('le lien de confirmation est fabriqué sur APP_URL, jamais sur l\'en-tête Host', function () {
    $source = (string) file_get_contents(CHEMIN_SITE . '/includes/mailer.php');
    preg_match('/function demander_confirmation_suppression\(.*?\n}/s', str_replace("\r\n", "\n", $source), $m);
    contient("url_publique('admin.php?supprimer=' . \$jeton)", (string) ($m[0] ?? ''), 'url_publique() lit APP_URL');
    contient('ADMIN_EMAIL', (string) ($m[0] ?? ''), 'envoyé à l\'adresse de l\'administrateur, jamais à une saisie');
});
