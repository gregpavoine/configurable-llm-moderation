<?php

declare(strict_types=1);

namespace Gsoi\CommentModeration\Tests\Container;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class LaunchScriptTest extends TestCase
{
    #[Test]
    public function helpDocumentsTheSafeLaunchInterface(): void
    {
        $process = $this->runScript('--help');

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertStringContainsString('Usage: ./launch.sh [--no-build]', $process->getOutput());
        self::assertStringContainsString('Starts the complete local moderation stack', $process->getOutput());
    }

    #[Test]
    public function anUnknownOptionIsRejectedWithoutStartingDocker(): void
    {
        $process = $this->runScript('--unknown');

        self::assertSame(64, $process->getExitCode());
        self::assertStringContainsString('Unknown option: --unknown', $process->getErrorOutput());
        self::assertStringContainsString('Usage: ./launch.sh [--no-build]', $process->getErrorOutput());
    }

    #[Test]
    public function aPlaceholderSecretIsReplacedWithoutWarnings(): void
    {
        $sandbox = sys_get_temp_dir().'/comment-moderation-launch-'.bin2hex(random_bytes(6));
        $binaryDirectory = $sandbox.'/bin';
        self::assertTrue(mkdir($binaryDirectory, 0700, true));
        self::assertTrue(copy(dirname(__DIR__, 2).'/launch.sh', $sandbox.'/launch.sh'));
        self::assertTrue(copy(dirname(__DIR__, 2).'/.env.docker.example', $sandbox.'/.env.docker.example'));
        $environment = (string) file_get_contents($sandbox.'/.env.docker.example');
        $environment = str_replace(['API_PORT=8000', 'OLLAMA_HOST_PORT=11435'], ['API_PORT=18000', 'OLLAMA_HOST_PORT=21435'], $environment);
        self::assertNotFalse(file_put_contents($sandbox.'/.env.docker.example', $environment));
        $this->createExecutable($binaryDirectory.'/curl', "#!/bin/sh\nexit 0\n");
        $this->createExecutable($binaryDirectory.'/openssl', "#!/bin/sh\nprintf '%064d\\n' 0\n");
        $this->createExecutable($binaryDirectory.'/docker', <<<'SH'
#!/bin/sh
if [ "$1" = "inspect" ]; then
    printf '0\n'
    exit 0
fi
if [ "$1" = "info" ]; then
    exit 0
fi
case "$*" in
    *"ps --status running --services"*)
        printf 'php\nweb\nworker\n'
        ;;
esac
exit 0
SH);

        try {
            $process = new Process(
                ['bash', 'launch.sh', '--no-build'],
                $sandbox,
                ['PATH' => $binaryDirectory.':'.(getenv('PATH') ?: '/usr/bin:/bin')],
            );
            $process->setTimeout(10);
            $process->run();

            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame('', $process->getErrorOutput());
            self::assertStringContainsString(
                'APP_SECRET=0000000000000000000000000000000000000000000000000000000000000000',
                (string) file_get_contents($sandbox.'/.env.docker'),
            );
            self::assertStringContainsString('API:     http://127.0.0.1:18000', $process->getOutput());
            self::assertStringContainsString('Ollama:  http://127.0.0.1:21435', $process->getOutput());
        } finally {
            $this->removeDirectory($sandbox);
        }
    }

    private function runScript(string $argument): Process
    {
        $process = new Process(['bash', 'launch.sh', $argument], dirname(__DIR__, 2));
        $process->setTimeout(10);
        $process->run();

        return $process;
    }

    private function createExecutable(string $path, string $contents): void
    {
        self::assertNotFalse(file_put_contents($path, $contents));
        self::assertTrue(chmod($path, 0700));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
