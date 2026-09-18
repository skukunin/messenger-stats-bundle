<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\DependencyInjection\Compiler;

use Skukunin\MessengerStatsBundle\Collector\EnvelopeDecoder;
use Skukunin\MessengerStatsBundle\Exception\InvalidArgumentException;
use Skukunin\MessengerStatsBundle\Transport\TransportKind;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class TransportDiscoveryPass implements CompilerPassInterface
{
    private const RECEIVER_TAG = 'messenger.receiver';
    private const FAILURE_TRANSPORTS_LOCATOR = 'messenger.failure_transports';
    private const TRANSPORTS_PARAMETER = 'messenger_stats.transports';
    private const EXCLUDE_PARAMETER = 'messenger_stats.exclude';
    private const THRESHOLDS_PARAMETER = 'messenger_stats.thresholds';
    private const DEFAULT_SERIALIZER = 'messenger.default_serializer';
    private const IGNORED_KINDS = [TransportKind::SYNC, TransportKind::IN_MEMORY];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::EXCLUDE_PARAMETER) || !$container->hasParameter(self::THRESHOLDS_PARAMETER)) {
            return;
        }

        $transports = $this->discover($container);
        $this->assertThresholdsAreKnown($container, $transports);

        $container->setParameter(self::TRANSPORTS_PARAMETER, $transports);
        $this->registerSerializerLocator($container, $transports);
    }

    /**
     * @return array<string, array{dsn: string, options: array<string, mixed>, kind: string, is_failure_transport: bool, serializer: string}>
     */
    private function discover(ContainerBuilder $container): array
    {
        $excluded = $this->stringListParameter($container, self::EXCLUDE_PARAMETER);
        $failureTransportNames = $this->failureTransportNamesFromLocator($container);

        $transports = [];
        foreach ($container->findTaggedServiceIds(self::RECEIVER_TAG) as $id => $tags) {
            $definition = $container->getDefinition($id);
            foreach ($tags as $tag) {
                if (!\is_array($tag)) {
                    continue;
                }

                $name = $tag['alias'] ?? null;
                $dsn = $this->argument($definition, 0, 'dsn');
                if (!\is_string($name) || !\is_string($dsn)) {
                    continue;
                }

                $kind = TransportKind::fromDsn($dsn);
                if (\in_array($kind, self::IGNORED_KINDS, true) || \in_array($name, $excluded, true)) {
                    continue;
                }

                $transports[$name] = [
                    'dsn' => $dsn,
                    'options' => $this->options($definition),
                    'kind' => $kind,
                    'is_failure_transport' => $this->isFailureTransport($tag, $name, $failureTransportNames),
                    'serializer' => $this->serializerId($definition),
                ];
            }
        }

        return $transports;
    }

    /**
     * @return list<string>
     */
    private function stringListParameter(ContainerBuilder $container, string $name): array
    {
        $value = $container->getParameter($name);

        return \is_array($value) ? array_values(array_filter($value, \is_string(...))) : [];
    }

    /**
     * @return list<string>
     */
    private function failureTransportNamesFromLocator(ContainerBuilder $container): array
    {
        if (!$container->hasDefinition(self::FAILURE_TRANSPORTS_LOCATOR)) {
            return [];
        }

        $transports = $this->argument($container->getDefinition(self::FAILURE_TRANSPORTS_LOCATOR), 0, 'factories');

        return \is_array($transports) ? array_values(array_filter(array_keys($transports), \is_string(...))) : [];
    }

    private function argument(Definition $definition, int $index, string $name): mixed
    {
        $arguments = $definition->getArguments();

        return $arguments[$index] ?? $arguments['$'.$name] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(Definition $definition): array
    {
        $options = $this->argument($definition, 1, 'options');
        if (!\is_array($options)) {
            return [];
        }

        $named = [];
        foreach ($options as $option => $value) {
            if (\is_string($option)) {
                $named[$option] = $value;
            }
        }

        return $named;
    }

    /**
     * @param array<array-key, mixed> $tag
     * @param list<string>            $failureTransportNames
     */
    private function isFailureTransport(array $tag, string $name, array $failureTransportNames): bool
    {
        $tagged = $tag['is_failure_transport'] ?? null;

        return null === $tagged ? \in_array($name, $failureTransportNames, true) : filter_var($tagged, \FILTER_VALIDATE_BOOL);
    }

    private function serializerId(Definition $definition): string
    {
        $serializer = $this->argument($definition, 2, 'serializer');

        return $serializer instanceof Reference || \is_string($serializer) ? (string) $serializer : self::DEFAULT_SERIALIZER;
    }

    /**
     * @param array<string, array{dsn: string, options: array<string, mixed>, kind: string, is_failure_transport: bool, serializer: string}> $transports
     */
    private function assertThresholdsAreKnown(ContainerBuilder $container, array $transports): void
    {
        $thresholds = $container->getParameter(self::THRESHOLDS_PARAMETER);
        if (!\is_array($thresholds)) {
            return;
        }

        foreach (array_keys($thresholds) as $name) {
            if (!isset($transports[$name])) {
                throw new InvalidArgumentException(\sprintf('Unknown transport "%s" configured in "%s", known transports are: %s.', (string) $name, self::THRESHOLDS_PARAMETER, [] === $transports ? 'none' : implode(', ', array_keys($transports))));
            }
        }
    }

    /**
     * @param array<string, array{dsn: string, options: array<string, mixed>, kind: string, is_failure_transport: bool, serializer: string}> $transports
     */
    private function registerSerializerLocator(ContainerBuilder $container, array $transports): void
    {
        if (!$container->hasDefinition(EnvelopeDecoder::class)) {
            return;
        }

        $serializers = [];
        foreach ($transports as $name => $transport) {
            if ($container->has($transport['serializer'])) {
                $serializers[$name] = new Reference($transport['serializer']);
            }
        }

        $container->getDefinition(EnvelopeDecoder::class)
            ->setArgument('$serializers', ServiceLocatorTagPass::register($container, $serializers, EnvelopeDecoder::class));
    }
}
