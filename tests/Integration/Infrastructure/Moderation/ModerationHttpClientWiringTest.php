<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Integration\Infrastructure\Moderation;

use Gsoi\CommentModeration\Infrastructure\Moderation\OpenAiCompatibleModerationService;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\AmpHttpClient;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ModerationHttpClientWiringTest extends KernelTestCase
{
    #[Test]
    public function moderationUsesADedicatedUndecoratedHttpTransport(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $moderationClient = $container->get('moderation.http_client');
        $defaultClient = $container->get(HttpClientInterface::class);
        $adapter = $container->get(OpenAiCompatibleModerationService::class);

        self::assertContains($moderationClient::class, [
            AmpHttpClient::class,
            CurlHttpClient::class,
            NativeHttpClient::class,
        ]);
        self::assertNotSame($defaultClient, $moderationClient);

        $clientProperty = new \ReflectionProperty($adapter, 'httpClient');
        self::assertSame($moderationClient, $clientProperty->getValue($adapter));
    }
}
