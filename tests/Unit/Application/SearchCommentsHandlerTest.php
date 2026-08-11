<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Unit\Application;

use Gsoi\CommentModeration\Application\Query\SearchComments\SearchCommentsHandler;
use Gsoi\CommentModeration\Application\Query\SearchComments\SearchCommentsQuery;
use Gsoi\CommentModeration\Domain\Comment\Comment;
use Gsoi\CommentModeration\Domain\Comment\CommentId;
use Gsoi\CommentModeration\Domain\Comment\CommentRepository;
use Gsoi\CommentModeration\Domain\Comment\ModerationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validation;

final class SearchCommentsHandlerTest extends TestCase
{
    #[Test]
    public function itMapsFiltersPaginationItemsAndTotal(): void
    {
        $first = Comment::submit('publisher-a', 'article-1', null, 'First.');
        $first->reject('harassment');
        $second = Comment::submit('publisher-a', 'article-2', 'user-8', 'Second.');
        $second->reject('spam');
        $repository = new RecordingSearchCommentRepository([$first, $second], 7);
        $handler = $this->handler($repository);

        $result = $handler(new SearchCommentsQuery('publisher-a', 'rejected', 2, 4));

        self::assertSame(7, $result->total);
        self::assertSame(2, $result->limit);
        self::assertSame(4, $result->offset);
        self::assertSame([$first->id()->toString(), $second->id()->toString()], array_column($result->items, 'id'));
        self::assertSame(['rejected', 'rejected'], array_column($result->items, 'status'));
        self::assertSame(['publisher-a', ModerationStatus::Rejected, 2, 4], $repository->searchArguments);
        self::assertSame(['publisher-a', ModerationStatus::Rejected], $repository->countArguments);
    }

    #[Test]
    public function itUsesUnfilteredDefaultPagination(): void
    {
        $repository = new RecordingSearchCommentRepository([], 0);

        $result = ($this->handler($repository))(new SearchCommentsQuery());

        self::assertSame([], $result->items);
        self::assertSame(0, $result->total);
        self::assertSame(20, $result->limit);
        self::assertSame(0, $result->offset);
        self::assertSame([null, null, 20, 0], $repository->searchArguments);
        self::assertSame([null, null], $repository->countArguments);
    }

    /** @return iterable<string, array{SearchCommentsQuery}> */
    public static function invalidQueries(): iterable
    {
        yield 'publisher too long' => [new SearchCommentsQuery(str_repeat('p', 101))];
        yield 'unknown status' => [new SearchCommentsQuery(status: 'archived')];
        yield 'zero limit' => [new SearchCommentsQuery(limit: 0)];
        yield 'limit over maximum' => [new SearchCommentsQuery(limit: 101)];
        yield 'negative offset' => [new SearchCommentsQuery(offset: -1)];
    }

    #[Test]
    #[DataProvider('invalidQueries')]
    public function itRejectsInvalidQueriesBeforeRepositoryAccess(SearchCommentsQuery $query): void
    {
        $this->expectException(ValidationFailedException::class);

        ($this->handler(new FailingSearchCommentRepository()))($query);
    }

    private function handler(CommentRepository $repository): SearchCommentsHandler
    {
        return new SearchCommentsHandler(
            $repository,
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );
    }
}

final class RecordingSearchCommentRepository implements CommentRepository
{
    /** @var null|array{?string, ?ModerationStatus, int, int} */
    public ?array $searchArguments = null;

    /** @var null|array{?string, ?ModerationStatus} */
    public ?array $countArguments = null;

    /** @param list<Comment> $comments */
    public function __construct(
        private readonly array $comments,
        private readonly int $total,
    ) {
    }

    public function save(Comment $comment): void
    {
        throw new \LogicException('Unexpected save.');
    }

    public function get(CommentId $id): Comment
    {
        throw new \LogicException('Unexpected get.');
    }

    public function search(?string $publisher, ?ModerationStatus $status, int $limit, int $offset): array
    {
        $this->searchArguments = [$publisher, $status, $limit, $offset];

        return $this->comments;
    }

    public function count(?string $publisher, ?ModerationStatus $status): int
    {
        $this->countArguments = [$publisher, $status];

        return $this->total;
    }

    public function pendingForModeration(int $limit): array
    {
        throw new \LogicException('Unexpected pending moderation lookup.');
    }
}

final class FailingSearchCommentRepository implements CommentRepository
{
    public function save(Comment $comment): void
    {
        throw new \LogicException('Repository must not be called.');
    }

    public function get(CommentId $id): Comment
    {
        throw new \LogicException('Repository must not be called.');
    }

    public function search(?string $publisher, ?ModerationStatus $status, int $limit, int $offset): array
    {
        throw new \LogicException('Repository must not be called.');
    }

    public function count(?string $publisher, ?ModerationStatus $status): int
    {
        throw new \LogicException('Repository must not be called.');
    }

    public function pendingForModeration(int $limit): array
    {
        throw new \LogicException('Repository must not be called.');
    }
}
