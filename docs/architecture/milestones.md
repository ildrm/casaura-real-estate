# Delivery milestones and live checklist

> **Last reconciled:** 2026-08-24  
> **Current repository scope:** Phases 1–10 plus production release hardening are implemented and verified.  
> **Release decision:** Engineering release candidate complete; production activation awaits the external launch gates below.

Each delivery phase ships as vertical slices with persistence, policy, API, UI, loading/error/empty states, accessibility, observability, and tests. A checked item means implemented and verified in this repository. Unchecked external launch gates require live provider access, infrastructure, or organizational approval; they are deployment prerequisites rather than missing source-code features.

## Phase 1 — Foundation

- [x] Greenfield architecture assessment and assumptions
- [x] Next.js 16 web and Laravel 13 API monorepo
- [x] PostgreSQL/PostGIS, Redis, OpenSearch, MinIO, and Mailpit local stack
- [x] Versioned `/api/v1` foundation and OpenAPI contract
- [x] User authentication endpoints and first-party SPA session model
- [x] Agency registration, profile, branches, memberships, and verification state
- [x] Permission-first RBAC seed templates
- [x] Server-side tenant context, policy checks, and cross-tenant tests
- [x] Plans, entitlements, promotional dates, and subscriptions
- [x] Hierarchical feature flags and audit model
- [x] Original Casaura design system and responsive public/agency shells
- [x] Public homepage, search handoff, sign-in, agency registration, and dashboard foundation
- [x] CI-ready lint, type, build, backend tests, and formatter commands
- [x] Provider-agnostic verified email, recovery, privileged MFA, consent, invitation, and credential-revocation wiring
- [x] Production process manifests, digest-only images, CI supply-chain gates, and secret-manager environment contract

## Phase 2 — Listing core

- [x] Property/listing split, taxonomy, features, amenities, history
- [x] Guided listing wizard and autosaved drafts
- [x] Secure media pipeline, storage ports, derivatives, quota enforcement
- [x] Publication workflow, moderation, quality score

## Phase 3 — Consumer marketplace

- [x] OpenSearch projection and global/filter/map search
- [x] Property detail, favorites, reactions, account dashboard
- [x] PostGIS bounding, radius, polygon, and approximate-location behavior

## Phase 4 — Leads and collaboration

- [x] Contact-to-lead routing, CRM pipeline, viewings, realtime messages
- [x] Reminders, notifications, calendar adapters, response-time analytics

## Phase 5 — Agency product

- [x] Complete storefront/team/opening-hours/newsletter/analytics flows

## Phase 6 — Administration

- [x] Moderation, settings, RBAC editor, flag UI, health, audit operations

## Repository production release hardening

- [x] Active-principal, tenant, platform/agency authority, and cross-tenant containment
- [x] Centralized feature, quota, workflow, and publication entitlement enforcement
- [x] Verified identity, privileged TOTP MFA, session/token revocation, consent, and membership lifecycle
- [x] SQL keyset/spatial search, safe discovery/SEO, complete listing/lead actions, and scheduler locks
- [x] Fail-closed malware scanning, private S3 storage, quarantine, purge, and reconciliation
- [x] Privacy request/export/deletion flows, retention enforcement, consent evidence, and operator controls
- [x] Production boot guard, health/readiness, heartbeats, structured logs, request/release correlation, and runbooks
- [x] CI browser/contract/audit/secret gates plus container build, scan, SBOM, provenance, and signing workflows

## External production launch gates

These items cannot be completed in source control and remain the only blockers to production activation:

- [ ] Approve the launch profile, jurisdiction, legal documents, operator identity, and support ownership
- [ ] Provision hosting, DNS/TLS/WAF, secret/KMS, PostgreSQL, Redis, private object storage, monitoring, and paging
- [ ] Configure the production mail provider, authenticate the sending domain, and approve deliverability
- [ ] Contract with a licensed RESO Web API feed, provision its OAuth client in the secret manager, confirm display/photo/retention rights, and complete a staging metadata plus incremental-sync certification
- [ ] Approve the OpenAI data-processing/subprocessor terms, provision the production project/model/budget and secret, and complete staging safety, redaction, latency, and spend review
- [ ] Complete Stripe merchant/tax onboarding for the United States and USD, create the live price, register the signed webhook, provision secrets, and reconcile checkout/subscription/invoice/refund/dispute events in staging
- [ ] Run CI high/critical image scans, generate SBOM/provenance, sign the immutable digests, and promote them to the production registry
- [ ] Complete staging critical-journey, capacity/load, dependency-failure, backup-restore, and incident-response exercises
- [ ] Record product, engineering, security, legal/privacy, and operations approval in the release record

## Phase 7 — Data integrations

- [x] Provider-neutral adapter contract plus production RESO Web API/OData 4.0/4.01 and OAuth client-credentials adapter
- [x] Tenant-owned connections, named secret references, live metadata discovery, versioned field mappings, and rights snapshots
- [x] Idempotent queued full/incremental sync with committed cursors, immutable provenance, validation quarantine, withdrawal handling, and search projection updates
- [x] Human duplicate review with source-to-canonical linking, rejection, and reversible merge evidence
- [x] Responsive integrations workspace with connection lifecycle, mapping, sync activity, errors, and duplicate decisions

## Phase 8 — Advanced marketplace

- [x] Private and collaborative collections with ordered items, expiring single-use invitations, revocation, and authorization boundaries
- [x] Two-to-five property comparison with idempotent private history and deletion
- [x] Deterministic organic recommendations isolated from sponsored inventory
- [x] Privacy-thresholded map layers and market aggregates with USD-only price cohorts
- [x] Responsive collections, comparison, recommendations, and market-intelligence experiences

## Phase 9 — Grounded AI

- [x] Provider-neutral AI contract plus production OpenAI Responses API adapter with strict JSON schema, `store: false`, bounded tokens, and one malformed/provider retry
- [x] Grounded conversational search and comparison answers with public listing citations and explicit proposed-filter confirmation
- [x] Human-reviewed, listing-version-bound copy suggestions with selective apply and no autonomous publication
- [x] Direct contact/street-address redaction, request refusal rules, redacted safety events, feedback, retention, and user deletion
- [x] Responsive assistant, comparison, listing-writer, and administrative safety experiences

## Phase 10 — Monetization

- [x] Provider-neutral billing contract plus production Stripe Checkout, Billing Portal, subscription, automatic-tax, and signed-webhook adapter
- [x] Idempotent hosted checkout, monotonic subscription/invoice state, refund/dispute handling, safe invoice links, and hash-only webhook evidence
- [x] Immutable/versioned promotion policies with plan eligibility, schedule/cap enforcement, privacy-preserving impression deduplication, and unavailable-listing auto-pause
- [x] Clearly labeled sponsored surfaces kept separate from deterministic organic ranking
- [x] Responsive agency billing/promotion workspace and permissioned platform release controls

## Quality gate applied after every phase

1. Unit/integration/API/E2E tests pass.
2. Static analysis, formatting, dependency, and secret checks pass.
3. Authorization and tenant isolation are reviewed with negative tests.
4. Desktop/mobile layouts are inspected against accepted concepts.
5. Accessibility keyboard, focus, landmarks, contrast, labels, and reduced motion are checked.
6. Structured logging, request IDs, error redaction, and health signals are present.
7. Architecture, OpenAPI, migrations, and operational documentation match shipped behavior.

## Historical phase verification snapshots

These snapshots preserve the evidence recorded when each phase closed. They are not the current aggregate test totals; the current release-candidate evidence appears after them.

### Phase 1 snapshot — 2026-08-18

- Laravel: 7 tests passed, 29 assertions; tenant isolation includes negative cross-agency cases.
- Playwright Chromium: 4 journeys passed across desktop and Pixel-sized mobile projects.
- Web: ESLint, TypeScript, and the Next.js production build passed.
- API: Laravel Pint and Composer validation passed.
- Dependencies: npm and Composer advisory audits reported no known vulnerabilities.
- Visual: desktop and mobile captures were compared with both accepted concepts; see the [fidelity ledger](../design/fidelity-ledger.md).

### Phase 2 snapshot — 2026-08-18

- Specification: 19 functional requirements, 11 non-functional requirements, 17 acceptance criteria, and 10 edge cases validated at 100/100 by the strict specification validator.
- Laravel: 15 tests passed with 129 assertions, including tenant-negative listing/media cases, optimistic concurrency, immutable history, workflow guards, private derivatives, idempotency, quota, reorder, and deletion behavior.
- Playwright Chromium: all 6 journeys passed across desktop Chrome and Pixel-sized mobile projects; the listing journey creates, autosaves, reloads, and resumes a real tenant-owned draft.
- Web: ESLint, TypeScript, and the Next.js production build passed with inventory, new-listing, and dynamic editor routes.
- API: Laravel Pint and the complete test suite passed after formatting.
- Visual: stable desktop and 390×844 mobile captures were inspected beside both approved Phase 2 concepts; see the [fidelity ledger](../design/fidelity-ledger.md).

### Phase 3 snapshot — 2026-08-18

- Specification: 18 functional requirements, 12 non-functional requirements, 15 acceptance criteria, and 10 edge cases validated at 100/100 by the strict specification validator.
- Laravel: 23 tests passed with 267 assertions, including unpublished exclusion, public-field allowlisting, approximate-location displacement, bounds/radius filters, projection rebuild, private engagement, and public derivative delivery.
- Playwright Chromium: all 8 journeys passed across desktop Chrome and Pixel-sized mobile projects; the marketplace journey publishes real inventory, searches it, switches list/map views, opens the detail route, persists a favorite, and verifies the account dashboard.
- Web: ESLint, TypeScript, and the Next.js production build passed with search, dynamic property detail, and account routes.
- API: Laravel Pint and the complete test suite passed after formatting; the OpenAPI YAML parses successfully.
- Visual: desktop and 390-pixel mobile search/detail captures were inspected beside both approved Phase 3 concepts; the mobile detail reading order was corrected during acceptance. See the [fidelity ledger](../design/fidelity-ledger.md).
- Integration note: the database search backend and SQLite spatial-equivalence path are exercised locally. The OpenSearch adapter and PostGIS expressions are implemented behind ports for the configured production services; live-provider validation remains part of deployment integration.

### Phase 4 snapshot — 2026-08-19

- Specification: the leads/collaboration specification passed strict validation at 100/100 with no findings.
- API: public inquiry routing, tenant CRM operations, UUIDv7 message cursors, viewing transitions/conflict warnings, reminders, notifications, calendar export, and canonical response metrics are represented in the versioned contract.
- Web: the property inquiry, agency lead desk, account collaboration, messaging, viewing, reminder, notification, and response-metric flows have explicit loading/error/empty/conflict states on desktop and mobile.
- Tests: the complete Laravel suite passed with 41 tests and 561 assertions; the complete Playwright suite passed 10/10 journeys across desktop Chromium and Pixel 7.

### Phase 5 snapshot — 2026-08-19

- Specification: the agency-product specification passed strict validation at 100/100 with no findings.
- API: safe storefront, weekly/exceptional hours, quota-bound team operations, consented subscriptions, single-send campaigns through a replaceable adapter, privacy-safe event recording, and date-bounded analytics are covered by feature tests and OpenAPI.
- Web: the public storefront and agency growth workspace use real tenant projections; demo profile defaults, invented readiness, and synthetic dashboard metrics were removed during review.
- Responsive acceptance: long agency/listing content has no body overflow at 375 or 390 pixels, operational labels are at least 13 pixels, and mobile actions/consent targets are at least 44 pixels.

### Phase 6 snapshot — 2026-08-19

- Specification: the administration specification passed strict validation at 100/100 with no findings.
- Authorization: agency roles do not inherit platform authority; moderation, settings, flags, custom RBAC, audits, and health each enforce named platform permissions server-side, with explicit partial/denied console states.
- Contract and database: every `/api/v1` Laravel path/method has an exact OpenAPI match; the YAML parses successfully; all Phase 1–6 migrations and seeds completed from an empty PostgreSQL/PostGIS database.
- Quality: Laravel Pint, Composer validation/advisory audit, ESLint, TypeScript, the Next.js production build (18 routes), npm advisory audit, and repository diff checks passed. Dependency audits reported no known vulnerabilities.
- Visual: the required independent frontend evaluation passed on round two at 10/12 with no blocking items after desktop/mobile screenshots, computed typography/touch checks, profile persistence, RBAC synchronization, and responsive overflow review.

## Current production release-candidate verification — 2026-08-24

- Specifications: Phases 7–10 each passed the strict specification validator at 100/100 before implementation.
- API: the complete Laravel suite passed with 101 tests and 1,149 assertions, including authorization/tenant negatives, entitlement enforcement, identity/MFA/revocation, RESO metadata and exact-replay ingestion, collection/comparison privacy, aggregate suppression, grounded-AI retry/redaction, Stripe webhook ordering/signatures, promotion isolation, the production guard, and exact OpenAPI route/method parity. Laravel Pint and strict Composer validation passed.
- Browser: all 12 Playwright journeys passed across desktop Chromium and Pixel 7. The matrix adds connection lifecycle/sync, collection membership and item flows, proposed AI filter confirmation, comparison history, listing suggestion apply, market suppression, billing/promotion, and responsive overflow checks.
- Web and contract: ESLint, TypeScript, the 35-route Next.js production build, workflow YAML parsing, the repository secret scan, and pinned Redocly OpenAPI lint all passed.
- Dependencies: npm reported 0 vulnerabilities and Composer reported no security advisories.
- Visual: Phase 7–10 desktop/mobile implementation captures were inspected beside their accepted concepts; composition, hierarchy, surfaces, controls, and responsive behavior are recorded in the [fidelity ledger](../design/fidelity-ledger.md).
- Containers: all three pinned production images build locally from the restricted Docker context. The API image runs as `casaura`, loads GD/Intl/PCNTL/PostgreSQL PDO/Redis/OPcache/Zip, and passes `php-fpm -t`; nginx passes its configuration test as UID 101 with a read-only filesystem, dropped capabilities, and no-new-privileges; the web image runs as `casaura` under the same hardening model and returned HTTP 200. The build also verifies the SHA-256-pinned phpredis 6.3.0 source required by PHP 8.5.
- Platform: the production Compose topology and pinned image contract validate, and CI builds every image and runs high/critical scanning, SBOM/provenance generation, signing, and the browser matrix. CI scan/sign/promotion plus staging runtime evidence remain mandatory release gates.
- Status: all planned source-code Phases 1–10 are complete and the repository is an engineering release candidate. Production remains **no-go** until the unchecked external approvals, live RESO/OpenAI/Stripe and mail provisioning, restore/load/incident drills, CI registry promotion, and recorded sign-offs are complete.
