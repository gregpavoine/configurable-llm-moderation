<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Command\ModerateCommentNow;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ModerateCommentNowCommand
{
    public function __construct(
        #[Assert\Uuid]
        public string $commentId,
    ) {
    }
}
