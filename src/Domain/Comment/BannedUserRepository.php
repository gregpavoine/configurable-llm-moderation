<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Domain\Comment;

interface BannedUserRepository
{
    public function isBanned(string $userId): bool;
}
