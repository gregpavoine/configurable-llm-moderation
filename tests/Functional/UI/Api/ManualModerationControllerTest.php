<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Functional\UI\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Gsoi\CommentModeration\Domain\Comment\Comment;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class ManualModerationControllerTest extends WebTestCase
{
    use JwtAuthenticationTrait;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    public function testModeratorCanPublishAComment(): void
    {
        [$client, $comment] = $this->pendingComment();

        $this->moderate($client, $comment, ['status' => 'published', 'reason' => 'manual_approval']);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame([
            'id' => $comment->id()->toString(),
            'publisher' => 'publisher-a',
            'source' => 'article-42',
            'authorId' => 'user-7',
            'body' => 'A useful comment.',
            'status' => 'published',
            'moderationReason' => 'manual_approval',
            'createdAt' => $comment->createdAt()->format(DATE_ATOM),
        ], array_diff_key($payload, ['moderatedAt' => true]));
        self::assertIsString($payload['moderatedAt'] ?? null);
    }

    public function testModeratorCanRejectAComment(): void
    {
        [$client, $comment] = $this->pendingComment();

        $this->moderate($client, $comment, ['status' => 'rejected', 'reason' => 'manual_rejection']);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame('rejected', $payload['status'] ?? null);
        self::assertSame('manual_rejection', $payload['moderationReason'] ?? null);
        self::assertIsString($payload['moderatedAt'] ?? null);
    }

    public function testPublishUsesTheOperatorDefaultReason(): void
    {
        [$client, $comment] = $this->pendingComment();

        $this->moderate($client, $comment, ['status' => 'published']);

        self::assertResponseIsSuccessful();
        self::assertSame('approved_by_operator', $this->payload($client)['moderationReason'] ?? null);
    }

    public function testRejectionUsesTheOperatorDefaultReason(): void
    {
        [$client, $comment] = $this->pendingComment();

        $this->moderate($client, $comment, ['status' => 'rejected']);

        self::assertResponseIsSuccessful();
        self::assertSame('rejected_by_operator', $this->payload($client)['moderationReason'] ?? null);
    }

    public function testInvalidStatusIsRejected(): void
    {
        [$client, $comment] = $this->pendingComment();

        $this->moderate($client, $comment, ['status' => 'unsupported']);

        $this->assertError($client, Response::HTTP_UNPROCESSABLE_ENTITY, 'validation_failed');
    }

    public function testProvidedBlankReasonIsRejected(): void
    {
        [$client, $comment] = $this->pendingComment();

        $this->moderate($client, $comment, ['status' => 'published', 'reason' => '   ']);

        $this->assertError($client, Response::HTTP_UNPROCESSABLE_ENTITY, 'validation_failed');
    }

    public function testMissingCommentReturnsNotFound(): void
    {
        $client = self::createClient();
        $this->createSchema();

        $client->jsonRequest(
            'POST',
            '/comments/'.Uuid::v7()->toRfc4122().'/moderation',
            ['status' => 'published'],
            server: $this->bearerHeader(),
        );

        $this->assertError($client, Response::HTTP_NOT_FOUND, 'comment_not_found');
    }

    public function testSecondDecisionReturnsConflict(): void
    {
        [$client, $comment] = $this->pendingComment();
        $client->disableReboot();
        $this->moderate($client, $comment, ['status' => 'published']);
        self::assertResponseIsSuccessful();

        $this->moderate($client, $comment, ['status' => 'rejected']);

        $this->assertError($client, Response::HTTP_CONFLICT, 'moderation_conflict');
    }

    /** @return array{KernelBrowser, Comment} */
    private function pendingComment(): array
    {
        $client = self::createClient();
        $this->createSchema();
        $comment = Comment::submit('publisher-a', 'article-42', 'user-7', 'A useful comment.');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($comment);
        $entityManager->flush();

        return [$client, $comment];
    }

    private function moderate(KernelBrowser $client, Comment $comment, array $payload): void
    {
        $client->jsonRequest(
            'POST',
            '/comments/'.$comment->id()->toString().'/moderation',
            $payload,
            server: $this->bearerHeader(),
        );
    }

    private function createSchema(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    /** @return array<string, mixed> */
    private function payload(KernelBrowser $client): array
    {
        $payload = json_decode($client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    private function assertError(KernelBrowser $client, int $status, string $code): void
    {
        self::assertResponseStatusCodeSame($status);
        $payload = $this->payload($client);
        self::assertSame($code, $payload['error']['code'] ?? null);
    }
}
