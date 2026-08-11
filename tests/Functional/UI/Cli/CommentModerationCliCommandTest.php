<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Functional\UI\Cli;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CommentModerationCliCommandTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    public function testItAddsListsReadsAndManuallyModeratesAComment(): void
    {
        $application = new Application(self::bootKernel());
        $this->createSchema();

        $add = new CommandTester($application->find('app:comments:add'));
        self::assertSame(0, $add->execute([
            '--publisher' => 'cli-site',
            '--source' => 'article-42',
            '--author-id' => 'cli-user',
            '--body' => 'Merci pour cet article.',
        ]));
        $added = $this->json($add);
        self::assertSame('pending', $added['status'] ?? null);
        self::assertIsString($added['id'] ?? null);

        $list = new CommandTester($application->find('app:comments:list'));
        self::assertSame(0, $list->execute(['--status' => 'pending']));
        $listed = $this->json($list);
        self::assertSame(1, $listed['total'] ?? null);
        self::assertSame('cli-site', $listed['items'][0]['publisher'] ?? null);

        $status = new CommandTester($application->find('app:comments:status'));
        self::assertSame(0, $status->execute(['id' => $added['id']]));
        $current = $this->json($status);
        self::assertSame($added['id'], $current['id'] ?? null);
        self::assertSame('pending', $current['status'] ?? null);

        $manual = new CommandTester($application->find('app:comments:moderate'));
        self::assertSame(0, $manual->execute([
            'id' => $added['id'],
            '--status' => 'rejected',
            '--reason' => 'cli_manual_test',
        ]));
        $moderated = $this->json($manual);
        self::assertSame('rejected', $moderated['status'] ?? null);
        self::assertSame('cli_manual_test', $moderated['moderationReason'] ?? null);
    }

    public function testItChecksLlmStatusAndRunsModerationWithoutPersisting(): void
    {
        $application = new Application(self::bootKernel());

        $status = new CommandTester($application->find('app:llm:status'));
        self::assertSame(0, $status->execute([]));
        $payload = $this->json($status);
        self::assertSame(false, $payload['configured'] ?? null);
        self::assertSame('manual_review_required', $payload['reason'] ?? null);

        $moderate = new CommandTester($application->find('app:llm:moderate'));
        self::assertSame(0, $moderate->execute(['--body' => 'Merci pour cet article.']));
        $decision = $this->json($moderate);
        self::assertSame('pending', $decision['status'] ?? null);
        self::assertSame('manual_review_required', $decision['reason'] ?? null);
    }

    public function testItRunsLlmModerationForAnExistingCommentSynchronously(): void
    {
        $application = new Application(self::bootKernel());
        $this->createSchema();

        $add = new CommandTester($application->find('app:comments:add'));
        self::assertSame(0, $add->execute([
            '--publisher' => 'cli-site',
            '--source' => 'article-llm',
            '--body' => 'Commentaire a reviser par le LLM.',
        ]));
        $added = $this->json($add);

        $moderate = new CommandTester($application->find('app:comments:moderate-llm'));
        self::assertSame(0, $moderate->execute(['id' => $added['id']]));
        $payload = $this->json($moderate);
        self::assertSame($added['id'], $payload['id'] ?? null);
        self::assertSame('pending', $payload['status'] ?? null);
        self::assertSame('manual_review_required', $payload['moderationReason'] ?? null);
    }

    public function testItRunsABoundedBatchAcrossArticles(): void
    {
        $application = new Application(self::bootKernel());
        $this->createSchema();

        foreach (['article-a', 'article-b', 'article-c'] as $source) {
            $add = new CommandTester($application->find('app:comments:add'));
            self::assertSame(0, $add->execute([
                '--publisher' => 'cli-site',
                '--source' => $source,
                '--body' => 'Commentaire a reviser.',
            ]));
        }

        $batch = new CommandTester($application->find('app:comments:moderate-batch'));
        self::assertSame(0, $batch->execute(['--limit' => '2']));
        $payload = $this->json($batch);

        self::assertSame(2, $payload['processed'] ?? null);
        self::assertSame(2, $payload['limit'] ?? null);
        self::assertCount(2, $payload['items'] ?? []);
        self::assertSame('manual_review_required', $payload['items'][0]['moderationReason'] ?? null);
        self::assertSame('manual_review_required', $payload['items'][1]['moderationReason'] ?? null);
    }

    public function testItListsPublishedCommentsForOneArticle(): void
    {
        $application = new Application(self::bootKernel());
        $this->createSchema();

        $first = $this->addComment($application, 'article-cli', 'Published for CLI article.');
        $second = $this->addComment($application, 'article-cli', 'Pending for CLI article.');
        $third = $this->addComment($application, 'article-other', 'Published for another article.');

        $manual = new CommandTester($application->find('app:comments:moderate'));
        self::assertSame(0, $manual->execute(['id' => $first, '--status' => 'published', '--reason' => 'allowed']));
        self::assertSame(0, $manual->execute(['id' => $third, '--status' => 'published', '--reason' => 'allowed']));

        $list = new CommandTester($application->find('app:comments:list'));
        self::assertSame(0, $list->execute(['--source' => 'article-cli', '--status' => 'published']));
        $payload = $this->json($list);

        self::assertSame(1, $payload['total'] ?? null);
        self::assertSame($first, $payload['items'][0]['id'] ?? null);
        self::assertSame('article-cli', $payload['items'][0]['source'] ?? null);
        self::assertSame('published', $payload['items'][0]['status'] ?? null);
        self::assertNotSame($second, $payload['items'][0]['id'] ?? null);
    }

    private function createSchema(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    /** @return array<string, mixed> */
    private function json(CommandTester $tester): array
    {
        $payload = json_decode(trim($tester->getDisplay()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    private function addComment(Application $application, string $source, string $body): string
    {
        $add = new CommandTester($application->find('app:comments:add'));
        self::assertSame(0, $add->execute([
            '--publisher' => 'cli-site',
            '--source' => $source,
            '--body' => $body,
        ]));
        $payload = $this->json($add);
        self::assertIsString($payload['id'] ?? null);

        return $payload['id'];
    }
}
