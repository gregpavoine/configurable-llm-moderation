<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Domain\Comment;

interface CommentRepository
{
    public function save(Comment $comment): void;

    public function get(CommentId $id): Comment;

    /** @return list<Comment> */
    public function search(?string $publisher, ?ModerationStatus $status, int $limit, int $offset): array;

    public function count(?string $publisher, ?ModerationStatus $status): int;
}
