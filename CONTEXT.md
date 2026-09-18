# Messenger Stats Bundle — Domain Glossary

## Transport
A Symfony Messenger transport as configured in the host application (`framework.messenger.transports`). Identified by its configured name (e.g. `async`, `failed`).

## Queue
A named partition inside a Doctrine transport (`queue_name`). One Transport may contain several Queues; non-Doctrine transports have exactly one implicit Queue.

## Stats Collector
The source of statistics for one Transport. Each Transport is served by exactly one Stats Collector, chosen by the Transport's kind.

## Detail Level
How much a Stats Collector can report for a Transport: `count` (only the number of messages) or `full` (per-queue breakdown, ages, message classes, failures). v1: Doctrine transports are `full`, all others `count`.

## Failure Transport
The Transport the host configured as `failure_transport`. Messages there are Failed Messages.

## Message States (Doctrine, `full` Detail Level)
- **Pending** — not yet delivered and available now (`available_at <= now`, `delivered_at` empty).
- **Delayed** — not yet available (`available_at > now`); retries waiting or scheduled messages.
- **In Progress** — delivered to a worker recently (`delivered_at` set, younger than the Stuck Threshold).
- **Stuck** — delivered but older than the Stuck Threshold; the worker probably died mid-message.

## Stuck Threshold
Age of a delivered message after which it counts as Stuck. Defaults to the transport's `redeliver_timeout`.

## Oldest Pending Age
Seconds the head of a Queue has been waiting. Proxy for "is a worker alive".

## Failed Message
A message in the Failure Transport. Reported with class, exception message, failed-at, retry count. The body is never reported.

## Stats Report
The whole response: generated-at time, application identifier, bundle version, and one entry per Transport with its Detail Level.

## Out of scope (v1)
Throughput, worker liveness.

## Stats Token
Static secret shared between one host application and the Monitor. Required to read the Stats Report. An empty token disables the endpoint.

## Allowed Origins
Optional list of client IPs / CIDRs permitted to read the Stats Report, applied in addition to the Stats Token.

## Monitor
The external application that periodically reads Stats Reports from many host applications and raises alerts.

## Threshold
Optional host-side rule on one metric of one Transport: a warning value and a critical value. Absent by default.

## Health Status
`ok`, `warning` or `critical`: the worst level among all breached Thresholds in a Stats Report. `ok` when no Threshold is configured.

## Problem
One breached Threshold: transport, metric, observed value, threshold value, level.

## Class Breakdown
Count of messages per message class within a Queue, computed from at most a Sample Size of the newest messages. Marked as sampled when the Queue is larger than the Sample Size.

## Report View
One rendering of a Stats Report: JSON, Health (status code only) or Metrics (Prometheus exposition). All views are built from the same Stats Report.

## Unavailable Transport
A Transport whose Stats Collector failed. Reported with Detail Level `unavailable` and the failure kind; it never blocks the rest of the Stats Report.
