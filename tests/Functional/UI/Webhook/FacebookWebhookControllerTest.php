<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Functional\UI\Webhook;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Gsoi\CommentModeration\Domain\Comment\ModerationStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class FacebookWebhookControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    public function testFacebookVerificationChallengeReturnsChallengeWhenTokenMatches(): void
    {
        $client = self::createClient();

        $client->request('GET', '/webhooks/facebook/comments?hub.mode=subscribe&hub.verify_token=facebook-test-token&hub.challenge=challenge-123');

        self::assertResponseIsSuccessful();
        self::assertSame('challenge-123', $client->getResponse()->getContent());
    }

    public function testFacebookVerificationChallengeRejectsInvalidToken(): void
    {
        $client = self::createClient();

        $client->request('GET', '/webhooks/facebook/comments?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=challenge-123');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testFacebookCallbackRejectsInvalidSignature(): void
    {
        $client = self::createClient();
        $this->createSchema();

        $client->request(
            'POST',
            '/webhooks/facebook/comments',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid',
            ],
            content: $this->facebookPayload(),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame(0, $this->commentCount());
    }

    public function testFacebookCallbackRejectsOversizedPayloads(): void
    {
        $client = self::createClient();
        $this->createSchema();
        $payload = json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => 'page-42',
                'changes' => [[
                    'field' => 'feed',
                    'value' => [
                        'item' => 'comment',
                        'post_id' => 'post-99',
                        'message' => str_repeat('x', 66_000),
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $client->request(
            'POST',
            '/webhooks/facebook/comments',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $this->signature($payload),
            ],
            content: $payload,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        self::assertSame(0, $this->commentCount());
    }

    public function testFacebookCallbackSubmitsCommentsForModeration(): void
    {
        $client = self::createClient();
        $this->createSchema();
        $payload = $this->facebookPayload();

        $client->request(
            'POST',
            '/webhooks/facebook/comments',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $this->signature($payload),
            ],
            content: $payload,
        );

        self::assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($response);
        self::assertSame(['received' => 1, 'ignored' => 0], $response);

        $connection = self::getContainer()->get(Connection::class);
        $comment = $connection->fetchAssociative('SELECT publisher, source_id, author_id, body, status FROM comments');
        self::assertIsArray($comment);
        self::assertSame('facebook_page:page-42', $comment['publisher']);
        self::assertSame('facebook_post:post-99', $comment['source_id']);
        self::assertSame('facebook_user:user-7', $comment['author_id']);
        self::assertSame('Merci pour cet article.', $comment['body']);
        self::assertSame(ModerationStatus::Pending->value, $comment['status']);
        self::assertSame(1, $this->queuedMessageCount());
    }

    public function testFacebookCallbackIgnoresEntriesWithoutCommentText(): void
    {
        $client = self::createClient();
        $this->createSchema();
        $payload = json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => 'page-42',
                'changes' => [[
                    'field' => 'feed',
                    'value' => [
                        'item' => 'comment',
                        'post_id' => 'post-99',
                        'comment_id' => 'comment-12',
                        'from' => ['id' => 'user-7'],
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $client->request(
            'POST',
            '/webhooks/facebook/comments',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $this->signature($payload),
            ],
            content: $payload,
        );

        self::assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($response);
        self::assertSame(['received' => 0, 'ignored' => 1], $response);
        self::assertSame(0, $this->commentCount());
    }

    private function createSchema(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    private function facebookPayload(): string
    {
        return json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => 'page-42',
                'changes' => [[
                    'field' => 'feed',
                    'value' => [
                        'item' => 'comment',
                        'post_id' => 'post-99',
                        'comment_id' => 'comment-12',
                        'message' => 'Merci pour cet article.',
                        'from' => ['id' => 'user-7'],
                    ],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function signature(string $payload): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, 'facebook-app-secret');
    }

    private function commentCount(): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM comments');
    }

    private function queuedMessageCount(): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM messenger_messages');
    }
}
