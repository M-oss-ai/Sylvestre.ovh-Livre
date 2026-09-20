# CLAUDE.md

Journal des dernières actions faites avec Claude Code sur ce dépôt.
Pas un guide d'architecture (l'ancien a été supprimé volontairement,
voir le commit « Delete CLAUDE.md ») — juste ce qui a changé récemment
et pourquoi, pour reprendre le fil sans tout redemander.

## Recherche de couverture — tome recherché selon le statut

**Fichier** : `js/app.js` (`chercherCouverture()`)

Avant, la recherche demandait systématiquement « tome actuel + 1 »,
même pour une série **terminée** ou **abandonnée** — qui ne sera plus
empruntée plus loin que son tome actuel. Corrigé : le tome envoyé à
l'API dépend maintenant du statut du formulaire (`f-status`) :

- `termine` / `abandon` → le **tome réel** (`tome_actuel`)
- `cours` / `envie` → **`tome_actuel + 1`** (le prochain à emprunter)

## Recherche de couverture — forfait illimité sans plafond

**Fichiers** : `api.php` (action `couverture.chercher`),
`includes/couvertures.php`

Le frein anti-force-brute (`limiteur_echec` / `limiteur_bloque_depuis`)
protège l'adresse IP du **serveur** face à MangaDex, pas les comptes
entre eux — mais il s'appliquait quand même à tout le monde. Il est
maintenant ignoré pour les comptes au forfait `illimite`, comme les
autres plafonds de l'application (nombre de séries, sessions
persistantes…).

La décision (« ce compte est-il exempté ? ») a été extraite dans une
fonction pure, `couverture_recherche_plafonnee(array $utilisateur): bool`
(`includes/couvertures.php`), pour rester testable sans base de
données — le comptage lui-même reste hors de portée des tests unitaires
(voir `tests/LISEZMOI.md`, section « non couvert, faute de base de
données »).

## Tests ajoutés

**Fichier** : `tests/cas/couvertures_titre_test.php`

Groupe `couverture_recherche_plafonnee() — l'exemption du forfait
illimité` : forfait illimité non plafonné, autres forfaits plafonnés,
et défaut restrictif quand le forfait est absent/inconnu (jamais
permissif par défaut). Suite complète : `php tests/lancer.php` → 332
tests, tout passe.

Le changement côté JS (`js/app.js`) n'a pas de test : le projet n'a ni
Node.js ni harnais de test JS, et `tests/LISEZMOI.md` liste déjà tout
le JS comme hors périmètre.
