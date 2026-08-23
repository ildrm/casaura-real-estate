# Spec: Phase 8 Advanced Marketplace

**Author:** Casaura Engineering
**Date:** 2026-08-23
**Status:** Approved
**Reviewers:** Product owner, approved completion of Phases 7–10 on 2026-08-23
**Related specs:** Phase 3 consumer marketplace, Phase 7 data integrations, product map, API conventions

## Context

Casaura already provides public search, safe map coordinates, listing detail, favorites, reactions, and account collaboration. Phase 8 adds durable collections, bounded comparisons, explainable recommendations, public-safe map layers, and honest market analytics without treating provider inventory or sparse cohorts as statistical truth.

The phase uses only published allowlisted projections. Recommendations remain deterministic so they are available without an AI provider and can be explained and tested. Collaborative collection access is explicit and revocable.

## Functional Requirements

- FR-1: Authenticated consumers MUST be able to create, rename, list, and soft-delete private collections.
- FR-2: Collection owners MUST be able to add or remove currently published listings idempotently and preserve a stable manual order.
- FR-3: When collaborative_collections is enabled, owners MUST be able to invite, revoke, and assign viewer or editor access using expiring single-use invitations.
- FR-4: Collection members MUST see only collections they own or have accepted access to; editors MAY reorder/add/remove items and viewers MUST remain read-only.
- FR-5: When comparisons is enabled, users MUST be able to compare two through five published listings using a stable allowlist of price, location, property facts, amenities, and provenance freshness.
- FR-6: Authenticated users MUST be able to save comparison history privately and remove it.
- FR-7: Public listing detail and account surfaces MUST provide deterministic, explainable recommendations derived from current published search projections.
- FR-8: Search MUST expose optional public-safe map layers for price bands, listing density, and property type, using only approximate/public coordinates.
- FR-9: Public market analytics MUST provide date/range and location-scoped inventory, median price, median unit price, and listing-age aggregates only when the minimum cohort is met.
- FR-10: Sponsored inventory MUST NOT influence Phase 8 organic recommendations or market aggregates.
- FR-11: The web app MUST provide responsive collection, comparison, recommendation, map-layer, and market-report flows with loading, error, empty, disabled, stale, and access-revoked states.

## Non-Functional Requirements

- NFR-S1: Collection and comparison APIs MUST enforce user ownership/membership before resource lookup and MUST not expose private activity publicly.
- NFR-S2: Map layers and analytics MUST use public coordinates and a minimum cohort of five listings; exact tenant coordinates and sparse statistics MUST never be returned.
- NFR-R1: Collection membership, ordering, and comparison saves MUST be transactionally idempotent and reject stale versions.
- NFR-P1: Comparison MUST be capped at five listings and recommendation results MUST be capped at twelve.
- NFR-P2: Market reports SHOULD respond within 750 ms p95 for a supported 366-day range using indexed aggregates.
- NFR-A1: Collections, comparison tables, layer controls, and charts MUST be keyboard operable and provide non-visual labels/text equivalents.
- NFR-A2: Desktop and 390-pixel layouts MUST have no page-level horizontal overflow; comparison tables MAY use a labelled horizontal scroll region.
- NFR-O1: Recommendation explanations and report cohort/range metadata MUST be present without raw user search text or private identifiers in logs.

## Acceptance Criteria

### AC-1: Manage a private collection (FR-1, FR-2, NFR-S1, NFR-R1)
Given an authenticated consumer and three published listings
When the consumer creates a collection, adds the listings twice, reorders them, and removes one
Then one private collection contains two uniquely ordered items at the latest version.

### AC-2: Collaborate with bounded roles (FR-3, FR-4, NFR-S1)
Given collaboration is enabled and an owner invites an editor and a viewer
When both accept and attempt to modify the collection
Then the editor can mutate items, the viewer receives 403, and revocation removes access immediately.

### AC-3: Prevent cross-account disclosure (FR-4, NFR-S1)
Given a private collection owned by another user
When an authenticated user guesses its UUID
Then list, read, update, and delete operations return 404 without collection metadata.

### AC-4: Compare bounded published inventory (FR-5, NFR-P1)
Given comparisons are enabled and five published listings exist
When a user requests their IDs
Then the API returns an ordered allowlisted comparison with missing facts rendered as null and no private address or moderation data.

### AC-5: Save private comparison history (FR-6, NFR-R1)
Given an authenticated user has a valid comparison
When they save the same ordered set twice and later delete it
Then one private history record exists before deletion and none appears afterward.

### AC-6: Explain deterministic recommendations (FR-7, FR-10, NFR-O1)
Given a published listing and eligible similar inventory including a sponsored campaign
When recommendations are requested
Then results are ranked by documented organic similarity, include reason codes, and ignore sponsorship.

### AC-7: Return public-safe map layers (FR-8, NFR-S2)
Given published listings with exact private and approximate public coordinates
When a visitor requests density and price-band layers
Then only bucketed public coordinates and aggregate values are returned.

### AC-8: Suppress sparse market analytics (FR-9, FR-10, NFR-S2, NFR-P2)
Given a location cohort below five published listings
When its market report is requested
Then the response marks the cohort insufficient and returns no median values; eligible cohorts exclude sponsored weighting.

### AC-9: Complete advanced marketplace web flows (FR-11, NFR-A1, NFR-A2)
Given consumer, collaborator, revoked, and feature-disabled states on desktop or mobile
When users operate collections, comparison, layers, and reports
Then controls match authorization, states are explicit, text equivalents exist, and the page has no body overflow.

## Edge Cases

- EC-1: An item becomes unpublished; retain its private collection position but mark it unavailable and exclude it from public facts.
- EC-2: A collection invitation expires or is replayed; return a generic invalid response and reveal no owner.
- EC-3: A comparison has fewer than two or more than five unique IDs; return field-level 422.
- EC-4: Duplicate listing IDs in a comparison collapse deterministically before count validation.
- EC-5: A recommendation candidate is the subject itself; exclude it.
- EC-6: Price currencies differ; do not compute relative-price reasons across currencies.
- EC-7: A polygon crosses the dateline; reuse the Phase 3 spatial normalization.
- EC-8: Market range exceeds 366 days; reject with 422.
- EC-9: Cohort values are even in count; calculate the median deterministically from the two central values.

## API Contracts

POST /api/v1/account/collections creates a private collection. GET /api/v1/public/compare accepts a comma-separated list of two through five opaque listing IDs.

| Method | Path | Access | Result |
| --- | --- | --- | --- |
| GET/POST | /api/v1/account/collections | user | collection list/create |
| GET/PATCH/DELETE | /api/v1/account/collections/{collection} | member/owner | projection/update/delete |
| PUT/DELETE/PATCH | /api/v1/account/collections/{collection}/items | editor | add/remove/reorder |
| POST/DELETE | /api/v1/account/collections/{collection}/members | owner | invite/revoke |
| POST | /api/v1/account/collection-invitations/{token}/accept | user | membership |
| GET | /api/v1/public/compare | public, flagged | comparison projection |
| GET/POST/DELETE | /api/v1/account/comparisons[/{comparison}] | user | private history |
| GET | /api/v1/public/listings/{listing}/recommendations | public | organic recommendations |
| GET | /api/v1/public/map-layers | public | bucketed public layers |
| GET | /api/v1/public/market-analytics | public | cohort-aware aggregates |

## Data Models

| Entity | Key fields and constraints |
| --- | --- |
| collections | owner user, name, version, soft-delete timestamp |
| collection_members | collection, user, role viewer/editor, accepted/revoked timestamps; unique collection/user |
| collection_invitations | collection, invited email hash, role, token hash, expiry, accepted/revoked timestamps |
| collection_properties | collection, listing, position, added actor; unique collection/listing and collection/position |
| comparison_histories | user, ordered listing IDs, fingerprint, created timestamp; unique active user/fingerprint |
| market_aggregate_cache | normalized scope/range/filter key, cohort, aggregate JSON, calculated/expiry timestamps; derived and rebuildable |

## Out of Scope

- OS-1: Public collections, anonymous collaborative editing, comments, ratings, or social feeds.
- OS-2: Currency conversion, appraisal, investment advice, price forecasting, or provider-only benchmark claims.
- OS-3: Personalized machine-learning ranking; Phase 8 recommendations are deterministic and explainable.
- OS-4: Exact parcel boundaries, private coordinates, or cohorts smaller than the privacy threshold.

