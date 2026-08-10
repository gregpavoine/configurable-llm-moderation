<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Unit\Domain\Comment;

use Gsoi\CommentModeration\Domain\Comment\CommentId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class CommentIdTest extends TestCase
{
    #[Test]
    public function itPreservesAValidIdentifier(): void
    {
        $value = '019fe6de-6991-77cd-8af3-67924aa3b591';

        self::assertSame($value, (new CommentId($value))->toString());
    }

    #[Test]
    public function itGeneratesAValidUuidVersionSeven(): void
    {
        $value = CommentId::generate()->toString();

        self::assertTrue(Uuid::isValid($value));
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($value));
    }

    #[Test]
    public function itRejectsAnInvalidIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid comment identifier.');

        new CommentId('invalid');
    }
}
