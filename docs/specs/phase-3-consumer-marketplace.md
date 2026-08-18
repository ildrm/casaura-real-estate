# Spec: Phase 3 Consumer Marketplace

**Author:** Casaura Engineering  
**Date:** 2026-08-18  
**Status:** Approved  
**Reviewers:** Product owner, via the directive to continue the approved milestone roadmap  
**Related specs:** [Phase 2 listing core](phase-2-listing-core.md), [data model](../architecture/data-model.md), [API conventions](../architecture/api.md)

## Context

Phase 2 gives agencies a tenant-safe catalogue and an audited draft-to-publication workflow. Consumers still see development-preview cards because published inventory has no public projection, spatial search boundary, detail serializer, or persisted engagement state. Phase 3 turns approved published listings into a searchable public marketplace while preserving PostgreSQL as the authority and treating the search engine as a rebuildable derived projection.

This slice includes deterministic text/facet/spatial search, a replaceable OpenSearch adapter with a database implementation for local/test environments, privacy-aware public locations, SEO-safe detail pages, and authenticated favorites/reactions/account views. Saved searches, alerts, collections, comparisons, comments, ratings, leads, and AI interpretation remain later slices. The accepted visual contracts are [Casaura search and map](../design/casaura-search-map-concept.png) and [Casaura property detail](../design/casaura-property-detail-concept.png).

## Functional Requirements

- FR-1: Publishing or materially changing a published listing MUST enqueue an idempotent search-projection operation; withdrawal/deletion MUST enqueue removal.
- FR-2: The projection MUST contain only consumer-safe fields and MUST never contain private address lines, exact private coordinates, storage keys, actor IDs, moderation notes, or audit data.
- FR-3: Production MUST support an OpenSearch search/index adapter while tests and local development MAY use the behaviorally equivalent database adapter.
- FR-4: Any projection MUST be rebuildable from PostgreSQL and projection failures MUST remain retryable without changing canonical listing state.
- FR-5: Public search MUST return only published, non-deleted listings and support query, sale/rent intent, price range, property type, bedrooms, bathrooms, area, amenities, currency, and verified-agency filters.
- FR-6: Public search MUST support stable cursor pagination, deterministic sorting, and a machine-readable applied-filter projection.
- FR-7: Public search MUST support map bounds and radius filters; PostgreSQL production queries MUST use PostGIS while local/test behavior remains equivalent.
- FR-8: A listing’s public location MUST be explicit as `exact`, `approximate`, or `hidden`; approximate coordinates MUST be deterministically displaced and exact private coordinates MUST not leak.
- FR-9: Search results MUST expose safe price markers, public coordinates when permitted, total/count metadata, and a synchronized list/map result identity.
- FR-10: The public detail endpoint MUST resolve an SEO slug plus opaque listing UUID and return current published facts, safe media metadata, features, amenities, price history, agency card, and similar listings.
- FR-11: Private original/derivative media object keys MUST remain absent from public APIs; delivery references MUST be signed/public-boundary URLs or safe placeholders.
- FR-12: An authenticated consumer MUST be able to add/remove/list favorites idempotently.
- FR-13: An authenticated consumer MUST be able to set one private `like` or `dislike` reaction per listing, replace it, remove it, and list their reactions.
- FR-14: Engagement writes MUST reject unpublished or cross-state listings without revealing tenant-private facts.
- FR-15: Public reaction aggregates MUST be omitted unless explicitly enabled by the effective feature flag; dislikes remain private by default.
- FR-16: The consumer web app MUST provide the approved responsive split search/map interface, mobile list/map toggle, filter controls, loading/error/empty states, and real API-derived cards.
- FR-17: The consumer web app MUST provide an SEO-friendly property detail page with privacy-aware map, gallery, facts, actions, price history, agency contact handoff, estimates disclaimer, and similar listings.
- FR-18: `/account` MUST provide protected overview, favorites, liked, and disliked sections backed by persisted API state and a clear sign-in handoff when unauthenticated.

## Non-Functional Requirements

- NFR-P1: Public search MUST cap pages at 50 and use stable cursor ordering; it MUST NOT use deep offset pagination.
- NFR-P2: Search SHOULD respond within 500 ms p95 for the documented production profile, excluding third-party map tile latency.
- NFR-P3: Search documents MUST carry a projection version so incompatible mappings can be rebuilt into a new index alias.
- NFR-S1: Public serializers MUST use explicit allowlists and MUST NOT reuse agency-private resources.
- NFR-S2: Favorite/reaction endpoints MUST require authentication, CSRF for cookie writes, per-user object scope, and dedicated rate limiting.
- NFR-S3: Public descriptions MUST render as text or sanitized structured content; untrusted markup MUST NOT execute.
- NFR-R1: Projection outbox creation MUST commit in the same transaction as a publish/update/withdraw decision; external indexing MUST occur after commit.
- NFR-R2: Projection processing MUST be idempotent by listing/version/operation and safe to replay.
- NFR-A1: Search filters, list/map toggle, favorite/reaction controls, gallery, and account tabs MUST be keyboard operable with programmatic names and visible focus.
- NFR-A2: Desktop and 390-pixel layouts MUST have no page-level horizontal overflow; mobile filters MUST not trap focus.
- NFR-SEO1: Detail pages MUST emit canonical metadata and JSON-LD derived only from the published projection; unpublished UUIDs MUST be `noindex`/404.
- NFR-O1: Search/projector failures MUST use stable error codes and structured request/job context without search queries being logged with user identifiers unnecessarily.

## Acceptance Criteria

### AC-1: Project a published listing (FR-1, FR-2, FR-4, NFR-R1)
Given a complete in-review listing
When an authorized reviewer publishes it
Then canonical status/history/audit and a pending versioned projection operation commit atomically
And processing creates one safe searchable document without private address lines, exact private coordinates, or storage keys.

### AC-2: Remove withdrawn inventory (FR-1, FR-5)
Given a published searchable listing
When it is withdrawn
Then a removal operation is queued and, after processing, public search/detail no longer returns it while canonical history remains.

### AC-3: Rebuild idempotently (FR-3, FR-4, NFR-R2)
Given canonical published listings and an empty search index
When the rebuild command runs twice
Then every published listing has exactly one current-version projection, stale/unpublished documents are absent, and no canonical record changes.

### AC-4: Filter public inventory (FR-5, FR-6)
Given published sale/rent listings with varied prices, types, facts, and amenities
When a visitor searches with explicit filters
Then every result satisfies every hard filter, applied filters are echoed, cursor ordering is stable, and no draft/withdrawn listing appears.

### AC-5: Search text safely (FR-5, NFR-S1)
Given published listings in Oakridge and a private draft with matching text
When a visitor searches `Oakridge`
Then only relevant published projections are returned and the response contains no agency-private fields.

### AC-6: Bound and radius search (FR-7, FR-8, FR-9)
Given listings with exact, approximate, and hidden public-location policies
When bounds or radius filters are applied
Then exact/approximate permitted points filter equivalently in PostGIS and test adapters, hidden listings have no marker, and no response contains the private point.

### AC-7: Resolve an SEO-safe detail (FR-10, FR-11, NFR-SEO1)
Given a published listing
When a visitor requests its slug-ID detail endpoint
Then the response contains safe facts, price history, safe media metadata, agency summary, and similar published listings
And a wrong slug redirects/canonicalizes without accepting a different UUID, while a draft returns 404.

### AC-8: Favorite idempotently (FR-12, FR-14)
Given an authenticated consumer and a published listing
When they favorite it twice and then remove it twice
Then one favorite exists after both adds, none exists after both removals, and each response is successful and stable.

### AC-9: Keep reactions private (FR-13, FR-15)
Given an authenticated consumer
When they like, replace with dislike, and remove the reaction
Then at most one user/listing reaction exists at each step, the account API reflects the change, and public APIs expose no dislike identity or aggregate by default.

### AC-10: Reject engagement with unavailable listings (FR-14)
Given a draft or withdrawn listing
When a consumer attempts favorite or reaction writes using its UUID
Then the API returns 404 and no engagement row is created.

### AC-11: Render synchronized desktop search (FR-9, FR-16)
Given published indexed inventory
When a visitor opens desktop search, applies filters, and selects a result
Then URL/API/list/map state agree, the selected card and marker share an ID, loading/error/empty states are honest, and keyboard focus remains visible.

### AC-12: Render mobile search without overflow (FR-16, NFR-A1, NFR-A2)
Given a 390×844 viewport
When a visitor toggles List/Map, opens/closes filters, and pages results
Then controls are reachable, focus returns to the trigger, cards remain within the viewport, and the body has no horizontal overflow.

### AC-13: Render property detail (FR-17, NFR-S3, NFR-SEO1)
Given a published projection
When a visitor opens `/property/{slug}-{id}`
Then canonical title/description/JSON-LD match API facts, approximate location and estimate disclaimers are visible, and unavailable lead/viewing actions truthfully identify their next milestone.

### AC-14: Persist engagement through reload (FR-12, FR-13, FR-17)
Given a signed-in consumer on a detail page
When they favorite or react and reload
Then the selected states hydrate from the API and remain keyboard/screen-reader understandable.

### AC-15: Show protected account state (FR-18)
Given a signed-in consumer with favorites, likes, and dislikes
When they open `/account`
Then each section shows only their persisted published listings; an unauthenticated visitor receives a sign-in handoff without leaked state.

## Edge Cases

- EC-1: Search adapter times out → return `SEARCH_UNAVAILABLE` with request ID; do not fall back to unbounded canonical scans in production.
- EC-2: Outbox worker crashes after indexing but before acknowledgement → replay upserts the same listing/version and acknowledges once.
- EC-3: Listing changes while an older projection is processing → the older version cannot overwrite the newer indexed document.
- EC-4: Price currency differs from requested currency → exclude unless an explicit conversion provider/version is available; never silently compare unlike currencies.
- EC-5: Bounds cross the antimeridian → split longitude predicate correctly or reject with a stable validation error.
- EC-6: Radius is zero, negative, or beyond configured maximum → return 422 field error.
- EC-7: Approximate location is regenerated → deterministic displacement remains stable for the property unless privacy policy/version changes.
- EC-8: Slug contains non-Latin text → normalize safely, preserve UUID resolution, and emit one canonical URL.
- EC-9: Favorite/reaction requests race → database unique constraints and upsert semantics produce one final row.
- EC-10: Listing is withdrawn between detail load and engagement write → write rechecks current public availability and returns 404.

## API Contracts

Public endpoints use `/api/v1/public`; consumer engagement uses the authenticated `/api/v1/account` boundary.

The primary read contract is `GET /api/v1/public/search`; detail uses `GET /api/v1/public/listings/{listing}`. Engagement is mutated with `PUT /api/v1/account/favorites/{listing}` and `PUT /api/v1/account/reactions/{listing}`.

| Method | Endpoint | Auth | Success |
| --- | --- | --- | --- |
| GET | `/api/v1/public/search` | public/rate-limited | 200 cursor page plus filters/map metadata |
| GET | `/api/v1/public/listings/{listing}` | public/rate-limited | 200 public detail projection |
| GET | `/api/v1/account/engagements` | user | 200 favorites/likes/dislikes |
| PUT | `/api/v1/account/favorites/{listing}` | user | 200 idempotent selected state |
| DELETE | `/api/v1/account/favorites/{listing}` | user | 200 idempotent cleared state |
| PUT | `/api/v1/account/reactions/{listing}` | user | 200 selected like/dislike state |
| DELETE | `/api/v1/account/reactions/{listing}` | user | 200 cleared state |

```typescript
interface PublicSearchRequest {
  q?: string;
  intent?: "sale" | "rent";
  min_price?: number;
  max_price?: number;
  currency?: string;
  property_type?: string;
  min_bedrooms?: number;
  min_bathrooms?: number;
  amenities?: string[];
  bounds?: `${number},${number},${number},${number}`;
  radius?: `${number},${number},${number}`;
  sort?: "newest" | "price_asc" | "price_desc";
  cursor?: string;
}

interface PublicSearchResult {
  data: PublicListingCard[];
  meta: {
    next_cursor: string | null;
    count: number;
    applied_filters: Record<string, string | number | string[]>;
  };
}

interface EngagementState {
  listing_id: string;
  favorite: boolean;
  reaction: "like" | "dislike" | null;
}
```

## Data Models

| Model/table | Key fields | Constraints and ownership |
| --- | --- | --- |
| `search_documents` | listing/property/agency IDs, projection version, slug, search text, facets, public point/policy, safe payload | Rebuildable safe public projection; unique listing ID; never canonical |
| `search_projection_outbox` | listing ID, version, operation, attempts, available/processed/error state | Unique operation identity; retryable; external indexing only after commit |
| `favorites` | user ID, listing ID, created timestamp | Unique consumer/listing pair; cascade with consumer/listing |
| `property_reactions` | user ID, listing ID, private `like|dislike`, timestamps | Unique consumer/listing pair; one replaceable private reaction |
| `addresses` extension | public-location policy, public latitude/longitude, PostGIS geography | Exact private fields remain agency-only; production geography has a GiST index |

## Out of Scope

- OS-1: Saved searches, alerts, and notification delivery.
- OS-2: Collaborative collections, comparisons, comments, and ratings.
- OS-3: Leads, viewings, and realtime messaging.
- OS-4: AI/natural-language parsing and semantic/vector ranking.
- OS-5: Third-party map tiles, neighborhood data, and licensed risk/parcel layers.
- OS-6: Currency conversion and public reaction aggregates.
