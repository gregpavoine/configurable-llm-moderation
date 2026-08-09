<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Domain\Moderation;

interface ModerationService
{
    public function moderate(string $content): ModerationDecision;
}
