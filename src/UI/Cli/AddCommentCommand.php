<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Cli;

use Gsoi\CommentModeration\Application\Command\SubmitComment\SubmitCommentCommand;
use Gsoi\CommentModeration\Application\Command\SubmitComment\SubmitCommentResult;
use Gsoi\CommentModeration\UI\Api\HandleTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:comments:add', description: 'Add a comment and enqueue it for moderation.')]
final class AddCommentCommand extends Command
{
    use HandleTrait;
    use JsonOutputTrait;

    public function __construct(private readonly MessageBusInterface $messageBus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('publisher', null, InputOption::VALUE_REQUIRED, 'Publisher identifier.')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source article or post identifier.')
            ->addOption('author-id', null, InputOption::VALUE_REQUIRED, 'Optional external author identifier.')
            ->addOption('body', null, InputOption::VALUE_REQUIRED, 'Comment body.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->handle($this->messageBus, new SubmitCommentCommand(
            $this->requiredOption($input, 'publisher'),
            $this->requiredOption($input, 'source'),
            $this->nullableOption($input, 'author-id'),
            $this->requiredOption($input, 'body'),
        ));

        if (!$result instanceof SubmitCommentResult) {
            throw new \LogicException('Unexpected submission result.');
        }

        $this->writeJson($output, [
            'id' => $result->id,
            'status' => $result->status->value,
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

    private function nullableOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && '' !== trim($value) ? $value : null;
    }
}
