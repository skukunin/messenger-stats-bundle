<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Support\Message;

final class SendInvoice
{
    public function __construct(
        public readonly string $reference = 'INV-1',
    ) {
    }
}
