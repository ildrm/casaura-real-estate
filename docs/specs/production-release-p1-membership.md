# Spec: P1 safe agency invitation and membership lifecycle

**Author:** Codex  
**Date:** 2026-08-22  
**Status:** Approved  
**Reviewer:** User, via approval to execute the production release plan  
**Related plan:** `PRODUCTION_RELEASE_PLAN.md`, P1.3

## Context

The current team endpoint creates an active user with an unknown random password, creates an invited membership, and exposes an update route that can set invited members active without proving control of the invited email. It has no invite token, expiry, resend, cancel, or acceptance process. It also lacks last-owner, assignment-ceiling, and explicit public-profile controls; the public storefront currently includes every active member.

This slice keeps agency-managed invitations in GA and makes the lifecycle complete. Invite links are delivered through the configured mail transport, tokens are single-use and hashed at rest, existing accounts must authenticate before binding, and newly created invitee accounts prove mailbox control through the invite before setting a password. Public team visibility becomes explicit opt-in.

## Functional Requirements

- FR-1: An authorized team manager MUST invite an allowlisted agency role by normalized email; the write MUST enforce the central feature and team quota under the agency row lock.
- FR-2: An invitation MUST create or bind one user, create an `invited` membership, store only a SHA-256 invite-token hash, set a configurable expiry, record inviter/created-user state, audit the transition, and send the raw token only through the notification URL.
- FR-3: A new-user invitee MUST accept with the valid token and a strong confirmed password. Acceptance MUST set the password, mark the email verified, activate the membership, consume the token, and establish a rotated first-party session.
- FR-4: An invite for a pre-existing user MUST require an authenticated principal whose user ID matches the invited membership. Token possession alone MUST NOT reset or bind an existing account.
- FR-5: Acceptance MUST reject missing, malformed, expired, cancelled, consumed, status-mismatched, or cross-user tokens with stable errors and no partial mutation.
- FR-6: Resend MUST be available only for an invited membership, replace the prior token/expiry atomically, audit the action, and invalidate the previous link.
- FR-7: Cancel MUST be available only for an invited membership, set it inactive, clear token/expiry, record cancellation, audit the action, and make every issued link unusable.
- FR-8: Generic team update MUST NOT activate an invited or inactive member. Activation is performed only by successful invite acceptance.
- FR-9: A role assignment MUST remain within the actor's assignment ceiling. Only an active agency owner may assign `agency_owner`; no agency actor may assign a platform-administration role through team endpoints.
- FR-10: An update that would remove, demote, or deactivate the last active agency owner MUST fail with HTTP 409 `LAST_OWNER_REQUIRED`.
- FR-11: If the canonical `agencies.owner_user_id` is deactivated or demoted while another active owner exists, ownership MUST move atomically to another active owner.
- FR-12: Membership public visibility MUST default false. Only an active member explicitly marked `is_public = true` may appear on the public storefront, ordered by explicit public position then creation time.
- FR-13: Team list and mutation responses MUST expose invitation status, expiry, inviter-independent state, and public visibility, but MUST NOT expose token hash, raw token, password state, recovery state, or cross-tenant data.
- FR-14: `/agency/team` is the canonical lifecycle surface. The legacy `/agency/members` read route MAY remain as a documented compatibility alias but MUST use the same tenant boundary and safe projection.

## Non-Functional Requirements

- NFR-S1: Invitation lookup and acceptance default to deny and use constant-time token-hash comparison through an indexed exact hash lookup.
- NFR-S2: Raw invite tokens, passwords, password hashes, and token hashes MUST never appear in API responses, audit records, logs, analytics, or public projections.
- NFR-S3: Acceptance, resend, cancellation, quota enforcement, role change, and last-owner evaluation MUST use transactions and row locks at their replay/concurrency boundary.
- NFR-R1: Notification delivery failure MUST leave an invited membership that can be safely resent; it MUST not activate or delete the invitee.
- NFR-C1: Existing team listing and successful invitation response shapes remain compatible except for additive lifecycle/public fields.
- NFR-P1: Owner and duplicate checks MUST use indexed relationship queries and avoid loading unrelated tenants.
- NFR-T1: Regression tests MUST cover new/existing users, expiry, replay, resend, cancel, cross-account binding, assignment ceiling, last owner, quota, and public visibility.

## Acceptance Criteria

### AC-1: New user receives a safe invitation (FR-1, FR-2, FR-13)
Given an eligible owner below team quota
When `POST /api/v1/agency/team` invites a new email
Then one unverified user and one invited membership are created
And a notification containing the raw token is sent
And only the token hash is stored
And the API/audit response contains no token or password material

### AC-2: New user acceptance is single-use (FR-3, FR-5)
Given a valid unexpired new-user invitation
When `POST /api/v1/auth/invitations/{token}/accept` submits a strong confirmed password
Then the membership becomes active, the email becomes verified, the token is cleared, and authentication is established
When the same token is submitted again
Then acceptance fails and no second transition occurs

### AC-3: Existing account binding requires its principal (FR-4)
Given an invitation bound to a pre-existing user
When a guest or different authenticated user presents the token
Then the response is 401/403 `INVITATION_AUTH_REQUIRED`
When the matching authenticated user presents it
Then the membership activates without changing that user's password

### AC-4: Expired and cancelled invitations fail closed (FR-5, FR-7)
Given one expired invite and one cancelled invite
When either token is presented
Then the response is 410 `INVITATION_EXPIRED` or 422 `INVITATION_INVALID`
And neither membership activates

### AC-5: Resend invalidates the previous link (FR-6)
Given an invited membership
When `POST /api/v1/agency/team/{member}/invitation` succeeds
Then a new expiry/hash is stored and a new notification is sent
And the old token no longer resolves

### AC-6: Generic update cannot bypass acceptance (FR-8)
Given an invited member
When `PATCH /api/v1/agency/team/{member}` requests `status = active`
Then validation fails
And the membership remains invited

### AC-7: Assignment ceiling prevents privilege escalation (FR-9)
Given an agency manager with team-management permission
When the manager assigns `agency_owner` or a platform-administration role
Then the response is 403/422 with the stable assignment/role error
And no role pivot changes

### AC-8: Agency cannot lose its last owner (FR-10, FR-11)
Given one active agency owner
When that owner is demoted or deactivated
Then the response is 409 `LAST_OWNER_REQUIRED`
Given two active owners and the canonical owner is demoted
When the update succeeds
Then `agencies.owner_user_id` moves to the remaining owner atomically

### AC-9: Public team is opt-in (FR-12)
Given active members with default visibility
When the public storefront is requested
Then none appears in the team projection
When one active member is explicitly made public with a position
Then only that member appears in the expected order

### AC-10: Canonical team API remains tenant-safe (FR-13, FR-14)
Given two agencies with members
When an owner lists or mutates `/api/v1/agency/team`
Then only the selected tenant's safe member projection is returned or changed
And token/password fields never appear

## Edge Cases and Error Scenarios

- EC-1: Email differs only by case -> bind the same user and reject duplicate membership.
- EC-2: An inactive prior membership is re-invited -> reuse that membership with a fresh invited lifecycle instead of creating a duplicate.
- EC-3: An invited user record is suspended before acceptance -> deny acceptance.
- EC-4: Notification delivery fails -> report safely and retain resendable invited state.
- EC-5: Resend and acceptance race -> row lock permits only the current hash to activate.
- EC-6: Cancel and acceptance race -> exactly one terminal transition wins.
- EC-7: A member is marked public while invited/inactive -> reject or force visibility false.
- EC-8: Public positions collide -> deterministic creation-time and ID tie-breakers preserve stable output.
- EC-9: The actor tries to mutate a member from another agency -> return 404.
- EC-10: Team quota is reached by invited members -> a new invitation is denied because invitations consume capacity.

## API Contracts

- `GET /api/v1/agency/team`
- `POST /api/v1/agency/team`
- `PATCH /api/v1/agency/team/{member}`
- `POST /api/v1/agency/team/{member}/invitation`
- `DELETE /api/v1/agency/team/{member}/invitation`
- `POST /api/v1/auth/invitations/{token}/accept`
- `GET /api/v1/agency/members` (compatibility read alias)

```typescript
interface TeamMember {
  id: string;
  status: "invited" | "active" | "inactive";
  job_title: string | null;
  is_public: boolean;
  public_position: number | null;
  invitation_expires_at: string | null;
  user: { id: string; name: string; email: string };
  roles: Array<{ id: string; name: string; slug: string }>;
}

interface InvitationAcceptance {
  password?: string;
  password_confirmation?: string;
}
```

## Data Models

| Entity | Added/used fields | Rule |
| --- | --- | --- |
| `agency_members` | inviter, invite hash/expiry/cancel, created-user marker, public visibility/position | One membership carries the complete lifecycle; hash is unique and nullable. |
| `users` | email, password, status, verification, security version | New invitees set password only at acceptance; existing passwords are untouched. |
| `roles`, `member_roles` | trusted allowlisted role and assignment rank | Target role cannot exceed actor ceiling. |
| `agencies` | canonical owner user | Must always identify an active owner when membership changes allow it. |
| `audit_logs` | invite/resend/cancel/accept/member change actions | Stores redacted lifecycle evidence. |

## Out of Scope

- OS-1: SCIM, directory sync, bulk CSV invitation, or domain-claim auto-join.
- OS-2: Platform operator provisioning UI; platform roles remain outside agency team assignment.
- OS-3: Public agent biography/avatar redesign beyond explicit visibility and ordering.
- OS-4: SMS invitation delivery.
- OS-5: Deleting user accounts when invitations are cancelled; orphan cleanup is handled by privacy/retention automation.
