<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Container;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ComposeContractTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $model;

    protected function setUp(): void
    {
        $projectDirectory = dirname(__DIR__, 2);
        $process = new Process([
            'docker', 'compose',
            '--profile', 'tools',
            '--env-file', '.env.docker.example',
            'config', '--format', 'json',
        ], $projectDirectory, [
            'APP_SECRET' => false,
            'JWT_PASSPHRASE' => false,
            'MODERATION_LLM_BASE_URL' => false,
            'MODERATION_LLM_MODEL' => 'gpt-oss-safeguard:20b',
            'MODERATION_LLM_API_KEY' => false,
            'FACEBOOK_APP_SECRET' => false,
            'FACEBOOK_WEBHOOK_VERIFY_TOKEN' => false,
        ]);
        $process->setTimeout(30);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());

        $model = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($model);
        $this->model = $model;
    }

    public function testCompleteStackHasSafeRuntimeBoundaries(): void
    {
        $services = $this->services();
        self::assertSame(['init', 'ollama', 'ollama-init', 'php', 'token', 'web', 'worker'], array_keys($services));
        self::assertSame(['app-data', 'jwt-data', 'ollama-data'], array_keys($this->model['volumes']));

        self::assertSame('127.0.0.1', $services['web']['ports'][0]['host_ip']);
        self::assertSame('8000', $services['web']['ports'][0]['published']);
        self::assertSame('127.0.0.1', $services['ollama']['ports'][0]['host_ip']);
        self::assertSame('11435', $services['ollama']['ports'][0]['published']);

        self::assertArrayNotHasKey('network_mode', $services['worker']);
        self::assertSame('http://ollama:11434/v1', $services['worker']['environment']['MODERATION_LLM_BASE_URL']);
        self::assertSame('gpt-oss-safeguard:20b', $services['worker']['environment']['MODERATION_LLM_MODEL']);
        self::assertSame('', $services['worker']['environment']['MODERATION_LLM_API_KEY']);
        self::assertSame('http://ollama:11434/v1', $services['php']['environment']['MODERATION_LLM_BASE_URL']);
        self::assertSame('gpt-oss-safeguard:20b', $services['php']['environment']['MODERATION_LLM_MODEL']);
        self::assertSame('', $services['worker']['environment']['JWT_PASSPHRASE']);
        self::assertSame('replace-with-meta-app-secret', $services['php']['environment']['FACEBOOK_APP_SECRET']);
        self::assertSame('replace-with-meta-verify-token', $services['php']['environment']['FACEBOOK_WEBHOOK_VERIFY_TOKEN']);

        self::assertSame('service_completed_successfully', $services['php']['depends_on']['init']['condition']);
        self::assertSame('service_completed_successfully', $services['worker']['depends_on']['init']['condition']);
        self::assertSame('service_completed_successfully', $services['worker']['depends_on']['ollama-init']['condition']);
        self::assertStringContainsString('messenger:consume', implode(' ', $services['worker']['command']));
        self::assertSame(['pull', 'gpt-oss-safeguard:20b'], $services['ollama-init']['command']);

        self::assertSame(['tools'], $services['token']['profiles']);
        self::assertSame(['php', 'bin/console', 'app:jwt:issue-moderator'], $services['token']['entrypoint']);
        self::assertSame(['--subject=docker-operator'], $services['token']['command']);
        self::assertSame('dev', $services['token']['environment']['APP_ENV']);

        foreach (['ollama', 'php', 'web'] as $service) {
            self::assertArrayHasKey('healthcheck', $services[$service]);
        }
    }

    public function testWorkerAcceptsAnExternalOpenAiCompatibleProvider(): void
    {
        $process = new Process([
            'docker', 'compose',
            '--profile', 'tools',
            '--env-file', '.env.docker.example',
            'config', '--format', 'json',
        ], dirname(__DIR__, 2), [
            'APP_SECRET' => false,
            'JWT_PASSPHRASE' => false,
            'MODERATION_LLM_BASE_URL' => 'https://api.example.test/v1',
            'MODERATION_LLM_MODEL' => 'moderator-model',
            'MODERATION_LLM_API_KEY' => 'external-secret',
            'FACEBOOK_APP_SECRET' => false,
            'FACEBOOK_WEBHOOK_VERIFY_TOKEN' => false,
        ]);
        $process->setTimeout(30);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $model = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('https://api.example.test/v1', $model['services']['worker']['environment']['MODERATION_LLM_BASE_URL']);
        self::assertSame('moderator-model', $model['services']['worker']['environment']['MODERATION_LLM_MODEL']);
        self::assertSame('external-secret', $model['services']['worker']['environment']['MODERATION_LLM_API_KEY']);
    }

    public function testWorkerCanDisableTheProviderForManualReviewFallback(): void
    {
        $process = new Process([
            'docker', 'compose',
            '--profile', 'tools',
            '--env-file', '.env.docker.example',
            'config', '--format', 'json',
        ], dirname(__DIR__, 2), [
            'APP_SECRET' => false,
            'JWT_PASSPHRASE' => false,
            'MODERATION_LLM_BASE_URL' => '',
            'MODERATION_LLM_MODEL' => '',
            'MODERATION_LLM_API_KEY' => '',
            'FACEBOOK_APP_SECRET' => false,
            'FACEBOOK_WEBHOOK_VERIFY_TOKEN' => false,
        ]);
        $process->setTimeout(30);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $model = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('', $model['services']['worker']['environment']['MODERATION_LLM_BASE_URL']);
        self::assertSame('', $model['services']['worker']['environment']['MODERATION_LLM_MODEL']);
        self::assertSame('', $model['services']['worker']['environment']['MODERATION_LLM_API_KEY']);
    }

    /** @return array<string, array<string, mixed>> */
    private function services(): array
    {
        $services = $this->model['services'] ?? null;
        self::assertIsArray($services);
        ksort($services);

        return $services;
    }
}
