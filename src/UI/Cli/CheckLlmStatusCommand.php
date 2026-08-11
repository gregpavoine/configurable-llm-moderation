<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Cli;

use Gsoi\CommentModeration\Application\Query\CheckLlmStatus\CheckLlmStatusQuery;
use Gsoi\CommentModeration\Domain\Moderation\ModerationProviderStatus;
use Gsoi\CommentModeration\UI\Api\HandleTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:llm:status', description: 'Check the configured moderation LLM provider.')]
final class CheckLlmStatusCommand extends Command
{
    use HandleTrait;
    use JsonOutputTrait;

    public function __construct(private readonly MessageBusInterface $messageBus)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->handle($this->messageBus, new CheckLlmStatusQuery());
        if (!$result instanceof ModerationProviderStatus) {
            throw new \LogicException('Unexpected LLM status result.');
        }

        $this->writeJson($output, $result->toArray());

        return self::SUCCESS;
    }
}
