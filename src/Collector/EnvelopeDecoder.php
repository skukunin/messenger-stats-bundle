<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Collector;

use DateTimeImmutable;
use DateTimeInterface;
use Psr\Container\ContainerInterface;
use Skukunin\MessengerStatsBundle\Exception\InvalidArgumentException;
use Skukunin\MessengerStatsBundle\Report\FailedMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Throwable;

final class EnvelopeDecoder
{
    public function __construct(
        private readonly ContainerInterface $serializers,
        private readonly StorageDateTimeParser $dateTimes,
        private readonly bool $exposeMessage,
    ) {
    }

    public function messageClass(string $transportName, string $body, string $headersJson): ?string
    {
        $envelope = $this->envelopeOf($transportName, $body, $headersJson);

        return null === $envelope ? null : $envelope->getMessage()::class;
    }

    private function envelopeOf(string $transportName, string $body, string $headersJson): ?Envelope
    {
        try {
            return $this->serializerOf($transportName)->decode(['body' => $body, 'headers' => $this->headersOf($headersJson)]);
        } catch (Throwable) {
            return null;
        }
    }

    private function serializerOf(string $transportName): SerializerInterface
    {
        $serializer = $this->serializers->get($transportName);
        if (!$serializer instanceof SerializerInterface) {
            throw new InvalidArgumentException(\sprintf('Serializer of transport "%s" is not a "%s".', $transportName, SerializerInterface::class));
        }

        return $serializer;
    }

    /**
     * @return array<string, string>
     */
    private function headersOf(string $headersJson): array
    {
        $decoded = json_decode($headersJson, true);
        if (!\is_array($decoded)) {
            return [];
        }

        $headers = [];
        foreach ($decoded as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    public function failedMessage(string $transportName, string $body, string $headersJson, string $createdAt): FailedMessage
    {
        $envelope = $this->envelopeOf($transportName, $body, $headersJson);
        if (null === $envelope) {
            return new FailedMessage(MessageRowDecoder::UNKNOWN_MESSAGE_CLASS, null, null, $this->dateTimes->parse($createdAt), 0, null);
        }

        $errorDetails = $envelope->last(ErrorDetailsStamp::class);
        $redelivery = $envelope->last(RedeliveryStamp::class);
        $sentToFailure = $envelope->last(SentToFailureTransportStamp::class);

        return new FailedMessage(
            $envelope->getMessage()::class,
            null === $errorDetails ? null : $this->filled($errorDetails->getExceptionClass()),
            $this->exposeMessage && null !== $errorDetails ? $this->filled($errorDetails->getExceptionMessage()) : null,
            null === $redelivery ? $this->dateTimes->parse($createdAt) : $this->immutable($redelivery->getRedeliveredAt()),
            $redelivery?->getRetryCount() ?? 0,
            null === $sentToFailure ? null : $this->filled($sentToFailure->getOriginalReceiverName()),
        );
    }

    private function filled(string $value): ?string
    {
        return '' === $value ? null : $value;
    }

    private function immutable(DateTimeInterface $value): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($value);
    }
}
