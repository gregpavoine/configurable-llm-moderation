# Documentation utilisateur et opérateur

Ce document explique comment configurer, démarrer, tester et exploiter le service de modération des commentaires. Il couvre les commentaires reçus par l'API classique, les commentaires Facebook reçus par webhook, la modération LLM, la revue manuelle, Docker et Kubernetes.

## 1. Vue d'ensemble

Le service reçoit des commentaires, les enregistre immédiatement en `pending`, puis les modère de façon asynchrone avec un worker.

Statuts possibles :

- `pending` : le commentaire attend une décision automatique ou manuelle.
- `published` : le commentaire est accepté dans le système.
- `rejected` : le commentaire est refusé dans le système.

Sources possibles :

- `POST /comments` : soumission directe par un client applicatif.
- `POST /webhooks/facebook/comments` : commentaire Facebook reçu depuis Meta.

Accès :

- `POST /comments` est public.
- `GET /comments`, `GET /comments/{id}`, `POST /comments/{id}/moderation` et `POST /comments/moderation/batch` exigent un JWT opérateur.
- `GET/POST /webhooks/facebook/comments` est public au sens HTTP, mais sécurisé par token de vérification Meta et signature HMAC.

## 1.1 Queue, batch et anti-flood

Chaque nouveau commentaire accepté est persisté en `pending`, puis un message `ModerateCommentCommand` est placé dans la queue Messenger `new_comments`. Cette queue est globale : elle ne dépend pas de l'article. Les commentaires de plusieurs articles, pages Facebook ou sources applicatives sont donc traités par ordre d'arrivée.

Mode nominal :

- un commentaire arrive via `POST /comments` ou via le webhook Facebook;
- il est stocké immédiatement en `pending`;
- il est ajouté à la queue `new_comments`;
- le worker le modère dès que le LLM est disponible;
- la décision devient `published`, `rejected` ou reste `pending` si une revue manuelle est nécessaire.

Le batch sert à traiter un groupe de commentaires `pending` déjà en base, par exemple après une coupure du LLM, une maintenance ou un pic de trafic. Il prend les plus anciens commentaires `pending` tous articles confondus, avec une limite bornée entre `1` et `100`.

Le batch n'est pas lié à un article. Si trois articles reçoivent des commentaires en même temps, le batch sélectionne les plus anciens `pending` dans toute la base.

L'anti-flood repose sur trois barrières :

- limite de taille HTTP avant parsing JSON;
- rate limits applicatifs sur la soumission de commentaires;
- throttling Messenger `moderation_provider` côté worker pour ne pas saturer le LLM.

Si le volume de commentaires dépasse la capacité du LLM, le service ne bloque pas l'ingestion : les commentaires restent en `pending` dans la queue/base, puis sont traités progressivement. L'application appelante doit donc prévoir un état produit compatible avec `pending`, par exemple “en attente de validation” ou “visible seulement après validation”.

## 2. Démarrage Docker

Depuis la racine du projet :

```bash
./launch.sh
```

Le script :

- crée `.env.docker` si nécessaire;
- génère un `APP_SECRET` local si la valeur est encore le placeholder;
- construit ou réutilise les images Docker;
- démarre `init`, `php`, `web`, `worker`, `ollama` et `ollama-init`;
- attend que l'API, PHP-FPM, Ollama et le worker soient prêts.

Pour redémarrer sans reconstruire :

```bash
./launch.sh --no-build
```

Vérifier la stack :

```bash
docker compose --env-file .env.docker ps -a
curl -fsS http://127.0.0.1:8000/health
```

Résultat attendu :

```json
{"status":"ok"}
```

## 3. Configuration LLM

Le worker utilise un fournisseur OpenAI-compatible si les variables sont renseignées.

Ollama local dans Docker :

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

Fournisseur externe :

```dotenv
MODERATION_LLM_BASE_URL=https://api.example.test/v1
MODERATION_LLM_MODEL=moderator-model
MODERATION_LLM_API_KEY=votre-cle-secrete
MODERATION_LLM_TIMEOUT=10
```

Revue manuelle forcée :

```dotenv
MODERATION_LLM_BASE_URL=
MODERATION_LLM_MODEL=
MODERATION_LLM_API_KEY=
```

Après toute modification de `.env.docker` :

```bash
./launch.sh --no-build
```

Comportement attendu :

- LLM configuré et réponse valide : le commentaire devient `published` ou `rejected`.
- LLM absent, timeout, erreur HTTP ou réponse invalide : le commentaire reste `pending` avec `moderationReason=manual_review_required`.

### Règles de modération envoyées au LLM

Les règles métier sont chargées par Symfony depuis :

```text
config/packages/moderation.yaml
```

Exemple :

```yaml
parameters:
    app.moderation.llm_rules:
        - 'Reject abusive political insults, dehumanizing language, and comparisons to Nazi ideology when used as an insult against a person or public figure.'
        - 'Reject direct harassment, personal attacks, threats, hate speech, discrimination, defamation, terrorism praise, and child sexual content.'
        - 'Publish respectful disagreement, criticism, satire, and non-abusive political opinions.'
```

Pour modifier la politique de modération, éditez ce fichier puis relancez :

```bash
./launch.sh --no-build
```

Les règles sont ajoutées au prompt système envoyé au LLM. Elles ne remplacent pas les garde-fous de base : le service continue de refuser menaces, haine, harcèlement, diffamation, apologie terroriste et contenus sexuels impliquant des mineurs.

## 4. Configuration Facebook

Dans `.env.docker`, configurer :

```dotenv
FACEBOOK_WEBHOOK_VERIFY_TOKEN=token-partage-avec-meta
FACEBOOK_APP_SECRET=secret-app-meta-hors-git
```

Puis relancer :

```bash
./launch.sh --no-build
```

Dans Meta Developers, configurer le webhook de page avec :

```text
Callback URL: https://votre-domaine.example/webhooks/facebook/comments
Verify token: même valeur que FACEBOOK_WEBHOOK_VERIFY_TOKEN
```

Le `FACEBOOK_APP_SECRET` doit être celui fourni par l'application Meta. Il sert à vérifier `X-Hub-Signature-256` sur chaque callback `POST`.

## 5. Workflow Facebook en production

Le système n'utilise pas de bouchon pour Facebook. Le workflow implémenté est le suivant :

```text
Utilisateur Facebook
  -> commentaire sur un post/page
  -> webhook Meta signe
  -> API /webhooks/facebook/comments
  -> verification X-Hub-Signature-256
  -> creation d'un commentaire interne
  -> queue Messenger
  -> worker de moderation
  -> LLM local/externe si configure
  -> published, rejected ou pending/manual_review_required
```

Mapping interne :

- `publisher=facebook_page:<page_id>`
- `source=facebook_post:<post_id>`
- `authorId=facebook_user:<user_id>` si Meta fournit l'auteur
- `body=<message du commentaire>`

Ce qui est autonome aujourd'hui :

- réception des commentaires Facebook par webhook;
- vérification que le callback vient bien de Meta;
- création du commentaire interne;
- décision automatique via LLM si configuré;
- fallback en revue manuelle si le LLM n'est pas fiable.

Ce qui n'est pas encore appliqué côté Facebook :

- masquer automatiquement un commentaire rejeté sur Facebook;
- supprimer automatiquement un commentaire Facebook;
- répondre au commentaire Facebook;
- synchroniser les modifications ou suppressions faites directement sur Facebook.

Conséquence opérationnelle : `rejected` signifie que le commentaire est rejeté dans notre système. Pour rendre l'application 100 % autonome côté Facebook, il faudra ajouter une étape Graph API après décision, par exemple masquer ou supprimer le commentaire Facebook quand notre statut devient `rejected`.

## 6. Générer un JWT opérateur

```bash
moderator_jwt=$(docker compose --env-file .env.docker --profile tools run --rm token --subject=alice)
```

Ne pas afficher, journaliser ni versionner ce jeton. Il expire après 900 secondes et porte `ROLE_MODERATOR`.

Tester les droits :

```bash
curl -sS -o /dev/null -w 'anonymous=%{http_code}\n' \
  http://127.0.0.1:8000/comments

curl -sS -o /dev/null -w 'operator=%{http_code}\n' \
  -H "Authorization: Bearer $moderator_jwt" \
  http://127.0.0.1:8000/comments
```

Résultat attendu :

```text
anonymous=401
operator=200
```

## 7. Tester un commentaire classique

Soumettre un commentaire :

```bash
submission=$(curl -fsS -X POST http://127.0.0.1:8000/comments \
  -H 'Content-Type: application/json' \
  --data '{"publisher":"site-a","source":"article-42","authorId":"user-7","body":"Merci pour cet article clair."}')

echo "$submission"
```

Récupérer l'identifiant avec PHP dans le conteneur :

```bash
comment_id=$(printf '%s' "$submission" | docker compose --env-file .env.docker exec -T php php -r '$p=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $p["id"];')
```

Lire le commentaire :

```bash
curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  "http://127.0.0.1:8000/comments/$comment_id"
```

Le statut initial est `pending`. Après traitement par le worker, il devient `published` ou `rejected`, sauf fallback manuel.

## 8. Tester la modération manuelle

Une décision manuelle accepte uniquement `published` ou `rejected`.

```bash
curl -fsS -X POST "http://127.0.0.1:8000/comments/$comment_id/moderation" \
  -H "Authorization: Bearer $moderator_jwt" \
  -H 'Content-Type: application/json' \
  --data '{"status":"rejected","reason":"manual_test"}'
```

Résultat attendu : le commentaire a `status=rejected`, `moderationReason=manual_test` et `moderatedAt` renseigné.

Un commentaire déjà final ne peut plus changer. Une seconde décision contradictoire répond `409`.

## 9. Commandes Symfony CLI utiles

Toutes les commandes suivantes s'exécutent via Docker :

```bash
docker compose --env-file .env.docker exec -T php php bin/console <commande>
```

Ajouter un commentaire :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:add \
  --publisher=cli-site \
  --source=article-42 \
  --author-id=cli-user \
  --body='Merci pour cet article.'
```

Lister les commentaires, avec filtre de statut :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:list --status=pending
docker compose --env-file .env.docker exec -T php php bin/console app:comments:list --status=rejected --limit=10 --offset=0
```

Vérifier le statut d'un commentaire :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:status <comment-id>
```

Modérer manuellement :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:moderate <comment-id> \
  --status=rejected \
  --reason=manual_cli_review
```

Checker l'état du LLM configuré :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:llm:status
```

Tester le LLM sur un texte brut sans créer de commentaire :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:llm:moderate \
  --body='Merci pour cet article clair.'
```

Lancer une modération LLM synchronisée sur un commentaire existant :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:moderate-llm <comment-id>
```

Modérer un batch de commentaires `pending`, tous articles confondus :

```bash
docker compose --env-file .env.docker exec -T php php bin/console app:comments:moderate-batch --limit=20
```

Toutes ces commandes retournent du JSON pour être facilement exploitables dans un script.

## 9.1 Intégrer ce micro-service dans un système existant

Le système existant doit traiter ce service comme un composant de décision externe.

Workflow recommandé :

1. Quand un utilisateur publie un commentaire dans votre application, appelez `POST /comments` avec `publisher`, `source`, `authorId` si disponible, et `body`.
2. Stockez l'identifiant retourné par le micro-service dans votre base métier.
3. Affichez le commentaire côté produit seulement si votre politique accepte l'état `pending`, sinon attendez `published`.
4. Récupérez la décision par polling `GET /comments/{id}` ou par liste filtrée `GET /comments?status=rejected`.
5. Si le statut devient `published`, publiez/maintenez le commentaire côté produit.
6. Si le statut devient `rejected`, masquez, bloquez ou supprimez le commentaire côté produit selon votre règle métier.
7. Si le statut reste `pending` avec `manual_review_required`, envoyez-le à une console opérateur et décidez via `POST /comments/{id}/moderation`.

Contrat HTTP minimal :

```http
POST /comments
Content-Type: application/json

{
  "publisher": "site-a",
  "source": "article-42",
  "authorId": "user-7",
  "body": "Texte du commentaire"
}
```

Réponse :

```json
{"id":"019ff02e-0113-763c-bb89-d393359af9fe","status":"pending"}
```

Le champ `source` doit contenir l'identifiant de l'article, du post ou de la ressource métier. Le champ `publisher` doit identifier l'application, le site ou la page source. Cette séparation permet de modérer plusieurs articles dans une seule queue globale.

Pour Facebook, le webhook crée la décision interne. L'application métier doit ensuite appliquer la décision côté Meta si nécessaire via Graph API. Cette partie dépend des permissions Meta du compte/page et n'est pas automatisée par ce micro-service.

En production, placez le service derrière votre API gateway ou reverse proxy :

- HTTPS obligatoire;
- JWT modérateur réservé aux back-offices et jobs internes;
- secrets dans un secret manager;
- métriques sur `pending`, `rejected`, `published`, profondeur de queue et temps de réponse LLM;
- scaling horizontal possible du worker si le LLM/provider supporte la charge.

## 10. Tester le webhook Facebook en local

Tester le challenge Meta :

```bash
curl -fsS \
  'http://127.0.0.1:8000/webhooks/facebook/comments?hub.mode=subscribe&hub.verify_token=token-partage-avec-meta&hub.challenge=challenge-123'
```

Résultat attendu :

```text
challenge-123
```

Envoyer un faux commentaire Facebook signé vers le vrai endpoint :

```bash
facebook_payload='{"object":"page","entry":[{"id":"page-42","changes":[{"field":"feed","value":{"item":"comment","post_id":"post-99","comment_id":"comment-12","message":"Merci pour cet article Facebook.","from":{"id":"user-7"}}}]}]}'

facebook_signature=$(printf '%s' "$facebook_payload" | docker compose --env-file .env.docker exec -T php php -r '$payload=stream_get_contents(STDIN); echo "sha256=".hash_hmac("sha256", $payload, "secret-app-meta-hors-git");')

curl -fsS -X POST http://127.0.0.1:8000/webhooks/facebook/comments \
  -H 'Content-Type: application/json' \
  -H "X-Hub-Signature-256: $facebook_signature" \
  --data "$facebook_payload"
```

Résultat attendu :

```json
{"received":1,"ignored":0}
```

Lire ensuite les commentaires filtrés par page Facebook :

```bash
curl -fsS \
  -H "Authorization: Bearer $moderator_jwt" \
  'http://127.0.0.1:8000/comments?publisher=facebook_page:page-42'
```

Tests négatifs attendus :

- signature absente ou invalide : `401`;
- verify token invalide : `403`;
- payload supérieur à 65 536 octets : `413`;
- événement Facebook sans message exploitable : `200` avec `ignored` incrémenté.

## 11. Tester avec Postman

Importer la collection :

```text
postman/comment-moderation.postman_collection.json
```

Variables à vérifier dans Postman :

- `base_url=http://127.0.0.1:8000`
- `moderator_jwt=<jeton genere avec Docker>`
- `facebook_verify_token=token-partage-avec-meta`
- `facebook_app_secret=secret-app-meta-hors-git`

Générer le JWT avant de lancer les requêtes protégées :

```bash
moderator_jwt=$(docker compose --env-file .env.docker --profile tools run --rm token --subject=alice)
```

Copier la valeur dans la variable Postman `moderator_jwt`.

La collection couvre :

- santé API;
- refus anonyme sur les lectures;
- lecture authentifiée;
- soumission directe;
- lecture du commentaire créé;
- décision manuelle;
- challenge Facebook;
- commentaire Facebook signé automatiquement dans Postman;
- recherche des commentaires Facebook;
- rejet d'une signature Facebook invalide.

## 12. Tester le fallback manuel

Arrêter le worker :

```bash
docker compose --env-file .env.docker stop worker
```

Soumettre un commentaire :

```bash
manual_submission=$(curl -fsS -X POST http://127.0.0.1:8000/comments \
  -H 'Content-Type: application/json' \
  --data '{"publisher":"site-a","source":"manual-check","authorId":null,"body":"Commentaire destiné au test manuel."}')

manual_id=$(printf '%s' "$manual_submission" | docker compose --env-file .env.docker exec -T php php -r '$p=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $p["id"];')
```

Décider manuellement :

```bash
curl -fsS -X POST "http://127.0.0.1:8000/comments/$manual_id/moderation" \
  -H "Authorization: Bearer $moderator_jwt" \
  -H 'Content-Type: application/json' \
  --data '{"status":"rejected","reason":"manual_review"}'
```

Redémarrer le worker :

```bash
docker compose --env-file .env.docker start worker
```

## 13. Kubernetes

Le manifest Kubernetes exemple est `k8s/comment-moderation.yaml`.

Il contient :

- namespace `comment-moderation`;
- ConfigMap applicative;
- Secret exemple;
- PVC pour `/app/var`;
- PVC pour `/app/config/jwt`;
- job d'initialisation;
- deployments `php`, `web` et `worker`;
- services internes;
- Ingress exemple.

Construire les images :

```bash
docker build --target app -t comment-moderation-app:local .
docker build --target web -t comment-moderation-web:local .
```

Déployer :

```bash
kubectl apply -f k8s/comment-moderation.yaml
kubectl -n comment-moderation wait --for=condition=complete job/comment-moderation-init --timeout=180s
kubectl -n comment-moderation rollout status deploy/comment-moderation-php --timeout=180s
kubectl -n comment-moderation rollout status deploy/comment-moderation-web --timeout=180s
kubectl -n comment-moderation rollout status deploy/comment-moderation-worker --timeout=180s
```

Tester localement via port-forward :

```bash
kubectl -n comment-moderation port-forward svc/comment-moderation-web 8000:80
curl -fsS http://127.0.0.1:8000/health
```

Avant un vrai déploiement partagé :

- remplacer toutes les valeurs `replace-*` du Secret;
- configurer l'host Ingress;
- utiliser HTTPS;
- connecter un vrai fournisseur LLM ou accepter la revue manuelle;
- envisager PostgreSQL plutôt que SQLite sur PVC pour un environnement durable.

Nettoyer :

```bash
kubectl delete -f k8s/comment-moderation.yaml
```

## 14. Logs, diagnostic et arrêt

Logs Docker :

```bash
docker compose --env-file .env.docker logs --tail=200 php web worker ollama
```

État Docker :

```bash
docker compose --env-file .env.docker ps -a
```

Arrêt non destructif :

```bash
docker compose --env-file .env.docker down
```

Suppression complète des volumes locaux :

```bash
docker compose --env-file .env.docker down -v
```

Attention : `down -v` supprime la base locale, les clés JWT et le modèle Ollama téléchargé.

## 15. Codes d'erreur utiles

- `200` : webhook Facebook reçu ou lecture réussie.
- `202` : commentaire accepté pour modération.
- `400` : JSON ou payload invalide.
- `401` : JWT absent/invalide ou signature Facebook invalide.
- `403` : rôle insuffisant ou verify token Facebook invalide.
- `404` : commentaire inexistant.
- `409` : tentative de modifier un commentaire déjà final.
- `413` : corps HTTP trop volumineux.
- `422` : payload applicatif invalide.
- `429` : limite de débit dépassée.

## 16. Limites fonctionnelles actuelles

Le service modère les commentaires dans son propre système. Il ne modifie pas encore la réalité côté Facebook.

Pour appliquer automatiquement une décision `rejected` sur Facebook, une évolution doit ajouter :

- stockage de l'identifiant `comment_id` Facebook;
- permission Meta adaptée;
- appel Graph API après décision;
- politique claire entre `hide`, `delete` ou réponse automatique;
- retries et journalisation opérationnelle sans exposer de secrets.
