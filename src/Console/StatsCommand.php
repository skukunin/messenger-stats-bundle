<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Console;

use Skukunin\MessengerStatsBundle\Report\HealthStatus;
use Skukunin\MessengerStatsBundle\Report\StatsReport;
use Skukunin\MessengerStatsBundle\Report\StatsReportBuilder;
use Skukunin\MessengerStatsBundle\View\JsonReportView;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'messenger:stats', description: 'Show Messenger transport statistics, health status and problems')]
final class StatsCommand extends Command
{
    public const NAME = 'messenger:stats';
    public const FORMAT_TABLE = 'table';
    public const FORMAT_JSON = 'json';

    public function __construct(
        private readonly StatsReportBuilder $reports,
        private readonly JsonReportView $json,
        private readonly TableRenderer $table,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table or json', self::FORMAT_TABLE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $this->formatOf($input);
        if (!\in_array($format, [self::FORMAT_TABLE, self::FORMAT_JSON], true)) {
            $output->writeln(\sprintf('<error>Unknown format "%s". Expected "%s" or "%s".</error>', $format, self::FORMAT_TABLE, self::FORMAT_JSON));

            return self::INVALID;
        }

        $report = $this->reports->build();
        $this->renderIn($format, $report, $output);

        return $this->exitCodeOf($report);
    }

    private function formatOf(InputInterface $input): string
    {
        $format = $input->getOption('format');

        return \is_string($format) ? $format : '';
    }

    private function renderIn(string $format, StatsReport $report, OutputInterface $output): void
    {
        if (self::FORMAT_JSON === $format) {
            $output->writeln($this->jsonOf($report), OutputInterface::OUTPUT_RAW);

            return;
        }

        $this->table->render($report, $output);
    }

    private function jsonOf(StatsReport $report): string
    {
        return json_encode($this->json->render($report), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    private function exitCodeOf(StatsReport $report): int
    {
        return HealthStatus::Critical === $report->status ? self::FAILURE : self::SUCCESS;
    }
}
