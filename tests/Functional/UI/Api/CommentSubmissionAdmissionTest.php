<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Functional\UI\Api;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Gsoi\CommentModeration\Infrastructure\Framework\Symfony\CommentSubmissionAdmissionListener;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class CommentSubmissionAdmissionTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    public function testOversizedBodyIsRejectedBeforePersistenceOrDispatch(): void
    {
        $client = self::createClient();
        $this->createSchema();

        $client->request(
            'POST',
            '/comments',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: str_repeat('x', 65_537),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        self::assertSame('payload_too_large', $this->errorCode($client));
        $this->assertNothingWasPersistedOrQueued();
    }

    public function testPerClientLimitIgnoresForwardedHeadersFromAnUntrustedPeer(): void
    {
        $client = $this->clientWithLimits(clientLimit: 1, globalLimit: 10);

        $this->submit($client, '198.51.100.10', '203.0.113.1');
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);

        $this->submit($client, '198.51.100.10', '203.0.113.2');

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame('rate_limited', $this->errorCode($client));
        self::assertSame(1, $this->commentCount());
        self::assertSame(1, $this->queuedMessageCount());
    }

    public function testGlobalLimitRejectsDifferentClientsBeforePersistenceOrDispatch(): void
    {
        $client = $this->clientWithLimits(clientLimit: 10, globalLimit: 1);

        $this->submit($client, '198.51.100.11');
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);

        $this->submit($client, '198.51.100.12');

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame('rate_limited', $this->errorCode($client));
        self::assertSame(1, $this->commentCount());
        self::assertSame(1, $this->queuedMessageCount());
    }

    private function clientWithLimits(int $clientLimit, int $globalLimit): KernelBrowser
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->createSchema();
        self::getContainer()->set(CommentSubmissionAdmissionListener::class, new CommentSubmissionAdmissionListener(
            $this->limiter('test-client', $clientLimit),
            $this->limiter('test-global', $globalLimit),
        ));

        return $client;
    }

    private function limiter(string $id, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory([
            'id' => $id,
            'policy' => 'fixed_window',
            'limit' => $limit,
            'interval' => '1 hour',
        ], new InMemoryStorage());
    }

    private function submit(KernelBrowser $client, string $remoteAddress, ?string $forwardedFor = null): void
    {
        $server = ['REMOTE_ADDR' => $remoteAddress];
        if (null !== $forwardedFor) {
            $server['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }

        $client->jsonRequest('POST', '/comments', [
            'publisher' => 'publisher-a',
            'source' => 'article-42',
            'authorId' => null,
            'body' => 'A useful comment.',
        ], $server);
    }

    private function createSchema(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    private function assertNothingWasPersistedOrQueued(): void
    {
        self::assertSame(0, $this->commentCount());
        self::assertSame(0, $this->queuedMessageCount());
    }

    private function commentCount(): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM comments');
    }

    private function queuedMessageCount(): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM messenger_messages');
    }

    private function errorCode(KernelBrowser $client): ?string
    {
        $payload = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return is_array($payload) && is_array($payload['error'] ?? null)
            ? ($payload['error']['code'] ?? null)
            : null;
    }
}
