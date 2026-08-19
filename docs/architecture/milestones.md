# Delivery milestones and live checklist

Each phase ships as vertical slices with persistence, policy, API, UI, loading/error/empty states, accessibility, observability, and tests. A checked item means implemented in this repository; later phases remain intentionally unchecked.

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
- [ ] Production identity provider/email verification/MFA wiring (requires provider selection)
- [ ] Production infrastructure manifests and secret manager integration (requires hosting selection)

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

## Phase 7 — Data integrations

- [ ] Provider ports, RESO/MLS connectors, import mapping, deduplication review

## Phase 8 — Advanced marketplace

- [ ] Collections, comparison, recommendations, map layers, market analytics

## Phase 9 — Grounded AI

- [ ] Conversational search, comparison assistant, listing assistant, safety logs

## Phase 10 — Monetization

- [ ] Billing provider, invoices, promotion policy UI, clearly labeled promotion inventory

## Quality gate applied after every phase

1. Unit/integration/API/E2E tests pass.
2. Static analysis, formatting, dependency, and secret checks pass.
3. Authorization and tenant isolation are reviewed with negative tests.
4. Desktop/mobile layouts are inspected against accepted concepts.
5. Accessibility keyboard, focus, landmarks, contrast, labels, and reduced motion are checked.
6. Structured logging, request IDs, error redaction, and health signals are present.
7. Architecture, OpenAPI, migrations, and operational documentation match shipped behavior.

## Latest Phase 1 verification — 2026-08-18

- Laravel: 7 tests passed, 29 assertions; tenant isolation includes negative cross-agency cases.
- Playwright Chromium: 4 journeys passed across desktop and Pixel-sized mobile projects.
- Web: ESLint, TypeScript, and the Next.js production build passed.
- API: Laravel Pint and Composer validation passed.
- Dependencies: npm and Composer advisory audits reported no known vulnerabilities.
- Visual: desktop and mobile captures were compared with both accepted concepts; see the [fidelity ledger](../design/fidelity-ledger.md).

## Latest Phase 2 verification — 2026-08-18

- Specification: 19 functional requirements, 11 non-functional requirements, 17 acceptance criteria, and 10 edge cases validated at 100/100 by the strict specification validator.
- Laravel: 15 tests passed with 129 assertions, including tenant-negative listing/media cases, optimistic concurrency, immutable history, workflow guards, private derivatives, idempotency, quota, reorder, and deletion behavior.
- Playwright Chromium: all 6 journeys passed across desktop Chrome and Pixel-sized mobile projects; the listing journey creates, autosaves, reloads, and resumes a real tenant-owned draft.
- Web: ESLint, TypeScript, and the Next.js production build passed with inventory, new-listing, and dynamic editor routes.
- API: Laravel Pint and the complete test suite passed after formatting.
- Visual: stable desktop and 390×844 mobile captures were inspected beside both approved Phase 2 concepts; see the [fidelity ledger](../design/fidelity-ledger.md).

## Latest Phase 3 verification — 2026-08-18

- Specification: 18 functional requirements, 12 non-functional requirements, 15 acceptance criteria, and 10 edge cases validated at 100/100 by the strict specification validator.
- Laravel: 23 tests passed with 267 assertions, including unpublished exclusion, public-field allowlisting, approximate-location displacement, bounds/radius filters, projection rebuild, private engagement, and public derivative delivery.
- Playwright Chromium: all 8 journeys passed across desktop Chrome and Pixel-sized mobile projects; the marketplace journey publishes real inventory, searches it, switches list/map views, opens the detail route, persists a favorite, and verifies the account dashboard.
- Web: ESLint, TypeScript, and the Next.js production build passed with search, dynamic property detail, and account routes.
- API: Laravel Pint and the complete test suite passed after formatting; the OpenAPI YAML parses successfully.
- Visual: desktop and 390-pixel mobile search/detail captures were inspected beside both approved Phase 3 concepts; the mobile detail reading order was corrected during acceptance. See the [fidelity ledger](../design/fidelity-ledger.md).
- Integration note: the database search backend and SQLite spatial-equivalence path are exercised locally. The OpenSearch adapter and PostGIS expressions are implemented behind ports for the configured production services; live-provider validation remains part of deployment integration.

## Latest Phase 4 verification — 2026-08-19

- Specification: the leads/collaboration specification passed strict validation at 100/100 with no findings.
- API: public inquiry routing, tenant CRM operations, UUIDv7 message cursors, viewing transitions/conflict warnings, reminders, notifications, calendar export, and canonical response metrics are represented in the versioned contract.
- Web: the property inquiry, agency lead desk, account collaboration, messaging, viewing, reminder, notification, and response-metric flows have explicit loading/error/empty/conflict states on desktop and mobile.
- Tests: the complete Laravel suite passed with 41 tests and 561 assertions; the complete Playwright suite passed 10/10 journeys across desktop Chromium and Pixel 7.

## Latest Phase 5 verification — 2026-08-19

- Specification: the agency-product specification passed strict validation at 100/100 with no findings.
- API: safe storefront, weekly/exceptional hours, quota-bound team operations, consented subscriptions, single-send campaigns through a replaceable adapter, privacy-safe event recording, and date-bounded analytics are covered by feature tests and OpenAPI.
- Web: the public storefront and agency growth workspace use real tenant projections; demo profile defaults, invented readiness, and synthetic dashboard metrics were removed during review.
- Responsive acceptance: long agency/listing content has no body overflow at 375 or 390 pixels, operational labels are at least 13 pixels, and mobile actions/consent targets are at least 44 pixels.

## Latest Phase 6 verification — 2026-08-19

- Specification: the administration specification passed strict validation at 100/100 with no findings.
- Authorization: agency roles do not inherit platform authority; moderation, settings, flags, custom RBAC, audits, and health each enforce named platform permissions server-side, with explicit partial/denied console states.
- Contract and database: every `/api/v1` Laravel path/method has an exact OpenAPI match; the YAML parses successfully; all Phase 1–6 migrations and seeds completed from an empty PostgreSQL/PostGIS database.
- Quality: Laravel Pint, Composer validation/advisory audit, ESLint, TypeScript, the Next.js production build (18 routes), npm advisory audit, and repository diff checks passed. Dependency audits reported no known vulnerabilities.
- Visual: the required independent frontend evaluation passed on round two at 10/12 with no blocking items after desktop/mobile screenshots, computed typography/touch checks, profile persistence, RBAC synchronization, and responsive overflow review.
