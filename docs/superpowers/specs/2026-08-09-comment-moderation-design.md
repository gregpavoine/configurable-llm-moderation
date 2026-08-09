# Comment Moderation Service Design

## Scope

Build the mandatory take-home scope: submit a comment, reject comments from banned users without invoking moderation, moderate other comments asynchronously through a replaceable LLM port, retrieve one comment, and search comments by publisher or status. Optional operator, outbound delivery, and Facebook features are deliberately excluded.

## Domain

`Comment` is the aggregate root. It owns a UUID, publisher identifier, source identifier, optional author identifier, body, moderation status, moderation reason, creation date, and moderation date. New comments start as `PENDING`; only pending comments may transition to `PUBLISHED` or `REJECTED`. A banned author is persisted as a `REJECTED` comment immediately so every submission remains traceable.

The moderation result contains a decision and a stable reason code. The initial adapter is a documented deterministic stub because no external LLM credential is supplied. The application depends only on `ModerationService`, making a real HTTP LLM adapter replaceable without changing the use cases.

## Application and data flow

`POST /comments` maps JSON to `SubmitCommentCommand`. Its synchronous handler validates the command, checks `BannedUserRepository`, persists the aggregate, and dispatches `ModerateCommentCommand` only for a non-banned author. The controller returns `202 Accepted` with the identifier and current status.

`ModerateCommentCommand` is routed to a Doctrine Messenger transport. Its idempotent handler ignores comments that are no longer pending, calls `ModerationService`, applies the decision, and saves once. Retries are owned by Messenger and therefore never recreate the comment.

`GET /comments/{id}` and `GET /comments` dispatch queries through Messenger synchronously. They return application view models rather than Doctrine entities. Search supports publisher and moderation-status filters plus bounded offset pagination.

## Persistence and infrastructure

Doctrine attributes remain on domain entities as allowed by the skeleton. Repository interfaces live in Domain; Doctrine implementations live in Infrastructure. SQLite is used for tests. The async transport uses Doctrine with a dedicated `moderation` queue and a failed-message transport.

The stub moderation prompt policy covers threats, hate or discriminatory incitement, harassment, defamation, and clearly illicit content. Comment text is treated as untrusted data. Author identifiers are not sent to the moderation adapter.

## HTTP errors

Application messages carry Symfony validation constraints and handlers validate explicitly. Validation failures become uniform JSON `422` responses, missing comments become `404`, malformed JSON becomes `400`, and unexpected production errors return a generic `500` payload. OpenAPI attributes describe request, response, and filter contracts.

## Tests and quality

Unit tests cover aggregate transitions, banned-user bypass, moderation decisions, and idempotence. Functional tests cover submission, detail, filtering, validation, and not-found behavior using the test database. PHPStan runs at level 9. Architecture rules prevent Doctrine's entity manager from entering Domain or Application and enforce handler/domain-exception conventions.

## Delivery

The Git history is split into focused commits: baseline, design/tooling, domain/persistence, moderation flow, read API, and documentation/verification. The final README stays within 10-15 substantive lines and explicitly discloses the moderation stub and AI-assisted development choices.
