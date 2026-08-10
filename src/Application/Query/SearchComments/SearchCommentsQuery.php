<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Query\SearchComments;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SearchCommentsQuery
{
    public function __construct(
        #[Assert\Length(max: 100)]
        public ?string $publisher = null,
        #[Assert\Choice(choices: ['pending', 'published', 'rejected'])]
        public ?string $status = null,
        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 20,
        #[Assert\PositiveOrZero]
        public int $offset = 0,
    ) {
    }
}
