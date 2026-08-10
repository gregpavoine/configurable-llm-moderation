<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Api;

use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/', methods: ['GET'])]
#[OA\Tag(name: 'Root')]
final readonly class RootController
{
    public function __construct(
        #[Autowire('%env(REVISION)%')]
        private string $revision,
    ) {
    }
    public function __invoke(): JsonResponse
    {
        $data = [
            'message' => 'Comment Moderation API.',
            'version' => $this->revision,
        ];

        return new JsonResponse($data, Response::HTTP_OK);
    }
}
