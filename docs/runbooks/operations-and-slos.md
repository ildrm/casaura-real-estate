# Operations, alerts, and service objectives

## Initial objectives

These are launch targets pending measured staging and production baselines:

- Public/API availability: 99.9% per calendar month.
- API latency: 95% under 500 ms and 99% under 1.5 s, excluding media transfer.
- Lead acceptance: 99.9% of valid requests persist within 2 seconds.
- Queue delay: 95% under 60 seconds; scheduler heartbeat age under 180 seconds.
- Restore: RPO 15 minutes and RTO 4 hours.

Page on sustained readiness failure, 5xx ratio above 2% for five minutes, stale worker
or scheduler heartbeat, any failed job, queue backlog above 1,000, search projection
backlog above 100, mail/scanner failure, storage errors, or abnormal authentication
failures. Ticket slower error-budget burn and capacity trends.

The public liveness/readiness endpoints expose no component details. Authorized
platform health exposes safe component states and backlog counts. Logs are JSON on
stderr and carry `Request-ID` and `Release-ID`; dashboards must allow both fields to
be searched without exposing PII. Synthetic probes cover sign-in/recovery, published
search/detail, lead submission, and a non-delivering internal media scan fixture.
