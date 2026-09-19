<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Collector;

use Skukunin\MessengerStatsBundle\Report\FailedMessage;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

final class HeadersDecoder
{
    public const ERROR_DETAILS_HEADER = self::STAMP_HEADER_PREFIX.ErrorDetailsStamp::class;
    public const REDELIVERY_HEADER = self::STAMP_HEADER_PREFIX.RedeliveryStamp::class;
    public const SENT_TO_FAILURE_HEADER = self::STAMP_HEADER_PREFIX.SentToFailureTransportStamp::class;

    private const STAMP_HEADER_PREFIX = 'X-Message-Stamp-';
    private const TYPE_HEADER = 'type';

    public function __construct(
        private readonly StorageDateTimeParser $dateTimes,
        private readonly bool $exposeMessage,
    ) {
    }

    public function decode(string $headersJson, string $createdAt): FailedMessage
    {
        $headers = $this->headersOf($headersJson);
        $errorDetails = $this->lastStampOf($headers, self::ERROR_DETAILS_HEADER);
        $redelivery = $this->lastStampOf($headers, self::REDELIVERY_HEADER);
        $sentToFailure = $this->lastStampOf($headers, self::SENT_TO_FAILURE_HEADER);

        return new FailedMessage(
            $this->messageClassOf($headers) ?? MessageRowDecoder::UNKNOWN_MESSAGE_CLASS,
            $this->stringField($errorDetails, 'exceptionClass'),
            $this->exposeMessage ? $this->stringField($errorDetails, 'exceptionMessage') : null,
            $this->dateTimes->parse($this->stringField($redelivery, 'redeliveredAt') ?? $createdAt),
            $this->intField($redelivery, 'retryCount'),
            $this->stringField($sentToFailure, 'originalReceiverName'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function headersOf(string $headersJson): array
    {
        return $this->stringKeyed(json_decode($headersJson, true));
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyed(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $keyed = [];
        foreach ($values as $name => $value) {
            if (\is_string($name)) {
                $keyed[$name] = $value;
            }
        }

        return $keyed;
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, mixed>
     */
    private function lastStampOf(array $headers, string $header): array
    {
        $value = $headers[$header] ?? null;
        if (!\is_string($value)) {
            return [];
        }

        $stamps = json_decode($value, true);
        if (!\is_array($stamps) || [] === $stamps) {
            return [];
        }

        return $this->stringKeyed(end($stamps));
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function messageClassOf(array $headers): ?string
    {
        $type = $headers[self::TYPE_HEADER] ?? null;

        return \is_string($type) && '' !== $type ? $type : null;
    }

    /**
     * @param array<string, mixed> $stamp
     */
    private function stringField(array $stamp, string $name): ?string
    {
        $value = $stamp[$name] ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @param array<string, mixed> $stamp
     */
    private function intField(array $stamp, string $name): int
    {
        $value = $stamp[$name] ?? null;

        return \is_int($value) ? $value : 0;
    }

    public function messageClass(string $headersJson): ?string
    {
        return $this->messageClassOf($this->headersOf($headersJson));
    }
}
