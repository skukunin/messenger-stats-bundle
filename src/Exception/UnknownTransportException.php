<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Exception;

final class UnknownTransportException extends InvalidArgumentException
{
    /**
     * @param list<string> $known
     */
    public function __construct(string $transport, array $known)
    {
        parent::__construct(\sprintf('Unknown transport "%s", known transports are: %s.', $transport, [] === $known ? 'none' : implode(', ', $known)));
    }
}
