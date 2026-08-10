<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Infrastructure\Moderation;

final readonly class ModerationLlmConfig
{
    private const float DEFAULT_TIMEOUT = 10.0;

    private string $model;
    private string $apiKey;
    private float $timeout;
    private ?string $baseUrl;
    private ?string $endpoint;
    private ?string $providerHost;
    private ?string $deferredReason;

    public function __construct(string $baseUrl, string $model, string $apiKey, float $timeout)
    {
        $baseUrl = trim($baseUrl);
        $this->model = trim($model);
        $this->apiKey = trim($apiKey);
        $this->timeout = $timeout > 0.0 && is_finite($timeout) ? $timeout : self::DEFAULT_TIMEOUT;

        if ('' === $baseUrl && '' === $this->model && '' === $this->apiKey) {
            $this->baseUrl = null;
            $this->endpoint = null;
            $this->providerHost = null;
            $this->deferredReason = 'manual_review_required';

            return;
        }

        if ('' === $baseUrl || '' === $this->model) {
            $this->baseUrl = null;
            $this->endpoint = null;
            $this->providerHost = null;
            $this->deferredReason = 'llm_misconfigured';

            return;
        }

        $url = parse_url($baseUrl);
        if (!is_array($url)) {
            $this->baseUrl = null;
            $this->endpoint = null;
            $this->providerHost = null;
            $this->deferredReason = 'llm_misconfigured';

            return;
        }

        $scheme = $url['scheme'] ?? null;
        $host = $url['host'] ?? null;
        if (
            !is_string($scheme)
            || !is_string($host)
            || '' === $host
            || array_key_exists('user', $url)
            || array_key_exists('pass', $url)
        ) {
            $this->baseUrl = null;
            $this->endpoint = null;
            $this->providerHost = null;
            $this->deferredReason = 'llm_misconfigured';

            return;
        }

        $scheme = strtolower($scheme);
        $normalizedHost = strtolower(trim($host, '[]'));
        if ('https' !== $scheme && ('http' !== $scheme || !$this->isLoopback($normalizedHost))) {
            $this->baseUrl = null;
            $this->endpoint = null;
            $this->providerHost = null;
            $this->deferredReason = 'llm_misconfigured';

            return;
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->endpoint = $this->baseUrl.'/chat/completions';
        $this->providerHost = $normalizedHost;
        $this->deferredReason = null;
    }

    public function isConfigured(): bool
    {
        return null !== $this->endpoint;
    }

    public function endpoint(): ?string
    {
        return $this->endpoint;
    }

    public function modelsEndpoint(): ?string
    {
        return null === $this->baseUrl ? null : $this->baseUrl.'/models';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    public function providerHost(): ?string
    {
        return $this->providerHost;
    }

    public function deferredReason(): ?string
    {
        return $this->deferredReason;
    }

    private function isLoopback(string $host): bool
    {
        if ('localhost' === $host || '::1' === $host) {
            return true;
        }

        if (false === filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $binaryAddress = inet_pton($host);

        return false !== $binaryAddress && "\x7f" === $binaryAddress[0];
    }
}
