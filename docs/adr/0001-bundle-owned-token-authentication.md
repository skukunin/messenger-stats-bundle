# Bundle-owned token authentication instead of host firewall integration

Status: accepted

## Context

The bundle exposes operational data (queue depths, message class names,
exception messages) over HTTP. It must be installable in any Symfony
application, and those applications have wildly different `security.yaml`
setups: some use HTTP basic, some JWT, some `security: false` on most paths.
Relying on the host to add a firewall for the bundle's routes means every
installation has to be configured correctly by hand, and one omission exposes
the endpoints publicly.

The consumers are machines (uptime checkers, Prometheus, a future monitor),
each holding one secret per host application. They support a static header
but not interactive login, OAuth or session cookies.

## Decision

- The bundle authenticates its own routes with a static bearer token
  (`Authorization: Bearer <token>`) compared with `hash_equals`, in a
  request listener scoped to the bundle's route names.
- The token is host configuration (`messenger_stats.token`), expected to come
  from an environment variable.
- An empty token disables the routes: they answer 404, not 401, so an
  unconfigured installation reveals nothing.
- An optional IP allowlist (`messenger_stats.allowed_ips`) is applied in
  addition to the token, never instead of it.
- Rejected requests are logged at `warning` with the client IP.
- The bundle does not register a firewall, authenticator, user provider or
  voter, and does not depend on `symfony/security-bundle`.

## Consequences

- Installation is one env variable; there is nothing to get wrong in
  `security.yaml`. Rotation is an env change and a redeploy.
- Hosts that want stronger controls (mTLS, VPN-only, SSO) apply them in front
  of the application; the bundle's token remains as the inner layer.
- Because the check runs before the controller regardless of host firewalls,
  a host firewall that also covers the prefix will authenticate twice. This
  is harmless but the README tells hosts not to do it.
- Changing to firewall-based auth later would break every installed host's
  configuration, which is why this decision is recorded.
