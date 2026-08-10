<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Unit\Application;

use Gsoi\CommentModeration\Application\Command\ManuallyModerateComment\ManuallyModerateCommentCommand;
use Gsoi\CommentModeration\Application\Command\ManuallyModerateComment\ManuallyModerateCommentHandler;
use Gsoi\CommentModeration\Domain\Comment\Comment;
use Gsoi\CommentModeration\Domain\Comment\CommentId;
use Gsoi\CommentModeration\Domain\Comment\CommentRepository;
use Gsoi\CommentModeration\Domain\Comment\Exception\CommentNotFoundException;
use Gsoi\CommentModeration\Domain\Comment\Exception\InvalidModerationTransitionException;
use Gsoi\CommentModeration\Domain\Comment\ModerationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validation;

final class ManuallyModerateCommentHandlerTest extends TestCase
{
    #[Test]
    public function itPublishesAPendingComment(): void
    {
        $comment = Comment::submit('publisher-a', 'article-1', null, 'Useful comment.');
        $repository = new ManuallyModeratedCommentRepository($comment);
        $handler = $this->handler($repository);

        $view = $handler(new ManuallyModerateCommentCommand(
            $comment->id()->toString(),
            'published',
            'approved_by_operator',
        ));

        self::assertSame('published', $view->status);
        self::assertSame('approved_by_operator', $view->moderationReason);
        self::assertNotNull($view->moderatedAt);
        self::assertSame(1, $repository->saveCount);
    }

    #[Test]
    public function itRejectsAPendingComment(): void
    {
        $comment = Comment::submit('publisher-a', 'article-1', null, 'Abusive comment.');
        $repository = new ManuallyModeratedCommentRepository($comment);
        $handler = $this->handler($repository);

        $view = $handler(new ManuallyModerateCommentCommand(
            $comment->id()->toString(),
            'rejected',
            'rejected_by_operator',
        ));

        self::assertSame('rejected', $view->status);
        self::assertSame('rejected_by_operator', $view->moderationReason);
        self::assertNotNull($view->moderatedAt);
        self::assertSame(1, $repository->saveCount);
    }

    #[Test]
    public function itRejectsAnUnsupportedManualStatusBeforeLoadingTheComment(): void
    {
        $repository = new ManuallyModeratedCommentRepository();
        $handler = $this->handler($repository);

        $this->expectException(ValidationFailedException::class);

        $handler(new ManuallyModerateCommentCommand(
            CommentId::generate()->toString(),
            'pending',
            'operator_review',
        ));
    }

    #[Test]
    public function itRejectsAWhitespaceOnlyModerationReason(): void
    {
        $handler = $this->handler(new ManuallyModeratedCommentRepository());

        $this->expectException(ValidationFailedException::class);

        $handler(new ManuallyModerateCommentCommand(
            CommentId::generate()->toString(),
            'published',
            " \t\n ",
        ));
    }

    #[Test]
    public function itFailsWhenTheCommentDoesNotExist(): void
    {
        $handler = $this->handler(new ManuallyModeratedCommentRepository());

        $this->expectException(CommentNotFoundException::class);

        $handler(new ManuallyModerateCommentCommand(
            CommentId::generate()->toString(),
            'published',
            'approved_by_operator',
        ));
    }

    #[Test]
    public function itFailsWhenTheCommentIsAlreadyInAFinalState(): void
    {
        $comment = Comment::submit('publisher-a', 'article-1', null, 'Already moderated.');
        $comment->publish('allowed');
        $handler = $this->handler(new ManuallyModeratedCommentRepository($comment));

        $this->expectException(InvalidModerationTransitionException::class);

        $handler(new ManuallyModerateCommentCommand(
            $comment->id()->toString(),
            'rejected',
            'rejected_by_operator',
        ));
    }

    private function handler(CommentRepository $repository): ManuallyModerateCommentHandler
    {
        return new ManuallyModerateCommentHandler(
            $repository,
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );
    }
}

final class ManuallyModeratedCommentRepository implements CommentRepository
{
    public int $saveCount = 0;

    public function __construct(private ?Comment $comment = null)
    {
    }

    public function save(Comment $comment): void
    {
        ++$this->saveCount;
        $this->comment = $comment;
    }

    public function get(CommentId $id): Comment
    {
        if (null === $this->comment || $this->comment->id()->toString() !== $id->toString()) {
            throw CommentNotFoundException::withId($id);
        }

        return $this->comment;
    }

    public function search(?string $publisher, ?ModerationStatus $status, int $limit, int $offset): array
    {
        return [];
    }

    public function count(?string $publisher, ?ModerationStatus $status): int
    {
        return 0;
    }
}
