# Configurable LLM and manual moderation design

## Goal

Use a configured local or external LLM for asynchronous comment moderation. When no usable LLM is configured, or when the provider cannot return a trustworthy decision, keep the comment pending for a moderator. Remove the deterministic stub from runtime wiring while retaining test doubles in the test suite.

## Runtime configuration

The integration uses the OpenAI-compatible chat-completions contract so the same adapter can target Ollama, OpenAI, Mistral, LM Studio, or another compatible server. The configured base URL includes `/v1` (for example `http://127.0.0.1:11434/v1`), and the adapter appends `/chat/completions` after removing a trailing slash.

- `MODERATION_LLM_BASE_URL`: provider base URL, empty by default.
- `MODERATION_LLM_MODEL`: provider model name, empty by default.
- `MODERATION_LLM_API_KEY`: optional bearer credential; empty is valid for a trusted local server.
- `MODERATION_LLM_TIMEOUT`: total request timeout, with a conservative default.

The provider is enabled only when both the base URL and model are present. Empty configuration selects manual review. Partial configuration, connection errors, timeouts, non-success HTTP responses, malformed JSON, and invalid decisions all produce a deferred result with an operational reason; they never publish content. Secrets and comment bodies are not written to logs.

## Moderation flow

1. `POST /comments` validates and persists a new comment as `pending`.
2. A banned author is still rejected immediately and never reaches Messenger or the LLM.
3. Other comments dispatch `ModerateCommentCommand` to the existing Doctrine transport.
4. The worker invokes `ModerationService`.
5. With valid LLM configuration, the HTTP adapter sends the comment as untrusted input under a fixed moderation system prompt. It requests a structured decision containing only `status` (`published` or `rejected`) and a bounded `reason`.
6. Without a trustworthy final decision, the domain records a reason such as `manual_review_required`, `llm_misconfigured`, `llm_unavailable`, or `llm_invalid_response` while preserving `pending` and leaving `moderatedAt` empty.
7. A moderator resolves a pending comment through the manual endpoint. Final comments remain immutable and repeated or conflicting decisions return a domain error.

## Domain and application changes

`ModerationDecision` gains an explicit deferred outcome rather than representing fallback through an exception. `Comment::defer()` records why manual review is required without creating a final moderation timestamp. The asynchronous handler applies published, rejected, or deferred outcomes through the aggregate and repository port.

A new `ManuallyModerateCommentCommand` and handler validate the requested final status and reason, load the comment, enforce the pending-only transition, and persist it. Controllers continue to dispatch application messages and never access Doctrine directly.

## HTTP API and authorization

- `POST /comments` remains public for comment collection.
- `GET /comments` and `GET /comments/{id}` require an authenticated operator.
- `POST /comments/{id}/moderation` requires `ROLE_MODERATOR` and accepts only `published` or `rejected` plus an optional bounded reason.
- `/`, `/health`, and development `/doc` remain public.

Authentication uses stateless Bearer JWTs with asymmetric keys and LexikJWTAuthenticationBundle's database-less provider. A development CLI command issues a short-lived moderator token; no fake password database or public login endpoint is added. Tokens are accepted only from the `Authorization` header. Private keys and passphrases stay outside Git, token expiration is mandatory, and production deployment requires HTTPS. OpenAPI declares the Bearer security scheme and protected operations.

## Security controls

The provider URL comes exclusively from trusted deployment configuration, never request data. The HTTP client disallows redirects, uses bounded connect/total timeouts, and validates the scheme: HTTPS is required except for loopback HTTP addresses used by local inference. This limits SSRF and credential forwarding risks.

The LLM receives no tools and cannot trigger application actions. Its response is treated as untrusted data, decoded with strict error handling, validated against the allowlist and length constraints, then converted to domain types. Prompt injection can therefore influence only a proposed moderation decision, not code execution or network destinations. Failures are fail-closed to manual review.

JWT verification pins the expected asymmetric algorithm and validates signature and temporal claims through the bundle. Access rules are tested for anonymous, authenticated, and moderator cases. Authentication and authorization errors retain the API's JSON error envelope without exposing exception details.

## Error handling and observability

Provider failures are logged with a stable reason and provider host, never the API key, raw token, prompt, response body, or comment text. The API exposes the deferred reason on comment detail/search so operators can identify the manual queue. Search by `status=pending` remains the operator inbox.

The manual endpoint returns the updated comment view. Unknown comments return `404`, invalid payloads return `422`, unauthenticated requests return `401`, insufficient roles return `403`, and invalid final-state transitions return `409`.

## Testing and quality

Unit tests cover configuration selection, prompt/response mapping, every deferred failure mode, domain transitions, and manual moderation. HTTP tests use Symfony `MockHttpClient`; no live provider or secret is required. Functional tests cover the public submission flow, protected reads, JWT role checks, manual decisions, JSON errors, and OpenAPI security metadata.

The final verification runs PHPUnit, PHPStan level 9, PHPat, container/YAML linting, Doctrine schema validation, Composer validation/audit, and a repository security scan. Production code remains strict-typed, comments are sparse and written in English only when they explain a non-obvious constraint.

## Out of scope

This increment does not add user registration, passwords, refresh tokens, token revocation, a moderator UI, decision history, provider-specific SDKs, or automatic failover across multiple LLMs. Those can be added behind the established ports without changing the comment submission contract.
