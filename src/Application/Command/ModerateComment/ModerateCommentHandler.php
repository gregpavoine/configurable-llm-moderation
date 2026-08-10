<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Command\ModerateComment;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler]
final readonly class ModerateCommentHandler
{
    public function __construct(
        private ModerateCommentProcessor $processor,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(ModerateCommentCommand $command): void
    {
        $violations = $this->validator->validate($command);
        if ($violations->count() > 0) {
            throw new ValidationFailedException($command, $violations);
        }

        $this->processor->moderate($command->commentId);
    }
}
