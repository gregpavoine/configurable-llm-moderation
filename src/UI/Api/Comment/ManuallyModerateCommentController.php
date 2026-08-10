<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Api\Comment;

use Gsoi\CommentModeration\Application\Command\ManuallyModerateComment\ManuallyModerateCommentCommand;
use Gsoi\CommentModeration\Application\Query\CommentView;
use Gsoi\CommentModeration\UI\Api\HandleTrait;
use Nelmio\ApiDocBundle\Attribute\Security as ApiSecurity;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/comments/{id}/moderation', methods: ['POST'])]
#[IsGranted('ROLE_MODERATOR')]
#[ApiSecurity(name: 'Bearer')]
#[OA\Tag(name: 'Comments')]
final readonly class ManuallyModerateCommentController
{
    use HandleTrait;

    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    #[OA\Response(response: 200, description: 'Updated comment after the operator decision.')]
    #[OA\Response(response: 401, description: 'Authentication required.')]
    #[OA\Response(response: 403, description: 'Moderator role required.')]
    #[OA\Response(response: 404, description: 'Comment not found.')]
    #[OA\Response(response: 409, description: 'Comment already has a final decision.')]
    #[OA\Response(response: 422, description: 'Invalid moderation payload.')]
    public function __invoke(string $id, #[MapRequestPayload] ManualModerationRequest $request): JsonResponse
    {
        $reason = $request->reason ?? match ($request->status) {
            'published' => 'approved_by_operator',
            'rejected' => 'rejected_by_operator',
            default => throw new \LogicException('Unsupported manual moderation status.'),
        };

        $result = $this->handle($this->messageBus, new ManuallyModerateCommentCommand(
            $id,
            $request->status,
            $reason,
        ));
        if (!$result instanceof CommentView) {
            throw new \LogicException('Unexpected manual moderation result.');
        }

        return new JsonResponse($result->toArray());
    }
}
