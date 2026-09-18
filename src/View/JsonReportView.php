<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\View;

use DateTimeImmutable;
use DateTimeZone;
use Skukunin\MessengerStatsBundle\Report\DetailLevel;
use Skukunin\MessengerStatsBundle\Report\FailedMessage;
use Skukunin\MessengerStatsBundle\Report\QueueStats;
use Skukunin\MessengerStatsBundle\Report\StatsReport;
use Skukunin\MessengerStatsBundle\Report\TransportStats;

final class JsonReportView
{
    public function __construct(
        private readonly ProblemView $problems,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function render(StatsReport $report): array
    {
        return [
            'schema_version' => StatsReport::SCHEMA_VERSION,
            'bundle_version' => $report->bundleVersion,
            'generated_at' => $this->dateOf($report->generatedAt),
            'app' => $report->app,
            'env' => $report->env,
            'status' => $report->status->value,
            'problems' => $this->problems->render($report->problems),
            'transports' => $this->transportsOf($report),
        ];
    }

    private function dateOf(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format(\DATE_ATOM);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function transportsOf(StatsReport $report): array
    {
        $transports = [];
        foreach ($report->transports as $transport) {
            $transports[$transport->name] = $this->transportOf($transport);
        }

        return $transports;
    }

    /**
     * @return array<string, mixed>
     */
    private function transportOf(TransportStats $transport): array
    {
        $document = [
            'kind' => $transport->kind,
            'detail_level' => $transport->detailLevel->value,
            'is_failure_transport' => $transport->isFailureTransport,
            'count' => $transport->count,
        ];

        if (DetailLevel::Full === $transport->detailLevel) {
            $document['queues'] = $this->queuesOf($transport);
        }

        if (DetailLevel::Full === $transport->detailLevel && $transport->isFailureTransport) {
            $document['failures'] = $this->failuresOf($transport);
        }

        if (DetailLevel::Unavailable === $transport->detailLevel) {
            $document['error'] = $transport->error;
        }

        return $document;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function queuesOf(TransportStats $transport): array
    {
        $queues = [];
        foreach ($transport->queues as $queue) {
            $queues[$queue->name] = $this->queueOf($queue);
        }

        return $queues;
    }

    /**
     * @return array<string, mixed>
     */
    private function queueOf(QueueStats $queue): array
    {
        return [
            'pending' => $queue->pending,
            'delayed' => $queue->delayed,
            'in_progress' => $queue->inProgress,
            'stuck' => $queue->stuck,
            'oldest_pending_age_seconds' => $queue->oldestPendingAgeSeconds,
            'class_breakdown' => $queue->classBreakdown,
            'class_breakdown_sampled' => $queue->classBreakdownSampled,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function failuresOf(TransportStats $transport): array
    {
        return array_map($this->failureOf(...), $transport->failures);
    }

    /**
     * @return array<string, mixed>
     */
    private function failureOf(FailedMessage $failure): array
    {
        return [
            'message_class' => $failure->messageClass,
            'exception_class' => $failure->exceptionClass,
            'exception_message' => $failure->exceptionMessage,
            'failed_at' => null === $failure->failedAt ? null : $this->dateOf($failure->failedAt),
            'retry_count' => $failure->retryCount,
            'original_transport' => $failure->originalTransport,
        ];
    }
}
