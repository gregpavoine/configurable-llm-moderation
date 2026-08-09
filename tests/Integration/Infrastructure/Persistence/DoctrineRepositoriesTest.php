<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Tests\Integration\Infrastructure\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Gsoi\Skeleton\Domain\Comment\Comment;
use Gsoi\Skeleton\Domain\Comment\ModerationStatus;
use Gsoi\Skeleton\Infrastructure\Persistence\Doctrine\BannedUser;
use Gsoi\Skeleton\Infrastructure\Persistence\Doctrine\DoctrineBannedUserRepository;
use Gsoi\Skeleton\Infrastructure\Persistence\Doctrine\DoctrineCommentRepository;
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
        self::assertSame([$second], $repository->search(null, ModerationStatus::Rejected, 10, 0));
        self::assertSame([$first], $repository->search('publisher-a', null, 10, 0));
        self::assertSame(1, $repository->count('publisher-a', null));
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
}
