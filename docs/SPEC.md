# Messenger Stats Bundle — Specification v1

Vocabulary: see [CONTEXT.md](../CONTEXT.md). Decisions: see [adr/](adr/).

## 1. Scope

A Symfony bundle that reports the state of the host application's Messenger
transports as a Stats Report, exposed as JSON, a health check, Prometheus
metrics and a console command. It reads; it never consumes, retries or
removes messages.

Out of scope for v1: throughput, worker liveness, report caching, a central
monitor application, Flex recipe.

## 2. Compatibility

| Dependency | Constraint |
|---|---|
| php | ^8.1 |
| symfony/framework-bundle, http-kernel, http-foundation, messenger, dependency-injection, config, console, event-dispatcher | ^5.4 \|\| ^6.4 \|\| ^7.0 |
| doctrine/dbal | ^2.13 \|\| ^3.0 \|\| ^4.0 |
| doctrine/persistence | ^2.2 \|\| ^3.0 \|\| ^4.0 (the `ConnectionRegistry` the Doctrine collector resolves connections through) |
| symfony/doctrine-messenger | ^5.4 \|\| ^6.4 \|\| ^7.0 (required; Doctrine is the only full-detail collector) |
| psr/container | ^1.1 \|\| ^2.0 (the serializer locator the message row decoder reads) |
| psr/log | ^1 \|\| ^2 \|\| ^3 |

No runtime dependency on `symfony/security-bundle`, `symfony/yaml`,
`symfony/clock`, `symfony/serializer`. `symfony/serializer` and
`symfony/property-access` are development dependencies only: the integration
suite needs them to write transport headers the way a host application using
Messenger's `Serializer` does.

## 3. Host configuration

```yaml
# config/packages/messenger_stats.yaml
messenger_stats:
    token: '%env(MESSENGER_STATS_TOKEN)%'   # empty/unset => routes answer 404
    allowed_ips: []                         # optional; IPs or CIDRs, applied after the token
    app_name: ~                             # default: basename of kernel.project_dir, resolved at runtime
    exclude: []                             # transport names to skip
    stuck_after_seconds: ~                  # default: each transport's redeliver_timeout
    class_breakdown_sample_size: 1000
    failures:
        limit: 10
        expose_message: true
    thresholds: {}                          # see 3.1
```

```yaml
# config/routes/messenger_stats.yaml
messenger_stats:
    resource: '@MessengerStatsBundle/config/routes.php'
    prefix: '/_messenger'
```

### 3.1 Thresholds

```yaml
thresholds:
    <transport name>:
        <metric>: { warning: <int|null>, critical: <int|null> }
```

Allowed `<metric>` values and what they compare against:

| Metric | Compared value |
|---|---|
| `pending` | sum of Pending over the transport's queues |
| `delayed` | sum of Delayed |
| `in_progress` | sum of In Progress |
| `stuck` | sum of Stuck |
| `oldest_pending_age_seconds` | max over queues |
| `failed` | Failed Message count (only meaningful on the Failure Transport) |
| `count` | total messages (the only metric available at Detail Level `count`) |

A level is breached when `value >= threshold`. Unknown transport names or
metrics fail container compilation.

## 4. Stats Report (domain model)

```
StatsReport
  generatedAt: DateTimeImmutable
  app: string
  env: string
  schemaVersion: "1"
  bundleVersion: string
  status: HealthStatus (ok|warning|critical)
  problems: Problem[]
  transports: TransportStats[]

TransportStats
  name: string
  kind: string                 # "doctrine", "amqp", "redis", ... derived from DSN scheme
  detailLevel: DetailLevel     # full|count|unavailable
  isFailureTransport: bool
  count: ?int                  # null when unavailable
  queues: QueueStats[]         # full only
  failures: FailedMessage[]    # full + failure transport only
  error: ?string               # unavailable only; exception class

QueueStats
  name: string
  pending, delayed, inProgress, stuck: int
  oldestPendingAgeSeconds: ?int   # null when no pending messages
  classBreakdown: array<string,int>  # class => count
  classBreakdownSampled: bool

FailedMessage
  messageClass: string
  exceptionClass: ?string
  exceptionMessage: ?string       # null when expose_message = false
  failedAt: ?DateTimeImmutable
  retryCount: int
  originalTransport: ?string

Problem
  transport, metric: string
  value, threshold: int
  level: warning|critical
```

Rules:

- `status` is the worst level among `problems`; `ok` when none.
- An Unavailable Transport contributes a Problem with level `critical` if any
  threshold is configured for that transport, otherwise `warning`, with
  metric `up`, value 0, threshold 1.
- The report is built once per request/command and handed to a Report View.
- A Stats Collector that throws makes only its own Transport Unavailable. The
  Stats Report Builder reports the exception **class** in `error` and never
  the exception message, which routinely embeds hostnames, ports and
  credentials; the message is written to the `error` log channel together
  with the transport name instead.
- `app` is `messenger_stats.app_name` when configured, otherwise the basename
  of `kernel.project_dir`; `env` is `kernel.environment`; `bundleVersion` is
  the package version reported by `Composer\InstalledVersions`, or `dev`.

## 5. Doctrine collector queries

Given transport options `table_name` (default `messenger_messages`),
`queue_name` (default `default`), connection name from the DSN, and `now`
from the Clock. `DoctrineDsnParser` resolves them the way
`Connection::buildConfiguration()` does: the DSN query string wins over the
transport's `options`, which win over the defaults; a DSN without a host
falls back to connection `default`, and unknown options are ignored instead
of rejected:

| Field | Query |
|---|---|
| Pending | `COUNT(*) WHERE queue_name = ? AND delivered_at IS NULL AND available_at <= now` |
| Delayed | `COUNT(*) WHERE queue_name = ? AND delivered_at IS NULL AND available_at > now` |
| In Progress | `COUNT(*) WHERE queue_name = ? AND delivered_at IS NOT NULL AND delivered_at > now - stuck_after` |
| Stuck | `COUNT(*) WHERE queue_name = ? AND delivered_at IS NOT NULL AND delivered_at <= now - stuck_after` |
| Oldest Pending Age | `now - MIN(available_at)` over Pending rows |
| Class Breakdown | `SELECT body, headers FROM ... WHERE queue_name = ? ORDER BY id DESC LIMIT sample_size`; decode each row (see below); `sampled = (rows fetched == sample_size AND total count > sample_size)` |
| Failures | `SELECT body, headers, created_at FROM ... WHERE queue_name = ? ORDER BY id DESC LIMIT failures.limit` on the Failure Transport |

A row is decoded by the Message Row Decoder, which is a hybrid: when the
`headers` column carries a non-empty `type` header the row is read from the
headers alone, otherwise the row is decoded through the transport's own
serializer. Both paths honour `failures.expose_message`.

### 5.1 Header path

The `headers` column holds a JSON object written by the transport's
serializer. Messenger's `Serializer` writes `type` (the message FQCN), one
`X-Message-Stamp-<stamp FQCN>` entry per stamp class and `Content-Type`.
Every stamp header's value is itself a JSON **string** holding an array of
that class's stamps, so the decoder parses it a second time and takes the
last element. The fields below were verified against Messenger 7.4; the names
are the stamps' own property names and have been stable since 5.4, where the
`RedeliveryStamp` carries additional legacy keys that are ignored:

| Header | Fields read |
|---|---|
| `type` | the message class; absent or empty ⇒ the envelope path decides |
| `X-Message-Stamp-Symfony\Component\Messenger\Stamp\ErrorDetailsStamp` | `exceptionClass`, `exceptionMessage` (also carries `exceptionCode`, `flattenException`) |
| `X-Message-Stamp-Symfony\Component\Messenger\Stamp\RedeliveryStamp` | `retryCount` (default 0), `redeliveredAt` (RFC 3339) used as Failed At |
| `X-Message-Stamp-Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp` | `originalReceiverName` |

Missing headers, invalid JSON, stamps that are not objects and fields of the
wrong type yield nulls (retry count 0), never an error. `redeliveredAt`
absent ⇒ Failed At falls back to the row's `created_at`.

### 5.2 Envelope path

Messenger's **default** serializer is `PhpSerializer`, which emits no headers
at all: `DoctrineSender` then stores `[]` in the column, and the message class
and every stamp live PHP-serialized inside `body`. Such a row is handed to the
transport's own serializer as `['body' => …, 'headers' => …]`, the way
`messenger:failed:show` reads it; the headers are the JSON object of the
column reduced to its string values, and an unusable column yields `[]`. The
resulting `Envelope` gives the message class and, through
`Envelope::last()`, the same three stamps as the header path:
`ErrorDetailsStamp` (`getExceptionClass()`, `getExceptionMessage()`),
`RedeliveryStamp` (`getRetryCount()`, `getRedeliveredAt()` used as Failed At)
and `SentToFailureTransportStamp` (`getOriginalReceiverName()`). Empty stamp
strings are reported as null, and a missing `RedeliveryStamp` falls back to
the row's `created_at`.

Any `\Throwable` raised while decoding — a `MessageDecodingFailedException`, a
message class that no longer exists, a transport with no serializer in the
locator — is swallowed: the message class is `unknown`, every failure detail
is null and the retry count is 0. Nothing propagates to the Stats Report.

The envelope path unserializes the row, so a Class Breakdown over a
`PhpSerializer` transport runs the messages' `__wakeup()` / `__unserialize()`
up to `class_breakdown_sample_size` times per report. Reading only the class
through `MessageTypeAwareSerializerInterface::getMessageType()` (Messenger
7.4+) would avoid that and is left for a later version.

All date comparisons are done with PHP-computed timestamps bound as
`Types::DATETIME_IMMUTABLE` parameters, not database date functions, so the
SQL is identical on SQLite, MySQL and Postgres. `created_at`, `available_at`
and `delivered_at` are written by doctrine-messenger as UTC
`datetime_immutable`; timestamps read back are therefore parsed as UTC
regardless of the process time zone.

Each Doctrine transport is queried on its own connection, resolved through
`doctrine.dbal.<name>_connection`, obtained from the `doctrine`
`ConnectionRegistry`. If the transport uses `auto_setup` and the table does
not exist yet, the transport is reported as full detail with one zeroed
Queue named after its `queue_name` and no failures, not as unavailable; the
Queue is still listed so the Prometheus series stay stable. Any other
failure propagates and is turned into an Unavailable Transport by the Stats
Report Builder.

A Doctrine transport definition addresses exactly one `queue_name`, so its
`queues` list always holds exactly one entry.

## 6. Transport discovery (compile time)

A compiler pass reads every definition tagged `messenger.receiver`, takes the
tag's `alias` as the transport name, and the factory arguments `$dsn` and
`$options`. It also captures the transport's serializer: the factory argument
`$serializer` (positional index 2 or the named `$serializer`) is a `Reference`
to either `messenger.default_serializer` or the per-transport `serializer`
option; its **service id** is stored, and `messenger.default_serializer` is
assumed when the argument is absent. Results are stored in the parameter
`messenger_stats.transports`:

```
[name => ['dsn' => string, 'options' => array, 'kind' => string, 'is_failure_transport' => bool, 'serializer' => string]]
```

The pass additionally registers a service locator (through
`ServiceLocatorTagPass::register()`) mapping transport name => serializer
service, and injects it into the Envelope Decoder. Only ids the container
knows enter the locator, and the locator's references are what keep the
serializer services from being removed as unused.

Transports with scheme `sync` or `in-memory`, and names listed in `exclude`,
are dropped. The Failure Transport is read from the `is_failure_transport`
attribute of the `messenger.receiver` tag, which covers both the global
failure transport and per-transport `failure_transport` options; a
`messenger.failure_transports` service locator is used only as a fallback
when the attribute is absent. Env placeholders in DSNs are resolved at
runtime when the container reads the parameter, not at compile time; a DSN
that is entirely an env placeholder is stored with kind `unknown` and its
kind is derived again from the resolved DSN by the runtime registry.

The pass also registers a second service locator (again through
`ServiceLocatorTagPass::register()`) mapping transport name => the
`messenger.transport.<name>` service, and injects it into the
`CountOnlyStatsCollector`. The locator is lazy, so no transport is
instantiated until it is collected.

The `kind` is the DSN scheme. A runtime `StatsCollectorResolver` walks the
services tagged `messenger_stats.collector` in tag priority order and returns
the first one whose `supports()` accepts the Transport: the
`DoctrineTransportStatsCollector` (priority 100) for kind `doctrine`, the
`CountOnlyStatsCollector` (priority -100) for every other kind. The
count-only collector reports the transport service's `getMessageCount()` when
that service implements `MessageCountAwareInterface`, and `count: null`
otherwise — including when the transport service is not in the locator. A
Transport no collector supports raises a `NoCollectorForTransportException`,
which the Stats Report Builder turns into an Unavailable Transport like any
other collection failure.

## 7. HTTP

All routes: `GET`, stateless, `Cache-Control: no-store` — on the rejections of
§7.1 as well as on the three documents. `ResponseHeaderBag` completes a
`Cache-Control` that names neither `public`, `private` nor `s-maxage`, so the
header sent is `no-store, private`.

### 7.1 Authentication (all routes)

1. Token config empty → `404` with an empty body. Because the token is an env
   placeholder, emptiness is decided at runtime by the listener, never at
   container compilation.
2. `Authorization: Bearer <token>` missing or not equal (`hash_equals`) →
   `401`, body `{"error":"unauthorized"}`, log warning with client IP.
3. `allowed_ips` non-empty and client IP not matched (`IpUtils::checkIp`) →
   `403`, body `{"error":"forbidden"}`, log warning.

Implemented as a `kernel.request` listener that acts only when the matched
route name starts with `messenger_stats_`. Its priority is 8: below
`RouterListener`'s 32, so `_route` is already known, and above 0, so the
controller is never resolved for a rejected request. Setting the response on
the event stops the propagation of `kernel.request` by itself.

### 7.2 `messenger_stats_stats` — `/stats`

`200 application/json`:

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
      "queues": { "failed": { "pending": 7, "delayed": 0, "in_progress": 0, "stuck": 0, "oldest_pending_age_seconds": 86400, "class_breakdown": {"App\\Message\\SendEmail": 7}, "class_breakdown_sampled": false } },
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

Dates are RFC 3339 in UTC. Keys absent for a Detail Level are omitted, not
null, except `count`: a `full` Transport carries `queues`, a `full` Failure
Transport carries `failures` as well — as `[]` when there is none — and an
Unavailable Transport carries `error`. Slashes are not escaped in the body.

`class_breakdown` and `transports` are JSON-encoded from PHP arrays, so an
empty one is written `[]`, not `{}`; an empty Class Breakdown is what a
Queue on a table that `auto_setup` has not created yet reports.

### 7.3 `messenger_stats_health` — `/health`

`200` when `status` is `ok` or `warning`, `503` when `critical`. Body is
`{"status": "...", "problems": [...]}`.

### 7.4 `messenger_stats_metrics` — `/metrics`

`200 text/plain; version=0.0.4; charset=utf-8`. Labels `app` and `env` on
every sample.

```
# HELP messenger_transport_up 1 when the transport could be read.
# TYPE messenger_transport_up gauge
messenger_transport_up{app="shop",env="prod",transport="async_payments"} 1
# HELP messenger_transport_messages Total messages in the transport.
# TYPE messenger_transport_messages gauge
messenger_transport_messages{app="shop",env="prod",transport="async_payments"} 46
# HELP messenger_queue_messages Messages per queue and state.
# TYPE messenger_queue_messages gauge
messenger_queue_messages{app="shop",env="prod",transport="async_payments",queue="payments",state="pending"} 42
messenger_queue_messages{...,state="delayed"} 3
messenger_queue_messages{...,state="in_progress"} 1
messenger_queue_messages{...,state="stuck"} 0
# HELP messenger_queue_oldest_pending_age_seconds Age of the oldest pending message.
# TYPE messenger_queue_oldest_pending_age_seconds gauge
messenger_queue_oldest_pending_age_seconds{app="shop",env="prod",transport="async_payments",queue="payments"} 900
# HELP messenger_queue_class_messages Messages per class (sampled).
# TYPE messenger_queue_class_messages gauge
messenger_queue_class_messages{app="shop",env="prod",transport="async_payments",queue="payments",class="App\\Message\\RecurringPaymentMessage"} 46
# HELP messenger_failed_messages Messages in the failure transport.
# TYPE messenger_failed_messages gauge
messenger_failed_messages{app="shop",env="prod",transport="failed"} 7
# HELP messenger_health_status 0 ok, 1 warning, 2 critical.
# TYPE messenger_health_status gauge
messenger_health_status{app="shop",env="prod"} 2
```

`transport_messages` is omitted when `count` is null, and
`queue_oldest_pending_age_seconds` when the Queue has no pending message. A
metric family without a single sample is omitted with its `# HELP` and
`# TYPE` lines, so a report holding only `count` Transports exposes no
`messenger_queue_*` family at all; `messenger_health_status` is always
exposed. `messenger_failed_messages` follows the Failure Transport's `count`,
whatever its Detail Level. Failure details are not exported as metrics.

Label values escape `\` as `\\`, `"` as `\"` and a newline as `\n`; `app` and
`env` come first in every sample. The exposition ends with a newline.

## 8. Console

`bin/console messenger:stats [--format=table|json]`

- `table` (default): one table per transport; failure transport also prints
  the failures table. Problems printed at the end.
- `json`: the same document as `/stats`.
- Exit code `0` for `ok`/`warning`, `1` for `critical`.

## 9. Architecture

```
src/
  MessengerStatsBundle.php
  BundleVersion.php                  # Composer\InstalledVersions, "dev" when not installed
  DependencyInjection/
    Configuration.php
    MessengerStatsExtension.php
    Compiler/TransportDiscoveryPass.php
  Transport/
    TransportDefinition.php          # name, dsn, kind, options, isFailureTransport, serializerServiceId
    TransportDefinitionRegistry.php  # built from the parameter at runtime
    TransportKind.php                # DSN scheme constants and derivation
    DoctrineDsnParser.php
    DoctrineTransportSettings.php    # connection, table, queue, redeliver, autoSetup
  Exception/
    MessengerStatsException.php      # marker interface
    InvalidArgumentException.php, UnknownTransportException.php,
    UnsupportedTransportDsnException.php, NoCollectorForTransportException.php
  Collector/
    StatsCollector.php               # interface: supports(def), collect(def): TransportStats
    StatsCollectorResolver.php
    DoctrineTransportStatsCollector.php
    CountOnlyStatsCollector.php
    MessageRowDecoder.php            # hybrid: headers when they carry the type, else the envelope
    HeadersDecoder.php
    EnvelopeDecoder.php              # decodes through the transport's own serializer
    UtcDateTimeParser.php
  Report/
    StatsReport.php, TransportStats.php, QueueStats.php, FailedMessage.php,
    Problem.php, HealthStatus.php (enum), DetailLevel.php (enum)
    ApplicationIdentity.php          # app name and env of the host application
    ApplicationIdentityFactory.php   # app_name ?: basename(kernel.project_dir)
    StatsReportBuilder.php           # registry + resolver + evaluator -> StatsReport
  Health/
    ThresholdSet.php, ThresholdEvaluator.php
  View/
    JsonReportView.php, HealthReportView.php, PrometheusReportView.php
    ProblemView.php                  # the problem list shared by JSON and health
  Http/
    StatsController.php, HealthController.php, MetricsController.php
    TokenRequestListener.php
    NoStoreResponseFactory.php       # the responses of the three routes and of §7.1
  Console/
    StatsCommand.php, TableRenderer.php
  Clock/
    Clock.php, SystemClock.php
config/
  services.php, routes.php
```

Interfaces carry no `Interface` suffix; implementations carry descriptive
suffixes. Models are plain readonly DTOs with no services injected.
Controllers call only `StatsReportBuilder`, a view and the response factory;
they are invokable services tagged `controller.service_arguments` and extend
no framework base class.

## 10. Tests

| Layer | Tooling | Covers |
|---|---|---|
| Unit | PHPUnit | DoctrineDsnParser, MessageRowDecoder with HeadersDecoder and EnvelopeDecoder, ThresholdEvaluator, HealthStatus derivation, the four views, the three controllers, TableRenderer, TokenRequestListener (with mocked request) |
| Integration | PHPUnit + SQLite in-memory + real `DoctrineTransport` | DoctrineTransportStatsCollector, run twice from one abstract case (`PhpSerializer` and `Serializer`): dispatch via transport, manipulate `delivered_at`/`available_at`, send to failure transport, assert counts/ages/breakdown/sampling/missing table, and a hand-written row whose class no longer exists |
| Functional | PHPUnit + minimal `TestKernel` | TransportDiscoveryPass against a real `framework.messenger` config, `StatsReportBuilder` over Doctrine transports plus a count-aware transport from a test transport factory and a transport on an unreachable connection, routes, 404/401/403/200/503 behaviour, console command exit codes |

CI (GitHub Actions): `lowest` (PHP 8.1, Symfony 5.4, DBAL 2, `--prefer-lowest`),
`highest` (latest PHP 8, Symfony 7, DBAL 4), plus `mysql` (highest + MySQL 8
service running the integration suite). PHPStan level max and php-cs-fixer
`@Symfony` + `declare_strict_types` on every job.

## 11. Delivery order

1. Skeleton: composer.json, bundle class, extension, configuration, CI, tooling.
2. Report model, Clock, ThresholdEvaluator (unit).
3. DoctrineDsnParser, TransportDiscoveryPass, registry (unit + functional).
4. DoctrineTransportStatsCollector, MessageRowDecoder (integration, under both serializers).
5. CountOnlyStatsCollector, resolver, StatsReportBuilder, unavailable handling.
6. Views + controllers + token listener (unit + functional).
7. Console command.
8. README (install, config, Uptime Kuma, Prometheus/Grafana, security notes).
9. Wire into a host application via path repository; smoke-test on real data; tag v0.1.0 once
   the remote exists.
