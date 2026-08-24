# Production release plan and status

Status date: 2026-08-24. The engineering release candidate is implemented in this
repository. Production launch remains **no-go** until the external approvals and
environment drills in the last section are completed on the selected hosting stack.

The current release scope is Phases 1–10 plus production hardening. Every planned
source-code phase is complete. Live launch still depends on provider contracts,
credentials, infrastructure evidence, legal/privacy review, and organizational sign-off.

## Completed implementation

1. **Authorization containment:** active-principal checks, server-side tenant context,
   platform/agency authority separation, cross-tenant negative tests, role ceilings,
   last-owner protection, and centralized entitlement enforcement.
2. **Identity and membership:** verified email, non-enumerating recovery, session/token
   security versions, required TOTP MFA for privileged identities, immutable consent
   evidence, expiring/rotating/cancellable invitations, and public team opt-in.
3. **Core workflows:** explicit listing and lead transitions, permission-aware complete
   web actions, idempotent concurrent public inquiry handling, authorized calendars,
   scheduler singleton locks, and production-disabled local newsletter delivery.
4. **Marketplace correctness:** SQL keyset pagination, stable cursors, PostgreSQL spatial
   filters with dateline support, bounded inputs, safe public discovery, canonical SEO,
   deterministic sitemap generation, and production locale/currency/unit validation.
5. **Media:** MIME/decode validation, private originals, re-encoded WebP derivatives,
   quotas, ClamAV fail-closed production scanning, quarantine, scheduled purge, object
   reconciliation, private S3 adapter, and destructive-search reset guards.
6. **Privacy:** persisted inquiry consent evidence, encrypted subject exports, reviewed
   deletion/anonymization, analytics pseudonymization/deletion, expired-export cleanup,
   invitation-orphan cleanup, privacy UI, and operator runbook.
7. **Platform:** production configuration guard, pinned non-root containers, separate
   API/web/worker/scheduler/scanner processes, immutable digest deployment contract,
   CI quality/contract/audit/secret gates, image scanning/signing/provenance workflow,
   liveness/readiness, structured logs, release/request correlation, and heartbeats.
8. **Operations:** deployment/rollback, backup/restore, incident, privacy, and SLO/alert
   runbooks; route/OpenAPI parity is executable in the test suite.
9. **Data integrations:** provider-neutral ports, production RESO Web API/OData and
   OAuth client-credentials support, live metadata discovery, mapping, incremental
   queued ingestion, immutable provenance, quarantine, and reversible duplicate review.
10. **Advanced marketplace:** private/collaborative ordered collections, single-use
    invitations, two-to-five listing comparisons/history, organic recommendations,
    privacy-thresholded map layers, and USD-only aggregate analytics.
11. **Grounded AI:** OpenAI Responses API and deterministic adapters, schema-constrained
    search/comparison/listing outputs, public citations, explicit filter confirmation,
    human suggestion apply, redaction/refusal controls, retention, feedback, and safety logs.
12. **Monetization:** Stripe hosted checkout/portal, automatic tax, signed monotonic
    webhook processing, safe invoice projections, versioned promotion policy, impression
    caps/deduplication, and labeled paid inventory isolated from organic ranking.

## Verified release-candidate evidence

- Laravel: 101 tests and 1,149 assertions passed; Pint and strict Composer validation
  passed.
- Browser: 12/12 Playwright journeys passed across desktop Chromium and Pixel 7,
  including signed email verification, real TOTP MFA setup, integrations, collections,
  comparisons, grounded AI, listing suggestions, market intelligence, and billing.
- Web: ESLint, TypeScript, and the 35-route optimized Next.js build passed.
- Contract/supply chain: exact route/method parity, pinned Redocly lint, workflow YAML,
  secret scan, npm audit (0 vulnerabilities), and Composer audit (no advisories) passed.
- Deployment definitions: the production Compose topology and immutable image contract
  validate, and all three pinned production images build locally from a restricted
  Docker context. The API image's required extensions and FPM configuration passed as
  the non-root `casaura` user; nginx configuration passed as UID 101 under read-only,
  capability-dropped constraints; and the similarly hardened web image returned HTTP
  200. High/critical scanning, signing, registry promotion, and staging runtime evidence
  remain mandatory CI/environment gates before launch.

## Release verification sequence

1. Run Composer validation/audit, Pint, and the complete Laravel suite on PostgreSQL.
2. Run npm audit, ESLint, TypeScript, the production Next.js build, and Playwright on
   desktop/mobile against production-like services.
3. Lint OpenAPI and verify exact route/method parity.
4. Build pinned containers, scan high/critical vulnerabilities, generate SBOM and
   provenance, sign digests, and validate non-root/read-only execution.
5. Restore a production-like backup, run migrations once, reconcile media, rebuild
   search if required, and measure RPO/RTO.
6. Deploy the immutable candidate to staging, exercise critical journeys and failure
   modes, observe alerts/heartbeats, then record formal approvals.
7. Certify the licensed RESO feed against live metadata and an exact-replay incremental
   sync; reconcile counts, attribution/photo rights, deletions, quarantine, and duplicate
   review before enabling the tenant flag.
8. Certify the OpenAI project/model with redaction/refusal fixtures, a human review of
   grounded citations and listing suggestions, latency/error-budget tests, spend alerts,
   and an approved data-processing record before enabling AI flags.
9. Certify Stripe test-to-live configuration with Checkout automatic tax, Portal return
   URLs, webhook signature/replay/ordering tests, and finance reconciliation for invoices,
   refunds, disputes, cancellations, and promotion entitlements.

## External launch gates

- Approve the launch profile: United States, USD, metric canonical storage with the
  configured display unit, operator legal identity/address, controller role, support
  contact, and customer segment.
- Obtain legal/privacy approval for the versioned terms, privacy notice, inquiry and
  registration consent text, retention exceptions, fair-housing policy, subprocessors,
  data residency, breach process, and user-rights handling.
- Select and provision hosting, domain/DNS/TLS/WAF, managed PostgreSQL/Redis/object
  storage, mail provider, secret/KMS system, monitoring/paging, and on-call ownership.
- Execute a licensed RESO/MLS agreement and provision the endpoint, OAuth client secret,
  allowed origins, rights/attribution/photo/retention terms, and staging certification.
- Approve OpenAI as a subprocessor, provision the live Responses API project/model and
  budget controls, mount its secret, and complete safety/privacy/quality acceptance.
- Complete Stripe merchant and tax registration, provision the live USD price and
  restricted keys/webhook secret, register the production webhook, and complete finance
  reconciliation acceptance. Stripe automatic tax is the approved tax mode.
- Prove mail domain authentication/deliverability, ClamAV availability, least-privilege
  identities, backup/restore targets, capacity/load targets, and incident exercises.
- Record product, engineering, security, legal/privacy, and operations sign-off in the
  release ticket. Repository checkboxes do not substitute for these approvals.

The executable activation procedure and evidence checklist are in
[provider-activation.md](../runbooks/provider-activation.md).
