<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Api\Comment;

use OpenApi\Attributes as OA;

final readonly class SubmitCommentRequest
{
    public function __construct(
        #[OA\Property(example: 'publisher-a')]
        public string $publisher,
        #[OA\Property(example: 'article-42')]
        public string $source,
        #[OA\Property(nullable: true, example: 'user-7', description: 'Optional best-effort external identifier supplied by the caller; it is not authenticated by this public endpoint.')]
        public ?string $authorId,
        #[OA\Property(maxLength: 5000, example: 'Merci pour cet article.')]
        public string $body,
    ) {
    }
}
