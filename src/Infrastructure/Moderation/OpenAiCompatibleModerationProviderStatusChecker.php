<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Infrastructure\Moderation;

use Gsoi\CommentModeration\Domain\Moderation\ModerationProviderStatus;
use Gsoi\CommentModeration\Domain\Moderation\ModerationProviderStatusChecker;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OpenAiCompatibleModerationProviderStatusChecker implements ModerationProviderStatusChecker
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ModerationLlmConfig $config,
    ) {
    }

    public function check(): ModerationProviderStatus
    {
        if (!$this->config->isConfigured()) {
            return new ModerationProviderStatus(
                false,
                false,
                $this->config->deferredReason() ?? 'llm_misconfigured',
                $this->config->providerHost(),
                $this->config->model(),
            );
        }

        $endpoint = $this->config->modelsEndpoint();
        if (null === $endpoint) {
            return new ModerationProviderStatus(true, false, 'llm_misconfigured', $this->config->providerHost(), $this->config->model());
        }

        $options = [
            'timeout' => $this->config->timeout(),
            'max_duration' => $this->config->timeout(),
            'max_redirects' => 0,
            'no_proxy' => '*',
        ];
        if ('' !== $this->config->apiKey()) {
            $options['headers'] = ['Authorization: Bearer '.$this->config->apiKey()];
        }

        try {
            $response = $this->httpClient->request('GET', $endpoint, $options);
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface) {
            return new ModerationProviderStatus(true, false, 'llm_unavailable', $this->config->providerHost(), $this->config->model());
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            return new ModerationProviderStatus(true, false, 'llm_unavailable', $this->config->providerHost(), $this->config->model());
        }

        return new ModerationProviderStatus(true, true, null, $this->config->providerHost(), $this->config->model());
    }
}
