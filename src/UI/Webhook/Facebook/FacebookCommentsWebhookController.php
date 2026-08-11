<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Webhook\Facebook;

use Generator;
use Gsoi\CommentModeration\Application\Command\SubmitComment\SubmitCommentCommand;
use Gsoi\CommentModeration\Application\Command\SubmitComment\SubmitCommentResult;
use Gsoi\CommentModeration\UI\Api\HandleTrait;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/webhooks/facebook/comments', name: 'facebook_comments_webhook_')]
#[OA\Tag(name: 'Facebook Webhook')]
final readonly class FacebookCommentsWebhookController
{
    use HandleTrait;

    private const int MAX_BODY_BYTES = 65_536;

    public function __construct(
        private MessageBusInterface $messageBus,
        #[Autowire('%env(string:FACEBOOK_WEBHOOK_VERIFY_TOKEN)%')]
        private string $verifyToken,
        #[Autowire('%env(string:FACEBOOK_APP_SECRET)%')]
        private string $appSecret,
    ) {
    }

    #[Route('', name: 'verify', methods: ['GET', 'HEAD'])]
    #[OA\Response(response: 200, description: 'Facebook webhook verification challenge.')]
    #[OA\Response(response: 403, description: 'Invalid Facebook verify token.')]
    public function verify(Request $request): Response
    {
        if ('subscribe' !== $request->query->get('hub_mode', $request->query->get('hub.mode'))) {
            throw new BadRequestHttpException('Invalid Facebook verification mode.');
        }

        if ('' === $this->verifyToken || !hash_equals($this->verifyToken, (string) $request->query->get('hub_verify_token', $request->query->get('hub.verify_token')))) {
            throw new AccessDeniedHttpException('Invalid Facebook verification token.');
        }

        return new Response((string) $request->query->get('hub_challenge', $request->query->get('hub.challenge')));
    }

    #[Route('', name: 'receive', methods: ['POST'])]
    #[OA\Response(response: 200, description: 'Facebook comment events accepted.')]
    #[OA\Response(response: 400, description: 'Invalid Facebook payload.')]
    #[OA\Response(response: 401, description: 'Invalid Facebook signature.')]
    public function receive(Request $request): JsonResponse
    {
        $content = $request->getContent();
        if (strlen($content) > self::MAX_BODY_BYTES) {
            throw new HttpException(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'Facebook webhook payload exceeds 65536 bytes.');
        }

        $this->assertValidSignature($content, (string) $request->headers->get('X-Hub-Signature-256'));

        $payload = json_decode($content, true);
        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid Facebook JSON payload.');
        }
        $payload = $this->associativeArray($payload);

        $received = 0;
        $ignored = 0;
        foreach ($this->extractCommentEvents($payload) as $event) {
            if (null === $event) {
                ++$ignored;
                continue;
            }

            $result = $this->handle($this->messageBus, new SubmitCommentCommand(
                $event['publisher'],
                $event['source'],
                $event['authorId'],
                $event['body'],
            ));

            if (!$result instanceof SubmitCommentResult) {
                throw new \LogicException('Unexpected Facebook comment submission result.');
            }

            ++$received;
        }

        return new JsonResponse(['received' => $received, 'ignored' => $ignored]);
    }

    private function assertValidSignature(string $content, string $signature): void
    {
        if ('' === $this->appSecret) {
            throw new AccessDeniedHttpException('Facebook app secret is not configured.');
        }

        $expected = 'sha256='.hash_hmac('sha256', $content, $this->appSecret);
        if ('' === $signature || !hash_equals($expected, $signature)) {
            throw new UnauthorizedHttpException('Facebook', 'Invalid Facebook signature.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return Generator<int, array{publisher: string, source: string, authorId: ?string, body: string}|null, void, void>
     */
    private function extractCommentEvents(array $payload): Generator
    {
        foreach ($this->arrayList($payload, 'entry') as $entry) {
            foreach ($this->arrayList($entry, 'changes') as $change) {
                $value = $this->arrayValue($change, 'value');
                if ('feed' !== ($change['field'] ?? null) || null === $value) {
                    yield null;
                    continue;
                }

                yield $this->mapFeedChange($entry, $value);
            }
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $value
     *
     * @return array{publisher: string, source: string, authorId: ?string, body: string}|null
     */
    private function mapFeedChange(array $entry, array $value): ?array
    {
        if ('comment' !== ($value['item'] ?? null)) {
            return null;
        }

        $pageId = $this->stringValue($entry['id'] ?? null);
        $postId = $this->stringValue($value['post_id'] ?? null);
        $message = trim($this->stringValue($value['message'] ?? null) ?? '');
        if (null === $pageId || null === $postId || '' === $message) {
            return null;
        }

        $from = $value['from'] ?? null;
        $authorId = is_array($from) ? $this->stringValue($from['id'] ?? null) : null;

        return [
            'publisher' => 'facebook_page:'.$pageId,
            'source' => 'facebook_post:'.$postId,
            'authorId' => null === $authorId ? null : 'facebook_user:'.$authorId,
            'body' => $message,
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function arrayList(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            return [];
        }

        $items = [];
        foreach ($data[$key] as $item) {
            $item = $this->associativeArray($item);
            if ([] !== $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    private function arrayValue(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        $value = $this->associativeArray($value);

        return [] === $value ? null : $value;
    }

    /** @return array<string, mixed> */
    private function associativeArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
