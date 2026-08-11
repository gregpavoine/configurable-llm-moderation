<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Cli;

use Gsoi\CommentModeration\Application\Command\ManuallyModerateComment\ManuallyModerateCommentCommand as ApplicationCommand;
use Gsoi\CommentModeration\Application\Query\CommentView;
use Gsoi\CommentModeration\UI\Api\HandleTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:comments:moderate', description: 'Manually publish or reject a pending comment.')]
final class ManuallyModerateCommentCommand extends Command
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
            ->addArgument('id', InputArgument::REQUIRED, 'Comment UUID.')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Final status: published or rejected.')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Decision reason.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->handle($this->messageBus, new ApplicationCommand(
            $this->commentId($input),
            $this->requiredOption($input, 'status'),
            $this->requiredOption($input, 'reason'),
        ));

        if (!$result instanceof CommentView) {
            throw new \LogicException('Unexpected manual moderation result.');
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

    private function requiredOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('The --%s option is required.', $name));
        }

        return $value;
    }
}
