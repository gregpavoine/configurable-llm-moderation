<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Cli;

use Gsoi\CommentModeration\Application\Query\CommentSearchResult;
use Gsoi\CommentModeration\Application\Query\CommentView;
use Gsoi\CommentModeration\Application\Query\SearchComments\SearchCommentsQuery;
use Gsoi\CommentModeration\UI\Api\HandleTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:comments:list', description: 'List comments with optional publisher and status filters.')]
final class ListCommentsCommand extends Command
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
            ->addOption('publisher', null, InputOption::VALUE_REQUIRED, 'Filter by publisher.')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by status: pending, published, rejected.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of comments.', '20')
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Pagination offset.', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->handle($this->messageBus, new SearchCommentsQuery(
            $this->nullableOption($input, 'publisher'),
            $this->nullableOption($input, 'status'),
            $this->intOption($input, 'limit'),
            $this->intOption($input, 'offset'),
        ));

        if (!$result instanceof CommentSearchResult) {
            throw new \LogicException('Unexpected search result.');
        }

        $this->writeJson($output, [
            'items' => array_map(static fn (CommentView $view): array => $view->toArray(), $result->items),
            'total' => $result->total,
            'limit' => $result->limit,
            'offset' => $result->offset,
        ]);

        return self::SUCCESS;
    }

    private function nullableOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && '' !== trim($value) ? $value : null;
    }

    private function intOption(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);
        if (!is_string($value) || !ctype_digit($value)) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be a positive integer.', $name));
        }

        return (int) $value;
    }
}
