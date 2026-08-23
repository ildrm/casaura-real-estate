# Spec: Phase 2 Listing Core

**Author:** Casaura Engineering
**Date:** 2026-08-18
**Status:** Approved
**Reviewers:** Product owner, via the directive to continue the approved milestone roadmap
**Related specs:** [Architecture assessment](../architecture/assessment.md), [data model](../architecture/data-model.md), [API conventions](../architecture/api.md)

## Context

Phase 1 established identity, agency tenancy, permissions, entitlements, audit logging, and the public/agency shells. An agency can register and enter a protected workspace, but it cannot persist the inventory that powers its storefront. Dashboard inventory values are therefore development preview data and the primary “Add property” action is intentionally unavailable.

Phase 2 introduces the canonical property/listing boundary and the complete draft-to-publication workflow. A property represents the physical asset and its stable facts; a listing represents one agency’s time-bound marketing offer. The slice must give agency staff a usable inventory table and guided editor while preserving tenant isolation, provenance, history, secure private media, and honest workflow states. Search indexing, consumer property detail, provider ingestion, external moderation operations, and billing remain later milestones.

The approved visual contract is [Casaura properties](../design/casaura-properties-concept.png) and [Casaura listing editor](../design/casaura-listing-editor-concept.png), extending the accepted Phase 1 Casaura agency dashboard.

## Functional Requirements

- FR-1: The system MUST store a physical `property` separately from each agency-owned `listing` that markets it.
- FR-2: An authorized agency member MUST be able to create a draft property and listing atomically with a unique agency reference.
- FR-3: Authorized members MUST be able to list, read, filter, and cursor-paginate only listings owned by the active agency.
- FR-4: Authorized members MUST be able to update mutable draft/listing fields with optimistic concurrency using the current integer version.
- FR-5: The system MUST expose seeded property types, feature definitions, and amenities through a read-only catalogue endpoint.
- FR-6: An authorized member MUST be able to set typed feature values and amenity selections for the listing’s property.
- FR-7: The guided editor MUST autosave a changed step and visibly report saving, saved, validation-error, and version-conflict states.
- FR-8: Authorized members MUST be able to upload JPEG, PNG, or WebP listing images to private storage after server-side MIME, size, and pixel-dimension validation.
- FR-9: Every accepted image MUST produce private WebP thumbnail and display derivatives while preserving the original as a private object.
- FR-10: The system MUST enforce a maximum of 15 MiB per image, 40 megapixels per decoded image, 30 active images per listing, and the agency’s storage entitlement.
- FR-11: Authorized members MUST be able to reorder or soft-delete media without exposing storage paths or private object URLs.
- FR-12: The system MUST calculate a deterministic listing-quality score and a machine-readable checklist after every material edit.
- FR-13: A draft MUST NOT enter `in_review` until all required facts are present, the description has at least 80 characters, at least five active images exist, and quality is at least 80.
- FR-14: A member with `listing.publish` permission MUST be able to publish an `in_review` listing or return it to `changes_requested` with a note.
- FR-15: Every price change and status transition MUST append immutable history, and every successful material update MUST append a listing-version snapshot in the same transaction.
- FR-16: Deleting a draft MUST soft-delete the listing and property; published listings MUST be withdrawn before deletion.
- FR-17: Create, update, media, submit, review, publish, withdraw, and delete actions MUST append an agency-scoped audit event.
- FR-18: The agency UI MUST provide the approved inventory table, filter state, empty/loading/error states, and a responsive six-step editor: Basics, Location, Details, Features, Media, Review.
- FR-19: Upload requests MUST be idempotent by `Idempotency-Key`; replaying a completed request MUST return the original media record without storing another object.

## Non-Functional Requirements

- NFR-P1: Tenant listing queries MUST use an `(agency_id, status, updated_at, id)` index and return no more than 50 rows per request.
- NFR-P2: The list endpoint SHOULD respond within 300 ms p95 for an agency with 10,000 listings on the documented production database profile.
- NFR-S1: Every private endpoint MUST require an authenticated active membership, a verified `Agency-ID`, and its named permission.
- NFR-S2: Object lookup MUST scope by active agency before resolving a listing, property, feature value, or media record.
- NFR-S3: Uploaded bytes MUST be MIME-sniffed and decoded; client extensions and client-provided MIME values MUST NOT be trusted.
- NFR-S4: Original and derivative media MUST remain on a non-public disk and MUST be served later only through an authorized or signed delivery boundary.
- NFR-S5: API responses and logs MUST NOT expose absolute storage paths, raw exceptions, EXIF payloads, or exact private coordinates to unauthenticated callers.
- NFR-R1: Property, listing, version, price-history, status-history, and audit mutations MUST commit or roll back as one database transaction.
- NFR-R2: Autosave MUST debounce edits by at least 600 ms and MUST preserve unsaved form state when the API returns an error.
- NFR-A1: Every editor control MUST have a programmatic label, errors MUST be associated with their controls, and all steps/actions MUST be keyboard operable.
- NFR-A2: Desktop and 390-pixel mobile layouts MUST have no page-level horizontal overflow; the table MAY use a labelled horizontal region when necessary.
- NFR-O1: Every API response MUST include a request ID, and rejected publication/media operations MUST use stable machine-readable error codes.

## Acceptance Criteria

### AC-1: Create a tenant-owned draft (FR-1, FR-2, FR-15, FR-17)
Given an authenticated member with `listing.create` in agency A
When they POST `/api/v1/listings` with a valid reference, intent, property type, and basic facts
Then the response is 201 with separate property and listing UUIDs, status `draft`, version 1, a quality result, and agency A ownership
And initial listing-version, status-history, price-history, and audit records exist in the same transaction.

### AC-2: Reject cross-tenant object access (FR-3, NFR-S1, NFR-S2)
Given a listing owned by agency A and an authenticated active member of agency B
When the member lists, reads, updates, deletes, submits, publishes, or uploads media using the agency A listing UUID
Then the API returns 404 or tenant-denied without revealing the object
And no property, listing, history, media, or audit state is changed.

### AC-3: List and filter inventory (FR-3, NFR-P1)
Given an agency owns published, draft, in-review, and needs-attention listings
When an authorized member GETs `/api/v1/listings?status=draft&q=Maple&limit=20`
Then every returned item belongs to the active agency, matches the status/query, includes quality and primary-media projection, and the response includes a stable next cursor when more results exist.

### AC-4: Autosave a current version (FR-4, FR-7, FR-12, FR-15)
Given a draft at version 3
When the editor PATCHes it with `version: 3` and valid changed fields
Then the API returns 200 with version 4, recalculated quality/checklist, and the saved projection
And one immutable version snapshot and any applicable price history are appended.

### AC-5: Reject a stale autosave (FR-4, FR-7)
Given the stored listing is version 4
When a client PATCHes it with `version: 3`
Then the API returns 409 with code `LISTING_VERSION_CONFLICT`, the current version, and no mutation.

### AC-6: Read canonical catalogue values (FR-5)
Given the seed catalogue exists
When an authenticated agency member GETs `/api/v1/property-catalog`
Then the response contains stable property-type, amenity, and typed feature-definition slugs without tenant-private data.

### AC-7: Save features and amenities (FR-6, FR-12, FR-15)
Given an editable draft and valid catalogue slugs
When an authorized member PATCHes `features` and `amenity_slugs`
Then typed values are validated, selections are synchronized for that property, version increments, quality is recalculated, and a version snapshot is appended.

### AC-8: Upload and derive a valid image (FR-8, FR-9, FR-17, FR-19, NFR-S3, NFR-S4)
Given an editable listing below quota
When an authorized member POSTs a valid image with a new `Idempotency-Key`
Then the response is 201 with safe media metadata
And the private original, 480-pixel thumbnail, and 1600-pixel display WebP objects exist
And no absolute storage path appears in the response.

### AC-9: Reject unsafe media (FR-8, FR-10, NFR-S3)
Given an editable listing
When a member uploads an oversized, unsupported, corrupt, or over-40-megapixel payload
Then the API returns a stable validation/media error
And no media row, derivative row, or storage object remains.

### AC-10: Enforce media quota and idempotency (FR-10, FR-19)
Given a listing has 30 active images or an upload key has already completed
When another unique upload is sent or the completed key is replayed
Then a unique upload returns 422 `MEDIA_QUOTA_EXCEEDED`
And a replay returns the original media projection without creating duplicate rows or objects.

### AC-11: Calculate honest quality (FR-12)
Given a draft has only basic facts
When its projection is requested
Then its quality score is below publication threshold and its checklist names missing location, description, features, or media requirements without invented analytics.

### AC-12: Block incomplete review submission (FR-13, NFR-R1)
Given a draft does not satisfy every review prerequisite
When an authorized member POSTs `/api/v1/listings/{id}/submit`
Then the API returns 422 `LISTING_NOT_READY` with the current checklist
And status/history/version remain unchanged.

### AC-13: Submit a complete draft (FR-13, FR-15, FR-17)
Given a draft satisfies every review prerequisite
When an authorized member submits it
Then status becomes `in_review`, submitted time is set, and status-history, version, and audit entries are appended atomically.

### AC-14: Publish or request changes (FR-14, FR-15, FR-17)
Given an `in_review` listing and a member with `listing.publish`
When they publish it or request changes with a non-empty note
Then the only resulting status is `published` or `changes_requested`
And the transition, reviewer identity, note, version snapshot, and audit event are recorded atomically.

### AC-15: Preserve history and deletion rules (FR-15, FR-16)
Given a draft price changes and a published listing is selected for deletion
When the draft is updated and the published listing is deleted directly
Then a price-history row preserves the former/current price
And direct published deletion returns 409 until the listing is withdrawn.

### AC-16: Complete the responsive agency workflow (FR-7, FR-18, NFR-A1, NFR-A2)
Given an authenticated agency owner on desktop or a 390-pixel viewport
When they open Properties, create a listing, complete editor steps, observe autosave, upload media, and review readiness
Then route state and persisted API data remain synchronized, labels/errors are accessible, core actions are keyboard operable, and no page-level horizontal overflow occurs.

### AC-17: Reorder and remove owned media (FR-11, FR-15, FR-17)
Given an editable listing with three active images owned by the active agency
When an authorized member submits a complete order for those media IDs and then deletes one image
Then positions are persisted without accepting foreign IDs, the selected image is soft-deleted with its derivatives quarantined, quality is recalculated, and version/audit records are appended.

## Edge Cases

- EC-1: Duplicate `(agency_id, reference)` during concurrent creation → one request succeeds and the other returns 409 `LISTING_REFERENCE_EXISTS` with no orphan property.
- EC-2: Database failure after property insert → the transaction rolls back the property, listing, history, and audit writes.
- EC-3: Unknown taxonomy, amenity, or feature slug → return 422 field errors and preserve the previous selections.
- EC-4: Feature value does not match its declared boolean, integer, decimal, string, or enum type → return 422 `FEATURE_VALUE_INVALID`.
- EC-5: Storage write or derivative generation fails → delete any objects written during the attempt, roll back database rows, and return 503 `MEDIA_STORAGE_UNAVAILABLE`.
- EC-6: A file has an allowed extension but disallowed sniffed MIME → reject it as `MEDIA_TYPE_UNSUPPORTED`.
- EC-7: Two autosaves use the same version concurrently → exactly one succeeds; the other receives `LISTING_VERSION_CONFLICT`.
- EC-8: Media reorder includes a media UUID from another listing or tenant → return 422 without changing any position.
- EC-9: Review submission races with a final autosave → transaction/version locking produces a current complete submission or a version conflict; it MUST NOT publish stale facts.
- EC-10: A soft-deleted media record is replayed with its former idempotency key → return 409 `UPLOAD_KEY_RETIRED`; do not restore it implicitly.

## API Contracts

All private endpoints require a Sanctum session, `Agency-ID`, and `Request-ID`; writes also require CSRF for cookie authentication. Listing writes return `ETag: \"{version}\"`.

The contract includes `GET /api/v1/listings`, `POST /api/v1/listings`, `PATCH /api/v1/listings/{listing}`, and the workflow/media endpoints tabulated below.

```typescript
type ListingStatus = "draft" | "changes_requested" | "in_review" | "published" | "withdrawn";
type ListingIntent = "sale" | "rent";

interface ListingWriteRequest {
  version?: number; // required on PATCH
  reference?: string;
  intent?: ListingIntent;
  property_type_slug?: string;
  title?: string;
  description?: string;
  price?: { amount_minor: number; currency: string };
  bedrooms?: number;
  bathrooms?: number;
  interior_area?: { value: number; unit: "sq_ft" | "sqm" };
  address?: {
    line_1: string; line_2?: string; locality: string; region: string;
    postal_code: string; country_code: string;
  };
  features?: Record<string, boolean | number | string | null>;
  amenity_slugs?: string[];
}

interface QualityResult {
  score: number;
  ready_for_review: boolean;
  checklist: Array<{ key: string; complete: boolean; message: string }>;
}

interface ListingProjection {
  id: string;
  property_id: string;
  agency_id: string;
  reference: string;
  intent: ListingIntent;
  status: ListingStatus;
  version: number;
  title: string | null;
  description: string | null;
  price: { amount_minor: number; currency: string } | null;
  property: Record<string, unknown>;
  quality: QualityResult;
  primary_media: MediaProjection | null;
  updated_at: string;
}

interface MediaProjection {
  id: string;
  original_name: string;
  mime_type: "image/jpeg" | "image/png" | "image/webp";
  byte_size: number;
  width: number;
  height: number;
  position: number;
  alt_text: string | null;
}

interface ListingError {
  error: {
    code: string;
    message: string;
    fields: Record<string, string[]>;
    request_id: string;
    current_version?: number;
    checklist?: QualityResult["checklist"];
  };
}
```

| Method | Endpoint | Permission | Success |
| --- | --- | --- | --- |
| GET | `/api/v1/property-catalog` | active member | 200 catalogue |
| GET | `/api/v1/listings` | `listing.view` | 200 cursor page |
| POST | `/api/v1/listings` | `listing.create` | 201 projection |
| GET | `/api/v1/listings/{listing}` | `listing.view` | 200 projection |
| PATCH | `/api/v1/listings/{listing}` | `listing.update` | 200 projection |
| DELETE | `/api/v1/listings/{listing}` | `listing.delete` | 204 |
| POST | `/api/v1/listings/{listing}/submit` | `listing.update` | 200 projection |
| POST | `/api/v1/listings/{listing}/publish` | `listing.publish` | 200 projection |
| POST | `/api/v1/listings/{listing}/request-changes` | `listing.publish` | 200 projection |
| POST | `/api/v1/listings/{listing}/withdraw` | `listing.publish` | 200 projection |
| GET | `/api/v1/listings/{listing}/media` | `listing.view` | 200 media list |
| POST | `/api/v1/listings/{listing}/media` | `media.manage` | 201 or idempotent 200 |
| PATCH | `/api/v1/listings/{listing}/media/order` | `media.manage` | 200 media list |
| DELETE | `/api/v1/listings/{listing}/media/{media}` | `media.manage` | 204 |

Errors use the existing Casaura envelope and include 401 unauthenticated, 403 missing permission, 404 tenant-scoped not found, 409 conflict, 422 validation/readiness/quota failure, 429 limiter, 503 storage failure, and generic 500 without internal details.

## Data Models

### Core catalogue and listing records

| Entity | Key fields | Constraints and indexes |
| --- | --- | --- |
| `property_types` | UUID, slug, name, category, active | unique slug; shared seeded catalogue |
| `properties` | UUID, agency, property type, address, canonical facts, timestamps, soft delete | index `(agency_id, property_type_id)`; tenant-owned private record |
| `property_identifiers` | UUID, property, scheme, value, source | unique `(scheme, value, source)` where provider rules permit |
| `addresses` | UUID, agency, formatted components, normalized string, latitude/longitude | index `(agency_id, locality, region)`; exact location private |
| `listings` | UUID, agency, property, creator, reference, intent, status, title, description, price minor/currency, version, quality, workflow timestamps, soft delete | unique `(agency_id, reference)`; index `(agency_id, status, updated_at, id)` |

### Facts and amenities

| Entity | Key fields | Constraints and indexes |
| --- | --- | --- |
| `property_feature_definitions` | UUID, slug, name, value type, unit, validation JSON, active | unique slug; immutable type after use |
| `property_feature_values` | UUID, property, definition, typed JSON value | unique `(property_id, definition_id)` |
| `amenities` | UUID, slug, name, group, active | unique slug |
| `property_amenities` | property, amenity | composite primary key |

### History and workflow

| Entity | Key fields | Constraints and indexes |
| --- | --- | --- |
| `listing_versions` | UUID, listing, version, actor, snapshot JSON, created time | unique `(listing_id, version)`; append-only |
| `listing_status_history` | UUID, listing, from/to status, actor, note, created time | append-only index `(listing_id, created_at)` |
| `price_history` | UUID, listing, amount minor, currency, effective time, actor | append-only index `(listing_id, effective_at)` |

### Media

| Entity | Key fields | Constraints and indexes |
| --- | --- | --- |
| `media` | UUID, agency, listing, idempotency key, safe metadata, private storage key, dimensions, position, checksum, timestamps, soft delete | unique `(listing_id, idempotency_key)`; index `(listing_id, position)` |
| `media_derivatives` | UUID, media, kind, private storage key, MIME, dimensions, bytes | unique `(media_id, kind)` |

All UUIDs are server-generated. Money is integer minor units with uppercase ISO currency. Measurements are stored canonically as square metres and projected in the selected display unit. History tables are append-only at the model boundary.

## Out of Scope

- OS-1: Public property-detail pages, saved/favorite reactions, and search indexing — Phase 3 consumes only published projections.
- OS-2: Lead capture, viewings, conversations, and realtime collaboration — Phase 4.
- OS-3: External moderator queues and administrator case management — Phase 6; Phase 2 supplies agency review transitions and immutable evidence.
- OS-4: MLS/RESO ingestion, provider photo rights, deduplication, and canonical cross-agency merging — Phase 7.
- OS-5: AI-generated descriptions or photo analysis — Phase 9; quality uses deterministic documented rules only.
- OS-6: Paid promoted listings, billing quotas purchased through a processor, and advertising placement — Phase 10.
- OS-7: Video, 3D tours, PDFs, floor plans, and remote URL imports — excluded until their independent security/rights workflows are specified.
