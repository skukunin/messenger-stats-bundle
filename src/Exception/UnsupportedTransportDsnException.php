<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Exception;

final class UnsupportedTransportDsnException extends InvalidArgumentException
{
    public function __construct(string $transport, string $dsn)
    {
        parent::__construct(\sprintf('Transport "%s" is not a Doctrine transport, its DSN is "%s".', $transport, $dsn));
    }
}
