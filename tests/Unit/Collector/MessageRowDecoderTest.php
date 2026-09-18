<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Collector;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Skukunin\MessengerStatsBundle\Collector\EnvelopeDecoder;
use Skukunin\MessengerStatsBundle\Collector\HeadersDecoder;
use Skukunin\MessengerStatsBundle\Collector\MessageRowDecoder;
use Skukunin\MessengerStatsBundle\Collector\UtcDateTimeParser;
use Skukunin\MessengerStatsBundle\Tests\Support\Message\RebuildIndex;
use Skukunin\MessengerStatsBundle\Tests\Support\Message\SendInvoice;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class MessageRowDecoderTest extends TestCase
{
    private const BODY = 'the serialized body';
    private const CREATED_AT = '2026-09-18 08:00:00';

    public function testTheTypeHeaderIsPreferredOverTheBody(): void
    {
        $decoder = $this->decoder($this->serializerNeverUsed());

        self::assertSame(SendInvoice::class, $decoder->messageClass('async', self::BODY, $this->headers(['type' => SendInvoice::class])));
    }

    private function decoder(SerializerInterface $serializer, bool $exposeMessage = true): MessageRowDecoder
    {
        $dateTimes = new UtcDateTimeParser();

        return new MessageRowDecoder(
            new HeadersDecoder($dateTimes, $exposeMessage),
            new EnvelopeDecoder(new ServiceLocator(['async' => static fn (): SerializerInterface => $serializer]), $dateTimes, $exposeMessage),
        );
    }

    private function serializerNeverUsed(): SerializerInterface
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::never())->method('decode');

        return $serializer;
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

    public function testTheBodyIsDecodedWhenNoTypeHeaderIsWritten(): void
    {
        $decoder = $this->decoder($this->serializerReturning(new Envelope(new RebuildIndex())));

        self::assertSame(RebuildIndex::class, $decoder->messageClass('async', self::BODY, '[]'));
    }

    private function serializerReturning(Envelope $envelope): SerializerInterface
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('decode')->willReturn($envelope);

        return $serializer;
    }

    public function testAnEmptyTypeHeaderFallsBackToTheBody(): void
    {
        $decoder = $this->decoder($this->serializerReturning(new Envelope(new RebuildIndex())));

        self::assertSame(RebuildIndex::class, $decoder->messageClass('async', self::BODY, $this->headers(['type' => ''])));
    }

    public function testAMessageIsUnknownWhenNeitherTheHeadersNorTheBodyCanBeRead(): void
    {
        $decoder = $this->decoder($this->serializerThrowing());

        self::assertSame(MessageRowDecoder::UNKNOWN_MESSAGE_CLASS, $decoder->messageClass('async', self::BODY, '[]'));
    }

    private function serializerThrowing(): SerializerInterface
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('decode')->willThrowException(new RuntimeException('cannot decode'));

        return $serializer;
    }

    public function testFailureDetailsComeFromTheHeadersWhenTheyCarryTheType(): void
    {
        $headers = $this->headers([
            'type' => SendInvoice::class,
            HeadersDecoder::ERROR_DETAILS_HEADER => '[{"exceptionClass":"Acme\\\\PaymentFailed","exceptionMessage":"Connection refused"}]',
            HeadersDecoder::REDELIVERY_HEADER => '[{"retryCount":3,"redeliveredAt":"2026-01-02T11:22:33+00:00"}]',
            HeadersDecoder::SENT_TO_FAILURE_HEADER => '[{"originalReceiverName":"async"}]',
        ]);

        $failure = $this->decoder($this->serializerNeverUsed())->failedMessage('async', self::BODY, $headers, self::CREATED_AT);

        self::assertSame(SendInvoice::class, $failure->messageClass);
        self::assertSame('Acme\\PaymentFailed', $failure->exceptionClass);
        self::assertSame('Connection refused', $failure->exceptionMessage);
        self::assertSame(3, $failure->retryCount);
        self::assertSame('async', $failure->originalTransport);
        self::assertNotNull($failure->failedAt);
        self::assertSame('2026-01-02T11:22:33+00:00', $failure->failedAt->format(\DATE_RFC3339));
    }

    public function testFailureDetailsComeFromTheEnvelopeWhenTheHeadersAreEmpty(): void
    {
        $envelope = new Envelope(new SendInvoice(), [
            new ErrorDetailsStamp('Acme\\PaymentFailed', 7, 'Connection refused'),
            new RedeliveryStamp(3, new DateTimeImmutable('2026-01-02T11:22:33+00:00')),
            new SentToFailureTransportStamp('async'),
        ]);

        $failure = $this->decoder($this->serializerReturning($envelope))->failedMessage('async', self::BODY, '[]', self::CREATED_AT);

        self::assertSame(SendInvoice::class, $failure->messageClass);
        self::assertSame('Acme\\PaymentFailed', $failure->exceptionClass);
        self::assertSame('Connection refused', $failure->exceptionMessage);
        self::assertSame(3, $failure->retryCount);
        self::assertSame('async', $failure->originalTransport);
        self::assertNotNull($failure->failedAt);
        self::assertSame('2026-01-02T11:22:33+00:00', $failure->failedAt->format(\DATE_RFC3339));
    }

    public function testAnUndecodableRowIsReportedAsAnUnknownFailure(): void
    {
        $failure = $this->decoder($this->serializerThrowing())->failedMessage('async', self::BODY, '[]', self::CREATED_AT);

        self::assertSame(MessageRowDecoder::UNKNOWN_MESSAGE_CLASS, $failure->messageClass);
        self::assertNull($failure->exceptionClass);
        self::assertNull($failure->exceptionMessage);
        self::assertNull($failure->originalTransport);
        self::assertSame(0, $failure->retryCount);
    }

    public function testTheExceptionMessageIsHiddenOnBothPaths(): void
    {
        $headers = $this->headers([
            'type' => SendInvoice::class,
            HeadersDecoder::ERROR_DETAILS_HEADER => '[{"exceptionClass":"Acme\\\\PaymentFailed","exceptionMessage":"Connection refused"}]',
        ]);
        $envelope = new Envelope(new SendInvoice(), [new ErrorDetailsStamp('Acme\\PaymentFailed', 7, 'Connection refused')]);

        $fromHeaders = $this->decoder($this->serializerReturning($envelope), false)->failedMessage('async', self::BODY, $headers, self::CREATED_AT);
        $fromEnvelope = $this->decoder($this->serializerReturning($envelope), false)->failedMessage('async', self::BODY, '[]', self::CREATED_AT);

        self::assertSame('Acme\\PaymentFailed', $fromHeaders->exceptionClass);
        self::assertNull($fromHeaders->exceptionMessage);
        self::assertSame('Acme\\PaymentFailed', $fromEnvelope->exceptionClass);
        self::assertNull($fromEnvelope->exceptionMessage);
    }
}
