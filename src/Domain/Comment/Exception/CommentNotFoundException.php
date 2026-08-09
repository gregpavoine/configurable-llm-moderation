<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Domain\Comment\Exception;

use Gsoi\Skeleton\Domain\Comment\CommentId;
use Gsoi\Skeleton\Domain\DomainException;

final class CommentNotFoundException extends DomainException
{
    public static function withId(CommentId $id): self
    {
        return new self(sprintf('Comment "%s" was not found.', $id->toString()));
    }
}
