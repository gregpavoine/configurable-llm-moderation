<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Domain\Comment\Exception;

use Gsoi\CommentModeration\Domain\Comment\CommentId;
use Gsoi\CommentModeration\Domain\DomainException;

final class CommentNotFoundException extends DomainException
{
    public static function withId(CommentId $id): self
    {
        return new self(sprintf('Comment "%s" was not found.', $id->toString()));
    }
}
