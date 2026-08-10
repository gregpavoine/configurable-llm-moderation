<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Command\ModerateCommentNow;

use Gsoi\CommentModeration\Application\Command\ModerateComment\ModerateCommentProcessor;
use Gsoi\CommentModeration\Application\Query\CommentView;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler]
final readonly class ModerateCommentNowHandler
{
    public function __construct(
        private ModerateCommentProcessor $processor,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(ModerateCommentNowCommand $command): CommentView
    {
        $violations = $this->validator->validate($command);
        if ($violations->count() > 0) {
            throw new ValidationFailedException($command, $violations);
        }

        return CommentView::fromComment($this->processor->moderate($command->commentId));
    }
}
