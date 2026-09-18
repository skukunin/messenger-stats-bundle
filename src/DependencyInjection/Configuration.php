<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\IntegerNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public const ROOT_NODE = 'messenger_stats';

    private const METRICS = ['pending', 'delayed', 'in_progress', 'stuck', 'oldest_pending_age_seconds', 'failed', 'count'];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT_NODE);
        $rootNode = $treeBuilder->getRootNode();
        \assert($rootNode instanceof ArrayNodeDefinition);

        $rootNode
            ->children()
                ->scalarNode('token')
                    ->defaultNull()
                    ->beforeNormalization()
                        ->ifString()
                        ->then(static fn (string $token): ?string => '' === $token ? null : $token)
                    ->end()
                ->end()
                ->arrayNode('allowed_ips')
                    ->scalarPrototype()->end()
                ->end()
                ->scalarNode('app_name')->defaultNull()->end()
                ->arrayNode('exclude')
                    ->scalarPrototype()->end()
                ->end()
                ->append($this->nullableIntegerNode('stuck_after_seconds', 1))
                ->integerNode('class_breakdown_sample_size')->min(1)->defaultValue(1000)->end()
                ->arrayNode('failures')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('limit')->min(1)->defaultValue(10)->end()
                        ->booleanNode('expose_message')->defaultTrue()->end()
                    ->end()
                ->end()
                ->append($this->thresholdsNode())
            ->end();

        return $treeBuilder;
    }

    private function nullableIntegerNode(string $name, int $min): IntegerNodeDefinition
    {
        $node = new IntegerNodeDefinition($name);
        $node->min($min)->defaultNull();
        $node->beforeNormalization()->ifNull()->thenUnset();

        return $node;
    }

    private function thresholdsNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('thresholds');
        $node
            ->useAttributeAsKey('transport')
            ->normalizeKeys(false)
            ->arrayPrototype()
                ->useAttributeAsKey('metric')
                ->validate()
                    ->ifTrue($this->hasUnknownMetric(...))
                    ->thenInvalid('Unknown "messenger_stats.thresholds" metric in %s, expected one of: '.implode(', ', self::METRICS).'.')
                ->end()
                ->arrayPrototype()
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append($this->nullableIntegerNode('warning', 0))
                        ->append($this->nullableIntegerNode('critical', 0))
                    ->end()
                    ->validate()
                        ->ifTrue($this->hasWarningAboveCritical(...))
                        ->thenInvalid('A "messenger_stats.thresholds" warning must not be greater than its critical, got %s.')
                    ->end()
                ->end()
            ->end();

        return $node;
    }

    private function hasUnknownMetric(mixed $metrics): bool
    {
        return \is_array($metrics) && [] !== array_diff(array_keys($metrics), self::METRICS);
    }

    private function hasWarningAboveCritical(mixed $threshold): bool
    {
        if (!\is_array($threshold)) {
            return false;
        }

        $warning = $threshold['warning'] ?? null;
        $critical = $threshold['critical'] ?? null;

        return \is_int($warning) && \is_int($critical) && $warning > $critical;
    }
}
