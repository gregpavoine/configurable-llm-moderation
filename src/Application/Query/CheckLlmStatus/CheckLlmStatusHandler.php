<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Query\CheckLlmStatus;

use Gsoi\CommentModeration\Domain\Moderation\ModerationProviderStatus;
use Gsoi\CommentModeration\Domain\Moderation\ModerationProviderStatusChecker;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckLlmStatusHandler
{
    public function __construct(private ModerationProviderStatusChecker $checker)
    {
    }

    public function __invoke(CheckLlmStatusQuery $query): ModerationProviderStatus
    {
        return $this->checker->check();
    }
}
