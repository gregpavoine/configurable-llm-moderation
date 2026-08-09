<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Command\SubmitComment;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SubmitCommentCommand
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public string $publisher,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $source,
        #[Assert\Length(max: 100)]
        public ?string $authorId,
        #[Assert\NotBlank]
        #[Assert\Length(max: 5000)]
        public string $body,
    ) {
    }
}
