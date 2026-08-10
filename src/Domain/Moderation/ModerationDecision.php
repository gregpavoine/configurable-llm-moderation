<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Domain\Moderation;

use Gsoi\CommentModeration\Domain\Comment\ModerationStatus;

final readonly class ModerationDecision
{
    private function __construct(
        public ModerationStatus $status,
        public string $reason,
    ) {
    }

    public static function publish(string $reason): self
    {
        return new self(ModerationStatus::Published, $reason);
    }

    public static function reject(string $reason): self
    {
        return new self(ModerationStatus::Rejected, $reason);
    }

    public static function defer(string $reason): self
    {
        return new self(ModerationStatus::Pending, $reason);
    }
}
