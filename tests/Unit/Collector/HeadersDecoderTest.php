<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Collector;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Collector\HeadersDecoder;
use Skukunin\MessengerStatsBundle\Collector\StorageDateTimeParser;
use Skukunin\MessengerStatsBundle\Report\FailedMessage;

final class HeadersDecoderTest extends TestCase
{
    private const CREATED_AT = '2026-09-18 08:00:00';

    public function testMessageClassComesFromTheTypeHeader(): void
    {
        $headers = $this->headers(['type' => 'Acme\\Message\\SendInvoice']);

        self::assertSame('Acme\\Message\\SendInvoice', $this->decoder()->messageClass($headers));
        self::assertSame('Acme\\Message\\SendInvoice', $this->decode($headers)->messageClass);
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function headers(array $headers): string
    {
        $json = json_encode($headers);
        self::assertIsString($json);

        return $json;
    }

    private function decoder(bool $exposeMessage = true): HeadersDecoder
    {
        return new HeadersDecoder(new StorageDateTimeParser('UTC'), $exposeMessage);
    }

    private function decode(string $headers, bool $exposeMessage = true, string $createdAt = self::CREATED_AT): FailedMessage
    {
        return $this->decoder($exposeMessage)->decode($headers, $createdAt);
    }

    public function testMissingTypeHeaderYieldsNullAndUnknown(): void
    {
        $headers = $this->headers(['Content-Type' => 'application/json']);

        self::assertNull($this->decoder()->messageClass($headers));
        self::assertSame('unknown', $this->decode($headers)->messageClass);
    }

    public function testNonStringTypeHeaderYieldsNull(): void
    {
        self::assertNull($this->decoder()->messageClass($this->headers(['type' => ['Acme\\Message\\SendInvoice']])));
        self::assertNull($this->decoder()->messageClass($this->headers(['type' => ''])));
    }

    public function testCorruptedJsonYieldsNullAndUnknown(): void
    {
        self::assertNull($this->decoder()->messageClass('{not json'));

        $message = $this->decode('{not json');

        self::assertSame('unknown', $message->messageClass);
        self::assertNull($message->exceptionClass);
        self::assertNull($message->exceptionMessage);
        self::assertNull($message->originalTransport);
        self::assertSame(0, $message->retryCount);
    }

    public function testHeadersThatAreNotAJsonObjectYieldNullAndUnknown(): void
    {
        self::assertNull($this->decoder()->messageClass('[]'));
        self::assertSame('unknown', $this->decode('"a string"')->messageClass);
    }

    public function testErrorDetails(): void
    {
        $message = $this->decode($this->headers([
            'type' => 'Acme\\Message\\SendInvoice',
            HeadersDecoder::ERROR_DETAILS_HEADER => '[{"exceptionClass":"Acme\\\\PaymentFailed","exceptionCode":7,"exceptionMessage":"Connection refused","flattenException":null}]',
        ]));

        self::assertSame('Acme\\PaymentFailed', $message->exceptionClass);
        self::assertSame('Connection refused', $message->exceptionMessage);
    }

    public function testTheLastErrorDetailsStampWins(): void
    {
        $message = $this->decode($this->headers([
            HeadersDecoder::ERROR_DETAILS_HEADER => '[{"exceptionClass":"Acme\\\\First","exceptionMessage":"first"},{"exceptionClass":"Acme\\\\Last","exceptionMessage":"last"}]',
        ]));

        self::assertSame('Acme\\Last', $message->exceptionClass);
        self::assertSame('last', $message->exceptionMessage);
    }

    public function testExceptionMessageIsHiddenWhenItIsNotExposed(): void
    {
        $headers = $this->headers([
            HeadersDecoder::ERROR_DETAILS_HEADER => '[{"exceptionClass":"Acme\\\\PaymentFailed","exceptionMessage":"Connection refused"}]',
        ]);

        $message = $this->decode($headers, false);

        self::assertSame('Acme\\PaymentFailed', $message->exceptionClass);
        self::assertNull($message->exceptionMessage);
    }

    public function testRetryCountAndFailedAtComeFromTheLastRedeliveryStamp(): void
    {
        $message = $this->decode($this->headers([
            HeadersDecoder::REDELIVERY_HEADER => '[{"retryCount":1,"redeliveredAt":"2026-01-01T10:00:00+00:00"},{"retryCount":3,"redeliveredAt":"2026-01-02T11:22:33+00:00"}]',
        ]));

        self::assertSame(3, $message->retryCount);
        self::assertNotNull($message->failedAt);
        self::assertSame('2026-01-02T11:22:33+00:00', $message->failedAt->format(\DATE_RFC3339));
    }

    public function testFailedAtFallsBackToTheRowCreationTimeReadAsUtc(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $message = $this->decode($this->headers(['type' => 'Acme\\Message\\SendInvoice']));
        } finally {
            date_default_timezone_set($previous);
        }

        self::assertNotNull($message->failedAt);
        self::assertSame('2026-09-18T08:00:00+00:00', $message->failedAt->format(\DATE_RFC3339));
        self::assertSame(0, $message->retryCount);
    }

    public function testFailedAtIsNullWhenNeitherTheStampNorTheRowTimeCanBeRead(): void
    {
        self::assertNull($this->decode($this->headers([]), true, 'not a date')->failedAt);
        self::assertNull($this->decode($this->headers([
            HeadersDecoder::REDELIVERY_HEADER => '[{"retryCount":2,"redeliveredAt":"whenever"}]',
        ]), true, 'not a date')->failedAt);
    }

    public function testRetryCountSurvivesAStampWithoutARedeliveryTime(): void
    {
        $message = $this->decode($this->headers([
            HeadersDecoder::REDELIVERY_HEADER => '[{"retryCount":2}]',
        ]));

        self::assertSame(2, $message->retryCount);
        self::assertNotNull($message->failedAt);
        self::assertSame('2026-09-18T08:00:00+00:00', $message->failedAt->format(\DATE_RFC3339));
    }

    public function testOriginalTransportComesFromTheLastSentToFailureTransportStamp(): void
    {
        $message = $this->decode($this->headers([
            HeadersDecoder::SENT_TO_FAILURE_HEADER => '[{"originalReceiverName":"first"},{"originalReceiverName":"async"}]',
        ]));

        self::assertSame('async', $message->originalTransport);
    }

    public function testStampHeadersThatAreNotJsonAreIgnored(): void
    {
        $message = $this->decode($this->headers([
            HeadersDecoder::ERROR_DETAILS_HEADER => '{not json',
            HeadersDecoder::REDELIVERY_HEADER => '{not json',
            HeadersDecoder::SENT_TO_FAILURE_HEADER => '{not json',
        ]));

        self::assertNull($message->exceptionClass);
        self::assertSame(0, $message->retryCount);
        self::assertNull($message->originalTransport);
    }

    public function testStampHeadersThatAreNotListsOfObjectsAreIgnored(): void
    {
        $message = $this->decode($this->headers([
            HeadersDecoder::ERROR_DETAILS_HEADER => '"just a string"',
            HeadersDecoder::REDELIVERY_HEADER => '[]',
            HeadersDecoder::SENT_TO_FAILURE_HEADER => '[42]',
        ]));

        self::assertNull($message->exceptionClass);
        self::assertSame(0, $message->retryCount);
        self::assertNull($message->originalTransport);
    }

    public function testStampFieldsOfTheWrongTypeAreIgnored(): void
    {
        $message = $this->decode($this->headers([
            HeadersDecoder::ERROR_DETAILS_HEADER => '[{"exceptionClass":[],"exceptionMessage":{}}]',
            HeadersDecoder::REDELIVERY_HEADER => '[{"retryCount":"many"}]',
            HeadersDecoder::SENT_TO_FAILURE_HEADER => '[{"originalReceiverName":false}]',
        ]));

        self::assertNull($message->exceptionClass);
        self::assertNull($message->exceptionMessage);
        self::assertSame(0, $message->retryCount);
        self::assertNull($message->originalTransport);
    }

    public function testEmptyHeadersOfAPhpSerializedEnvelope(): void
    {
        self::assertNull($this->decoder()->messageClass('[]'));

        $message = $this->decode('[]');

        self::assertSame('unknown', $message->messageClass);
        self::assertSame(0, $message->retryCount);
    }
}
