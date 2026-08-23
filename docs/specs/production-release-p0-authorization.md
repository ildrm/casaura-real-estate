# Spec: P0 authorization and principal-status containment

**Author:** Codex  
**Date:** 2026-08-22  
**Status:** Approved  
**Reviewer:** User, via approval to execute the production release plan  
**Related plan:** `PRODUCTION_RELEASE_PLAN.md`, P0.1 and P0.2

## Context

Casaura currently grants platform access when an active agency membership has a role whose slug matches a privileged platform slug and whose permissions contain the requested permission. `PlatformAuthorization` does not require the role to be an immutable system role. Because custom agency roles are mutable and role slugs are unique only within their scope, a custom agency role can reuse a privileged platform slug and be interpreted as platform authority.

Authenticated sessions also recheck user status only during login. Tenant routes validate active membership and agency state, but authenticated account routes do not consistently reject a user suspended after session creation. Platform authorization validates membership status but not the associated agency status. This containment slice closes those trust-boundary gaps without changing successful response shapes or introducing a migration.

## Functional Requirements

- FR-1: Platform authorization MUST grant a platform permission only through an active membership whose associated agency is active and whose role is an immutable system role with `scope = platform`, a recognized privileged platform slug, and the requested permission.
- FR-2: Platform authorization MUST NOT grant authority through a custom role, including a custom agency-scoped role whose slug matches a privileged platform role and whose permissions include the requested permission.
- FR-3: Platform authorization MUST NOT grant authority through an inactive membership or a membership belonging to an inactive agency.
- FR-4: Every route inside the authenticated `/api/v1` route group MUST reject a principal whose current user status is not `active`, even when the existing session or token remains otherwise valid.
- FR-5: Principal-status rejection MUST return HTTP 403 with the stable error code `ACCOUNT_ACCESS_DENIED`, an empty `fields` object, and the current request ID.
- FR-6: Existing unauthenticated behavior MUST remain controlled by `auth:sanctum`; this slice MUST NOT replace a 401 authentication failure with a principal-status response.
- FR-7: Existing active users, active tenants, trusted platform system roles, and tenant permissions MUST retain their current successful behavior.
- FR-8: The implementation MUST add regression tests covering every recognized privileged platform slug, malicious custom-role collisions, inactive membership, inactive agency, suspended user with an existing authenticated context, and active-user compatibility.

## Non-Functional Requirements

- NFR-S1: Authorization MUST default to deny when role trust, permission, membership state, agency state, or principal state cannot be proven.
- NFR-S2: Authorization denials MUST NOT reveal role membership, permission details, agency state, or other private account information.
- NFR-S3: The change MUST NOT add plaintext credentials, tokens, secrets, or PII to logs or API responses.
- NFR-R1: Principal and authorization state MUST be evaluated from current persisted state on each authenticated request; stale relationship state MUST NOT survive across requests.
- NFR-P1: The new checks MUST use indexed relationship predicates and MUST NOT load all memberships or roles into application memory.
- NFR-C1: Successful API response schemas and existing route paths/methods MUST remain backward compatible.

## Acceptance Criteria

### AC-1: Trusted platform administrator is authorized (FR-1, FR-7)
Given an active user with an active membership in an active agency and the immutable system `platform_administrator` role containing `platform.settings`
When the user requests `GET /api/v1/admin/settings`
Then the response status is 200
And the response contains the existing settings projection

### AC-2: Custom agency role collision is denied (FR-2, NFR-S1)
Given an active user with an active membership in an active agency and a non-system agency role whose slug is `platform_administrator` and whose permissions contain `platform.settings`
When the user requests `GET /api/v1/admin/settings`
Then the response status is 403
And the response error code is `PLATFORM_PERMISSION_DENIED`
And no settings data is returned

### AC-3: Every privileged slug requires trusted system identity (FR-1, FR-2, FR-8)
Given a non-system agency role for each recognized privileged slug with the permission required by a corresponding admin route
When the assigned user requests that admin route
Then every request returns status 403
And each response error code is `PLATFORM_PERMISSION_DENIED`

### AC-4: Inactive membership is denied (FR-3)
Given an active user whose membership has a trusted platform system role but membership status is not `active`
When the user requests an admin route protected by that role permission
Then the response status is 403
And the response error code is `PLATFORM_PERMISSION_DENIED`

### AC-5: Inactive platform agency is denied (FR-3)
Given an active user with an active membership and trusted platform system role whose associated agency status is not `active`
When the user requests an admin route protected by that role permission
Then the response status is 403
And the response error code is `PLATFORM_PERMISSION_DENIED`

### AC-6: Suspended authenticated user is denied on account route (FR-4, FR-5, NFR-R1)
Given a user authenticated through an existing session or test authentication context whose persisted status changes from `active` to `suspended`
When the user requests `GET /api/v1/me`
Then the response status is 403
And the response error code is `ACCOUNT_ACCESS_DENIED`
And the response contains the current request ID

### AC-7: Suspended authenticated user is denied on tenant and admin routes (FR-4, FR-5)
Given a suspended authenticated user who otherwise has valid tenant membership and platform-role relationships
When the user requests one tenant route and one admin route
Then both responses have status 403
And both responses have error code `ACCOUNT_ACCESS_DENIED`

### AC-8: Unauthenticated request remains an authentication failure (FR-6, NFR-C1)
Given no authenticated principal
When the caller requests `GET /api/v1/me`
Then the response status is 401
And the response is not `ACCOUNT_ACCESS_DENIED`

### AC-9: Active tenant user remains compatible (FR-7, NFR-C1)
Given an active user with an active membership in an active agency and the existing tenant permission
When the user requests the corresponding tenant route with the correct `Agency-ID`
Then the route retains its existing successful response status and shape

## Edge Cases and Error Scenarios

- EC-1: A custom agency role uses `moderator` plus `comment.moderate` → deny platform moderation access.
- EC-2: A custom agency role uses `support_administrator` plus `audit.view` → deny platform audit access.
- EC-3: A custom agency role uses `platform_administrator` plus `platform.settings` → deny platform settings access.
- EC-4: A custom agency role uses `super_administrator` plus `platform.settings` → deny platform settings access.
- EC-5: A trusted role exists but the permission pivot is absent → deny access.
- EC-6: The user has multiple memberships and only an inactive membership carries the trusted platform role → deny access.
- EC-7: The user has multiple memberships and an active membership in an inactive agency carries the trusted platform role → deny access.
- EC-8: The authenticated principal disappears between authentication and authorization → deny without exposing internals.
- EC-9: The database cannot evaluate authorization relationships → use the existing generic error-redaction path; never grant access.

## API Contracts

No route, request body, or successful response shape changes are introduced.

- `GET /api/v1/me` keeps its existing 200 projection for an active principal and 401 when unauthenticated, and adds the authenticated-inactive 403 response below.
- `GET /api/v1/admin/settings` keeps its existing 200 projection for a trusted platform operator and existing 403 `PLATFORM_PERMISSION_DENIED` response for an active principal without trusted authority.
- The same authenticated-inactive 403 response applies uniformly to every method/path nested under the existing `auth:sanctum` route group.

```typescript
interface AccountAccessDeniedResponse {
  error: {
    code: "ACCOUNT_ACCESS_DENIED";
    message: "This account is not available.";
    fields: Record<string, never>;
    request_id: string | null;
  };
}
```

## Data Models

No migration is required.

| Entity/relationship | Fields used | Required constraint for this slice |
| --- | --- | --- |
| `users` | `id`, `status` | Current persisted `status` is checked on every authenticated request. |
| `agencies` | `id`, `status` | Platform-role membership is valid only when the associated agency is active. |
| `agency_members` | `agency_id`, `user_id`, `status` | Membership must be active. Existing unique agency/user constraint remains. |
| `roles` | `scope`, `slug`, `is_system` | Platform trust requires `scope = platform`, recognized privileged slug, and `is_system = true`. |
| `role_permissions` | `role_id`, `permission_id` | The requested permission must be attached to the trusted role. |
| `permissions` | `name` | Existing unique permission name remains authoritative. |

## Out of Scope

- OS-1: Email verification, password recovery, MFA, and security-version session revocation are separate P1 identity work.
- OS-2: Feature-flag, subscription, entitlement, and quota enforcement are the next containment specification.
- OS-3: Redesigning role storage or migrating system agency roles to a different scope is excluded; this slice uses existing schema safely.
- OS-4: Team invitation acceptance and owner-role invariants are separate membership work.
- OS-5: Changing API version, route paths, successful response shapes, or permission names is excluded.
- OS-6: Production infrastructure, CI/CD, secrets, search, media, and provider integration work remains governed by later release specifications.
