<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Command\ModerateComment;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ModerateCommentCommand
{
    public function __construct(
        #[Assert\Uuid]
        public string $commentId,
    ) {
    }
}
