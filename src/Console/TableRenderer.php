<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Console;

use DateTimeImmutable;
use DateTimeZone;
use Skukunin\MessengerStatsBundle\Report\DetailLevel;
use Skukunin\MessengerStatsBundle\Report\FailedMessage;
use Skukunin\MessengerStatsBundle\Report\HealthStatus;
use Skukunin\MessengerStatsBundle\Report\Problem;
use Skukunin\MessengerStatsBundle\Report\QueueStats;
use Skukunin\MessengerStatsBundle\Report\StatsReport;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

final class TableRenderer
{
    private const UNKNOWN = '-';
    private const TRANSPORT_HEADERS = ['Transport', 'Kind', 'Stats', 'Queue', 'Pending', 'Delayed', 'In progress', 'Stuck', 'Oldest pending (s)', 'Count'];
    private const FAILURE_HEADERS = ['Message class', 'Exception', 'Message', 'Failed at', 'Retries', 'Original transport'];
    private const PROBLEM_HEADERS = ['Level', 'Transport', 'Metric', 'Value', 'Threshold'];

    public function render(StatsReport $report, OutputInterface $output): void
    {
        $this->renderHeadline($report, $output);
        $this->renderTransports($report, $output);
        $this->renderFailures($report, $output);
        $this->renderProblems($report, $output);
    }

    private function renderHeadline(StatsReport $report, OutputInterface $output): void
    {
        $output->writeln(\sprintf(
            'App: %s  Env: %s  Generated: %s  Status: %s',
            $report->app,
            $report->env,
            $this->dateOf($report->generatedAt),
            $this->statusOf($report->status),
        ));
        $output->writeln('');
    }

    private function dateOf(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format(\DATE_ATOM);
    }

    private function statusOf(HealthStatus $status): string
    {
        $color = match ($status) {
            HealthStatus::Ok => 'green',
            HealthStatus::Warning => 'yellow',
            HealthStatus::Critical => 'red',
        };

        return \sprintf('<fg=%s>%s</>', $color, $status->value);
    }

    private function renderTransports(StatsReport $report, OutputInterface $output): void
    {
        $rows = [];
        foreach ($report->transports as $transport) {
            foreach ($this->rowsOf($transport) as $row) {
                $rows[] = $row;
            }
        }

        $this->renderTable(self::TRANSPORT_HEADERS, $rows, $output);
    }

    /**
     * @return list<list<string>>
     */
    private function rowsOf(TransportStats $transport): array
    {
        return match ($transport->detailLevel) {
            DetailLevel::Full => $this->queueRowsOf($transport),
            DetailLevel::Count => [$this->countRowOf($transport)],
            DetailLevel::Unavailable => [$this->unavailableRowOf($transport)],
        };
    }

    /**
     * @return list<list<string>>
     */
    private function queueRowsOf(TransportStats $transport): array
    {
        if ([] === $transport->queues) {
            return [$this->countRowOf($transport)];
        }

        $rows = [];
        foreach ($transport->queues as $index => $queue) {
            $rows[] = $this->queueRowOf($transport, $queue, 0 === $index);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function countRowOf(TransportStats $transport): array
    {
        return array_merge(
            [$transport->name, $transport->kind, $this->statsLabelOf($transport->detailLevel), self::UNKNOWN],
            $this->unknownStates(),
            [$this->numberOf($transport->count)],
        );
    }

    /**
     * @return list<string>
     */
    private function unknownStates(): array
    {
        return array_fill(0, 5, self::UNKNOWN);
    }

    private function numberOf(?int $value): string
    {
        return null === $value ? self::UNKNOWN : (string) $value;
    }

    private function statsLabelOf(DetailLevel $detailLevel): string
    {
        return DetailLevel::Count === $detailLevel ? 'count only' : $detailLevel->value;
    }

    /**
     * @return list<string>
     */
    private function queueRowOf(TransportStats $transport, QueueStats $queue, bool $isFirstQueue): array
    {
        return [
            $isFirstQueue ? $transport->name : '',
            $transport->kind,
            $this->statsLabelOf($transport->detailLevel),
            $queue->name,
            (string) $queue->pending,
            (string) $queue->delayed,
            (string) $queue->inProgress,
            (string) $queue->stuck,
            $this->numberOf($queue->oldestPendingAgeSeconds),
            $this->numberOf($transport->count),
        ];
    }

    /**
     * @return list<string>
     */
    private function unavailableRowOf(TransportStats $transport): array
    {
        return array_merge(
            [$transport->name, $transport->kind, $this->statsLabelOf($transport->detailLevel), 'unavailable: '.$transport->error],
            $this->unknownStates(),
            [''],
        );
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    private function renderTable(array $headers, array $rows, OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();
        $output->writeln('');
    }

    private function renderFailures(StatsReport $report, OutputInterface $output): void
    {
        foreach ($report->transports as $transport) {
            if ($this->hasFailures($transport)) {
                $this->renderFailuresOf($transport, $output);
            }
        }
    }

    private function hasFailures(TransportStats $transport): bool
    {
        return DetailLevel::Full === $transport->detailLevel && $transport->isFailureTransport && [] !== $transport->failures;
    }

    private function renderFailuresOf(TransportStats $transport, OutputInterface $output): void
    {
        $output->writeln(\sprintf('Failures on %s', $transport->name));

        $this->renderTable(self::FAILURE_HEADERS, array_map($this->failureRowOf(...), $transport->failures), $output);
    }

    /**
     * @return list<string>
     */
    private function failureRowOf(FailedMessage $failure): array
    {
        return [
            $failure->messageClass,
            $this->textOf($failure->exceptionClass),
            $this->textOf($failure->exceptionMessage),
            null === $failure->failedAt ? self::UNKNOWN : $this->dateOf($failure->failedAt),
            (string) $failure->retryCount,
            $this->textOf($failure->originalTransport),
        ];
    }

    private function textOf(?string $value): string
    {
        return null === $value || '' === $value ? self::UNKNOWN : $value;
    }

    private function renderProblems(StatsReport $report, OutputInterface $output): void
    {
        if ([] === $report->problems) {
            $output->writeln('No problems.');

            return;
        }

        $output->writeln('Problems');

        $this->renderTable(self::PROBLEM_HEADERS, array_map($this->problemRowOf(...), $report->problems), $output);
    }

    /**
     * @return list<string>
     */
    private function problemRowOf(Problem $problem): array
    {
        return [
            $problem->level->value,
            $problem->transport,
            $problem->metric,
            (string) $problem->value,
            (string) $problem->threshold,
        ];
    }
}
