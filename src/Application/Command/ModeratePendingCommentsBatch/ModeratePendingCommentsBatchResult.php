<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Command\ModeratePendingCommentsBatch;

use Gsoi\CommentModeration\Application\Query\CommentView;

final readonly class ModeratePendingCommentsBatchResult
{
    /** @param list<CommentView> $items */
    public function __construct(
        public array $items,
        public int $processed,
        public int $limit,
    ) {
    }

    /** @return array{items: list<array<string, mixed>>, processed: int, limit: int} */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (CommentView $view): array => $view->toArray(), $this->items),
            'processed' => $this->processed,
            'limit' => $this->limit,
        ];
    }
}
