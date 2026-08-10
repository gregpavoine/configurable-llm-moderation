<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Application\Command\ModerateText;

use Gsoi\CommentModeration\Domain\Moderation\ModerationDecision;
use Gsoi\CommentModeration\Domain\Moderation\ModerationService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler]
final readonly class ModerateTextHandler
{
    public function __construct(
        private ModerationService $moderationService,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(ModerateTextCommand $command): ModerationDecision
    {
        $violations = $this->validator->validate($command);
        if ($violations->count() > 0) {
            throw new ValidationFailedException($command, $violations);
        }

        return $this->moderationService->moderate($command->body);
    }
}
