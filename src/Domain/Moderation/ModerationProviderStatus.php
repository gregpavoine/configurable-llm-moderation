<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Domain\Moderation;

final readonly class ModerationProviderStatus
{
    public function __construct(
        public bool $configured,
        public bool $available,
        public ?string $reason,
        public ?string $providerHost,
        public string $model,
    ) {
    }

    /** @return array{configured: bool, available: bool, reason: ?string, providerHost: ?string, model: string} */
    public function toArray(): array
    {
        return [
            'configured' => $this->configured,
            'available' => $this->available,
            'reason' => $this->reason,
            'providerHost' => $this->providerHost,
            'model' => $this->model,
        ];
    }
}
