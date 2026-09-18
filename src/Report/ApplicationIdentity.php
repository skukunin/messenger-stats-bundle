<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Report;

final class ApplicationIdentity
{
    public function __construct(
        public readonly string $name,
        public readonly string $env,
    ) {
    }
}
