<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Functional\UI\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Gsoi\CommentModeration\Infrastructure\Persistence\Doctrine\BannedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class SubmitCommentControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    public function testACommentIsAcknowledgedAsPending(): void
    {
        $client = self::createClient();
        $this->createSchema();

        $client->jsonRequest('POST', '/comments', [
            'publisher' => 'publisher-a',
            'source' => 'article-42',
            'authorId' => 'user-7',
            'body' => 'Merci pour cet article.',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $payload = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('pending', $payload['status'] ?? null);
        self::assertIsString($payload['id'] ?? null);
    }

    public function testABannedAuthorsCommentIsAcknowledgedAsRejected(): void
    {
        $client = self::createClient();
        $this->createSchema();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new BannedUser('user-7'));
        $entityManager->flush();

        $client->jsonRequest('POST', '/comments', [
            'publisher' => 'publisher-a',
            'source' => 'article-42',
            'authorId' => 'user-7',
            'body' => 'Text.',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        self::assertStringContainsString('"status":"rejected"', $client->getResponse()->getContent());
    }

    private function createSchema(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }
}
