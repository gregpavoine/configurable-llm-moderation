<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Container;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class KubernetesManifestTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $documents;

    protected function setUp(): void
    {
        $manifestPath = dirname(__DIR__, 2).'/k8s/comment-moderation.yaml';
        self::assertFileExists($manifestPath);

        $parsed = Yaml::parseFile($manifestPath, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        self::assertIsArray($parsed);
        self::assertIsArray($parsed['items'] ?? null);
        $this->documents = $parsed['items'];
    }

    public function testKubernetesStackDefinesExpectedWorkloadsAndNetwork(): void
    {
        self::assertSame('Namespace', $this->resource('Namespace', 'comment-moderation')['kind']);
        self::assertSame('ClusterIP', $this->resource('Service', 'comment-moderation-web')['spec']['type']);
        self::assertSame(80, $this->resource('Service', 'comment-moderation-web')['spec']['ports'][0]['port']);

        $php = $this->resource('Deployment', 'comment-moderation-php');
        $web = $this->resource('Deployment', 'comment-moderation-web');
        $worker = $this->resource('Deployment', 'comment-moderation-worker');
        $init = $this->resource('Job', 'comment-moderation-init');

        self::assertSame('comment-moderation-app:local', $this->container($php, 'php')['image']);
        self::assertSame('comment-moderation-web:local', $this->container($web, 'web')['image']);
        self::assertSame(['php', 'bin/console', 'messenger:consume', 'async', '--time-limit=3600', '--memory-limit=256M', '-vv'], $this->container($worker, 'worker')['command']);
        self::assertSame(['/usr/local/bin/app-init'], $this->container($init, 'init')['command']);

        self::assertSame('/health', $this->container($web, 'web')['readinessProbe']['httpGet']['path']);
        self::assertSame(9000, $this->container($php, 'php')['readinessProbe']['tcpSocket']['port']);
        self::assertSame('ReadWriteOnce', $this->resource('PersistentVolumeClaim', 'comment-moderation-data')['spec']['accessModes'][0]);
        self::assertSame('ReadWriteOnce', $this->resource('PersistentVolumeClaim', 'comment-moderation-jwt')['spec']['accessModes'][0]);
    }

    public function testKubernetesStackExternalizesConfigurationAndSecrets(): void
    {
        $configMap = $this->resource('ConfigMap', 'comment-moderation-config');
        self::assertSame('prod', $configMap['data']['APP_ENV']);
        self::assertSame('0', $configMap['data']['APP_DEBUG']);
        self::assertSame('10', $configMap['data']['MODERATION_LLM_TIMEOUT']);
        self::assertArrayHasKey('FACEBOOK_WEBHOOK_VERIFY_TOKEN', $configMap['data']);

        $secret = $this->resource('Secret', 'comment-moderation-secrets');
        self::assertSame('Opaque', $secret['type']);
        foreach (['APP_SECRET', 'JWT_PASSPHRASE', 'MODERATION_LLM_API_KEY', 'FACEBOOK_APP_SECRET'] as $key) {
            self::assertArrayHasKey($key, $secret['stringData']);
            self::assertStringStartsWith('replace-', $secret['stringData'][$key]);
        }

        $php = $this->container($this->resource('Deployment', 'comment-moderation-php'), 'php');
        self::assertContains(['configMapRef' => ['name' => 'comment-moderation-config']], $php['envFrom']);
        self::assertContains(['secretRef' => ['name' => 'comment-moderation-secrets']], $php['envFrom']);
    }

    /** @return array<string, mixed> */
    private function resource(string $kind, string $name): array
    {
        foreach ($this->documents as $document) {
            if (($document['kind'] ?? null) === $kind && ($document['metadata']['name'] ?? null) === $name) {
                return $document;
            }
        }

        self::fail(sprintf('Missing Kubernetes resource %s/%s.', $kind, $name));
    }

    /**
     * @param array<string, mixed> $workload
     *
     * @return array<string, mixed>
     */
    private function container(array $workload, string $name): array
    {
        $containers = $workload['spec']['template']['spec']['containers'] ?? null;
        self::assertIsArray($containers);

        foreach ($containers as $container) {
            if (is_array($container) && ($container['name'] ?? null) === $name) {
                return $container;
            }
        }

        self::fail(sprintf('Missing container %s.', $name));
    }
}
