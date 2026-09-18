<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle;

use Skukunin\MessengerStatsBundle\DependencyInjection\Compiler\TransportDiscoveryPass;
use Skukunin\MessengerStatsBundle\DependencyInjection\MessengerStatsExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class MessengerStatsBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new TransportDiscoveryPass());
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function getContainerExtension(): ExtensionInterface
    {
        return new MessengerStatsExtension();
    }
}
