<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Api\Comment;

final readonly class SearchCommentsParams
{
    public function __construct(
        public ?string $publisher = null,
        public ?string $status = null,
        public int $limit = 20,
        public int $offset = 0,
    ) {
    }
}
