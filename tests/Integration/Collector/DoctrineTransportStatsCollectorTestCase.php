<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Integration\Collector;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ConnectionRegistry;
use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Collector\DoctrineTransportStatsCollector;
use Skukunin\MessengerStatsBundle\Collector\EnvelopeDecoder;
use Skukunin\MessengerStatsBundle\Collector\HeadersDecoder;
use Skukunin\MessengerStatsBundle\Collector\MessageRowDecoder;
use Skukunin\MessengerStatsBundle\Collector\StorageDateTimeParser;
use Skukunin\MessengerStatsBundle\Report\DetailLevel;
use Skukunin\MessengerStatsBundle\Report\QueueStats;
use Skukunin\MessengerStatsBundle\Report\TransportStats;
use Skukunin\MessengerStatsBundle\Tests\Support\FixedClock;
use Skukunin\MessengerStatsBundle\Tests\Support\Message\RebuildIndex;
use Skukunin\MessengerStatsBundle\Tests\Support\Message\SendInvoice;
use Skukunin\MessengerStatsBundle\Transport\DoctrineDsnParser;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinition;
use Skukunin\MessengerStatsBundle\Transport\TransportKind;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as MessengerConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

abstract class DoctrineTransportStatsCollectorTestCase extends TestCase
{
    protected const TABLE = 'messenger_messages';
    protected const TRANSPORT = 'async';
    private const LOCAL_TIMEZONE = 'Europe/Berlin';
    private const NAIVE_FORMAT = 'Y-m-d H:i:s';

    protected DbalConnection $database;

    private DateTimeImmutable $now;

    private string $defaultTimezone;

    protected function setUp(): void
    {
        $this->defaultTimezone = date_default_timezone_get();
        $this->database = self::openDatabase();
        $this->database->executeStatement('DROP TABLE IF EXISTS '.$this->database->quoteIdentifier(self::TABLE));
        $this->now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->add(new DateInterval('PT60S'));
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);
    }

    private static function openDatabase(): DbalConnection
    {
        $url = getenv('DATABASE_URL');
        if (!\is_string($url) || '' === $url) {
            return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        }

        if (!class_exists(DsnParser::class)) {
            return DriverManager::getConnection(['url' => $url]);
        }

        return DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql']))->parse($url));
    }

    public function testDoctrineTransportsAreSupported(): void
    {
        self::assertTrue($this->collector()->supports($this->definition()));
        self::assertFalse($this->collector()->supports($this->definition('amqp://guest@localhost/%2f/messages')));
    }

    protected function collector(?int $stuckAfterSeconds = 300, int $sampleSize = 1000, int $failuresLimit = 10, bool $exposeMessage = true, string $storageTimezone = 'UTC'): DoctrineTransportStatsCollector
    {
        $connections = $this->createMock(ConnectionRegistry::class);
        $connections->method('getConnection')->willReturn($this->database);
        $dateTimes = new StorageDateTimeParser($storageTimezone);

        return new DoctrineTransportStatsCollector(
            $connections,
            new DoctrineDsnParser(),
            $this->rowDecoder($dateTimes, $exposeMessage),
            $dateTimes,
            new FixedClock($this->now),
            $stuckAfterSeconds,
            $sampleSize,
            $failuresLimit,
        );
    }

    private function rowDecoder(StorageDateTimeParser $dateTimes, bool $exposeMessage): MessageRowDecoder
    {
        $serializers = new ServiceLocator([self::TRANSPORT => fn (): SerializerInterface => $this->serializer()]);

        return new MessageRowDecoder(new HeadersDecoder($dateTimes, $exposeMessage), new EnvelopeDecoder($serializers, $dateTimes, $exposeMessage));
    }

    abstract protected function serializer(): SerializerInterface;

    protected function definition(string $dsn = 'doctrine://default', bool $isFailureTransport = false): TransportDefinition
    {
        return new TransportDefinition(self::TRANSPORT, $dsn, TransportKind::fromDsn($dsn), [], $isFailureTransport, 'messenger.default_serializer');
    }

    public function testAnEmptyTransportIsReportedWithZeroes(): void
    {
        $this->transport();

        $stats = $this->collector()->collect($this->definition());

        self::assertSame(DetailLevel::Full, $stats->detailLevel);
        self::assertSame(self::TRANSPORT, $stats->name);
        self::assertSame(TransportKind::DOCTRINE, $stats->kind);
        self::assertSame(0, $stats->count);
        self::assertSame([], $stats->failures);
        $this->assertZeroQueue($this->onlyQueue($stats), 'default');
    }

    protected function transport(string $queueName = 'default'): DoctrineTransport
    {
        $connection = new MessengerConnection(
            ['table_name' => self::TABLE, 'queue_name' => $queueName, 'auto_setup' => true],
            $this->database,
        );
        $connection->setup();

        return new DoctrineTransport($connection, $this->serializer());
    }

    private function assertZeroQueue(QueueStats $queue, string $name): void
    {
        self::assertSame($name, $queue->name);
        self::assertSame(0, $queue->pending);
        self::assertSame(0, $queue->delayed);
        self::assertSame(0, $queue->inProgress);
        self::assertSame(0, $queue->stuck);
        self::assertNull($queue->oldestPendingAgeSeconds);
        self::assertSame([], $queue->classBreakdown);
        self::assertFalse($queue->classBreakdownSampled);
    }

    protected function onlyQueue(TransportStats $stats): QueueStats
    {
        self::assertCount(1, $stats->queues);

        return $stats->queues[0];
    }

    public function testDelayedMessagesAreNotCountedAsPending(): void
    {
        $transport = $this->transport();
        $this->send($transport, new SendInvoice());
        $this->send($transport, new SendInvoice());
        $this->send($transport, new RebuildIndex(), new DelayStamp(3_600_000));

        $queue = $this->onlyQueue($this->collector()->collect($this->definition()));

        self::assertSame(2, $queue->pending);
        self::assertSame(1, $queue->delayed);
        self::assertSame(0, $queue->inProgress);
        self::assertSame(0, $queue->stuck);
    }

    protected function send(DoctrineTransport $transport, object $message, StampInterface ...$stamps): int
    {
        $stamp = $transport->send(new Envelope($message, $stamps))->last(TransportMessageIdStamp::class);
        self::assertInstanceOf(TransportMessageIdStamp::class, $stamp);

        $id = $stamp->getId();
        self::assertIsNumeric($id);

        return (int) $id;
    }

    public function testDeliveredMessagesAreInProgressUntilTheStuckThreshold(): void
    {
        $transport = $this->transport();
        $this->markDelivered($this->send($transport, new SendInvoice()), 10);
        $this->markDelivered($this->send($transport, new SendInvoice()), 301);

        $queue = $this->onlyQueue($this->collector(300)->collect($this->definition()));

        self::assertSame(0, $queue->pending);
        self::assertSame(1, $queue->inProgress);
        self::assertSame(1, $queue->stuck);
    }

    private function markDelivered(int $id, int $secondsAgo): void
    {
        $this->database->executeStatement(
            'UPDATE '.$this->database->quoteIdentifier(self::TABLE).' SET delivered_at = ? WHERE id = ?',
            [$this->secondsAgo($secondsAgo), $id],
            [Types::DATETIME_IMMUTABLE, Types::INTEGER],
        );
    }

    private function secondsAgo(int $seconds): DateTimeImmutable
    {
        return $this->now->sub(new DateInterval(\sprintf('PT%dS', $seconds)));
    }

    public function testTheStuckThresholdFallsBackToTheRedeliverTimeout(): void
    {
        $transport = $this->transport();
        $this->markDelivered($this->send($transport, new SendInvoice()), 61);
        $this->markDelivered($this->send($transport, new SendInvoice()), 59);

        $queue = $this->onlyQueue($this->collector(null)->collect($this->definition('doctrine://default?redeliver_timeout=60')));

        self::assertSame(1, $queue->stuck);
        self::assertSame(1, $queue->inProgress);
    }

    public function testTheConfiguredStuckThresholdOverridesTheRedeliverTimeout(): void
    {
        $transport = $this->transport();
        $this->markDelivered($this->send($transport, new SendInvoice()), 61);
        $this->markDelivered($this->send($transport, new SendInvoice()), 59);

        $queue = $this->onlyQueue($this->collector(3600)->collect($this->definition('doctrine://default?redeliver_timeout=60')));

        self::assertSame(0, $queue->stuck);
        self::assertSame(2, $queue->inProgress);
    }

    public function testOldestPendingAgeIsMeasuredOnTheOldestAvailableMessage(): void
    {
        $transport = $this->transport();
        $this->makeAvailable($this->send($transport, new SendInvoice()), 900);
        $this->makeAvailable($this->send($transport, new SendInvoice()), 100);

        $queue = $this->onlyQueue($this->collector()->collect($this->definition()));

        self::assertSame(2, $queue->pending);
        self::assertSame(900, $queue->oldestPendingAgeSeconds);
    }

    private function makeAvailable(int $id, int $secondsAgo): void
    {
        $this->database->executeStatement(
            'UPDATE '.$this->database->quoteIdentifier(self::TABLE).' SET available_at = ? WHERE id = ?',
            [$this->secondsAgo($secondsAgo), $id],
            [Types::DATETIME_IMMUTABLE, Types::INTEGER],
        );
    }

    public function testOldestPendingAgeIgnoresDelayedAndDeliveredMessages(): void
    {
        $transport = $this->transport();
        $this->makeAvailable($this->markedDelivered($this->send($transport, new SendInvoice()), 10), 900);
        $this->send($transport, new RebuildIndex(), new DelayStamp(3_600_000));

        $queue = $this->onlyQueue($this->collector()->collect($this->definition()));

        self::assertSame(0, $queue->pending);
        self::assertNull($queue->oldestPendingAgeSeconds);
    }

    private function markedDelivered(int $id, int $secondsAgo): int
    {
        $this->markDelivered($id, $secondsAgo);

        return $id;
    }

    public function testClassBreakdownCountsEveryMessageOfTheQueue(): void
    {
        $transport = $this->transport();
        $this->send($transport, new SendInvoice());
        $this->send($transport, new SendInvoice());
        $this->send($transport, new RebuildIndex());

        $queue = $this->onlyQueue($this->collector()->collect($this->definition()));

        self::assertSame([RebuildIndex::class => 1, SendInvoice::class => 2], $queue->classBreakdown);
        self::assertFalse($queue->classBreakdownSampled);
    }

    public function testClassBreakdownIsSampledOverTheNewestMessages(): void
    {
        $transport = $this->transport();
        $this->send($transport, new SendInvoice());
        $this->send($transport, new SendInvoice());
        $this->send($transport, new RebuildIndex());

        $queue = $this->onlyQueue($this->collector(300, 2)->collect($this->definition()));

        self::assertSame([RebuildIndex::class => 1, SendInvoice::class => 1], $queue->classBreakdown);
        self::assertTrue($queue->classBreakdownSampled);
    }

    public function testAMessageWhoseClassNoLongerExistsIsReportedAsUnknown(): void
    {
        $this->transport();
        $this->insertMessageOfAMissingClass();

        $stats = $this->collector()->collect($this->definition(isFailureTransport: true));

        self::assertNotNull($stats->classBreakdown);
        self::assertSame([MessageRowDecoder::UNKNOWN_MESSAGE_CLASS => 1], $stats->classBreakdown->counts);
        self::assertCount(1, $stats->failures);
        self::assertSame(MessageRowDecoder::UNKNOWN_MESSAGE_CLASS, $stats->failures[0]->messageClass);
        self::assertNull($stats->failures[0]->exceptionClass);
        self::assertNull($stats->failures[0]->exceptionMessage);
        self::assertNull($stats->failures[0]->originalTransport);
        self::assertSame(0, $stats->failures[0]->retryCount);
        self::assertNotNull($stats->failures[0]->failedAt);
    }

    private function insertMessageOfAMissingClass(): void
    {
        $this->database->executeStatement(
            'INSERT INTO '.$this->database->quoteIdentifier(self::TABLE).' (body, headers, queue_name, created_at, available_at) VALUES (?, ?, ?, ?, ?)',
            [$this->bodyOfAMissingClass(), '[]', 'default', $this->secondsAgo(60), $this->secondsAgo(60)],
            [Types::STRING, Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE],
        );
    }

    private function bodyOfAMissingClass(): string
    {
        $serialized = serialize(new Envelope(new SendInvoice()));
        $missing = 'Vendor\\Missing\\Message';

        return addslashes(str_replace(
            \sprintf('O:%d:"%s"', \strlen(SendInvoice::class), SendInvoice::class),
            \sprintf('O:%d:"%s"', \strlen($missing), $missing),
            $serialized,
        ));
    }

    public function testOtherQueuesOfTheSameTableAreNotCounted(): void
    {
        $this->send($this->transport('payments'), new SendInvoice());
        $this->send($this->transport('reporting'), new RebuildIndex());
        $this->send($this->transport('reporting'), new RebuildIndex());

        $queue = $this->onlyQueue($this->collector()->collect($this->definition('doctrine://default?queue_name=payments')));

        self::assertSame('payments', $queue->name);
        self::assertSame(1, $queue->pending);
        self::assertSame([SendInvoice::class => 1], $queue->classBreakdown);
    }

    public function testFailuresAreReportedOnTheFailureTransport(): void
    {
        $transport = $this->transport();
        $this->send($transport, new RebuildIndex());
        $this->send(
            $transport,
            new SendInvoice(),
            new ErrorDetailsStamp('Acme\\PaymentFailed', 7, 'Connection refused'),
            new RedeliveryStamp(3, new DateTimeImmutable('2026-01-02T11:22:33+00:00')),
            new SentToFailureTransportStamp('async'),
        );

        $stats = $this->collector()->collect($this->definition(isFailureTransport: true));

        self::assertCount(2, $stats->failures);
        $failure = $stats->failures[0];
        self::assertSame(SendInvoice::class, $failure->messageClass);
        self::assertSame('Acme\\PaymentFailed', $failure->exceptionClass);
        self::assertSame('Connection refused', $failure->exceptionMessage);
        self::assertSame(3, $failure->retryCount);
        self::assertSame('async', $failure->originalTransport);
        self::assertNotNull($failure->failedAt);
        self::assertSame('2026-01-02T11:22:33+00:00', $failure->failedAt->format(\DATE_RFC3339));

        self::assertSame(RebuildIndex::class, $stats->failures[1]->messageClass);
        self::assertNull($stats->failures[1]->exceptionClass);
        self::assertSame(0, $stats->failures[1]->retryCount);
        self::assertNotNull($stats->failures[1]->failedAt);
    }

    public function testFailuresAreCappedAtTheConfiguredLimit(): void
    {
        $transport = $this->transport();
        $this->send($transport, new SendInvoice());
        $this->send($transport, new SendInvoice());
        $this->send($transport, new RebuildIndex());

        $stats = $this->collector(300, 1000, 2)->collect($this->definition(isFailureTransport: true));

        self::assertCount(2, $stats->failures);
        self::assertSame(RebuildIndex::class, $stats->failures[0]->messageClass);
        self::assertSame(SendInvoice::class, $stats->failures[1]->messageClass);
    }

    public function testFailureMessagesAreHiddenWhenTheyAreNotExposed(): void
    {
        $this->send(
            $this->transport(),
            new SendInvoice(),
            new ErrorDetailsStamp('Acme\\PaymentFailed', 7, 'Connection refused'),
        );

        $stats = $this->collector(300, 1000, 10, false)->collect($this->definition(isFailureTransport: true));

        self::assertSame('Acme\\PaymentFailed', $stats->failures[0]->exceptionClass);
        self::assertNull($stats->failures[0]->exceptionMessage);
    }

    public function testATransportThatIsNotTheFailureTransportReportsNoFailures(): void
    {
        $this->send($this->transport(), new SendInvoice());

        self::assertSame([], $this->collector()->collect($this->definition())->failures);
    }

    public function testAMissingTableIsReportedWithZeroes(): void
    {
        $stats = $this->collector()->collect($this->definition('doctrine://default?queue_name=payments'));

        self::assertSame(DetailLevel::Full, $stats->detailLevel);
        self::assertSame(0, $stats->count);
        self::assertSame([], $stats->failures);
        $this->assertZeroQueue($this->onlyQueue($stats), 'payments');
    }

    public function testAMissingTableIsReportedAsAnEmptyFailureTransport(): void
    {
        $stats = $this->collector()->collect($this->definition('doctrine://default?queue_name=failed', true));

        self::assertSame(DetailLevel::Full, $stats->detailLevel);
        self::assertTrue($stats->isFailureTransport);
        self::assertSame(0, $stats->count);
        self::assertSame([], $stats->queues);
        self::assertSame([], $stats->failures);
        self::assertNotNull($stats->classBreakdown);
        self::assertSame([], $stats->classBreakdown->counts);
        self::assertFalse($stats->classBreakdown->sampled);
    }

    public function testTheFailureTransportIsReportedAsACountWithAClassBreakdownAndNoQueues(): void
    {
        $transport = $this->transport();
        $this->send($transport, new SendInvoice());
        $this->markDelivered($this->send($transport, new SendInvoice()), 900);
        $this->send($transport, new RebuildIndex(), new DelayStamp(3_600_000));

        $stats = $this->collector()->collect($this->definition(isFailureTransport: true));

        self::assertSame(DetailLevel::Full, $stats->detailLevel);
        self::assertTrue($stats->isFailureTransport);
        self::assertSame(3, $stats->count);
        self::assertSame(3, $stats->failedCount());
        self::assertSame([], $stats->queues);
        self::assertNotNull($stats->classBreakdown);
        self::assertSame([RebuildIndex::class => 1, SendInvoice::class => 2], $stats->classBreakdown->counts);
        self::assertFalse($stats->classBreakdown->sampled);
        self::assertCount(3, $stats->failures);
    }

    public function testTheClassBreakdownOfTheFailureTransportIsSampled(): void
    {
        $transport = $this->transport();
        $this->send($transport, new SendInvoice());
        $this->send($transport, new SendInvoice());
        $this->send($transport, new RebuildIndex());

        $stats = $this->collector(300, 2)->collect($this->definition(isFailureTransport: true));

        self::assertSame(3, $stats->count);
        self::assertNotNull($stats->classBreakdown);
        self::assertSame([RebuildIndex::class => 1, SendInvoice::class => 1], $stats->classBreakdown->counts);
        self::assertTrue($stats->classBreakdown->sampled);
    }

    public function testTheReportedCountIsTheSumOfEveryState(): void
    {
        $transport = $this->transport();
        $this->send($transport, new SendInvoice());
        $this->markDelivered($this->send($transport, new SendInvoice()), 10);
        $this->markDelivered($this->send($transport, new SendInvoice()), 900);
        $this->send($transport, new RebuildIndex(), new DelayStamp(3_600_000));

        $stats = $this->collector()->collect($this->definition());
        $queue = $this->onlyQueue($stats);

        self::assertSame(4, $stats->count);
        self::assertSame(4, $queue->pending + $queue->delayed + $queue->inProgress + $queue->stuck);
    }

    public function testAMessageJustWrittenInLocalTimeIsPending(): void
    {
        $this->runInBerlin();
        $this->transport();
        $justNow = (new DateTime('now', new DateTimeZone(self::LOCAL_TIMEZONE)))->format(self::NAIVE_FORMAT);
        $this->insertLikeMessenger54(new SendInvoice(), $justNow, $justNow);

        $queue = $this->onlyQueue($this->collector(storageTimezone: self::LOCAL_TIMEZONE)->collect($this->definition()));

        self::assertSame(1, $queue->pending);
        self::assertSame(0, $queue->delayed);
        self::assertNotNull($queue->oldestPendingAgeSeconds);
        self::assertLessThan(120, $queue->oldestPendingAgeSeconds);
    }

    private function runInBerlin(): void
    {
        date_default_timezone_set(self::LOCAL_TIMEZONE);
    }

    private function insertLikeMessenger54(object $message, string $createdAt, string $availableAt, ?string $deliveredAt = null): void
    {
        $encoded = $this->serializer()->encode(new Envelope($message));

        $this->database->insert(self::TABLE, [
            'body' => $encoded['body'],
            'headers' => json_encode($encoded['headers'] ?? [], \JSON_THROW_ON_ERROR),
            'queue_name' => 'default',
            'created_at' => $createdAt,
            'available_at' => $availableAt,
            'delivered_at' => $deliveredAt,
        ]);
    }

    public function testTheOldestPendingAgeOfLocalTimeRowsIsTheRealAge(): void
    {
        $this->runInBerlin();
        $this->transport();
        $this->insertLikeMessenger54(new SendInvoice(), $this->localSecondsAgo(900), $this->localSecondsAgo(900));
        $this->insertLikeMessenger54(new SendInvoice(), $this->localSecondsAgo(100), $this->localSecondsAgo(100));

        $queue = $this->onlyQueue($this->collector(storageTimezone: self::LOCAL_TIMEZONE)->collect($this->definition()));

        self::assertSame(2, $queue->pending);
        self::assertSame(900, $queue->oldestPendingAgeSeconds);
    }

    private function localSecondsAgo(int $seconds): string
    {
        return $this->secondsAgo($seconds)->setTimezone(new DateTimeZone(self::LOCAL_TIMEZONE))->format(self::NAIVE_FORMAT);
    }

    public function testStuckDetectionOfLocalTimeRowsUsesTheRealAge(): void
    {
        $this->runInBerlin();
        $this->transport();
        $this->insertLikeMessenger54(new SendInvoice(), $this->localSecondsAgo(400), $this->localSecondsAgo(400), $this->localSecondsAgo(10));
        $this->insertLikeMessenger54(new SendInvoice(), $this->localSecondsAgo(400), $this->localSecondsAgo(400), $this->localSecondsAgo(301));

        $queue = $this->onlyQueue($this->collector(300, storageTimezone: self::LOCAL_TIMEZONE)->collect($this->definition()));

        self::assertSame(0, $queue->pending);
        self::assertSame(1, $queue->inProgress);
        self::assertSame(1, $queue->stuck);
    }

    public function testFailedAtFallsBackToTheLocalCreatedAtAsAUtcInstant(): void
    {
        $this->runInBerlin();
        $this->transport();
        $this->insertLikeMessenger54(new SendInvoice(), '2026-07-01 12:00:00', '2026-07-01 12:00:00');

        $stats = $this->collector(storageTimezone: self::LOCAL_TIMEZONE)->collect($this->definition(isFailureTransport: true));

        self::assertCount(1, $stats->failures);
        self::assertNotNull($stats->failures[0]->failedAt);
        self::assertSame('2026-07-01T10:00:00+00:00', $stats->failures[0]->failedAt->format(\DATE_RFC3339));
    }
}
