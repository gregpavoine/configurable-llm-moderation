<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Integration\Infrastructure\Persistence;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Gsoi\CommentModeration\Domain\Comment\Comment;
use Gsoi\CommentModeration\Domain\Comment\Exception\InvalidModerationTransitionException;
use Gsoi\CommentModeration\Domain\Comment\ModerationStatus;
use Gsoi\CommentModeration\Infrastructure\Persistence\Doctrine\BannedUser;
use Gsoi\CommentModeration\Infrastructure\Persistence\Doctrine\DoctrineBannedUserRepository;
use Gsoi\CommentModeration\Infrastructure\Persistence\Doctrine\DoctrineCommentRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineRepositoriesTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        (new SchemaTool($this->entityManager))->createSchema($metadata);
    }

    public function testCommentsCanBeStoredReadAndFiltered(): void
    {
        $repository = new DoctrineCommentRepository($this->entityManager);
        $first = Comment::submit('publisher-a', 'article-1', null, 'First');
        $second = Comment::submit('publisher-b', 'article-2', null, 'Second');
        $second->reject('harassment');
        $repository->save($first);
        $repository->save($second);

        self::assertSame($first, $repository->get($first->id()));
        self::assertSame([$second], $repository->search(null, null, ModerationStatus::Rejected, 10, 0));
        self::assertSame([$second], $repository->search(null, 'article-2', ModerationStatus::Rejected, 10, 0));
        self::assertSame([$first], $repository->search('publisher-a', null, null, 10, 0));
        self::assertSame(1, $repository->count('publisher-a', null, null));
    }

    public function testBannedUsersAreDetected(): void
    {
        $repository = new DoctrineBannedUserRepository($this->entityManager);
        self::assertFalse($repository->isBanned('user-1'));

        $bannedUser = new BannedUser('user-1');
        self::assertSame('user-1', $bannedUser->userId());
        self::assertNotNull($bannedUser->bannedAt());
        $this->entityManager->persist($bannedUser);
        $this->entityManager->flush();

        self::assertTrue($repository->isBanned('user-1'));
    }

    public function testStaleModerationDecisionCannotOverwriteCommittedDecision(): void
    {
        $firstRepository = new DoctrineCommentRepository($this->entityManager);
        $comment = Comment::submit('publisher-a', 'article-1', null, 'First');
        $firstRepository->save($comment);
        $id = $comment->id();
        $this->entityManager->clear();

        $secondEntityManager = new EntityManager(
            $this->entityManager->getConnection(),
            $this->entityManager->getConfiguration(),
        );
        $secondRepository = new DoctrineCommentRepository($secondEntityManager);

        $firstDecision = $firstRepository->get($id);
        $staleDecision = $secondRepository->get($id);
        self::assertNotSame($firstDecision, $staleDecision);
        self::assertSame(1, $firstDecision->version());
        self::assertSame(1, $staleDecision->version());

        $firstDecision->publish('approved by first moderator');
        $firstRepository->save($firstDecision);
        self::assertSame(2, $firstDecision->version());
        $staleDecision->reject('rejected by stale moderator');

        try {
            $secondRepository->save($staleDecision);
            self::fail('A stale moderation decision must conflict.');
        } catch (InvalidModerationTransitionException) {
            $this->entityManager->clear();
        }

        $persisted = $firstRepository->get($id);
        self::assertSame(ModerationStatus::Published, $persisted->status());
        self::assertSame('approved by first moderator', $persisted->moderationReason());
        self::assertSame(2, $persisted->version());
    }
}
