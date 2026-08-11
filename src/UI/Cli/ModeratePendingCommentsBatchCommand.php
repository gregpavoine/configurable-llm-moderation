<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Cli;

use Gsoi\CommentModeration\Application\Command\ModeratePendingCommentsBatch\ModeratePendingCommentsBatchCommand as ApplicationCommand;
use Gsoi\CommentModeration\Application\Command\ModeratePendingCommentsBatch\ModeratePendingCommentsBatchResult;
use Gsoi\CommentModeration\UI\Api\HandleTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:comments:moderate-batch', description: 'Moderate a batch of pending comments across all sources.')]
final class ModeratePendingCommentsBatchCommand extends Command
{
    use HandleTrait;
    use JsonOutputTrait;

    public function __construct(private readonly MessageBusInterface $messageBus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of pending comments to moderate.', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->handle($this->messageBus, new ApplicationCommand($this->limit($input)));
        if (!$result instanceof ModeratePendingCommentsBatchResult) {
            throw new \LogicException('Unexpected batch moderation result.');
        }

        $this->writeJson($output, $result->toArray());

        return self::SUCCESS;
    }

    private function limit(InputInterface $input): int
    {
        $value = $input->getOption('limit');
        if (!is_string($value) || !ctype_digit($value)) {
            throw new \InvalidArgumentException('The --limit option must be a positive integer.');
        }

        return (int) $value;
    }
}
