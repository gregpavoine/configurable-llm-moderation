<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Application\Query\SearchComments;

use Gsoi\Skeleton\Application\Query\CommentSearchResult;
use Gsoi\Skeleton\Application\Query\CommentView;
use Gsoi\Skeleton\Domain\Comment\CommentRepository;
use Gsoi\Skeleton\Domain\Comment\ModerationStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler]
final readonly class SearchCommentsHandler
{
    public function __construct(
        private CommentRepository $comments,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(SearchCommentsQuery $query): CommentSearchResult
    {
        $violations = $this->validator->validate($query);
        if ($violations->count() > 0) {
            throw new ValidationFailedException($query, $violations);
        }

        $status = null === $query->status ? null : ModerationStatus::from($query->status);
        $comments = $this->comments->search($query->publisher, $status, $query->limit, $query->offset);

        return new CommentSearchResult(
            array_map(CommentView::fromComment(...), $comments),
            $this->comments->count($query->publisher, $status),
            $query->limit,
            $query->offset,
        );
    }
}
