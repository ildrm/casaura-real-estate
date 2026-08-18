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

- [ ] Property/listing split, taxonomy, features, amenities, history
- [ ] Guided listing wizard and autosaved drafts
- [ ] Secure media pipeline, storage ports, derivatives, quota enforcement
- [ ] Publication workflow, moderation, quality score

## Phase 3 — Consumer marketplace

- [ ] OpenSearch projection and global/filter/map search
- [ ] Property detail, favorites, reactions, account dashboard
- [ ] PostGIS bounding, radius, polygon, and approximate-location behavior

## Phase 4 — Leads and collaboration

- [ ] Contact-to-lead routing, CRM pipeline, viewings, realtime messages
- [ ] Reminders, notifications, calendar adapters, response-time analytics

## Phase 5 — Agency product

- [ ] Complete storefront/team/opening-hours/newsletter/analytics flows

## Phase 6 — Administration

- [ ] Moderation, settings, RBAC editor, flag UI, health, audit operations

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
