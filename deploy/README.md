# Production deployment contract

The production-like Compose file defines the required process topology: web,
API gateway, PHP-FPM API, queue worker, scheduler, and malware scanner. PostgreSQL,
Redis, object storage, mail delivery, TLS/WAF, backups, and metrics/log collection
are managed services supplied by the hosting platform.

Release images must be built by CI from the Dockerfiles in `infra/docker`, scanned,
signed, and referenced in `compose.production.yml` by immutable `@sha256` digest.
The same digest is promoted; source code is never built on a production host.
The web image's public configuration is intentionally baked at build time, so a
separate immutable web artifact is produced for each public origin.

Before starting a release, inject every required variable from the secret manager,
run `php artisan config:cache`, then run `php artisan migrate --force` as a one-off
release task. Do not run migrations concurrently from application replicas.
Mount provider credentials as read-only named files. Startup fails when the production
environment guard detects insecure defaults or non-live/incomplete RESO, OpenAI, or
Stripe configuration. Queue workers must subscribe to `default,integrations`.
The Compose contract defaults provider-dependent feature keys into `FEATURE_FORCE_OFF`;
an operator must explicitly remove only the certified keys during controlled activation.

Traffic may be routed only after `/api/v1/health/live` and
`/api/v1/health/ready` succeed. Readiness includes PostgreSQL, Redis, object
storage, worker, and scheduler signals. Roll back by restoring the previous image
digests; database changes must remain backward compatible with that prior release.

The detailed promotion, backup, restore, incident, rollback, and live provider
certification procedures live under `docs/runbooks`; start with
`docs/runbooks/provider-activation.md` before enabling Phase 7, 9, or 10 flags.
