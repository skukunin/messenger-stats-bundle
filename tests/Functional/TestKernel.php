<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Functional;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Skukunin\MessengerStatsBundle\Collector\CountOnlyStatsCollector;
use Skukunin\MessengerStatsBundle\Collector\DoctrineTransportStatsCollector;
use Skukunin\MessengerStatsBundle\Collector\StatsCollectorResolver;
use Skukunin\MessengerStatsBundle\MessengerStatsBundle;
use Skukunin\MessengerStatsBundle\Report\StatsReportBuilder;
use Skukunin\MessengerStatsBundle\Tests\Support\PublicServicesPass;
use Skukunin\MessengerStatsBundle\Tests\Support\Transport\CountingTransportFactory;
use Skukunin\MessengerStatsBundle\Transport\DoctrineDsnParser;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinitionRegistry;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const ENV_TRANSPORT_DSN = 'TEST_TRANSPORT_DSN';
    public const JSON_SERIALIZER = 'messenger.transport.symfony_serializer';
    public const BROKEN_CONNECTION = 'broken_connection';
    public const ROUTE_PREFIX = '/_messenger';

    /**
     * @param array<string, mixed> $statsConfig
     */
    public function __construct(
        private readonly array $statsConfig = [],
    ) {
        parent::__construct('test', true);
    }

    /**
     * @return iterable<BundleInterface>
     */
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new DoctrineBundle(), new MessengerStatsBundle()];
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new PublicServicesPass([
            TransportDefinitionRegistry::class,
            DoctrineDsnParser::class,
            DoctrineTransportStatsCollector::class,
            CountOnlyStatsCollector::class,
            StatsCollectorResolver::class,
            StatsReportBuilder::class,
            'messenger.transport.async',
            'messenger.transport.payments',
            'messenger.transport.fake',
        ]));
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', [
            'secret' => 'messenger-stats',
            'test' => true,
            'router' => ['utf8' => true],
            'property_access' => true,
            'serializer' => true,
            'messenger' => [
                'failure_transport' => 'failed',
                'transports' => [
                    'async' => 'doctrine://default',
                    'payments' => ['dsn' => 'doctrine://default?queue_name=payments', 'serializer' => self::JSON_SERIALIZER],
                    'failed' => 'doctrine://default?queue_name=failed',
                    'retry' => ['dsn' => 'doctrine://default', 'options' => ['queue_name' => 'retry', 'redeliver_timeout' => 60]],
                    'env_dsn' => '%env('.self::ENV_TRANSPORT_DSN.')%',
                    'fake' => CountingTransportFactory::DSN,
                    'broken' => 'doctrine://'.self::BROKEN_CONNECTION,
                    'sync' => 'sync://',
                    'memory' => 'in-memory://',
                ],
            ],
        ]);

        $container->loadFromExtension('doctrine', [
            'dbal' => [
                'default_connection' => 'default',
                'connections' => [
                    'default' => ['url' => 'sqlite:///:memory:'],
                    self::BROKEN_CONNECTION => ['driver' => 'pdo_sqlite', 'path' => '/messenger-stats-unreachable/broken.sqlite'],
                ],
            ],
        ]);

        $container->register(CountingTransportFactory::class, CountingTransportFactory::class)
            ->addTag('messenger.transport_factory');

        $container->loadFromExtension('messenger_stats', $this->statsConfig);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(\dirname(__DIR__, 2).'/config/routes.php')->prefix(self::ROUTE_PREFIX);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/messenger_stats_bundle/'.$this->fingerprint();
    }

    private function fingerprint(): string
    {
        return hash('sha256', serialize([$this->statsConfig, $_SERVER[self::ENV_TRANSPORT_DSN] ?? null]));
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir().'/log';
    }

    protected function getConfigDir(): string
    {
        return $this->getCacheDir().'/config';
    }
}
