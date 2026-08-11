# Service de modération des commentaires
API Symfony 8 / PHP 8.5 : `POST /comments` répond `202`, puis le worker modère asynchronement ; lectures et modération manuelle sont protégées.
Les commentaires Facebook entrent par `GET/POST /webhooks/facebook/comments` : challenge Meta via `FACEBOOK_WEBHOOK_VERIFY_TOKEN`, callbacks signés `X-Hub-Signature-256` via `FACEBOOK_APP_SECRET`, puis mapping vers le même pipeline de modération.
Sans LLM (`MODERATION_LLM_BASE_URL`, `MODERATION_LLM_MODEL` et `MODERATION_LLM_API_KEY` vides), le commentaire reste `pending`, avec `manual_review_required` et `moderatedAt: null`.
Ollama local : `MODERATION_LLM_BASE_URL=http://127.0.0.1:11434/v1`, `MODERATION_LLM_MODEL=llama3.2` et une clé vide sont valides.
Fournisseur externe OpenAI-compatible : `MODERATION_LLM_BASE_URL=https://api.openai.com/v1`, `MODERATION_LLM_MODEL=gpt-4o-mini`, `MODERATION_LLM_API_KEY=<secret>`.
Réglez aussi `MODERATION_LLM_TIMEOUT=10`, installez avec `composer install`, puis appliquez `php bin/console doctrine:migrations:migrate --no-interaction`.
Générez les clés JWT hors Git : `php bin/console lexik:jwt:generate-keypair --skip-if-exists`; émettez un jeton : `php bin/console app:jwt:issue-moderator --subject=alice`.
Envoyez-le seulement dans `Authorization: Bearer <token>` ; les clés, passphrases et jetons ne doivent jamais être versionnés.
Lancez le worker : `php bin/console messenger:consume async -vv`; l'endpoint opérateur est `POST /comments/{id}/moderation` avec `{"status":"published","reason":"optional"}` ; utilisez `"rejected"` pour refuser.
Commandes CLI JSON : `app:comments:add`, `app:comments:list`, `app:comments:status`, `app:comments:moderate`, `app:comments:moderate-llm`, `app:llm:status` et `app:llm:moderate`.
Les fournisseurs n'acceptent que HTTPS (ou HTTP loopback local), le client de modération contourne explicitement les proxies d'environnement, la réponse LLM est strictement validée, et tout échec bascule vers la revue manuelle.
Les JWT RS256 expirent en 15 minutes ; déployez uniquement sous HTTPS et limitez l'accès aux opérateurs ayant `ROLE_MODERATOR`.
En production, exportez explicitement `APP_ENV=prod`, `APP_DEBUG=0` et un `APP_SECRET` aléatoire fort ; le noyau refuse de démarrer en mode `prod` avec le debug actif. Bloquez aussi tout proxy HTTP sortant au niveau du déploiement. Si l'API est derrière un reverse proxy, ne déclarez comme proxies de confiance que ses adresses exactes : les limites par client utilisent `Request::getClientIp()`.
`POST /comments` limite le corps brut à 65 536 octets, accepte au plus 20 requêtes par minute et par client et 200 globalement ; le worker limite également le débit vers le fournisseur. Ces valeurs sont des garde-fous locaux et doivent être complétées par les limites du reverse proxy/API gateway.
Le champ public nullable `authorId` est un identifiant externe fourni par l'appelant, donc seulement exploitable comme signal de bannissement de bonne foi. Un bannissement opposable exige qu'un proxy amont authentifié signe ou injecte une identité vérifiée ; cette voie de confiance reste une évolution compatible proposée, pas une propriété de l'endpoint anonyme actuel.
Stack Docker complète : lancez `./launch.sh` (`--no-build` pour réutiliser les images) ; API `http://127.0.0.1:8000`, Ollama `http://127.0.0.1:11435`.
Déploiement Kubernetes exemple : `k8s/comment-moderation.yaml` avec `php`, `web`, `worker`, job d'init, ConfigMap, Secret et PVC.
JWT local de test : `docker compose --env-file .env.docker --profile tools run --rm token --subject=alice` ; ce profil isolé contient les dépendances de développement, absentes de l'image applicative de production.
État et logs : `docker compose --env-file .env.docker ps -a` et `docker compose --env-file .env.docker logs -f`; arrêt : `docker compose --env-file .env.docker down` (ajoutez `-v` uniquement pour effacer base, clés et modèle).
Documentation OpenAPI : `/doc` en environnement `dev` uniquement ; vérification locale : `vendor/bin/phpunit && vendor/bin/phpstan analyse && vendor/bin/phparkitect check`.
Documentation complète : `docs/USER_GUIDE.md` pour l'usage, l'exploitation et les tests; `docs/DEVELOPMENT.md` pour l'architecture, le développement, Docker, Kubernetes et les vérifications.
Usage de l'IA : Codex a aidé à analyser, concevoir et générer code/tests ; les choix, corrections et vérifications finales ont été revus humainement.
