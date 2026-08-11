<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Api\Comment;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class BatchModerationRequest
{
    public function __construct(
        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 20,
    ) {
    }
}
