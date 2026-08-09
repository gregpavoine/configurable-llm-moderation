<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Functional\UI\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Gsoi\CommentModeration\Domain\Comment\Comment;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CommentReadControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    public function testACommentCanBeRetrievedByIdentifier(): void
    {
        $client = self::createClient();
        $this->createSchema();
        $comment = Comment::submit('publisher-a', 'article-42', 'user-7', 'A useful comment.');
        $this->persist($comment);

        $client->request('GET', '/comments/'.$comment->id()->toString());

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame($comment->id()->toString(), $payload['id'] ?? null);
        self::assertSame('publisher-a', $payload['publisher'] ?? null);
        self::assertSame('pending', $payload['status'] ?? null);
    }

    public function testCommentsCanBeFilteredByPublisherAndStatus(): void
    {
        $client = self::createClient();
        $this->createSchema();
        $published = Comment::submit('publisher-a', 'article-1', null, 'First');
        $published->publish('allowed');
        $rejected = Comment::submit('publisher-a', 'article-2', null, 'Second');
        $rejected->reject('harassment');
        $other = Comment::submit('publisher-b', 'article-3', null, 'Third');
        $this->persist($published, $rejected, $other);

        $client->request('GET', '/comments?publisher=publisher-a&status=rejected&limit=10&offset=0');

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(1, $payload['total'] ?? null);
        self::assertCount(1, $payload['items'] ?? []);
        self::assertSame($rejected->id()->toString(), $payload['items'][0]['id'] ?? null);
    }

    public function testSearchPaginationIsReturnedInTheEnvelope(): void
    {
        $client = self::createClient();
        $this->createSchema();
        $this->persist(
            Comment::submit('publisher-a', 'article-1', null, 'First'),
            Comment::submit('publisher-a', 'article-2', null, 'Second'),
        );

        $client->request('GET', '/comments?limit=1&offset=1');

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(2, $payload['total'] ?? null);
        self::assertSame(1, $payload['limit'] ?? null);
        self::assertSame(1, $payload['offset'] ?? null);
        self::assertCount(1, $payload['items'] ?? []);
    }

    private function createSchema(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    private function persist(Comment ...$comments): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach ($comments as $comment) {
            $entityManager->persist($comment);
        }
        $entityManager->flush();
    }
}
