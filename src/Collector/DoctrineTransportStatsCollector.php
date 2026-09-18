<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Collector;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ConnectionRegistry;
use Exception;
use Skukunin\MessengerStatsBundle\Clock\Clock;
use Skukunin\MessengerStatsBundle\Exception\InvalidArgumentException;
use Skukunin\MessengerStatsBundle\Report\FailedMessage;
use Skukunin\MessengerStatsBundle\Report\QueueStats;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Transport\DoctrineDsnParser;
use Skukunin\MessengerStatsBundle\Transport\DoctrineTransportSettings;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinition;
use Skukunin\MessengerStatsBundle\Transport\TransportKind;

final class DoctrineTransportStatsCollector implements StatsCollector
{
    public function __construct(
        private readonly ConnectionRegistry $connections,
        private readonly DoctrineDsnParser $dsnParser,
        private readonly MessageRowDecoder $messages,
        private readonly Clock $clock,
        private readonly ?int $stuckAfterSeconds,
        private readonly int $classBreakdownSampleSize,
        private readonly int $failuresLimit,
    ) {
    }

    public function supports(TransportDefinition $definition): bool
    {
        return TransportKind::DOCTRINE === $definition->kind;
    }

    public function collect(TransportDefinition $definition): TransportStats
    {
        $settings = $this->dsnParser->parse($definition);
        $connection = $this->connectionOf($settings->connectionName);

        try {
            return TransportStats::full(
                $definition->name,
                $definition->kind,
                $definition->isFailureTransport,
                [$this->queueStatsOf($connection, $settings, $definition->name)],
                $definition->isFailureTransport ? $this->failuresOf($connection, $settings, $definition->name) : [],
            );
        } catch (TableNotFoundException) {
            return TransportStats::full($definition->name, $definition->kind, $definition->isFailureTransport, [$this->emptyQueueStatsOf($settings)], []);
        }
    }

    private function connectionOf(string $name): Connection
    {
        $connection = $this->connections->getConnection($name);
        if (!$connection instanceof Connection) {
            throw new InvalidArgumentException(\sprintf('Doctrine connection "%s" is not a "%s".', $name, Connection::class));
        }

        return $connection;
    }

    private function queueStatsOf(Connection $connection, DoctrineTransportSettings $settings, string $transportName): QueueStats
    {
        $now = $this->clock->now();
        $stuckSince = $this->stuckSince($now, $settings);

        $pending = $this->countOf($connection, $settings, 'delivered_at IS NULL AND available_at <= ?', [$now]);
        $delayed = $this->countOf($connection, $settings, 'delivered_at IS NULL AND available_at > ?', [$now]);
        $inProgress = $this->countOf($connection, $settings, 'delivered_at IS NOT NULL AND delivered_at > ?', [$stuckSince]);
        $stuck = $this->countOf($connection, $settings, 'delivered_at IS NOT NULL AND delivered_at <= ?', [$stuckSince]);
        $total = $pending + $delayed + $inProgress + $stuck;

        return new QueueStats(
            $settings->queueName,
            $pending,
            $delayed,
            $inProgress,
            $stuck,
            $this->oldestPendingAgeSecondsOf($connection, $settings, $now),
            $this->classBreakdownOf($connection, $settings, $transportName),
            $total > $this->classBreakdownSampleSize,
        );
    }

    private function stuckSince(DateTimeImmutable $now, DoctrineTransportSettings $settings): DateTimeImmutable
    {
        return $now->sub(new DateInterval(\sprintf('PT%dS', $this->stuckAfterSeconds ?? $settings->redeliverTimeout)));
    }

    /**
     * @param list<DateTimeImmutable> $parameters
     */
    private function countOf(Connection $connection, DoctrineTransportSettings $settings, string $condition, array $parameters): int
    {
        $sql = \sprintf('SELECT COUNT(*) FROM %s WHERE queue_name = ? AND %s', $this->tableOf($connection, $settings), $condition);
        $count = $connection->executeQuery($sql, [$settings->queueName, ...$parameters], $this->parameterTypes($parameters))->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function tableOf(Connection $connection, DoctrineTransportSettings $settings): string
    {
        return $connection->quoteIdentifier($settings->tableName);
    }

    /**
     * @param list<DateTimeImmutable> $parameters
     *
     * @return list<string>
     */
    private function parameterTypes(array $parameters): array
    {
        return [Types::STRING, ...array_fill(0, \count($parameters), Types::DATETIME_IMMUTABLE)];
    }

    private function oldestPendingAgeSecondsOf(Connection $connection, DoctrineTransportSettings $settings, DateTimeImmutable $now): ?int
    {
        $sql = \sprintf('SELECT MIN(available_at) FROM %s WHERE queue_name = ? AND delivered_at IS NULL AND available_at <= ?', $this->tableOf($connection, $settings));
        $oldest = $connection->executeQuery($sql, [$settings->queueName, $now], $this->parameterTypes([$now]))->fetchOne();
        if (!\is_string($oldest) || '' === $oldest) {
            return null;
        }

        $availableAt = $this->utcTimestampOf($oldest);

        return null === $availableAt ? null : max(0, $now->getTimestamp() - $availableAt->getTimestamp());
    }

    private function utcTimestampOf(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @return array<string, int>
     */
    private function classBreakdownOf(Connection $connection, DoctrineTransportSettings $settings, string $transportName): array
    {
        $breakdown = [];
        foreach ($this->newestRows($connection, $settings, ['body', 'headers'], $this->classBreakdownSampleSize) as $row) {
            $messageClass = $this->messages->messageClass($transportName, $this->columnOf($row, 'body'), $this->columnOf($row, 'headers'));
            $breakdown[$messageClass] = ($breakdown[$messageClass] ?? 0) + 1;
        }

        return $breakdown;
    }

    /**
     * @param list<string> $columns
     *
     * @return list<array<string, mixed>>
     */
    private function newestRows(Connection $connection, DoctrineTransportSettings $settings, array $columns, int $limit): array
    {
        $query = $connection->createQueryBuilder()
            ->select(...$columns)
            ->from($this->tableOf($connection, $settings))
            ->where('queue_name = ?')
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit);

        return $connection->executeQuery($query->getSQL(), [$settings->queueName], [Types::STRING])->fetchAllAssociative();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function columnOf(array $row, string $name): string
    {
        $value = $row[$name] ?? $row[strtoupper($name)] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return list<FailedMessage>
     */
    private function failuresOf(Connection $connection, DoctrineTransportSettings $settings, string $transportName): array
    {
        $failures = [];
        foreach ($this->newestRows($connection, $settings, ['body', 'headers', 'created_at'], $this->failuresLimit) as $row) {
            $failures[] = $this->messages->failedMessage($transportName, $this->columnOf($row, 'body'), $this->columnOf($row, 'headers'), $this->columnOf($row, 'created_at'));
        }

        return $failures;
    }

    private function emptyQueueStatsOf(DoctrineTransportSettings $settings): QueueStats
    {
        return new QueueStats($settings->queueName, 0, 0, 0, 0, null, [], false);
    }
}
