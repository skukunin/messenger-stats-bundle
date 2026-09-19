<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Transport;

use Composer\InstalledVersions;

final class StorageTimezoneResolver
{
    public const AUTO = 'auto';

    private const PACKAGE = 'symfony/doctrine-messenger';
    private const UTC = 'UTC';
    private const FIRST_VERSION_STORING_UTC = '6.3';

    public function __construct(
        private readonly ?string $doctrineMessengerVersion,
        private readonly string $defaultTimezone,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(self::installedDoctrineMessengerVersion(), date_default_timezone_get());
    }

    private static function installedDoctrineMessengerVersion(): ?string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled(self::PACKAGE)) {
            return null;
        }

        return InstalledVersions::getVersion(self::PACKAGE);
    }

    public function resolve(string $configured): string
    {
        if (self::AUTO !== $configured) {
            return $configured;
        }

        return $this->doctrineMessengerStoresUtc() ? self::UTC : $this->defaultTimezone;
    }

    private function doctrineMessengerStoresUtc(): bool
    {
        return null !== $this->doctrineMessengerVersion
            && 1 === preg_match('/^\d+\.\d+/', $this->doctrineMessengerVersion)
            && version_compare($this->doctrineMessengerVersion, self::FIRST_VERSION_STORING_UTC, '>=');
    }
}
