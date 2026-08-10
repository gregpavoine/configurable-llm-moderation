<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Unit\Domain\Comment;

use DateTimeImmutable;
use Gsoi\CommentModeration\Domain\Comment\Comment;
use Gsoi\CommentModeration\Domain\Comment\Exception\InvalidModerationTransitionException;
use Gsoi\CommentModeration\Domain\Comment\ModerationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommentTest extends TestCase
{
    #[Test]
    public function aSubmittedCommentStartsPending(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-09T10:00:00+00:00');

        $comment = Comment::submit('publisher-a', 'article-42', 'user-7', 'A useful comment.', $createdAt);

        self::assertSame(ModerationStatus::Pending, $comment->status());
        self::assertSame('publisher-a', $comment->publisher());
        self::assertSame('article-42', $comment->source());
        self::assertSame('user-7', $comment->authorId());
        self::assertSame('A useful comment.', $comment->body());
        self::assertSame($createdAt, $comment->createdAt());
        self::assertNull($comment->moderationReason());
        self::assertNull($comment->moderatedAt());
    }

    #[Test]
    public function aPendingCommentCanBePublished(): void
    {
        $moderatedAt = new DateTimeImmutable('2026-08-09T10:01:00+00:00');
        $comment = Comment::submit('publisher-a', 'article-42', null, 'Thank you.');

        $comment->publish('allowed', $moderatedAt);

        self::assertSame(ModerationStatus::Published, $comment->status());
        self::assertSame('allowed', $comment->moderationReason());
        self::assertSame($moderatedAt, $comment->moderatedAt());
    }

    #[Test]
    public function aPendingCommentCanBeRejected(): void
    {
        $comment = Comment::submit('publisher-a', 'article-42', null, 'Threatening content.');

        $comment->reject('threat', new DateTimeImmutable('2026-08-09T10:01:00+00:00'));

        self::assertSame(ModerationStatus::Rejected, $comment->status());
        self::assertSame('threat', $comment->moderationReason());
    }

    #[Test]
    public function aPendingCommentCanBeDeferredToManualReview(): void
    {
        $comment = Comment::submit('publisher', 'article', 'author', 'Body.');

        $comment->defer('manual_review_required');

        self::assertSame(ModerationStatus::Pending, $comment->status());
        self::assertSame('manual_review_required', $comment->moderationReason());
        self::assertNull($comment->moderatedAt());
    }

    #[Test]
    public function aFinalDecisionCannotBeChanged(): void
    {
        $comment = Comment::submit('publisher-a', 'article-42', null, 'Text.');
        $comment->publish('allowed');

        $this->expectException(InvalidModerationTransitionException::class);

        $comment->reject('changed-mind');
    }

    #[Test]
    public function aFinalCommentCannotBeDeferred(): void
    {
        $comment = Comment::submit('publisher', 'article', 'author', 'Body.');
        $comment->publish('allowed');

        $this->expectException(InvalidModerationTransitionException::class);

        $comment->defer('manual_review_required');
    }
}
