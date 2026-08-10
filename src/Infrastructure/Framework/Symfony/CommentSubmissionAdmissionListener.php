<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Infrastructure\Framework\Symfony;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 31)]
final readonly class CommentSubmissionAdmissionListener
{
    private const int MAX_BODY_BYTES = 65_536;

    public function __construct(
        private RateLimiterFactoryInterface $clientLimiter,
        private RateLimiterFactoryInterface $globalLimiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ('comment_submit' !== $request->attributes->get('_route')) {
            return;
        }

        $this->enforceBodyLimit($request);

        $clientKey = hash('sha256', $request->getClientIp() ?? 'unknown');
        $this->enforceRateLimit($this->clientLimiter->create($clientKey)->consume());
        $this->enforceRateLimit($this->globalLimiter->create('global')->consume());
    }

    private function enforceBodyLimit(Request $request): void
    {
        $contentLength = $request->headers->get('Content-Length');
        if (null !== $contentLength && ctype_digit($contentLength) && (int) $contentLength > self::MAX_BODY_BYTES) {
            throw new HttpException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'Request body exceeds 65536 bytes.');
        }

        $content = stream_get_contents($request->getContent(true), self::MAX_BODY_BYTES + 1);
        if (false === $content || strlen($content) > self::MAX_BODY_BYTES) {
            throw new HttpException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'Request body exceeds 65536 bytes.');
        }
    }

    private function enforceRateLimit(RateLimit $limit): void
    {
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        throw new TooManyRequestsHttpException($retryAfter, 'Comment submission rate limit exceeded.');
    }
}
