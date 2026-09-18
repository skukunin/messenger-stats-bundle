<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Skukunin\MessengerStatsBundle\BundleVersion;
use Skukunin\MessengerStatsBundle\Clock\Clock;
use Skukunin\MessengerStatsBundle\Clock\SystemClock;
use Skukunin\MessengerStatsBundle\Collector\CountOnlyStatsCollector;
use Skukunin\MessengerStatsBundle\Collector\DoctrineTransportStatsCollector;
use Skukunin\MessengerStatsBundle\Collector\EnvelopeDecoder;
use Skukunin\MessengerStatsBundle\Collector\HeadersDecoder;
use Skukunin\MessengerStatsBundle\Collector\MessageRowDecoder;
use Skukunin\MessengerStatsBundle\Collector\StatsCollectorResolver;
use Skukunin\MessengerStatsBundle\Collector\UtcDateTimeParser;
use Skukunin\MessengerStatsBundle\Console\StatsCommand;
use Skukunin\MessengerStatsBundle\Console\TableRenderer;
use Skukunin\MessengerStatsBundle\Health\ThresholdEvaluator;
use Skukunin\MessengerStatsBundle\Health\ThresholdSet;
use Skukunin\MessengerStatsBundle\Http\HealthController;
use Skukunin\MessengerStatsBundle\Http\MetricsController;
use Skukunin\MessengerStatsBundle\Http\NoStoreResponseFactory;
use Skukunin\MessengerStatsBundle\Http\StatsController;
use Skukunin\MessengerStatsBundle\Http\TokenRequestListener;
use Skukunin\MessengerStatsBundle\Report\ApplicationIdentity;
use Skukunin\MessengerStatsBundle\Report\ApplicationIdentityFactory;
use Skukunin\MessengerStatsBundle\Report\StatsReportBuilder;
use Skukunin\MessengerStatsBundle\Transport\DoctrineDsnParser;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinitionRegistry;
use Skukunin\MessengerStatsBundle\View\HealthReportView;
use Skukunin\MessengerStatsBundle\View\JsonReportView;
use Skukunin\MessengerStatsBundle\View\ProblemView;
use Skukunin\MessengerStatsBundle\View\PrometheusReportView;
use Symfony\Component\HttpKernel\KernelEvents;

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

    $services->set(UtcDateTimeParser::class);

    $services->set(HeadersDecoder::class)
        ->args([
            service(UtcDateTimeParser::class),
            '%messenger_stats.failures.expose_message%',
        ]);

    $services->set(EnvelopeDecoder::class)
        ->args([
            abstract_arg('serializer locator filled by the transport discovery pass'),
            service(UtcDateTimeParser::class),
            '%messenger_stats.failures.expose_message%',
        ]);

    $services->set(MessageRowDecoder::class)
        ->args([
            service(HeadersDecoder::class),
            service(EnvelopeDecoder::class),
        ]);

    $services->set(DoctrineTransportStatsCollector::class)
        ->args([
            service('doctrine'),
            service(DoctrineDsnParser::class),
            service(MessageRowDecoder::class),
            service(Clock::class),
            '%messenger_stats.stuck_after_seconds%',
            '%messenger_stats.class_breakdown_sample_size%',
            '%messenger_stats.failures.limit%',
        ])
        ->tag('messenger_stats.collector', ['priority' => 100]);

    $services->set(CountOnlyStatsCollector::class)
        ->args([abstract_arg('transport locator filled by the transport discovery pass')])
        ->tag('messenger_stats.collector', ['priority' => -100]);

    $services->set(StatsCollectorResolver::class)
        ->args([tagged_iterator('messenger_stats.collector')]);

    $services->set(BundleVersion::class);

    $services->set(ApplicationIdentityFactory::class)
        ->args([
            '%messenger_stats.app_name%',
            '%kernel.project_dir%',
            '%kernel.environment%',
        ]);

    $services->set(ApplicationIdentity::class)
        ->factory([service(ApplicationIdentityFactory::class), 'create']);

    $services->set(StatsReportBuilder::class)
        ->args([
            service(TransportDefinitionRegistry::class),
            service(StatsCollectorResolver::class),
            service(ThresholdEvaluator::class),
            service(Clock::class),
            service(ApplicationIdentity::class),
            service(BundleVersion::class),
            service('logger')->nullOnInvalid(),
        ]);

    $services->set(ProblemView::class);

    $services->set(JsonReportView::class)
        ->args([service(ProblemView::class)]);

    $services->set(HealthReportView::class)
        ->args([service(ProblemView::class)]);

    $services->set(PrometheusReportView::class);

    $services->set(NoStoreResponseFactory::class);

    $services->set(StatsController::class)
        ->args([
            service(StatsReportBuilder::class),
            service(JsonReportView::class),
            service(NoStoreResponseFactory::class),
        ])
        ->tag('controller.service_arguments');

    $services->set(HealthController::class)
        ->args([
            service(StatsReportBuilder::class),
            service(HealthReportView::class),
            service(NoStoreResponseFactory::class),
        ])
        ->tag('controller.service_arguments');

    $services->set(MetricsController::class)
        ->args([
            service(StatsReportBuilder::class),
            service(PrometheusReportView::class),
            service(NoStoreResponseFactory::class),
        ])
        ->tag('controller.service_arguments');

    $services->set(TableRenderer::class);

    $services->set(StatsCommand::class)
        ->autoconfigure(false)
        ->args([
            service(StatsReportBuilder::class),
            service(JsonReportView::class),
            service(TableRenderer::class),
        ])
        ->tag('console.command', ['command' => StatsCommand::NAME]);

    $services->set(TokenRequestListener::class)
        ->args([
            '%messenger_stats.token%',
            '%messenger_stats.allowed_ips%',
            service(NoStoreResponseFactory::class),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('kernel.event_listener', [
            'event' => KernelEvents::REQUEST,
            'method' => '__invoke',
            'priority' => TokenRequestListener::PRIORITY,
        ]);
};
