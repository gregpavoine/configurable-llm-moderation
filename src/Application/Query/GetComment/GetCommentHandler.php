<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Application\Query\GetComment;

use Gsoi\Skeleton\Application\Query\CommentView;
use Gsoi\Skeleton\Domain\Comment\CommentId;
use Gsoi\Skeleton\Domain\Comment\CommentRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler]
final readonly class GetCommentHandler
{
    public function __construct(
        private CommentRepository $comments,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(GetCommentQuery $query): CommentView
    {
        $violations = $this->validator->validate($query);
        if ($violations->count() > 0) {
            throw new ValidationFailedException($query, $violations);
        }

        return CommentView::fromComment($this->comments->get(new CommentId($query->id)));
    }
}
