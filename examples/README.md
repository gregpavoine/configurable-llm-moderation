# Exemples complets : ajout, liste et modération

Ce document donne les commandes prêtes à copier pour tester le service de modération avec Docker.

Il couvre deux usages :

- CLI Symfony : pratique pour exploiter et diagnostiquer depuis le conteneur `php`.
- API HTTP : pratique pour tester le comportement réel exposé aux clients.

Les exemples supposent que la stack Docker est lancée :

```bash
./launch.sh
```

ou, si les images existent déjà :

```bash
./launch.sh --no-build
```

Vérifier la santé de l'API :

```bash
curl -fsS http://127.0.0.1:8000/health
```

Résultat attendu :

```json
{"status":"ok"}
```

La documentation OpenAPI est disponible ici :

```text
http://127.0.0.1:8000/doc/
http://127.0.0.1:8000/doc/openapi.json
```

## Variables utiles

Définir ces variables dans le terminal hôte pour simplifier les exemples :

```bash
api_base_url=http://127.0.0.1:8000
```

Pour les commandes API protégées, générer un JWT modérateur :

```bash
moderator_jwt=$(docker compose --env-file .env.docker --profile tools run --rm token --subject=alice)
```

Le JWT expire après 900 secondes. Régénérez-le si une requête retourne `401`.

## Préparer le LLM

Dans `.env.docker`, configurer un fournisseur OpenAI-compatible. Pour Ollama dans Docker :

```dotenv
MODERATION_LLM_BASE_URL=http://ollama:11434/v1
MODERATION_LLM_MODEL=gpt-oss-safeguard:20b
MODERATION_LLM_API_KEY=
MODERATION_LLM_TIMEOUT=180
```

Pour LM Studio lancé sur la machine hôte :

```dotenv
MODERATION_LLM_BASE_URL=http://host.docker.internal:1234/v1
MODERATION_LLM_MODEL=local-model
MODERATION_LLM_API_KEY=
MODERATION_LLM_TIMEOUT=60
```

Pour une API externe compatible OpenAI :

```dotenv
MODERATION_LLM_BASE_URL=https://api.example.com/v1
MODERATION_LLM_MODEL=moderator-model
MODERATION_LLM_API_KEY=votre-cle-secrete
MODERATION_LLM_TIMEOUT=30
```

Après modification :

```bash
docker compose --env-file .env.docker up -d --force-recreate php worker
```

Vérifier l'état du fournisseur :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:llm:status
```

Résultat attendu avec un LLM disponible :

```json
{"configured":true,"available":true,"reason":null,"providerHost":"ollama","model":"gpt-oss-safeguard:20b"}
```

Si `configured=false`, la configuration est absente ou invalide.

Si `available=false`, le modèle n'est pas joignable, pas chargé, ou le timeout est trop court.

Les règles métier envoyées au LLM sont dans :

```text
config/packages/moderation.yaml
```

## Section CLI

Toutes les commandes CLI ci-dessous s'exécutent dans le conteneur `php` et renvoient du JSON.

Le préfixe commun est :

```bash
docker compose --env-file .env.docker exec -T php php bin/console
```

### 1. Ajouter un commentaire

Commentaire acceptable :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:add \
  --publisher=site-a \
  --source=article-42 \
  --author-id=user-7 \
  --body="Merci pour cet article clair."
```

Commentaire à rejeter :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:add \
  --publisher=site-a \
  --source=article-42 \
  --author-id=user-8 \
  --body="MACRON tu es un nazi"
```

Réponse type :

```json
{"id":"019ff02e-0113-763c-bb89-d393359af9fe","status":"pending"}
```

Conserver l'identifiant :

```bash
comment_id=019ff02e-0113-763c-bb89-d393359af9fe
```

### 2. Lister les commentaires

Liste par défaut :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:list
```

Filtrer par statut :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:list --status=pending
docker compose --env-file .env.docker exec -T php php bin/console app:comments:list --status=published
docker compose --env-file .env.docker exec -T php php bin/console app:comments:list --status=rejected
```

Filtrer par publisher :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:list --publisher=site-a
```

Pagination :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:list \
  --limit=10 \
  --offset=0
```

Combiner les filtres :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:list \
  --publisher=site-a \
  --status=rejected \
  --limit=20 \
  --offset=0
```

### 3. Vérifier un commentaire

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:status "$comment_id"
```

Réponse type :

```json
{
  "id": "019ff02e-0113-763c-bb89-d393359af9fe",
  "publisher": "site-a",
  "source": "article-42",
  "authorId": "user-8",
  "body": "MACRON tu es un nazi",
  "status": "pending",
  "moderationReason": "manual_review_required",
  "moderatedAt": null,
  "createdAt": "2026-08-11T10:00:00+00:00"
}
```

### 4. Modérer manuellement

Rejeter :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:moderate "$comment_id" \
  --status=rejected \
  --reason="Insulte politique abusive"
```

Publier :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:moderate "$comment_id" \
  --status=published \
  --reason="Commentaire acceptable"
```

Un commentaire ne peut être modéré manuellement que s'il est encore `pending`.

Si le commentaire est déjà `published` ou `rejected`, l'application retourne une erreur de transition.

### 5. Tester le LLM sur un texte brut

Cette commande ne crée pas de commentaire en base. Elle sert à tester la décision du fournisseur.

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:llm:moderate \
  --body="MACRON tu es un nazi"
```

Résultat attendu :

```json
{"status":"rejected","reason":"..."}
```

Tester un commentaire acceptable :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:llm:moderate \
  --body="Merci pour cet article clair."
```

Résultat attendu :

```json
{"status":"published","reason":"..."}
```

### 6. Relancer une modération LLM sur un commentaire existant

Cette commande applique la décision au commentaire en base si le commentaire est encore `pending`.

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:moderate-llm "$comment_id"
```

Vérifier ensuite :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:status "$comment_id"
```

### 7. Modérer un batch de commentaires pending

Le batch prend les plus anciens commentaires `pending`, tous articles et toutes sources confondus.

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:moderate-batch --limit=20
```

Réponse type :

```json
{
  "items": [],
  "processed": 0,
  "limit": 20
}
```

Créer plusieurs commentaires puis lancer un batch court :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:add \
  --publisher=site-a \
  --source=article-1 \
  --body="Commentaire article 1"

docker compose --env-file .env.docker exec -T php php bin/console app:comments:add \
  --publisher=site-a \
  --source=article-2 \
  --body="MACRON tu es un nazi"

docker compose --env-file .env.docker exec -T php php bin/console app:comments:moderate-batch --limit=2
```

### 8. Tester un commentaire Facebook en CLI indirecte

Il n'y a pas de commande CLI dédiée à Facebook, car Facebook entre par webhook HTTP signé.

Pour diagnostiquer ensuite le commentaire Facebook créé, utiliser :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:list \
  --publisher=facebook_page:page-123
```

## Section API

Les exemples API utilisent `curl` depuis la machine hôte.

Routes principales :

```text
POST       /comments
GET        /comments
GET        /comments/{id}
POST       /comments/{id}/moderation
POST       /comments/moderation/batch
GET|HEAD   /webhooks/facebook/comments
POST       /webhooks/facebook/comments
GET        /doc/
GET        /doc/openapi.json
```

### 1. Ajouter un commentaire

```bash
submission=$(curl -fsS -X POST "$api_base_url/comments" \
  -H 'Content-Type: application/json' \
  --data '{
    "publisher": "site-a",
    "source": "article-42",
    "authorId": "user-7",
    "body": "Merci pour cet article clair."
  }')

echo "$submission"
```

Réponse attendue :

```json
{"id":"019ff02e-0113-763c-bb89-d393359af9fe","status":"pending"}
```

Extraire l'identifiant avec PHP dans le conteneur :

```bash
comment_id=$(printf '%s' "$submission" | docker compose --env-file .env.docker exec -T php php -r '$p=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $p["id"];')
```

Créer un commentaire explicitement problématique :

```bash
submission=$(curl -fsS -X POST "$api_base_url/comments" \
  -H 'Content-Type: application/json' \
  --data '{
    "publisher": "site-a",
    "source": "article-42",
    "authorId": "user-8",
    "body": "MACRON tu es un nazi"
  }')

comment_id=$(printf '%s' "$submission" | docker compose --env-file .env.docker exec -T php php -r '$p=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $p["id"];')
```

### 2. Lister les commentaires

Les routes de lecture nécessitent un JWT :

```bash
moderator_jwt=$(docker compose --env-file .env.docker --profile tools run --rm token --subject=alice)
```

Liste par défaut :

```bash
curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  "$api_base_url/comments"
```

Filtrer par statut :

```bash
curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  "$api_base_url/comments?status=pending"

curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  "$api_base_url/comments?status=published"

curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  "$api_base_url/comments?status=rejected"
```

Filtrer par publisher :

```bash
curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  "$api_base_url/comments?publisher=site-a"
```

Pagination :

```bash
curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  "$api_base_url/comments?limit=10&offset=0"
```

Combiner les filtres :

```bash
curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  "$api_base_url/comments?publisher=site-a&status=rejected&limit=20&offset=0"
```

### 3. Vérifier un commentaire

```bash
curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  "$api_base_url/comments/$comment_id"
```

### 4. Modérer manuellement

Rejeter :

```bash
curl -fsS -X POST \
  -H "Authorization: Bearer $moderator_jwt" \
  -H 'Content-Type: application/json' \
  --data '{"status":"rejected","reason":"Insulte politique abusive"}' \
  "$api_base_url/comments/$comment_id/moderation"
```

Publier :

```bash
curl -fsS -X POST \
  -H "Authorization: Bearer $moderator_jwt" \
  -H 'Content-Type: application/json' \
  --data '{"status":"published","reason":"Commentaire acceptable"}' \
  "$api_base_url/comments/$comment_id/moderation"
```

Réponse attendue :

```json
{
  "id": "019ff02e-0113-763c-bb89-d393359af9fe",
  "status": "rejected",
  "moderationReason": "Insulte politique abusive"
}
```

Un commentaire déjà final retourne `409 Conflict`.

### 5. Attendre la modération automatique

Après `POST /comments`, le worker traite le commentaire de façon asynchrone.

Boucle simple :

```bash
for attempt in $(seq 1 24); do
  response=$(curl -fsS \
    -H "Authorization: Bearer $moderator_jwt" \
    "$api_base_url/comments/$comment_id")

  comment_status=$(printf '%s' "$response" | docker compose --env-file .env.docker exec -T php php -r '$p=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $p["status"];')

  echo "attempt=$attempt status=$comment_status"
  echo "$response"

  [ "$comment_status" != "pending" ] && break
  sleep 5
done
```

Ne pas utiliser une variable shell appelée `status` sous `zsh`, car elle est réservée en lecture seule.

### 6. Modérer un batch via API

Cette route est protégée par JWT modérateur. Elle traite les commentaires `pending` les plus anciens, tous articles confondus.

```bash
curl -fsS -X POST "$api_base_url/comments/moderation/batch" \
  -H "Authorization: Bearer $moderator_jwt" \
  -H 'Content-Type: application/json' \
  --data '{"limit":20}'
```

Réponse attendue :

```json
{
  "items": [],
  "processed": 0,
  "limit": 20
}
```

La limite doit être comprise entre `1` et `100`.

### 7. Tester le webhook Facebook

Configurer `.env.docker` :

```dotenv
FACEBOOK_WEBHOOK_VERIFY_TOKEN=token-partage-avec-meta
FACEBOOK_APP_SECRET=secret-app-meta-hors-git
```

Redémarrer :

```bash
docker compose --env-file .env.docker up -d --force-recreate php worker
```

Tester le challenge Meta :

```bash
curl -i \
  "$api_base_url/webhooks/facebook/comments?hub.mode=subscribe&hub.verify_token=token-partage-avec-meta&hub.challenge=challenge-123"
```

Résultat attendu :

```text
HTTP/1.1 200 OK

challenge-123
```

Préparer un faux commentaire Facebook :

```bash
facebook_payload='{
  "object": "page",
  "entry": [
    {
      "id": "page-123",
      "changes": [
        {
          "field": "feed",
          "value": {
            "item": "comment",
            "verb": "add",
            "comment_id": "facebook-comment-1",
            "post_id": "facebook-post-42",
            "from": {
              "id": "facebook-user-7"
            },
            "message": "MACRON tu es un nazi"
          }
        }
      ]
    }
  ]
}'
```

Signer le payload :

```bash
facebook_signature=$(printf '%s' "$facebook_payload" | docker compose --env-file .env.docker exec -T php php -r '$payload=stream_get_contents(STDIN); echo "sha256=".hash_hmac("sha256", $payload, "secret-app-meta-hors-git");')
```

Envoyer le webhook :

```bash
curl -fsS -X POST "$api_base_url/webhooks/facebook/comments" \
  -H 'Content-Type: application/json' \
  -H "X-Hub-Signature-256: $facebook_signature" \
  --data "$facebook_payload"
```

Résultat attendu :

```json
{"received":1,"ignored":0}
```

Retrouver le commentaire Facebook :

```bash
curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  "$api_base_url/comments?publisher=facebook_page:page-123"
```

Le commentaire créé doit contenir :

```json
{
  "publisher": "facebook_page:page-123",
  "source": "facebook_post:facebook-post-42",
  "authorId": "facebook_user:facebook-user-7",
  "body": "MACRON tu es un nazi"
}
```

### 8. Tester les erreurs courantes

Lecture sans JWT :

```bash
curl -i "$api_base_url/comments"
```

Résultat attendu :

```text
HTTP/1.1 401 Unauthorized
```

JWT invalide :

```bash
curl -i \
  -H 'Authorization: Bearer invalid' \
  "$api_base_url/comments"
```

Résultat attendu :

```text
HTTP/1.1 401 Unauthorized
```

Statut manuel invalide :

```bash
curl -i -X POST \
  -H "Authorization: Bearer $moderator_jwt" \
  -H 'Content-Type: application/json' \
  --data '{"status":"unsupported","reason":"test"}' \
  "$api_base_url/comments/$comment_id/moderation"
```

Résultat attendu :

```text
HTTP/1.1 422 Unprocessable Entity
```

Webhook Facebook avec mauvaise signature :

```bash
curl -i -X POST "$api_base_url/webhooks/facebook/comments" \
  -H 'Content-Type: application/json' \
  -H 'X-Hub-Signature-256: sha256=invalid' \
  --data "$facebook_payload"
```

Résultat attendu :

```text
HTTP/1.1 401 Unauthorized
```

## Résumé des capacités CLI/API

| Besoin | CLI | API |
|---|---:|---:|
| Ajouter un commentaire | `app:comments:add` | `POST /comments` |
| Lister les commentaires | `app:comments:list` | `GET /comments` |
| Filtrer par statut | `--status=` | `?status=` |
| Filtrer par publisher | `--publisher=` | `?publisher=` |
| Paginer | `--limit= --offset=` | `?limit=&offset=` |
| Voir un commentaire | `app:comments:status` | `GET /comments/{id}` |
| Modérer manuellement | `app:comments:moderate` | `POST /comments/{id}/moderation` |
| Modérer un batch pending | `app:comments:moderate-batch` | `POST /comments/moderation/batch` |
| Relancer le LLM sur un commentaire | `app:comments:moderate-llm` | non exposé actuellement |
| Tester le LLM sur texte brut | `app:llm:moderate` | non exposé actuellement |
| Checker le LLM | `app:llm:status` | non exposé actuellement |
| Générer un JWT | `app:jwt:issue-moderator` | non exposé volontairement |
| Recevoir Facebook | indirect | `GET/POST /webhooks/facebook/comments` |

## Notes importantes

- `POST /comments` est public.
- `GET /comments`, `GET /comments/{id}`, `POST /comments/{id}/moderation` et `POST /comments/moderation/batch` nécessitent un JWT.
- La modération manuelle ne s'applique qu'aux commentaires `pending`.
- Si le LLM est absent ou non fiable, le commentaire reste `pending`.
- Si le LLM répond correctement, le worker passe le commentaire en `published` ou `rejected`.
- La queue `new_comments` traite les nouveaux commentaires indépendamment de l'article.
- Le batch traite les commentaires `pending` les plus anciens, tous articles confondus, avec une limite maximale de `100`.
- En cas de flood, l'ingestion est protégée par des limites HTTP/applicatives et le worker ralentit les appels LLM via le limiter `moderation_provider`.
- La décision Facebook est appliquée dans la base interne. Le système ne masque/supprime pas encore automatiquement le commentaire côté Facebook via Graph API.
