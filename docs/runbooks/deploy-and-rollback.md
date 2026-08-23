# Deployment and rollback runbook

## Preconditions

- CI is green for the exact commit. The three release images are built, scanned,
  signed, and referenced by digest.
- Product owner, security, privacy/legal, and operations approvals are recorded in
  the release ticket. Approved operator identity, jurisdiction, support address,
  legal text, currency, units, and country are configured.
- Managed PostgreSQL PITR, Redis, private object storage, mail delivery, TLS/WAF,
  log collection, metrics, alerts, queue workers, scheduler, and ClamAV are healthy.
- A backup is taken and its restore procedure has a successful recent drill.

## Deploy

1. Set `RELEASE_ID` to the source commit and inject secrets from the platform secret
   manager. Never place populated production environment files in the repository.
2. Run `php artisan config:cache` in a one-off task. The production environment
   guard must pass; do not disable it to continue a release.
3. Run `php artisan migrate --force` once. Migrations must be backward compatible
   with the currently serving image.
4. Start API, worker, scheduler, web, gateway, and scanner using the same approved
   digest set. Wait for `/api/v1/health/live`, then `/api/v1/health/ready`.
5. Shift a small traffic percentage and verify authentication, invitation, listing
   create/edit/publish, media scan/upload, public search/detail, lead creation,
   privacy export, mail delivery, request IDs, queues, and scheduler heartbeats.
6. Shift remaining traffic while monitoring the release dashboard and error budget.
   Record image digests, migration batch, smoke evidence, operator, and timestamps.

## Roll back

1. Stop traffic increase and preserve logs/traces using `Request-ID` and `Release-ID`.
2. Restore the prior approved image digests. Do not reverse a database migration
   unless its documented down path is explicitly approved and data-safe.
3. If the schema is incompatible with the prior image, deploy the prepared forward
   compatibility fix. A database restore is a last resort and requires incident
   command plus explicit acceptance of the recovery-point data loss.
4. Verify readiness and the critical smoke journeys, then record the incident and
   create follow-up actions. Rotate any credential whose exposure is suspected.

## Automatic no-go conditions

- Any production guard, migration, readiness, smoke, image scan, signature, or
  contract check fails.
- Worker or scheduler heartbeat is stale; failed jobs are non-zero; search or queue
  backlogs exceed their release threshold.
- Legal/operator variables contain placeholders, mail uses a local/log adapter,
  storage is local/public, malware scanning is unavailable, or backups are untested.
