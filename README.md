# Comment Moderation API

![PHP](https://img.shields.io/badge/PHP-8.5-777bb4)
![Symfony](https://img.shields.io/badge/Symfony-8-000000)
![Tests](https://img.shields.io/badge/PHPUnit-131%20tests-brightgreen)
![Architecture](https://img.shields.io/badge/Architecture-DDD%20%2B%20CQRS-blue)

Service Symfony de modération asynchrone de commentaires, avec API REST, JWT opérateur, modération automatique par LLM OpenAI-compatible, fallback en revue manuelle, ingestion de commentaires Facebook, Docker Compose et manifests Kubernetes.

English version is available below: [English documentation](#english-documentation).

## Documentation française

### Fonctionnalités

- Soumission publique de commentaires via `POST /comments`.
- Traitement asynchrone avec Symfony Messenger.
- Modération automatique via LLM local ou externe compatible OpenAI.
- Fallback sécurisé en `pending` si le LLM est absent, indisponible ou non fiable.
- Modération manuelle protégée par JWT RS256.
- Recherche et lecture des commentaires avec filtres par statut.
- Webhook Facebook signé pour les commentaires d'articles/pages.
- Commandes Symfony CLI pour exploiter et tester l'API.
- Environnement Docker complet avec API, worker, Nginx, init et Ollama.
- Manifests Kubernetes d'exemple.
- Collection Postman incluse.

### Stack technique

- PHP 8.5
- Symfony 8
- Doctrine ORM et migrations
- Symfony Messenger avec transport Doctrine
- LexikJWTAuthenticationBundle
- Symfony HttpClient
- PHPUnit, PHPStan, PHPat
- Docker Compose
- Kubernetes YAML

### Workflow de modération

```text
Client ou Facebook
  -> API Symfony
  -> Commentaire enregistré en pending
  -> Message Messenger queue new_comments
  -> Worker
  -> LLM si configuré
  -> published / rejected / pending manual_review_required
```

Si aucun LLM n'est correctement renseigné, aucune décision automatique n'est inventée : le commentaire reste en `pending` pour modération manuelle.

Les nouveaux commentaires sont mis en queue indépendamment de l'article. En cas de retard ou de saturation du LLM, l'opérateur peut relancer un batch global des plus anciens commentaires `pending` via `app:comments:moderate-batch` ou `POST /comments/moderation/batch`. Les payloads HTTP sont bornés, les soumissions sont rate-limitées et le worker limite le débit envoyé au fournisseur LLM.

### Démarrage rapide avec Docker

Depuis la racine du projet :

```bash
cp .env.example .env
cp .env.docker.example .env.docker
```

Adapter ensuite `.env` pour un lancement Symfony local et `.env.docker` pour Docker. Ces fichiers réels restent locaux et ne doivent pas être commités.

```bash
./launch.sh
```

Le script prépare `.env.docker`, génère les secrets locaux nécessaires, construit les images, démarre les services, applique les migrations et attend que la stack soit prête.

Pour redémarrer sans reconstruire :

```bash
./launch.sh --no-build
```

Endpoints locaux :

- API : `http://127.0.0.1:8000`
- Santé : `http://127.0.0.1:8000/health`
- Documentation OpenAPI : `http://127.0.0.1:8000/doc/`
- Ollama exposé côté hôte : `http://127.0.0.1:11435`

Vérifier la santé :

```bash
curl -fsS http://127.0.0.1:8000/health
```

Résultat attendu :

```json
{"status":"ok"}
```

### Configuration

Copier ou laisser `launch.sh` créer `.env.docker`, puis ajuster les variables selon le besoin.

LLM local via Ollama dans Docker :

```dotenv
MODERATION_LLM_BASE_URL=http://ollama:11434/v1
MODERATION_LLM_MODEL=llama3.2
MODERATION_LLM_API_KEY=
MODERATION_LLM_TIMEOUT=30
```

LM Studio ou autre fournisseur compatible OpenAI lancé sur la machine hôte :

```dotenv
MODERATION_LLM_BASE_URL=http://host.docker.internal:1234/v1
MODERATION_LLM_MODEL=local-model
MODERATION_LLM_API_KEY=
MODERATION_LLM_TIMEOUT=30
```

LLM externe compatible OpenAI :

```dotenv
MODERATION_LLM_BASE_URL=https://api.example.com/v1
MODERATION_LLM_MODEL=moderator-model
MODERATION_LLM_API_KEY=votre-cle-secrete
MODERATION_LLM_TIMEOUT=10
```

Mode revue manuelle uniquement :

```dotenv
MODERATION_LLM_BASE_URL=
MODERATION_LLM_MODEL=
MODERATION_LLM_API_KEY=
```

Facebook :

```dotenv
FACEBOOK_WEBHOOK_VERIFY_TOKEN=token-partage-avec-meta
FACEBOOK_APP_SECRET=secret-app-meta-hors-git
```

Règles de modération envoyées au LLM :

```yaml
# config/packages/moderation.yaml
parameters:
    app.moderation.llm_rules:
        - 'Reject abusive political insults, dehumanizing language, and comparisons to Nazi ideology when used as an insult against a person or public figure.'
        - 'Reject direct harassment, personal attacks, threats, hate speech, discrimination, defamation, terrorism praise, and child sexual content.'
        - 'Publish respectful disagreement, criticism, satire, and non-abusive political opinions.'
```

Modifier ce fichier puis redémarrer la stack pour que Symfony recharge la configuration.

Ne versionnez jamais `.env.docker`, les clés JWT, les tokens, les passphrases ou les clés API.

### Tester l'API avec Docker

Générer un JWT opérateur :

```bash
moderator_jwt=$(docker compose --env-file .env.docker --profile tools run --rm token --subject=alice)
```

Créer un commentaire :

```bash
submission=$(curl -fsS -X POST http://127.0.0.1:8000/comments \
  -H 'Content-Type: application/json' \
  --data '{"publisher":"site-a","source":"article-42","authorId":"user-7","body":"Merci pour cet article clair."}')

echo "$submission"
```

Extraire l'identifiant :

```bash
comment_id=$(printf '%s' "$submission" | docker compose --env-file .env.docker exec -T php php -r '$p=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $p["id"];')
```

Lire le commentaire :

```bash
curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  "http://127.0.0.1:8000/comments/$comment_id"
```

Lister par statut :

```bash
curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  "http://127.0.0.1:8000/comments?status=pending"
```

Lister les commentaires publiés d'un article :

```bash
curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  "http://127.0.0.1:8000/comments?source=article-42&status=published"
```

Modérer manuellement :

```bash
curl -fsS -X POST \
  -H "Authorization: Bearer $moderator_jwt" \
  -H 'Content-Type: application/json' \
  --data '{"status":"published","reason":"validated manually"}' \
  "http://127.0.0.1:8000/comments/$comment_id/moderation"
```

Tester le statut LLM :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:llm:status
```

Forcer une modération LLM sur un commentaire existant :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:moderate-llm "$comment_id"
```

### Commandes Symfony CLI

Toutes les commandes renvoient du JSON exploitable.

```bash
php bin/console app:comments:add
php bin/console app:comments:list --status=pending
php bin/console app:comments:list --source=article-42 --status=published
php bin/console app:comments:status <comment-id>
php bin/console app:comments:moderate <comment-id> --status=published --reason="validated"
php bin/console app:comments:moderate-llm <comment-id>
php bin/console app:comments:moderate-batch --limit=20
php bin/console app:llm:status
php bin/console app:llm:moderate "Texte à modérer"
php bin/console app:jwt:issue-moderator --subject=alice
```

Avec Docker, préfixer par :

```bash
docker compose --env-file .env.docker exec -T php php bin/console
```

### Workflow Facebook

Endpoints :

- `GET /webhooks/facebook/comments` : challenge de vérification Meta.
- `POST /webhooks/facebook/comments` : réception des événements signés.

Le système vérifie `X-Hub-Signature-256`, mappe le commentaire Facebook vers le modèle interne, puis utilise le même pipeline de modération que `POST /comments`.

Limite actuelle : la décision est appliquée dans notre système. Le service ne masque/supprime pas encore automatiquement le commentaire sur Facebook via Graph API.

### Tests et qualité

Vérifications locales :

```bash
composer validate --strict
php bin/console lint:container
php bin/console lint:yaml config/ k8s/
vendor/bin/phpunit --display-deprecations
vendor/bin/phpstan analyse --no-progress
vendor/bin/phparkitect check
docker compose --env-file .env.docker.example config --quiet
docker compose --profile tools --env-file .env.docker.example config --quiet
```

Collection Postman :

```text
postman/comment-moderation.postman_collection.json
```

Guides complets :

- [Documentation utilisateur](docs/USER_GUIDE.md)
- [Documentation développeur](docs/DEVELOPMENT.md)

### Sécurité

- JWT RS256 avec expiration de 900 secondes.
- Jetons acceptés uniquement via `Authorization: Bearer`.
- Routes opérateur protégées par `ROLE_MODERATOR`.
- LLM externe autorisé uniquement en HTTPS, sauf HTTP loopback local.
- Timeouts, taille de corps et débit applicatif bornés.
- Pas de log de clé API, token, prompt brut ou secret.
- `.env.docker`, clés JWT et secrets locaux sont ignorés par Git.

### Déploiement

Docker Compose :

```bash
./launch.sh
```

Kubernetes :

```bash
kubectl apply -f k8s/comment-moderation.yaml
```

Avant production, remplacer les placeholders du Secret Kubernetes, utiliser TLS réel, une base durable adaptée et un secret manager.

### Usage de l'IA

Codex a servi à structurer le projet, coder certaines parties, améliorer la structure du code, vérifier les aspects sécurité et la conformité avec la demande initiale, proposer des axes d'amélioration, rédiger la documentation et écrire les tests.

---

## English documentation

Symfony service for asynchronous comment moderation, with a REST API, operator JWTs, automatic moderation through an OpenAI-compatible LLM, manual-review fallback, Facebook comment ingestion, Docker Compose and Kubernetes manifests.

### Features

- Public comment submission through `POST /comments`.
- Asynchronous processing with Symfony Messenger.
- Automatic moderation through a local or external OpenAI-compatible LLM.
- Safe fallback to `pending` when the LLM is missing, unavailable or unreliable.
- Manual moderation protected by RS256 JWT.
- Comment search and read endpoints with status filters.
- Signed Facebook webhook for article/page comments.
- Symfony CLI commands for operations and tests.
- Full Docker environment with API, worker, Nginx, init job and Ollama.
- Example Kubernetes manifests.
- Postman collection included.

### Technical stack

- PHP 8.5
- Symfony 8
- Doctrine ORM and migrations
- Symfony Messenger with Doctrine transport
- LexikJWTAuthenticationBundle
- Symfony HttpClient
- PHPUnit, PHPStan, PHPat
- Docker Compose
- Kubernetes YAML

### Moderation workflow

```text
Client or Facebook
  -> Symfony API
  -> Comment stored as pending
  -> Messenger message on the new_comments queue
  -> Worker
  -> LLM if configured
  -> published / rejected / pending manual_review_required
```

If no LLM is configured correctly, the system does not invent an automatic decision. The comment stays `pending` for manual moderation.

New comments are queued independently of the article. If moderation falls behind or the LLM saturates, an operator can run a global batch over the oldest `pending` comments through `app:comments:moderate-batch` or `POST /comments/moderation/batch`. HTTP payloads are bounded, submissions are rate-limited and the worker throttles traffic sent to the LLM provider.

### Quick start with Docker

From the project root:

```bash
cp .env.example .env
cp .env.docker.example .env.docker
```

Then adjust `.env` for local Symfony usage and `.env.docker` for Docker. These real files stay local and must not be committed.

```bash
./launch.sh
```

The script prepares `.env.docker`, generates required local secrets, builds images, starts services, runs migrations and waits until the stack is ready.

Restart without rebuilding:

```bash
./launch.sh --no-build
```

Local endpoints:

- API: `http://127.0.0.1:8000`
- Health: `http://127.0.0.1:8000/health`
- OpenAPI documentation: `http://127.0.0.1:8000/doc/`
- Ollama exposed on host: `http://127.0.0.1:11435`

Health check:

```bash
curl -fsS http://127.0.0.1:8000/health
```

Expected response:

```json
{"status":"ok"}
```

### Configuration

Let `launch.sh` create `.env.docker`, then adjust variables as needed.

Local Ollama LLM in Docker:

```dotenv
MODERATION_LLM_BASE_URL=http://ollama:11434/v1
MODERATION_LLM_MODEL=llama3.2
MODERATION_LLM_API_KEY=
MODERATION_LLM_TIMEOUT=30
```

LM Studio or another OpenAI-compatible provider running on the host machine:

```dotenv
MODERATION_LLM_BASE_URL=http://host.docker.internal:1234/v1
MODERATION_LLM_MODEL=local-model
MODERATION_LLM_API_KEY=
MODERATION_LLM_TIMEOUT=30
```

External OpenAI-compatible LLM:

```dotenv
MODERATION_LLM_BASE_URL=https://api.example.com/v1
MODERATION_LLM_MODEL=moderator-model
MODERATION_LLM_API_KEY=your-secret-key
MODERATION_LLM_TIMEOUT=10
```

Manual-review-only mode:

```dotenv
MODERATION_LLM_BASE_URL=
MODERATION_LLM_MODEL=
MODERATION_LLM_API_KEY=
```

Facebook:

```dotenv
FACEBOOK_WEBHOOK_VERIFY_TOKEN=shared-meta-token
FACEBOOK_APP_SECRET=meta-app-secret-outside-git
```

Moderation rules sent to the LLM:

```yaml
# config/packages/moderation.yaml
parameters:
    app.moderation.llm_rules:
        - 'Reject abusive political insults, dehumanizing language, and comparisons to Nazi ideology when used as an insult against a person or public figure.'
        - 'Reject direct harassment, personal attacks, threats, hate speech, discrimination, defamation, terrorism praise, and child sexual content.'
        - 'Publish respectful disagreement, criticism, satire, and non-abusive political opinions.'
```

Update this file, then restart the stack so Symfony reloads the configuration.

Never commit `.env.docker`, JWT keys, tokens, passphrases or API keys.

### Test the API with Docker

Generate an operator JWT:

```bash
moderator_jwt=$(docker compose --env-file .env.docker --profile tools run --rm token --subject=alice)
```

Create a comment:

```bash
submission=$(curl -fsS -X POST http://127.0.0.1:8000/comments \
  -H 'Content-Type: application/json' \
  --data '{"publisher":"site-a","source":"article-42","authorId":"user-7","body":"Thanks for this clear article."}')

echo "$submission"
```

Extract its identifier:

```bash
comment_id=$(printf '%s' "$submission" | docker compose --env-file .env.docker exec -T php php -r '$p=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $p["id"];')
```

Read the comment:

```bash
curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  "http://127.0.0.1:8000/comments/$comment_id"
```

List by status:

```bash
curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  "http://127.0.0.1:8000/comments?status=pending"
```

List published comments for one article:

```bash
curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  "http://127.0.0.1:8000/comments?source=article-42&status=published"
```

Manual moderation:

```bash
curl -fsS -X POST \
  -H "Authorization: Bearer $moderator_jwt" \
  -H 'Content-Type: application/json' \
  --data '{"status":"published","reason":"validated manually"}' \
  "http://127.0.0.1:8000/comments/$comment_id/moderation"
```

Check LLM status:

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:llm:status
```

Force LLM moderation on an existing comment:

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:moderate-llm "$comment_id"
```

### Symfony CLI commands

All commands return machine-readable JSON.

```bash
php bin/console app:comments:add
php bin/console app:comments:list --status=pending
php bin/console app:comments:list --source=article-42 --status=published
php bin/console app:comments:status <comment-id>
php bin/console app:comments:moderate <comment-id> --status=published --reason="validated"
php bin/console app:comments:moderate-llm <comment-id>
php bin/console app:comments:moderate-batch --limit=20
php bin/console app:llm:status
php bin/console app:llm:moderate "Text to moderate"
php bin/console app:jwt:issue-moderator --subject=alice
```

With Docker, prefix commands with:

```bash
docker compose --env-file .env.docker exec -T php php bin/console
```

### Facebook workflow

Endpoints:

- `GET /webhooks/facebook/comments`: Meta verification challenge.
- `POST /webhooks/facebook/comments`: signed event ingestion.

The system verifies `X-Hub-Signature-256`, maps the Facebook comment to the internal model, then sends it through the same moderation pipeline as `POST /comments`.

Current limitation: the decision is applied inside this system only. The service does not yet hide/delete comments on Facebook through Graph API.

### Tests and quality

Local checks:

```bash
composer validate --strict
php bin/console lint:container
php bin/console lint:yaml config/ k8s/
vendor/bin/phpunit --display-deprecations
vendor/bin/phpstan analyse --no-progress
vendor/bin/phparkitect check
docker compose --env-file .env.docker.example config --quiet
docker compose --profile tools --env-file .env.docker.example config --quiet
```

Postman collection:

```text
postman/comment-moderation.postman_collection.json
```

Full guides:

- [User guide](docs/USER_GUIDE.md)
- [Developer guide](docs/DEVELOPMENT.md)

### Security

- RS256 JWTs with a 900-second TTL.
- Tokens accepted only through `Authorization: Bearer`.
- Operator routes protected by `ROLE_MODERATOR`.
- External LLM URLs must use HTTPS, except local loopback HTTP.
- Request body size, timeouts and application throughput are bounded.
- API keys, tokens, raw prompts and secrets are not logged.
- `.env.docker`, JWT keys and local secrets are ignored by Git.

### Deployment

Docker Compose:

```bash
./launch.sh
```

Kubernetes:

```bash
kubectl apply -f k8s/comment-moderation.yaml
```

Before production, replace Kubernetes Secret placeholders, use real TLS, a durable database and a secret manager.

### AI usage

Codex was used to structure the project, code some parts, improve the code structure, verify security aspects and compliance with the initial request, propose improvement areas, write the documentation and write the tests.
