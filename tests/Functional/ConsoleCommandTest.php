<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Console\StatsCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Filesystem\Filesystem;

final class ConsoleCommandTest extends TestCase
{
    private const ENV_TRANSPORT_DSN_VALUE = 'doctrine://default?queue_name=envq';

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $_SERVER[TestKernel::ENV_TRANSPORT_DSN] = self::ENV_TRANSPORT_DSN_VALUE;
        putenv(TestKernel::ENV_TRANSPORT_DSN.'='.self::ENV_TRANSPORT_DSN_VALUE);
    }

    protected function tearDown(): void
    {
        if (null !== $this->kernel) {
            $cacheDir = $this->kernel->getCacheDir();
            $this->kernel->shutdown();
            (new Filesystem())->remove($cacheDir);
            $this->kernel = null;
        }

        unset($_SERVER[TestKernel::ENV_TRANSPORT_DSN]);
        putenv(TestKernel::ENV_TRANSPORT_DSN);
    }

    public function testTheCommandIsRegisteredUnderItsNameWithItsDescription(): void
    {
        $command = $this->application()->find(StatsCommand::NAME);

        self::assertSame(StatsCommand::NAME, $command->getName());
        self::assertSame('Show Messenger transport statistics, health status and problems', $command->getDescription());
        self::assertSame([], $command->getAliases());
    }

    private function application(): Application
    {
        $this->kernel ??= new TestKernel();
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        return $application;
    }

    public function testTheJsonFormatReportsEveryDiscoveredTransportAtItsDetailLevel(): void
    {
        $document = $this->runJson();
        $transports = $this->arrayOf($document['transports']);

        self::assertSame('1', $document['schema_version']);
        self::assertSame('full', $this->arrayOf($transports['async'])['detail_level']);
        self::assertSame('full', $this->arrayOf($transports['failed'])['detail_level']);
        self::assertSame('unavailable', $this->arrayOf($transports['broken'])['detail_level']);
        self::assertStringStartsWith('Doctrine\\', $this->stringOf($this->arrayOf($transports['broken'])['error']));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function runJson(): array
    {
        $tester = new ApplicationTester($this->application());

        self::assertSame(Command::SUCCESS, $tester->run(
            ['command' => StatsCommand::NAME, '--format' => 'json'],
            ['capture_stderr_separately' => true],
        ));

        return $this->arrayOf(json_decode($tester->getDisplay(), true));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arrayOf(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    private function stringOf(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    public function testTheTableFormatRendersTheTransportsAndTheProblems(): void
    {
        $tester = new ApplicationTester($this->application());

        self::assertSame(Command::SUCCESS, $tester->run(
            ['command' => StatsCommand::NAME],
            ['capture_stderr_separately' => true],
        ));

        $display = $tester->getDisplay();
        self::assertStringContainsString('Status: warning', $display);
        self::assertStringContainsString('Oldest pending (s)', $display);
        self::assertStringContainsString('unavailable: Doctrine\\', $display);
        self::assertStringContainsString('up', $display);
    }
}
