<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Functional\UI\Api;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Gsoi\CommentModeration\Domain\Comment\Comment;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class SecurityAccessTest extends WebTestCase
{
    use JwtAuthenticationTrait;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    public function testCommentSubmissionRemainsPublic(): void
    {
        $client = self::createClient();
        $this->createSchema();

        $client->jsonRequest('POST', '/comments', [
            'publisher' => 'publisher-a',
            'source' => 'article-42',
            'authorId' => 'user-7',
            'body' => 'A public submission.',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
    }

    public function testRootAndHealthRemainPublic(): void
    {
        $client = self::createClient();

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();
    }

    public function testCommentReadsRequireAuthentication(): void
    {
        $client = self::createClient();
        $this->createSchema();

        $client->request('GET', '/comments');

        $this->assertError(Response::HTTP_UNAUTHORIZED, 'unauthorized', 'Authentication required.');
    }

    public function testCommentCollectionHeadRequiresAuthentication(): void
    {
        $client = self::createClient();
        $this->createSchema();

        $client->request('HEAD', '/comments');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testCommentDetailHeadRequiresAuthentication(): void
    {
        $client = self::createClient();
        $this->createSchema();
        $comment = Comment::submit('publisher-a', 'article-42', null, 'Protected comment.');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($comment);
        $entityManager->flush();

        $client->request('HEAD', '/comments/'.$comment->id()->toString());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testInvalidBearerTokenIsRejectedWithoutInternalDetails(): void
    {
        $client = self::createClient();
        $this->createSchema();

        $client->request('GET', '/comments', server: ['HTTP_AUTHORIZATION' => 'Bearer invalid']);

        $this->assertError(Response::HTTP_UNAUTHORIZED, 'unauthorized', 'Authentication required.');
    }

    public function testTokenOutsideTheAuthorizationHeaderIsRejected(): void
    {
        $client = self::createClient();
        $this->createSchema();
        $token = substr($this->bearerHeader()['HTTP_AUTHORIZATION'], strlen('Bearer '));

        $client->request('GET', '/comments?bearer='.$token);

        $this->assertError(Response::HTTP_UNAUTHORIZED, 'unauthorized', 'Authentication required.');
    }

    public function testAuthenticatedOperatorCanReadComments(): void
    {
        $client = self::createClient();
        $this->createSchema();

        $client->request('GET', '/comments', server: $this->bearerHeader(['ROLE_OPERATOR']));

        self::assertResponseIsSuccessful();
    }

    public function testOperatorWithoutModeratorRoleCannotDecide(): void
    {
        $client = self::createClient();

        $client->jsonRequest(
            'POST',
            '/comments/018f4f45-5fbf-7b3e-b33f-07d0d960c523/moderation',
            ['status' => 'published'],
            server: $this->bearerHeader(['ROLE_OPERATOR']),
        );

        $this->assertError(Response::HTTP_FORBIDDEN, 'forbidden', 'Forbidden.');
    }

    public function testModeratorIsAdmittedToTheManualDecisionEndpoint(): void
    {
        $client = self::createClient();

        $client->jsonRequest(
            'POST',
            '/comments/018f4f45-5fbf-7b3e-b33f-07d0d960c523/moderation',
            ['status' => 'unsupported'],
            server: $this->bearerHeader(),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function createSchema(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    private function assertError(int $status, string $code, string $message): void
    {
        self::assertResponseStatusCodeSame($status);
        $payload = json_decode(self::getClient()->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame($code, $payload['error']['code'] ?? null);
        self::assertSame($message, $payload['error']['message'] ?? null);
        self::assertSame([], $payload['error']['violations'] ?? null);
    }
}
