<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Unit\Infrastructure\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Gsoi\CommentModeration\Domain\Comment\Comment;
use Gsoi\CommentModeration\Domain\Comment\Exception\InvalidModerationTransitionException;
use Gsoi\CommentModeration\Infrastructure\Persistence\Doctrine\DoctrineCommentRepository;
use PHPUnit\Framework\TestCase;

final class DoctrineCommentRepositoryTest extends TestCase
{
    public function testItMapsAnOptimisticConflictForTheSavedComment(): void
    {
        $comment = Comment::submit('publisher-a', 'article-1', null, 'First');
        $failure = OptimisticLockException::lockFailed($comment);
        $repository = $this->repositoryThrowing($failure);

        try {
            $repository->save($comment);
            self::fail('The optimistic conflict must be translated.');
        } catch (InvalidModerationTransitionException $exception) {
            self::assertSame($failure, $exception->getPrevious());
        }
    }

    public function testItRethrowsAnEntityLessOptimisticFailureUnchanged(): void
    {
        $comment = Comment::submit('publisher-a', 'article-1', null, 'First');
        $failure = new OptimisticLockException('Commit failed', null, new \RuntimeException('database failure'));
        $repository = $this->repositoryThrowing($failure);

        try {
            $repository->save($comment);
            self::fail('The infrastructure failure must escape unchanged.');
        } catch (OptimisticLockException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    public function testItRethrowsAnUnrelatedOptimisticConflictUnchanged(): void
    {
        $comment = Comment::submit('publisher-a', 'article-1', null, 'First');
        $unrelatedComment = Comment::submit('publisher-b', 'article-2', null, 'Second');
        $failure = OptimisticLockException::lockFailed($unrelatedComment);
        $repository = $this->repositoryThrowing($failure);

        try {
            $repository->save($comment);
            self::fail('An unrelated optimistic conflict must escape unchanged.');
        } catch (OptimisticLockException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    private function repositoryThrowing(OptimisticLockException $failure): DoctrineCommentRepository
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willThrowException($failure);

        return new DoctrineCommentRepository($entityManager);
    }
}
