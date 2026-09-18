<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Tests\Unit\Report;

use PHPUnit\Framework\TestCase;
use Skukunin\MessengerStatsBundle\Report\ApplicationIdentityFactory;

final class ApplicationIdentityFactoryTest extends TestCase
{
    public function testTheConfiguredNameWins(): void
    {
        $identity = (new ApplicationIdentityFactory('shop', '/var/www/my-project', 'prod'))->create();

        self::assertSame('shop', $identity->name);
        self::assertSame('prod', $identity->env);
    }

    public function testTheProjectDirectoryNamesTheApplicationWhenNoNameIsConfigured(): void
    {
        $identity = (new ApplicationIdentityFactory(null, '/var/www/my-project', 'dev'))->create();

        self::assertSame('my-project', $identity->name);
        self::assertSame('dev', $identity->env);
    }

    public function testAnEmptyConfiguredNameIsTreatedAsAbsent(): void
    {
        self::assertSame('my-project', (new ApplicationIdentityFactory('', '/var/www/my-project/', 'dev'))->create()->name);
    }
}
