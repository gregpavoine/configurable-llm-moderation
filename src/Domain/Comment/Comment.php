<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Domain\Comment;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gsoi\CommentModeration\Domain\Comment\Exception\InvalidModerationTransitionException;

#[ORM\Entity]
#[ORM\Table(name: 'comments')]
#[ORM\Index(columns: ['publisher'], name: 'idx_comments_publisher')]
#[ORM\Index(columns: ['status'], name: 'idx_comments_status')]
final class Comment
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $publisher;

    #[ORM\Column(name: 'source_id', type: Types::STRING, length: 255)]
    private string $source;

    #[ORM\Column(name: 'author_id', type: Types::STRING, length: 100, nullable: true)]
    private ?string $authorId;

    #[ORM\Column(type: Types::TEXT)]
    private string $body;

    #[ORM\Column(type: Types::STRING, enumType: ModerationStatus::class)]
    private ModerationStatus $status;

    #[ORM\Column(name: 'moderation_reason', type: Types::STRING, length: 100, nullable: true)]
    private ?string $moderationReason = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'moderated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $moderatedAt = null;

    private function __construct()
    {
    }

    public static function submit(
        string $publisher,
        string $source,
        ?string $authorId,
        string $body,
        ?DateTimeImmutable $createdAt = null,
    ): self {
        $comment = new self();
        $comment->id = CommentId::generate()->toString();
        $comment->publisher = $publisher;
        $comment->source = $source;
        $comment->authorId = $authorId;
        $comment->body = $body;
        $comment->status = ModerationStatus::Pending;
        $comment->createdAt = $createdAt ?? new DateTimeImmutable();

        return $comment;
    }

    public function publish(string $reason, ?DateTimeImmutable $moderatedAt = null): void
    {
        $this->decide(ModerationStatus::Published, $reason, $moderatedAt);
    }

    public function reject(string $reason, ?DateTimeImmutable $moderatedAt = null): void
    {
        $this->decide(ModerationStatus::Rejected, $reason, $moderatedAt);
    }

    public function id(): CommentId
    {
        return new CommentId($this->id);
    }

    public function publisher(): string
    {
        return $this->publisher;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function authorId(): ?string
    {
        return $this->authorId;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): ModerationStatus
    {
        return $this->status;
    }

    public function moderationReason(): ?string
    {
        return $this->moderationReason;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function moderatedAt(): ?DateTimeImmutable
    {
        return $this->moderatedAt;
    }

    public function isPending(): bool
    {
        return ModerationStatus::Pending === $this->status;
    }

    private function decide(ModerationStatus $status, string $reason, ?DateTimeImmutable $moderatedAt): void
    {
        if (!$this->isPending()) {
            throw InvalidModerationTransitionException::fromFinalState($this->status->value);
        }

        $this->status = $status;
        $this->moderationReason = $reason;
        $this->moderatedAt = $moderatedAt ?? new DateTimeImmutable();
    }
}
