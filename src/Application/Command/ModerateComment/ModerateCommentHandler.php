<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Command\ModerateComment;

use Gsoi\CommentModeration\Domain\Comment\CommentId;
use Gsoi\CommentModeration\Domain\Comment\CommentRepository;
use Gsoi\CommentModeration\Domain\Comment\ModerationStatus;
use Gsoi\CommentModeration\Domain\Moderation\ModerationService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler]
final readonly class ModerateCommentHandler
{
    public function __construct(
        private CommentRepository $comments,
        private ModerationService $moderationService,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(ModerateCommentCommand $command): void
    {
        $violations = $this->validator->validate($command);
        if ($violations->count() > 0) {
            throw new ValidationFailedException($command, $violations);
        }

        $comment = $this->comments->get(new CommentId($command->commentId));
        if (!$comment->isPending()) {
            return;
        }

        $decision = $this->moderationService->moderate($comment->body());
        if (ModerationStatus::Published === $decision->status) {
            $comment->publish($decision->reason);
        } else {
            $comment->reject($decision->reason);
        }

        $this->comments->save($comment);
    }
}
