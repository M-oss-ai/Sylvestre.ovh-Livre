<?php
/* =====================================================================
   Les nouveaux tomes.

   Une série suivie chez MangaDex (serie.mangadex_id) a une couverture par
   tome. Deux choses en découlent :

     1. FIN DE SÉRIE. Quand le tome lu est le dernier que MangaDex connaît,
        il n'y a plus de « tome à emprunter » :
          - série finie (completed) ou abandonnée (cancelled) → elle passe en
            « Terminée » et garde la couverture du dernier tome ;
          - série en cours (ongoing) ou en pause (hiatus) → elle passe « En
            attente » (statut `attente`), et sa carte dit « Tome N pas encore
            paru ».
        Décidé au moment où l'on avance d'un tome : nouveautes_fin_de_serie(),
        et aussi à la revérification d'une série « En cours » déjà au bout
        (celles d'avant cette fonction).

     2. NOUVEAU TOME. Une série « à jour » est revérifiée — à l'arrivée sur la
        bibliothèque et à chaque passage du cron. Si MangaDex illustre
        désormais un tome de plus, la série prend sa couverture, REPASSE « En
        cours » si elle était « En attente », remonte en tête (maj_le), et un
        message l'annonce : nouveautes_verifier().

   Ce fichier est chargé par api.php, index.php ET purger.php. Le cron ne
   charge ni fonctions.php (en-têtes HTTP, session) ni rien de ce qui en
   dépend : ici, rien de plus que config.php et couvertures.php.

   Les décisions sont des fonctions PURES, testées sans réseau ni base :
   fin_de_serie(), nouveaute_evaluer(), et les messages. Le reste lit
   MangaDex et écrit en base.
   ===================================================================== */

declare(strict_types=1);

require_once __DIR__ . '/couvertures.php';

/* ---------------------------------------------------------------------
   Les décisions
   --------------------------------------------------------------------- */

/**
 * Où en est cette série, quand on vient d'arriver au tome $tome_actuel ?
 *
 *   'termine'  : dernier tome atteint, série finie ou abandonnée chez MangaDex ;
 *   'a_venir'  : dernier tome atteint, série qui continue (en cours, en pause) ;
 *   'en_route' : MangaDex connaît des tomes plus loin — il manque seulement
 *                la couverture du suivant, ce qui est courant au-delà des
 *                premiers tomes. Rien ne change ;
 *   'inconnu'  : MangaDex ne dit rien d'exploitable (aucune couverture, état de
 *                publication absent ou inconnu), OU la personne a lu PLUS de
 *                tomes que MangaDex n'en connaît : c'est MangaDex qui est en
 *                retard (couvertures pas encore ajoutées), la personne en est la
 *                preuve. On ne devine pas, on ne change rien, on ne dit rien.
 *
 * Le dernier tome est le plus haut de deux sources : celui qu'illustre une
 * couverture ($dernier_illustre), et le « dernier volume » que MangaDex
 * déclare pour une série finie ($dernier_volume). Une série finie dont les
 * dernières couvertures manquent n'est donc pas terminée tant qu'on n'a pas
 * lu son dernier volume : s'arrêter à la dernière couverture la ferait
 * passer « Terminée » avec des tomes encore à lire.
 */
function fin_de_serie(string $statut_mangadex, int $dernier_volume, int $dernier_illustre, int $tome_actuel): string
{
    $fin = max($dernier_volume, $dernier_illustre);
    if ($fin < 1) {
        return 'inconnu';
    }
    if ($tome_actuel < $fin) {
        return 'en_route';
    }
    /* Au-delà de ce que MangaDex connaît : la liste de MangaDex n'est pas
       complète, on ne peut pas dire qu'on est « au bout ». Seul le tome EXACT
       du dernier connu est une fin. */
    if ($tome_actuel > $fin) {
        return 'inconnu';
    }
    return match ($statut_mangadex) {
        'completed', 'cancelled' => 'termine',
        'ongoing', 'hiatus'      => 'a_venir',
        default                  => 'inconnu',
    };
}

/**
 * Que faire d'une série « à jour », quand MangaDex illustre jusqu'au tome
 * $mangadex ? ($connu : ce qu'on en savait, 0 si on ne le savait pas encore.)
 *
 *   'nouveau'   : un tome de plus que la dernière fois, ET que celui qu'on a lu,
 *                 ET la personne était ARRIVÉE AU BOUT de ce qui existait
 *                 ($tome_actuel >= $connu) : on l'annonce. Au tome 1 d'une série
 *                 dont le tome 4 est le dernier, le tome 5 qui sort n'est pas une
 *                 nouvelle — il reste trois tomes à lire avant ;
 *   'memoriser' : on apprend le nombre sans rien annoncer (première
 *                 vérification, tomes nouveaux mais déjà lus, ou personne qui
 *                 n'est pas au bout) ;
 *   'inchange'  : rien à changer. MangaDex qui répond moins haut que ce qu'on
 *                 savait (une couverture retirée) ne fait jamais reculer
 *                 « dernier_tome » : on ne change pas la carte sur un recul.
 *
 * La première vérification n'annonce rien : une série qui existait avant la
 * fonction n'a aucun « dernier tome » en base, et lui en découvrir un
 * « nouveau » ferait annoncer tous les tomes parus depuis toujours.
 */
function nouveaute_evaluer(int $tome_actuel, int $connu, int $mangadex): string
{
    if ($mangadex < 1) {
        return 'inchange';
    }
    if ($connu < 1) {
        return 'memoriser';
    }
    if ($mangadex <= $connu) {
        return 'inchange';
    }
    /* Les séries qu'on vérifie sont déjà celles dont le tome lu a atteint le
       dernier connu (nouveautes_series_a_verifier) : la condition ci-dessous
       est ce qui en fait une règle de la décision elle-même, qui tient même si
       la personne a reculé entre la requête et l'appel. */
    return ($mangadex > $tome_actuel && $tome_actuel >= $connu) ? 'nouveau' : 'memoriser';
}

/**
 * Le statut d'une série qu'on vient de faire reculer d'un tome (« ← »).
 *
 * « En attente » et « Terminée » sont posées d'elles-mêmes quand on arrive AU
 * BOUT des tomes que MangaDex connaît (fin_de_serie()). En quitter le dernier
 * tome, c'est reprendre la lecture : la série repasse « En cours » (demande de
 * l'utilisateur, d'abord pour « En attente », puis pour « Terminée »).
 *
 * Seulement si le tome quitté EST le dernier connu ($dernier_tome > 0 : sans
 * rien de connu, un statut choisi à la main reste comme choisi) : une série
 * mise « En attente » ou « Terminée » à la main au milieu des tomes, parce
 * qu'on attend l'édition française ou qu'on a lâché, n'en bouge pas.
 */
function statut_apres_recul(string $statut, int $dernier_tome, int $tome_avant): string
{
    $au_bout = in_array($statut, ['attente', 'termine'], true) && $dernier_tome > 0 && $tome_avant === $dernier_tome;
    return $au_bout ? 'cours' : $statut;
}

/**
 * Le statut d'une série qu'on vient de faire avancer d'un tome (« → »), dont
 * le nouveau tome lu est $tome_apres.
 *
 * Une série « En attente » dont la personne lit un tome AU-DELÀ du dernier que
 * MangaDex connaît n'attend plus rien : ce tome existe, c'est MangaDex qui est en
 * retard — elle repasse « En cours ». Seulement si le dernier tome est connu
 * ($dernier_tome > 0) : sans rien de connu, un statut mis à la main reste. Les
 * autres statuts ne changent pas : « Terminée » reste ce que la personne a décidé
 * (elle peut lire un hors-série), et « Envie » → « En cours » est l'affaire
 * d'api.php.
 */
function statut_apres_avance(string $statut, int $dernier_tome, int $tome_apres): string
{
    return ($statut === 'attente' && $dernier_tome > 0 && $tome_apres > $dernier_tome) ? 'cours' : $statut;
}

/**
 * L'état de publication que MangaDex donne à une série, tel qu'on le range en
 * base (serie.publication) : l'un des quatre mots de MangaDex, ou '' si la
 * valeur n'est pas l'un d'eux. On ne range jamais une chaîne qu'on n'a pas
 * vérifiée : elle finirait dans une carte.
 */
function publication_connue(string $statut_mangadex): string
{
    return in_array($statut_mangadex, ['ongoing', 'completed', 'hiatus', 'cancelled'], true) ? $statut_mangadex : '';
}

/**
 * Le message qui annonce un nouveau tome, à l'écran comme dans l'e-mail.
 * Le titre est une donnée du compte : il reste du texte, l'appelant l'échappe.
 */
function nouveaute_message(string $titre, int $tome): string
{
    return 'Un nouveau tome est disponible pour la série « ' . $titre . ' » (tome ' . $tome . ').';
}

/**
 * Ce qu'on dit quand on arrive au bout d'une série (fin_de_serie() : 'termine'
 * ou 'a_venir'). $suivant : le numéro du tome qui n'existe pas encore.
 */
function fin_de_serie_message(string $titre, string $etat, int $suivant): string
{
    if ($etat === 'termine') {
        return '« ' . $titre . ' » : dernier tome atteint, la série passe en « Terminée » 🏁';
    }
    return '« ' . $titre . ' » : vous êtes à jour, le tome ' . $suivant . " n'est pas encore paru — la série passe « En attente » ⏳";
}

/* ---------------------------------------------------------------------
   Arrivée au dernier tome (à chaque « → »)
   --------------------------------------------------------------------- */

/**
 * La série vient d'avancer et son tome suivant n'a pas de couverture : est-ce
 * la fin de ce que MangaDex connaît ? Interroge MangaDex (deux appels : les
 * couvertures les plus hautes, puis l'état de publication), décide
 * (fin_de_serie()) et écrit.
 *
 *   'termine'  : statut « termine », couverture du dernier tome ;
 *   'a_venir'  : statut « attente », « dernier_tome » mémorisé : la carte dira
 *                « Tome N en attente » (ou « En pause au tome N ») ; « publication »
 *                mémorise l'état de publication MangaDex ;
 *   'en_route', 'inconnu' : rien n'est écrit ;
 *   'echec'    : MangaDex n'a pas répondu (ou la série a changé entre-temps).
 *
 * Retourne ['etat' => …, 'tome' => le dernier tome connu].
 *
 * $dernier : ce que mangadex_dernier_tome() vient de rendre, si l'appelant l'a
 * déjà (le relevé) : un appel de moins. $infos : idem pour mangadex_statut_serie().
 *
 * $utilisateur_id est passé À PART et non lu dans $s : la ligne que rend
 * ma_serie() (api.php) ne le porte pas, et une clé absente donnait 0 — un
 * UPDATE qui ne trouvait aucune série, sans la moindre erreur.
 *
 * Seule une série « En cours » est concernée : « Terminée » et « Abandonnée »
 * ne cherchent pas plus loin que leur tome, « Envie » n'a rien lu.
 *
 * Les écritures sont gardées par `tome_actuel = ?` et `statut = 'cours'` :
 * l'appel dure quelques secondes, pendant lesquelles la personne a pu
 * reculer, avancer ou éditer la fiche. Une série qui a bougé n'est pas
 * écrasée, et l'état rendu est alors « echec ».
 */
function nouveautes_fin_de_serie(PDO $pdo, int $utilisateur_id, array $s, ?array $dernier = null, ?array $infos = null): array
{
    $lien = (string) $s['mangadex_id'];
    $tome = (int) $s['tome_actuel'];

    $dernier ??= mangadex_dernier_tome($lien);
    if ($dernier !== null) {
        $infos ??= mangadex_statut_serie($lien);
    }
    if ($dernier === null || $infos === null) {
        return ['etat' => 'echec', 'tome' => 0];
    }

    $etat = fin_de_serie($infos['statut'], $infos['dernier_volume'], $dernier['tome'], $tome);
    $fin  = ['etat' => $etat, 'tome' => $dernier['tome']];

    /* L'état de publication est rangé avec le statut : c'est lui qui fait dire à
       la carte « Se termine au tome N », « Arrêtée au tome N », « En pause au
       tome N » ou « Tome N en attente » (carte.php, serie_fin_etiquette()). */
    $publication = publication_connue($infos['statut']);

    if ($etat === 'termine') {
        /* La couverture du dernier tome, s'il y en a une : sinon l'image en
           place reste (une série finie dont les dernières couvertures
           manquent). */
        $couverture = (string) ($dernier['url'] ?? '');
        $req = $pdo->prepare(
            "UPDATE serie
                SET statut = 'termine', dernier_tome = ?, nouveau_tome = 0, verifie_le = NOW(), publication = ?,
                    tome_final = ?, publication_le = NOW(),
                    couverture = IF(? <> '', ?, couverture)
              WHERE id = ? AND utilisateur_id = ? AND tome_actuel = ? AND statut = 'cours'"
        );
        $req->execute([$dernier['tome'], $publication, $infos['dernier_volume'], $couverture, $couverture, (int) $s['id'], $utilisateur_id, $tome]);
        return $req->rowCount() === 1 ? $fin : ['etat' => 'echec', 'tome' => 0];
    }

    if ($etat === 'a_venir') {
        /* Un changement de statut EST une modification : maj_le suit. */
        try {
            $req = $pdo->prepare(
                "UPDATE serie
                    SET statut = 'attente', dernier_tome = ?, verifie_le = NOW(), publication = ?,
                        tome_final = ?, publication_le = NOW()
                  WHERE id = ? AND utilisateur_id = ? AND tome_actuel = ? AND statut = 'cours'"
            );
            $req->execute([$dernier['tome'], $publication, $infos['dernier_volume'], (int) $s['id'], $utilisateur_id, $tome]);
        } catch (PDOException $e) {
            // Mode strict, valeur absente de l'ENUM : la migration 15 n'a pas été jouée.
            error_log('nouveautes: statut « attente » refusé par la base (migration 15 ?) - ' . $e->getMessage());
            return ['etat' => 'echec', 'tome' => 0];
        }
        if ($req->rowCount() !== 1) {
            return ['etat' => 'echec', 'tome' => 0];   // la série a bougé pendant l'appel
        }
        return nouveautes_statut_ecrit($pdo, (int) $s['id'], 'attente') ? $fin : ['etat' => 'echec', 'tome' => 0];
    }

    return $fin;
}

/**
 * Relit ce que la base a rangé dans `statut` et rétablit « En cours » si ce
 * n'est pas ce qu'on a écrit.
 *
 * Hors mode strict (c'est le cas de bien des mutualisés), une valeur absente de
 * l'ENUM ne fait AUCUNE erreur : MySQL range '' à la place. Si la migration 15
 * n'a pas été jouée avant l'envoi du code, une série passerait ainsi à un
 * statut vide, sans un mot — et sortirait de tous les filtres. On le voit ici,
 * on le défait, on le dit au journal.
 */
function nouveautes_statut_ecrit(PDO $pdo, int $id, string $attendu): bool
{
    $req = $pdo->prepare('SELECT statut FROM serie WHERE id = ?');
    $req->execute([$id]);
    if ($req->fetchColumn() === $attendu) {
        return true;
    }
    error_log('nouveautes: le statut « ' . $attendu . ' » n\'a pas été rangé (série ' . $id . ') : la migration 15 de livre.sql n\'est pas jouée ?');
    $pdo->prepare("UPDATE serie SET statut = 'cours' WHERE id = ?")->execute([$id]);
    return false;
}

/* ---------------------------------------------------------------------
   Vérification d'une série « à jour » (arrivée sur la bibliothèque, cron)
   --------------------------------------------------------------------- */

/**
 * Les séries à vérifier, les plus anciennement vérifiées d'abord.
 * $utilisateur_id : celles d'un compte (la bibliothèque), ou null pour tout le
 * site (le cron). $limite : combien, au plus ; 0 = sans limite (voir
 * nouveautes_limite_sql()).
 *
 * Une série est candidate quand elle est :
 *   - « En cours » ou « En attente », et liée à MangaDex ;
 *   - à jour — son tome lu a atteint le dernier connu — ou jamais vérifiée
 *     (dernier_tome = 0) : la première vérification ne fait qu'apprendre le
 *     nombre de tomes, sans rien annoncer (nouveaute_evaluer()) ; OU son état de
 *     publication est à (re)lire (jamais lu, ou plus ancien que
 *     NOUVEAUTE_PUBLICATION_JOURS) : la carte le dit même quand on est au tome 2,
 *     un appel de plus, mais seulement cette fois-là ;
 *   - pas vérifiée depuis NOUVEAUTE_MINUTES.
 * Jamais celles d'un compte bloqué : la consultation seule ne sollicite pas
 * MangaDex (voir ACTIONS_BLOQUEES).
 *
 * 'publication_perimee' dit, pour chaque ligne, si l'état de publication est à
 * relire : nouveautes_verifier_serie() s'en sert.
 *
 * Les nombres sont insérés tels quels : ce sont des entiers déjà bornés.
 */
function nouveautes_series_a_verifier(PDO $pdo, ?int $utilisateur_id, int $limite): array
{
    $perimee = '(s.publication_le IS NULL OR s.publication_le < NOW() - INTERVAL ' . (int) NOUVEAUTE_PUBLICATION_JOURS . ' DAY)';
    $sql = "SELECT s.id, s.utilisateur_id, s.titre, s.statut, s.tome_actuel, s.mangadex_id, s.dernier_tome, s.couverture, s.publication,
                   s.tome_final, " . $perimee . " AS publication_perimee
              FROM serie s
              JOIN utilisateur u ON u.id = s.utilisateur_id
             WHERE s.statut IN ('cours', 'attente')
               AND s.mangadex_id <> ''
               AND (s.dernier_tome = 0 OR s.tome_actuel >= s.dernier_tome OR " . $perimee . ")
               AND (s.verifie_le IS NULL OR s.verifie_le < NOW() - INTERVAL " . (int) NOUVEAUTE_MINUTES . " MINUTE)
               AND u.forfait <> 'bloque'"
        . ($utilisateur_id !== null ? ' AND s.utilisateur_id = ' . $utilisateur_id : '')
        . ' ORDER BY s.verifie_le ASC, s.id ASC' . nouveautes_limite_sql($limite);

    return $pdo->query($sql)->fetchAll();
}

/**
 * La clause LIMIT d'une liste de séries à vérifier : ' LIMIT n', ou rien quand
 * $limite vaut 0 (ou moins) — « sans limite », comme NOUVEAUTE_MAX_VISITE et
 * NOUVEAUTE_MAX_CRON à 0. C'est alors le budget de temps de
 * nouveautes_verifier() qui arrête le relevé.
 */
function nouveautes_limite_sql(int $limite): string
{
    return $limite > 0 ? ' LIMIT ' . $limite : '';
}

/**
 * Note qu'on a regardé cette série, sans toucher à sa date de modification :
 * relever un tome n'est pas la modifier, et la bibliothèque est triée dessus.
 */
function nouveautes_noter_verification(PDO $pdo, int $id, int $utilisateur_id): void
{
    $pdo->prepare('UPDATE serie SET verifie_le = NOW(), maj_le = maj_le WHERE id = ? AND utilisateur_id = ?')
        ->execute([$id, $utilisateur_id]);
}

/**
 * Vérifie UNE série (un appel à MangaDex, deux quand un tome nouveau lui
 * cherche sa couverture) et écrit ce qu'il faut.
 *
 * Retourne ['etat' => …] :
 *   'nouveau'    : un tome de plus à emprunter — la couverture est prise, la
 *                  série repasse « En cours » si elle attendait, la date de
 *                  modification passe à maintenant, le message est à annoncer
 *                  (nouveau_tome). Porte aussi 'tome' ;
 *   'statut'     : une série « En cours » qui est AU BOUT de ce que MangaDex
 *                  connaît change de statut — « En attente » (elle continue) ou
 *                  « Terminée » (elle est finie ou abandonnée). Porte aussi
 *                  'statut'. Les séries d'avant cette fonction se classent
 *                  ainsi ; rien à annoncer, la pastille de la carte le dit ;
 *   'memorise'   : le nombre de tomes est appris, rien à annoncer ;
 *   'publication': seul l'état de publication a été (re)lu — la série n'est pas
 *                  au bout de ses tomes, ses couvertures n'ont pas été
 *                  interrogées ;
 *   'inchange'   : rien de neuf ;
 *   'echec'      : MangaDex n'a pas répondu pour cette série. Elle est notée
 *                  « vérifiée » quand même : sans cela une série que
 *                  MangaDex ne connaît plus resterait en tête de file pour
 *                  toujours et affamerait toutes les autres ;
 *   'file_pleine': NOTRE file d'attente (ou un 429) a refusé l'appel — la
 *                  faute n'est pas à la série, rien n'est noté, et l'appelant
 *                  doit s'arrêter.
 *
 * L'écriture d'un tome nouveau est gardée (`tome_actuel` et `dernier_tome`
 * relus) : deux onglets, ou le cron et une visite, qui vérifient la même série
 * au même instant ne l'annoncent qu'une fois.
 */
function nouveautes_verifier_serie(PDO $pdo, array $s): array
{
    $id    = (int) $s['id'];
    $uid   = (int) $s['utilisateur_id'];
    $lien  = (string) $s['mangadex_id'];
    $tome  = (int) $s['tome_actuel'];
    $connu = (int) $s['dernier_tome'];

    mangadex_attente_suggeree(0);

    /* L'état de publication (UN appel /manga) : lu la première fois, puis relu
       toutes les NOUVEAUTE_PUBLICATION_JOURS jours. C'est lui, avec le dernier
       tome, qui fait dire à la carte « Se termine au tome N » et ses trois
       cousines, même quand on est au tome 2 (carte.php, serie_fin_etiquette()).
       Ce n'est pas une modification de la série : maj_le = maj_le. Un MangaDex
       qui ne répond pas ne bloque rien, on réessaiera au relevé suivant. */
    $infos = null;
    if (!empty($s['publication_perimee'])) {
        $infos = mangadex_statut_serie($lien);
        if ($infos !== null) {
            nouveautes_publication_ecrire($pdo, $id, $uid, $lien, $infos);
        } elseif (mangadex_attente_suggeree() > 0) {
            return ['etat' => 'file_pleine'];
        }
    }

    /* Une série qui n'est pas au bout des tomes n'a pas de nouveau tome à
       attendre : on n'interroge pas ses couvertures pour rien. */
    if ($connu > 0 && $tome < $connu) {
        nouveautes_noter_verification($pdo, $id, $uid);
        return ['etat' => $infos !== null ? 'publication' : 'echec'];
    }

    $dernier = mangadex_dernier_tome($lien);
    if ($dernier === null) {
        if (mangadex_attente_suggeree() > 0) {
            return ['etat' => 'file_pleine'];
        }
        nouveautes_noter_verification($pdo, $id, $uid);
        return ['etat' => 'echec'];
    }

    $verdict = nouveaute_evaluer($tome, $connu, $dernier['tome']);

    if ($verdict === 'nouveau') {
        /* La couverture du tome À EMPRUNTER (celui qui suit le tome lu), et
           à défaut la plus récente : « prendre la nouvelle image ». */
        $suivant    = $tome + 1;
        $couverture = couverture_liee($lien, $suivant);
        if ($couverture === '') {
            $couverture = (string) ($dernier['url'] ?? '');
        }
        if ($couverture === '') {
            $couverture = (string) $s['couverture'];
        }

        /* « statut = 'cours' » : une série qui attendait ce tome n'attend plus.
           Gardée par le statut lu (« statut = ? ») comme par le tome : si la
           personne a changé de statut pendant l'appel, on n'y touche pas. */
        $req = $pdo->prepare(
            "UPDATE serie
                SET dernier_tome = ?, couverture = ?, nouveau_tome = ?, statut = 'cours', verifie_le = NOW(), maj_le = NOW()
              WHERE id = ? AND utilisateur_id = ? AND mangadex_id = ? AND tome_actuel = ? AND dernier_tome = ? AND statut = ?"
        );
        $req->execute([$dernier['tome'], $couverture, $suivant, $id, $uid, $lien, $tome, $connu, (string) ($s['statut'] ?? 'cours')]);
        return $req->rowCount() === 1
            ? ['etat' => 'nouveau', 'tome' => $suivant]
            : ['etat' => 'inchange'];
    }

    /* Une série « En cours » arrivée au bout de ce que MangaDex connaît, À LA
       PREMIÈRE VÉRIFICATION seulement ($connu === 0) : « En attente » ou « Terminée »
       selon l'état de publication. Celles qui y étaient déjà avant cette fonction
       ne passeraient jamais par un « → ». Ensuite non : une personne qui remet « En
       cours » une série que MangaDex croit au bout (MangaDex est en retard : elle a
       le tome suivant) doit avoir le dernier mot, pas la revoir « En attente » une
       heure plus tard. Un MangaDex qui ne répond pas à cette seconde question ne
       bloque rien : la série reste « En cours » et on réessaie au relevé suivant. */
    if ($connu === 0 && (string) ($s['statut'] ?? '') === 'cours' && $dernier['tome'] >= 1 && $tome >= $dernier['tome']) {
        $fin = nouveautes_fin_de_serie($pdo, $uid, $s, $dernier, $infos);
        if ($fin['etat'] === 'termine') {
            return ['etat' => 'statut', 'statut' => 'termine'];
        }
        if ($fin['etat'] === 'a_venir') {
            return ['etat' => 'statut', 'statut' => 'attente'];
        }
    }

    if ($verdict === 'memoriser') {
        $pdo->prepare(
            'UPDATE serie SET dernier_tome = ?, verifie_le = NOW(), maj_le = maj_le
              WHERE id = ? AND utilisateur_id = ? AND mangadex_id = ?'
        )->execute([$dernier['tome'], $id, $uid, $lien]);
        return ['etat' => 'memorise'];
    }

    nouveautes_noter_verification($pdo, $id, $uid);
    return ['etat' => 'inchange'];
}

/**
 * Range l'état de publication lu chez MangaDex : l'état (publication_connue()),
 * le dernier volume DÉCLARÉ (tome_final : 0 tant que la série n'est pas finie),
 * et le moment de la lecture. Ce n'est pas une modification de la série
 * (maj_le = maj_le). Gardée par le lien : une série qui a changé de MangaDex
 * pendant l'appel n'hérite pas de l'état de l'autre.
 *
 * @param array{statut: string, dernier_volume: int} $infos  ce que mangadex_statut_serie() rend
 */
function nouveautes_publication_ecrire(PDO $pdo, int $id, int $utilisateur_id, string $lien, array $infos): void
{
    $pdo->prepare(
        'UPDATE serie SET publication = ?, tome_final = ?, publication_le = NOW(), maj_le = maj_le
          WHERE id = ? AND utilisateur_id = ? AND mangadex_id = ?'
    )->execute([publication_connue($infos['statut']), (int) $infos['dernier_volume'], $id, $utilisateur_id, $lien]);
}

/** Échecs de suite après lesquels on suppose MangaDex en panne, et on s'arrête. */
const NOUVEAUTE_ECHECS_DE_SUITE = 3;

/**
 * Vérifie ces séries, dans l'ordre, en s'arrêtant plutôt que d'insister :
 *   - quand NOTRE file d'attente refuse un appel (ou MangaDex répond 429) ;
 *   - après NOUVEAUTE_ECHECS_DE_SUITE échecs de suite : MangaDex est en panne,
 *     et chaque appel perdu immobilise un processus PHP jusqu'au délai ;
 *   - quand le budget de temps ($budget_secondes) est épuisé : ce que le cron
 *     ou la page ne verra pas aujourd'hui sera vu au prochain passage.
 *
 * Retourne :
 *   'verifiees'  : le nombre de séries réellement traitées ;
 *   'nouveaux'   : les tomes nouveaux, [['id', 'utilisateur_id', 'titre', 'tome']] ;
 *   'statuts'    : les séries qui ont CHANGÉ DE STATUT (« En attente »,
 *                  « Terminée »), [['id', 'utilisateur_id', 'titre', 'statut']] —
 *                  à rafraîchir à l'écran, rien à annoncer ;
 *   'interrompu' : '' si tout est passé, sinon 'file', 'echecs' ou 'temps'.
 */
function nouveautes_verifier(PDO $pdo, array $series, float $budget_secondes): array
{
    $debut  = microtime(true);
    $bilan  = ['verifiees' => 0, 'nouveaux' => [], 'statuts' => [], 'interrompu' => ''];
    $echecs = 0;

    foreach ($series as $s) {
        if (microtime(true) - $debut >= $budget_secondes) {
            $bilan['interrompu'] = 'temps';
            break;
        }

        $r = nouveautes_verifier_serie($pdo, $s);
        if ($r['etat'] === 'file_pleine') {
            $bilan['interrompu'] = 'file';
            break;
        }
        $bilan['verifiees']++;

        if ($r['etat'] === 'echec') {
            if (++$echecs >= NOUVEAUTE_ECHECS_DE_SUITE) {
                $bilan['interrompu'] = 'echecs';
                break;
            }
            continue;
        }
        $echecs = 0;

        if ($r['etat'] === 'nouveau') {
            $bilan['nouveaux'][] = [
                'id'             => (int) $s['id'],
                'utilisateur_id' => (int) $s['utilisateur_id'],
                'titre'          => (string) $s['titre'],
                'tome'           => (int) $r['tome'],
            ];
        } elseif ($r['etat'] === 'statut') {
            $bilan['statuts'][] = [
                'id'             => (int) $s['id'],
                'utilisateur_id' => (int) $s['utilisateur_id'],
                'titre'          => (string) $s['titre'],
                'statut'         => (string) $r['statut'],
            ];
        }
    }
    return $bilan;
}

/**
 * Les tomes nouveaux d'un relevé, rangés par compte, pour n'écrire qu'UN
 * message à chacun quel que soit le nombre de ses séries.
 *
 * @param  array $nouveaux  ce que nouveautes_verifier() rend dans 'nouveaux'
 * @return array<int, array<int, array{titre: string, tome: int}>>  utilisateur_id => séries
 */
function nouveautes_par_compte(array $nouveaux): array
{
    $comptes = [];
    foreach ($nouveaux as $n) {
        $comptes[(int) $n['utilisateur_id']][] = ['titre' => (string) $n['titre'], 'tome' => (int) $n['tome']];
    }
    return $comptes;
}

/**
 * Le temps qu'un passage peut consacrer à MangaDex, en secondes : la moitié de
 * ce que PHP accorde au script (la suite — rapport, e-mails — doit encore
 * tenir), ou deux minutes en ligne de commande, où PHP n'en accorde aucun.
 */
function nouveautes_budget(): float
{
    $max = (int) ini_get('max_execution_time');
    return $max > 0 ? max(5.0, $max * 0.5) : 120.0;
}
