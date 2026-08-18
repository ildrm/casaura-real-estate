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
- Traceability: accept/return `Request-ID`; generate one if absent.
- Errors: stable machine code plus message, field errors, and request ID. Never expose stack traces or secret-bearing upstream responses.
- Pagination: cursor links and metadata; offset pagination only for small admin reference lists.
- Concurrency: mutable resources expose an ETag/version and reject stale writes with `409` or `412`.

## Phase 1 endpoint map

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/health` | public | API/database readiness summary |
| POST | `/auth/register` | public/rate-limited | Register consumer account |
| POST | `/auth/register-agency` | public/flagged/rate-limited | Atomically create owner, agency, membership, role, and subscription |
| POST | `/auth/login` | public/rate-limited | Rotate session and return principal |
| POST | `/auth/logout` | user | Invalidate session/token |
| GET | `/me` | user | Principal, memberships, permissions, active entitlements |
| GET | `/agencies/{agency}` | public | Public safe agency projection |
| GET | `/agency` | user + tenant | Current agency workspace projection |
| PATCH | `/agency` | `agency.manage_profile` | Update current agency profile |
| GET | `/agency/members` | `agency.manage_members` | Tenant-scoped membership list |
| POST | `/agency/members` | `agency.manage_members` | Invite/create membership |
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
| GET | `/public/listings/{listing}` | public | Published listing detail, safe agency projection, public media links, history, similar inventory, and caller-specific engagement state |
| GET | `/public/media/{media}/{kind}` | public | Stream an active published listing's `thumbnail` or `display` WebP derivative without revealing storage keys |
| GET | `/account/engagements` | user | Current account's favorite, liked, and disliked published listing cards |
| PUT | `/account/favorites/{listing}` | user | Idempotently favorite a published listing |
| DELETE | `/account/favorites/{listing}` | user | Idempotently remove a favorite |
| PUT | `/account/reactions/{listing}` | user | Set or replace the private `like`/`dislike` state |
| DELETE | `/account/reactions/{listing}` | user | Remove the private reaction |

Search spatial syntax is `bounds=min_longitude,min_latitude,max_longitude,max_latitude` or `radius=latitude,longitude,kilometres`. Approximate public coordinates, never tenant-private coordinates, drive public spatial search and map rendering.

Future endpoint families follow resource boundaries: `/collections`, `/viewings`, `/leads`, `/conversations`, `/integrations`, `/admin`. Administrator APIs use explicit `/admin` routes and never share broad internal serializers with public APIs.

## Example error

```json
{
  "error": {
    "code": "TENANT_ACCESS_DENIED",
    "message": "You do not have access to this agency.",
    "fields": {},
    "request_id": "01J..."
  }
}
```

## Security behavior

- Login, registration, password reset, uploads, comments, messages, search, AI, and public API each use separate named limiters.
- State-changing cookie-authenticated requests require CSRF protection; same-site cookies are secure in production.
- Public serializers never expose legal documents, private coordinates, member emails, billing state, credentials, or moderation notes.
- Policies run on every object action; route-model binding alone is not authorization.
- Support impersonation requires an audited, time-limited grant and a visible UI banner.

The executable contract is [OpenAPI](../../packages/contracts/openapi.yaml).
