<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Skukunin\MessengerStatsBundle\Clock\Clock;
use Skukunin\MessengerStatsBundle\Clock\SystemClock;
use Skukunin\MessengerStatsBundle\Health\ThresholdEvaluator;
use Skukunin\MessengerStatsBundle\Health\ThresholdSet;
use Skukunin\MessengerStatsBundle\Transport\DoctrineDsnParser;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinitionRegistry;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

    $services->set(SystemClock::class);
    $services->alias(Clock::class, SystemClock::class);

    $services->set(ThresholdSet::class)
        ->args(['%messenger_stats.thresholds%']);

    $services->set(ThresholdEvaluator::class);

    $services->set(TransportDefinitionRegistry::class)
        ->args(['%messenger_stats.transports%']);

    $services->set(DoctrineDsnParser::class);
};
