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
  `prenom`       VARCHAR(80)  NOT NULL DEFAULT '',
  `nom`          VARCHAR(80)  NOT NULL DEFAULT '',
  `photo`        VARCHAR(500) NOT NULL DEFAULT '',
  -- Forfait du compte : « standard » = limité à MAX_SERIES_PAR_UTILISATEUR
  -- (voir .env), « illimite » = pas de limite de séries. Pas de paiement en
  -- ligne dans l'appli : le passage à l'illimité se fait manuellement en
  -- base (l'utilisateur doit contacter l'administrateur, voir ADMIN_EMAIL) :
  --     UPDATE utilisateur SET forfait = 'illimite' WHERE identifiant = '...';
  `forfait`      ENUM('standard','illimite') NOT NULL DEFAULT 'standard',
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
  `cree_le`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_utilisateur_identifiant` (`identifiant`),
  UNIQUE KEY `uk_utilisateur_email` (`email`),
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
  `type`           ENUM('verification','reinit','changement_email','blocage_email') NOT NULL,
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
  `statut`         ENUM('cours','envie','termine','abandon') NOT NULL DEFAULT 'cours',
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
--  File de rattrapage des e-mails
--
--  L'envoi reste immédiat : c'est ce qui permet à un lien de
--  confirmation d'arriver en quelques secondes. Mais quand le serveur
--  SMTP ne répond pas, l'ancienne version perdait le message pour de
--  bon — l'utilisateur attendait un e-mail qui ne viendrait jamais.
--  Les envois ratés atterrissent ici, et purger.php les repasse.
--
--  Seuls les échecs y passent : cette table reste normalement vide.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mail_file` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `destinataire`   VARCHAR(190) NOT NULL,
  `sujet`          VARCHAR(255) NOT NULL,
  `corps`          TEXT NOT NULL,
  `essais`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `cree_le`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mail_file_cree` (`cree_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
