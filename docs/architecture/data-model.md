# Domain model and database design

## Core ER diagram

```mermaid
erDiagram
  USERS ||--o{ AGENCY_MEMBERS : joins
  AGENCIES ||--o{ AGENCY_MEMBERS : contains
  AGENCIES ||--o{ AGENCY_BRANCHES : operates
  AGENCY_MEMBERS ||--o{ MEMBER_ROLES : receives
  ROLES ||--o{ MEMBER_ROLES : assigned
  ROLES ||--o{ ROLE_PERMISSIONS : grants
  PERMISSIONS ||--o{ ROLE_PERMISSIONS : contains
  AGENCIES ||--o{ PROPERTIES : owns_private_record
  PROPERTIES ||--o{ PROPERTY_IDENTIFIERS : identified_by
  PROPERTIES ||--o{ LISTINGS : marketed_as
  AGENCIES ||--o{ LISTINGS : publishes
  LISTINGS ||--o{ LISTING_VERSIONS : snapshots
  LISTINGS ||--o{ LISTING_STATUS_HISTORY : transitions
  LISTINGS ||--o{ PRICE_HISTORY : changes
  LISTINGS ||--o{ MEDIA : presents
  LISTINGS ||--|| SEARCH_DOCUMENTS : projects
  LISTINGS ||--o{ SEARCH_PROJECTION_OUTBOX : queues
  USERS ||--o{ FAVORITES : saves
  LISTINGS ||--o{ FAVORITES : receives
  USERS ||--o{ PROPERTY_REACTIONS : reacts
  LISTINGS ||--o{ PROPERTY_REACTIONS : receives
  LISTINGS ||--o{ DATA_SOURCE_RECORDS : sourced_from
  PROVIDER_CONNECTIONS ||--o{ DATA_SOURCE_RECORDS : imports
  PROVIDER_CONNECTIONS ||--o{ SYNC_JOBS : schedules
  USERS ||--o{ COLLECTIONS : owns
  COLLECTIONS ||--o{ COLLECTION_PROPERTIES : orders
  LISTINGS ||--o{ COLLECTION_PROPERTIES : saved_in
  AI_SESSIONS ||--o{ AI_GENERATIONS : contains
  AI_GENERATIONS ||--o{ AI_CITATIONS : grounded_by
  AGENCIES ||--o{ BILLING_CUSTOMERS : bills
  AGENCIES ||--o{ PROMOTION_CAMPAIGNS : sponsors
  PROMOTION_POLICIES ||--o{ PROMOTION_CAMPAIGNS : governs
  AGENCIES ||--o{ LEADS : receives
  LISTINGS ||--o{ LEADS : generates
  LEADS ||--o{ VIEWING_REQUESTS : schedules
  AGENCIES ||--o{ SUBSCRIPTIONS : has
  PLANS ||--o{ SUBSCRIPTIONS : selected
  PLANS ||--o{ PLAN_ENTITLEMENTS : grants
  FEATURE_FLAGS ||--o{ FEATURE_FLAG_OVERRIDES : resolves
  AGENCIES ||--o{ FEATURE_FLAG_OVERRIDES : customizes
  USERS ||--o{ AUDIT_LOGS : acts
```

## Phase 1 physical tables

| Table | Important fields and constraints |
| --- | --- |
| `users` | UUID PK; unique case-normalized email; verified/suspended timestamps; locale/timezone; credential security version; encrypted MFA secret/recovery codes; hashed password |
| `agencies` | UUID PK; unique slug; legal/profile fields; verification/status enums; owner user FK; timezone; soft delete only for business recovery |
| `agency_branches` | UUID PK; `agency_id`; slug unique per agency; address/location placeholders; `is_primary` |
| `agency_members` | UUID PK; unique `(agency_id,user_id)`; status; job title; invited/accepted timestamps; inviter; hashed single-use invitation token; expiry/cancellation; public-profile opt-in |
| `roles` | UUID PK; optional `agency_id`; system roles immutable; unique scoped slug |
| `permissions` | UUID PK; unique machine name such as `agency.manage_members` |
| `role_permissions` | composite unique role/permission FKs |
| `member_roles` | composite unique agency-member/role FKs; role scope must match member agency or be global |
| `plans` | UUID PK; unique slug; active/public flags; price metadata (no processor secrets) |
| `plan_entitlements` | unique plan/key; typed JSON value; optional quota/reset period |
| `subscriptions` | agency FK; plan FK; status; promotion/trial/free-until/current-period/billing timestamps |
| `feature_flags` | unique key; global default; environment rules JSON; description; history timestamps |
| `feature_flag_overrides` | unique `(feature_flag_id, scope_type, scope_id)`; nullable boolean/value; validity window |
| `audit_logs` | append-only UUID PK; actor, agency, action, entity, before/after JSON, IP, request ID, timestamp |
| `personal_access_tokens` | Sanctum token hashes and abilities; never store plaintext tokens |
| `consent_records` | Immutable user/agency purpose, legal version/text/hash, source, consent timestamp, and additive revocation evidence |

## Phase 2 physical tables

| Table | Important fields and constraints |
| --- | --- |
| `property_types` | UUID PK; globally unique stable slug; category and active state |
| `amenities` | UUID PK; globally unique stable slug; presentation group and active state |
| `property_feature_definitions` | UUID PK; unique slug; typed value contract, unit, and JSON validation rules |
| `addresses` | UUID PK; required agency ownership; normalized address and private coordinate fields; tenant/locality index |
| `properties` | UUID PK; agency, property type, optional address; canonical bedrooms, bathrooms, metric area; recoverable soft delete |
| `property_identifiers` | UUID PK; property, scheme, value, source; unique property/scheme/value identity |
| `listings` | UUID PK; agency/property/creator; unique agency reference; intent, workflow state, copy, minor-unit price, optimistic version, quality score, transition timestamps; tenant workflow index; soft delete |
| `property_feature_values` | One JSON value per property/feature definition; application layer enforces the definition’s declared type |
| `property_amenities` | Composite property/amenity PK for deterministic synchronization |
| `listing_versions` | Immutable unique `(listing_id, version)` JSON snapshots with actor and timestamp |
| `listing_status_history` | Append-only from/to state, actor, review note, and timestamp |
| `price_history` | Append-only minor-unit amount, ISO currency, actor, and effective timestamp |
| `media` | Agency/listing ownership; unique listing idempotency key; sniffed MIME, decoded dimensions, checksum, private object key, position, alt text; soft delete |
| `media_derivatives` | Unique media/kind private WebP derivative metadata; storage keys never leave private API serializers |

## Phase 3 physical tables and extensions

| Table or extension | Important fields and constraints |
| --- | --- |
| `listings.slug` | Stable public slug paired with the opaque listing UUID in canonical property URLs |
| `addresses` public-location fields | Explicit `public_location_policy` plus nullable public coordinates; exact private coordinates remain tenant-only |
| `search_documents` | One public-safe document per published listing; denormalized text, facts, features, amenities, agency state, media metadata, public location, projection version, and listed timestamp |
| `search_projection_outbox` | Idempotent per-listing operation/version queue with attempts, processed/failure timestamps, and last error for rebuildable search delivery |
| `favorites` | Unique `(user_id, listing_id)` private account relationship with cascading ownership constraints |
| `property_reactions` | Unique `(user_id, listing_id)` private `like`/`dislike` state; replacement does not create duplicate reactions |
| PostgreSQL spatial extensions | PostGIS geography points and GiST indexes for public and private locations; SQLite stores equivalent scalar coordinates for deterministic local tests |

## Phase 4 physical tables

| Table | Important fields and constraints |
| --- | --- |
| `leads` | Agency/listing/consumer/assignee ownership; idempotency key and payload hash; validated contact; inquiry consent version/text/hash/time; status, priority, optimistic version, response timestamps; tenant pipeline index |
| `lead_status_history` | Append-only from/to status and assignee, actor, note, and timestamp |
| `conversations` | One agency-owned conversation per lead with listing subject and last-message cursor timestamp |
| `conversation_participants` | Unique conversation/user role; participant scope is authoritative for consumer access |
| `messages` | UUIDv7 cursor identity, participant sender, plain-text body, append-only timestamp |
| `viewing_requests` | Agency/lead/listing/consumer/assignee, timezone-aware interval, status, notes, optimistic version |
| `viewing_status_history` | Append-only viewing transition evidence |
| `reminders` | Agency and assigned-user scope, optional lead/viewing target, due/status/dispatched timestamps |
| `notifications` | User-owned in-app notification with optional agency, allowlisted data, read time, and deduplication key |

## Phase 5 physical tables

| Table | Important fields and constraints |
| --- | --- |
| `agency_opening_hours` | Unique agency/weekday schedule with explicit closed state |
| `agency_closures` | Unique agency/date exception with optional reduced hours/reason |
| `newsletter_subscribers` | Unique agency/email consent record, idempotency evidence, hashed unsubscribe token, and lifecycle timestamps |
| `newsletter_campaigns` | Agency/author draft or sent plain-text campaign with immutable sent timestamp |
| `newsletter_events` | Unique campaign/subscriber delivery outcome and adapter name; append-only |
| `analytics_events` | Privacy-safe agency/listing event type, hourly anonymous-session hash for public-view deduplication, allowlisted metadata, and occurrence timestamp |

## Production hardening tables

| Table | Important fields and constraints |
| --- | --- |
| `data_subject_requests` | Subject/requester, export or deletion type, controlled status, opaque operator approval reference, encrypted-object key/checksum, failure code, completion and expiry timestamps |

## Phase 6 physical tables and extensions

| Table or extension | Important fields and constraints |
| --- | --- |
| `abuse_reports` | Immutable reporter/listing/category/details plus idempotency evidence |
| `moderation_cases` | One case per report, target, workflow status, assignee, outcome/note, optimistic version |
| `moderation_case_history` | Append-only actor/assignee/status/outcome evidence |
| `settings.version` | Optimistic version for non-secret platform setting updates; secret values remain externally managed |

## Phase 7 physical tables

| Table | Important fields and constraints |
| --- | --- |
| `real_estate_data_providers` | Stable adapter/protocol key, active state, and declared capabilities |
| `provider_connections` | Tenant/provider ownership; RESO base/token URLs; client ID plus named secret reference; resource, rights, Data Dictionary, lifecycle, and optimistic version state |
| `field_mappings` | Unique connection/resource/version mapping JSON with actor and activation evidence |
| `sync_jobs` | Connection-scoped idempotency and payload hash; full/incremental mode, committed cursors, counts, failure code, and lifecycle timestamps |
| `data_source_records` | Immutable connection/resource/external ID/payload-hash identity; optional canonical property/listing; mapping/rights snapshot, provider timestamp, outcome, and diagnostic envelope |
| `import_errors` | Sync/source association, stable validation code/field, retryability, redacted detail, and resolution evidence |
| `duplicate_candidates` | Tenant/source/canonical candidate, bounded score/reasons, optimistic decision state, actor, and reversible merge snapshot |

## Phase 8 physical tables

| Table | Important fields and constraints |
| --- | --- |
| `collections` | User ownership, version, timestamps, and recoverable soft delete |
| `collection_members` | Unique collection/user role with acceptance and revocation evidence |
| `collection_invitations` | Hashed invited email and single-use token, role, expiry, acceptance, and revocation |
| `collection_properties` | Unique collection/listing and collection/position constraints with adding actor |
| `comparison_histories` | Private user, ordered listing IDs, and unique content fingerprint for idempotency |
| `market_aggregate_cache` | Hashed cohort key, explicit cohort size, aggregate JSON, calculation time, and expiry; cohorts below the privacy threshold are never returned |

## Phase 9 physical tables

| Table | Important fields and constraints |
| --- | --- |
| `ai_sessions` / `ai_messages` | User/purpose lifecycle, expiry, role, optional redacted content, and immutable content hash |
| `ai_generations` | Optional session/tenant/listing, adapter/model/purpose/status, prompt hash, constrained filters/output, token/latency telemetry, safety code, and expiry |
| `ai_citations` | Generation/source identity, allowlisted field paths, and grounded snapshot hash |
| `ai_listing_suggestions` | Tenant/listing/generation, source listing version, suggested/applied field sets, human actor, and apply timestamp |
| `ai_safety_events` | Redacted category/action/rule version and immutable occurrence time; no raw prompt or contact data |

## Phase 10 physical tables and extensions

| Table or extension | Important fields and constraints |
| --- | --- |
| `plans.provider_price_id` | Optional unique Stripe price identity; no secret material |
| `subscriptions` provider fields | Stripe customer/subscription identity plus monotonic provider timestamp and cancellation lifecycle |
| `billing_customers` | Unique tenant/provider and provider/customer relationships with version |
| `billing_checkout_sessions` | Tenant/plan/actor, idempotency and payload hash, unique provider session, hosted URL, status, and expiry |
| `billing_events` | Unique provider event, provider time, payload hash, safe object/customer identifiers and summary, resolution status/failure; raw webhook payload is not retained |
| `invoices` | Tenant/subscription/provider identity, minor-unit subtotal/tax/total, ISO currency, period, provider timestamp, and provider-hosted document URLs |
| `promotion_policies` | Immutable family/version, placement/label/disclosure, eligible plans, window, cap, lifecycle, and actor |
| `promotion_campaigns` | Tenant/listing/policy, duplicated controlled placement, schedule, cap/count, lifecycle, and optimistic version |
| `promotion_impressions` | Campaign, placement, hour-bucketed HMAC visitor dedupe hash, and occurrence time with a replay-preventing unique constraint |

## Remaining optional marketplace extensions

The following are designed now and delivered by vertical slice, not as empty speculative migrations:

- Catalogue extensions: `listing_sources`, `rooms`, `developments`, `buildings`, `units`.
- Geography: `locations`, `geographic_boundaries`, parcel/cadastral provider references.
- Media extensions: `floor_plans`, `property_documents`, `upload_sessions`, `storage_migrations`.
- Discovery/engagement: `property_comments`, `property_ratings`, `saved_searches`, `search_alerts`, `search_demand_events`.
- CRM/collaboration extensions: `open_houses` and provider-specific delivery receipts.
- Agency growth extensions: `agency_verifications`, dedicated agent profiles, and external campaign-provider state.
- Trust/admin extensions: `cms_entries` and sanctions/appeals.

## Index strategy

- B-tree: tenant FK first for every agency-owned list/query, e.g. `(agency_id, status, created_at desc)`.
- Partial: active listings and pending moderation queues.
- GiST: exact/public property points, service polygons, geography boundaries, drawn searches.
- GIN/trigram: normalized addresses, identifiers, reference numbers, and deduplication candidates.
- Unique: source identity `(provider_id, resource, external_record_id)` and per-agency slugs/reference IDs.
- BRIN: append-only analytics, audit, and ingestion events once tables become large.
- Cursor pagination uses a stable compound sort `(rank_or_timestamp, id)`; no deep offset pagination.

## Constraints and invariants

- A listing references one physical property but does not own its identity.
- An agency can never assign a private record to another agency through mass assignment.
- Status and price changes append history in the same transaction as the current value update.
- Monetary values use integer minor units plus ISO 4217 currency; measurements use canonical metric values with display conversion.
- External fields store field-level provenance when they affect consumer-visible facts.
- Uncertain duplicate candidates never auto-merge. A merge is reversible and preserves every source/listing record.

## Tenant isolation model

Agency-owned tables carry a non-null `agency_id` unless a documented shared/public record requires otherwise. API middleware resolves the active membership from the authenticated user and `Agency-ID` header. Policies check both capability and object ownership. Repository/application queries accept tenant context; background jobs serialize the tenant ID and re-resolve it before work. Tests exercise guessed UUIDs and cross-agency list/read/update/delete attempts.

PostgreSQL RLS can add a second barrier using a transaction-local `app.agency_id`. It will be enabled only after queue, migration, support-access, and connection-pool behavior are covered by integration tests.

## Retention strategy

| Data | Default strategy |
| --- | --- |
| Audit/security events | 7 years or jurisdiction policy; append-only; tightly restricted |
| Raw provider payloads | Provider contract maximum; hash/metadata may outlive payload for traceability |
| Search analytics | Remove anonymous identifiers after 7 days and delete raw events after 90 days by scheduled policy |
| Conversations/leads | Agency-configurable within legal minima/maxima; delete or anonymize on closure |
| Media | Quarantine on delete; purge originals/derivatives after 30 days unless legal hold; reconcile referenced objects daily |
| Account data | Encrypted export expires after 7 days; reviewed deletion revokes credentials and anonymizes direct personal data while preserving restricted lawful evidence |
| Backups | Encrypted rolling retention with deletion propagation schedule and tested restore |

## Provenance model

`data_source_records` stores provider, resource, external ID, fetched/changed timestamps, sync status, raw hash, mapping version, rights snapshot, and optionally encrypted/compressed raw payload. Consumer-visible canonical fields can point to a source-field record. Corrections append a new mapping/result version rather than overwriting diagnostic history.

## RESO mapping strategy

1. Provider adapters implement `RealEstateDataProviderInterface` and return versioned neutral envelopes.
2. Connections pin provider-specific Data Dictionary/resource versions and contractual display rules.
3. Declarative mappings translate provider fields/enums/units to canonical DTOs; mappings are versioned and testable with fixtures.
4. Delta tokens and modification timestamps drive incremental imports; idempotency keys prevent replay duplication.
5. Required attribution, photo rights, retention, visibility, and refresh requirements travel with every record.
6. Validation failures enter a retryable import-error queue; source deletions become explicit expiry/withdrawal events.
7. Add/Edit capability is an optional provider port and never assumed from read access.
