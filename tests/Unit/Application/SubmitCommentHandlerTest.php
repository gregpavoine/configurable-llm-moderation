<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Unit\Application;

use Gsoi\CommentModeration\Application\Command\ModerateComment\ModerateCommentCommand;
use Gsoi\CommentModeration\Application\Command\SubmitComment\SubmitCommentCommand;
use Gsoi\CommentModeration\Application\Command\SubmitComment\SubmitCommentHandler;
use Gsoi\CommentModeration\Domain\Comment\BannedUserRepository;
use Gsoi\CommentModeration\Domain\Comment\Comment;
use Gsoi\CommentModeration\Domain\Comment\CommentId;
use Gsoi\CommentModeration\Domain\Comment\CommentRepository;
use Gsoi\CommentModeration\Domain\Comment\ModerationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validation;

final class SubmitCommentHandlerTest extends TestCase
{
    #[Test]
    public function aValidCommentIsPersistedAndQueuedForModeration(): void
    {
        $comments = new InMemoryCommentRepository();
        $bus = new RecordingMessageBus();
        $handler = $this->handler($comments, new FixedBannedUserRepository(false), $bus);

        $result = $handler(new SubmitCommentCommand('publisher-a', 'article-42', 'user-7', 'A useful comment.'));

        self::assertSame(ModerationStatus::Pending, $result->status);
        self::assertCount(1, $comments->comments);
        self::assertInstanceOf(ModerateCommentCommand::class, $bus->messages[0]);
        self::assertSame($result->id, $bus->messages[0]->commentId);
    }

    #[Test]
    public function aBannedAuthorsCommentIsRejectedWithoutModeration(): void
    {
        $comments = new InMemoryCommentRepository();
        $bus = new RecordingMessageBus();
        $handler = $this->handler($comments, new FixedBannedUserRepository(true), $bus);

        $result = $handler(new SubmitCommentCommand('publisher-a', 'article-42', 'user-7', 'Text.'));

        self::assertSame(ModerationStatus::Rejected, $result->status);
        self::assertSame('author_banned', $comments->comments[0]->moderationReason());
        self::assertSame([], $bus->messages);
    }

    #[Test]
    public function anAnonymousCommentDoesNotUseTheUntrustedBanSignal(): void
    {
        $comments = new InMemoryCommentRepository();
        $bus = new RecordingMessageBus();
        $handler = $this->handler($comments, new FailingBannedUserRepository(), $bus);

        $result = $handler(new SubmitCommentCommand('publisher-a', 'article-42', null, 'Anonymous feedback.'));

        self::assertSame(ModerationStatus::Pending, $result->status);
        self::assertCount(1, $comments->comments);
        self::assertCount(1, $bus->messages);
    }

    #[Test]
    public function anInvalidCommentIsRejectedBeforePersistence(): void
    {
        $comments = new InMemoryCommentRepository();
        $handler = $this->handler($comments, new FixedBannedUserRepository(false), new RecordingMessageBus());

        $this->expectException(ValidationFailedException::class);

        $handler(new SubmitCommentCommand('', '', null, ''));
    }

    private function handler(
        CommentRepository $comments,
        BannedUserRepository $bannedUsers,
        MessageBusInterface $bus,
    ): SubmitCommentHandler {
        return new SubmitCommentHandler(
            $comments,
            $bannedUsers,
            $bus,
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );
    }
}

final class InMemoryCommentRepository implements CommentRepository
{
    /** @var list<Comment> */
    public array $comments = [];

    public function save(Comment $comment): void
    {
        $this->comments[] = $comment;
    }

    public function get(CommentId $id): Comment
    {
        foreach ($this->comments as $comment) {
            if ($comment->id()->toString() === $id->toString()) {
                return $comment;
            }
        }

        throw new \RuntimeException('Not found.');
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

final readonly class FixedBannedUserRepository implements BannedUserRepository
{
    public function __construct(private bool $banned)
    {
    }

    public function isBanned(string $userId): bool
    {
        return $this->banned;
    }
}

final class FailingBannedUserRepository implements BannedUserRepository
{
    public function isBanned(string $userId): bool
    {
        throw new \LogicException('Anonymous submissions must not query the ban signal.');
    }
}

final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return Envelope::wrap($message, $stamps);
    }
}
