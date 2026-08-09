<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\Application\Query;

final readonly class CommentSearchResult
{
    /** @param list<CommentView> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $limit,
        public int $offset,
    ) {
    }
}
