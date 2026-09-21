# Tests unitaires

Aucune dépendance : pas de Composer, pas de PHPUnit — le même parti pris
que le reste du projet. Le lanceur tient en deux fichiers.

```bash
php tests/lancer.php
```

Un seul groupe de fichiers, par exemple ceux qui parlent du mot de passe :

```bash
php tests/lancer.php mot_de_passe
```

Un seul fichier, directement (pratique pour déboguer) :

```bash
php tests/cas/carte_test.php
```

Sous Windows, si `php` n'est pas dans le `PATH` :

```bash
C:\xampp\php\php.exe tests\lancer.php
```

Le script se termine avec un code de retour **0** si tout passe, **1**
sinon : de quoi le brancher un jour sur un crochet Git ou une
intégration continue.

---

## Ce qu'il faut avant de lancer

**MySQL doit tourner**, même si aucun de ces tests n'interroge la base.

`includes/config.php` ouvre une connexion PDO dès son inclusion, et une
connexion ratée y appelle `erreur_fatale()`, qui coupe le processus. Il
n'y a donc pas moyen de charger les fonctions du projet sans que la
connexion aboutisse. Si MySQL est arrêté, les tests le disent
explicitement au lieu d'afficher une page d'erreur HTML.

La connexion est ouverte, puis **jamais utilisée** : voir les garde-fous
ci-dessous.

---

## Les garde-fous

`tests/amorce.php` place le projet dans un état de test **avant** de le
charger. Trois choses ne peuvent pas arriver pendant un test :

| Garde-fou | Comment |
|---|---|
| Toucher à vos données | `DB_NAME` pointe sur `information_schema`. Les tables de l'application sont hors de portée : une écriture échouerait bruyamment |
| Envoyer un e-mail | `SMTP_HOST`, `SMTP_USER` et `SMTP_PASSWORD` sont vidés. `envoyer_email_smtp()` refuse de partir dès que l'un des trois manque |
| Polluer votre installation | Le journal et les sessions vont dans le dossier temporaire du système ; les quelques tests qui écrivent dans `uploads/` suppriment leur fichier derrière eux |

Cela repose sur une propriété de `charger_env()` : **une variable
d'environnement déjà définie n'est jamais écrasée par le `.env`**. Ce que
l'amorce pose par `putenv()` l'emporte donc sur votre configuration — et
ce qu'un fichier de cas pose avant d'inclure l'amorce l'emporte à son
tour.

---

## Un fichier de cas = un processus

Chaque fichier de `tests/cas/` est exécuté dans son **propre processus**.
Ce n'est pas de la prudence excessive : c'est ce qui rend plusieurs
scénarios vérifiables du tout.

- Les réglages du `.env` deviennent des **constantes** (`MDP_MIN`,
  `IP_ENTETE`, `ASSETS_VERSION`…). Une constante ne se redéfinit pas :
  vérifier qu'un `MDP_MIN` réglé à 3 est bien relevé à 8 exige un
  processus dont l'environnement porte `MDP_MIN=3` dès le départ.
- `ip_client()` garde son résultat dans un `static` : le premier appel le
  fige pour toute la durée du processus. D'où un fichier par scénario
  d'adresse.
- `erreur_fatale()`, `reponse_json()` et `exiger_csrf()` se terminent par
  `exit`. Elles sont appelées par les scripts de `tests/outils/`, dont la
  sortie est ensuite examinée.

C'est aussi ce qui garantit qu'un test ne peut pas en polluer un autre
par une session, une globale ou un `$_SERVER` oublié.

---

## Un plantage ne passe jamais pour un succès

C'est la propriété la plus importante du lanceur, et elle n'est pas
gratuite : un fichier de tests qui s'effondre rend naturellement « 0 test,
0 échec », ce qui ressemble en tous points à un fichier sain.

Quatre filets, chacun pour un mode de défaillance différent :

| Ce qui arrive | Ce qui le repère |
|---|---|
| Une assertion cède | `test()` l'intercepte et la compte |
| Une exception non rattrapée | `lanceur.php` reprend la main sur le `set_exception_handler` de `config.php`, qui sinon afficherait une page d'erreur et couperait le processus en silence |
| Une erreur fatale de PHP | `error_get_last()`, relu à la fin du script |
| Le fichier s'arrête **proprement** mais avant sa dernière ligne — une fonction du projet appelée depuis un test a fait `exit` | Le drapeau posé par `executer-cas.php` une fois le fichier déroulé en entier |

À quoi s'ajoutent, côté `lancer.php` : l'absence totale de compte rendu
(le processus est mort avant même de charger le lanceur — base
injoignable, erreur de syntaxe) et un code de retour non nul alors que le
compte rendu n'annonce aucun échec.

Le dernier cas mérite un mot : c'est le seul qui ne laisse aucune trace.
Ni erreur, ni exception, juste des tests qui disparaissent sans que le
total ne le dise. C'est pour lui seul que `lancer.php` passe par
`executer-cas.php` au lieu d'appeler le fichier de cas directement.

Lancé à la main (`php tests/cas/x_test.php`), un fichier de cas n'a
personne pour poser ce drapeau : ce contrôle-là est alors désactivé, les
trois autres restent.

---

## Écrire un test

```php
<?php
declare(strict_types=1);

// Toute configuration particulière se pose ICI, avant l'amorce.
putenv('ASSETS_VERSION=42');

require __DIR__ . '/../lanceur.php';

groupe('actif() — ASSETS_VERSION renseigné');

test('le numéro de version est ajouté tel quel', function () {
    egale('css/style.css?v=42', actif('css/style.css'), 'la version vient du .env');
});
```

Le fichier doit s'appeler `tests/cas/quelquechose_test.php` pour être
découvert.

### Les assertions

| Assertion | Vérifie |
|---|---|
| `egale($attendu, $obtenu, $description)` | Égalité **stricte** (`===`) |
| `differe($interdit, $obtenu, $description)` | La valeur n'est pas celle-là |
| `vrai($valeur, $description)` · `faux(…)` | Exactement `true` / `false` |
| `contient($aiguille, $botte, $description)` | Sous-chaîne présente |
| `sans($aiguille, $botte, $description)` | Sous-chaîne absente — la forme des tests d'échappement |
| `motif($regex, $sujet, $description)` | Correspondance |
| `estNul($valeur, $description)` | Exactement `null` |
| `vide($tableau, $description)` | Tableau vide — « aucune erreur » |
| `leve($classe, $fn, $description)` | L'appel lève cette exception |

La description n'est pas décorative : c'est elle qui s'affiche quand
l'assertion cède, avec le fichier et la ligne.

Une assertion qui échoue interrompt **son** test, et lui seul : les
suivantes porteraient sur un état déjà faux. Les autres tests du fichier
continuent.

### Utilitaires disponibles

- `journal_test()` — le contenu du journal (`error_log`) depuis le début
  du fichier ou le dernier vidage. C'est ainsi que sont vérifiés
  `journal_securite()` et les messages destinés à l'administrateur.
- `vider_journal_test()` — repart d'un journal vide.
- `executer_php($script, $arguments)` — lance un script en
  sous-processus et rend `['sortie' => …, 'code' => …]`.
- `CHEMIN_PROJET` — la racine du projet.

---

## Ce qui est couvert, et ce qui ne l'est pas

Le périmètre est celui des **fonctions pures** : rien qui exige la base
de données ni le réseau.

**Couvert** — `charger_env`, `env`, `ip_client`, `ip_interne`,
`ini_octets`, `plafond_pixels`, `taille_lisible`, `erreur_fatale`, `e`,
`texte`, `valider_mot_de_passe`, `jeton_csrf`, `csrf_valide`,
`exiger_csrf`, `post_trop_gros`, `message_post_trop_gros`,
`journal_securite`, `url_image_sure`, `photo_depuis_formulaire`,
`initiales`, `cookie_persistant_params`, `actif`, `reponse_json`,
`cron_en_ligne_de_commande`, `cron_refus_navigateur`,
`cron_appelant_authentifie`,
`enregistrer_image`, `enregistrer_image_depuis_donnees`, `gif_anime`,
`corriger_orientation`, `traiter_image`, `ecrire_image`, `url_publique`,
`envoyer_email`, `envoyer_email_smtp` (validation), `avertir_compte_supprime`
(câblage), `titre_normalise`, `mangadex_titre`,
`couverture_quota`, `couverture_tranche_lisible`,
`mangadex_attente_suggeree`, `mangadex_attendre_son_tour` (la file
d'attente des appels sortants), `carte_html`, les
planchers de toutes les constantes — y compris `COUVERTURE_*` et le
défaut restrictif de `COUVERTURE_CONTENU_ADULTE` —, et les contrôles de forme qui
précèdent une requête (`valider_profil`, `jeton_action_valide`,
`verifier_session_persistante`, `supprimer_images_locales`).

**Non couvert, faute de réseau** — `mangadex_get` et
`chercher_couvertures`, qui interrogent MangaDex. Le classement des
résultats, lui, repose sur `titre_normalise`, qui est testé : c'est la
partie qui décide quelle série remonte en tête.

**Non couvert, faute de base de données** — le limiteur anti force brute
(`limiteur_echec`, `limiteur_bloque_depuis`, le doublement des durées, la
fenêtre glissante), les sessions persistantes (`creer_session_persistante`,
la rotation des jetons, le plafond d'appareils), les jetons d'action
(`generer_jeton_action`, `consommer_jeton_action`), les comptes
(`utilisateur_actuel`, `connecter`, `invalider_sessions`,
`email_disponible`), la file d'e-mails (`empiler_mail`,
`traiter_file_mail`), `couverture_consommer` et `couverture_rendre` (le
quota de recherche par compte : le barème est testé, le comptage non), `compter_series`, le cloisonnement par
`utilisateur_id` d'`api.php` et le rapport de `purger.php`.

Ces fonctions-là demandent une base de test dédiée, remise à zéro entre
chaque test — c'est un autre chantier, et il vaut la peine : c'est là que
se trouve le gros de la logique de sécurité.

**Non couvert non plus** — le JavaScript (`js/*.js`), qui demanderait
Node.js.
