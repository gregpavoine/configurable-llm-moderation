<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\UI\Cli;

use Lexik\Bundle\JWTAuthenticationBundle\Security\User\JWTUser;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

#[AsCommand(name: 'app:jwt:issue-moderator', description: 'Issue a short-lived moderator JWT.')]
#[When(env: 'dev')]
#[When(env: 'test')]
final class IssueModeratorTokenCommand extends Command
{
    public function __construct(private readonly JWTTokenManagerInterface $tokenManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Token subject identifier.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $subject = $input->getOption('subject');
        if (!is_string($subject)) {
            throw new \InvalidArgumentException('The --subject option must contain a non-empty identifier.');
        }
        $subject = trim($subject);
        if ('' === $subject) {
            throw new \InvalidArgumentException('The --subject option must contain a non-empty identifier.');
        }

        $user = JWTUser::createFromPayload($subject, ['roles' => ['ROLE_MODERATOR']]);
        $output->writeln($this->tokenManager->create($user));

        return self::SUCCESS;
    }
}
