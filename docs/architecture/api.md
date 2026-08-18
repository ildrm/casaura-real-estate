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

Future endpoint families follow resource boundaries: `/properties`, `/listings`, `/search`, `/collections`, `/viewings`, `/leads`, `/conversations`, `/integrations`, `/admin`. Administrator APIs use explicit `/admin` routes and never share broad internal serializers with public APIs.

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
