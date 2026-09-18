# Messenger Stats Bundle

A Symfony bundle that reports the state of your Symfony Messenger transports:
queue depths, delayed, in-progress and stuck messages, oldest pending age,
message class breakdown and failed messages. The report is exposed as JSON, as
a health check, as Prometheus metrics and as a console command. It only reads;
it never consumes, retries or removes messages.

**Work in progress** — the API and the configuration are not stable yet, and no
release has been tagged. See [docs/SPEC.md](docs/SPEC.md) for the specification
and [CHANGELOG.md](CHANGELOG.md) for the current state.

## License

Released under the [MIT License](LICENSE).
