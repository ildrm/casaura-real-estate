# Spec: Phase 5 Agency Product

**Author:** Casaura Engineering  
**Date:** 2026-08-19  
**Status:** Approved  
**Reviewers:** Product owner, via the directive to complete roadmap phases 4–6  
**Related specs:** [Phase 4 leads and collaboration](phase-4-leads-collaboration.md), [product map](../architecture/product-map.md), [API conventions](../architecture/api.md)

## Context

The agency foundation can store a basic profile and membership, but public agency discovery, opening hours, role-managed team operations, compliant newsletter capture, and real performance reporting are incomplete. Phase 5 turns those foundations into a coherent agency product while reusing the tenant, entitlement, feature-flag, listing, lead, and analytics boundaries already shipped.

No external campaign provider or licensed market-intelligence feed is selected. Newsletter sending therefore uses a replaceable delivery port and auditable local delivery events. Analytics are derived only from Casaura events and canonical records; the UI must label date ranges and must not invent market benchmarks.

## Functional Requirements

- FR-1: An active agency with the `agency_storefronts` feature enabled MUST expose a public storefront by stable slug.
- FR-2: The storefront MUST show only allowlisted agency profile, verified state, opening hours, active public team members, and published listing cards.
- FR-3: Members with `agency.manage_profile` MUST be able to replace weekly opening hours and exceptional closures using the agency timezone.
- FR-4: Members with `agency.manage_members` MUST be able to invite a unique email, set job title, activate/deactivate membership, and assign allowed non-platform roles within the active agency.
- FR-5: Team quotas MUST use the effective plan entitlement and MUST reject writes that exceed it.
- FR-6: Visitors MUST be able to subscribe idempotently to an enabled agency newsletter with explicit consent and receive an opaque unsubscribe token.
- FR-7: Subscribers MUST be able to unsubscribe without authentication and resubscribe only through a new consent action.
- FR-8: Authorized agency members MUST be able to create draft newsletter campaigns, update content, and send once to active subscribers when the effective `newsletters` flag is enabled.
- FR-9: Campaign sending MUST use a replaceable delivery adapter and append per-recipient delivery events without exposing tokens or recipient lists publicly.
- FR-10: Casaura MUST record allowlisted storefront/listing views and CRM outcomes as append-only analytics events without raw query text or unnecessary identifiers.
- FR-11: Members with `analytics.view` MUST receive date-bounded storefront, listing, engagement, lead, viewing, and newsletter aggregates derived from stored records/events.
- FR-12: The web app MUST provide responsive public storefront and agency growth/team/newsletter/analytics flows with loading, error, empty, disabled-feature, and quota states.

## Non-Functional Requirements

- NFR-P1: Public storefront responses SHOULD complete within 500 ms p95 for 50 published listings excluding media delivery.
- NFR-S1: Public serializers MUST exclude member email, legal identifiers, exact private addresses, subscriber data, and internal analytics events.
- NFR-S2: Subscription and unsubscribe writes MUST be rate limited and idempotent; tokens MUST be random and stored as hashes.
- NFR-S3: Campaign content MUST render as plain text or sanitized content and MUST NOT execute untrusted markup.
- NFR-R1: Team, hours, subscriber, campaign, and event mutations MUST append audit evidence in the same transaction where applicable.
- NFR-R2: A campaign MUST transition from draft to sent at most once; a replay MUST not duplicate delivery events.
- NFR-A1: Storefront sections, team editor, hours editor, subscriber form, campaign editor, and analytics ranges MUST be keyboard accessible.
- NFR-A2: Desktop and 390-pixel layouts MUST have no page-level horizontal overflow.
- NFR-O1: Analytics and delivery failures MUST use stable codes/request IDs without logging subscriber tokens or campaign bodies.

## Acceptance Criteria

### AC-1: Render a safe storefront (FR-1, FR-2, NFR-S1)
Given an active agency with storefronts enabled, hours, active team, and published inventory
When a visitor opens `/professionals/{slug}`
Then only public profile/team/hours/listings are shown and private membership/contact/legal fields are absent.

### AC-2: Hide unavailable storefronts (FR-1)
Given a disabled feature, inactive/deleted agency, or unknown slug
When its storefront is requested
Then the API/page returns 404 without internal state details.

### AC-3: Replace opening hours atomically (FR-3, NFR-R1)
Given an authorized profile manager
When they submit a complete valid weekly schedule and closures
Then the previous schedule is replaced atomically, projected in agency timezone, and audited.

### AC-4: Manage a quota-bound team (FR-4, FR-5)
Given an authorized owner below quota
When they invite a new unique email, assign an agency role, and activate the member
Then one user/membership exists with only allowed permissions and an audit trail.

### AC-5: Prevent cross-tenant or platform-role assignment (FR-4, NFR-S1)
Given an agency manager
When they target another tenant's member or a platform administration role
Then the API returns 404 or 422 and no role assignment changes.

### AC-6: Subscribe and unsubscribe safely (FR-6, FR-7, NFR-S2)
Given newsletters are enabled and a visitor consents
When they subscribe twice then use the returned unsubscribe token
Then one subscriber exists, consent evidence is retained, and the token deactivates only that subscription.

### AC-7: Respect the newsletter feature gate (FR-6, FR-8)
Given newsletters are disabled for an agency
When subscription, campaign creation, or sending is attempted
Then the API returns 403 `FEATURE_DISABLED` and persists no newsletter data.

### AC-8: Send a campaign once (FR-8, FR-9, NFR-R2)
Given a valid draft and two active subscribers
When an authorized member sends it twice
Then the campaign is sent once and exactly two successful or failed delivery events identify the adapter outcome.

### AC-9: Record privacy-safe events (FR-10, NFR-S1)
Given public storefront and property traffic
When views are recorded
Then events contain allowlisted entity/type/time fields and omit raw query, private address, message, subscriber token, and user email values.

### AC-10: Report honest agency analytics (FR-11)
Given events, favorites, leads, viewings, and campaign delivery records in a requested range
When an authorized analyst requests analytics
Then aggregates match canonical counts, include the effective UTC range, and use zero/null for absent metrics.

### AC-11: Complete growth workflows in the web app (FR-12, NFR-A1, NFR-A2)
Given a permitted agency member on desktop or 390-pixel mobile
When they manage hours, team, campaigns, and date-bounded analytics
Then API state remains synchronized, disabled/quota/error states are explicit, and the body has no horizontal overflow.

## Edge Cases

- EC-1: Overlapping hours or close-before-open returns field-level 422 errors and preserves the prior schedule.
- EC-2: Duplicate member email in the same agency returns 409 `MEMBER_EXISTS`.
- EC-3: Team quota exhaustion returns 422 `TEAM_QUOTA_EXCEEDED`.
- EC-4: Reused unsubscribe token is idempotent and reveals no subscriber details.
- EC-5: Invalid/expired token returns 404 without email enumeration.
- EC-6: Empty campaign subject/body or oversized content returns 422.
- EC-7: A campaign with no active recipients may be sent and reports zero deliveries.
- EC-8: Adapter failure records a failed delivery event and does not expose the upstream response.
- EC-9: Analytics range longer than 366 days is rejected.

## API Contracts

The public storefront is `GET /api/v1/public/agencies/{agency:slug}`; tenant growth endpoints follow the table below.

```ts
interface StorefrontProjection { agency: PublicAgency; opening_hours: OpeningHour[]; team: PublicTeamMember[]; listings: PublicListingCard[]; }
interface OpeningHour { weekday: number; opens_at: string | null; closes_at: string | null; closed: boolean; }
interface NewsletterCampaign { id: string; subject: string; body: string; status: "draft" | "sent"; sent_at: string | null; }
interface AgencyAnalytics { range: { from: string; to: string }; storefront_views: number; listing_views: number; favorites: number; leads: number; viewings: number; newsletter_deliveries: number; }
```

| Method | Path | Access | Result |
| --- | --- | --- | --- |
| GET | `/api/v1/public/agencies/{agency:slug}` | public | storefront projection |
| GET/PUT | `/api/v1/agency/opening-hours` | member / `agency.manage_profile` | schedule projection |
| GET/POST/PATCH | `/api/v1/agency/team[/{member}]` | `agency.manage_members` | team projection |
| POST | `/api/v1/public/agencies/{agency}/newsletter/subscriptions` | public, limited | subscription receipt/token |
| DELETE | `/api/v1/public/newsletter/subscriptions/{token}` | public, limited | 204 |
| GET/POST/PATCH | `/api/v1/agency/newsletter/campaigns[/{campaign}]` | `agency.manage_profile` | campaign projection |
| POST | `/api/v1/agency/newsletter/campaigns/{campaign}/send` | `agency.manage_profile` | sent campaign/delivery summary |
| GET | `/api/v1/agency/analytics` | `analytics.view` | date-bounded aggregates |

## Data Models

| Entity | Key fields and constraints |
| --- | --- |
| `agency_opening_hours` | agency, weekday, opens/closes, closed; unique agency/weekday |
| `agency_closures` | agency, date, optional hours/reason; unique agency/date |
| `newsletter_subscribers` | agency, normalized email, status, consent timestamp/source, token hash, unsubscribed timestamp; unique agency/email |
| `newsletter_campaigns` | agency, author, subject/body, status, sent timestamp |
| `newsletter_events` | campaign, subscriber, event type, adapter, error code, timestamp; append-only unique campaign/subscriber/type |
| `analytics_events` | agency, optional listing, type, occurred timestamp, anonymous session hash, metadata allowlist |

## Out of Scope

- OS-1: External email marketing providers, visual template builders, drip automation, and open/click tracking pixels.
- OS-2: Licensed comparative market data, attribution modeling, and cross-agency benchmarks.
- OS-3: Platform moderation/configuration — Phase 6.
- OS-4: Billing upgrades and paid newsletter quotas — Phase 10.
