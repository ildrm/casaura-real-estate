# Spec: Phase 6 Administration

**Author:** Casaura Engineering  
**Date:** 2026-08-19  
**Status:** Approved  
**Reviewers:** Product owner, via the directive to complete roadmap phases 4–6  
**Related specs:** [Phase 5 agency product](phase-5-agency-product.md), [product map](../architecture/product-map.md), [API conventions](../architecture/api.md)

## Context

Casaura already persists feature flags, settings, roles, permissions, audit logs, and listing review evidence, but none has a safe platform operations boundary. Agency roles currently travel through tenant membership; platform operations require explicit role detection that does not trust an arbitrary `Agency-ID` and does not grant agency owners global powers.

Phase 6 supplies a narrowly allowlisted administration API and responsive operations console for moderation cases, non-secret settings, role/permission editing, feature flags, health, and audit review. Infrastructure secret management, provider dashboards, billing, CMS, and support impersonation remain separate specifications.

## Functional Requirements

- FR-1: Administrator routes MUST require authentication plus an active membership carrying the named permission through an approved platform role.
- FR-2: Agency owner/manager roles MUST NOT inherit `platform.settings` or platform-wide moderation authority.
- FR-3: Authenticated users MUST be able to submit an abuse report against a published listing with category, reason, and optional details.
- FR-4: Members with `comment.moderate` MUST be able to list, filter, inspect, assign, and transition moderation cases through `open`, `reviewing`, `resolved`, and `dismissed`.
- FR-5: Moderation transitions MUST preserve the report, actor, outcome/note, timestamps, and audit evidence; reports and case evidence MUST be immutable.
- FR-6: Members with `platform.settings` MUST be able to list and update allowlisted non-secret settings with optimistic version checks.
- FR-7: Secret settings MUST never return values and MUST NOT be writable through the general settings endpoint.
- FR-8: Members with `platform.settings` MUST be able to list flags and create/update global or agency overrides with validity windows and audit evidence.
- FR-9: Members with `platform.settings` MUST be able to list permissions and create/update/delete only non-system roles; system roles MUST remain immutable.
- FR-10: Role permission assignment MUST reject platform permissions on agency-scoped roles and unknown permission names.
- FR-11: Members with `audit.view` MUST be able to filter and cursor-paginate redacted audit records without mutating or deleting them.
- FR-12: Authorized operators MUST receive a health projection for API, database, queue persistence, projection backlog, failed jobs, and application version without secrets.
- FR-13: The web app MUST provide a responsive administration console for moderation, settings, roles, flags, health, and audit operations with explicit denied/loading/error/empty states.

## Non-Functional Requirements

- NFR-P1: Admin list endpoints MUST cap pages at 100 and use stable cursor pagination except small permission/setting reference lists.
- NFR-S1: Admin authorization MUST be server-side and MUST NOT rely on hidden navigation or client role names alone.
- NFR-S2: Admin serializers MUST allowlist fields and redact passwords, tokens, secret setting values, raw message/contact bodies, private coordinates, and stack traces.
- NFR-S3: Abuse-report submission MUST use a dedicated limiter and MUST reject unpublished targets without revealing state.
- NFR-R1: Settings, flag, role, and moderation changes MUST commit mutation and audit evidence atomically.
- NFR-R2: Settings and mutable admin resources MUST reject stale versions with 409.
- NFR-A1: Console navigation, tables, dialogs/forms, and status actions MUST be keyboard operable with labels and visible focus.
- NFR-A2: Desktop and 390-pixel layouts MUST have no page-level horizontal overflow; wide tables MUST use labelled scroll regions.
- NFR-O1: Health and error responses MUST carry request IDs and stable status codes while remaining safe for an authorized operator.

## Acceptance Criteria

### AC-1: Enforce the platform boundary (FR-1, FR-2, NFR-S1)
Given an agency owner and a platform administrator
When each requests `/api/v1/admin/settings`
Then the owner receives 403 and the platform administrator receives the allowlisted settings projection without requiring a selected tenant.

### AC-2: Create one moderation case per report replay (FR-3, NFR-S3)
Given a published listing and authenticated reporter
When the same report is submitted twice with one idempotency key
Then one immutable abuse report and one open case exist and the unavailable target path remains 404.

### AC-3: Operate a moderation case (FR-4, FR-5, NFR-R1)
Given an open case
When an authorized moderator assigns it, reviews it, and resolves it with an outcome
Then only valid transitions succeed and history plus audit evidence identify every change.

### AC-4: Protect settings and secrets (FR-6, FR-7, NFR-S2, NFR-R2)
Given public and secret settings
When an administrator lists or updates them
Then secret values are redacted, the general endpoint rejects secret writes, and a stale version changes nothing.

### AC-5: Manage feature flags safely (FR-8, NFR-R1)
Given a known flag and agency
When an administrator creates a bounded agency override
Then effective resolution reflects the override during its window and an audit record contains no secret data.

### AC-6: Edit only custom roles (FR-9, FR-10)
Given system and custom roles
When an administrator edits both
Then the system role is rejected as immutable while valid custom permissions synchronize and are audited.

### AC-7: Reject unsafe role permissions (FR-10)
Given an agency-scoped custom role
When `platform.settings` or an unknown permission is assigned
Then the API returns 422 and preserves the previous role permissions.

### AC-8: Read immutable, redacted audits (FR-11, NFR-S2)
Given audit events across agencies and actions
When an authorized operator filters and paginates them
Then matching redacted events and a stable next cursor are returned and update/delete routes do not exist.

### AC-9: Inspect operational health (FR-12, NFR-O1)
Given the database is ready and queued/outbox work exists
When an authorized operator requests health
Then component states, backlog counts, version, timestamp, and request ID are returned without DSNs, credentials, payloads, or stack traces.

### AC-10: Use the administration console (FR-13, NFR-A1, NFR-A2)
Given an authorized or denied user on desktop or 390-pixel mobile
When they open and operate `/admin`
Then authorized sections reflect API state, denied users see a clear access state, keyboard actions work, and the body has no horizontal overflow.

## Edge Cases

- EC-1: Suspended/inactive memberships and agency-only roles never authorize admin routes.
- EC-2: Unknown, draft, withdrawn, or deleted report targets return 404.
- EC-3: Duplicate report idempotency keys with a different payload return 409.
- EC-4: Invalid moderation transition returns 409 `MODERATION_TRANSITION_INVALID`.
- EC-5: Secret setting update returns 422 `SECRET_SETTING_MANAGED_EXTERNALLY`.
- EC-6: Invalid flag scope/window returns 422 and no override.
- EC-7: Deleting a system role returns 409 `SYSTEM_ROLE_IMMUTABLE`.
- EC-8: Removing a permission from the acting administrator's system role is impossible because system roles are immutable.
- EC-9: A failing component marks health `degraded` without exposing its exception.

## API Contracts

The operations entry point is `GET /api/v1/admin/health`; moderation and configuration endpoints follow the table below.

```ts
type ModerationStatus = "open" | "reviewing" | "resolved" | "dismissed";
interface ModerationCase {
  id: string; status: ModerationStatus; category: string; target_type: "listing"; target_id: string;
  assigned_user_id: string | null; outcome: string | null; version: number;
  report: { details: string | null; created_at: string };
}
interface AdminSetting { namespace: string; key: string; value: unknown | null; secret: boolean; version: number; }
interface AdminHealth { status: "ok" | "degraded"; version: string; checked_at: string; components: Record<string, { status: string; backlog?: number }>; request_id: string; }
```

| Method | Path | Permission | Result |
| --- | --- | --- | --- |
| POST | `/api/v1/public/listings/{listing}/reports` | user, limited | 201/200 report receipt |
| GET/PATCH | `/api/v1/admin/moderation-cases[/{case}]` | `comment.moderate` | case list/projection |
| GET/PATCH | `/api/v1/admin/settings[/{namespace}/{key}]` | `platform.settings` | allowlisted settings |
| GET/PUT/DELETE | `/api/v1/admin/feature-flags[/{flag}/overrides/{override}]` | `platform.settings` | flag/override projection |
| GET/POST/PATCH/DELETE | `/api/v1/admin/roles[/{role}]` | `platform.settings` | permission catalogue / custom roles |
| GET | `/api/v1/admin/audit-logs` | `audit.view` | redacted cursor page |
| GET | `/api/v1/admin/health` | platform operator | component health |

## Data Models

| Entity | Key fields and constraints |
| --- | --- |
| `abuse_reports` | reporter, listing, idempotency key/hash, category, details, created timestamp; immutable unique reporter/key |
| `moderation_cases` | report, category, target type/ID, status, assignee, outcome/note, version, timestamps |
| `moderation_case_history` | case, from/to status, actor, assignee, note/outcome, timestamp; append-only |
| `settings` extension | integer version for optimistic admin updates; secret flag remains authoritative |
| Existing flags/overrides | validity-windowed global/agency configuration with audit evidence |
| Existing roles/permissions | system immutability plus validated custom role scope and assignments |

## Out of Scope

- OS-1: Secret-manager writes, infrastructure deployment, job retry controls, and support impersonation.
- OS-2: CMS/taxonomy/location editors, provider dashboards, billing, invoices, and payment operations.
- OS-3: Comments/ratings product UI; moderation cases support their future reports but do not invent those features.
- OS-4: Automated sanctions, content classifiers, AI moderation, and legal appeals workflows.
