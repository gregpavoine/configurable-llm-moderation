<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Unit\Infrastructure\Moderation;

use Gsoi\CommentModeration\Infrastructure\Moderation\ModerationLlmConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModerationLlmConfigTest extends TestCase
{
    #[Test]
    #[DataProvider('configurations')]
    public function itSelectsOnlyCompleteAndSecureProviderConfigurations(
        string $baseUrl,
        string $model,
        string $apiKey,
        bool $configured,
        ?string $endpoint,
        ?string $deferredReason,
    ): void {
        $config = $this->config($baseUrl, $model, $apiKey, 7.5);

        self::assertSame($configured, $config->isConfigured());
        self::assertSame($endpoint, $config->endpoint());
        self::assertSame($deferredReason, $config->deferredReason());
        self::assertSame(trim($model), $config->model());
        self::assertSame(trim($apiKey), $config->apiKey());
        self::assertSame(7.5, $config->timeout());
    }

    /** @return iterable<string, array{string, string, string, bool, ?string, ?string}> */
    public static function configurations(): iterable
    {
        yield 'empty selects manual review' => ['', '', '', false, null, 'manual_review_required'];
        yield 'ollama loopback http is allowed' => ['http://127.0.0.1:11434/v1/', 'qwen3:8b', '', true, 'http://127.0.0.1:11434/v1/chat/completions', null];
        yield 'the full IPv4 loopback range is allowed' => ['http://127.42.0.1:11434/v1/', 'model', '', true, 'http://127.42.0.1:11434/v1/chat/completions', null];
        yield 'localhost http is allowed' => [' http://localhost:11434/v1/ ', ' model ', ' ', true, 'http://localhost:11434/v1/chat/completions', null];
        yield 'docker ollama service http is allowed' => ['http://ollama:11434/v1/', 'model', '', true, 'http://ollama:11434/v1/chat/completions', null];
        yield 'docker host gateway http is allowed for LM Studio' => ['http://host.docker.internal:1234/v1/', 'model', '', true, 'http://host.docker.internal:1234/v1/chat/completions', null];
        yield 'ipv6 loopback http is allowed' => ['http://[::1]:11434/v1/', 'model', '', true, 'http://[::1]:11434/v1/chat/completions', null];
        yield 'external https is allowed' => ['https://api.example.com/v1', 'model', 'secret', true, 'https://api.example.com/v1/chat/completions', null];
        yield 'partial configuration is rejected' => ['https://api.example.com/v1', '', '', false, null, 'llm_misconfigured'];
        yield 'model without URL is rejected' => ['', 'model', '', false, null, 'llm_misconfigured'];
        yield 'remote plain http is rejected' => ['http://api.example.com/v1', 'model', '', false, null, 'llm_misconfigured'];
    }

    #[Test]
    public function itRejectsCredentialsEmbeddedInTheProviderUrl(): void
    {
        $config = $this->config('https://user:password@api.example.com/v1', 'model', '', 10.0);

        self::assertFalse($config->isConfigured());
        self::assertNull($config->endpoint());
        self::assertSame('llm_misconfigured', $config->deferredReason());
    }

    #[Test]
    #[DataProvider('nonPositiveTimeouts')]
    public function itUsesTheDocumentedDefaultForNonPositiveTimeouts(float $timeout): void
    {
        $config = $this->config('https://api.example.com/v1', 'model', '', $timeout);

        self::assertSame(10.0, $config->timeout());
    }

    /** @return iterable<string, array{float}> */
    public static function nonPositiveTimeouts(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
    }

    private function config(string $baseUrl, string $model, string $apiKey, float $timeout): ModerationLlmConfig
    {
        return new ModerationLlmConfig($baseUrl, $model, $apiKey, $timeout);
    }
}
