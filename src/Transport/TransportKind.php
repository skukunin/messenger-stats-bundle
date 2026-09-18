<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Transport;

final class TransportKind
{
    public const DOCTRINE = 'doctrine';
    public const SYNC = 'sync';
    public const IN_MEMORY = 'in-memory';
    public const UNKNOWN = 'unknown';

    private function __construct()
    {
    }

    public static function fromDsn(string $dsn): string
    {
        $scheme = strstr($dsn, ':', true);

        return \is_string($scheme) && '' !== $scheme ? strtolower($scheme) : self::UNKNOWN;
    }
}
