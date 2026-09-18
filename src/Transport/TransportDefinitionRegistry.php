<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Transport;

use Skukunin\MessengerStatsBundle\Exception\UnknownTransportException;

final class TransportDefinitionRegistry
{
    /**
     * @param array<string, array{dsn: string, options: array<string, mixed>, kind: string, is_failure_transport: bool}> $transports
     */
    public function __construct(
        private readonly array $transports,
    ) {
    }

    /**
     * @return list<TransportDefinition>
     */
    public function all(): array
    {
        $definitions = [];
        foreach (array_keys($this->transports) as $name) {
            $definitions[] = $this->definitionOf($name);
        }

        return $definitions;
    }

    private function definitionOf(string $name): TransportDefinition
    {
        $transport = $this->transports[$name];

        return new TransportDefinition(
            $name,
            $transport['dsn'],
            $this->kindOf($transport['kind'], $transport['dsn']),
            $transport['options'],
            $transport['is_failure_transport'],
        );
    }

    private function kindOf(string $kind, string $dsn): string
    {
        return TransportKind::UNKNOWN === $kind ? TransportKind::fromDsn($dsn) : $kind;
    }

    public function get(string $name): TransportDefinition
    {
        if (!isset($this->transports[$name])) {
            throw new UnknownTransportException($name, $this->names());
        }

        return $this->definitionOf($name);
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_keys($this->transports);
    }

    /**
     * @return list<string>
     */
    public function failureTransportNames(): array
    {
        $names = [];
        foreach ($this->transports as $name => $transport) {
            if ($transport['is_failure_transport']) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
