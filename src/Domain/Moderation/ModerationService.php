<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Domain\Moderation;

interface ModerationService
{
    public function moderate(string $content): ModerationDecision;
}
