<?php

declare(strict_types=1);

namespace Symfony\Component\Routing\Loader\Configurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('messenger_stats_stats', '/stats')
        ->controller('Skukunin\MessengerStatsBundle\Http\StatsController')
        ->methods(['GET'])
        ->stateless();

    $routes->add('messenger_stats_health', '/health')
        ->controller('Skukunin\MessengerStatsBundle\Http\HealthController')
        ->methods(['GET'])
        ->stateless();

    $routes->add('messenger_stats_metrics', '/metrics')
        ->controller('Skukunin\MessengerStatsBundle\Http\MetricsController')
        ->methods(['GET'])
        ->stateless();
};
