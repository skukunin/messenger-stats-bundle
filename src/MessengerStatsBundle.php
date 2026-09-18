<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle;

use Skukunin\MessengerStatsBundle\DependencyInjection\MessengerStatsExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class MessengerStatsBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function getContainerExtension(): ExtensionInterface
    {
        return new MessengerStatsExtension();
    }
}
