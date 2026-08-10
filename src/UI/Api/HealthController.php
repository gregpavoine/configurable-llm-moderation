<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Api;

use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/health', methods: ['GET'])]
    #[OA\Tag(name: 'Health')]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
        ]);
    }
}
