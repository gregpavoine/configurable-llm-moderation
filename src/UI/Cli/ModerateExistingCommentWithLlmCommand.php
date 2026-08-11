<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Cli;

use Gsoi\CommentModeration\Application\Command\ModerateCommentNow\ModerateCommentNowCommand;
use Gsoi\CommentModeration\Application\Query\CommentView;
use Gsoi\CommentModeration\UI\Api\HandleTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:comments:moderate-llm', description: 'Run LLM moderation synchronously for an existing pending comment.')]
final class ModerateExistingCommentWithLlmCommand extends Command
{
    use HandleTrait;
    use JsonOutputTrait;

    public function __construct(private readonly MessageBusInterface $messageBus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Comment UUID.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->handle($this->messageBus, new ModerateCommentNowCommand($this->commentId($input)));
        if (!$result instanceof CommentView) {
            throw new \LogicException('Unexpected LLM moderation result.');
        }

        $this->writeJson($output, $result->toArray());

        return self::SUCCESS;
    }

    private function commentId(InputInterface $input): string
    {
        $id = $input->getArgument('id');
        if (!is_string($id)) {
            throw new \InvalidArgumentException('The id argument is required.');
        }

        return $id;
    }
}
