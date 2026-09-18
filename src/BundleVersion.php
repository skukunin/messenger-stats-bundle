<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle;

use Composer\InstalledVersions;

final class BundleVersion
{
    public const PACKAGE = 'skukunin/messenger-stats-bundle';
    public const FALLBACK = 'dev';

    public function __construct(
        private readonly string $package = self::PACKAGE,
    ) {
    }

    public function version(): string
    {
        return $this->installedVersion() ?? self::FALLBACK;
    }

    private function installedVersion(): ?string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled($this->package)) {
            return null;
        }

        return InstalledVersions::getPrettyVersion($this->package);
    }
}
