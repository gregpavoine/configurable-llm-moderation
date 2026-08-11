<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Functional\UI\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OpenApiDocumentationTest extends WebTestCase
{
    public function testSwaggerUiIsPubliclyAvailable(): void
    {
        $client = self::createClient();

        $client->request('GET', '/doc/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('swagger-ui', $client->getResponse()->getContent());
    }

    public function testOpenApiJsonIsPubliclyAvailable(): void
    {
        $client = self::createClient();

        $client->request('GET', '/doc/openapi.json');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        $payload = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('openapi', $payload);
        self::assertArrayHasKey('paths', $payload);
    }
}
