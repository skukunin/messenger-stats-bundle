<?php

declare(strict_types=1);

namespace Skukunin\MessengerStatsBundle\Report;

final class ApplicationIdentityFactory
{
    public function __construct(
        private readonly ?string $appName,
        private readonly string $projectDir,
        private readonly string $env,
    ) {
    }

    public function create(): ApplicationIdentity
    {
        return new ApplicationIdentity($this->name(), $this->env);
    }

    private function name(): string
    {
        return null === $this->appName || '' === $this->appName ? basename($this->projectDir) : $this->appName;
    }
}
