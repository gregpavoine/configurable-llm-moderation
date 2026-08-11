<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Cli;

use Gsoi\CommentModeration\Application\Command\ModerateText\ModerateTextCommand as ApplicationCommand;
use Gsoi\CommentModeration\Domain\Moderation\ModerationDecision;
use Gsoi\CommentModeration\UI\Api\HandleTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:llm:moderate', description: 'Run moderation for a raw text without persisting a comment.')]
final class ModerateTextCommand extends Command
{
    use HandleTrait;
    use JsonOutputTrait;

    public function __construct(private readonly MessageBusInterface $messageBus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('body', null, InputOption::VALUE_REQUIRED, 'Text to moderate.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->handle($this->messageBus, new ApplicationCommand($this->requiredOption($input, 'body')));
        if (!$result instanceof ModerationDecision) {
            throw new \LogicException('Unexpected moderation decision.');
        }

        $this->writeJson($output, [
            'status' => $result->status->value,
            'reason' => $result->reason,
        ]);

        return self::SUCCESS;
    }

    private function requiredOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('The --%s option is required.', $name));
        }

        return $value;
    }
}
