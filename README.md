# API Skeleton

Base de projet Symfony (API REST) suivant une architecture DDD/CQRS, prête à accueillir un cas d'usage.

## Architecture

Le projet suit une architecture DDD/CQRS :

- `src/Domain/` — Logique métier (entités, value objects, interfaces repository, exceptions)
- `src/Application/` — Handlers de commandes (`Command/`) et de requêtes (`Query/`), vues de lecture (DTO)
- `src/Infrastructure/` — Persistence (Doctrine), framework (Symfony), implémentations des ports
- `src/UI/` — Controllers, DTOs d'entrée (`*Params` / `*Request`), organisés par action

Les conventions de code détaillées (nommage, validation, gestion d'erreurs, Messenger) sont décrites dans [`AGENTS.md`](AGENTS.md).

## Endpoints fournis

| Méthode | Route      | Réponse                                            |
|---------|------------|----------------------------------------------------|
| `GET`   | `/`        | `{ "message": "API Skeleton.", "version": "…" }`   |
| `GET`   | `/health`  | `{ "status": "ok" }`                               |

## Documentation API

La documentation OpenAPI est générée automatiquement par [NelmioApiDocBundle](https://github.com/nelmio/NelmioApiDocBundle).

Swagger UI est accessible à l'adresse `/doc` (uniquement en environnement `dev`). La configuration se trouve dans `config/packages/dev/nelmio_api_doc.yaml`.

## Configuration

Renseigner les variables d'environnement dans `.env.local` (non commité) :

- `APP_SECRET` — secret de l'application.
- `DATABASE_URL` — DSN de la base de données Doctrine.

## Commandes

Les commandes Symfony/Doctrine s'exécutent via `php bin/console <commande>` (ou dans le conteneur applicatif si vous utilisez Docker).

### Base de données

```bash
# Générer une migration à partir des différences entité / BDD
php bin/console doctrine:migrations:diff

# Exécuter les migrations
php bin/console doctrine:migrations:migrate

# Charger les fixtures (dev/test) — répertoire src/Infrastructure/Persistence/Fixture
php bin/console doctrine:fixtures:load --append
```

## Qualité

```bash
# Analyse statique (PHPStan niveau 9 sur src/)
vendor/bin/phpstan analyse

# Tests
vendor/bin/phpunit
```
