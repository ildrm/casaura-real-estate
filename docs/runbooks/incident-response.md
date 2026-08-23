# Incident response runbook

## Severity and first response

- **SEV-1:** confirmed cross-tenant disclosure, credential/key compromise, destructive
  data loss, or broad outage. Page incident commander and security/privacy leads;
  stop risky writes or traffic immediately.
- **SEV-2:** degraded critical journey, growing queue/search backlog, failed mail,
  scanner outage, or readiness failure with a safe fallback. Assign an owner and
  mitigation within 30 minutes.
- **SEV-3:** localized or non-critical defect without data/security impact. Track in
  the normal engineering queue.

Use `Request-ID`, `Release-ID`, actor, tenant, route name, and timestamp to correlate
structured logs. Never paste passwords, session cookies, reset/invitation links, MFA
secrets, recovery codes, full inquiry bodies, or raw exports into an incident channel.

## Containment and recovery

1. Declare severity, commander, scribe, affected release, start time, and customer
   impact. Preserve evidence with least-privilege access.
2. Disable the narrow feature through the emergency kill switch when possible;
   suspend affected principals/tenants or roll back images when necessary.
3. For suspected secret exposure, revoke and rotate the credential, invalidate
   sessions/tokens, and audit access. For tenant leakage, stop the relevant endpoint
   before attempting data correction.
4. Recover using the deployment or backup runbook. Confirm all critical journeys and
   security invariants before resolving.
5. Security/privacy owners determine legal and customer notification obligations for
   the approved jurisdiction. Do not make notification decisions ad hoc.
6. Complete a blameless review with timeline, root cause, detection gap, corrective
   actions, owners, and deadlines.
