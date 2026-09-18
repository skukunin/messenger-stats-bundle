# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Discovery of every configured Messenger transport at container compile time.
- Full statistics for Doctrine transports: pending, delayed, in-progress and stuck messages, oldest pending age, sampled class breakdown, and failed messages with exception details.
- Message count for any other transport implementing `MessageCountAwareInterface`.
- Optional per-transport thresholds producing a health status and a list of problems.
- `GET /stats` (JSON), `GET /health` (200/503) and `GET /metrics` (Prometheus) routes behind a bearer token and an optional IP allowlist.
- `messenger:stats` console command with table and JSON output.
- Tooling: PHPUnit, PHPStan, PHP CS Fixer, GrumPHP and a GitHub Actions matrix.
