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
putenv('MAIL_FILE_MAX_ESSAIS=0');
putenv('SESSION_DUREE=-99');
putenv('REMEMBER_DUREE_VIP=-99');
putenv('QUOTA_BASE_MO=-5');
putenv('CRON_HEURES=0');                  // 0 h : aucun passage ne serait jamais « à l heure »
putenv('RAPPORT_HEURES=-3');
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
    /* MDP_REGLE est construite à partir de MDP_MIN, jamais écrite en dur :
       le rappel affiché sous le champ suit donc toujours la règle réelle. */
    contient((string) MDP_MIN, MDP_REGLE, 'la règle affichée cite le vrai minimum');
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

test('un message en file a droit à au moins un essai', function () {
    vrai(MAIL_FILE_MAX_ESSAIS >= 1, 'sinon tout message serait abandonné d emblée');
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
