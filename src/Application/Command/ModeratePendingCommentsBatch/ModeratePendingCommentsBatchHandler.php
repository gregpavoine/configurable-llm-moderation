<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Command\ModeratePendingCommentsBatch;

use Gsoi\CommentModeration\Application\Command\ModerateComment\ModerateCommentProcessor;
use Gsoi\CommentModeration\Application\Query\CommentView;
use Gsoi\CommentModeration\Domain\Comment\CommentRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler]
final readonly class ModeratePendingCommentsBatchHandler
{
    public function __construct(
        private CommentRepository $comments,
        private ModerateCommentProcessor $processor,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(ModeratePendingCommentsBatchCommand $command): ModeratePendingCommentsBatchResult
    {
        $violations = $this->validator->validate($command);
        if ($violations->count() > 0) {
            throw new ValidationFailedException($command, $violations);
        }

        $views = [];
        foreach ($this->comments->pendingForModeration($command->limit) as $comment) {
            $views[] = CommentView::fromComment($this->processor->moderate($comment->id()->toString()));
        }

        return new ModeratePendingCommentsBatchResult($views, count($views), $command->limit);
    }
}
