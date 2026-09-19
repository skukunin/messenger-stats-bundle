# Messenger Stats Bundle

Queue statistics, health check and Prometheus metrics for Symfony Messenger
transports.

The bundle reads the state of every Messenger transport configured in your
application and exposes it four ways:

| Route / command | Format | Meant for |
|---|---|---|
| `GET /_messenger/stats` | JSON | humans, dashboards, custom monitors |
| `GET /_messenger/health` | `200` or `503` | uptime checkers (Uptime Kuma, Pingdom, Better Stack) |
| `GET /_messenger/metrics` | Prometheus text | Prometheus, Grafana, Alertmanager |
| `bin/console messenger:stats` | table or JSON | SSH sessions, cron checks |

It only reads. It never consumes, retries or removes messages.

## What you get

For every Doctrine transport (`doctrine://…`), per queue:

- **pending** — waiting for a worker and available now
- **delayed** — waiting for a retry delay or a scheduled time
- **in_progress** — handed to a worker recently
- **stuck** — handed to a worker too long ago (the worker probably died)
- **oldest_pending_age_seconds** — how long the head of the queue has waited,
  the best signal that no worker is running
- **class_breakdown** — messages per message class, sampled on big queues

For the failure transport, the message count, the class breakdown and the
last N failed messages with message class, exception class and message,
failure time, retry count and original transport. It has no per-state
breakdown: nothing consumes it automatically, so every message would only
show up as "pending". Message bodies and stack traces are never exposed.

For any other transport (AMQP, Redis, SQS, Beanstalkd, …) the bundle reports
the message count when the transport supports it, and nothing else.

A transport that cannot be read (database down, broker unreachable) is
reported as `unavailable` with the exception class. The rest of the report is
still returned.

## Requirements

- PHP 8.1+
- Symfony 5.4, 6.4 or 7.x
- Doctrine DBAL 2.13, 3 or 4 and `symfony/doctrine-messenger`

## Installation

```bash
composer require skukunin/messenger-stats-bundle
```

Register the bundle if Flex did not:

```php
// config/bundles.php
return [
    // ...
    Skukunin\MessengerStatsBundle\MessengerStatsBundle::class => ['all' => true],
];
```

Import the routes under a prefix of your choice:

```yaml
# config/routes/messenger_stats.yaml
messenger_stats:
    resource: '@MessengerStatsBundle/config/routes.php'
    prefix: '/_messenger'
```

Configure a token:

```yaml
# config/packages/messenger_stats.yaml
messenger_stats:
    token: '%env(default::MESSENGER_STATS_TOKEN)%'
```

```dotenv
# .env.local
MESSENGER_STATS_TOKEN=change-me-to-a-long-random-string
```

That is all. Transports are discovered from `framework.messenger.transports`.

## Security

The bundle authenticates its own routes. You do not need a firewall entry in
`security.yaml` for them. If a catch-all firewall in your application would
otherwise demand a login or HTTP basic credentials on the prefix, give the
prefix its own firewall with `security: false`, so the bearer token is the
only check:

```yaml
security:
    firewalls:
        messenger_stats:
            pattern: ^/_messenger/
            security: false
```

- Every route requires `Authorization: Bearer <token>`. The comparison is
  constant-time.
- When the token is empty or unset the routes answer `404` and reveal nothing.
  Use `%env(default::MESSENGER_STATS_TOKEN)%` so an undefined variable
  resolves to an empty token instead of throwing.
- `allowed_ips` adds an IP or CIDR allowlist on top of the token.
- Rejected requests are logged at `warning` with the client IP.
- Responses are sent with `Cache-Control: no-store`.

The report exposes message class names, queue depths and exception messages.
Treat the token like any other operational secret. If exception messages in
your application may contain personal data, set
`failures.expose_message: false`.

## Configuration reference

```yaml
messenger_stats:
    token: '%env(default::MESSENGER_STATS_TOKEN)%'
    allowed_ips: []                 # e.g. ['10.0.0.0/8', '192.168.1.20']
    app_name: ~                     # default: basename of the project directory
    exclude: []                     # transport names to leave out of the report
    stuck_after_seconds: ~          # default: each transport's redeliver_timeout
    class_breakdown_sample_size: 1000
    failures:
        limit: 10
        expose_message: true
    thresholds: {}
    storage_timezone: auto          # or a timezone identifier, e.g. 'Europe/Berlin'
```

`storage_timezone` is the timezone the Doctrine transport writes its
timestamps in. Messenger 6.3 and later write UTC; Messenger 5.4 to 6.2 write
PHP's default timezone (`date.timezone`). `auto` picks the right one from the
installed `symfony/doctrine-messenger` version when the container is
compiled, and falls back to the default timezone when the version cannot be
read. On Messenger < 6.3 the web server, the CLI and the workers must share
one `date.timezone` (Messenger already requires that); set
`storage_timezone` explicitly if they do not, or if the container is compiled
under a different timezone than the workers run in.

`sync://` and `in-memory://` transports are always skipped.

The report also carries `app` (from `app_name`) and `env` (the kernel
environment), so several applications and environments can share one
Prometheus or one monitor.

## Thresholds and health

Without thresholds the report's `status` is always `ok` and `/health` always
answers `200`. Alerting is then the job of whatever reads `/stats` or
`/metrics`.

With thresholds the bundle evaluates the report itself:

```yaml
messenger_stats:
    thresholds:
        async:
            oldest_pending_age_seconds: { warning: 300, critical: 900 }
            stuck: { critical: 1 }
        failed:
            failed: { warning: 1, critical: 50 }
```

A level is breached when the value is greater than or equal to the threshold.
The report gains `status` (`ok`, `warning`, `critical`) and a `problems` list,
`/health` answers `503` on `critical`, and `messenger:stats` exits with code
`1`.

Available metrics per transport: `pending`, `delayed`, `in_progress`, `stuck`,
`oldest_pending_age_seconds`, `failed` (only meaningful on the failure
transport) and `count` (the only one available for non-Doctrine transports).
On the failure transport only `failed` and `count` are allowed. A threshold on
a transport that does not exist, or a `pending`, `delayed`, `in_progress`,
`stuck` or `oldest_pending_age_seconds` threshold on the failure transport,
fails container compilation.

An `unavailable` transport counts as `critical` when it has thresholds
configured and as `warning` otherwise.

## The JSON report

```json
{
  "schema_version": "1",
  "bundle_version": "0.1.0",
  "generated_at": "2026-09-18T10:00:00+00:00",
  "app": "shop",
  "env": "prod",
  "status": "critical",
  "problems": [
    {"transport": "async_payments", "metric": "oldest_pending_age_seconds", "value": 900, "threshold": 600, "level": "critical"}
  ],
  "transports": {
    "async_payments": {
      "kind": "doctrine",
      "detail_level": "full",
      "is_failure_transport": false,
      "count": 46,
      "queues": {
        "payments": {
          "pending": 42,
          "delayed": 3,
          "in_progress": 1,
          "stuck": 0,
          "oldest_pending_age_seconds": 900,
          "class_breakdown": {"App\\Message\\RecurringPaymentMessage": 46},
          "class_breakdown_sampled": false
        }
      }
    },
    "failed": {
      "kind": "doctrine",
      "detail_level": "full",
      "is_failure_transport": true,
      "count": 7,
      "class_breakdown": {"App\\Message\\SendEmail": 7},
      "class_breakdown_sampled": false,
      "failures": [
        {"message_class": "App\\Message\\SendEmail", "exception_class": "Symfony\\Component\\Mailer\\Exception\\TransportException", "exception_message": "Connection refused", "failed_at": "2026-09-17T10:00:00+00:00", "retry_count": 3, "original_transport": "async"}
      ]
    },
    "events": {
      "kind": "amqp",
      "detail_level": "count",
      "is_failure_transport": false,
      "count": 12
    },
    "reporting": {
      "kind": "doctrine",
      "detail_level": "unavailable",
      "is_failure_transport": false,
      "count": null,
      "error": "Doctrine\\DBAL\\Exception\\ConnectionException"
    }
  }
}
```

`schema_version` changes only when a field is removed or renamed.

## Uptime checkers

Point any HTTP monitor at `/_messenger/health` with the header
`Authorization: Bearer <token>`. It answers `503` when a threshold is
critical, so the checker's normal "site down" alert becomes your queue alert.

Uptime Kuma: monitor type *HTTP(s)*, URL `https://example.com/_messenger/health`,
*Headers* `{"Authorization": "Bearer <token>"}`. Attach your usual Slack,
Teams or e-mail notification.

For a rule without host-side thresholds, use Uptime Kuma's *HTTP(s) - Json
Query* type against `/_messenger/stats` with an expression such as
`$.transports.failed.count` and the condition `< 10`.

## Prometheus and Grafana

```yaml
scrape_configs:
    - job_name: messenger
      metrics_path: /_messenger/metrics
      scheme: https
      authorization:
          type: Bearer
          credentials: <token>
      static_configs:
          - targets: ['shop.example.com']
            labels: { instance: shop-prod }
```

Exposed families, all gauges with `app` and `env` labels:

| Metric | Labels | Meaning |
|---|---|---|
| `messenger_transport_up` | `transport` | 1 when the transport could be read |
| `messenger_transport_messages` | `transport` | total messages |
| `messenger_queue_messages` | `transport`, `queue`, `state` | messages per state |
| `messenger_queue_oldest_pending_age_seconds` | `transport`, `queue` | age of the oldest pending message |
| `messenger_queue_class_messages` | `transport`, `queue`, `class` | messages per class, sampled |
| `messenger_failed_messages` | `transport` | messages in the failure transport |
| `messenger_failed_class_messages` | `transport`, `class` | messages per class in the failure transport, sampled |
| `messenger_health_status` | | 0 ok, 1 warning, 2 critical |

Example alert rules:

```yaml
groups:
    - name: messenger
      rules:
          - alert: MessengerWorkerDown
            expr: messenger_queue_oldest_pending_age_seconds > 600
            for: 5m
          - alert: MessengerFailures
            expr: messenger_failed_messages > 0
          - alert: MessengerTransportUnreadable
            expr: messenger_transport_up == 0
```

## Console

```bash
bin/console messenger:stats
```

```
App: shop  Env: prod  Generated: 2026-09-18T10:00:00+00:00  Status: critical

+----------------+----------+-------------+----------+---------+---------+-------------+-------+--------------------+-------+
| Transport      | Kind     | Detail      | Queue    | Pending | Delayed | In progress | Stuck | Oldest pending (s) | Count |
+----------------+----------+-------------+----------+---------+---------+-------------+-------+--------------------+-------+
| async_payments | doctrine | full        | payments | 42      | 3       | 1           | 0     | 900                | 46    |
| failed         | doctrine | full        | -        | -       | -       | -           | -     | -                  | 7     |
| events         | amqp     | count       | -        | -       | -       | -           | -     | -                  | 12    |
+----------------+----------+-------------+----------+---------+---------+-------------+-------+--------------------+-------+

Failures on failed
...

Problems
+----------+----------------+----------------------------+-------+-----------+
| Level    | Transport      | Metric                     | Value | Threshold |
+----------+----------------+----------------------------+-------+-----------+
| critical | async_payments | oldest_pending_age_seconds | 900   | 600       |
+----------+----------------+----------------------------+-------+-----------+
```

`--format=json` prints the same document as `/stats`. The exit code is `1`
when the status is `critical`, which makes the command usable from cron on
hosts without any HTTP monitoring.

## Troubleshooting

**`UnexpectedSessionUsageException: Session was used while the request was
declared stateless.`** The bundle's routes are stateless, and something in
your application reads or writes the session on every request, typically a
`kernel.response` subscriber. In debug mode Symfony turns that into a 500.
In production it only logs a warning, but every poll by a monitor then starts
a session and sends a cookie. Skip stateless requests in that subscriber:

```php
if ($event->getRequest()->attributes->getBoolean('_stateless')) {
    return;
}
```

## How it works

- A compiler pass records every configured transport with its DSN, options and
  serializer. Nothing is duplicated in the bundle configuration.
- Doctrine transports are queried with plain SQL on the transport's own
  connection and table. State counts are exact and index-backed. The class
  breakdown reads at most `class_breakdown_sample_size` of the newest rows and
  is flagged `class_breakdown_sampled` when the queue is larger.
- Message class and failure details come from the `type` and
  `X-Message-Stamp-*` headers when the JSON serializer is in use, and from
  decoding the row through the transport's own serializer otherwise. With the
  default `PhpSerializer` that means messages are unserialized, as a worker
  would, up to the sample size per report.
- A message whose class no longer exists is reported as `unknown`.
- `stuck` uses the transport's `redeliver_timeout` unless
  `stuck_after_seconds` is set. Setting it above `redeliver_timeout` has no
  effect, because Messenger redelivers the message first.
- Time comparisons are computed in PHP and bound as parameters, so the SQL is
  identical on SQLite, MySQL and PostgreSQL. They are made in the timezone
  Messenger stored the timestamps in (`storage_timezone`) and reported in UTC.
- Messenger < 6.3 stores local time without an offset. A timestamp written
  during the hour a DST change repeats in autumn is inherently ambiguous and
  may be read one hour off.
## Not covered

Throughput (messages processed per minute), worker process liveness, and
non-Doctrine transport details beyond the message count. The report is
computed on every request; put a scrape interval or an uptime interval in
front of it rather than expecting caching.

## Contributing

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan
vendor/bin/php-cs-fixer fix
```

GrumPHP installs a pre-commit hook that runs the same checks on the unit
suite. Integration tests run on SQLite by default and against MySQL when
`DATABASE_URL` is set.

## License

Released under the [MIT License](LICENSE).
