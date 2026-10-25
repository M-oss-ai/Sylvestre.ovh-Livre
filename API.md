# API JSON — Ma Bibliothèque Manga

API REST en JSON pour lire et modifier **ta bibliothèque** depuis un serveur
(l'app personnelle). Elle sert à :

1. **rechercher** une série dans ta bibliothèque ;
2. **ajouter** une série ou **modifier** une entrée (titre, auteur, statut,
   tome lu, favori) ;
3. lancer la **Recherche auto** de couverture pour le tome suivant, puis
   **enregistrer** la proposition choisie.

**Il n'y a pas de suppression** : aucune route `DELETE`, aucune porte vers
la suppression ou l'administration du compte.

La description formelle est dans [`openapi.yaml`](openapi.yaml) (OpenAPI 3.0).
Ce document est la version lisible, avec des exemples.

---

## En bref

| | |
|---|---|
| Adresse de base | `https://livre.sylvestre.ovh/api/v1` |
| Format | JSON, UTF-8, partout (requêtes et réponses) |
| Authentification | `Authorization: Bearer <jeton>` |
| Protocole | **HTTPS uniquement** (en HTTP : `403 https_requis`) |
| Appelant | **de serveur à serveur** — pas de CORS, jamais depuis un navigateur |
| Débit | 60 requêtes par minute et par jeton |
| Taille d'un corps | 16 Ko au plus |

---

## 1. Obtenir un jeton

Le jeton est un secret : celui qui le possède peut lire et modifier toute ta
bibliothèque (pas la supprimer). Il est :

- **lié à ton compte** ; il ne donne jamais accès à un autre compte ;
- **stocké haché** sur le site (SHA-256) : le site ne peut pas le
  retrouver, il n'est montré **qu'une seule fois** ;
- **sans expiration**, mais **révocable** à tout instant ;
- limité à ce que décrit ce document (aucune suppression, aucun accès aux
  réglages du compte, ni au mot de passe, ni à l'e-mail).

### Créer

1. Connecte-toi sur le site, puis ouvre **`https://livre.sylvestre.ovh/jeton-api.php`**.
2. Donne un nom au jeton (ex. « App PWA — serveur »), saisis ton mot de passe,
   clique **Créer un jeton**. Créer un jeton est une action sensible du site,
   comme changer d'e-mail : un compte **Google** doit d'abord se reconnecter
   avec Google (et retaper son mot de passe, s'il en a un), puis dispose de
   10 minutes pour créer le jeton.
3. Copie-le tout de suite : il commence par `lbm_` et fait 68 caractères.
   Il ne sera plus jamais affiché.
4. Range-le dans la configuration **du serveur** de l'app (`.env`, variable
   d'environnement, gestionnaire de secrets). Jamais dans le code, dans un
   dépôt, ni dans une page ou un script qui s'exécute dans un navigateur.

Tu peux avoir **5 jetons actifs** au plus.

### Changer de jeton sans coupure (rotation)

Plusieurs jetons sont valides en même temps :

1. crée un **nouveau** jeton ;
2. mets-le dans la configuration de l'app et redémarre l'app ;
3. quand tout marche, **révoque l'ancien** sur `jeton-api.php`.

### Révoquer

Sur `jeton-api.php`, bouton **Révoquer**. Le jeton est refusé immédiatement.
Fais-le sans attendre si le jeton a pu fuiter (journal, capture d'écran,
dépôt, requête en HTTP clair…).

### Vérifier qu'il marche

```bash
export LBM_TOKEN='lbm_…'            # le jeton, lu depuis la configuration de l'app
curl -s https://livre.sylvestre.ovh/api/v1/moi \
  -H "Authorization: Bearer $LBM_TOKEN"
```

Tous les exemples ci-dessous supposent `LBM_TOKEN` défini et
`API=https://livre.sylvestre.ovh/api/v1`.

---

## 2. Conventions

### Succès

Un objet JSON. Une série est toujours enveloppée : `{"serie": {…}}`. Une
liste : `{"series": […], "total": …}`.

### Erreurs

Tous les codes HTTP ≥ 400 ont le même corps :

```json
{
  "erreur": {
    "code": "validation",
    "message": "Données invalides.",
    "champs": { "statut": "Doit être l'une de : cours, attente, envie, termine, abandon." }
  }
}
```

- **`code`** est stable : c'est lui que le programme teste.
- `message` est pour un humain (en français) ; il peut changer.
- `champs` n'existe que pour `validation` : un message **par champ fautif**,
  tous rendus d'un coup.
- `attente` (secondes) accompagne les `429`, avec l'en-tête `Retry-After`.

Aucune erreur ne contient de détail interne (SQL, chemin, trace).

### Codes d'erreur

| HTTP | `code` | Quand |
|---|---|---|
| 400 | `corps_requis` | un corps JSON est obligatoire et manque |
| 400 | `json_invalide` | le corps n'est pas un **objet** JSON valide |
| 401 | `jeton_invalide` | jeton absent, inconnu, mal formé ou révoqué (volontairement indiscernables) |
| 401 | `jeton_expire` | le jeton a une date d'expiration dépassée (n'arrive pas avec un jeton normal) |
| 403 | `https_requis` | requête en HTTP clair |
| 403 | `compte_bloque` | le compte est en **consultation seule** (forfait « bloqué ») : toute écriture et toute Recherche auto sont refusées, la lecture reste permise. Le message dit la raison donnée par l'administrateur |
| 404 | `route_inconnue` | adresse inconnue |
| 404 | `serie_introuvable` | la série n'existe pas **ou n'est pas à toi** |
| 405 | `methode_non_autorisee` | mauvaise méthode ; l'en-tête `Allow` liste les bonnes |
| 413 | `corps_trop_gros` | corps > 16 Ko |
| 415 | `type_non_supporte` | `Content-Type` ≠ `application/json` |
| 422 | `validation` | une valeur est refusée (voir `champs`) |
| 422 | `quota_series` | limite de séries du compte atteinte (150 en forfait standard) |
| 429 | `trop_de_requetes` | plus de 60 requêtes par minute avec ce jeton |
| 429 | `trop_d_echecs` | trop de jetons refusés depuis ton adresse IP : blocage temporaire |
| 429 | `quota_recherche` | quota de Recherche auto du compte épuisé pour la tranche |
| 429 | `service_occupe` | trop de recherches en cours sur le site (file MangaDex) ; la recherche n'est **pas** décomptée |
| 500 | `erreur_interne` | erreur technique côté site ; réessayer plus tard |

### Débit

- **60 requêtes par tranche de 60 secondes, par jeton.** Chaque réponse
  porte `X-RateLimit-Limit`, `X-RateLimit-Remaining` et `X-RateLimit-Reset`
  (secondes avant la prochaine tranche).
- Au-delà : `429 trop_de_requetes` + `Retry-After`. Les requêtes refusées
  comptent aussi : insister ne sert à rien. Attends `Retry-After` secondes.
- **Jetons refusés** : après 10 jetons refusés depuis la même adresse IP, cette
  adresse est bloquée 60 secondes (le blocage double à chaque récidive, jusqu'à
  1 heure), **même avec un bon jeton**. Si l'app reçoit un `401`, elle doit
  s'arrêter et prévenir, pas boucler.

### Validation

Stricte : un champ inconnu, un mauvais type ou une valeur hors borne est
**refusé**, jamais corrigé en silence.

| Champ | Règle |
|---|---|
| `titre` | texte, 1 à 190 caractères (obligatoire à la création) |
| `auteur` | texte, 0 à 190 caractères (c'est le « sous-titre / auteur » de la fiche) ; `""` pour l'effacer |
| `tome_lu` | **entier** de 0 à 9999 ; `0` = série non commencée. Un nombre en texte (`"3"`) est refusé |
| `statut` | `cours`, `attente`, `envie`, `termine` ou `abandon` — voir ci-dessous |
| `favori` | booléen `true` / `false` (une **valeur**, pas une bascule : répéter est sans effet) |

Les retours à la ligne et caractères de contrôle d'un texte sont remplacés
par un espace ou supprimés, comme sur le site ; les espaces autour sont retirés.

### Statuts

| Code | Libellé affiché |
|---|---|
| `cours` | En cours |
| `attente` | En attente |
| `envie` | Envie |
| `termine` | Terminée |
| `abandon` | Abandonnée |

Ce sont **exactement** les statuts du site : l'API en lit la liste, une valeur inconnue est refusée (`422`). Le statut `attente` (« En attente ») signale une série arrivée au dernier tome paru ; le site le pose et le lève tout seul (vérification des nouveautés), et l'API peut aussi le poser ou le retirer à la main.
La liste est aussi dans la réponse de `GET /moi`.

### L'objet série

```json
{
  "id": 42,
  "titre": "Berserk",
  "auteur": "Kentaro Miura",
  "tome_lu": 4,
  "tome_suivant": 5,
  "statut": "cours",
  "statut_libelle": "En cours",
  "favori": false,
  "couverture": {
    "url": "https://uploads.mangadex.org/covers/d9e2…/2c0f….jpg.512.jpg",
    "source": "mangadex",
    "mangadex_id": "d9e2f26e-c762-41a6-bb71-b6ae18cadde2"
  },
  "cree_le": "2026-10-25T04:14:48+01:00",
  "modifie_le": "2026-10-25T04:15:06+01:00"
}
```

- `tome_suivant` = `tome_lu + 1` (le tome « à emprunter »).
- `couverture.url` est `null` s'il n'y en a pas ; une image envoyée par fichier
  sur le site apparaît en adresse absolue (`https://livre.sylvestre.ovh/uploads/…`).
- `couverture.source` : `aucune`, `mangadex`, `importee` (fichier du site) ou
  `lien` (autre adresse https).
- `couverture.mangadex_id` : la série MangaDex liée (sinon `null`). Une fois
  liée, le site retrouve les couvertures suivantes en une seule requête.
- Dates en ISO 8601 avec décalage (heure de Paris).

---

## 3. Routes

### `GET /moi` — le compte et ses limites

Pour tester le jeton et connaître les limites.

```bash
curl -s "$API/moi" -H "Authorization: Bearer $LBM_TOKEN"
```

```json
{
  "compte": {
    "identifiant": "marc",
    "forfait": "standard",
    "series": 37,
    "series_max": 150,
    "tome_max": 9999,
    "statuts": { "cours": "En cours", "attente": "En attente", "envie": "Envie", "termine": "Terminée", "abandon": "Abandonnée" },
    "requetes": { "max": 60, "fenetre": 60 },
    "recherches_couverture": { "max": 30, "fenetre": 120 }
  }
}
```

`series_max` et `recherches_couverture` valent `null` avec le forfait illimité.

---

### `GET /series` — rechercher dans ta bibliothèque

Paramètres (tous facultatifs) :

| Paramètre | Effet |
|---|---|
| `q` | texte cherché dans le **titre et l'auteur**, sans tenir compte de la casse ni des accents (`elan` trouve « Élan ») ; `%` et `_` sont du texte, pas des jokers |
| `statut` | `cours`, `attente`, `envie`, `termine` ou `abandon` |
| `favori` | `true`/`1` (favoris seulement) ou `false`/`0` (les autres) |
| `limite` | taille de la page, 1 à 100 (défaut **50**) |
| `decalage` | nombre de séries à sauter (défaut 0) |

Tri : la série **modifiée le plus récemment** d'abord.

```bash
curl -s -G "$API/series" \
  --data-urlencode "q=berserk" --data-urlencode "statut=cours" \
  -H "Authorization: Bearer $LBM_TOKEN"
```

```json
{
  "series": [ { "id": 42, "titre": "Berserk", "…": "…" } ],
  "total": 1,
  "limite": 50,
  "decalage": 0
}
```

`total` compte **toutes** les séries qui correspondent, pas seulement la page.
Pour tout lire : avance `decalage` de `limite` jusqu'à dépasser `total`.

Erreurs : `422 validation` (paramètre invalide, ex. `limite=500`).

---

### `GET /series/{id}` — lire une série

```bash
curl -s "$API/series/42" -H "Authorization: Bearer $LBM_TOKEN"
```

Réponse : `{"serie": {…}}`. Erreur : `404 serie_introuvable`.

---

### `POST /series` — ajouter une série

Corps : `titre` (obligatoire), et facultativement `auteur`, `tome_lu`,
`statut`, `favori`. Valeurs par défaut, **identiques au site** : `auteur`
vide, `tome_lu` 0, `statut` `cours`, `favori` `false`.

```bash
curl -s -X POST "$API/series" \
  -H "Authorization: Bearer $LBM_TOKEN" -H "Content-Type: application/json" \
  -d '{"titre":"Vinland Saga","auteur":"Makoto Yukimura","tome_lu":10,"statut":"cours","favori":true}'
```

Réponse **`201 Created`**, avec l'en-tête `Location` et `{"serie": {…}}`.

Erreurs : `422 validation`, `422 quota_series` (150 séries en forfait
standard — la limite est appliquée par la base, deux requêtes simultanées ne
la dépassent pas).

> **Doublons.** L'API ne refuse pas deux séries du même titre (le site non
> plus). Avant d'ajouter, cherche : `GET /series?q=<titre>`. Si l'app peut
> rejouer une requête (réessai après coupure), c'est à elle de vérifier.

---

### `PATCH /series/{id}` — modifier une série

Envoie **seulement** les champs à changer : `titre`, `auteur`, `tome_lu`,
`statut`, `favori`. Les autres restent intacts. Au moins un champ est exigé.

```bash
# J'ai lu le tome 11
curl -s -X PATCH "$API/series/42" \
  -H "Authorization: Bearer $LBM_TOKEN" -H "Content-Type: application/json" \
  -d '{"tome_lu":11}'

# Série terminée, retirée des favoris
curl -s -X PATCH "$API/series/42" \
  -H "Authorization: Bearer $LBM_TOKEN" -H "Content-Type: application/json" \
  -d '{"statut":"termine","favori":false}'
```

Réponse : `{"serie": {…}}` (la série à jour).

Points d'attention :

- **Rien n'est déduit.** Sur le site, avancer d'un tome fait passer une série
  « Envie » en « En cours » ; l'API ne le fait pas. Si tu veux ce
  comportement, envoie `{"tome_lu": 1, "statut": "cours"}`.
- Une requête avec **une seule** valeur fausse n'en applique **aucune**.
- La couverture, le lien MangaDex, l'identifiant, les dates : non modifiables
  ici (`422 validation`, « Champ inconnu »). La couverture a sa propre route.

Erreurs : `404 serie_introuvable`, `422 validation`.

---

### `POST /series/{id}/couvertures/recherche` — Recherche auto

Lance la **Recherche auto existante du site** (MangaDex) pour la série et
renvoie les propositions.

Le tome cherché est celui du site :

- série `cours`, `attente` ou `envie` → le **tome suivant** (`tome_lu + 1`) ;
- série `termine` ou `abandon` → le tome qu'on en a (`tome_lu`) ;
- jamais en dessous de 1.

Corps **facultatif** : `{"tome": 7}` impose un autre tome (1 à 9999).

```bash
curl -s -X POST "$API/series/42/couvertures/recherche" \
  -H "Authorization: Bearer $LBM_TOKEN"
```

```json
{
  "serie_id": 42,
  "tome": 5,
  "propositions": [
    {
      "serie": "Berserk (Fan Colored)",
      "mangadex_id": "d9e2f26e-c762-41a6-bb71-b6ae18cadde2",
      "tome": 5,
      "exacte": true,
      "url": "https://uploads.mangadex.org/covers/d9e2f26e-c762-41a6-bb71-b6ae18cadde2/2c0f5097-45f9-444b-8fcf-99ae9173d387.jpg.512.jpg"
    }
  ],
  "recherches": { "restantes": 29, "max": 30, "fenetre": 120 },
  "filtre_actif": false
}
```

- Les propositions sont **classées** : la plus probable en premier (même
  classement que le site). `exacte: true` = la vraie couverture du tome
  demandé ; `false` = une couverture de la série, servie en repli.
- `recherches` : ce qu'il reste dans la tranche (`null` en forfait illimité).
- `filtre_actif: true` signale que le filtre de contenu sensible a pu écarter
  des séries (réglage du compte, modifiable sur le site seulement).
- **Une liste vide** veut dire « rien trouvé », mais peut aussi venir d'une
  indisponibilité passagère de MangaDex. Réessaie une fois après quelques
  secondes avant de conclure.

**Coût et limites.** Une recherche fait une quinzaine d'appels à MangaDex :
elle dure **plusieurs secondes** (jusqu'à ~20 s). Prévois un délai
d'attente côté app d'au moins 60 s. Le **quota est celui du compte, partagé
avec le site** : 30 recherches par 2 minutes en forfait standard, aucun
quota en illimité. Les appels sortants sont mis en file pour protéger
l'adresse IP du serveur.

Erreurs : `404 serie_introuvable`, `422 validation` (`tome` invalide),
`429 quota_recherche` (attendre `Retry-After`), `429 service_occupe`.

---

### `PUT /series/{id}/couverture` — enregistrer la proposition choisie

Enregistre l'`url` d'une proposition comme couverture de la série. Le lien
MangaDex est déduit de l'adresse : les couvertures des tomes suivants se
retrouveront en une seule requête.

```bash
curl -s -X PUT "$API/series/42/couverture" \
  -H "Authorization: Bearer $LBM_TOKEN" -H "Content-Type: application/json" \
  -d '{"url":"https://uploads.mangadex.org/covers/d9e2f26e-c762-41a6-bb71-b6ae18cadde2/2c0f5097-45f9-444b-8fcf-99ae9173d387.jpg.512.jpg"}'
```

Réponse : `{"serie": {…}}`.

**Seule une image de couverture de `uploads.mangadex.org/covers/<uuid>/`
est acceptée** — telle que renvoyée par la recherche. Toute autre adresse
(autre site, `http://`, paramètres `?…`, `..`, `data:`, `javascript:`) est
refusée en `422`. L'API ne permet donc pas de faire afficher n'importe
quelle image sur la fiche. Si la série avait une image envoyée par fichier
sur le site, elle est remplacée et le fichier orphelin est supprimé.

Erreurs : `404 serie_introuvable`, `422 validation`, `400 corps_requis`.

---

## 4. Scénario type

```bash
# 1. La série est-elle déjà là ?
curl -s -G "$API/series" --data-urlencode "q=Berserk" -H "Authorization: Bearer $LBM_TOKEN"

# 2a. Non → l'ajouter
curl -s -X POST "$API/series" -H "Authorization: Bearer $LBM_TOKEN" -H "Content-Type: application/json" \
  -d '{"titre":"Berserk","auteur":"Kentaro Miura","tome_lu":4}'
# 2b. Oui → avancer le tome lu
curl -s -X PATCH "$API/series/42" -H "Authorization: Bearer $LBM_TOKEN" -H "Content-Type: application/json" \
  -d '{"tome_lu":5}'

# 3. Chercher la couverture du tome suivant
curl -s -X POST "$API/series/42/couvertures/recherche" -H "Authorization: Bearer $LBM_TOKEN"

# 4. L'utilisateur choisit une proposition → l'enregistrer
curl -s -X PUT "$API/series/42/couverture" -H "Authorization: Bearer $LBM_TOKEN" -H "Content-Type: application/json" \
  -d '{"url":"<url de la proposition>"}'
```

Exemple côté serveur Node (≥ 18, `fetch` natif) :

```js
const API = 'https://livre.sylvestre.ovh/api/v1';
const TOKEN = process.env.LBM_TOKEN; // jamais en dur, jamais côté navigateur

async function lbm(method, path, body) {
  const res = await fetch(API + path, {
    method,
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      ...(body ? { 'Content-Type': 'application/json' } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
    signal: AbortSignal.timeout(60_000), // la Recherche auto peut durer ~20 s
  });
  const json = await res.json();
  if (!res.ok) {
    const e = new Error(json.erreur?.message ?? `HTTP ${res.status}`);
    e.status = res.status;
    e.code = json.erreur?.code;          // « validation », « quota_recherche »…
    e.champs = json.erreur?.champs;
    e.retryAfter = Number(res.headers.get('retry-after')) || undefined;
    throw e;
  }
  return json;
}

// await lbm('PATCH', '/series/42', { tome_lu: 5 });
```

À prévoir côté app :

- `401` → **arrêter** (jeton révoqué ou faux) et alerter ; ne pas réessayer en boucle.
- `429` → attendre `Retry-After` secondes, puis réessayer.
- `5xx`, coupure réseau → réessayer avec une pause croissante, **sans** rejouer
  un `POST /series` sans avoir vérifié qu'il n'est pas déjà passé.

---

## 5. Sécurité — ce qui est garanti

| Sujet | Mécanisme |
|---|---|
| Transport | HTTPS obligatoire. En HTTP la requête est **refusée** (pas redirigée : le jeton serait déjà parti en clair). Un jeton envoyé ainsi est à révoquer. HSTS actif sur le domaine. |
| Authentification | Jeton de 256 bits aléatoires. Seule son empreinte SHA-256 est en base. Comparaison par recherche de l'empreinte. Révocable. |
| Cloisonnement | Toutes les requêtes SQL filtrent sur ton compte. La série d'un autre compte est `404` (pas `403` : on ne confirme pas qu'elle existe). |
| Injections | Requêtes SQL **préparées** exclusivement ; les noms de colonnes d'un `UPDATE` viennent d'une liste fermée, jamais du client. |
| Entrées | Validation stricte (types, bornes, champs inconnus refusés, 16 Ko max). |
| Couvertures | Seules les adresses `uploads.mangadex.org/covers/…` sont enregistrables : pas de lien arbitraire, pas de requête sortante vers une adresse du client (SSRF). |
| CORS | Aucun en-tête `Access-Control-*` : un navigateur ne peut pas appeler l'API depuis une autre origine. |
| Sessions | Aucune session, aucun cookie. |
| Cache | `Cache-Control: no-store` sur toutes les réponses. |
| Force brute | Jetons refusés comptés par adresse IP, blocage croissant. |
| Débit | 60 requêtes/minute par jeton ; quotas de séries et de recherches partagés avec le site. |
| Journaux | Les refus et la création/révocation d'un jeton sont journalisés (adresse IP, raison). **Jamais le jeton.** |
| Erreurs | Sans détail interne. |

Réglages (dans le `.env` du site, tous bornés) : `API_REQUETES_MAX`,
`API_FENETRE`, `API_ECHECS_MAX`, `API_ECHECS_BLOCAGE`.

---

## 6. Décisions de conception

Pour mémoire, les choix faits sans te demander (détails internes) :

- Le champ `auteur` regroupe « sous-titre / auteur » : c'est la colonne unique du site.
- `tome_lu` (JSON) ↔ `tome_actuel` (base). `tome_suivant` est calculé, jamais stocké.
- Pas d'enveloppe `ok: true` : le code HTTP dit déjà succès ou échec.
- Les routes sont en français (`/series`, `/couverture`), comme le site.
- Les noms de paramètres de page sont `limite` et `decalage` (pas `limit` / `offset`).
- `PATCH` et non `PUT` pour modifier : on n'envoie que ce qui change.
- La Recherche auto passe par **`chercher_couvertures()`**, la fonction du site :
  mêmes résultats, même classement, même quota, même filtre de contenu sensible.
- Le jeton est sans expiration par choix ; la colonne `expire` existe pour
  qu'on puisse en imposer une plus tard sans changer le schéma.
