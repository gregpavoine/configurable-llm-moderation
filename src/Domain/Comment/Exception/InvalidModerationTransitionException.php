<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Domain\Comment\Exception;

use Gsoi\CommentModeration\Domain\DomainException;

final class InvalidModerationTransitionException extends DomainException
{
    public static function fromFinalState(string $status): self
    {
        return new self(sprintf('A comment in status "%s" cannot be moderated again.', $status));
    }
}
