<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Support\Transport;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

final class UncountableTransport implements TransportInterface
{
    /**
     * @return iterable<Envelope>
     */
    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }

    public function send(Envelope $envelope): Envelope
    {
        return $envelope;
    }
}
