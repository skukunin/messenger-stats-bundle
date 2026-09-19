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
- The failure transport is reported as a count, a sampled class breakdown and the newest failures, without queues or per-state counts; Prometheus exports its classes as `messenger_failed_class_messages{transport,class}`, and queue-state thresholds on it fail container compilation. `schema_version` stays `"1"` because no version with the previous shape was released.
- `storage_timezone` option (default `auto`): Doctrine timestamps are compared in the timezone Messenger stored them in, UTC from Messenger 6.3 and PHP's default timezone before, so pending, delayed, stuck, oldest pending age and failure times are correct on Messenger 5.4 to 6.2 hosts that do not run in UTC.
