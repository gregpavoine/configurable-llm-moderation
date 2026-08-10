<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Functional\UI\Api;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Gsoi\CommentModeration\Application\Command\ModerateComment\ModerateCommentCommand;
use Gsoi\CommentModeration\Application\Command\ModerateComment\ModerateCommentHandler;
use Gsoi\CommentModeration\Infrastructure\Persistence\Doctrine\BannedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class SubmitCommentControllerTest extends WebTestCase
{
    use JwtAuthenticationTrait;

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
        self::assertSame(1, $this->queuedMessageCount());
    }

    public function testEmptyLlmConfigurationDefersASubmittedCommentForManualReview(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->createSchema();

        $client->jsonRequest('POST', '/comments', [
            'publisher' => 'publisher-a',
            'source' => 'article-42',
            'authorId' => 'user-7',
            'body' => 'Merci pour cet article.',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $submitted = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($submitted);
        self::assertIsString($submitted['id'] ?? null);

        $handler = self::getContainer()->get(ModerateCommentHandler::class);
        self::assertInstanceOf(ModerateCommentHandler::class, $handler);
        $handler(new ModerateCommentCommand($submitted['id']));

        $client->request('GET', '/comments/'.$submitted['id'], server: $this->bearerHeader());

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame([
            'status' => 'pending',
            'moderationReason' => 'manual_review_required',
            'moderatedAt' => null,
        ], array_intersect_key($payload, [
            'status' => true,
            'moderationReason' => true,
            'moderatedAt' => true,
        ]));
    }

    public function testABannedAuthorsCommentIsAcknowledgedAsRejected(): void
    {
        $client = self::createClient();
        $client->disableReboot();
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
        $submitted = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($submitted);
        self::assertSame('rejected', $submitted['status'] ?? null);
        self::assertIsString($submitted['id'] ?? null);
        self::assertSame(0, $this->queuedMessageCount());

        $client->request('GET', '/comments/'.$submitted['id'], server: $this->bearerHeader());

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('rejected', $payload['status'] ?? null);
        self::assertSame('author_banned', $payload['moderationReason'] ?? null);
    }

    private function createSchema(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    private function queuedMessageCount(): int
    {
        $connection = self::getContainer()->get(Connection::class);

        return (int) $connection->fetchOne('SELECT COUNT(*) FROM messenger_messages');
    }
}
