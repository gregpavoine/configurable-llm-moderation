<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Unit\Infrastructure\Framework\Symfony;

use Gsoi\CommentModeration\Infrastructure\Framework\Symfony\Kernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    #[Test]
    public function productionCannotBootWithDebugEnabled(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('APP_DEBUG=0');

        new Kernel('prod', true);
    }

    #[Test]
    public function developmentCanBootWithDebugEnabled(): void
    {
        $kernel = new Kernel('dev', true);

        self::assertTrue($kernel->isDebug());
    }
}
