<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Tests\Unit\Application;

use Gsoi\Skeleton\Application\Command\ModerateComment\ModerateCommentCommand;
use Gsoi\Skeleton\Application\Command\ModerateComment\ModerateCommentHandler;
use Gsoi\Skeleton\Domain\Comment\Comment;
use Gsoi\Skeleton\Domain\Comment\CommentId;
use Gsoi\Skeleton\Domain\Comment\CommentRepository;
use Gsoi\Skeleton\Domain\Comment\Exception\CommentNotFoundException;
use Gsoi\Skeleton\Domain\Comment\ModerationStatus;
use Gsoi\Skeleton\Domain\Moderation\ModerationDecision;
use Gsoi\Skeleton\Domain\Moderation\ModerationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class ModerateCommentHandlerTest extends TestCase
{
    #[Test]
    public function itPublishesAnAllowedPendingComment(): void
    {
        $comment = Comment::submit('publisher-a', 'article-1', null, 'Thank you.');
        $repository = new ModerationCommentRepository($comment);
        $handler = $this->handler($repository, new FixedModerationService(ModerationDecision::publish('allowed')));

        $handler(new ModerateCommentCommand($comment->id()->toString()));

        self::assertSame(ModerationStatus::Published, $comment->status());
        self::assertSame('allowed', $comment->moderationReason());
        self::assertSame(1, $repository->saveCount);
    }

    #[Test]
    public function itRejectsAForbiddenPendingComment(): void
    {
        $comment = Comment::submit('publisher-a', 'article-1', null, 'Forbidden.');
        $handler = $this->handler(
            new ModerationCommentRepository($comment),
            new FixedModerationService(ModerationDecision::reject('harassment')),
        );

        $handler(new ModerateCommentCommand($comment->id()->toString()));

        self::assertSame(ModerationStatus::Rejected, $comment->status());
        self::assertSame('harassment', $comment->moderationReason());
    }

    #[Test]
    public function repeatedDeliveryDoesNotModerateOrSaveAgain(): void
    {
        $comment = Comment::submit('publisher-a', 'article-1', null, 'Thank you.');
        $comment->publish('allowed');
        $repository = new ModerationCommentRepository($comment);
        $service = new CountingModerationService();
        $handler = $this->handler($repository, $service);

        $handler(new ModerateCommentCommand($comment->id()->toString()));

        self::assertSame(0, $service->calls);
        self::assertSame(0, $repository->saveCount);
    }

    #[Test]
    public function anUnknownCommentFailsExplicitly(): void
    {
        $id = CommentId::generate();
        $repository = new ModerationCommentRepository();
        $handler = $this->handler($repository, new CountingModerationService());

        $this->expectException(CommentNotFoundException::class);

        $handler(new ModerateCommentCommand($id->toString()));
    }

    private function handler(CommentRepository $repository, ModerationService $service): ModerateCommentHandler
    {
        return new ModerateCommentHandler(
            $repository,
            $service,
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );
    }
}

final class ModerationCommentRepository implements CommentRepository
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

final readonly class FixedModerationService implements ModerationService
{
    public function __construct(private ModerationDecision $decision)
    {
    }

    public function moderate(string $content): ModerationDecision
    {
        return $this->decision;
    }
}

final class CountingModerationService implements ModerationService
{
    public int $calls = 0;

    public function moderate(string $content): ModerationDecision
    {
        ++$this->calls;

        return ModerationDecision::publish('allowed');
    }
}
