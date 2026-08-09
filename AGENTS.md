---
description: Standards DDD/CQRS pour les APIs Symfony
alwaysApply: true
---

# API Symfony — guide agent

Modèle de référence pour les micro-services Symfony basés sur ce skeleton.

**À adapter par projet** : namespace (`Gsoi\Skeleton\` → le nom réel de votre API), routes, entités, variables d'environnement et flux métier spécifiques → `README.md` (vue d'ensemble) et `docs/` (détail), pas ici.

---

## Principes de codage

- `declare(strict_types=1);` dans tout fichier PHP.
- **PHPStan niveau 9** sans erreur sur `src/`.
- **Validation des entrées** via `#[Assert\…]` sur les Command/Query (`Application/`) et validation explicite dans le handler avec `ValidatorInterface` — jamais dans le domaine.
- **Exceptions métier** dans `Domain/<Contexte>/Exception/`, héritant de `DomainException` (base dans `src/Domain/Exception/` ou `src/Domain/DomainException.php`).
- **Types stricts** : pas de `mixed`, pas de suppressions `@var` pour contourner l'analyse.
- **Composition > héritage** : injection de dépendances, pas de hiérarchie de classes abstraite sans justification.
- **DRY** : réutiliser les helpers existants et les patterns du projet avant d'en créer de nouveaux.
- **Pas de commentaires narratifs** : documenter uniquement le non-évident.
---

## Stack et outillage

| Élément | Norme cible |
|---------|-------------|
| PHP | ≥ 8.5 |
| Symfony | 8.0.* |
| Doc API | NelmioApiDocBundle — Swagger UI en dev sur `/doc` |

---

## Architecture DDD + CQRS

### Organisation `src/`

```text
src/
├── Domain/           # Entités, VOs, ports (interfaces), services métier, exceptions
├── Application/
│   ├── Command/      # Commandes + Handlers (écriture, async si pertinent)
│   └── Query/        # Requêtes + Handlers (lecture)
├── Infrastructure/   # Doctrine, clients HTTP, listeners Symfony, implémentations des ports
└── UI/
    ├── Api/          # Controllers REST
    ├── Cli/          # (optionnel) Command Symfony
    └── Webhook/      # (optionnel) Webhook externes
```

Un dossier par cas d'usage : `CreateResource/`, `GetResource/`, etc. Message et handler colocalisés.

### Règles de dépendance

```text
Domain          →  (rien, sauf allowlist : Assert, Uid, Doctrine\ORM\Mapping…)
Application     →  Domain
UI              →  Application
Infrastructure  →  Domain, Application, UI
```

Règles additionnelles à reproduire dans `phparkitect.php` :

- Tous les `*Handler` sous `Application/` portent `#[AsMessageHandler]`.
- Toutes les exceptions `Domain/**/*Exception` étendent `DomainException`.
- `EntityManagerInterface` interdit dans `Domain/` et `Application/` (persistance via les ports `*Repository` uniquement).

### Doctrine et persistence

| Autorisé | Interdit |
|----------|----------|
| Attributs de mapping Doctrine sur les entités du `Domain/` (`#[ORM\Entity]`, `#[ORM\Column]`, etc.) | `EntityManagerInterface` dans `Domain/` et `Application/` |
| Interfaces repository dans `Domain/` | `persist()`, `flush()`, requêtes DQL/QueryBuilder dans les handlers |
| Implémentations Doctrine dans `Infrastructure/Persistence/` | Accès direct à l'ORM depuis `UI/` |

Les handlers Application orchestrent le domaine via les ports ; seule la couche Infrastructure manipule l'`EntityManager`.

### Rôle des couches

| Couche | Contenu | Interdit |
|--------|---------|----------|
| **Domain** | Règles métier, agrégats, ports, VOs, mapping Doctrine sur les entités | `EntityManager`, clients HTTP, SDK externes, accès Infrastructure |
| **Application** | Orchestration CQRS (Command/Query + Handler) | `EntityManager`, logique métier riche qui appartient au domaine |
| **Infrastructure** | Implémentations concrètes, persistence, intégrations | — |
| **UI** | Traduction HTTP ↔ messages applicatifs, DTOs, OpenAPI | Accès direct aux repositories ou à l'`EntityManager` |

---

## Conventions de code

### Messages (Command / Query)

```php
final readonly class GetResourceQuery
{
    public function __construct(
        public string $id,
    ) {}
}
```

### Handlers

```php
#[AsMessageHandler]
final readonly class GetResourceHandler
{
    public function __invoke(GetResourceQuery $query): ?ResourceView { /* … */ }
}
```

### DTO UI et messages

- **Command / Query** : `#[Assert\…]` sur les propriétés du message ; le handler appelle `$this->validator->validate($message)` et lève `ValidationFailedException` si besoin.
- **DTO UI** (query params, body) : suffixe `*Dto` ou `*Params` pour OpenAPI et binding HTTP ; conversion explicite vers Command/Query dans le controller.
- Attributs `#[OA\…]` sur les DTO et controllers pour Nelmio.
- Binding HTTP : `#[MapRequestPayload]` / `#[MapQueryString]`.
- **ViewModels** de lecture dans `Application/Query/…/` (`*View`, `*Response`) — pas d'entités Doctrine exposées directement.

### Contrôleurs

- `#[AsController]` ou classe `final` invokable.
- Action unique `__invoke()`.
- Dispatch via `HandleTrait` + `MessageBusInterface` :

```php
final class GetResourceController
{
    use HandleTrait;

    public function __invoke(/* … */): JsonResponse
    {
        $result = $this->handle(new GetResourceQuery(/* … */));
        // …
    }
}
```

### Ports et persistence

- Interface dans `Domain/` : `ResourceRepository`.
- Implémentation Doctrine dans `Infrastructure/Persistence/`.
- Types custom Doctrine pour les VOs (`ResourceIdType`, etc.).

### Handlers — validation

```php
#[AsMessageHandler]
final readonly class GetResourceHandler
{
    public function __construct(
        private ResourceRepository $repository,
        private ValidatorInterface $validator,
    ) {}

    public function __invoke(GetResourceQuery $query): ?ResourceView
    {
        $violations = $this->validator->validate($query);
        if ($violations->count() > 0) {
            throw new ValidationFailedException($query, $violations);
        }
        // …
    }
}
```

Les exceptions domaine (`ResourceNotFoundException`, etc.) se lèvent dans le handler ; le controller ou un listener d'exceptions les traduit en HTTP.

### Nommage

- Namespace racine : `Gsoi\Skeleton\` (à renommer selon l'API).
- Exceptions : `Domain/<Contexte>/Exception/<Nom>Exception.php`.
- Routes : kebab-case, health check sur `/` et `/health`.

---

## Validation et erreurs HTTP

**Norme** : contraintes `#[Assert\…]` sur les Command/Query + `ValidatorInterface` dans chaque handler.

- Violations → `ValidationFailedException` → réponse **422** JSON (via un listener d'exceptions).
- Erreurs domaine → exceptions `Domain/**/*Exception` ou `HttpExceptionInterface`.
- Forme d'erreur uniforme via un listener d'exceptions JSON dédié.
- Production : message générique pour les 500 ; détails en mode debug uniquement.

Les DTO UI servent au binding HTTP et à OpenAPI ; la validation métier se fait sur le message applicatif dans le handler.

---

## Messenger et asynchrone

- **Commandes** : transport `async`, routage par `#[AsMessage('async')]` sur la classe Command.
- **Orchestration synchrone** : transport `sync` pour les handlers qui doivent s'exécuter dans la requête HTTP (ex. parsing webhook avant dispatch async).
- **Retry** : selon la stratégie du broker de messages retenu (redélivrance sur nack).
- **Middleware** recommandés : `dispatch_after_current_bus`, `doctrine_transaction`.
- **Idempotence** obligatoire sur les handlers async (rejeu possible).

**Queries** : même pattern que les Commands — `#[AsMessageHandler]` + `HandleTrait` dans le controller. Ne pas injecter le handler directement.
