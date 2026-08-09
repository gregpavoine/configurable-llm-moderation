# Configurable LLM and Manual Moderation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Use a configured OpenAI-compatible LLM for asynchronous moderation and safely defer to a JWT-protected manual workflow when no trustworthy automated decision is available.

**Architecture:** Preserve the existing `ModerationService` port and Messenger flow. Add a fail-closed HTTP adapter that returns an explicit deferred domain decision, then expose a separate CQRS command for moderator decisions. Use a stateless, database-less Lexik JWT firewall for read and moderation routes while keeping comment submission public.

**Tech Stack:** PHP 8.5, Symfony 8.0, Messenger with Doctrine transport, Symfony HttpClient, LexikJWTAuthenticationBundle 3.2, Doctrine ORM, PHPUnit 12, PHPStan 2 level 9, PHPat.

## Global Constraints

- Keep `declare(strict_types=1);` in every PHP file and PHPStan level 9 clean on `src/`.
- Preserve DDD/CQRS dependency directions and repository ports; UI must never access Doctrine directly.
- Production comments must be sparse, useful, understandable, and written in English.
- Never log API keys, bearer tokens, comment bodies, prompts, or raw provider responses.
- Treat every LLM response as untrusted input and fail closed to `pending` manual review.
- Accept LLM base URLs only from deployment configuration; require HTTPS except for loopback HTTP.
- JWT authentication is stateless, uses RS256, accepts Bearer tokens only in the `Authorization` header, and requires expiration.
- Keep `POST /comments`, `/`, `/health`, and development `/doc` public; protect reads and manual moderation.
- Do not add users, passwords, refresh tokens, provider SDKs, a moderator UI, or multi-provider failover.

---

### Task 1: Deferred moderation domain outcome

**Files:**
- Modify: `src/Domain/Moderation/ModerationDecision.php`
- Modify: `src/Domain/Comment/Comment.php`
- Modify: `src/Application/Command/ModerateComment/ModerateCommentHandler.php`
- Modify: `tests/Unit/Domain/Comment/CommentTest.php`
- Modify: `tests/Unit/Application/ModerateCommentHandlerTest.php`

**Interfaces:**
- Produces: `ModerationDecision::defer(string $reason): ModerationDecision`
- Produces: `Comment::defer(string $reason): void`
- Preserves: `ModerationService::moderate(string $content): ModerationDecision`

- [ ] **Step 1: Write failing domain tests for deferred decisions**

```php
public function testAPendingCommentCanBeDeferredToManualReview(): void
{
    $comment = Comment::submit('publisher', 'article', 'author', 'Body.');
    $comment->defer('manual_review_required');

    self::assertSame(ModerationStatus::Pending, $comment->status());
    self::assertSame('manual_review_required', $comment->moderationReason());
    self::assertNull($comment->moderatedAt());
}

public function testAFinalCommentCannotBeDeferred(): void
{
    $comment = Comment::submit('publisher', 'article', 'author', 'Body.');
    $comment->publish('allowed');

    $this->expectException(InvalidModerationTransitionException::class);
    $comment->defer('manual_review_required');
}
```

Extend the handler test with a service returning `ModerationDecision::defer('llm_unavailable')`; assert the saved comment stays pending with that reason.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
vendor/bin/phpunit tests/Unit/Domain/Comment/CommentTest.php tests/Unit/Application/ModerateCommentHandlerTest.php --testdox
```

Expected: failures because `defer()` does not exist.

- [ ] **Step 3: Implement the minimal deferred outcome**

Add to `ModerationDecision`:

```php
public static function defer(string $reason): self
{
    return new self(ModerationStatus::Pending, $reason);
}
```

Add to `Comment`:

```php
public function defer(string $reason): void
{
    if (!$this->isPending()) {
        throw InvalidModerationTransitionException::fromFinalState($this->status->value);
    }

    $this->moderationReason = $reason;
    $this->moderatedAt = null;
}
```

Update `ModerateCommentHandler` to use a three-way `match` and call `defer()` for `Pending`.

- [ ] **Step 4: Verify and commit**

```bash
vendor/bin/phpunit tests/Unit/Domain/Comment/CommentTest.php tests/Unit/Application/ModerateCommentHandlerTest.php --testdox
vendor/bin/phpstan analyse --no-progress
vendor/bin/phparkitect check
git add src/Domain src/Application/Command/ModerateComment tests/Unit/Domain tests/Unit/Application/ModerateCommentHandlerTest.php
git commit -m "feat: support deferred moderation decisions"
```

---

### Task 2: OpenAI-compatible moderation adapter

**Files:**
- Modify: `composer.json`, `composer.lock`, `.env`, `config/services.yaml`
- Create: `src/Infrastructure/Moderation/ModerationLlmConfig.php`
- Create: `src/Infrastructure/Moderation/OpenAiCompatibleModerationService.php`
- Delete: `src/Infrastructure/Moderation/StubModerationService.php`
- Delete: `tests/Unit/Infrastructure/Moderation/StubModerationServiceTest.php`
- Create: `tests/Unit/Infrastructure/Moderation/ModerationLlmConfigTest.php`
- Create: `tests/Unit/Infrastructure/Moderation/OpenAiCompatibleModerationServiceTest.php`

**Interfaces:**
- Produces: `ModerationLlmConfig::__construct(string $baseUrl, string $model, string $apiKey, float $timeout)`
- Produces: `ModerationLlmConfig::isConfigured(): bool` and `endpoint(): ?string`
- Produces: `model(): string`, `apiKey(): string`, `timeout(): float`, `providerHost(): ?string`, and `deferredReason(): ?string`
- Produces: `OpenAiCompatibleModerationService::moderate(string $content): ModerationDecision`
- Consumes: `HttpClientInterface`, `LoggerInterface`, `ModerationLlmConfig`

- [ ] **Step 1: Install the bounded HTTP dependency**

```bash
composer require symfony/http-client:8.0.* --no-interaction
composer validate --strict
```

- [ ] **Step 2: Write failing configuration tests**

Cover these cases:

```php
yield 'empty selects manual review' => ['', '', '', false, null];
yield 'ollama loopback http is allowed' => ['http://127.0.0.1:11434/v1/', 'qwen3:8b', '', true, 'http://127.0.0.1:11434/v1/chat/completions'];
yield 'external https is allowed' => ['https://api.example.com/v1', 'model', 'secret', true, 'https://api.example.com/v1/chat/completions'];
yield 'partial configuration is rejected' => ['https://api.example.com/v1', '', '', false, null];
yield 'remote plain http is rejected' => ['http://api.example.com/v1', 'model', '', false, null];
```

Also assert embedded URL credentials are rejected and non-positive timeout values become the documented positive default.

- [ ] **Step 3: Write failing adapter tests with `MockHttpClient`**

Inspect the outgoing request:

```php
self::assertSame('POST', $method);
self::assertSame('http://127.0.0.1:11434/v1/chat/completions', $url);
self::assertSame('qwen3:8b', $body['model']);
self::assertSame('json_schema', $body['response_format']['type']);
self::assertSame(0, $body['temperature']);
```

Test empty, partial, and insecure configuration; published/rejected responses; transport failure; timeout; non-2xx status; malformed outer JSON; missing content; malformed structured content; unknown status; empty reason; reason over 100 characters; and Bearer header handling. Assert no secret or content reaches logger context.

- [ ] **Step 4: Run tests and verify RED**

```bash
vendor/bin/phpunit tests/Unit/Infrastructure/Moderation/ModerationLlmConfigTest.php tests/Unit/Infrastructure/Moderation/OpenAiCompatibleModerationServiceTest.php --testdox
```

- [ ] **Step 5: Implement configuration validation**

Trim values; require URL and model together; allow only HTTPS or loopback HTTP (`localhost`, `127.0.0.0/8`, `::1`); reject URL credentials; strip the trailing slash; append `/chat/completions`; retain an internal invalid reason to distinguish empty from malformed configuration.

- [ ] **Step 6: Implement the fail-closed HTTP adapter**

Use a fixed system prompt covering threat, hate/discrimination, harassment, defamation, terrorism praise, and child sexual content. Send the comment only as an untrusted user message. Request:

```php
[
    'model' => $config->model(),
    'temperature' => 0,
    'messages' => [
        ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
        ['role' => 'user', 'content' => $content],
    ],
    'response_format' => [
        'type' => 'json_schema',
        'json_schema' => [
            'name' => 'moderation_decision',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['published', 'rejected']],
                    'reason' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                ],
                'required' => ['status', 'reason'],
                'additionalProperties' => false,
            ],
        ],
    ],
]
```

Set bounded `timeout`, `max_duration`, and `max_redirects: 0`. Decode with `JSON_THROW_ON_ERROR`, validate the exact schema/allowlist, and log only stable reason codes plus provider host.

- [ ] **Step 7: Wire safe defaults and remove the runtime stub**

```dotenv
MODERATION_LLM_BASE_URL=
MODERATION_LLM_MODEL=
MODERATION_LLM_API_KEY=
MODERATION_LLM_TIMEOUT=10
```

Bind constructor arguments in `config/services.yaml`, alias the port to the HTTP adapter, and delete the production stub and phrase-matching test.

- [ ] **Step 8: Verify and commit**

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
vendor/bin/phparkitect check
php bin/console lint:container
git add composer.json composer.lock .env config/services.yaml src/Infrastructure/Moderation tests/Unit/Infrastructure/Moderation tests/Unit/Application/ModerateCommentHandlerTest.php
git commit -m "feat: use configured LLM for moderation"
```

---

### Task 3: Manual moderation application use case

**Files:**
- Create: `src/Application/Command/ManuallyModerateComment/ManuallyModerateCommentCommand.php`
- Create: `src/Application/Command/ManuallyModerateComment/ManuallyModerateCommentHandler.php`
- Modify: `src/UI/Api/Exception/JsonExceptionListener.php`
- Create: `tests/Unit/Application/ManuallyModerateCommentHandlerTest.php`
- Modify: `tests/Functional/UI/Api/ErrorResponseTest.php`

**Interfaces:**
- Produces: `ManuallyModerateCommentCommand::__construct(string $commentId, string $status, string $reason)`
- Produces: handler return type `CommentView`
- Consumes: `CommentRepository`, `ValidatorInterface`

- [ ] **Step 1: Write failing handler tests**

Cover publish, reject, invalid status, unknown identifier, and conflicting final state:

```php
$view = ($handler)(new ManuallyModerateCommentCommand($id, 'published', 'approved_by_operator'));
self::assertSame('published', $view->status);
self::assertSame('approved_by_operator', $view->moderationReason);
self::assertNotNull($view->moderatedAt);
```

- [ ] **Step 2: Run and verify RED**

```bash
vendor/bin/phpunit tests/Unit/Application/ManuallyModerateCommentHandlerTest.php --testdox
```

- [ ] **Step 3: Implement command and handler**

Use `NotBlank`, `Uuid`, `Choice(['published', 'rejected'])`, and `Length(min: 1, max: 100)`. Validate explicitly, load through `CommentRepository`, call `publish()` or `reject()` from the exact allowlist, save, and return `CommentView::fromComment($comment)`.

- [ ] **Step 4: Map state conflicts**

Map `InvalidModerationTransitionException` in `JsonExceptionListener` to HTTP `409`:

```json
{"error":{"code":"moderation_conflict","message":"...","violations":[]}}
```

- [ ] **Step 5: Verify and commit**

```bash
vendor/bin/phpunit tests/Unit/Application/ManuallyModerateCommentHandlerTest.php tests/Functional/UI/Api/ErrorResponseTest.php --testdox
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
vendor/bin/phparkitect check
git add src/Application/Command/ManuallyModerateComment src/UI/Api/Exception tests/Unit/Application/ManuallyModerateCommentHandlerTest.php tests/Functional/UI/Api/ErrorResponseTest.php
git commit -m "feat: add manual moderation use case"
```

---

### Task 4: JWT-protected operator API

**Files:**
- Modify: `composer.json`, `composer.lock`, `config/bundles.php`, `.env`, `.gitignore`, `tests/bootstrap.php`
- Create: `config/packages/security.yaml`
- Create: `config/packages/lexik_jwt_authentication.yaml`
- Create: `config/jwt/.gitignore`
- Modify: `config/packages/dev/nelmio_api_doc.yaml`, `config/services.yaml`
- Create: `src/Infrastructure/Security/JsonAuthenticationEntryPoint.php`
- Create: `src/UI/Cli/IssueModeratorTokenCommand.php`
- Create: `src/UI/Api/Comment/ManualModerationRequest.php`
- Create: `src/UI/Api/Comment/ManuallyModerateCommentController.php`
- Modify: `src/UI/Api/Comment/GetCommentController.php`, `src/UI/Api/Comment/SearchCommentsController.php`
- Create: `tests/Functional/UI/Api/SecurityAccessTest.php`
- Create: `tests/Functional/UI/Api/ManualModerationControllerTest.php`
- Modify: `tests/Functional/UI/Api/CommentReadControllerTest.php`, `tests/Functional/UI/Api/ErrorResponseTest.php`

**Interfaces:**
- Produces: `POST /comments/{id}/moderation` with `{status: published|rejected, reason?: string}`
- Produces: `app:jwt:issue-moderator --subject=<identifier>`
- Consumes: `ManuallyModerateCommentCommand`, `JWTTokenManagerInterface`, database-less Lexik provider

- [ ] **Step 1: Install bounded security dependencies**

```bash
composer require symfony/security-bundle:8.0.* lexik/jwt-authentication-bundle:^3.2 --no-interaction
composer validate --strict
```

- [ ] **Step 2: Create ephemeral test keys before kernel boot**

In `tests/bootstrap.php`, create a 2048-bit RSA pair under ignored `var/jwt-test/` only when missing with `openssl_pkey_new`, `openssl_pkey_export`, and `openssl_pkey_get_details`. Set `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, and a test passphrase in `$_SERVER` and `$_ENV` before Symfony boots. Never commit a test private key.

- [ ] **Step 3: Write failing access tests**

```php
$client->jsonRequest('POST', '/comments', $validPayload);
self::assertResponseStatusCodeSame(202);

$client->request('GET', '/comments');
self::assertResponseStatusCodeSame(401);

$client->request('GET', '/comments', server: ['HTTP_AUTHORIZATION' => 'Bearer invalid']);
self::assertResponseStatusCodeSame(401);
```

Create valid tokens through `JWTTokenManagerInterface` and `JWTUser::createFromPayload()`. Assert a token without `ROLE_MODERATOR` gets `403` on manual moderation and a moderator token is admitted.

- [ ] **Step 4: Write failing manual-controller tests**

Cover authenticated publish/reject success, default reasons (`approved_by_operator`, `rejected_by_operator`), invalid status `422`, missing comment `404`, and second decision `409`. Assert the response is the updated `CommentView`.

- [ ] **Step 5: Run and verify RED**

```bash
vendor/bin/phpunit tests/Functional/UI/Api/SecurityAccessTest.php tests/Functional/UI/Api/ManualModerationControllerTest.php --testdox
```

- [ ] **Step 6: Configure stateless RS256 authentication**

Configure Lexik with environment key paths, passphrase, `RS256`, and 900-second TTL. Configure a database-less `lexik_jwt` provider and stateless API firewall. Order rules exactly:

```yaml
access_control:
    - { path: ^/$, roles: PUBLIC_ACCESS }
    - { path: ^/health$, roles: PUBLIC_ACCESS }
    - { path: ^/doc, roles: PUBLIC_ACCESS }
    - { path: ^/comments$, roles: PUBLIC_ACCESS, methods: [POST] }
    - { path: ^/comments/.+/moderation$, roles: ROLE_MODERATOR, methods: [POST] }
    - { path: ^/comments, roles: IS_AUTHENTICATED_FULLY, methods: [GET] }
```

Use `JsonAuthenticationEntryPoint` for the existing error envelope and map access denied to `403 forbidden` without internal details.

- [ ] **Step 7: Implement protected endpoint and DTO**

Validate status and optional reason. Use `#[IsGranted('ROLE_MODERATOR')]`, default missing reason by status, dispatch the command, require a `CommentView`, and return `200`. Add Bearer OpenAPI metadata to read and manual operations.

- [ ] **Step 8: Implement development token command**

Create a database-less `JWTUser` with `ROLE_MODERATOR`, issue through `JWTTokenManagerInterface`, and write only the token to stdout. Require non-empty `--subject`; do not accept TTL, algorithm, key path, or roles from CLI input.

- [ ] **Step 9: Configure safe key paths**

```dotenv
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=
```

Ignore `config/jwt/*.pem` and preserve the directory with `config/jwt/.gitignore`.

Generate a development pair only after the ignore rules exist:

```bash
php bin/console lexik:jwt:generate-keypair --skip-if-exists
git check-ignore config/jwt/private.pem config/jwt/public.pem
```

Expected: both generated files are ignored and neither appears in `git status`.

- [ ] **Step 10: Update existing tests to send real JWTs**

Create a shared helper that obtains a moderator token from the test container. Use it in read and read-error tests. Do not bypass the firewall with `loginUser()`.

- [ ] **Step 11: Verify and commit**

```bash
vendor/bin/phpunit tests/Functional/UI/Api --testdox
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
vendor/bin/phparkitect check
php bin/console lint:container
php bin/console lint:yaml config
php bin/console debug:config security
git add composer.json composer.lock config .env .gitignore tests/bootstrap.php src/Infrastructure/Security src/UI/Cli src/UI/Api/Comment tests/Functional/UI/Api
git commit -m "feat: secure manual moderation with JWT"
```

---

### Task 5: Documentation, acceptance, and security verification

**Files:**
- Modify: `README.md`
- Modify: `tests/Functional/UI/Api/SubmitCommentControllerTest.php`
- Modify only for validated findings: scoped production files and their regression tests

**Interfaces:**
- Documents: LLM environment, key generation, token issuance, worker, endpoint, fallback
- Verifies: OpenAPI security, fresh migration, dependency audit, repository security posture

- [ ] **Step 1: Add end-to-end fallback assertions**

Assert empty LLM configuration produces:

```json
{"status":"pending","moderationReason":"manual_review_required","moderatedAt":null}
```

Keep banned authors immediately `rejected/author_banned` with no queued message.

- [ ] **Step 2: Run acceptance tests**

```bash
vendor/bin/phpunit tests/Functional/UI/Api/SubmitCommentControllerTest.php tests/Functional/UI/Api/ManualModerationControllerTest.php --testdox
```

- [ ] **Step 3: Update README within 15 lines**

Document empty/manual fallback, local Ollama and external examples, key generation, token issuance, Bearer use, worker, manual endpoint, security limits, and the required AI-usage disclosure. Verify `wc -l README.md` is at most 15.

- [ ] **Step 4: Run standard repository security scan**

Invoke `codex-security:security-scan`. Validate candidates before changing code. Fix confirmed in-scope findings with regression tests; never suppress analysis or weaken validation.

- [ ] **Step 5: Run complete fresh verification**

```bash
composer validate --strict
composer install --dry-run --no-interaction
composer audit --locked
vendor/bin/phpunit
vendor/bin/phpstan analyse --no-progress
vendor/bin/phparkitect check
php bin/console lint:container
php bin/console lint:yaml config
php bin/console doctrine:schema:validate
php bin/console nelmio:apidoc:dump --format=json
git diff --check
git status --short
```

Migrate a fresh temporary SQLite database and verify `/comments`, `/comments/{id}`, and `/comments/{id}/moderation` in OpenAPI with Bearer security on protected operations.

- [ ] **Step 6: Commit docs and any verified security fixes**

Commit each security fix separately as `fix: harden <specific boundary>`, then:

```bash
git add README.md tests/Functional/UI/Api/SubmitCommentControllerTest.php
git commit -m "docs: explain LLM and manual moderation flows"
```

- [ ] **Step 7: Review final history**

```bash
git log --oneline --decorate main..HEAD
git status --short --branch
```

Expected: several concise commits, a clean worktree, and no secret or generated key material tracked.
