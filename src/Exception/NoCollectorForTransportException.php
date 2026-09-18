<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Exception;

final class NoCollectorForTransportException extends InvalidArgumentException
{
    public function __construct(string $transport, string $kind)
    {
        parent::__construct(\sprintf('No stats collector supports transport "%s" of kind "%s".', $transport, $kind));
    }
}
