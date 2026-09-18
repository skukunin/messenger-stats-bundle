<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Collector;

use Skukunin\MessengerStatsBundle\Report\FailedMessage;

final class MessageRowDecoder
{
    public const UNKNOWN_MESSAGE_CLASS = 'unknown';

    public function __construct(
        private readonly HeadersDecoder $headers,
        private readonly EnvelopeDecoder $envelopes,
    ) {
    }

    public function messageClass(string $transportName, string $body, string $headersJson): string
    {
        return $this->headers->messageClass($headersJson)
            ?? $this->envelopes->messageClass($transportName, $body, $headersJson)
            ?? self::UNKNOWN_MESSAGE_CLASS;
    }

    public function failedMessage(string $transportName, string $body, string $headersJson, string $createdAt): FailedMessage
    {
        return null !== $this->headers->messageClass($headersJson)
            ? $this->headers->decode($headersJson, $createdAt)
            : $this->envelopes->failedMessage($transportName, $body, $headersJson, $createdAt);
    }
}
