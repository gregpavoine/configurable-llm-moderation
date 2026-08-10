<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Command\SubmitComment;

use Gsoi\CommentModeration\Domain\Comment\ModerationStatus;

final readonly class SubmitCommentResult
{
    public function __construct(
        public string $id,
        public ModerationStatus $status,
    ) {
    }
}
