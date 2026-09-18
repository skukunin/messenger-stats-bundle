<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Transport;

use Skukunin\MessengerStatsBundle\Exception\UnsupportedTransportDsnException;

final class DoctrineDsnParser
{
    private const DEFAULT_CONNECTION_NAME = 'default';
    private const DEFAULT_TABLE_NAME = 'messenger_messages';
    private const DEFAULT_QUEUE_NAME = 'default';
    private const DEFAULT_REDELIVER_TIMEOUT = 3600;
    private const DEFAULT_AUTO_SETUP = true;

    public function parse(TransportDefinition $transport): DoctrineTransportSettings
    {
        if (TransportKind::DOCTRINE !== TransportKind::fromDsn($transport->dsn)) {
            throw new UnsupportedTransportDsnException($transport->name, $transport->dsn);
        }

        $options = $this->queryOptionsOf($transport->dsn) + $transport->options;

        return new DoctrineTransportSettings(
            $this->connectionNameOf($transport->dsn),
            $this->stringOption($options, 'table_name', self::DEFAULT_TABLE_NAME),
            $this->stringOption($options, 'queue_name', self::DEFAULT_QUEUE_NAME),
            $this->intOption($options, 'redeliver_timeout', self::DEFAULT_REDELIVER_TIMEOUT),
            $this->boolOption($options, 'auto_setup', self::DEFAULT_AUTO_SETUP),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function queryOptionsOf(string $dsn): array
    {
        $query = strstr($dsn, '?');
        if (false === $query) {
            return [];
        }

        parse_str(substr($query, 1), $parsed);

        $options = [];
        foreach ($parsed as $name => $value) {
            if (\is_string($name)) {
                $options[$name] = $value;
            }
        }

        return $options;
    }

    private function connectionNameOf(string $dsn): string
    {
        preg_match('#^[^:]*:(?://)?([^/?]*)#', $dsn, $matches);
        $host = $matches[1] ?? '';

        return '' === $host ? self::DEFAULT_CONNECTION_NAME : $host;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function stringOption(array $options, string $name, string $default): string
    {
        $value = $options[$name] ?? null;

        return \is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function intOption(array $options, string $name, int $default): int
    {
        $value = $options[$name] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function boolOption(array $options, string $name, bool $default): bool
    {
        $value = $options[$name] ?? null;

        return null === $value ? $default : filter_var($value, \FILTER_VALIDATE_BOOL);
    }
}
