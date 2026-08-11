<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Unit\Application;

use Gsoi\CommentModeration\Application\Command\ModerateComment\ModerateCommentProcessor;
use Gsoi\CommentModeration\Application\Command\ModeratePendingCommentsBatch\ModeratePendingCommentsBatchCommand;
use Gsoi\CommentModeration\Application\Command\ModeratePendingCommentsBatch\ModeratePendingCommentsBatchHandler;
use Gsoi\CommentModeration\Domain\Comment\Comment;
use Gsoi\CommentModeration\Domain\Comment\CommentId;
use Gsoi\CommentModeration\Domain\Comment\CommentRepository;
use Gsoi\CommentModeration\Domain\Comment\Exception\CommentNotFoundException;
use Gsoi\CommentModeration\Domain\Comment\ModerationStatus;
use Gsoi\CommentModeration\Domain\Moderation\ModerationDecision;
use Gsoi\CommentModeration\Domain\Moderation\ModerationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validation;

final class ModeratePendingCommentsBatchHandlerTest extends TestCase
{
    #[Test]
    public function itModeratesPendingCommentsAcrossSourcesInOneBoundedBatch(): void
    {
        $first = Comment::submit('publisher-a', 'article-1', null, 'First abusive comment.');
        $second = Comment::submit('publisher-a', 'article-2', null, 'Second abusive comment.');
        $alreadyPublished = Comment::submit('publisher-a', 'article-3', null, 'Already handled.');
        $alreadyPublished->publish('previously_allowed');
        $repository = new BatchCommentRepository([$first, $second, $alreadyPublished]);

        $result = $this->handler(
            $repository,
            new BatchFixedModerationService(ModerationDecision::reject('policy_violation')),
        )(new ModeratePendingCommentsBatchCommand(10));

        self::assertSame(2, $result->processed);
        self::assertSame(10, $result->limit);
        self::assertCount(2, $result->items);
        self::assertSame(2, $repository->saveCount);
        self::assertSame(10, $repository->lastPendingLimit);
        self::assertSame(ModerationStatus::Rejected, $first->status());
        self::assertSame(ModerationStatus::Rejected, $second->status());
        self::assertSame(ModerationStatus::Published, $alreadyPublished->status());
    }

    #[Test]
    public function itRejectsAnInvalidBatchLimitBeforeRepositoryAccess(): void
    {
        $repository = new BatchCommentRepository([]);

        $this->expectException(ValidationFailedException::class);

        $this->handler(
            $repository,
            new BatchFixedModerationService(ModerationDecision::publish('allowed')),
        )(new ModeratePendingCommentsBatchCommand(101));
    }

    private function handler(CommentRepository $repository, ModerationService $service): ModeratePendingCommentsBatchHandler
    {
        return new ModeratePendingCommentsBatchHandler(
            $repository,
            new ModerateCommentProcessor($repository, $service),
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );
    }
}

final class BatchCommentRepository implements CommentRepository
{
    public int $saveCount = 0;
    public ?int $lastPendingLimit = null;

    /** @param list<Comment> $comments */
    public function __construct(private array $comments)
    {
    }

    public function save(Comment $comment): void
    {
        ++$this->saveCount;
        foreach ($this->comments as $index => $stored) {
            if ($stored->id()->toString() === $comment->id()->toString()) {
                $this->comments[$index] = $comment;

                return;
            }
        }

        $this->comments[] = $comment;
    }

    public function get(CommentId $id): Comment
    {
        foreach ($this->comments as $comment) {
            if ($comment->id()->toString() === $id->toString()) {
                return $comment;
            }
        }

        throw CommentNotFoundException::withId($id);
    }

    public function search(?string $publisher, ?ModerationStatus $status, int $limit, int $offset): array
    {
        return [];
    }

    public function count(?string $publisher, ?ModerationStatus $status): int
    {
        return 0;
    }

    public function pendingForModeration(int $limit): array
    {
        $this->lastPendingLimit = $limit;

        return array_slice(
            array_values(array_filter(
                $this->comments,
                static fn (Comment $comment): bool => $comment->isPending(),
            )),
            0,
            $limit,
        );
    }
}

final readonly class BatchFixedModerationService implements ModerationService
{
    public function __construct(private ModerationDecision $decision)
    {
    }

    public function moderate(string $content): ModerationDecision
    {
        return $this->decision;
    }
}
