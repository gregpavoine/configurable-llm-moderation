<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Command\ModerateText;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ModerateTextCommand
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 5000)]
        public string $body,
    ) {
    }
}
