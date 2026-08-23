# Architecture assessment

## Existing repository assessment

The starting repository contained only a license and a one-line README, so there were no compatibility constraints, migrations, production data, or legacy contracts to preserve. A modular monolith is the right first architecture: it keeps cross-domain transactions and operational complexity manageable while retaining clean boundaries that can later be extracted around search, media, notifications, realtime messaging, and ingestion.

## Assumptions

1. The approved launch profile is United States and USD. Production builds require explicit units, locale, country, operator, and legal version; counsel-approved consent, retention exceptions, fair-housing, Stripe automatic-tax, and state/local obligations remain launch gates.
2. Agencies are the tenant boundary. A user may belong to multiple agencies and must explicitly select an active agency context.
3. PostgreSQL/PostGIS is authoritative. OpenSearch, Redis, analytics stores, and CDN caches are derived or ephemeral.
4. The first-party web application uses secure Sanctum cookie sessions; future native and partner clients use scoped tokens/OAuth.
5. Exact property location may be private. Public projections can use an approximate point while the authoritative point remains restricted.
6. The production provider choices are RESO Web API/OData, OpenAI Responses API, and Stripe Billing/Checkout. Provider-neutral ports and deterministic test adapters remain authoritative architecture boundaries; live modules stay feature-gated until licensed/approved credentials and staging evidence exist.
7. Casaura is a working brand for this implementation and should receive legal/trademark review before public launch.
8. The API and web app deploy independently. The first implementation stays in one repository to keep contracts, tests, and local development coordinated.

## Proposed repository structure

```text
apps/
  api/
    app/Domain/             Domain services and ports
    app/Http/Controllers/   Versioned JSON transport layer
    app/Models/             Persistence models
    app/Policies/           Object-level authorization
    database/              Migrations, factories, seeders
    tests/                 Unit, feature, tenant and security tests
  web/
    src/app/               Next.js routes and server-rendered layouts
    src/components/        Brand, UI primitives, and feature components
    src/lib/               API clients, contracts, and utilities
    public/                Optimized project-owned assets
docs/
  architecture/            Decisions, diagrams, data model, delivery plan
  design/                  Accepted concepts and extracted tokens
infra/docker/              Production images and local service configuration
packages/contracts/        OpenAPI contract and future generated cross-app types
```

## System context

```mermaid
flowchart LR
  Consumer[Consumer] --> Web[Next.js web / PWA]
  Agency[Agency team] --> Web
  Admin[Platform staff] --> Web
  Native[Future native apps] --> API[Laravel /api/v1]
  Web --> API
  API --> PG[(PostgreSQL + PostGIS)]
  API --> Redis[(Redis)]
  API --> Search[(OpenSearch)]
  API --> Storage[Storage abstraction]
  Storage --> S3[S3 / MinIO]
  API --> Providers[RESO Web API / OData]
  API --> Notify[Email / push / SMS adapters]
  API --> AI[OpenAI Responses / deterministic adapter]
  API --> Billing[Stripe Checkout / Billing / webhooks]
```

## Deployment topology

- `web`: stateless Next.js nodes behind a CDN/edge cache.
- `api`: stateless Laravel HTTP nodes with separate queue workers and singleton scheduler. Collaboration currently uses bounded polling; no websocket gateway is claimed.
- `postgres`: managed HA PostgreSQL with PostGIS and point-in-time recovery.
- `redis`: cache, rate limits, locks, queues, session support, and realtime fan-out.
- `opensearch`: optional derived search cluster; PostgreSQL/PostGIS keyset search is the production-capable default and every optional index is rebuildable.
- `object storage`: private-by-default originals plus policy-controlled derivatives behind signed or proxied delivery.
- observability: structured JSON logs, request/release correlation, liveness/readiness, component health, heartbeats, and externally collected metrics/alerts. Distributed tracing is a hosting integration, not a repository claim.

## Domain boundaries

| Boundary | Owns | Does not own |
| --- | --- | --- |
| Identity & access | users, sessions, MFA, roles, permissions | agency billing policy |
| Tenancy & agencies | agencies, branches, membership, verification, opening hours | physical properties |
| Catalogue | properties, listings, taxonomy, features, status/price history | full-text ranking engine |
| Search & discovery | query grammar, index projections, ranking, maps, alerts | authoritative listing state |
| Media | uploads, validation, derivatives, storage providers, delivery | listing publication rules |
| Engagement | reactions, favorites, collections, comments, ratings | CRM lead stages |
| Leads & viewings | inquiries, routing, pipeline, schedules, outcomes | messaging transport internals |
| Messaging & notifications | conversations, messages, preferences, adapters | lead ownership |
| Agency growth | storefront, newsletter, analytics, demand intelligence | global moderation |
| Integrations & provenance | providers, mappings, sync, raw records, deduplication | consumer-facing editorial copy |
| Grounded AI | sessions, constrained generations, citations, redaction, safety evidence | authoritative listing facts or autonomous publication |
| Trust & moderation | verification, reports, cases, sanctions, audit | billing settlement |
| Entitlements & billing | plans, subscriptions, quotas, promotion policy, invoices | feature implementation |
| Platform configuration | flags, settings, CMS, SEO configuration | infrastructure secrets in source control |

Boundaries communicate through application services and domain events. Controllers never query another tenant by an unscoped identifier. Cross-boundary projections are replaceable and never become the source of truth.

## Critical flows

### Request authorization

```mermaid
sequenceDiagram
  participant C as Client
  participant A as API middleware
  participant T as Tenant context
  participant P as Policy
  participant DB as PostgreSQL
  C->>A: Authenticated request + Agency-ID
  A->>DB: Load active membership
  DB-->>A: membership + roles
  A->>T: Bind immutable agency context
  A->>P: Check permission + object tenant
  P-->>A: allow / deny
  A->>DB: Execute tenant-scoped query
  DB-->>C: JSON response
```

### Search indexing

`listing transaction → outbox event → projection builder → PostgreSQL search document`, with an optional OpenSearch adapter and versioned index reset restricted to an explicit console-only maintenance mode. Search results return only public-safe projection fields.

### Media upload

`authenticated upload → tenant quota → size check → ClamAV scan → detected MIME/decode/dimension limit → metadata-stripping WebP derivatives → private StorageProviderInterface → media record → publishable state`. Delete moves objects to quarantine; scheduled reconciliation and purge provide recovery and lifecycle enforcement.

### Data ingestion

`provider adapter → raw immutable envelope → mapping version → canonical validation → provenance records → identity candidates → human review when uncertain → listing/property transaction → indexing event`.

The production RESO adapter discovers bounded OData metadata, uses OAuth client
credentials from a named mounted secret, follows bounded pagination, commits cursors
only after successful processing, and preserves exact-replay source evidence.

### Grounded AI

`redact request → derive bounded schema input → OpenAI Responses or deterministic adapter → validate strict JSON → bind public listing citations → persist expiry-bound evidence → require user confirmation/human apply`. Provider failure or malformed output retries once. Search/comparison then falls back deterministically; listing copy fails safely rather than applying speculative content.

### Billing and promotion

`tenant checkout request → Stripe-hosted Checkout with automatic tax → signed idempotent webhook → monotonic subscription/invoice projection → entitlement resolver`. Promotion policies are immutable versions; sponsored inventory is labeled, capped under lock, privacy-deduplicated, auto-paused when unavailable, and queried separately from organic ranking.

## Key architectural decisions

- Modular monolith first; asynchronous boundaries and stable ports make later extraction possible without distributed transactions on day one.
- UUIDv7-compatible IDs at public/domain boundaries; opaque IDs reduce enumeration and support distributed ingestion.
- Server-side tenant context plus policies and tenant-scoped relationships; frontend filtering is never an authorization control.
- PostgreSQL row-level security is a defense-in-depth deployment option after connection-pooling strategy is fixed, not the only tenant boundary.
- Transactional outbox for search, analytics, and notifications in Phase 2 to prevent dual-write loss.
- Feature availability resolves in this order: emergency environment override → agency override → plan entitlement → global default. Every change is audited.
