-- =====================================================================
--  Ma Bibliothèque Manga — base de données « Livre »
--  À importer dans phpMyAdmin (onglet SQL) ou en ligne de commande :
--      mysql -u root < livre.sql
--
--  Base DÉJÀ installée ? Ce fichier peut être rejoué sans risque : chaque
--  instruction est en « CREATE TABLE IF NOT EXISTS », donc les tables
--  existantes sont laissées intactes et seules les nouvelles sont créées.
--  Faites tout de même une sauvegarde avant (phpMyAdmin → Exporter).
-- =====================================================================

-- ---------------------------------------------------------------------
--  ⚠️ CES DEUX INSTRUCTIONS SONT POUR LE DÉVELOPPEMENT LOCAL UNIQUEMENT.
--
--  SUPPRIMEZ-LES avant d'exécuter ce fichier chez OVH (ou tout autre
--  hébergement mutualisé) : la base y est créée depuis le manager, elle
--  porte un nom imposé, et votre utilisateur MySQL n'a pas le droit d'en
--  créer une. Sans cette suppression, ces deux lignes échouent — et
--  comme le « USE » échoue avec elles, TOUT le reste du fichier échoue
--  aussi. On croit avoir installé le schéma, la base est vide.
--
--  Chez OVH : phpMyAdmin → sélectionnez votre base dans la colonne de
--  gauche → onglet SQL → collez le fichier SANS ces deux instructions.
-- ---------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `Livre`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `Livre`;
-- --------------------- fin de la partie à supprimer -------------------

-- ---------------------------------------------------------------------
--  Comptes utilisateurs
--  Le mot de passe n'est JAMAIS stocké en clair : uniquement un hachage
--  bcrypt produit par password_hash() (60 caractères, 255 par sécurité
--  si PHP change d'algorithme par défaut).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `utilisateur` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifiant`  VARCHAR(50)  NOT NULL,
  `email`        VARCHAR(190) NOT NULL,
  `email_verifie` TINYINT(1)  NOT NULL DEFAULT 0,
  `mot_de_passe` VARCHAR(255) NOT NULL,
  `photo`        VARCHAR(500) NOT NULL DEFAULT '',
  -- Forfait du compte : « standard » = limité à MAX_SERIES_PAR_UTILISATEUR
  -- (voir .env), « illimite » = pas de limite de séries, « bloque » =
  -- consultation seule (ni création, ni modification, ni favori, ni tome,
  -- ni import, ni suppression : voir ACTIONS_BLOQUEES dans fonctions.php).
  -- Pas de paiement en ligne dans l'appli : un changement de forfait se fait
  -- depuis admin.php (qui prévient l'intéressé par e-mail), ou à la main en
  -- base (l'utilisateur doit contacter l'administrateur, voir ADMIN_EMAIL) :
  --     UPDATE utilisateur SET forfait = 'illimite' WHERE identifiant = '...';
  --     UPDATE utilisateur SET forfait = 'bloque'   WHERE identifiant = '...';
  --     UPDATE utilisateur SET forfait = 'standard' WHERE identifiant = '...';
  `forfait`      ENUM('standard','illimite','bloque') NOT NULL DEFAULT 'standard',
  -- Administrateur : peut ouvrir admin.php. Une colonne à part du forfait :
  -- on peut être bloqué ET administrateur. Le premier se pose à la main :
  --     UPDATE utilisateur SET admin = 1 WHERE identifiant = '...';
  `admin`        TINYINT(1)   NOT NULL DEFAULT 0,
  -- Le motif saisi en bloquant le compte (repris dans l'e-mail), et la date.
  -- Vides quand le compte n'est pas bloqué.
  `raison_blocage` VARCHAR(500) NOT NULL DEFAULT '',
  `bloque_le`    DATETIME     NULL DEFAULT NULL,
  -- Incrémenté à chaque changement de mot de passe. Une session PHP porte
  -- la valeur qu'elle a vue à la connexion : dès qu'elles diffèrent, la
  -- session est rejetée. C'est ce qui déconnecte RÉELLEMENT les autres
  -- appareils quand on change son mot de passe (voir utilisateur_actuel()).
  `session_version` INT UNSIGNED NOT NULL DEFAULT 0,
  -- L'utilisateur a-t-il declare etre majeur ? Sert uniquement a la
  -- recherche automatique de couverture : sans cette declaration, les
  -- series classees « erotica » par MangaDex ne lui sont pas proposees.
  -- N'a aucun effet si COUVERTURE_CONTENU_ADULTE vaut 0 dans le .env,
  -- ce qui est le reglage par defaut.
  -- Deux colonnes et non une, parce que ce sont deux choses :
  --   adulte_confirme : l'utilisateur a declare etre majeur. Un fait
  --     acquis, qu'on ne redemande pas.
  --   filtre_sensible : le filtre est-il actif en ce moment ? Une
  --     preference, que l'on rebascule quand on veut — mais seulement
  --     apres avoir declare sa majorite.
  -- Les fusionner obligerait a redemander la declaration a chaque fois
  -- que l'utilisateur reactive le filtre, ce qui n'aurait aucun sens.
  `adulte_confirme` TINYINT(1) NOT NULL DEFAULT 0,
  `filtre_sensible` TINYINT(1) NOT NULL DEFAULT 1,
  -- Identifiant du compte Google relié (revendication « sub » d'OpenID
  -- Connect), NULL sinon. C'est lui, et non l'adresse, qui reconnaît le
  -- compte Google : une adresse peut changer, le « sub » jamais.
  -- Un compte créé par Google n'a pas de mot de passe : `mot_de_passe`
  -- y vaut '' (chaîne vide), que password_verify() refuse toujours.
  `google_sub`   VARCHAR(255) NULL DEFAULT NULL,
  `cree_le`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_utilisateur_identifiant` (`identifiant`),
  UNIQUE KEY `uk_utilisateur_email` (`email`),
  UNIQUE KEY `uk_utilisateur_google` (`google_sub`),
  -- supprimer_image_locale() cherche « qui référence encore ce fichier ? » :
  -- sans cet index, c'est un parcours complet de la table à chaque
  -- suppression d'image (et il y en a une par série supprimée).
  KEY `idx_utilisateur_photo` (`photo`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Jetons à usage unique : confirmation d'e-mail, réinitialisation de mot
--  de passe, changement d'adresse.
--
--  Le jeton n'est PAS stocké en clair : uniquement son empreinte sha256,
--  comme un mot de passe. Quelqu'un qui lirait cette table (sauvegarde
--  égarée, injection ailleurs) ne pourrait donc pas s'en servir pour
--  réinitialiser un mot de passe.
--
--  Une ligne par jeton, et non un seul « slot » par compte : demander une
--  confirmation d'e-mail n'annule plus une réinitialisation en cours.
--
--  `donnee` porte la valeur en attente de validation — pour le type
--  « changement_email », la nouvelle adresse. Elle n'est écrite dans
--  utilisateur.email qu'au clic sur le lien : tant que la nouvelle adresse
--  n'est pas confirmée, l'ancienne reste celle du compte. Une faute de
--  frappe ne peut donc pas rendre un compte irrécupérable.
--
--  « blocage_email » est le lien envoyé à l'ANCIENNE adresse avec l'alerte
--  de changement : il bloque ce changement (ou le défait) et fait choisir
--  un nouveau mot de passe. `donnee` y porte l'adresse à rétablir.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `jeton_action` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `utilisateur_id` INT UNSIGNED NOT NULL,
  `jeton_hash`     CHAR(64) NOT NULL,
  `type`           ENUM('verification','reinit','changement_email','blocage_email','suppression_admin') NOT NULL,
  `donnee`         VARCHAR(190) NULL,
  `expire`         DATETIME NOT NULL,
  `cree_le`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_jeton_action_hash` (`jeton_hash`),
  KEY `idx_jeton_action_utilisateur` (`utilisateur_id`, `type`),
  KEY `idx_jeton_action_expire` (`expire`),
  CONSTRAINT `fk_jeton_action_utilisateur`
    FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateur` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Séries
--  Pas de table « tome » : le tome courant est une simple colonne, et le
--  prochain tome à emprunter est calculé (tome_actuel + 1), jamais stocké.
--  ON DELETE CASCADE : supprimer un compte supprime sa bibliothèque.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `serie` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `utilisateur_id` INT UNSIGNED NOT NULL,
  `titre`          VARCHAR(190) NOT NULL,
  `auteur`         VARCHAR(190) NOT NULL DEFAULT '',
  `tome_actuel`    INT UNSIGNED NOT NULL DEFAULT 0,
  -- « attente » : arrivé au dernier tome paru d'une série qui continue (voir la
  -- migration 15). Ajouté EN FIN de liste : l'ordre d'affichage est celui de
  -- STATUTS (includes/fonctions.php), pas celui de l'ENUM.
  `statut`         ENUM('cours','envie','termine','abandon','attente') NOT NULL DEFAULT 'cours',
  `couverture`     VARCHAR(500) NOT NULL DEFAULT '',
  -- Serie correspondante chez MangaDex, une fois que l'utilisateur
  -- l'a designee. Tant qu'elle est renseignee, la couverture suit
  -- automatiquement le tome : avancer d'un tome va chercher la bonne
  -- image sans rien redemander ni redeviner.
  -- Vide des que l'utilisateur choisit une image d'une autre source :
  -- son choix prime, et un lien qui ecraserait son image serait un
  -- piege. Un identifiant MangaDex est un UUID, donc 36 caracteres.
  `mangadex_id`    CHAR(36)     NOT NULL DEFAULT '',
  -- Serie mise en favori par son proprietaire. Un simple drapeau :
  -- l'ordre d'affichage n'en depend pas, seul le filtre s'en sert.
  `favori`         TINYINT(1)   NOT NULL DEFAULT 0,
  -- Nouveaux tomes (voir includes/nouveautes.php et la migration 13) :
  --   dernier_tome : le plus haut tome qui a une couverture chez MangaDex,
  --                  tel qu'on l'a vu la dernière fois (0 = pas encore su) ;
  --   verifie_le   : la dernière fois qu'on a interrogé MangaDex pour cette
  --                  série (NULL = jamais) ;
  --   nouveau_tome : le tome à emprunter dont l'arrivée n'a pas encore été
  --                  annoncée à l'écran (0 = rien à annoncer).
  `dernier_tome`   INT UNSIGNED NOT NULL DEFAULT 0,
  `verifie_le`     DATETIME     NULL DEFAULT NULL,
  `nouveau_tome`   INT UNSIGNED NOT NULL DEFAULT 0,
  -- Etat de publication que MangaDex donne a la serie (migration 16) :
  -- ongoing, completed, hiatus, cancelled, ou vide (pas encore su). Range
  -- quand on arrive au dernier tome ; la carte en tire « Se termine au tome N »,
  -- « Arretee au tome N », « En pause au tome N » ou « Tome N en attente ».
  `publication`    VARCHAR(12)  NOT NULL DEFAULT '',
  `cree_le`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `maj_le`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_serie_utilisateur` (`utilisateur_id`),
  -- La bibliothèque est affichée triée par « dernière modification » (maj_le
  -- DESC) : cet index composite couvre à la fois le WHERE utilisateur_id = ?
  -- et le ORDER BY en un seul parcours.
  KEY `idx_serie_utilisateur_maj` (`utilisateur_id`, `maj_le`),
  -- Voir idx_utilisateur_photo : évite le parcours complet de table dans
  -- supprimer_image_locale(), appelée en boucle par « vider la bibliothèque ».
  KEY `idx_serie_couverture` (`couverture`(191)),
  CONSTRAINT `fk_serie_utilisateur`
    FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateur` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pas d'index sur `titre` : la recherche est entièrement côté navigateur
-- (js/commun.js), aucune requête ne filtre dessus. Un index inutilisé ne
-- sert à rien mais ralentit chaque écriture.

-- La collection démarre vide : aucune donnée d'exemple n'est insérée.
-- Créez votre compte depuis la page « inscription.php ».

-- ---------------------------------------------------------------------
--  Anti-bruteforce (connexion) et anti-spam (inscription), par IP.
--  Stocké en base plutôt qu'en session : un script qui n'envoie pas de
--  cookie (donc change de session à chaque requête) ne peut pas
--  contourner le blocage.
--
--  `blocages` compte les blocages successifs : la durée double à chaque
--  fois (1 min, 2, 4, 8… plafonnée à 1 h). Un attaquant patient n'a plus
--  5 essais par minute indéfiniment ; un utilisateur qui se trompe une
--  fois ne subit qu'une minute.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tentative_ip` (
  `ip`           VARCHAR(45)  NOT NULL,
  `action`       VARCHAR(30)  NOT NULL,
  `essais`       INT UNSIGNED NOT NULL DEFAULT 0,
  `blocages`     INT UNSIGNED NOT NULL DEFAULT 0,
  `bloque_jusqu` DATETIME     NULL,
  `maj_le`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ip`, `action`),
  -- Utilisé par purger.php pour effacer les lignes devenues inutiles :
  -- sans purge, cette table grossit indéfiniment (la base d'un forfait
  -- OVH d'entrée de gamme est plafonnée, souvent à 200 Mo).
  KEY `idx_tentative_maj` (`maj_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Connexion persistante (forfait « illimite » uniquement) : un jeton
--  longue durée par appareil, pour ne se connecter qu'une seule fois par
--  machine. Une ligne = un appareil mémorisé ; la déconnexion ne
--  supprime que celle de l'appareil courant.
--
--  Le jeton tourne à chaque connexion automatique, mais l'ancien reste
--  accepté quelques secondes (`remplace_le`) : sans ce délai, deux
--  requêtes simultanées (deux onglets, un préchargement) se disputent le
--  jeton et l'une des deux déconnecte l'utilisateur.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `session_persistante` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `utilisateur_id` INT UNSIGNED NOT NULL,
  `jeton_hash`     CHAR(64) NOT NULL,
  `expire`         DATETIME NOT NULL,
  `remplace_le`    DATETIME NULL,
  `cree_le`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_persistante_jeton` (`jeton_hash`),
  KEY `idx_session_persistante_utilisateur` (`utilisateur_id`),
  KEY `idx_session_persistante_expire` (`expire`),
  CONSTRAINT `fk_session_persistante_utilisateur`
    FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateur` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Ancienne file de rattrapage des e-mails, retirée : un envoi raté
--  n'est plus retenté (un message à une adresse fausse, bloqué en tête,
--  empêchait tous les suivants de partir). La table disparaît.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `mail_file`;

-- ---------------------------------------------------------------------
--  Migrations — bases déjà installées
--
--  À exécuter sur une base créée avant ces évolutions. Tout est
--  rejouable sans risque : chaque bloc est sans effet si la colonne
--  qu'il pose existe déjà. Sur une base neuve, ils ne font rien non
--  plus : on peut les laisser.
--
--  ⚠️ « ADD COLUMN IF NOT EXISTS » n'est PAS utilisé ici, bien qu'il
--  soit plus court : c'est une extension MariaDB, et elle échoue sur
--  MySQL d'Oracle comme sur les MariaDB antérieures à la 10.0.2. Les
--  blocs ci-dessous interrogent information_schema, puis ne construisent
--  l'ALTER que si la colonne manque. C'est plus verbeux, et cela
--  fonctionne partout.
--
--  « DO 0 » est l'instruction qui ne fait rien : c'est ce qu'on exécute
--  quand il n'y a rien à faire.
-- ---------------------------------------------------------------------

--  1. Quotas d'envoi retirés : `mail_envoye` ne servait qu'à leur
--     comptage. Plus aucun code ne l'écrit ni ne la lit, elle ne ferait
--     que grossir pour rien.
DROP TABLE IF EXISTS `mail_envoye`;

--  2. Filtre des images sensibles (recherche automatique de couverture).
--     Deux colonnes, parce que ce sont deux choses distinctes :
--       adulte_confirme : l'utilisateur a déclaré être majeur. Un fait
--         acquis, qu'on ne lui redemande pas.
--       filtre_sensible : le filtre est-il actif en ce moment ? Une
--         préférence réversible, modifiable seulement après déclaration.
--     Les valeurs par défaut sont les plus restrictives : filtre actif,
--     aucune déclaration. Les comptes existants sont donc protégés sans
--     que personne ait à intervenir.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'adulte_confirme');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `utilisateur` ADD COLUMN `adulte_confirme` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'filtre_sensible');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `utilisateur` ADD COLUMN `filtre_sensible` TINYINT(1) NOT NULL DEFAULT 1');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;

-- ---------------------------------------------------------------------
--  3. Quota de recherche de couverture, par compte et par tranche.
--
--     Une table à part plutôt qu'une ligne de « tentative_ip » : ce
--     n'est pas la même chose. « tentative_ip » enregistre des ÉCHECS
--     et double la peine à chaque récidive, ce qui convient à des mots
--     de passe essayés au hasard. Chercher une couverture est un usage
--     normal : on compte des recherches RÉUSSIES, sans escalade, et la
--     tranche suivante repart entière.
--
--     « fenetre_fin » porte la fin de la tranche en cours. Une tranche
--     échue est repartie à la première recherche suivante, sans qu'un
--     nettoyage soit nécessaire pour que le compte redevienne bon.
--
--     ON DELETE CASCADE : la ligne disparaît avec le compte, comme les
--     séries et les jetons.
-- ---------------------------------------------------------------------
--  4. Lien vers la serie correspondante chez MangaDex.
--
--     Sans lui, retrouver la couverture du tome suivant obligeait a
--     relancer une recherche complete et a redeviner quelle serie
--     etait la bonne — une vingtaine de requetes, a chaque tome.
--     Avec lui, une seule requete suffit, et elle ne se trompe pas.
--
--     Les series existantes partent sans lien : elles en obtiennent un
--     a la prochaine recherche de couverture.
--  La valeur par défaut est une chaîne vide. Dans un ALTER construit à
--  l'intérieur d'une chaîne SQL, elle s'écrit avec quatre apostrophes :
--  deux pour la chaîne vide, doublées pour survivre à la chaîne qui les
--  contient.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'serie' AND COLUMN_NAME = 'mangadex_id');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `serie` ADD COLUMN `mangadex_id` CHAR(36) NOT NULL DEFAULT ''''');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;

-- ---------------------------------------------------------------------
--  5. Favoris.
--
--     Voir la mise en garde ci-dessus : « ADD COLUMN IF NOT EXISTS »
--     n'est pas utilisable, d'ou ce detour par information_schema.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'serie' AND COLUMN_NAME = 'favori');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `serie` ADD COLUMN `favori` TINYINT(1) NOT NULL DEFAULT 0');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;

-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `recherche_couverture` (
  `utilisateur_id` INT UNSIGNED NOT NULL,
  `essais`         INT UNSIGNED NOT NULL DEFAULT 0,
  `fenetre_fin`    DATETIME     NOT NULL,
  PRIMARY KEY (`utilisateur_id`),
  -- Utilisé par purger.php pour effacer les tranches depuis longtemps
  -- échues : sans purge, une ligne subsiste par compte ayant cherché.
  KEY `idx_recherche_fenetre` (`fenetre_fin`),
  CONSTRAINT `fk_recherche_utilisateur` FOREIGN KEY (`utilisateur_id`)
    REFERENCES `utilisateur` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  Date du dernier rapport d'activité (purger.php)
--
--  Le cron passe toutes les CRON_HEURES heures, le rapport ne part que
--  toutes les RAPPORT_HEURES heures. Retenir la date du dernier envoi
--  permet de décider sans calendrier (un cron que l'hébergeur décale de
--  quelques minutes ne saute ni ne double aucun rapport) et d'afficher le
--  temps réellement écoulé : « depuis 7 j et 5 heures ».
--  Une seule ligne, id = 1.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rapport_cron` (
  `id`         TINYINT UNSIGNED NOT NULL,
  `envoye_le`  DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  6. Lien « bloquer ce changement », envoyé à l'ancienne adresse avec
--     l'alerte de changement d'adresse (voir reinitialiser-mot-de-passe.php).
--
--     Un type de jeton à part, parce qu'aucune autre demande ne doit le
--     remplacer : une réinitialisation ou un second changement d'adresse,
--     que l'attaquant peut demander lui-même, effaceraient le lien de sa
--     victime.
--
--     MODIFY réécrit la liste entière des valeurs : on ne l'exécute que
--     si la nouvelle manque, sur le même modèle que les colonnes.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'jeton_action' AND COLUMN_NAME = 'type'
              AND COLUMN_TYPE LIKE '%''blocage_email''%');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `jeton_action` MODIFY COLUMN `type` ENUM(''verification'',''reinit'',''changement_email'',''blocage_email'') NOT NULL');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;

-- ---------------------------------------------------------------------
--  7. Connexion avec Google (voir google.php).
--
--     `google_sub` : l'identifiant du compte Google relié, NULL sinon.
--     L'index UNIQUE empêche deux comptes du site de se partager le même
--     compte Google ; les NULL, eux, ne se gênent pas entre eux.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'google_sub');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `utilisateur` ADD COLUMN `google_sub` VARCHAR(255) NULL DEFAULT NULL AFTER `filtre_sensible`');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'utilisateur' AND INDEX_NAME = 'uk_utilisateur_google');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `utilisateur` ADD UNIQUE KEY `uk_utilisateur_google` (`google_sub`)');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;

-- ---------------------------------------------------------------------
--  8. Prénom et nom retirés : le site ne les demande plus (l'avatar par
--     défaut prend les deux premières lettres de l'identifiant).
--
--     ⚠️ Irréversible : rejouer ce fichier EFFACE les prénoms et noms
--     déjà enregistrés. Exporter la table avant, si on veut les garder.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'prenom');
SET @sql := IF(@c = 0, 'DO 0', 'ALTER TABLE `utilisateur` DROP COLUMN `prenom`');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'nom');
SET @sql := IF(@c = 0, 'DO 0', 'ALTER TABLE `utilisateur` DROP COLUMN `nom`');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;

-- ---------------------------------------------------------------------
--  9. Clés d'accès (« passkeys », voir includes/cle_acces.php).
--
--     Une ligne par clé : la clé PUBLIQUE seulement (PEM) — la privée ne
--     quitte jamais l'appareil ou le gestionnaire de mots de passe, rien
--     ici ne permet de se connecter à sa place.
--
--     `cle_hash`  : empreinte sha256 de l'identifiant de la clé. C'est elle
--                   qui se cherche (et que l'index UNIQUE protège : une même
--                   clé ne sert pas deux comptes) ; l'identifiant brut, plus
--                   long qu'un index n'en admet partout, n'est pas indexé.
--     `cle_id`    : l'identifiant, en base64url, pour dire au navigateur
--                   « cet appareil a déjà une clé de ce compte » à l'ajout.
--     `compteur`  : le compteur de signatures de la dernière connexion ;
--                   s'il recule, la clé a peut-être été copiée.
--     `utilisee_le` : NULL tant que la clé n'a jamais servi.
--
--     ON DELETE CASCADE : les clés disparaissent avec le compte.
CREATE TABLE IF NOT EXISTS `cle_acces` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `utilisateur_id` INT UNSIGNED NOT NULL,
  `cle_hash`       CHAR(64)     NOT NULL,
  `cle_id`         VARCHAR(700) NOT NULL,
  `cle_publique`   TEXT         NOT NULL,
  `compteur`       INT UNSIGNED NOT NULL DEFAULT 0,
  `nom`            VARCHAR(60)  NOT NULL DEFAULT '',
  `cree_le`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `utilisee_le`    DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cle_acces_hash` (`cle_hash`),
  KEY `idx_cle_acces_utilisateur` (`utilisateur_id`),
  CONSTRAINT `fk_cle_acces_utilisateur`
    FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateur` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  10. Forfait « bloque » : consultation seule (voir ACTIONS_BLOQUEES,
--      includes/fonctions.php). Une valeur de plus dans la liste du
--      forfait : aucune donnée n'est touchée, chaque compte garde son
--      forfait actuel.
--
--      ⚠️ À exécuter AVANT tout « UPDATE … SET forfait = 'bloque' ». Hors
--      mode strict, une valeur absente de l'ENUM ne fait aucune erreur :
--      MySQL range '' à la place, et le compte, loin d'être bloqué, resterait
--      libre — sans qu'aucun message ne le dise.
--
--      MODIFY réécrit la liste entière des valeurs : on ne l'exécute que si
--      la nouvelle manque, sur le même modèle que la migration 6.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'forfait'
              AND COLUMN_TYPE LIKE '%''bloque''%');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `utilisateur` MODIFY COLUMN `forfait` ENUM(''standard'',''illimite'',''bloque'') NOT NULL DEFAULT ''standard''');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;


-- ---------------------------------------------------------------------
--  11. Page d'administration (admin.php).
--
--      `admin`          : le compte peut ouvrir admin.php. Une colonne à part
--                         du forfait, exprès : on peut être bloqué ET admin
--                         (le forfait dit ce qu'on fait de sa bibliothèque,
--                         `admin` ce qu'on fait des autres comptes). À poser
--                         À LA MAIN pour le premier administrateur :
--                           UPDATE utilisateur SET admin = 1 WHERE identifiant = '...';
--                         Les suivants se nomment depuis la page elle-même.
--      `raison_blocage` : le motif saisi en bloquant le compte, repris dans
--                         l'e-mail envoyé. Vide quand le compte n'est pas
--                         bloqué.
--      `bloque_le`      : depuis quand. NULL quand le compte n'est pas bloqué.
--      jeton_action.type « suppression_admin » : le lien envoyé à ADMIN_EMAIL
--                         pour CONFIRMER la suppression d'un compte depuis
--                         admin.php (voir api.php, « admin.supprimer »).
--
--      Aucune donnée n'est touchée : chaque compte garde son forfait, et
--      personne n'est administrateur tant qu'on ne l'a pas décidé.
--      À exécuter AVANT d'ouvrir admin.php (même piège que la migration 10 :
--      hors mode strict, une valeur absente d'un ENUM est rangée en '').
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'admin');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `utilisateur` ADD COLUMN `admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `forfait`');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'raison_blocage');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `utilisateur` ADD COLUMN `raison_blocage` VARCHAR(500) NOT NULL DEFAULT '''' AFTER `admin`');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'utilisateur' AND COLUMN_NAME = 'bloque_le');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `utilisateur` ADD COLUMN `bloque_le` DATETIME NULL DEFAULT NULL AFTER `raison_blocage`');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'jeton_action' AND COLUMN_NAME = 'type'
              AND COLUMN_TYPE LIKE '%''suppression_admin''%');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `jeton_action` MODIFY COLUMN `type` ENUM(''verification'',''reinit'',''changement_email'',''blocage_email'',''suppression_admin'') NOT NULL');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;


-- ---------------------------------------------------------------------
--  12. Réglages modifiables depuis l'administration (voir
--      includes/reglages.php et admin.php).
--
--      Une ligne par réglage CHANGÉ : le .env reste la valeur de départ, la
--      table ne porte que l'écart, et « revenir au .env » efface la ligne.
--      Liste fermée : config.php ne lit que les clés que reglages.php connaît,
--      et ramène chaque valeur dans ses bornes.
--
--      `valeur`      : toujours en unité NATIVE (secondes, heures…), comme dans
--                      le .env — la page convertit en jours ou en minutes.
--      `modifie_par` : l'administrateur qui l'a changé. SET NULL à la
--                      suppression de son compte : le réglage reste.
--
--      Table absente = aucune erreur : le .env gouverne seul, sans bruit.
--      Tout est rejouable (« CREATE TABLE IF NOT EXISTS »), rien n'est touché.
CREATE TABLE IF NOT EXISTS `reglage` (
  `cle`         VARCHAR(64)  NOT NULL,
  `valeur`      VARCHAR(190) NOT NULL,
  `modifie_le`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `modifie_par` INT UNSIGNED NULL,
  PRIMARY KEY (`cle`),
  KEY `idx_reglage_modifie_par` (`modifie_par`),
  CONSTRAINT `fk_reglage_utilisateur`
    FOREIGN KEY (`modifie_par`) REFERENCES `utilisateur` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  13. Nouveaux tomes (voir includes/nouveautes.php).
--
--      `serie.dernier_tome` : le plus haut tome qui a une couverture chez
--                       MangaDex, tel qu'on l'a vu la dernière fois. 0 tant
--                       qu'on ne le sait pas : la première vérification le
--                       range sans rien annoncer. Une série dont le tome lu
--                       atteint ce nombre est « à jour » : sa carte dit
--                       « Tome N en attente » au lieu de « à emprunter ».
--      `serie.verifie_le` : la dernière interrogation de MangaDex pour cette
--                       série, pour ne pas la refaire à chaque page (voir
--                       NOUVEAUTE_MINUTES). NULL = jamais.
--      `serie.nouveau_tome` : le tome à emprunter dont l'arrivée n'a pas encore
--                       été annoncée à l'écran. Le bandeau de la bibliothèque
--                       le lit, et se vide d'un clic. 0 = rien à annoncer.
--      (Une première version de cette migration ajoutait aussi la colonne
--      `utilisateur.notif_tomes`, pour un e-mail : abandonnée au profit de la
--      notification push, voir la migration 14.)
--
--      Aucune donnée n'est touchée : chaque série garde son tome, son statut
--      et son image. À exécuter AVANT d'envoyer le code (pages et cron lisent
--      ces colonnes), comme les migrations 10 et 11.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'serie' AND COLUMN_NAME = 'dernier_tome');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `serie` ADD COLUMN `dernier_tome` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `favori`');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'serie' AND COLUMN_NAME = 'verifie_le');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `serie` ADD COLUMN `verifie_le` DATETIME NULL DEFAULT NULL AFTER `dernier_tome`');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;

SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'serie' AND COLUMN_NAME = 'nouveau_tome');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `serie` ADD COLUMN `nouveau_tome` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `verifie_le`');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;



-- ---------------------------------------------------------------------
--  14. Notifications push (voir includes/push.php).
--
--      Une ligne par APPAREIL (un navigateur) qui a accepté les notifications :
--      un compte peut en avoir plusieurs (téléphone, ordinateur).
--
--      `endpoint`      : l'adresse chez le service de notification du
--                        navigateur (Google, Mozilla, Apple, Microsoft), où le
--                        serveur POSTe le message. Fixée par le navigateur,
--                        vérifiée à l'enregistrement (https, service connu).
--      `endpoint_hash` : son empreinte sha256. C'est elle qui se cherche et que
--                        l'index UNIQUE protège : un navigateur n'appartient
--                        qu'à UN compte à la fois (le dernier qui l'a activé).
--      `p256dh`, `auth`: les deux clés PUBLIQUES du navigateur, de quoi
--                        chiffrer le message pour lui seul. Rien ici ne permet
--                        de lire ni d'envoyer à sa place.
--      `envoye_le`     : le dernier message accepté par le service. NULL = jamais.
--
--      ON DELETE CASCADE : les appareils disparaissent avec le compte.
--      Une adresse que le service déclare périmée (404, 410) est effacée par
--      le cron au premier envoi raté.
--
--      Rien n'est supprimé : une base qui aurait joué la première version de la
--      migration 13 garde sa colonne `utilisateur.notif_tomes`, inutilisée
--      (on ne prévient plus par e-mail). Elle ne gêne rien ; la retirer est
--      facultatif : ALTER TABLE `utilisateur` DROP COLUMN `notif_tomes`;
--      Tout est rejouable.
CREATE TABLE IF NOT EXISTS `abonnement_push` (
  `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `utilisateur_id` INT UNSIGNED  NOT NULL,
  `endpoint_hash`  CHAR(64)      NOT NULL,
  `endpoint`       VARCHAR(1000) NOT NULL,
  `p256dh`         VARCHAR(100)  NOT NULL,
  `auth`           VARCHAR(40)   NOT NULL,
  `cree_le`        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `envoye_le`      DATETIME      NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_abonnement_push_hash` (`endpoint_hash`),
  KEY `idx_abonnement_push_utilisateur` (`utilisateur_id`),
  CONSTRAINT `fk_abonnement_push_utilisateur`
    FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateur` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  15. Statut « En attente » (valeur `attente` de serie.statut).
--
--      Une série arrivée au dernier tome paru d'une série qui continue (en
--      cours de publication, ou en pause chez MangaDex) passe d'elle-même en
--      « En attente », et repasse « En cours » dès qu'un tome de plus sort
--      (voir includes/nouveautes.php). On peut aussi le choisir à la main.
--
--      Une valeur de plus dans la liste : aucune donnée n'est touchée, chaque
--      série garde son statut actuel. La valeur est ajoutée EN FIN de liste, ce
--      qui ne réécrit rien.
--
--      ⚠️ À exécuter AVANT d'envoyer le code (même piège que la migration 10) :
--      hors mode strict, une valeur absente de l'ENUM ne fait aucune erreur,
--      MySQL range '' à la place — et la série perdrait son statut sans un mot.
--      Le code relit d'ailleurs ce qu'il vient d'écrire et le dit au journal.
--
--      MODIFY réécrit la liste entière des valeurs : on ne l'exécute que si
--      la nouvelle manque, sur le même modèle que les migrations 6 et 10.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'serie' AND COLUMN_NAME = 'statut'
              AND COLUMN_TYPE LIKE '%''attente''%');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `serie` MODIFY COLUMN `statut` ENUM(''cours'',''envie'',''termine'',''abandon'',''attente'') NOT NULL DEFAULT ''cours''');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;


-- ---------------------------------------------------------------------
--  16. État de publication d'une série (`serie.publication`).
--
--      L'état que MangaDex donne à la série — ongoing (en cours), completed
--      (finie), hiatus (en pause), cancelled (arrêtée) — est rangé quand on
--      arrive au dernier tome connu (voir includes/nouveautes.php). La carte en
--      tire sa mention : « Se termine au tome N », « Arrêtée au tome N »,
--      « En pause au tome N » ou « Tome N en attente ».
--
--      Une colonne texte plutôt qu'un ENUM : la valeur est contrôlée par le code
--      (quatre mots, ou vide), et un ENUM rangerait '' sans un mot hors mode
--      strict (voir la migration 15).
--
--      Aucune donnée n'est touchée : chaque série garde son tome, son statut et
--      son image. Les séries déjà « En attente » apprennent leur état au prochain
--      relevé (une seule fois) ; celles déjà « Terminée » n'ont pas de mention
--      tant qu'on ne le sait pas. ⚠️ À exécuter AVANT d'envoyer le code : pages et
--      cron lisent cette colonne.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'serie' AND COLUMN_NAME = 'publication');
SET @sql := IF(@c > 0, 'DO 0',
  'ALTER TABLE `serie` ADD COLUMN `publication` VARCHAR(12) NOT NULL DEFAULT '''' AFTER `nouveau_tome`');
PREPARE requete FROM @sql; EXECUTE requete; DEALLOCATE PREPARE requete;
