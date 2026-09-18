<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Functional;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Skukunin\MessengerStatsBundle\MessengerStatsBundle;
use Skukunin\MessengerStatsBundle\Tests\Support\PublicServicesPass;
use Skukunin\MessengerStatsBundle\Transport\DoctrineDsnParser;
use Skukunin\MessengerStatsBundle\Transport\TransportDefinitionRegistry;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public const ENV_TRANSPORT_DSN = 'TEST_TRANSPORT_DSN';

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
        $container->addCompilerPass(new PublicServicesPass([TransportDefinitionRegistry::class, DoctrineDsnParser::class]));
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', [
            'secret' => 'messenger-stats',
            'test' => true,
            'router' => ['utf8' => true],
            'messenger' => [
                'failure_transport' => 'failed',
                'transports' => [
                    'async' => 'doctrine://default',
                    'payments' => 'doctrine://default?queue_name=payments',
                    'failed' => 'doctrine://default?queue_name=failed',
                    'retry' => ['dsn' => 'doctrine://default', 'options' => ['queue_name' => 'retry', 'redeliver_timeout' => 60]],
                    'env_dsn' => '%env('.self::ENV_TRANSPORT_DSN.')%',
                    'sync' => 'sync://',
                    'memory' => 'in-memory://',
                ],
            ],
        ]);

        $container->loadFromExtension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
        ]);

        $container->loadFromExtension('messenger_stats', $this->statsConfig);
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
