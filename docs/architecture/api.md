# API design

## Conventions

- Base path: `/api/v1`.
- JSON keys: `snake_case` to match Laravel and OpenAPI contracts.
- IDs: opaque UUID strings.
- Dates: RFC 3339 UTC; timezone is explicit on schedules.
- Money: `{ amount_minor, currency }`.
- Authentication: Sanctum secure cookie for the first-party web app; scoped bearer tokens for future native/partner clients.
- Tenant selection: `Agency-ID` request header, verified against active membership on every private agency route.
- Idempotency: `Idempotency-Key` required for imports, uploads, billing, lead creation, and other replay-sensitive writes.
- Traceability: accept/return a UUID `Request-ID`, generate one if absent, and return the immutable `Release-ID`.
- Errors: stable machine code plus message, field errors, and request ID. Never expose stack traces or secret-bearing upstream responses.
- Pagination: cursor links and metadata; offset pagination only for small admin reference lists.
- Concurrency: mutable resources expose an ETag/version and reject stale writes with `409` or `412`.

## Phase 1 endpoint map

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/health/live`, `/health/ready` | public | Process liveness and dependency/heartbeat readiness without internal detail |
| POST | `/auth/register` | public/flagged/rate-limited | Register a consented consumer account; disabled in the GA launch profile |
| POST | `/auth/register-agency` | public/flagged/rate-limited | Atomically create a consented owner, agency, membership, role, and subscription |
| POST | `/auth/login` | public/rate-limited | Rotate session, enforce verification/MFA policy, and return principal |
| POST | `/auth/forgot-password`, `/auth/reset-password` | public/rate-limited | Non-enumerating recovery and credential/session rotation |
| GET/POST | `/auth/email/...` | user/signed/rate-limited | Deliver and consume signed verification links |
| POST/DELETE | `/auth/mfa/...` | verified user | TOTP setup/challenge/disable and one-time recovery codes |
| POST | `/auth/invitations/{token}/accept` | public/rate-limited | Accept a single-use, expiring agency invitation |
| POST | `/auth/logout` | user | Invalidate session/token |
| GET | `/me` | user | Principal, memberships, permissions, active entitlements |
| GET | `/agencies/{agency}` | public | Public safe agency projection |
| GET | `/agency` | user + tenant | Current agency workspace projection |
| PATCH | `/agency` | `agency.manage_profile` | Update current agency profile |
| GET | `/agency/members` | `agency.manage_members` | Tenant-scoped membership list |
| GET | `/agency/feature-flags` | member | Effective flags/entitlements, without internal rules |

## Phase 2 endpoint map

All endpoints below require an authenticated session and active `Agency-ID` membership. Object reads resolve inside that tenant before authorization-sensitive behavior is exposed.

| Method | Path | Permission | Purpose |
| --- | --- | --- | --- |
| GET | `/property-catalog` | active member | Stable property types, amenities, and typed feature definitions |
| GET | `/listings` | `listing.view` | Filtered, cursor-paginated tenant inventory |
| POST | `/listings` | `listing.create` | Atomically create a property/listing draft and initial history |
| GET | `/listings/{listing}` | `listing.view` | Current listing, property, quality, and primary-media projection |
| PATCH | `/listings/{listing}` | `listing.update` | Optimistic autosave with required current `version` |
| DELETE | `/listings/{listing}` | `listing.delete` | Soft-delete an eligible draft and its private property |
| POST | `/listings/{listing}/submit` | `listing.update` | Submit a complete draft for review |
| POST | `/listings/{listing}/publish` | `listing.publish` | Publish an in-review listing |
| POST | `/listings/{listing}/request-changes` | `listing.publish` | Return an in-review listing with a required reviewer note |
| POST | `/listings/{listing}/withdraw` | `listing.publish` | Withdraw a published listing |
| GET | `/listings/{listing}/media` | `listing.view` | Safe private-media metadata without object keys |
| POST | `/listings/{listing}/media` | `media.manage` | Idempotent private image upload and WebP derivative generation |
| PATCH | `/listings/{listing}/media/order` | `media.manage` | Persist a complete owned-media order |
| DELETE | `/listings/{listing}/media/{media}` | `media.manage` | Quarantine objects and soft-delete owned media |

## Phase 3 endpoint map

Public routes use a dedicated search limiter and return only the allowlisted search projection. Account routes require an authenticated consumer and use a separate engagement limiter.

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/public/search` | public | Full-text, hard-filter, sort, bounds, and radius search with cursor pagination |
| GET | `/public/discovery` | public | Stable published listing/storefront identifiers for sitemap generation |
| GET | `/public/listings/{listing}` | public | Published listing detail, safe agency projection, public media links, history, similar inventory, and caller-specific engagement state |
| GET | `/public/media/{media}/{kind}` | public | Stream an active published listing's `thumbnail` or `display` WebP derivative without revealing storage keys |
| GET | `/account/engagements` | user | Current account's favorite, liked, and disliked published listing cards |
| GET/POST | `/account/privacy/requests` | user | List or request encrypted exports and reviewed deletion |
| GET | `/account/privacy/requests/{request}/download` | subject user | Checksum-verified, expiring JSON export |
| PUT | `/account/favorites/{listing}` | user | Idempotently favorite a published listing |
| DELETE | `/account/favorites/{listing}` | user | Idempotently remove a favorite |
| PUT | `/account/reactions/{listing}` | user | Set or replace the private `like`/`dislike` state |
| DELETE | `/account/reactions/{listing}` | user | Remove the private reaction |

Search spatial syntax is `bounds=min_longitude,min_latitude,max_longitude,max_latitude` or `radius=latitude,longitude,kilometres`. Approximate public coordinates, never tenant-private coordinates, drive public spatial search and map rendering.

Future endpoint families follow resource boundaries: `/collections`, `/viewings`, `/leads`, `/conversations`, `/integrations`, `/admin`. Administrator APIs use explicit `/admin` routes and never share broad internal serializers with public APIs.

## Phase 4 endpoint map

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| POST | `/public/listings/{listing}/leads` | public, lead limiter, idempotency | Create the lead, initial conversation/message, notification, history, and audit receipt |
| GET/PATCH | `/leads[/{lead}]` | tenant + `lead.manage` | Filter and operate the versioned tenant CRM pipeline |
| GET/POST | `/conversations/{conversation}/messages` | participant or permitted agency member | Poll and append participant-scoped plain-text messages |
| GET/POST/PATCH | `/viewings[/{viewing}]` | tenant + `lead.manage` | Schedule and transition timezone-aware viewings |
| GET | `/viewings/{viewing}/calendar` | viewing participant | Export a confirmed viewing through the calendar port |
| GET/POST/PATCH | `/reminders[/{reminder}]` | tenant + `lead.manage` | Manage assigned lead/viewing reminders |
| GET/PATCH | `/notifications[/{notification}]` | user | List and read only the caller's in-app notifications |
| GET | `/account/collaboration` | user | Consumer conversation and viewing projection |
| GET | `/agency/analytics/collaboration` | tenant + `analytics.view` | Canonical first-response metrics |

## Phase 5 endpoint map

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| GET | `/public/agencies/{slug}` | public | Feature-gated safe storefront with team, hours, and published inventory |
| GET/PUT | `/agency/opening-hours` | member / `agency.manage_profile` | Read or atomically replace weekly hours and closures |
| GET/POST/PATCH | `/agency/team[/{member}]` | `agency.manage_members` | Quota-bound invite, status, public visibility, title, and safe agency-role assignment |
| POST/DELETE | `/agency/team/{member}/invitation` | `agency.manage_members` | Rotate/resend or cancel a pending invitation |
| POST | `/public/agencies/{agency}/newsletter/subscriptions` | public, newsletter limiter | Idempotent consent capture with opaque unsubscribe token |
| DELETE | `/public/newsletter/subscriptions/{token}` | public, newsletter limiter | Idempotent token-scoped unsubscribe |
| GET/POST/PATCH | `/agency/newsletter/campaigns[/{campaign}]` | `agency.manage_profile` | Feature-gated draft campaign operations |
| POST | `/agency/newsletter/campaigns/{campaign}/send` | `agency.manage_profile` | Deliver once through the newsletter adapter and record outcomes |
| GET | `/agency/analytics` | `analytics.view` | Date-bounded storefront/listing/engagement/CRM/newsletter aggregates |

## Phase 6 endpoint map

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| POST | `/public/listings/{listing}/reports` | user, report limiter, idempotency | Create immutable abuse evidence and one moderation case |
| GET/PATCH | `/admin/moderation-cases[/{case}]` | platform `comment.moderate` | Filter and operate the versioned moderation queue with report evidence and validated moderator assignment |
| GET/PATCH | `/admin/settings[/{namespace}/{key}]` | platform `platform.settings` | Read redacted settings and update only non-secret values |
| GET/PUT/DELETE | `/admin/feature-flags[...]` | platform `platform.settings` | Cursor-paginate flags and audit validity-windowed global/agency overrides |
| GET/POST/PATCH/DELETE | `/admin/roles[/{role}]` | platform `platform.settings` | Inspect permissions and edit only safe non-system roles |
| GET | `/admin/audit-logs` | platform `audit.view` | Cursor-paginate redacted immutable audit metadata |
| GET | `/admin/health` | platform `audit.view` | Safe database, queue, failed-job, and search-backlog projection |

## Phase 7 endpoint map

Every integration route is tenant-scoped, requires `integration.configure`, and uses
the integrations limiter. Secret values are supplied only by named secret reference;
the API never returns secret content.

| Method | Path | Purpose |
| --- | --- | --- |
| GET/POST | `/integrations/connections` | List or create a tenant RESO connection with rights/resource configuration |
| GET/PATCH/DELETE | `/integrations/connections/{connection}` | Read, version-update, disable, or re-enable an owned connection |
| GET | `/integrations/connections/{connection}/metadata` | Discover bounded live RESO resources and fields before mapping |
| GET/POST | `/integrations/connections/{connection}/mappings` | List or activate a versioned declarative field mapping |
| GET/POST | `/integrations/connections/{connection}/syncs` | List jobs or enqueue an idempotent full/incremental sync |
| GET | `/integrations/syncs/{sync}` | Poll a tenant-owned asynchronous sync projection |
| GET | `/integrations/import-errors` | Review redacted validation/quarantine outcomes |
| GET/PATCH | `/integrations/duplicate-candidates[/{candidate}]` | Review, link/merge, reject, or reverse uncertain source matches |

## Phase 8 endpoint map

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| GET/POST | `/account/collections` | verified user | List or create private collections |
| GET/PATCH/DELETE | `/account/collections/{collection}` | owner/member policy | Read, rename, or delete an authorized collection |
| PUT/PATCH/DELETE | `/account/collections/{collection}/items` | owner/editor policy | Idempotently add, atomically reorder, or remove published listings |
| POST/DELETE | `/account/collections/{collection}/members[...]` | owner policy | Create expiring invitations or revoke a collaborator |
| POST | `/account/collection-invitations/{token}/accept` | matching verified user | Atomically accept a single-use invitation |
| GET | `/public/compare` | public | Compare 2–5 published, public-safe listing projections |
| GET/POST/DELETE | `/account/comparisons[...]` | verified user | List, idempotently save, or delete private comparison history |
| GET | `/public/listings/{listing}/recommendations` | public | Deterministic organic recommendations; sponsored records are excluded |
| GET | `/public/map-layers` | public | Privacy-thresholded clusters/price bands from public coordinates |
| GET | `/public/market-analytics` | public | Privacy-thresholded single-currency market aggregates |

## Phase 9 endpoint map

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| POST | `/ai/search` | public, AI limiter/flag | Return schema-validated proposed filters, public results, and grounded citations |
| POST | `/ai/comparisons` | public, AI limiter/flag | Explain only the supplied public comparison facts with citations |
| GET/DELETE | `/account/ai-sessions[/{session}]` | subject user | List or delete the caller's retained AI sessions |
| POST | `/account/ai-generations/{generation}/feedback` | subject user | Record bounded useful/not-useful feedback |
| POST | `/listings/{listing}/ai-suggestions` | tenant + `listing.update` | Generate a version-bound listing-copy suggestion without mutating the listing |
| POST | `/listings/{listing}/ai-suggestions/{suggestion}/apply` | tenant + `listing.update` | Human-select fields and apply only against the unchanged source version |
| GET | `/admin/ai-safety-events` | platform `audit.view` | Inspect redacted refusal/safety metadata without prompt contents |

## Phase 10 endpoint map

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| GET | `/billing` | tenant + `billing.manage` | Project subscription, available plans, entitlements, invoices, and active policies |
| POST | `/billing/checkout-sessions` | tenant + `billing.manage`, idempotency | Create Stripe-hosted subscription Checkout with automatic tax |
| POST | `/billing/portal-sessions` | tenant + `billing.manage`, idempotency | Create a Stripe-hosted customer portal session |
| POST | `/webhooks/stripe` | public signed webhook limiter | Verify, deduplicate, order, and project allowlisted billing events |
| GET/POST/PATCH | `/billing/promotion-campaigns[/{campaign}]` | tenant + `billing.manage` | Operate eligible, scheduled, capped tenant campaigns |
| GET | `/public/sponsored-listings` | public | Return labeled paid inventory separately from organic search/recommendations |
| GET/POST/PATCH | `/admin/promotion-policies[/{policy}]` | platform `platform.settings` | List and version immutable promotion policy families |

## Example error

```json
{
  "error": {
    "code": "TENANT_ACCESS_DENIED",
    "message": "You do not have access to this agency.",
    "fields": {},
    "request_id": "d829dee2-b77d-4ef7-bcbb-a738dc1e6f7b"
  }
}
```

## Security behavior

- Login, registration, password reset, uploads, messages, search, leads, newsletters, reports, and engagement use scoped named limiters.
- State-changing cookie-authenticated requests require CSRF protection; same-site cookies are secure in production.
- Public serializers never expose legal documents, private coordinates, member emails, billing state, credentials, or moderation notes.
- Policies run on every object action; route-model binding alone is not authorization.
- Support impersonation is not implemented and no production role is granted equivalent bypass authority.

## Production behavior

All authenticated operational routes require an active principal and verified identity;
privileged memberships/platform roles additionally require an MFA-upgraded session.
Tenant routes resolve active membership before object lookup and then enforce the named
permission and centralized entitlement. Public inquiry writes persist versioned consent
evidence and use a uniqueness constraint plus payload hash to make concurrent replays safe.

Production boot fails on insecure origins, cookies, CORS, secrets, database/cache/queue,
object storage, malware scanning, mail, logging, or incomplete live RESO/OpenAI/Stripe
configuration. Local newsletter delivery, deterministic provider adapters, and destructive
OpenSearch reset are unavailable in production. Stripe webhook traffic uses a dedicated
limiter and stores only an event hash plus an allowlisted summary. The exact Laravel route
and OpenAPI method sets are compared by the automated test suite.

The executable contract is [OpenAPI](../../packages/contracts/openapi.yaml).
