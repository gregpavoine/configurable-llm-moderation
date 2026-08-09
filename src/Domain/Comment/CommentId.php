<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Domain\Comment;

use Symfony\Component\Uid\Uuid;

final readonly class CommentId
{
    public function __construct(private string $value)
    {
        if (!Uuid::isValid($value)) {
            throw new \InvalidArgumentException('Invalid comment identifier.');
        }
    }

    public static function generate(): self
    {
        return new self(Uuid::v7()->toRfc4122());
    }

    public function toString(): string
    {
        return $this->value;
    }
}
