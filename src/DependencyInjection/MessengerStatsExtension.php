<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class MessengerStatsExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{token: mixed, allowed_ips: list<string>, app_name: ?string, exclude: list<string>, stuck_after_seconds: ?int, class_breakdown_sample_size: int, failures: array{limit: int, expose_message: bool}, thresholds: array<string, array<string, array{warning: ?int, critical: ?int}>>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');

        $container->setParameter('messenger_stats.token', $this->stringTokenOf($config['token']));
        $container->setParameter('messenger_stats.allowed_ips', $config['allowed_ips']);
        $container->setParameter('messenger_stats.app_name', $config['app_name']);
        $container->setParameter('messenger_stats.exclude', $config['exclude']);
        $container->setParameter('messenger_stats.stuck_after_seconds', $config['stuck_after_seconds']);
        $container->setParameter('messenger_stats.class_breakdown_sample_size', $config['class_breakdown_sample_size']);
        $container->setParameter('messenger_stats.failures.limit', $config['failures']['limit']);
        $container->setParameter('messenger_stats.failures.expose_message', $config['failures']['expose_message']);
        $container->setParameter('messenger_stats.thresholds', $config['thresholds']);
    }

    private function stringTokenOf(mixed $token): ?string
    {
        if (null !== $token && !\is_string($token)) {
            throw new InvalidConfigurationException(\sprintf('Invalid type for path "messenger_stats.token". Expected a string, but got "%s".', get_debug_type($token)));
        }

        return $token;
    }

    public function getAlias(): string
    {
        return Configuration::ROOT_NODE;
    }
}
