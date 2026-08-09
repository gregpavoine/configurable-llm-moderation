<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Infrastructure\Moderation;

use Gsoi\Skeleton\Domain\Moderation\ModerationDecision;
use Gsoi\Skeleton\Domain\Moderation\ModerationService;

final class StubModerationService implements ModerationService
{
    /** @var array<non-empty-string, list<non-empty-string>> */
    private const array RULES = [
        'threat' => ['je vais te tuer', 'i will kill you', 'menace de mort'],
        'hate_or_discrimination' => ['race inférieure', 'mort aux', 'haine raciale', 'sale arabe'],
        'harassment' => ['va te suicider', 'harceler', 'harcèle', 'harcelez'],
        'defamation' => ['est un voleur', 'est une fraude', "c'est un escroc"],
        'illegal_content' => ['vive le terrorisme', 'contenu pédopornographique'],
    ];

    public function moderate(string $content): ModerationDecision
    {
        $normalized = mb_strtolower($content);

        foreach (self::RULES as $reason => $phrases) {
            foreach ($phrases as $phrase) {
                if (str_contains($normalized, $phrase)) {
                    return ModerationDecision::reject($reason);
                }
            }
        }

        return ModerationDecision::publish('allowed');
    }
}
