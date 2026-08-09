<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Application\Command\SubmitComment;

use Gsoi\Skeleton\Application\Command\ModerateComment\ModerateCommentCommand;
use Gsoi\Skeleton\Domain\Comment\BannedUserRepository;
use Gsoi\Skeleton\Domain\Comment\Comment;
use Gsoi\Skeleton\Domain\Comment\CommentRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler]
final readonly class SubmitCommentHandler
{
    public function __construct(
        private CommentRepository $comments,
        private BannedUserRepository $bannedUsers,
        private MessageBusInterface $messageBus,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(SubmitCommentCommand $command): SubmitCommentResult
    {
        $violations = $this->validator->validate($command);
        if ($violations->count() > 0) {
            throw new ValidationFailedException($command, $violations);
        }

        $comment = Comment::submit(
            $command->publisher,
            $command->source,
            $command->authorId,
            $command->body,
        );

        if (null !== $command->authorId && $this->bannedUsers->isBanned($command->authorId)) {
            $comment->reject('author_banned');
            $this->comments->save($comment);

            return new SubmitCommentResult($comment->id()->toString(), $comment->status());
        }

        $this->comments->save($comment);
        $this->messageBus->dispatch(new ModerateCommentCommand($comment->id()->toString()));

        return new SubmitCommentResult($comment->id()->toString(), $comment->status());
    }
}
