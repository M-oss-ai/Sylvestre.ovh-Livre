# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Langue du projet

Tout est en **français** : identifiants, commentaires, messages d'interface,
messages de commit. Les commentaires expliquent le *pourquoi*, en nommant
souvent le bug qui a motivé la ligne. Reprenez ce ton plutôt que d'écrire
des commentaires descriptifs.

## Commandes

```bash
php tests/lancer.php                  # toute la suite (303 tests, ~7 s)
php tests/lancer.php mot_de_passe     # les fichiers dont le nom contient ce motif
php tests/cas/carte_test.php          # un seul fichier, pour déboguer
php -l fichier.php                    # vérification de syntaxe (pas d'autre linter)
php -S 127.0.0.1:8899 -t .            # serveur local
php purger.php                        # la tâche planifiée
```

Sous Windows sans `php` dans le `PATH` : `C:\xampp\php\php.exe`.

**MySQL doit tourner même pour les tests**, alors qu'aucun n'interroge la
base : `config.php` ouvre une connexion PDO dès son inclusion et coupe le
processus si elle échoue. `tests/amorce.php` fait pointer `DB_NAME` sur
`information_schema`, vide les réglages SMTP et déporte journal et sessions
dans le dossier temporaire — vos données et votre boîte mail sont hors de
portée.

Diagnostic en production (voir `purger.php`) :

```bash
curl -H "X-Cron-Token: <jeton>" "https://<domaine>/purger.php?ip"
```

Lisez `tests/LISEZMOI.md` avant d'écrire un test : il explique pourquoi
chaque fichier de cas tourne dans son propre processus et comment le
lanceur empêche un plantage de passer pour un succès.

## Aucune dépendance, volontairement

Pas de Composer, pas de npm, pas d'étape de construction, pas de framework —
y compris pour les tests, dont le lanceur est écrit à la main. C'est un parti
pris affirmé du README. N'introduisez pas de dépendance sans le demander.

PHP **8.1 minimum** (type de retour `never`), 8.2 en production.

## Architecture

### Le `.env` est la seule source de réglage

Règle structurante du projet : **tout ce qu'un administrateur peut vouloir
changer vit dans `.env`**, jamais en dur dans le code. Une cinquantaine de
clés. `includes/config.php` est le seul endroit où elles deviennent des
constantes, chacune bornée par un plancher ou un plafond codé en dur pour
qu'une faute de frappe ne désarme pas une protection.

Quand une limite est aussi appliquée côté navigateur, elle descend par un
attribut `data-*` sur `<body>` et se lit avec `L.limite()` (`js/commun.js`).
Recopier la valeur en dur dans le JavaScript la désynchroniserait du `.env`.

### Ordre d'inclusion et état global

- `includes/config.php` — `.env`, constantes, `$pdo` (variable **globale**,
  reprise par `global $pdo` dans les fonctions), gestion des erreurs,
  `ip_client()`. Suffit seul aux scripts en ligne de commande.
- `includes/fonctions.php` — inclut config + images + mailer. **Émet les
  en-têtes de sécurité et démarre la session au moment de l'inclusion**, puis
  appelle `verifier_session_persistante()`.
- `includes/carte.php` — le **seul** endroit où le HTML d'une carte est écrit,
  partagé par `index.php` (rendu initial) et `api.php` (retour AJAX).

### Points à connaître avant de modifier

**`api.php`** est un point d'entrée POST unique avec un `switch` sur `action`.
Dans l'ordre : session, taille du corps (`post_trop_gros()` **avant** le
contrôle CSRF, sinon un fichier trop lourd se déguise en jeton invalide),
jeton CSRF, puis l'action. Chaque requête filtre sur `utilisateur_id`.

**Les quotas sont appliqués dans l'`INSERT` lui-même**
(`INSERT … SELECT … FROM DUAL WHERE (SELECT COUNT(*) …) < ?`). Un `COUNT`
suivi d'un `INSERT` se fait doubler par deux requêtes simultanées.

**Le limiteur** (`tentative_ip`) compte par IP ou par compte (`compte:<id>`).
La fenêtre de comptage (`LIMITEUR_FENETRE`) est **délibérément découplée** de
la durée de blocage : quand les deux se confondaient, espacer ses tentatives
d'une minute suffisait à n'être jamais bloqué. Les durées doublent à chaque
récidive.

**Les sessions** reposent sur la colonne `session_version`, incrémentée à
chaque changement de mot de passe : c'est ce qui déconnecte réellement les
autres appareils. Le forfait `illimite` ajoute un jeton d'appareil qui tourne
à chaque reconnexion automatique, l'ancien restant valide 60 s
(`remplace_le`) pour ne pas déconnecter un second onglet.

**Les e-mails** partent **immédiatement** dans la requête. `mail_file` n'est
pas une file d'envoi : un message n'y entre **que si l'envoi immédiat a
échoué**, et `purger.php` le rejoue. Aucun plafond d'envoi applicatif, retiré
volontairement. `envoyer_email_smtp()` est l'envoi brut, réservé au dépileur
de la file et au rapport du cron — qui ne doit pas être empilé.

**Les images** sont typées par leur contenu (`getimagesizefromstring`), jamais
par leur nom, ré-encodées en WebP, et nommées par l'empreinte **salée** de
leur contenu, ce qui les déduplique. `IMAGE_PIXELS_MAX` est ramené
automatiquement à ce que `memory_limit` permet : GD alloue 4 octets par pixel,
et un dépassement est une erreur fatale mémoire non rattrapable.

**La CSP interdit tout script et tout style « inline ».** N'ajoutez jamais de
bloc `<script>` ni d'attribut `style=`. Tout ce qui sort vers le HTML passe
par `e()` ; le JavaScript n'injecte en `innerHTML` que le HTML produit par
`carte.php`.

## Déploiement (OVH mutualisé)

- `.ovhconfig` fixe PHP 8.2 ; `.env` se place **au-dessus** de la racine web.
- **Transférez en binaire.** Un enregistrement en mode texte a déjà converti
  tous les fichiers en Latin-1, détruisant les emoji au passage.
- Incrémentez `ASSETS_VERSION` à chaque déploiement : `css/` et `js/` sont
  mis en cache un an.
- `purger.php` tourne par le cron et sort en **code non nul** en cas
  d'anomalie, ce qui déclenche l'envoi du journal par OVH.

## Ce que les tests ne couvrent pas encore

Le périmètre actuel est celui des fonctions pures. Restent à couvrir, faute
de base de test dédiée : le limiteur, les sessions persistantes, les jetons
d'action, les comptes, la file d'e-mails et le cloisonnement d'`api.php` —
c'est-à-dire le gros de la logique de sécurité.
