<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Functional\UI\Cli;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class IssueModeratorTokenCommandTest extends KernelTestCase
{
    public function testItIsNotRegisteredInProduction(): void
    {
        $application = new Application(self::bootKernel(['environment' => 'prod', 'debug' => false]));

        self::assertFalse($application->has('app:jwt:issue-moderator'));
    }

    public function testItIssuesAnRs256ModeratorTokenForFifteenMinutes(): void
    {
        $application = new Application(self::bootKernel());
        self::assertTrue($application->has('app:jwt:issue-moderator'));
        $tester = new CommandTester($application->find('app:jwt:issue-moderator'));

        self::assertSame(0, $tester->execute(['--subject' => 'operator@example.test']));

        $token = trim($tester->getDisplay());
        self::assertSame($token.PHP_EOL, $tester->getDisplay());
        $segments = explode('.', $token);
        self::assertCount(3, $segments);
        $header = json_decode((string) base64_decode(strtr($segments[0], '-_', '+/'), true), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($header);
        self::assertSame('RS256', $header['alg'] ?? null);

        $manager = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $manager);
        $payload = $manager->parse($token);
        self::assertSame('operator@example.test', $payload['username'] ?? null);
        self::assertSame(['ROLE_MODERATOR'], $payload['roles'] ?? null);
        self::assertSame(900, ($payload['exp'] ?? 0) - ($payload['iat'] ?? 0));
    }

    public function testItRejectsAnEmptySubject(): void
    {
        $application = new Application(self::bootKernel());
        self::assertTrue($application->has('app:jwt:issue-moderator'));
        $tester = new CommandTester($application->find('app:jwt:issue-moderator'));

        $this->expectException(\InvalidArgumentException::class);
        $tester->execute(['--subject' => '   ']);
    }
}
