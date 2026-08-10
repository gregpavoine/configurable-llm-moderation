<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Command\ModerateComment;

use Gsoi\CommentModeration\Domain\Comment\Comment;
use Gsoi\CommentModeration\Domain\Comment\CommentId;
use Gsoi\CommentModeration\Domain\Comment\CommentRepository;
use Gsoi\CommentModeration\Domain\Comment\ModerationStatus;
use Gsoi\CommentModeration\Domain\Moderation\ModerationService;

final readonly class ModerateCommentProcessor
{
    public function __construct(
        private CommentRepository $comments,
        private ModerationService $moderationService,
    ) {
    }

    public function moderate(string $commentId): Comment
    {
        $comment = $this->comments->get(new CommentId($commentId));
        if (!$comment->isPending()) {
            return $comment;
        }

        $decision = $this->moderationService->moderate($comment->body());
        match ($decision->status) {
            ModerationStatus::Published => $comment->publish($decision->reason),
            ModerationStatus::Rejected => $comment->reject($decision->reason),
            ModerationStatus::Pending => $comment->defer($decision->reason),
        };

        $this->comments->save($comment);

        return $comment;
    }
}
