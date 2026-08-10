<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Command\ManuallyModerateComment;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ManuallyModerateCommentCommand
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $commentId,
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['published', 'rejected'])]
        public string $status,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(min: 1, max: 100)]
        public string $reason,
    ) {
    }
}
