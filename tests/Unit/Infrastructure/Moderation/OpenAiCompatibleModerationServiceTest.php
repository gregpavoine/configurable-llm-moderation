<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Unit\Infrastructure\Moderation;

use Gsoi\CommentModeration\Domain\Comment\ModerationStatus;
use Gsoi\CommentModeration\Infrastructure\Moderation\ModerationLlmConfig;
use Gsoi\CommentModeration\Infrastructure\Moderation\OpenAiCompatibleModerationService;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OpenAiCompatibleModerationServiceTest extends TestCase
{
    private const int RESPONSE_BODY_LIMIT = 65_536;

    #[Test]
    public function itSendsTheBoundedStructuredModerationRequest(): void
    {
        $comment = 'Untrusted comment body.';
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($comment): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('http://127.0.0.1:11434/v1/chat/completions', $url);
            self::assertSame(4.5, $options['timeout']);
            self::assertSame(4.5, $options['max_duration']);
            self::assertSame(0, $options['max_redirects']);
            self::assertFalse($options['buffer']);
            self::assertSame('*', $options['no_proxy']);
            self::assertSame([], array_values(array_filter(
                $options['headers'],
                static fn (string $header): bool => str_starts_with(strtolower($header), 'authorization:'),
            )));

            $body = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('qwen3:8b', $body['model']);
            self::assertSame(0, $body['temperature']);
            self::assertSame(64, $body['max_tokens']);
            self::assertSame('system', $body['messages'][0]['role']);
            self::assertStringContainsString('threat', $body['messages'][0]['content']);
            self::assertStringContainsString('hate or discrimination', $body['messages'][0]['content']);
            self::assertStringContainsString('harassment', $body['messages'][0]['content']);
            self::assertStringContainsString('defamation', $body['messages'][0]['content']);
            self::assertStringContainsString('terrorism praise', $body['messages'][0]['content']);
            self::assertStringContainsString('child sexual content', $body['messages'][0]['content']);
            self::assertSame(['role' => 'user', 'content' => $comment], $body['messages'][1]);
            self::assertSame([
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
            ], $body['response_format']);

            return self::response('published', 'allowed');
        });

        $decision = $this->service($client, $this->config(apiKey: '', timeout: 4.5))->moderate($comment);

        self::assertSame(ModerationStatus::Published, $decision->status);
        self::assertSame('allowed', $decision->reason);
    }

    #[Test]
    public function itMapsARejectedProviderDecision(): void
    {
        $service = $this->service(
            new MockHttpClient(self::response('rejected', 'harassment')),
            $this->config(),
        );

        $decision = $service->moderate('Comment.');

        self::assertSame(ModerationStatus::Rejected, $decision->status);
        self::assertSame('harassment', $decision->reason);
    }

    #[Test]
    #[DataProvider('invalidConfigurations')]
    public function itDefersWithoutCallingTheProviderWhenConfigurationIsNotUsable(
        string $baseUrl,
        string $model,
        string $apiKey,
        string $expectedReason,
    ): void {
        $client = new MockHttpClient(static function (): never {
            self::fail('The provider must not be called for unusable configuration.');
        });
        $logger = new ModerationTestLogger();
        $service = $this->service($client, $this->config($baseUrl, $model, $apiKey), $logger);

        $decision = $service->moderate('Sensitive comment.');

        self::assertSame(ModerationStatus::Pending, $decision->status);
        self::assertSame($expectedReason, $decision->reason);
        $this->assertLogsOnlySafeMetadata($logger, $expectedReason, null, 'Sensitive comment.', 'top-secret');
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'empty configuration' => ['', '', '', 'manual_review_required'];
        yield 'partial configuration' => ['https://api.example.com/v1', '', 'top-secret', 'llm_misconfigured'];
        yield 'insecure configuration' => ['http://api.example.com/v1', 'model', 'top-secret', 'llm_misconfigured'];
    }

    #[Test]
    #[DataProvider('providerFailures')]
    public function itDefersWhenTheProviderIsUnavailable(HttpClientInterface $client): void
    {
        $logger = new ModerationTestLogger();
        $service = $this->service($client, $this->config(apiKey: 'top-secret'), $logger);

        $decision = $service->moderate('Sensitive comment.');

        self::assertSame(ModerationStatus::Pending, $decision->status);
        self::assertSame('llm_unavailable', $decision->reason);
        $this->assertLogsOnlySafeMetadata($logger, 'llm_unavailable', '127.0.0.1', 'Sensitive comment.', 'top-secret');
    }

    /** @return iterable<string, array{HttpClientInterface}> */
    public static function providerFailures(): iterable
    {
        yield 'transport failure' => [new MockHttpClient(static fn (): never => throw new TransportException('connection failed'))];
        yield 'timeout' => [new MockHttpClient(static fn (): never => throw new TimeoutException('request timed out'))];
        yield 'non-2xx status' => [new MockHttpClient(new MockResponse('provider failure', ['http_code' => 503]))];
    }

    #[Test]
    #[DataProvider('invalidProviderResponses')]
    public function itDefersForAnInvalidProviderResponse(MockResponse $response): void
    {
        $logger = new ModerationTestLogger();
        $service = $this->service(
            new MockHttpClient($response),
            $this->config(apiKey: 'top-secret'),
            $logger,
        );

        $decision = $service->moderate('Sensitive comment.');

        self::assertSame(ModerationStatus::Pending, $decision->status);
        self::assertSame('llm_invalid_response', $decision->reason);
        $this->assertLogsOnlySafeMetadata($logger, 'llm_invalid_response', '127.0.0.1', 'Sensitive comment.', 'top-secret');
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function invalidProviderResponses(): iterable
    {
        yield 'malformed outer JSON' => [new MockResponse('{')];
        yield 'missing content' => [new MockResponse('{"choices":[{"message":{}}]}')];
        yield 'malformed structured content' => [new MockResponse('{"choices":[{"message":{"content":"{"}}]}')];
        yield 'structured content is not an object' => [new MockResponse('{"choices":[{"message":{"content":"[]"}}]}')];
        yield 'unknown status' => [self::response('pending', 'uncertain')];
        yield 'empty reason' => [self::response('published', '   ')];
        yield 'reason over 100 characters' => [self::response('rejected', str_repeat('x', 101))];
        yield 'additional structured property' => [self::structuredResponse(['status' => 'published', 'reason' => 'allowed', 'extra' => true])];
    }

    #[Test]
    public function itSendsTheApiKeyOnlyAsABearerAuthorizationHeader(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertContains('Authorization: Bearer top-secret', $options['headers']);

            return self::response('published', 'allowed');
        });

        $decision = $this->service($client, $this->config(apiKey: ' top-secret '))->moderate('Comment.');

        self::assertSame(ModerationStatus::Published, $decision->status);
    }

    #[Test]
    public function itAcceptsAValidResponseJustBelowTheBodyLimit(): void
    {
        $service = $this->service(
            new MockHttpClient(self::sizedResponse(self::RESPONSE_BODY_LIMIT - 1)),
            $this->config(),
        );

        $decision = $service->moderate('Comment.');

        self::assertSame(ModerationStatus::Published, $decision->status);
        self::assertSame('allowed', $decision->reason);
    }

    #[Test]
    public function itCancelsAndDefersAResponseOverTheBodyLimit(): void
    {
        $client = new CapturingMockHttpClient(self::sizedResponse(self::RESPONSE_BODY_LIMIT + 1));
        $service = $this->service($client, $this->config());

        $decision = $service->moderate('Comment.');

        self::assertSame(ModerationStatus::Pending, $decision->status);
        self::assertSame('llm_invalid_response', $decision->reason);
        self::assertTrue($client->lastResponse?->getInfo('canceled'));
    }

    #[Test]
    public function itRejectsAReasonWhoseRawLengthExceedsTheSchemaLimit(): void
    {
        $rawReason = str_repeat(' ', 50).'allowed'.str_repeat(' ', 50);
        $service = $this->service(
            new MockHttpClient(self::response('published', $rawReason)),
            $this->config(),
        );

        $decision = $service->moderate('Comment.');

        self::assertSame(ModerationStatus::Pending, $decision->status);
        self::assertSame('llm_invalid_response', $decision->reason);
    }

    private function config(
        string $baseUrl = 'http://127.0.0.1:11434/v1',
        string $model = 'qwen3:8b',
        string $apiKey = 'top-secret',
        float $timeout = 10.0,
    ): ModerationLlmConfig {
        return new ModerationLlmConfig($baseUrl, $model, $apiKey, $timeout);
    }

    private function service(
        HttpClientInterface $client,
        ModerationLlmConfig $config,
        ?ModerationTestLogger $logger = null,
    ): OpenAiCompatibleModerationService {
        return new OpenAiCompatibleModerationService($client, $logger ?? new ModerationTestLogger(), $config);
    }

    private static function response(string $status, string $reason): MockResponse
    {
        return self::structuredResponse(['status' => $status, 'reason' => $reason]);
    }

    /** @param array<string, mixed> $decision */
    private static function structuredResponse(array $decision): MockResponse
    {
        return new MockResponse((string) json_encode([
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => json_encode($decision, JSON_THROW_ON_ERROR),
                ],
                'finish_reason' => 'stop',
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    private static function sizedResponse(int $length): MockResponse
    {
        $payload = [
            'choices' => [[
                'message' => [
                    'content' => json_encode(['status' => 'published', 'reason' => 'allowed'], JSON_THROW_ON_ERROR),
                ],
            ]],
            'padding' => '',
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $payload['padding'] = str_repeat('x', $length - strlen($body));
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertSame($length, strlen($body));

        return new MockResponse($body);
    }

    private function assertLogsOnlySafeMetadata(
        ModerationTestLogger $logger,
        string $reason,
        ?string $providerHost,
        string $content,
        string $secret,
    ): void {
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('Automated moderation deferred.', $logger->records[0]['message']);
        self::assertSame(['reason' => $reason, 'provider_host' => $providerHost], $logger->records[0]['context']);

        $encodedRecords = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($content, $encodedRecords);
        self::assertStringNotContainsString($secret, $encodedRecords);
        self::assertStringNotContainsString('provider failure', $encodedRecords);
    }
}

final class ModerationTestLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|Stringable, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }
}

final class CapturingMockHttpClient extends MockHttpClient
{
    public ?ResponseInterface $lastResponse = null;

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->lastResponse = parent::request($method, $url, $options);
    }
}
