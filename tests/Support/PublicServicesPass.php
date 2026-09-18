<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Support;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PublicServicesPass implements CompilerPassInterface
{
    /**
     * @param list<string> $ids
     */
    public function __construct(
        private readonly array $ids,
    ) {
    }

    public function process(ContainerBuilder $container): void
    {
        foreach ($this->ids as $id) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            }
        }
    }
}
