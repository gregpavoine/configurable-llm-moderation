# Comment Moderation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the mandatory Symfony comment submission, moderation, detail, and search API as a tested DDD/CQRS application.

**Architecture:** A `Comment` aggregate owns moderation transitions, application handlers orchestrate domain ports, and Doctrine adapters implement persistence. Submission is synchronous so the API can acknowledge with an identifier; moderation alone is routed asynchronously through Messenger and a replaceable `ModerationService` port.

**Tech Stack:** PHP 8.5, Symfony 8.0, Doctrine ORM/DBAL/Messenger, PHPUnit 12, PHPStan 2, PHPat, Nelmio OpenAPI.

## Global Constraints

- Every PHP file uses `declare(strict_types=1);`.
- PHPStan must pass at level 9 on `src/`.
- Command/query input uses Symfony constraints and explicit handler validation.
- Domain and Application never depend on `EntityManagerInterface`.
- Controllers dispatch messages through `MessageBusInterface`; they do not call repositories or handlers.
- Only moderation is asynchronous; its handler is idempotent.
- The final README contains 10-15 substantive lines and an `Usage de l'IA` section.

---

### Task 1: Executable quality baseline

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `phparkitect.php`
- Create: `config/packages/messenger.yaml`
- Modify: `.env`
- Delete: `config/packages/nyholm_psr7.yaml`
- Test: `tests/Architecture/LayerRulesTest.php`

**Interfaces:**
- Consumes: the supplied Symfony skeleton.
- Produces: executable PHPUnit, PHPStan, PHPat, Doctrine Messenger transports named `async` and `failed`.

- [ ] Add PHPUnit, PHPStan, PHPat, Symfony Doctrine Messenger, and Symfony test dependencies; remove the dangling Nyholm configuration.
- [ ] Write a failing architecture test/config that detects `EntityManagerInterface` under `src/Domain` or `src/Application`.
- [ ] Run `vendor/bin/phpunit tests/Architecture` and confirm the missing configuration/dependency failure.
- [ ] Add the minimal PHPat layer rules and Messenger configuration, then rerun the architecture test.
- [ ] Run `vendor/bin/phpunit`, `vendor/bin/phpstan analyse`, and the PHPat command.
- [ ] Commit with `chore: prepare test and messaging infrastructure`.

### Task 2: Comment aggregate and persistence

**Files:**
- Create: `src/Domain/Comment/Comment.php`
- Create: `src/Domain/Comment/CommentId.php`
- Create: `src/Domain/Comment/ModerationStatus.php`
- Create: `src/Domain/Comment/CommentRepository.php`
- Create: `src/Domain/Comment/BannedUserRepository.php`
- Create: `src/Domain/Comment/Exception/CommentNotFoundException.php`
- Create: `src/Domain/DomainException.php`
- Create: `src/Infrastructure/Persistence/Doctrine/DoctrineCommentRepository.php`
- Create: `src/Infrastructure/Persistence/Doctrine/DoctrineBannedUserRepository.php`
- Create: `src/Infrastructure/Persistence/Doctrine/BannedUser.php`
- Create: `migrations/Version20260809000000.php`
- Test: `tests/Unit/Domain/Comment/CommentTest.php`

**Interfaces:**
- Produces: `Comment::submit(...)`, `publish(string $reason)`, `reject(string $reason)`, `isPending(): bool`, repository `save`, `get`, and `search` methods.
- Produces: `BannedUserRepository::isBanned(string $userId): bool`.

- [ ] Write aggregate tests proving a new comment is pending, publish/reject record the reason and date, and a final decision cannot be changed.
- [ ] Run the unit test and confirm it fails because the domain types do not exist.
- [ ] Implement the minimal aggregate, enum, identifier, exception, and repository ports.
- [ ] Run the unit test until green, then refactor duplicated transition checks.
- [ ] Add Doctrine repository integration tests and confirm they fail before adapters/migration exist.
- [ ] Implement Doctrine entities/adapters and migration, then run the integration tests.
- [ ] Commit with `feat: add comment domain and persistence`.

### Task 3: Submission and banned-user decision

**Files:**
- Create: `src/Application/Command/SubmitComment/SubmitCommentCommand.php`
- Create: `src/Application/Command/SubmitComment/SubmitCommentHandler.php`
- Create: `src/Application/Command/SubmitComment/SubmitCommentResult.php`
- Create: `src/Application/Command/ModerateComment/ModerateCommentCommand.php`
- Create: `src/UI/Api/Comment/SubmitCommentRequest.php`
- Create: `src/UI/Api/Comment/SubmitCommentController.php`
- Create: `src/UI/Api/HandleTrait.php`
- Test: `tests/Unit/Application/SubmitCommentHandlerTest.php`
- Test: `tests/Functional/UI/Api/SubmitCommentControllerTest.php`

**Interfaces:**
- Consumes: `CommentRepository`, `BannedUserRepository`, and `MessageBusInterface`.
- Produces: `SubmitCommentResult(string $id, ModerationStatus $status)` and `POST /comments` returning `202`.

- [ ] Write handler tests for an anonymous submission, a normal author dispatching moderation, and a banned author rejected without dispatch.
- [ ] Run the handler tests and observe the expected missing-handler failure.
- [ ] Implement the command, result, handler, and moderation message minimally.
- [ ] Run handler tests until green.
- [ ] Write functional tests for valid `202`, malformed/invalid payload `422`, and banned response status.
- [ ] Implement request DTO, controller, and shared bus handling; rerun functional tests.
- [ ] Commit with `feat: accept comments and block banned authors`.

### Task 4: Asynchronous moderation

**Files:**
- Create: `src/Domain/Moderation/ModerationService.php`
- Create: `src/Domain/Moderation/ModerationDecision.php`
- Create: `src/Infrastructure/Moderation/StubModerationService.php`
- Create: `src/Application/Command/ModerateComment/ModerateCommentHandler.php`
- Modify: `config/services.yaml`
- Test: `tests/Unit/Application/ModerateCommentHandlerTest.php`
- Test: `tests/Unit/Infrastructure/Moderation/StubModerationServiceTest.php`

**Interfaces:**
- Produces: `ModerationService::moderate(string $content): ModerationDecision`.
- Produces: a handler that loads by identifier, no-ops on final comments, applies one decision, and saves.

- [ ] Write failing tests for publish, reject, missing comment, and repeated delivery idempotence.
- [ ] Implement decision type, service port, and handler until the tests pass.
- [ ] Write failing stub tests for benign text and each documented illegal-content category.
- [ ] Implement the deterministic adapter using normalized phrase rules and stable reason codes.
- [ ] Wire the port alias and verify Messenger routing with `debug:messenger`.
- [ ] Commit with `feat: moderate pending comments asynchronously`.

### Task 5: Detail, search, and uniform errors

**Files:**
- Create: `src/Application/Query/GetComment/GetCommentQuery.php`
- Create: `src/Application/Query/GetComment/GetCommentHandler.php`
- Create: `src/Application/Query/SearchComments/SearchCommentsQuery.php`
- Create: `src/Application/Query/SearchComments/SearchCommentsHandler.php`
- Create: `src/Application/Query/CommentView.php`
- Create: `src/Application/Query/CommentSearchResult.php`
- Create: `src/UI/Api/Comment/GetCommentController.php`
- Create: `src/UI/Api/Comment/SearchCommentsController.php`
- Create: `src/UI/Api/Comment/SearchCommentsParams.php`
- Create: `src/UI/Api/Exception/JsonExceptionListener.php`
- Test: `tests/Functional/UI/Api/GetCommentControllerTest.php`
- Test: `tests/Functional/UI/Api/SearchCommentsControllerTest.php`

**Interfaces:**
- Produces: `GET /comments/{id}` and `GET /comments?publisher=&status=&limit=&offset=`.
- Produces: JSON errors shaped as `{"error":{"code":"...","message":"...","violations":[]}}`.

- [ ] Write failing functional tests for detail success/not-found and filtered/paginated search.
- [ ] Implement query view models, handlers, params, and controllers until green.
- [ ] Write failing functional tests for uniform `400`, `404`, and `422` responses.
- [ ] Implement the exception listener and rerun the full functional suite.
- [ ] Commit with `feat: expose comment detail and search APIs`.

### Task 6: Acceptance and delivery documentation

**Files:**
- Modify: `README.md`
- Modify: API controllers/DTOs only if OpenAPI verification reveals missing schemas.

**Interfaces:**
- Produces: documented install, worker, API choices, missing optional scope, and AI usage in 10-15 substantive lines.

- [ ] Run migrations, the Messenger routing diagnostic, PHPUnit, PHPStan, PHPat, and Symfony lint commands.
- [ ] Inspect generated OpenAPI JSON and ensure all three endpoints and response statuses exist.
- [ ] Replace the skeleton README with the concise delivery README and explicit stub disclosure.
- [ ] Run the full verification suite again from a clean test database.
- [ ] Commit with `docs: finalize delivery and usage notes`.
- [ ] Review `git log --oneline`, `git status`, and the diff from the baseline commit.
