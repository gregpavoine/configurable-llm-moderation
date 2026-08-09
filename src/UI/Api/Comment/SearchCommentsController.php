<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Api\Comment;

use Gsoi\CommentModeration\Application\Query\CommentSearchResult;
use Gsoi\CommentModeration\Application\Query\CommentView;
use Gsoi\CommentModeration\Application\Query\SearchComments\SearchCommentsQuery;
use Gsoi\CommentModeration\UI\Api\HandleTrait;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/comments', methods: ['GET'])]
#[OA\Tag(name: 'Comments')]
final readonly class SearchCommentsController
{
    use HandleTrait;

    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    #[OA\Response(response: 200, description: 'Filtered comment collection.')]
    public function __invoke(#[MapQueryString] SearchCommentsParams $params = new SearchCommentsParams()): JsonResponse
    {
        $result = $this->handle($this->messageBus, new SearchCommentsQuery(
            $params->publisher,
            $params->status,
            $params->limit,
            $params->offset,
        ));
        if (!$result instanceof CommentSearchResult) {
            throw new \LogicException('Unexpected search result.');
        }

        return new JsonResponse([
            'items' => array_map(static fn (CommentView $view): array => $view->toArray(), $result->items),
            'total' => $result->total,
            'limit' => $result->limit,
            'offset' => $result->offset,
        ]);
    }
}
