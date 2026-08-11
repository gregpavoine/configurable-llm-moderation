# Documentation développeur

Ce document décrit l'architecture, la configuration, les workflows techniques, les tests et les règles d'exploitation du service de modération.

## 1. Stack

- PHP 8.5
- Symfony 8
- Doctrine ORM et migrations
- Symfony Messenger avec transport Doctrine
- LexikJWTAuthenticationBundle pour les JWT RS256
- Symfony HttpClient pour le fournisseur LLM
- Docker Compose pour l'environnement local complet
- Kubernetes manifests YAML pour un déploiement exemple
- PHPUnit, PHPStan, PHPat, Composer audit et linters Symfony

## 2. Architecture applicative

Le projet suit une séparation DDD/CQRS légère.

`src/Domain` :

- agrégat `Comment`;
- value object `CommentId`;
- enum `ModerationStatus`;
- exceptions domaine;
- ports `CommentRepository`, `BannedUserRepository`, `ModerationService`.

`src/Application` :

- commandes `SubmitComment`, `ModerateComment`, `ManuallyModerateComment`;
- queries `GetComment`, `SearchComments`;
- handlers applicatifs;
- vues de sortie `CommentView`, `CommentSearchResult`.

`src/Infrastructure` :

- repositories Doctrine;
- client LLM OpenAI-compatible;
- sécurité JWT;
- limites d'admission HTTP;
- kernel Symfony.

`src/UI` :

- contrôleurs HTTP REST;
- webhook Facebook;
- commandes CLI JSON pour les commentaires, la modération et le statut LLM.

## 3. Cycle d'un commentaire

Soumission directe :

```text
POST /comments
  -> validation payload
  -> SubmitCommentCommand
  -> rejet immediat si auteur banni
  -> persistance pending
  -> dispatch ModerateCommentCommand
  -> worker Messenger
  -> ModerationService
  -> published, rejected ou pending/manual_review_required
```

Soumission Facebook :

```text
POST /webhooks/facebook/comments
  -> limite 65 536 octets
  -> verification X-Hub-Signature-256
  -> parsing payload Meta
  -> mapping vers SubmitCommentCommand
  -> meme pipeline que POST /comments
```

Décision manuelle :

```text
POST /comments/{id}/moderation
  -> JWT ROLE_MODERATOR
  -> ManuallyModerateCommentCommand
  -> transition pending -> published/rejected
```

Un commentaire final est immuable. Les conflits de transition remontent en `409`.

## 4. Modération LLM

Le port domaine est `Gsoi\CommentModeration\Domain\Moderation\ModerationService`.

L'implémentation runtime est `OpenAiCompatibleModerationService`. Elle est activée seulement si la configuration est cohérente.

Variables :

```dotenv
MODERATION_LLM_BASE_URL=
MODERATION_LLM_MODEL=
MODERATION_LLM_API_KEY=
MODERATION_LLM_TIMEOUT=10
```

Règles :

- base URL et model présents : appel fournisseur;
- clé API vide autorisée pour un fournisseur local de confiance;
- HTTPS obligatoire sauf HTTP loopback local;
- timeouts bornés;
- redirects désactivés;
- réponse JSON strictement validée;
- proxy environnement contourné pour limiter le risque de fuite;
- aucune clé, aucun token, aucun prompt brut et aucun corps de commentaire ne doit être loggé.

Réponse attendue du fournisseur : décision structurée avec un statut final `published` ou `rejected` et une raison bornée.

Tout échec fournisseur produit une décision différée : le commentaire reste `pending` avec une raison opérationnelle, généralement `manual_review_required`.

## 5. Webhook Facebook

Endpoint :

```text
GET  /webhooks/facebook/comments
POST /webhooks/facebook/comments
```

Variables :

```dotenv
FACEBOOK_WEBHOOK_VERIFY_TOKEN=
FACEBOOK_APP_SECRET=
```

`GET` gère le challenge Meta :

- `hub.mode=subscribe`;
- `hub.verify_token` égal à `FACEBOOK_WEBHOOK_VERIFY_TOKEN`;
- réponse brute `hub.challenge`.

`POST` reçoit les événements :

- le corps brut est limité à 65 536 octets;
- `FACEBOOK_APP_SECRET` doit être configuré;
- `X-Hub-Signature-256` doit être présent;
- la signature attendue est `sha256=<hmac_sha256(raw_body, FACEBOOK_APP_SECRET)>`;
- signature invalide : `401`;
- événement non exploitable : `200` avec compteur `ignored`.

Mapping :

- `entry.id` devient `publisher=facebook_page:<page_id>`;
- `value.post_id` devient `source=facebook_post:<post_id>`;
- `value.from.id` devient `authorId=facebook_user:<id>`;
- `value.message` devient `body`.

Limite importante : le système décide dans sa base interne. Il ne masque, ne supprime et ne répond pas encore au commentaire sur Facebook. Pour appliquer la décision sur Facebook, il faudra ajouter une intégration Graph API après modération.

## 6. Sécurité

JWT :

- algorithme RS256;
- clés générées hors Git;
- expiration 900 secondes;
- transport uniquement via `Authorization: Bearer <token>`;
- pas de login public;
- commande de génération réservée aux environnements `dev` et `test`.

Routes :

- `/`, `/health`, `/doc` en dev : public;
- `POST /comments` : public avec limites d'admission;
- `GET /comments` et `GET /comments/{id}` : JWT requis;
- `POST /comments/{id}/moderation` : `ROLE_MODERATOR`;
- `GET/POST /webhooks/facebook/comments` : public HTTP mais protégé par token Meta/HMAC.

Production :

- HTTPS obligatoire;
- `APP_ENV=prod`;
- `APP_DEBUG=0`;
- `APP_SECRET` fort et géré par secret manager;
- clés JWT et secrets Facebook hors Git;
- reverse proxy configuré avec des trusted proxies explicites;
- limites reverse proxy/API gateway en plus des limites applicatives.

## 7. Docker Compose

Services :

- `init` : génère les clés JWT si absentes, lance migrations, valide le schéma, clear cache;
- `php` : PHP-FPM Symfony;
- `web` : Nginx;
- `worker` : `messenger:consume async`;
- `ollama` : serveur LLM local;
- `ollama-init` : téléchargement du modèle;
- `token` : profil tools pour émettre un JWT local.

Démarrage :

```bash
./launch.sh
```

Redémarrage sans build :

```bash
./launch.sh --no-build
```

Diagnostic :

```bash
docker compose --env-file .env.docker ps -a
docker compose --env-file .env.docker logs --tail=200 php web worker ollama
docker compose --env-file .env.docker exec -T php php bin/console doctrine:migrations:status --no-interaction
docker compose --env-file .env.docker exec -T php php bin/console doctrine:schema:validate
```

## 8. Kubernetes

Manifest :

```text
k8s/comment-moderation.yaml
```

Ressources :

- `Namespace/comment-moderation`;
- `ConfigMap/comment-moderation-config`;
- `Secret/comment-moderation-secrets`;
- `ConfigMap/comment-moderation-nginx`;
- `PersistentVolumeClaim/comment-moderation-data`;
- `PersistentVolumeClaim/comment-moderation-jwt`;
- `Job/comment-moderation-init`;
- `Deployment/comment-moderation-php`;
- `Deployment/comment-moderation-web`;
- `Deployment/comment-moderation-worker`;
- services `comment-moderation-php` et `comment-moderation-web`;
- `Ingress/comment-moderation`.

Images attendues :

```bash
docker build --target app -t comment-moderation-app:local .
docker build --target web -t comment-moderation-web:local .
```

Déploiement :

```bash
kubectl apply -f k8s/comment-moderation.yaml
kubectl -n comment-moderation wait --for=condition=complete job/comment-moderation-init --timeout=180s
kubectl -n comment-moderation rollout status deploy/comment-moderation-php --timeout=180s
kubectl -n comment-moderation rollout status deploy/comment-moderation-web --timeout=180s
kubectl -n comment-moderation rollout status deploy/comment-moderation-worker --timeout=180s
```

Test local :

```bash
kubectl -n comment-moderation port-forward svc/comment-moderation-web 8000:80
curl -fsS http://127.0.0.1:8000/health
```

Limites du manifest exemple :

- images locales `:local`;
- SQLite sur PVC;
- Secret exemple à remplacer;
- Ingress host `comment-moderation.local`;
- pas de déploiement Ollama K8S inclus.

Pour un environnement durable, remplacer SQLite par PostgreSQL et utiliser un registry d'images, un secret manager et TLS réel.

## 9. Procédure de test Docker

Démarrer :

```bash
./launch.sh
curl -fsS http://127.0.0.1:8000/health
```

JWT :

```bash
moderator_jwt=$(docker compose --env-file .env.docker --profile tools run --rm token --subject=alice)
```

Sécurité :

```bash
curl -sS -o /dev/null -w 'anonymous=%{http_code}\n' http://127.0.0.1:8000/comments
curl -sS -o /dev/null -w 'operator=%{http_code}\n' -H "Authorization: Bearer $moderator_jwt" http://127.0.0.1:8000/comments
```

Commentaire direct :

```bash
submission=$(curl -fsS -X POST http://127.0.0.1:8000/comments \
  -H 'Content-Type: application/json' \
  --data '{"publisher":"docker-test","source":"article-42","authorId":"user-7","body":"Merci pour cet article clair et utile."}')

comment_id=$(printf '%s' "$submission" | docker compose --env-file .env.docker exec -T php php -r '$p=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $p["id"];')

curl -fsS -H "Authorization: Bearer $moderator_jwt" "http://127.0.0.1:8000/comments/$comment_id"
```

Modération manuelle :

```bash
curl -fsS -X POST "http://127.0.0.1:8000/comments/$comment_id/moderation" \
  -H "Authorization: Bearer $moderator_jwt" \
  -H 'Content-Type: application/json' \
  --data '{"status":"rejected","reason":"manual_test"}'
```

Facebook signé :

```bash
facebook_payload='{"object":"page","entry":[{"id":"page-42","changes":[{"field":"feed","value":{"item":"comment","post_id":"post-99","comment_id":"comment-12","message":"Merci pour cet article Facebook.","from":{"id":"user-7"}}}]}]}'

facebook_signature=$(printf '%s' "$facebook_payload" | docker compose --env-file .env.docker exec -T php php -r '$payload=stream_get_contents(STDIN); echo "sha256=".hash_hmac("sha256", $payload, "secret-app-meta-hors-git");')

curl -fsS -X POST http://127.0.0.1:8000/webhooks/facebook/comments \
  -H 'Content-Type: application/json' \
  -H "X-Hub-Signature-256: $facebook_signature" \
  --data "$facebook_payload"
```

## 10. Commandes Symfony CLI

Les commandes CLI passent par les mêmes messages applicatifs que l'API et retournent du JSON.

Ajouter un commentaire :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:add \
  --publisher=cli-site \
  --source=article-42 \
  --author-id=cli-user \
  --body='Merci pour cet article.'
```

Lister avec filtres :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:list --status=pending
docker compose --env-file .env.docker exec -T php php bin/console app:comments:list --publisher=facebook_page:page-42 --status=rejected
```

Lire le statut courant :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:status <comment-id>
```

Modération manuelle :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:moderate <comment-id> \
  --status=published \
  --reason=cli_manual_review
```

Statut du fournisseur LLM :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:llm:status
```

Modérer un texte brut sans persistance :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:llm:moderate \
  --body='Texte à classifier.'
```

Relancer la modération LLM d'un commentaire existant, de façon synchronisée :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:moderate-llm <comment-id>
```

Implémentation :

- `src/UI/Cli/*Command.php` formate les entrées/sorties console;
- les commandes dispatchent des Commands/Queries applicatives via Messenger;
- `ModerateCommentProcessor` porte la logique commune entre worker async et commande CLI synchronisée;
- `ModerationProviderStatusChecker` expose un port domaine pour le check de fournisseur LLM.

## 11. Collection Postman

La collection importable est :

```text
postman/comment-moderation.postman_collection.json
```

Elle utilise les variables de collection suivantes :

- `base_url`
- `moderator_jwt`
- `comment_id`
- `facebook_verify_token`
- `facebook_app_secret`
- `facebook_payload`
- `facebook_signature`

Le script de pre-request de la requête `08 - Facebook signed comment` calcule automatiquement `facebook_signature` avec `CryptoJS.HmacSHA256(payload, facebook_app_secret)`.

Avant d'exécuter la collection :

```bash
./launch.sh
moderator_jwt=$(docker compose --env-file .env.docker --profile tools run --rm token --subject=alice)
```

Copier le JWT dans la variable Postman `moderator_jwt`. Vérifier aussi que les valeurs `facebook_verify_token` et `facebook_app_secret` correspondent à `.env.docker`.

## 12. Tests automatisés

Suite complète :

```bash
vendor/bin/phpunit --display-deprecations
```

Tests ciblés utiles :

```bash
vendor/bin/phpunit tests/Unit --testdox
vendor/bin/phpunit tests/Functional/UI/Webhook/FacebookWebhookControllerTest.php --testdox
vendor/bin/phpunit tests/Functional/UI/Cli/CommentModerationCliCommandTest.php --testdox
vendor/bin/phpunit tests/Container/KubernetesManifestTest.php --testdox
vendor/bin/phpunit tests/Container/ComposeContractTest.php --testdox
```

Qualité :

```bash
composer validate --strict
composer audit --no-interaction
vendor/bin/phpstan analyse --no-progress
vendor/bin/phparkitect check
php bin/console lint:container
php bin/console lint:yaml config k8s
bash -n launch.sh
docker compose --profile tools --env-file .env.docker.example config --quiet
docker build --check .
```

## 13. Base de données

Docker utilise SQLite :

```dotenv
DATABASE_URL=sqlite:////app/var/data.db
```

Les migrations créent :

- `comments`;
- `banned_users`;
- tables Messenger Doctrine;
- champ `version` pour verrouillage optimiste.

En production durable, PostgreSQL est recommandé. SQLite sur PVC reste acceptable pour un test technique ou une démonstration simple, mais pas pour un trafic concurrent significatif.

## 14. OpenAPI

La documentation `/doc` est disponible en environnement `dev`. Elle n'est pas exposée par la stack Docker `prod`, volontairement.

Pour vérifier le contrat sans exposer Swagger en prod, utiliser les tests fonctionnels et les attributs OpenAPI des contrôleurs.

## 15. Règles de contribution

Avant de commit :

- écrire un test RED avant une modification comportementale;
- faire passer le test ciblé;
- lancer la suite complète adaptée;
- ne pas committer `.env.docker`, clés JWT, secrets ou tokens;
- ne pas inclure `config/reference.php` si c'est seulement une régénération Symfony non liée au changement;
- garder les commentaires de code courts, en anglais, uniquement quand ils expliquent une contrainte non évidente.

## 16. Évolutions recommandées

Pour rendre la modération Facebook entièrement autonome côté plateforme :

- persister `facebook_comment_id`;
- ajouter un port applicatif `FacebookCommentActionService`;
- appeler Graph API après décision `rejected`;
- choisir explicitement entre masquer, supprimer ou répondre;
- gérer les retries et erreurs Graph API;
- ajouter des tests de contrat avec client HTTP mocké;
- documenter les permissions Meta nécessaires.

Pour une production plus robuste :

- PostgreSQL;
- observabilité structurée;
- métriques queue/LLM;
- rotation des secrets;
- registry d'images;
- chart Helm si plusieurs environnements doivent être maintenus.
