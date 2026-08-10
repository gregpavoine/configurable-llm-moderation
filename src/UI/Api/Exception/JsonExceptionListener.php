<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Api\Exception;

use Gsoi\CommentModeration\Domain\Comment\Exception\CommentNotFoundException;
use Gsoi\CommentModeration\Domain\Comment\Exception\InvalidModerationTransitionException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

#[AsEventListener(event: KernelEvents::EXCEPTION)]
final readonly class JsonExceptionListener
{
    public function __construct(
        #[Autowire('%kernel.debug%')]
        private bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $this->unwrap($event->getThrowable());

        if ($exception instanceof ValidationFailedException) {
            $violations = [];
            foreach ($exception->getViolations() as $violation) {
                $violations[] = [
                    'property' => $violation->getPropertyPath(),
                    'message' => (string) $violation->getMessage(),
                ];
            }

            $event->setResponse($this->response(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'validation_failed',
                'Request validation failed.',
                $violations,
            ));

            return;
        }

        if ($exception instanceof CommentNotFoundException) {
            $event->setResponse($this->response(
                Response::HTTP_NOT_FOUND,
                'comment_not_found',
                $exception->getMessage(),
            ));

            return;
        }

        if ($exception instanceof InvalidModerationTransitionException) {
            $event->setResponse($this->response(
                Response::HTTP_CONFLICT,
                'moderation_conflict',
                $exception->getMessage(),
            ));

            return;
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $response = $this->response(
                $status,
                $this->httpErrorCode($status),
                $exception->getMessage(),
            );
            $response->headers->add($exception->getHeaders());
            $event->setResponse($response);

            return;
        }

        $event->setResponse($this->response(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'internal_error',
            $this->debug ? $exception->getMessage() : 'An unexpected error occurred.',
        ));
    }

    private function unwrap(\Throwable $exception): \Throwable
    {
        while ($exception instanceof HandlerFailedException && null !== $exception->getPrevious()) {
            $exception = $exception->getPrevious();
        }

        if ($exception instanceof HttpExceptionInterface && $exception->getPrevious() instanceof ValidationFailedException) {
            return $exception->getPrevious();
        }

        return $exception;
    }

    /** @param list<array{property: string, message: string}> $violations */
    private function response(int $status, string $code, string $message, array $violations = []): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'violations' => $violations,
            ],
        ], $status);
    }

    private function httpErrorCode(int $status): string
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'bad_request',
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE => 'payload_too_large',
            Response::HTTP_TOO_MANY_REQUESTS => 'rate_limited',
            Response::HTTP_NOT_FOUND => 'not_found',
            Response::HTTP_METHOD_NOT_ALLOWED => 'method_not_allowed',
            default => 'http_error',
        };
    }
}
