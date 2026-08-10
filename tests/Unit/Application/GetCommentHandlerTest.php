<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Unit\Application;

use Gsoi\CommentModeration\Application\Query\GetComment\GetCommentHandler;
use Gsoi\CommentModeration\Application\Query\GetComment\GetCommentQuery;
use Gsoi\CommentModeration\Domain\Comment\Comment;
use Gsoi\CommentModeration\Domain\Comment\CommentId;
use Gsoi\CommentModeration\Domain\Comment\CommentRepository;
use Gsoi\CommentModeration\Domain\Comment\ModerationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validation;

final class GetCommentHandlerTest extends TestCase
{
    #[Test]
    public function itReturnsTheRequestedCommentView(): void
    {
        $comment = Comment::submit('publisher-a', 'article-42', 'user-7', 'Useful feedback.');
        $handler = new GetCommentHandler(
            new FixedGetCommentRepository($comment),
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );

        $view = $handler(new GetCommentQuery($comment->id()->toString()));

        self::assertSame($comment->id()->toString(), $view->id);
        self::assertSame('publisher-a', $view->publisher);
        self::assertSame('article-42', $view->source);
        self::assertSame('user-7', $view->authorId);
        self::assertSame('Useful feedback.', $view->body);
        self::assertSame('pending', $view->status);
    }

    #[Test]
    public function itRejectsAnInvalidIdentifierBeforeRepositoryAccess(): void
    {
        $handler = new GetCommentHandler(
            new FailingGetCommentRepository(),
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );

        $this->expectException(ValidationFailedException::class);

        $handler(new GetCommentQuery('not-a-uuid'));
    }
}

final readonly class FixedGetCommentRepository implements CommentRepository
{
    public function __construct(private Comment $comment)
    {
    }

    public function save(Comment $comment): void
    {
        throw new \LogicException('Unexpected save.');
    }

    public function get(CommentId $id): Comment
    {
        if ($id->toString() !== $this->comment->id()->toString()) {
            throw new \LogicException('Unexpected identifier.');
        }

        return $this->comment;
    }

    public function search(?string $publisher, ?ModerationStatus $status, int $limit, int $offset): array
    {
        throw new \LogicException('Unexpected search.');
    }

    public function count(?string $publisher, ?ModerationStatus $status): int
    {
        throw new \LogicException('Unexpected count.');
    }
}

final class FailingGetCommentRepository implements CommentRepository
{
    public function save(Comment $comment): void
    {
        throw new \LogicException('Repository must not be called.');
    }

    public function get(CommentId $id): Comment
    {
        throw new \LogicException('Repository must not be called.');
    }

    public function search(?string $publisher, ?ModerationStatus $status, int $limit, int $offset): array
    {
        throw new \LogicException('Repository must not be called.');
    }

    public function count(?string $publisher, ?ModerationStatus $status): int
    {
        throw new \LogicException('Repository must not be called.');
    }
}
