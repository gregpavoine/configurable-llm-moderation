<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Api\Comment;

use Gsoi\CommentModeration\Application\Command\SubmitComment\SubmitCommentCommand;
use Gsoi\CommentModeration\Application\Command\SubmitComment\SubmitCommentResult;
use Gsoi\CommentModeration\UI\Api\HandleTrait;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/comments', methods: ['POST'])]
#[OA\Tag(name: 'Comments')]
final readonly class SubmitCommentController
{
    use HandleTrait;

    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    #[OA\Response(response: 202, description: 'Comment acknowledged for moderation.')]
    #[OA\Response(response: 422, description: 'Invalid comment payload.')]
    public function __invoke(#[MapRequestPayload] SubmitCommentRequest $request): JsonResponse
    {
        $result = $this->handle($this->messageBus, new SubmitCommentCommand(
            $request->publisher,
            $request->source,
            $request->authorId,
            $request->body,
        ));

        if (!$result instanceof SubmitCommentResult) {
            throw new \LogicException('Unexpected submission result.');
        }

        return new JsonResponse([
            'id' => $result->id,
            'status' => $result->status->value,
        ], Response::HTTP_ACCEPTED);
    }
}
