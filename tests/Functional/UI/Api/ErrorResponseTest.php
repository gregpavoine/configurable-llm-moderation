<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Tests\Functional\UI\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class ErrorResponseTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    public function testInvalidCommentInputUsesTheValidationErrorShape(): void
    {
        $client = self::createClient();
        $this->createSchema();

        $client->jsonRequest('POST', '/comments', [
            'publisher' => '',
            'source' => '',
            'authorId' => null,
            'body' => '',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = $this->payload($client->getResponse()->getContent());
        self::assertSame('validation_failed', $payload['error']['code'] ?? null);
        self::assertNotEmpty($payload['error']['violations'] ?? []);
    }

    public function testUnknownCommentUsesTheNotFoundErrorShape(): void
    {
        $client = self::createClient();
        $this->createSchema();

        $client->request('GET', '/comments/'.Uuid::v7()->toRfc4122());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $payload = $this->payload($client->getResponse()->getContent());
        self::assertSame('comment_not_found', $payload['error']['code'] ?? null);
    }

    public function testMalformedJsonUsesTheBadRequestErrorShape(): void
    {
        $client = self::createClient();
        $client->request('POST', '/comments', server: ['CONTENT_TYPE' => 'application/json'], content: '{');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $payload = $this->payload($client->getResponse()->getContent());
        self::assertSame('bad_request', $payload['error']['code'] ?? null);
    }

    public function testInvalidSearchFilterUsesTheValidationErrorShape(): void
    {
        $client = self::createClient();
        $this->createSchema();

        $client->request('GET', '/comments?status=unknown');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = $this->payload($client->getResponse()->getContent());
        self::assertSame('validation_failed', $payload['error']['code'] ?? null);
    }

    private function createSchema(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    /** @return array<string, mixed> */
    private function payload(string $content): array
    {
        $payload = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
