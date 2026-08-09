<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Application\Command\SubmitComment;

use Gsoi\Skeleton\Domain\Comment\ModerationStatus;

final readonly class SubmitCommentResult
{
    public function __construct(
        public string $id,
        public ModerationStatus $status,
    ) {
    }
}
