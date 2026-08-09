<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Unit\Infrastructure\Moderation;

use Gsoi\CommentModeration\Domain\Comment\ModerationStatus;
use Gsoi\CommentModeration\Infrastructure\Moderation\StubModerationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StubModerationServiceTest extends TestCase
{
    #[Test]
    public function benignContentIsPublished(): void
    {
        $decision = (new StubModerationService())->moderate('Merci pour cet article très intéressant.');

        self::assertSame(ModerationStatus::Published, $decision->status);
        self::assertSame('allowed', $decision->reason);
    }

    /** @param non-empty-string $content */
    #[Test]
    #[DataProvider('forbiddenContent')]
    public function forbiddenContentIsRejected(string $content, string $reason): void
    {
        $decision = (new StubModerationService())->moderate($content);

        self::assertSame(ModerationStatus::Rejected, $decision->status);
        self::assertSame($reason, $decision->reason);
    }

    /** @return iterable<string, array{non-empty-string, non-empty-string}> */
    public static function forbiddenContent(): iterable
    {
        yield 'threat' => ['Je vais te tuer.', 'threat'];
        yield 'hate speech' => ['Ces gens sont une race inférieure.', 'hate_or_discrimination'];
        yield 'harassment' => ['Va te suicider, je vais continuer à te harceler.', 'harassment'];
        yield 'defamation' => ["Ce journaliste est un voleur.", 'defamation'];
        yield 'terrorism praise' => ['Vive le terrorisme.', 'illegal_content'];
    }
}
