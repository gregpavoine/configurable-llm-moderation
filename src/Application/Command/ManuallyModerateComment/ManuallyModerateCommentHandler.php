<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Command\ManuallyModerateComment;

use Gsoi\CommentModeration\Application\Query\CommentView;
use Gsoi\CommentModeration\Domain\Comment\CommentId;
use Gsoi\CommentModeration\Domain\Comment\CommentRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler]
final readonly class ManuallyModerateCommentHandler
{
    public function __construct(
        private CommentRepository $comments,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(ManuallyModerateCommentCommand $command): CommentView
    {
        $violations = $this->validator->validate($command);
        if ($violations->count() > 0) {
            throw new ValidationFailedException($command, $violations);
        }

        $comment = $this->comments->get(new CommentId($command->commentId));
        match ($command->status) {
            'published' => $comment->publish($command->reason),
            'rejected' => $comment->reject($command->reason),
            default => throw new \LogicException('Unsupported manual moderation status.'),
        };
        $this->comments->save($comment);

        return CommentView::fromComment($comment);
    }
}
