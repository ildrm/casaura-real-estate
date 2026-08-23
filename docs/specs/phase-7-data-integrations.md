# Spec: Phase 7 Data Integrations

**Author:** Casaura Engineering
**Date:** 2026-08-23
**Status:** Approved
**Reviewers:** Product owner, approved the provider-neutral and live RESO implementation on 2026-08-23
**Related specs:** Phase 2 listing core, data model, API conventions, ADR-001 launch profile

## Context

Casaura has a canonical property/listing boundary, secure media, publication workflow, search projection, tenant permissions, and an integration.configure permission. It does not yet persist provider connections, execute licensed feed synchronization, map RESO resources, retain field provenance, or route uncertain duplicates to a human.

Phase 7 adds provider-neutral ingestion ports plus a production RESO Web API/OData adapter. Tests use deterministic fixtures; production connections obtain secrets from the deployment secret manager. A code-complete connection is not permission to activate a feed: MLS credentials, display rules, attribution, photo rights, retention, and refresh obligations remain required deployment evidence.

## Functional Requirements

- FR-1: Members with integration.configure MUST be able to create, inspect, disable, and version tenant-owned provider connections without storing plaintext credentials.
- FR-2: The ingestion boundary MUST expose a provider-neutral interface and a configurable RESO Web API/OData adapter supporting metadata discovery, bearer-token authentication, pagination, and incremental modification cursors.
- FR-3: Authorized members MUST be able to start an idempotent full or incremental sync and inspect its queued, running, completed, partial, or failed result.
- FR-4: Every fetched record MUST retain immutable source identity, payload hash, provider timestamps, mapping version, rights snapshot, and sync outcome.
- FR-5: Versioned declarative field mappings MUST translate RESO resources, enums, units, addresses, prices, identifiers, and display rules into canonical validation input.
- FR-6: Invalid provider records MUST NOT mutate canonical inventory and MUST create retryable, redacted import errors tied to the source record and mapping version.
- FR-7: Exact source replays MUST be idempotent; uncertain identity matches MUST create duplicate candidates and MUST NOT auto-merge.
- FR-8: Authorized reviewers MUST be able to reject, link, or merge a duplicate candidate, and every merge MUST be reversible while preserving source and listing history.
- FR-9: Accepted records MUST create or update tenant-owned properties/listings transactionally, respect provider visibility/expiry state, and enqueue the existing search projection.
- FR-10: The web app MUST provide a responsive integration workspace for connections, mappings, sync runs, import errors, and duplicate review with explicit loading, empty, disabled, and failure states.

## Non-Functional Requirements

- NFR-S1: Provider secrets MUST come from named secret-manager references, MUST be encrypted in transit, and MUST never appear in API responses, logs, audit payloads, or database columns.
- NFR-S2: Provider base URLs and token URLs MUST use HTTPS in production and MUST be constrained to operator-approved origins to prevent server-side request forgery.
- NFR-S3: Tenant lookup MUST precede connection, sync, error, mapping, source-record, and duplicate-candidate lookup.
- NFR-R1: Sync scheduling MUST use idempotency keys and singleton connection locks; retries MUST resume from the last committed page or delta cursor.
- NFR-R2: Canonical mutation, provenance, history, and outbox writes MUST commit atomically.
- NFR-P1: A sync page MUST be bounded to at most 500 records and queue work MUST release memory between pages.
- NFR-O1: Syncs MUST emit structured counts, duration, provider code, connection ID, request/release IDs, and redacted failure codes.
- NFR-A1: Connection and review forms MUST be keyboard operable with visible focus and associated errors.
- NFR-A2: Desktop and 390-pixel layouts MUST have no page-level horizontal overflow.

## Acceptance Criteria

### AC-1: Configure a safe connection (FR-1, NFR-S1, NFR-S2, NFR-S3)
Given an authorized agency owner and an approved HTTPS provider origin
When the owner creates a connection using a secret reference
Then the connection is tenant-owned and the response, audit log, and database contain no plaintext token.

### AC-2: Read a paginated RESO feed (FR-2, FR-3, NFR-R1, NFR-P1)
Given a fixture RESO endpoint with two pages and a modification cursor
When an authorized member starts an incremental synchronization
Then both bounded pages are consumed once, the cursor is committed after canonical writes, and the run reports deterministic counts.

### AC-3: Preserve provenance and mapping versions (FR-4, FR-5, NFR-R2)
Given a RESO Property record and an active mapping version
When the record imports successfully
Then its immutable source identity, payload hash, rights snapshot, mapping version, canonical property/listing mutation, history, and search outbox commit together.

### AC-4: Quarantine invalid input (FR-6, NFR-S1)
Given a provider record with an invalid currency or required field
When mapping and canonical validation run
Then no canonical inventory changes and a redacted retryable import error identifies the field and mapping version.

### AC-5: Make replay idempotent (FR-7, NFR-R1)
Given a source record already imported at a payload hash and provider timestamp
When the same record and sync idempotency key are replayed
Then no duplicate property, listing, history, or source record is created.

### AC-6: Review an uncertain duplicate (FR-7, FR-8, NFR-S3)
Given two non-exact records with a similarity score above the review threshold
When a permitted reviewer links, rejects, or merges the candidate
Then no automatic merge occurs, the chosen decision is audited, and another tenant cannot read or mutate the candidate.

### AC-7: Reverse a merge (FR-8, NFR-R2)
Given a reviewed merge with preserved source snapshots
When an authorized reviewer reverses it
Then the canonical relationship is restored without deleting provenance or listing history.

### AC-8: Apply provider lifecycle state (FR-9)
Given a previously published imported listing and a source withdrawal
When the delta synchronization processes the withdrawal
Then the listing follows the configured withdrawal policy and its public search projection is removed.

### AC-9: Operate integrations in the web app (FR-10, NFR-A1, NFR-A2)
Given an authorized, denied, or feature-disabled user on desktop or mobile
When they open the integrations workspace
Then permitted controls reflect API state, denied/disabled states are explicit, keyboard operation works, and the body does not overflow horizontally.

## Edge Cases

- EC-1: A provider returns the same external ID with a changed payload hash; append provenance and update only mapped mutable fields.
- EC-2: A delta token expires; mark the run partial and require an explicit bounded full resync.
- EC-3: A token refresh fails; stop the run with a redacted authentication code and retain the previous cursor.
- EC-4: A provider returns an unapproved redirect host; reject the request before following it.
- EC-5: Two workers claim one connection; the singleton lock permits only one active run.
- EC-6: Mapping changes during a run; the run remains pinned to its starting mapping version.
- EC-7: Photo display rights are absent; preserve metadata but do not ingest or publish the photo.
- EC-8: A source record belongs to another agency connection; return 404 before disclosure.
- EC-9: A reviewed merge targets a deleted canonical property; reject with a conflict and preserve the candidate.

## API Contracts

POST /api/v1/integrations/connections creates a tenant connection. POST /api/v1/integrations/connections/{connection}/syncs starts an idempotent queued sync.

| Method | Path | Access | Result |
| --- | --- | --- | --- |
| GET/POST | /api/v1/integrations/connections | integration.configure | connection list/create |
| GET/PATCH/DELETE | /api/v1/integrations/connections/{connection} | integration.configure | inspect/version/disable |
| GET/POST | /api/v1/integrations/connections/{connection}/mappings | integration.configure | mapping versions |
| GET/POST | /api/v1/integrations/connections/{connection}/syncs | integration.configure | run list/start |
| GET | /api/v1/integrations/syncs/{sync} | integration.configure | run counters/errors |
| GET | /api/v1/integrations/import-errors | integration.configure | redacted error queue |
| GET/PATCH | /api/v1/integrations/duplicate-candidates[/{candidate}] | integration.configure | list/review/reverse |

Connection responses contain id, provider, name, enabled, approved origins, secret reference name, resource versions, mapping version, last sync state, version, and timestamps. They never contain a credential value.

## Data Models

| Entity | Key fields and constraints |
| --- | --- |
| real_estate_data_providers | stable key, adapter, protocol, active state, capability metadata |
| provider_connections | agency, provider, name, approved base/token origins, secret reference, resource versions, enabled, version; unique agency/name |
| field_mappings | connection, resource, version, mapping JSON, actor, active timestamp; immutable unique connection/resource/version |
| sync_jobs | connection, mode, status, idempotency key/hash, start cursor, end cursor, counters, failure code, timestamps |
| data_source_records | connection, resource, external ID, payload hash, mapping version, rights snapshot, provider times, outcome; unique connection/resource/external ID/payload hash |
| import_errors | sync, source record, field, stable code, retryable state, redacted detail, timestamps |
| duplicate_candidates | agency, left/right property/source IDs, score, reasons, status, decision actor, reversible merge snapshot, version |

## Out of Scope

- OS-1: Purchasing an MLS license, supplying credentials, or granting contractual display/photo rights.
- OS-2: Provider-specific Add/Edit writeback, syndication destinations, RETS, and non-RESO proprietary schemas.
- OS-3: Automatic uncertain merges or deletion of source provenance.
- OS-4: Importing executable documents, unscanned media, private remarks, showing instructions, or other non-public fields.

