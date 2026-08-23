# Backup and restore runbook

## Required controls

- PostgreSQL uses encrypted managed backups and point-in-time recovery. Target RPO:
  15 minutes. Target RTO: 4 hours until the first measured drill establishes a
  tighter reliable value.
- The private media/export bucket uses encryption, versioning, lifecycle rules,
  access logging, and separate least-privilege identities. Quarantine and export
  expiry remain application-controlled.
- Redis is non-authoritative. Sessions, cache, rate-limit counters, and queued work
  may be rebuilt; durable failed-job and outbox records remain in PostgreSQL.
- Backup access is separate from application runtime access. Restore credentials
  are break-glass secrets with access logging and periodic rotation.

## Quarterly restore drill

1. Open an approved isolated restore environment with no outbound customer mail.
2. Select a random recovery point inside the retention window and restore PostgreSQL.
3. Restore a versioned sample of media objects and verify checksums against database
   records with `php artisan media:reconcile`.
4. Deploy the release digest corresponding to the restored schema, run migrations
   only if the drill explicitly tests forward recovery, and start worker/scheduler.
5. Run readiness and critical smoke journeys. Verify tenant isolation, public/private
   media separation, search rebuild, consent records, audit continuity, and privacy
   export decryption.
6. Measure achieved RPO/RTO, document missing objects or consistency errors, destroy
   the isolated environment, and retain non-sensitive evidence in the release system.

A release is blocked when no successful drill exists for the selected hosting stack,
when achieved RPO/RTO exceeds the approved targets, or when deletion/lifecycle rules
cannot be demonstrated on restored data.
