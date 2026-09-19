# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.1] - 2026-09-19

### Changed

- README and Composer description state that the package is a Symfony bundle; added `symfony-bundle` and `bundle` keywords.

### Fixed

- CI: the PHP 8.1 / Symfony 5.4 lowest-dependency job installs GrumPHP as the dependency-free shim, checks code style only on current tools, gives PHPStan 1 GB of memory and tolerates deprecations raised by vendor code.
- CI: GitHub Actions moved to their Node 24 versions.
- A functional test that PHPStan 1.12 rejected.

No change to the bundle's runtime code.

## [0.1.0] - 2026-09-19

### Added

- Discovery of every configured Messenger transport at container compile time.
- Full statistics for Doctrine transports: pending, delayed, in-progress and stuck messages, oldest pending age and a sampled class breakdown.
- The failure transport is reported as a count, a sampled class breakdown and the newest failures with exception details.
- Message count for any other transport implementing `MessageCountAwareInterface`.
- `storage_timezone` option (default `auto`): Doctrine timestamps are compared in the timezone Messenger stored them in, UTC from Messenger 6.3 and PHP's default timezone before.
- Optional per-transport thresholds producing a health status and a list of problems.
- `GET /stats` (JSON), `GET /health` (200/503) and `GET /metrics` (Prometheus) routes behind a bearer token and an optional IP allowlist.
- `messenger:stats` console command with table and JSON output.
- Tooling: PHPUnit, PHPStan, PHP CS Fixer, GrumPHP and a GitHub Actions matrix.

[Unreleased]: https://github.com/skukunin/messenger-stats-bundle/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/skukunin/messenger-stats-bundle/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/skukunin/messenger-stats-bundle/releases/tag/v0.1.0
