<?php

declare(strict_types=1);

namespace Gsoi\Skeleton\UI\Api\Comment;

use Gsoi\Skeleton\Application\Query\CommentView;
use Gsoi\Skeleton\Application\Query\GetComment\GetCommentQuery;
use Gsoi\Skeleton\UI\Api\HandleTrait;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/comments/{id}', methods: ['GET'])]
#[OA\Tag(name: 'Comments')]
final readonly class GetCommentController
{
    use HandleTrait;

    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    #[OA\Response(response: 200, description: 'Comment detail.')]
    #[OA\Response(response: 404, description: 'Comment not found.')]
    public function __invoke(string $id): JsonResponse
    {
        $result = $this->handle($this->messageBus, new GetCommentQuery($id));
        if (!$result instanceof CommentView) {
            throw new \LogicException('Unexpected comment query result.');
        }

        return new JsonResponse($result->toArray());
    }
}
