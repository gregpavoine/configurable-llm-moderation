<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Unit\Domain\Moderation;

use Gsoi\CommentModeration\Domain\Comment\ModerationStatus;
use Gsoi\CommentModeration\Domain\Moderation\ModerationDecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModerationDecisionTest extends TestCase
{
    /** @return iterable<string, array{ModerationDecision, ModerationStatus, string}> */
    public static function decisions(): iterable
    {
        yield 'publish' => [ModerationDecision::publish('acceptable'), ModerationStatus::Published, 'acceptable'];
        yield 'reject' => [ModerationDecision::reject('harassment'), ModerationStatus::Rejected, 'harassment'];
        yield 'defer' => [ModerationDecision::defer('manual_review_required'), ModerationStatus::Pending, 'manual_review_required'];
    }

    #[Test]
    #[DataProvider('decisions')]
    public function itBuildsTheRequestedDecision(
        ModerationDecision $decision,
        ModerationStatus $expectedStatus,
        string $expectedReason,
    ): void {
        self::assertSame($expectedStatus, $decision->status);
        self::assertSame($expectedReason, $decision->reason);
    }
}
