<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Cli;

use Symfony\Component\Console\Output\OutputInterface;

trait JsonOutputTrait
{
    /** @param array<string, mixed> $payload */
    private function writeJson(OutputInterface $output, array $payload): void
    {
        $output->writeln(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
