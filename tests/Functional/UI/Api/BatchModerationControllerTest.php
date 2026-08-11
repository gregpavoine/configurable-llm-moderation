<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Functional\UI\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Gsoi\CommentModeration\Domain\Comment\Comment;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class BatchModerationControllerTest extends WebTestCase
{
    use JwtAuthenticationTrait;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    public function testModeratorCanModerateABoundedBatchAcrossArticles(): void
    {
        $client = self::createClient();
        $this->createSchema();
        $this->persistPendingComments('article-a', 'article-b', 'article-c');

        $client->jsonRequest(
            'POST',
            '/comments/moderation/batch',
            ['limit' => 2],
            server: $this->bearerHeader(),
        );

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame(2, $payload['processed'] ?? null);
        self::assertSame(2, $payload['limit'] ?? null);
        self::assertCount(2, $payload['items'] ?? []);
        self::assertSame('article-a', $payload['items'][0]['source'] ?? null);
        self::assertSame('article-b', $payload['items'][1]['source'] ?? null);
        self::assertSame('manual_review_required', $payload['items'][0]['moderationReason'] ?? null);
    }

    public function testAnonymousBatchModerationIsRejected(): void
    {
        $client = self::createClient();
        $this->createSchema();

        $client->jsonRequest('POST', '/comments/moderation/batch', ['limit' => 1]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testBatchLimitIsValidated(): void
    {
        $client = self::createClient();
        $this->createSchema();

        $client->jsonRequest(
            'POST',
            '/comments/moderation/batch',
            ['limit' => 101],
            server: $this->bearerHeader(),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function createSchema(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    private function persistPendingComments(string ...$sources): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($sources as $source) {
            $entityManager->persist(Comment::submit('publisher-a', $source, null, 'Commentaire a reviser.'));
        }

        $entityManager->flush();
    }

    /** @return array<string, mixed> */
    private function payload(object $client): array
    {
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
