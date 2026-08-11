<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Domain\Comment;

interface CommentRepository
{
    public function save(Comment $comment): void;

    public function get(CommentId $id): Comment;

    /** @return list<Comment> */
    public function search(?string $publisher, ?string $source, ?ModerationStatus $status, int $limit, int $offset): array;

    /** @return list<Comment> */
    public function pendingForModeration(int $limit): array;

    public function count(?string $publisher, ?string $source, ?ModerationStatus $status): int;
}
