<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Api\Comment;

use Gsoi\CommentModeration\Application\Command\ModeratePendingCommentsBatch\ModeratePendingCommentsBatchCommand;
use Gsoi\CommentModeration\Application\Command\ModeratePendingCommentsBatch\ModeratePendingCommentsBatchResult;
use Gsoi\CommentModeration\UI\Api\HandleTrait;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/comments/moderation/batch', methods: ['POST'])]
#[OA\Tag(name: 'Comments')]
#[OA\RequestBody(required: false, content: new OA\JsonContent(ref: new Model(type: BatchModerationRequest::class)))]
#[OA\Response(response: 200, description: 'Batch moderation result.')]
#[OA\Response(response: 401, description: 'Authentication required.')]
#[OA\Response(response: 403, description: 'Moderator role required.')]
#[OA\Response(response: 422, description: 'Invalid batch payload.')]
final class BatchModerateCommentsController
{
    use HandleTrait;

    public function __invoke(
        #[MapRequestPayload] BatchModerationRequest $request,
        MessageBusInterface $messageBus,
    ): JsonResponse {
        $result = $this->handle($messageBus, new ModeratePendingCommentsBatchCommand($request->limit));
        if (!$result instanceof ModeratePendingCommentsBatchResult) {
            throw new \LogicException('Unexpected batch moderation result.');
        }

        return new JsonResponse($result->toArray());
    }
}
