# Spec: P1 production product workflows and web runtime contract

**Author:** Codex  
**Date:** 2026-08-22  
**Status:** Approved  
**Reviewer:** User, via approval to execute the production release plan  
**Related plan:** `PRODUCTION_RELEASE_PLAN.md`, P1.4 and P1.8

## Context

The API contains the core listing, lead, viewing, reminder, and team operations, but several browser journeys stop before their terminal actions. Lead assignment clearing is recorded incorrectly, any active agency member can export a viewing calendar, public lead retry handling has a uniqueness race, reminder dispatch is not scheduled, and production web builds silently accept localhost and fixed US assumptions. This slice completes the supported controlled-GA workflows and makes runtime configuration explicit.

## Functional Requirements

- FR-1: The listing editor MUST expose permission-aware publish, request-changes, withdraw, delete, media reorder, and media delete actions for states in which the API permits them.
- FR-2: Listing, lead, viewing, invitation, and campaign changes MUST follow explicit server-side transition matrices and return stable domain errors without partial history.
- FR-3: Clearing a lead assignee MUST write a history row whose `to_assigned_member_id` is null.
- FR-4: Viewing calendar export MUST be limited to the linked consumer or an active tenant member with `lead.manage`.
- FR-5: Public inquiry idempotency MUST resolve concurrent retries to one lead and reject payload reuse with HTTP 409.
- FR-6: `reminders:dispatch` MUST run every minute under a singleton scheduler lock and remain idempotent.
- FR-7: Team UI MUST support invite, resend, cancel, deactivate, and explicit public visibility without offering direct activation.
- FR-8: Invitation acceptance MUST be available in the browser for both new and existing users.
- FR-9: Protected agency pages MUST revalidate `/me`, select only an active membership, and provide an agency selector sourced from server-authorized memberships.
- FR-10: The launch locale, currency, area unit, country, API origin, and canonical origin MUST come from a validated public environment contract; production MUST reject localhost/insecure origins.
- FR-11: Static synthetic market/location counts MUST not render in production. Public discovery MUST use real API projections or honest empty states.
- FR-12: Sitemap output MUST exclude noindex search and include current published listing and public agency URLs from an allowlisted API projection.

## Non-Functional Requirements

- NFR-S1: Browser visibility and local storage are convenience state only; every request remains server-authorized.
- NFR-S2: Destructive browser actions require explicit confirmation, disable while pending, and surface the server error.
- NFR-C1: Existing response fields remain compatible; new fields and discovery endpoints are additive.
- NFR-R1: Idempotency, scheduler, and transition writes are transactional and replay-safe.
- NFR-A1: New controls use native labels, buttons, status regions, and keyboard-operable ordering controls.
- NFR-T1: API regression, web lint, typecheck, production build, and browser critical-journey tests gate the slice.

## Acceptance Criteria

### AC-1: Complete listing lifecycle (FR-1, FR-2)
Given an authorized staff member and a listing in each supported state
When the browser renders the editor
Then only valid actions for that state and the principal permissions are enabled
And successful actions refresh the canonical projection
And delete or withdraw requires confirmation and returns to inventory when terminal.

### AC-2: Correct collaboration history and access (FR-2, FR-3, FR-4)
Given an assigned lead
When its assignment is cleared
Then history records null as the destination
Given a confirmed viewing
When a tenant member without `lead.manage` requests the calendar
Then the API responds 404 while the linked consumer and permitted member succeed.

### AC-3: Inquiry replay is concurrency-safe (FR-5)
Given the same agency and idempotency key
When equivalent inquiries race or retry
Then one lead/conversation exists and both callers resolve the same lead
When the payload differs
Then the API returns `IDEMPOTENCY_CONFLICT`.

### AC-4: Reminder scheduler is operational (FR-6)
Given a due pending reminder
When repeated scheduler ticks execute
Then one notification is emitted and the reminder is marked dispatched
And `schedule:list` shows the singleton minute task.

### AC-5: Team lifecycle is truthful in the browser (FR-7, FR-8)
Given a pending invitation
When a manager uses team controls
Then resend replaces the link, cancel invalidates it, and no activate button is offered
And an invitee can accept through the public invitation page.

### AC-6: Protected workspace is server-derived (FR-9)
Given an unauthenticated, unverified, suspended, or membership-less principal
When an agency page loads
Then it redirects or renders a stable access state
Given multiple active memberships
Then the selector contains only those memberships and changes the active agency safely.

### AC-7: Production configuration and discovery fail closed (FR-10, FR-11, FR-12)
Given a production build
When required origins or launch-market variables are absent, insecure, or localhost
Then validation fails
Given valid configuration
Then locale/currency render consistently, fake market counts do not appear, search is absent from sitemap, and current published resources are included.

## Edge Cases and Error Scenarios

- EC-1: A permission is removed while an editor is open -> the next action fails server-side and the UI displays the denial.
- EC-2: Media reorder receives a partial/duplicate list -> validation fails and order is unchanged.
- EC-3: Two lead retries reach the uniqueness boundary before either pre-check returns -> insert conflict resolves to the canonical lead.
- EC-4: Scheduler overlap on two nodes -> shared lock permits one dispatcher.
- EC-5: Stored active agency no longer belongs to the principal -> select the first active membership or show no-workspace state.
- EC-6: Sitemap API is unavailable -> return only stable static indexable routes rather than invented entries.
- EC-7: A withdrawn listing remains in a stale sitemap response -> its public route still returns not found/noindex.

## API Contracts

- Existing listing workflow and media routes under `/api/v1/listings/{listing}`.
- Existing lead, viewing, team, invitation, and reminder command routes.
- `GET /api/v1/public/discovery` returns allowlisted listing and agency sitemap records only.

```typescript
interface PublicDiscovery {
  listings: Array<{ id: string; slug: string; updated_at: string }>;
  agencies: Array<{ slug: string; updated_at: string }>;
}
```

## Data Models

| Entity | Fields/invariants used | Production rule |
| --- | --- | --- |
| `listings`, `listing_status_history` | status, version, actor, note | Only the explicit listing transition matrix appends history. |
| `leads`, `lead_status_history` | status, assignment, version, idempotency key/hash | Status and assignment changes are optimistic, atomic, and record explicit null destinations. |
| `viewing_requests`, `viewing_status_history` | status, schedule, participant, version | Only explicit transitions are accepted; calendar access is participant/permission scoped. |
| `reminders`, `notifications` | due/status/dispatched timestamp and dedupe key | A reminder produces at most one due notification. |
| `agency_members` | active status, permissions, public state | Workspace selection and team visibility derive from current server state. |
| public search/storefront projections | listing ID/slug/update and agency slug/update | Discovery exposes no private address, contact, tenant, or identity fields. |

## Out of Scope

- OS-1: Consumer self-registration and broad consumer collaboration while their GA feature flags remain off.
- OS-2: Paid billing, provider inventory, OpenSearch traffic, and newsletter delivery through the local adapter.
- OS-3: Multi-locale translation catalog or RTL beyond the single configured launch market.
