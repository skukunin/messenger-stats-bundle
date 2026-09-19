<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\View;

use Skukunin\MessengerStatsBundle\Report\DetailLevel;
use Skukunin\MessengerStatsBundle\Report\QueueStats;
use Skukunin\MessengerStatsBundle\Report\StatsReport;
use Skukunin\MessengerStatsBundle\Report\TransportStats;

final class PrometheusReportView
{
    public function render(StatsReport $report): string
    {
        $labels = ['app' => $report->app, 'env' => $report->env];

        return $this->familyOf('messenger_transport_up', '1 when the transport could be read.', $this->transportUpSamples($report, $labels))
            .$this->familyOf('messenger_transport_messages', 'Total messages in the transport.', $this->transportMessagesSamples($report, $labels))
            .$this->familyOf('messenger_queue_messages', 'Messages per queue and state.', $this->queueMessagesSamples($report, $labels))
            .$this->familyOf('messenger_queue_oldest_pending_age_seconds', 'Age of the oldest pending message.', $this->queueOldestPendingAgeSamples($report, $labels))
            .$this->familyOf('messenger_queue_class_messages', 'Messages per class (sampled).', $this->queueClassSamples($report, $labels))
            .$this->familyOf('messenger_failed_messages', 'Messages in the failure transport.', $this->failedMessagesSamples($report, $labels))
            .$this->familyOf('messenger_failed_class_messages', 'Messages per class in the failure transport (sampled).', $this->failedClassSamples($report, $labels))
            .$this->familyOf('messenger_health_status', '0 ok, 1 warning, 2 critical.', [$this->sample($labels, $report->status->severity())]);
    }

    /**
     * @param list<array{labels: array<string, string>, value: int}> $samples
     */
    private function familyOf(string $name, string $help, array $samples): string
    {
        if ([] === $samples) {
            return '';
        }

        $family = '# HELP '.$name.' '.$help."\n".'# TYPE '.$name." gauge\n";
        foreach ($samples as $sample) {
            $family .= $name.$this->labelsOf($sample['labels']).' '.$sample['value']."\n";
        }

        return $family;
    }

    /**
     * @param array<string, string> $labels
     */
    private function labelsOf(array $labels): string
    {
        $pairs = [];
        foreach ($labels as $name => $value) {
            $pairs[] = $name.'="'.$this->escaped($value).'"';
        }

        return '{'.implode(',', $pairs).'}';
    }

    private function escaped(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\"', '\n'], $value);
    }

    /**
     * @param array<string, string> $labels
     *
     * @return list<array{labels: array<string, string>, value: int}>
     */
    private function transportUpSamples(StatsReport $report, array $labels): array
    {
        $samples = [];
        foreach ($report->transports as $transport) {
            $samples[] = $this->sample($labels + ['transport' => $transport->name], DetailLevel::Unavailable === $transport->detailLevel ? 0 : 1);
        }

        return $samples;
    }

    /**
     * @param array<string, string> $labels
     *
     * @return array{labels: array<string, string>, value: int}
     */
    private function sample(array $labels, int $value): array
    {
        return ['labels' => $labels, 'value' => $value];
    }

    /**
     * @param array<string, string> $labels
     *
     * @return list<array{labels: array<string, string>, value: int}>
     */
    private function transportMessagesSamples(StatsReport $report, array $labels): array
    {
        $samples = [];
        foreach ($report->transports as $transport) {
            if (null !== $transport->count) {
                $samples[] = $this->sample($labels + ['transport' => $transport->name], $transport->count);
            }
        }

        return $samples;
    }

    /**
     * @param array<string, string> $labels
     *
     * @return list<array{labels: array<string, string>, value: int}>
     */
    private function queueMessagesSamples(StatsReport $report, array $labels): array
    {
        $samples = [];
        foreach ($report->transports as $transport) {
            foreach ($transport->queues as $queue) {
                foreach ($this->statesOf($queue) as $state => $value) {
                    $samples[] = $this->sample($this->queueLabelsOf($labels, $transport, $queue) + ['state' => $state], $value);
                }
            }
        }

        return $samples;
    }

    /**
     * @return array<string, int>
     */
    private function statesOf(QueueStats $queue): array
    {
        return [
            'pending' => $queue->pending,
            'delayed' => $queue->delayed,
            'in_progress' => $queue->inProgress,
            'stuck' => $queue->stuck,
        ];
    }

    /**
     * @param array<string, string> $labels
     *
     * @return array<string, string>
     */
    private function queueLabelsOf(array $labels, TransportStats $transport, QueueStats $queue): array
    {
        return $labels + ['transport' => $transport->name, 'queue' => $queue->name];
    }

    /**
     * @param array<string, string> $labels
     *
     * @return list<array{labels: array<string, string>, value: int}>
     */
    private function queueOldestPendingAgeSamples(StatsReport $report, array $labels): array
    {
        $samples = [];
        foreach ($report->transports as $transport) {
            foreach ($transport->queues as $queue) {
                if (null !== $queue->oldestPendingAgeSeconds) {
                    $samples[] = $this->sample($this->queueLabelsOf($labels, $transport, $queue), $queue->oldestPendingAgeSeconds);
                }
            }
        }

        return $samples;
    }

    /**
     * @param array<string, string> $labels
     *
     * @return list<array{labels: array<string, string>, value: int}>
     */
    private function queueClassSamples(StatsReport $report, array $labels): array
    {
        $samples = [];
        foreach ($report->transports as $transport) {
            foreach ($transport->queues as $queue) {
                foreach ($queue->classBreakdown as $class => $count) {
                    $samples[] = $this->sample($this->queueLabelsOf($labels, $transport, $queue) + ['class' => $class], $count);
                }
            }
        }

        return $samples;
    }

    /**
     * @param array<string, string> $labels
     *
     * @return list<array{labels: array<string, string>, value: int}>
     */
    private function failedMessagesSamples(StatsReport $report, array $labels): array
    {
        $samples = [];
        foreach ($report->transports as $transport) {
            $failed = $transport->failedCount();
            if (null !== $failed) {
                $samples[] = $this->sample($labels + ['transport' => $transport->name], $failed);
            }
        }

        return $samples;
    }

    /**
     * @param array<string, string> $labels
     *
     * @return list<array{labels: array<string, string>, value: int}>
     */
    private function failedClassSamples(StatsReport $report, array $labels): array
    {
        $samples = [];
        foreach ($report->transports as $transport) {
            foreach ($transport->classBreakdown->counts ?? [] as $class => $count) {
                $samples[] = $this->sample($labels + ['transport' => $transport->name, 'class' => $class], $count);
            }
        }

        return $samples;
    }
}
