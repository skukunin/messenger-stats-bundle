<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Support\Message;

final class RebuildIndex
{
    public function __construct(
        public readonly string $index = 'catalog',
    ) {
    }
}
