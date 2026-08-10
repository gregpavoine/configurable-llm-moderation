<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Domain\Moderation;

interface ModerationProviderStatusChecker
{
    public function check(): ModerationProviderStatus;
}
