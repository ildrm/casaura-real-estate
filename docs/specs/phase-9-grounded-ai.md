# Spec: Phase 9 Grounded AI

**Author:** Casaura Engineering
**Date:** 2026-08-23
**Status:** Approved
**Reviewers:** Product owner, approved OpenAI Responses API plus provider-neutral and deterministic adapters on 2026-08-23
**Related specs:** Phase 3 consumer marketplace, Phase 8 advanced marketplace, privacy lifecycle, ADR-001 launch profile

## Context

Casaura has canonical published search documents, comparisons, listing drafts, tenant permissions, privacy retention, feature flags, request correlation, and safe public serializers. Phase 9 adds grounded assistants without allowing a model to become a source of property truth or an autonomous publisher.

The AI boundary has a deterministic local adapter for tests and safe degraded operation plus a production OpenAI Responses API adapter. Only allowlisted retrieved facts leave Casaura, direct identifiers are redacted, citations resolve to current Casaura resources, and every generated listing draft requires explicit human application.

## Functional Requirements

- FR-1: The AI boundary MUST expose a provider-neutral interface with deterministic and OpenAI Responses API adapters selected by configuration.
- FR-2: When ai_search is enabled, users MUST be able to submit conversational property criteria and receive parsed deterministic filters, grounded matching listings, citations, and assumptions.
- FR-3: Conversational search MUST display parsed filters and MUST require explicit user action before applying them to public search.
- FR-4: Users MUST be able to request a grounded comparison explanation for two through five currently published listings using only the Phase 8 comparison projection.
- FR-5: When ai_listing_writer is enabled, permitted agency members MUST be able to generate title/description suggestions from their tenant-owned canonical listing facts.
- FR-6: Generated listing copy MUST remain a suggestion, MUST carry source/provenance metadata, and MUST require an explicit version-checked apply action before modifying a draft.
- FR-7: Retrieval MUST use only current allowlisted public search data for consumer assistants and tenant-owned allowlisted listing data for the listing assistant.
- FR-8: The gateway MUST reject disallowed requests, prompt-injection attempts that request hidden instructions/private data, and unsupported legal, financial, discriminatory, or certainty claims with stable safety outcomes.
- FR-9: AI sessions, generations, citations, provider/model identifiers, token/latency totals, safety decisions, and user feedback MUST be recorded in a redacted audit model.
- FR-10: Users MUST be able to delete their AI session content, and retention enforcement MUST remove message content while preserving restricted aggregate safety evidence.
- FR-11: The web app MUST provide responsive conversational search, comparison assistant, listing assistant, citations, human-approval, unavailable, timeout, safety, and deletion states.

## Non-Functional Requirements

- NFR-S1: Provider requests MUST exclude email, phone, exact private address/coordinates, credentials, billing data, private messages, moderation evidence, and raw provider payloads.
- NFR-S2: OpenAI credentials MUST come from the secret manager and MUST never be returned, stored in generation payloads, or logged.
- NFR-S3: Assistant output MUST be treated as untrusted text, rendered without executable markup, and validated again before any apply action.
- NFR-R1: Provider timeouts or unavailable responses MUST not mutate listings and SHOULD return the deterministic grounded fallback where the request supports it.
- NFR-R2: Listing suggestion application MUST enforce tenant permission and optimistic listing version in one transaction.
- NFR-P1: Retrieval context MUST be capped at twelve listings or one tenant listing and provider output MUST be capped by configured token limits.
- NFR-P2: The gateway MUST time out external generation within fifteen seconds and expose a retryable safe error or fallback.
- NFR-O1: Logs MUST contain request/release IDs, adapter, model, latency, token totals, citation IDs, and safety code without prompt/message content.
- NFR-A1: Conversation, citations, filter confirmation, comparison, and apply controls MUST be keyboard accessible and announced.
- NFR-A2: Desktop and 390-pixel layouts MUST have no page-level horizontal overflow.

## Acceptance Criteria

### AC-1: Switch providers behind one contract (FR-1, NFR-S2)
Given deterministic and OpenAI configurations
When the same grounded request is executed through either adapter
Then both return the canonical generation envelope and neither exposes a credential.

### AC-2: Parse and ground conversational search (FR-2, FR-3, FR-7, NFR-P1)
Given ai_search is enabled and published inventory matches a natural-language request
When a user submits the request
Then the response contains bounded parsed filters, current listing citations, assumptions, and an unapplied state until the user confirms.

### AC-3: Explain a comparison from canonical facts (FR-4, FR-7)
Given a valid Phase 8 comparison
When the user requests an assistant explanation
Then every factual claim is supported by a returned listing/field citation and missing facts are identified rather than invented.

### AC-4: Generate but do not publish listing copy (FR-5, FR-6, NFR-S3)
Given a permitted member owns a draft listing
When they request title and description suggestions
Then a suggestion with canonical fact provenance is stored while the listing remains unchanged.

### AC-5: Apply a suggestion safely (FR-6, NFR-R2)
Given a pending suggestion and the listing version used to create it
When the authorized member explicitly applies selected fields
Then validation and version checks run, the draft changes once, history is appended, and the suggestion records the applying actor.

### AC-6: Enforce grounding and safety (FR-8, NFR-S1, NFR-S3)
Given a prompt asks for private data, hidden instructions, discriminatory steering, or a guaranteed investment outcome
When the gateway evaluates it
Then the request is refused or safely redirected with a stable safety code and no restricted context is sent to the provider.

### AC-7: Degrade without unsafe mutation (FR-1, NFR-R1, NFR-P2)
Given the OpenAI endpoint times out
When a supported search or comparison request runs
Then the deterministic grounded adapter returns a labelled fallback; a listing-writing request returns a retryable error and changes no listing.

### AC-8: Persist redacted operational evidence (FR-9, NFR-O1)
Given a successful or refused generation
When operators inspect logs and authorized safety records
Then adapter/model/latency/tokens/citations/safety are present and prompt content, credentials, and direct identifiers are absent from logs.

### AC-9: Delete retained conversation content (FR-10)
Given a user-owned AI session
When the user deletes it or retention expires
Then message content is removed and the user can no longer retrieve it while restricted aggregate safety evidence remains non-identifying.

### AC-10: Complete grounded AI web flows (FR-11, NFR-A1, NFR-A2)
Given enabled, disabled, timeout, safety, stale-version, and success states on desktop or mobile
When users operate each assistant
Then citations and confirmation are explicit, focus/status announcements work, and the page has no body overflow.

## Edge Cases

- EC-1: A cited listing becomes unpublished before response serialization; remove it and any unsupported claim.
- EC-2: Parsed filters contain unsupported fields; return them as ignored assumptions and never pass them to search.
- EC-3: The model emits malformed JSON; validate, retry once with the same bounded context, then fall back or fail safely.
- EC-4: Provider output contains HTML/script; render as plain text and reject unsafe apply content.
- EC-5: The user requests more than five comparison listings; reject before provider invocation.
- EC-6: Listing version changes after suggestion generation; apply returns 409 and preserves the suggestion.
- EC-7: The user includes an email or phone in a prompt; redact it before persistence/provider transfer.
- EC-8: A provider returns a citation not in the supplied context; discard the unsupported output.
- EC-9: Feature force-off activates during a session; subsequent generation requests are denied.

## API Contracts

POST /api/v1/ai/search returns a grounded generation envelope with parsed_filters, citations, assumptions, safety, and provider metadata. POST /api/v1/listings/{listing}/ai-suggestions creates a tenant-owned suggestion without applying it.

| Method | Path | Access | Result |
| --- | --- | --- | --- |
| POST | /api/v1/ai/search | public/user, flagged, limited | grounded search proposal |
| POST | /api/v1/ai/comparisons | public/user, flagged, limited | cited comparison |
| GET/DELETE | /api/v1/account/ai-sessions[/{session}] | user | private session list/delete |
| POST | /api/v1/account/ai-generations/{generation}/feedback | user | bounded feedback |
| POST | /api/v1/listings/{listing}/ai-suggestions | listing.update, flagged | suggestion |
| POST | /api/v1/listings/{listing}/ai-suggestions/{suggestion}/apply | listing.update | versioned draft mutation |
| GET | /api/v1/admin/ai-safety-events | audit.view | redacted safety events |

## Data Models

| Entity | Key fields and constraints |
| --- | --- |
| ai_sessions | optional user, purpose, status, content expiry, timestamps; private ownership |
| ai_messages | session, role, encrypted/redacted content, content hash, created timestamp |
| ai_generations | session, optional agency/listing, adapter/model, purpose, status, parsed filters/output, prompt hash, latency/tokens, safety code, expiry |
| ai_citations | generation, listing/source type, source ID, field paths, snapshot hash |
| ai_listing_suggestions | agency, listing, generation, source listing version, suggested fields, applied fields/actor/time |
| ai_safety_events | generation, non-identifying category/action/rule version, timestamp; restricted retention |

## Out of Scope

- OS-1: Autonomous publication, price changes, lead messaging, billing actions, or any tool call that mutates business state without explicit confirmation.
- OS-2: Model training/fine-tuning on user content, biometric/image inference, voice assistants, or raw MLS payload transfer.
- OS-3: Legal, financial, mortgage, appraisal, fair-housing eligibility, or guaranteed investment advice.
- OS-4: Production activation without approved OpenAI account, data-processing terms, residency/retention policy, budget, monitoring, and secret provisioning.

