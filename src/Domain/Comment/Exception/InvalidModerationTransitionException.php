<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Domain\Comment\Exception;

use Gsoi\Skeleton\Domain\DomainException;

final class InvalidModerationTransitionException extends DomainException
{
    public static function fromFinalState(string $status): self
    {
        return new self(sprintf('A comment in status "%s" cannot be moderated again.', $status));
    }
}
