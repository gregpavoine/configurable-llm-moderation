<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Infrastructure\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTFailureEventInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class JsonAuthenticationEntryPoint implements AuthenticationEntryPointInterface, AccessDeniedHandlerInterface, EventSubscriberInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized();
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return $this->error(Response::HTTP_FORBIDDEN, 'forbidden', 'Forbidden.');
    }

    public function onJwtFailure(JWTFailureEventInterface $event): void
    {
        $event->setResponse($this->unauthorized());
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Events::JWT_EXPIRED => 'onJwtFailure',
            Events::JWT_INVALID => 'onJwtFailure',
            Events::JWT_NOT_FOUND => 'onJwtFailure',
        ];
    }

    private function unauthorized(): JsonResponse
    {
        return $this->error(
            Response::HTTP_UNAUTHORIZED,
            'unauthorized',
            'Authentication required.',
            ['WWW-Authenticate' => 'Bearer'],
        );
    }

    /** @param array<string, string> $headers */
    private function error(int $status, string $code, string $message, array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'violations' => [],
            ],
        ], $status, $headers);
    }
}
