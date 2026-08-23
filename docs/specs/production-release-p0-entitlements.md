# Spec: P0 feature, subscription, entitlement, and quota enforcement

**Author:** Codex  
**Date:** 2026-08-22  
**Status:** Approved  
**Reviewer:** User, via approval to execute the production release plan  
**Related plan:** `PRODUCTION_RELEASE_PLAN.md`, P0.3 and P0.4

## Context

Casaura stores feature flags, tenant overrides, subscriptions, plan entitlements, and quotas, but most API consumers do not enforce them. The existing resolver also falls back to a globally enabled flag when a subscription is missing, inactive, expired, attached to an inactive plan, or lacks the matching entitlement. Consequently, callers can bypass commercial and operational controls by invoking direct API routes.

This slice makes one resolver authoritative, distinguishes global features from plan-gated tenant capabilities, applies it to existing feature consumers, and enforces listing, team, and media quotas at their write-time concurrency boundaries. It does not add billing providers or new product surfaces.

## Functional Requirements

- FR-1: Global feature resolution MUST apply, in order, the environment force-off list, a boolean environment rule, an active global override, and the flag default. A missing flag MUST resolve disabled.
- FR-2: A tenant feature whose key exists in `plan_entitlements` MUST require an active subscription, an active plan, and no elapsed `current_period_ends_at` value.
- FR-3: A positive environment rule or tenant override MUST NOT revive a plan-gated feature for a tenant that fails FR-2.
- FR-4: For an eligible tenant, resolution MUST apply, in order, a boolean environment rule, an active agency override, the tenant plan entitlement, an active global override, and the flag default.
- FR-5: When an eligible tenant's plan lacks a plan-gated entitlement, the feature MUST resolve disabled instead of falling through to a global default.
- FR-6: `customer_registration` and `agency_registration` MUST be enforced before validation or persistence by their public registration endpoints.
- FR-7: `listing_creation` MUST be enforced by the listing creation path. Its plan quota MUST count all non-deleted listings for the agency and be checked while the agency row is locked.
- FR-8: `team_management` MUST be enforced for team list, invite, and update operations. Its plan quota MUST count all current membership records and be checked while the agency row is locked.
- FR-9: `media_storage_mb` MUST be enforced for uploads. Its plan quota MUST count active original and derivative bytes for the agency and include the proposed original and derivative bytes before metadata is committed.
- FR-10: `messaging` MUST be enforced for message reads and sends against the conversation agency.
- FR-11: `viewings` MUST be enforced for tenant listing, creation, update, and authorized calendar export against the viewing agency.
- FR-12: `likes` or `dislikes` MUST be enforced when creating or changing the corresponding reaction against the listing agency. Removing an existing reaction MUST remain available for user cleanup.
- FR-13: Existing storefront and newsletter consumers MUST continue using the central resolver and therefore inherit corrected subscription semantics where the key is plan-gated.
- FR-14: Feature denial MUST return HTTP 403 with code `FEATURE_DISABLED`; quota denial MUST use the existing domain-specific quota code without creating partial database state.
- FR-15: Seeded capabilities without a complete API/UI product surface, including `comparisons` and `collaborative_collections`, MUST default disabled.

## Non-Functional Requirements

- NFR-S1: Resolution MUST default to deny when a flag, subscription, plan, or required entitlement cannot be proven eligible.
- NFR-S2: Error responses MUST NOT expose plan internals, subscription timestamps, quota usage, storage keys, or cross-tenant identifiers.
- NFR-R1: Subscription, entitlement, override, and quota state MUST be read from current persisted state for each request.
- NFR-R2: Listing, membership, and media quota checks MUST be performed in the same database transaction as the protected metadata write and while holding an agency-row lock.
- NFR-P1: Media usage calculation MUST avoid double-counting original bytes when an item has multiple derivatives.
- NFR-C1: Existing successful routes and response projections MUST remain backward compatible for eligible tenants and enabled global features.
- NFR-T1: Regression tests MUST cover resolution precedence, inactive and expired subscriptions, missing entitlements, direct-route feature denial, quota denial, cleanup behavior, and launch-plan compatibility.

## Acceptance Criteria

### AC-1: Active entitled tenant remains enabled (FR-2, FR-4, NFR-C1)
Given an active agency with an active, unexpired subscription to an active plan containing `listing_creation = true`
When the resolver evaluates `listing_creation`
Then it resolves enabled from the plan
And the existing listing creation endpoint can return 201 while below quota

### AC-2: Inactive subscription cannot be bypassed (FR-2, FR-3)
Given `listing_creation` is globally enabled and has a positive agency override
And the agency subscription status is not `active`
When the agency calls `POST /api/v1/listings`
Then the response is 403 with `FEATURE_DISABLED`
And no property, identifier, listing, history, version, or audit row is created

### AC-3: Expired subscription cannot be bypassed (FR-2, FR-3)
Given an active subscription whose `current_period_ends_at` is in the past
When the agency calls a plan-gated route
Then the response is 403 with `FEATURE_DISABLED`

### AC-4: Missing plan entitlement denies (FR-5)
Given an otherwise eligible subscription whose plan lacks `listing_creation`
And the global feature default is enabled
When the agency calls `POST /api/v1/listings`
Then the response is 403 with `FEATURE_DISABLED`

### AC-5: Listing quota is atomic (FR-7, NFR-R2)
Given an eligible plan with listing quota 1 and one non-deleted listing for the agency
When another listing is created
Then the response is 422 with `LISTING_QUOTA_EXCEEDED`
And no partial property or listing data is created

### AC-6: Team feature and quota are authoritative (FR-8)
Given `team_management` is disabled for an eligible tenant
When an authorized owner lists, invites, or updates members
Then the response is 403 with `FEATURE_DISABLED`
Given it is enabled and membership count equals quota
When the owner invites another member
Then the response is 422 with `TEAM_QUOTA_EXCEEDED`

### AC-7: Media byte quota includes derivatives (FR-9, NFR-P1)
Given `media_storage_mb` quota cannot accommodate an upload's original plus generated derivatives
When the upload is attempted
Then the response is 422 with `MEDIA_STORAGE_QUOTA_EXCEEDED`
And no media or derivative row remains
And newly written storage objects are removed

### AC-8: Registration flags block persistence (FR-6)
Given `customer_registration` or `agency_registration` resolves globally disabled
When its registration endpoint receives an otherwise valid request
Then the response is 403 with `FEATURE_DISABLED`
And no user, agency, branch, membership, or subscription is created

### AC-9: Messaging cannot bypass tenant entitlement (FR-10)
Given an authorized conversation participant and a conversation agency whose `messaging` capability is disabled
When the participant reads or sends messages
Then the response is 403 with `FEATURE_DISABLED`
And no message or lead activity mutation occurs

### AC-10: Viewings cannot bypass tenant entitlement (FR-11)
Given an otherwise authorized viewing caller and an agency whose `viewings` capability is disabled
When the caller lists, creates, updates, or exports a viewing
Then the response is 403 with `FEATURE_DISABLED`
And no viewing mutation occurs

### AC-11: Reaction creation honors independent flags (FR-12)
Given a published listing and `likes` disabled while `dislikes` remains enabled
When a consumer submits a like
Then the response is 403 with `FEATURE_DISABLED`
When the consumer submits a dislike
Then the existing success response is returned
And the consumer can still remove either existing reaction

### AC-12: Unsupported launch flags are safe (FR-15)
Given a fresh seeded database
When feature defaults are inspected
Then `comparisons` and `collaborative_collections` are disabled

### AC-13: Central precedence and existing consumers remain consistent (FR-1, FR-13, FR-14)
Given an enabled global default with no higher-priority rule
When global resolution is requested
Then the feature resolves enabled from `global`
Given an active global override or environment force-off rule
When resolution is requested through a storefront or newsletter consumer
Then the higher-priority result is enforced
And every denial returns HTTP 403 with `FEATURE_DISABLED`

## Edge Cases and Error Scenarios

- EC-1: The flag record is absent even though an entitlement exists -> resolve disabled with source `missing`.
- EC-2: Subscription status is active but its plan is inactive -> resolve disabled with source `subscription`.
- EC-3: `current_period_ends_at` equals the current instant -> treat the subscription as expired.
- EC-4: Promotional, trial, and free-until timestamps do not independently expire the launch plan; only status and `current_period_ends_at` are authoritative in this slice.
- EC-5: A force-off entry always denies, including global registrations and eligible tenant capabilities.
- EC-6: A timed override outside its start/end window is ignored.
- EC-7: A plan entitlement boolean value is false -> resolve disabled even when the global default is true.
- EC-8: A quota is null -> the feature is enabled without a numeric limit.
- EC-9: A quota is zero -> no new protected resource can be created.
- EC-10: Soft-deleted listings and media do not consume quotas; undeleted membership invitations do consume team quota.
- EC-11: An idempotent media replay is still denied while the capability is disabled; disabled state is checked before returning prior media.
- EC-12: Storage or derivative generation fails before quota evaluation -> existing storage-unavailable behavior and cleanup apply.

## API Contracts

No successful response schema or route path changes are introduced. Existing endpoints add the following stable denial where applicable:

- `POST /api/v1/auth/register`
- `POST /api/v1/auth/register-agency`
- `POST /api/v1/listings`
- `GET|POST|PATCH /api/v1/agency/team[/{member}]`
- `POST /api/v1/listings/{listing}/media`
- `GET|POST /api/v1/conversations/{conversation}/messages`
- `GET|POST|PATCH /api/v1/viewings[/{viewing}]`
- `GET /api/v1/viewings/{viewing}/calendar`
- `PUT /api/v1/account/reactions/{listing}`

```typescript
interface FeatureDisabledResponse {
  error: {
    code: "FEATURE_DISABLED";
    message: "This feature is not available.";
    fields: Record<string, never>;
    request_id: string | null;
  };
}
```

Quota errors retain the common error envelope and use `LISTING_QUOTA_EXCEEDED`, `TEAM_QUOTA_EXCEEDED`, or `MEDIA_STORAGE_QUOTA_EXCEEDED` with HTTP 422.

The internal resolver contract remains:

```typescript
interface FeatureResolution {
  enabled: boolean;
  value: unknown;
  source: "missing" | "environment" | "subscription" | "agency" | "plan" | "global_override" | "global";
}
```

## Data Models

No migration is required.

| Entity | Fields used | Rule |
| --- | --- | --- |
| `feature_flags` | `key`, `default_enabled`, `environment_rules` | Missing flags deny. |
| `feature_flag_overrides` | scope, enabled/value, start/end | Only active overrides apply. |
| `subscriptions` | agency, plan, status, current period end | Plan-gated features require active and unexpired state. |
| `plans` | `is_active` | Inactive plans deny their capabilities. |
| `plan_entitlements` | key, value, quota | Presence makes a key plan-gated; tenant plan must contain the key. |
| `listings` | agency, deleted timestamp | Non-deleted rows consume listing quota. |
| `agency_members` | agency | All current rows consume team quota. |
| `media` / `media_derivatives` | agency, byte size, deleted timestamp | Active original and derivative bytes consume media quota. |

## Out of Scope

- OS-1: Paid billing providers, invoices, metering periods, upgrades, and payment collection.
- OS-2: Building comparison, collaborative collection, newsletter UI, video, 3D, MLS, AI, sponsorship, or payments product surfaces.
- OS-3: Per-period usage counters other than current row/byte quotas.
- OS-4: Distributed lock infrastructure; database row locks are the launch concurrency boundary.
- OS-5: Subscription grace periods or billing-status policy beyond current launch-plan semantics.
- OS-6: Identity recovery, MFA, consent, membership lifecycle, search, infrastructure, observability, privacy automation, and release documentation work covered by later specifications.
