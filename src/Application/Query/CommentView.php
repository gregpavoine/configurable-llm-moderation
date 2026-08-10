<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Query;

use Gsoi\CommentModeration\Domain\Comment\Comment;

final readonly class CommentView
{
    public function __construct(
        public string $id,
        public string $publisher,
        public string $source,
        public ?string $authorId,
        public string $body,
        public string $status,
        public ?string $moderationReason,
        public string $createdAt,
        public ?string $moderatedAt,
    ) {
    }

    public static function fromComment(Comment $comment): self
    {
        return new self(
            $comment->id()->toString(),
            $comment->publisher(),
            $comment->source(),
            $comment->authorId(),
            $comment->body(),
            $comment->status()->value,
            $comment->moderationReason(),
            $comment->createdAt()->format(DATE_ATOM),
            $comment->moderatedAt()?->format(DATE_ATOM),
        );
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'publisher' => $this->publisher,
            'source' => $this->source,
            'authorId' => $this->authorId,
            'body' => $this->body,
            'status' => $this->status,
            'moderationReason' => $this->moderationReason,
            'createdAt' => $this->createdAt,
            'moderatedAt' => $this->moderatedAt,
        ];
    }
}
