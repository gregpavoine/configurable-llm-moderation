<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Unit\Application;

use DateTimeImmutable;
use Gsoi\CommentModeration\Application\Query\CommentView;
use Gsoi\CommentModeration\Domain\Comment\Comment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommentViewTest extends TestCase
{
    #[Test]
    public function itMapsAPendingCommentToThePublicArrayContract(): void
    {
        $createdAt = new DateTimeImmutable('2026-08-09T10:00:00+00:00');
        $comment = Comment::submit('publisher-a', 'article-42', null, 'Body.', $createdAt);

        $view = CommentView::fromComment($comment);

        self::assertSame([
            'id' => $comment->id()->toString(),
            'publisher' => 'publisher-a',
            'source' => 'article-42',
            'authorId' => null,
            'body' => 'Body.',
            'status' => 'pending',
            'moderationReason' => null,
            'createdAt' => '2026-08-09T10:00:00+00:00',
            'moderatedAt' => null,
        ], $view->toArray());
    }

    #[Test]
    public function itMapsACompletedModerationDecision(): void
    {
        $comment = Comment::submit(
            'publisher-a',
            'article-42',
            'user-7',
            'Body.',
            new DateTimeImmutable('2026-08-09T10:00:00+00:00'),
        );
        $comment->publish('acceptable', new DateTimeImmutable('2026-08-09T10:01:00+00:00'));

        $view = CommentView::fromComment($comment);

        self::assertSame('published', $view->status);
        self::assertSame('acceptable', $view->moderationReason);
        self::assertSame('2026-08-09T10:01:00+00:00', $view->moderatedAt);
        self::assertSame('user-7', $view->authorId);
    }
}
