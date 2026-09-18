<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Collector;

use DateTimeImmutable;
use Error;
use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Collector\EnvelopeDecoder;
use Skukunin\MessengerStatsBundle\Collector\UtcDateTimeParser;
use Skukunin\MessengerStatsBundle\Report\FailedMessage;
use Skukunin\MessengerStatsBundle\Tests\Support\Message\SendInvoice;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Throwable;

final class EnvelopeDecoderTest extends TestCase
{
    private const BODY = 'the serialized body';
    private const CREATED_AT = '2026-09-18 08:00:00';

    public function testTheMessageClassComesFromTheDecodedEnvelope(): void
    {
        $decoder = $this->decoder($this->serializerReturning(new Envelope(new SendInvoice())));

        self::assertSame(SendInvoice::class, $decoder->messageClass('async', self::BODY, '[]'));
    }

    private function decoder(SerializerInterface $serializer, bool $exposeMessage = true, string $transportName = 'async'): EnvelopeDecoder
    {
        return new EnvelopeDecoder(
            new ServiceLocator([$transportName => static fn (): SerializerInterface => $serializer]),
            new UtcDateTimeParser(),
            $exposeMessage,
        );
    }

    private function serializerReturning(Envelope $envelope): SerializerInterface
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('decode')->willReturn($envelope);

        return $serializer;
    }

    public function testTheRowIsHandedToTheSerializerAsAnEncodedEnvelope(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
            ->method('decode')
            ->with(['body' => self::BODY, 'headers' => ['Content-Type' => 'application/json']])
            ->willReturn(new Envelope(new SendInvoice()));

        self::assertSame(SendInvoice::class, $this->decoder($serializer)->messageClass('async', self::BODY, '{"Content-Type":"application\/json"}'));
    }

    public function testHeadersThatAreNotStringsAreDropped(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
            ->method('decode')
            ->with(['body' => self::BODY, 'headers' => []])
            ->willReturn(new Envelope(new SendInvoice()));

        self::assertSame(SendInvoice::class, $this->decoder($serializer)->messageClass('async', self::BODY, '{"count":3,"list":["a"]}'));
    }

    public function testHeadersThatAreNotAJsonObjectAreDropped(): void
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects(self::once())
            ->method('decode')
            ->with(['body' => self::BODY, 'headers' => []])
            ->willReturn(new Envelope(new SendInvoice()));

        self::assertSame(SendInvoice::class, $this->decoder($serializer)->messageClass('async', self::BODY, '{not json'));
    }

    public function testTheFailedMessageIsBuiltFromTheEnvelopeStamps(): void
    {
        $message = $this->failedMessage($this->envelopeWith(
            new ErrorDetailsStamp('Acme\\PaymentFailed', 7, 'Connection refused'),
            new RedeliveryStamp(3, new DateTimeImmutable('2026-01-02T11:22:33+00:00')),
            new SentToFailureTransportStamp('async'),
        ));

        self::assertSame(SendInvoice::class, $message->messageClass);
        self::assertSame('Acme\\PaymentFailed', $message->exceptionClass);
        self::assertSame('Connection refused', $message->exceptionMessage);
        self::assertSame(3, $message->retryCount);
        self::assertSame('async', $message->originalTransport);
        self::assertNotNull($message->failedAt);
        self::assertSame('2026-01-02T11:22:33+00:00', $message->failedAt->format(\DATE_RFC3339));
    }

    private function failedMessage(Envelope $envelope, bool $exposeMessage = true): FailedMessage
    {
        return $this->decoder($this->serializerReturning($envelope), $exposeMessage)->failedMessage('async', self::BODY, '[]', self::CREATED_AT);
    }

    private function envelopeWith(StampInterface ...$stamps): Envelope
    {
        return new Envelope(new SendInvoice(), $stamps);
    }

    public function testTheLastStampOfEachTypeWins(): void
    {
        $message = $this->failedMessage($this->envelopeWith(
            new ErrorDetailsStamp('Acme\\First', 1, 'first'),
            new ErrorDetailsStamp('Acme\\Last', 2, 'last'),
            new SentToFailureTransportStamp('first'),
            new SentToFailureTransportStamp('async'),
        ));

        self::assertSame('Acme\\Last', $message->exceptionClass);
        self::assertSame('last', $message->exceptionMessage);
        self::assertSame('async', $message->originalTransport);
    }

    public function testTheExceptionMessageIsHiddenWhenItIsNotExposed(): void
    {
        $message = $this->failedMessage($this->envelopeWith(new ErrorDetailsStamp('Acme\\PaymentFailed', 7, 'Connection refused')), false);

        self::assertSame('Acme\\PaymentFailed', $message->exceptionClass);
        self::assertNull($message->exceptionMessage);
    }

    public function testAnEnvelopeWithoutStampsFallsBackToTheRowCreationTime(): void
    {
        $message = $this->failedMessage($this->envelopeWith());

        self::assertSame(SendInvoice::class, $message->messageClass);
        self::assertNull($message->exceptionClass);
        self::assertNull($message->exceptionMessage);
        self::assertNull($message->originalTransport);
        self::assertSame(0, $message->retryCount);
        self::assertNotNull($message->failedAt);
        self::assertSame('2026-09-18T08:00:00+00:00', $message->failedAt->format(\DATE_RFC3339));
    }

    public function testEmptyStampValuesAreReportedAsNull(): void
    {
        $message = $this->failedMessage($this->envelopeWith(new ErrorDetailsStamp('', 0, ''), new SentToFailureTransportStamp('')));

        self::assertNull($message->exceptionClass);
        self::assertNull($message->exceptionMessage);
        self::assertNull($message->originalTransport);
    }

    public function testADecodingFailureIsReportedAsAnUnknownMessage(): void
    {
        $decoder = $this->decoder($this->serializerThrowing(new MessageDecodingFailedException('Message class "Vendor\\Missing\\Message" not found during decoding.')));

        self::assertNull($decoder->messageClass('async', self::BODY, '[]'));

        $message = $decoder->failedMessage('async', self::BODY, '[]', self::CREATED_AT);

        self::assertSame('unknown', $message->messageClass);
        self::assertNull($message->exceptionClass);
        self::assertNull($message->exceptionMessage);
        self::assertNull($message->originalTransport);
        self::assertSame(0, $message->retryCount);
        self::assertNotNull($message->failedAt);
        self::assertSame('2026-09-18T08:00:00+00:00', $message->failedAt->format(\DATE_RFC3339));
    }

    private function serializerThrowing(Throwable $failure): SerializerInterface
    {
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('decode')->willThrowException($failure);

        return $serializer;
    }

    public function testAnErrorRaisedByTheSerializerIsReportedAsAnUnknownMessage(): void
    {
        $decoder = $this->decoder($this->serializerThrowing(new Error('Class "Vendor\\Missing\\Message" not found')));

        self::assertNull($decoder->messageClass('async', self::BODY, '[]'));
        self::assertSame('unknown', $decoder->failedMessage('async', self::BODY, '[]', self::CREATED_AT)->messageClass);
    }

    public function testATransportWithoutASerializerIsReportedAsAnUnknownMessage(): void
    {
        $decoder = $this->decoder($this->serializerReturning(new Envelope(new SendInvoice())), true, 'other');

        self::assertNull($decoder->messageClass('async', self::BODY, '[]'));
        self::assertSame('unknown', $decoder->failedMessage('async', self::BODY, '[]', self::CREATED_AT)->messageClass);
    }
}
