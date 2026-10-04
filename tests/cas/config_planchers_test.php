<?php
/* =====================================================================
   Les planchers codés en dur des constantes.

   Tout se règle dans le .env, mais chaque valeur a un plancher écrit
   dans le code : une faute de frappe, un « 0 » ou une ligne vide ne doit
   pas pouvoir désarmer une protection ni rendre le site inutilisable.

   Ce fichier impose un .env HOSTILE — des valeurs absurdement basses —
   et vérifie que le code les relève. Les constantes étant figées au
   chargement, ce scénario ne peut vivre que dans son propre processus.
   ===================================================================== */

declare(strict_types=1);

putenv('MDP_MIN=3');                   // en dessous, la politique ne protège plus rien
putenv('MDP_MAX=2');                   // un maximum sous le minimum : incohérent
putenv('LIMITEUR_FENETRE=10');
putenv('LIMITEUR_BLOCAGE_MAX=1');
putenv('LIMITEUR_OUBLI=5');
putenv('CONNEXION_MAX_ESSAIS=0');      // 0 = bloquer tout le monde d emblée
putenv('CONNEXION_BLOCAGE=0');
putenv('CONNEXION_MAX_ESSAIS_COMPTE=0');
putenv('MDP_CONFIRM_MAX=0');
putenv('INSCRIPTION_MAX=0');
putenv('MDP_OUBLIE_MAX=0');
putenv('VERIF_RENVOI_MAX=0');
putenv('MAX_UTILISATEURS=0');
putenv('MAX_SERIES_PAR_UTILISATEUR=0');
putenv('MAX_APPAREILS=0');
putenv('TOME_MAX=0');
putenv('IMAGE_TAILLE_MAX=100');        // 100 octets : plus aucune image ne passerait
putenv('IMAGE_COTE_MAX=1');
putenv('IMAGE_QUALITE=0');
putenv('IMAGE_PIXELS_MAX=1');
putenv('IMPORT_TAILLE_MAX=1');
putenv('SMTP_TIMEOUT=0');
putenv('SESSION_DUREE=-99');
putenv('REMEMBER_DUREE_VIP=-99');
putenv('QUOTA_BASE_MO=-5');
putenv('CRON_HEURES=0');                  // 0 h : aucun passage ne serait jamais « à l heure »
putenv('RAPPORT_HEURES=-3');
putenv('CLE_ACCES_MAX=0');                // 0 clé : la fonction deviendrait muette sans le dire
putenv('CLE_ACCES_DEFI_DUREE=1');         // 1 s : on n'aurait pas le temps de choisir sa clé
putenv('ADMIN_SUPPRESSION_DUREE=1');     // 1 s : le lien de confirmation serait mort avant d'être lu
putenv('SERIES_PAGES_MAX=-5');            // jamais un nombre négatif : lu comme 0, une seule page
putenv('NOUVEAUTE_MINUTES=0');            // 0 min : MangaDex interrogé à chaque page, pour chaque série
putenv('NOUVEAUTE_PUBLICATION_JOURS=0');  // 0 jour : l'état de publication relu à chaque passage, pour toutes les séries
putenv('NOUVEAUTE_MAX_VISITE=0');         // 0 : sans limite de nombre (le budget de temps garde)
putenv('NOUVEAUTE_MAX_CRON=-5');          // jamais un nombre négatif : lu comme 0
putenv('PUSH_TTL=0');                     // un message non remis serait jeté à l'instant
putenv('PUSH_TIMEOUT=0');                 // un envoi qui échoue avant d'avoir commencé
putenv('APP_URL=https://exemple.test/bibliotheque/');   // avec un / final

require __DIR__ . '/../lanceur.php';

groupe('Politique de mot de passe');

test('MDP_MIN ne descend jamais sous 8', function () {
    /* Quoi qu'on mette dans le .env : en dessous de 8 caractères, la
       politique ne protège plus de rien. */
    egale(8, MDP_MIN, 'le .env demandait 3');
});

test('MDP_MAX reste au-dessus de MDP_MIN', function () {
    // Un maximum inférieur au minimum rendrait TOUT mot de passe invalide :
    // plus personne ne pourrait s'inscrire ni changer de mot de passe.
    vrai(MDP_MAX > MDP_MIN, 'le maximum dépasse le minimum');
    egale(9, MDP_MAX, 'le .env demandait 2, on obtient MDP_MIN + 1');
});

test('le texte d aide ne peut pas mentir sur la règle appliquée', function () {
    /* Le message de longueur est construit à partir de MDP_MIN, jamais
       écrit en dur : il suit donc toujours la règle réellement appliquée. */
    contient((string) MDP_MIN, implode(' ', valider_mot_de_passe('Aa1!')), 'le message cite le vrai minimum');
});

groupe('Limiteur anti force brute');

test('la fenêtre de comptage garde une durée utile', function () {
    /* Attention à ne pas confondre le PLANCHER (60 s, codé en dur) et le
       DÉFAUT (900 s, appliqué seulement quand la clé est absente du .env).
       Ici la clé est présente avec 10 s : c'est le plancher qui joue. */
    egale(60, LIMITEUR_FENETRE, 'le .env demandait 10 s, relevé au plancher de 60 s');
});

test('le plafond de blocage ne tombe pas à rien', function () {
    vrai(LIMITEUR_BLOCAGE_MAX >= 60, 'au moins une minute');
});

test('le délai d oubli des récidives reste long', function () {
    /* Trop court, une IP insistante repartirait de zéro chaque nuit et
       le doublement de la durée ne monterait jamais. */
    vrai(LIMITEUR_OUBLI >= 3600, 'au moins une heure');
});

test('aucun compteur ne peut bloquer dès le premier essai', function () {
    // Un « 0 » dans le .env verrouillerait le site pour tout le monde.
    vrai(CONNEXION_MAX_ESSAIS >= 1, 'connexion par IP');
    vrai(CONNEXION_MAX_ESSAIS_COMPTE >= 1, 'connexion par compte');
    vrai(MDP_CONFIRM_MAX >= 1, 'confirmation par mot de passe');
    vrai(INSCRIPTION_MAX >= 1, 'inscription');
    vrai(MDP_OUBLIE_MAX >= 1, 'mot de passe oublié');
    vrai(VERIF_RENVOI_MAX >= 1, 'renvoi du lien de confirmation');
});

test('les durées de blocage restent non nulles', function () {
    vrai(CONNEXION_BLOCAGE >= 1, 'une durée de blocage de 0 s ne bloquerait rien');
});

groupe('Quotas');

test('les quotas gardent au moins une unité', function () {
    vrai(MAX_UTILISATEURS >= 1, 'au moins un compte possible');
    vrai(MAX_SERIES_PAR_UTILISATEUR >= 1, 'au moins une série possible');
    vrai(MAX_APPAREILS >= 1, 'au moins un appareil mémorisable');
    vrai(TOME_MAX >= 1, 'au moins le tome 1');
});

groupe('Images');

test('la taille maximale reste exploitable', function () {
    /* 100 octets laisserait passer zéro image : le message d'erreur
       serait incompréhensible et l'application inutilisable. */
    egale(65536, IMAGE_TAILLE_MAX, 'plancher de 64 Ko');
    vrai(IMPORT_TAILLE_MAX >= 65536, 'idem pour l import d une sauvegarde');
});

test('le côté maximal reste visible', function () {
    egale(100, IMAGE_COTE_MAX, 'plancher de 100 px, pas 1 px');
});

test('la qualité reste dans la plage 1-100', function () {
    vrai(IMAGE_QUALITE >= 1 && IMAGE_QUALITE <= 100, 'IMAGE_QUALITE est bornée');
    egale(1, IMAGE_QUALITE, 'le .env demandait 0');
});

test('le plafond de pixels garde un plancher praticable', function () {
    vrai(IMAGE_PIXELS_MAX >= 1000000, 'au moins 1 Mpx, sinon plus aucune photo ne passe');
});

groupe('Divers');

test('le délai SMTP ne tombe pas à zéro', function () {
    // Un délai de 0 s ferait échouer tout envoi avant même la connexion.
    vrai(SMTP_TIMEOUT >= 1, 'au moins une seconde');
});

test('les durées négatives sont ramenées à zéro', function () {
    egale(0, SESSION_DUREE, 'une durée de session négative devient 0');
    egale(0, REMEMBER_DUREE_VIP, 'idem pour le jeton d appareil');
    egale(0, QUOTA_BASE_MO, 'idem pour le quota affiché dans le rapport');
});

test('le cron et son rapport valent au moins une heure', function () {
    egale(1, CRON_HEURES, 'le .env demandait 0');
    egale(1, RAPPORT_HEURES, 'le .env demandait -3');
});

test('APP_URL perd son slash final', function () {
    /* Sans ce rtrim, tous les liens envoyés par e-mail contiendraient un
       double slash : « https://site.fr//verifier-email.php ». */
    egale('https://exemple.test/bibliotheque', APP_URL, 'le / final est retiré');
    egale(
        'https://exemple.test/bibliotheque/inscription.php',
        url_publique('inscription.php'),
        'le lien construit est propre'
    );
});

groupe('Clés d\'accès');

test('CLE_ACCES_MAX ne descend jamais sous 1', function () {
    egale(1, CLE_ACCES_MAX, 'le .env demandait 0');
});

test('CLE_ACCES_DEFI_DUREE ne descend jamais sous une minute', function () {
    egale(60, CLE_ACCES_DEFI_DUREE, 'le .env demandait 1 seconde');
});

groupe('Bibliothèque par pages');

test('SERIES_PAGES_MAX : un nombre négatif vaut 0 (pas de pages), jamais une valeur étrange', function () {
    /* Exception voulue au plancher, comme les quotas de recherche : 0 veut dire
       « toutes les séries sur une seule page ». Le réglage ne protège rien. */
    egale(0, SERIES_PAGES_MAX, 'le .env demandait -5');
});

groupe('Nouveaux tomes');

test('NOUVEAUTE_MINUTES ne descend jamais sous 60 minutes', function () {
    /* À 0, chaque arrivée sur la bibliothèque interrogerait MangaDex pour
       chaque série à jour : le débit que tous les comptes se partagent. */
    egale(60, NOUVEAUTE_MINUTES, 'le .env demandait 0');
});

test('NOUVEAUTE_PUBLICATION_JOURS ne descend jamais sous 1 jour', function () {
    /* À 0, chaque passage relirait l'état de publication de TOUTES les séries
       liées : un appel de plus chacune, à chaque fois. */
    egale(1, NOUVEAUTE_PUBLICATION_JOURS, 'le .env demandait 0');
});

test('NOUVEAUTE_MAX_VISITE et NOUVEAUTE_MAX_CRON : 0 et les nombres négatifs valent « sans limite »', function () {
    /* Exception voulue au plancher, comme les quotas de recherche : c'est le
       budget de temps du relevé qui le garde (nouveautes_verifier()). */
    egale(0, NOUVEAUTE_MAX_VISITE, 'le .env demandait 0');
    egale(0, NOUVEAUTE_MAX_CRON, 'le .env demandait -5 : jamais un nombre négatif');
});

groupe('Notifications push');

test('il n\'y a plus de PUSH_MAX_APPAREILS : les notifications suivent MAX_APPAREILS', function () {
    faux(defined('PUSH_MAX_APPAREILS'), 'une seule limite d\'appareils');
    vrai(MAX_APPAREILS >= 1, 'et elle ne descend jamais sous 1 : plus personne ne pourrait activer ses notifications');
});

test('PUSH_TTL ne descend jamais sous une minute, PUSH_TIMEOUT sous une seconde', function () {
    egale(60, PUSH_TTL, 'le .env demandait 0');
    egale(1, PUSH_TIMEOUT, 'le .env demandait 0');
});

test('sans clés VAPID dans le .env, les notifications sont absentes — et c\'est tout', function () {
    egale('', VAPID_PUBLIC, 'aucune clé publique par défaut');
    egale('', VAPID_PRIVATE, 'aucune clé privée par défaut');
});

groupe('Administration');

test('ADMIN_SUPPRESSION_DUREE ne descend jamais sous 5 minutes', function () {
    egale(300, ADMIN_SUPPRESSION_DUREE, 'le .env demandait 1 seconde : le temps d\'ouvrir sa boîte mail');
});
