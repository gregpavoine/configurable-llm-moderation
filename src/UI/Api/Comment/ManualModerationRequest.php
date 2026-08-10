<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Api\Comment;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ManualModerationRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['published', 'rejected'])]
        #[OA\Property(enum: ['published', 'rejected'])]
        public string $status,
        #[Assert\NotBlank(allowNull: true, normalizer: 'trim')]
        #[Assert\Length(min: 1, max: 100)]
        #[OA\Property(nullable: true, maxLength: 100)]
        public ?string $reason = null,
    ) {
    }
}
