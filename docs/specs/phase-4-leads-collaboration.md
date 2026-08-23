# Spec: Phase 4 Leads and Collaboration

**Author:** Casaura Engineering  
**Date:** 2026-08-19  
**Status:** Approved  
**Reviewers:** Product owner, via the directive to complete roadmap phases 4–6  
**Related specs:** [Phase 3 consumer marketplace](phase-3-consumer-marketplace.md), [data model](../architecture/data-model.md), [API conventions](../architecture/api.md)

## Context

Phase 3 lets a consumer discover and save a published property, but the property page cannot hand interest to the responsible agency. Agency dashboard lead, viewing, and message panels are placeholders. Phase 4 closes that gap with a tenant-owned CRM handoff, an auditable pipeline, timezone-aware viewing coordination, persistent conversations, reminders, notifications, and response-time reporting.

External email/SMS/push, websocket, and calendar vendors are not selected. The slice therefore defines replaceable notification and calendar ports, persists in-app notifications, exports standards-compliant iCalendar events, and exposes cursor/polling-safe message APIs. Those adapters are complete local behavior and the integration boundary for a later deployment choice.

## Functional Requirements

- FR-1: A visitor MUST be able to submit an idempotent inquiry for a published listing with validated contact details and consent.
- FR-2: Inquiry creation MUST atomically create an agency-owned lead, initial status history, conversation, first message, notification, and audit record.
- FR-3: Agency members with `lead.manage` MUST be able to list, filter, read, assign, prioritize, and move only their tenant's leads through `new`, `contacted`, `qualified`, `viewing`, `won`, and `lost`.
- FR-4: Every lead status or assignment change MUST append immutable status history and audit evidence.
- FR-5: An authenticated consumer and authorized agency member MUST be able to list their own conversations and send plain-text messages.
- FR-6: Message listing MUST support stable cursor polling and MUST expose no participant outside the conversation.
- FR-7: Authorized agency members MUST be able to create and update timezone-aware viewing requests linked to an owned lead and listing.
- FR-8: Consumers MUST be able to see their own viewing requests and messages, while agency members see only the active tenant's records.
- FR-9: Confirmed viewings MUST be exportable through a replaceable calendar adapter as an RFC 5545-compatible iCalendar response.
- FR-10: Authorized members MUST be able to create, complete, and cancel lead/viewing reminders assigned within the active agency.
- FR-11: Due reminders, new inquiries, messages, and viewing changes MUST create in-app notifications through a replaceable notification dispatcher.
- FR-12: Users MUST be able to list and mark only their own notifications as read.
- FR-13: Agency response analytics MUST report lead count, first-response coverage, and average first-response seconds from canonical timestamps without invented values.
- FR-14: The agency web workspace MUST provide responsive lead pipeline, viewing, conversation, reminder, notification, and response analytics states backed by the API.
- FR-15: The public detail and account web experiences MUST provide accessible contact, viewing, and messaging handoffs.

## Non-Functional Requirements

- NFR-P1: Tenant lead/message/viewing lists MUST use stable cursor ordering and cap pages at 50.
- NFR-S1: Public inquiry writes MUST require `Idempotency-Key`, a dedicated rate limiter, explicit consent, and a published listing.
- NFR-S2: Tenant object lookup MUST scope by active agency before returning existence or authorization details.
- NFR-S3: Conversation and notification access MUST be participant/user scoped; message content MUST be treated as plain text.
- NFR-R1: Inquiry creation and each workflow transition MUST commit its canonical state, history, notification, and audit writes atomically.
- NFR-R2: Replayed inquiry keys MUST return the original lead without duplicating messages or notifications.
- NFR-A1: Pipeline controls, forms, tabs, notifications, and message composer MUST be keyboard operable with visible labels and status announcements.
- NFR-A2: Desktop and 390-pixel layouts MUST have no page-level horizontal overflow.
- NFR-O1: Failures MUST use stable error codes and request IDs without logging private message/contact content.

## Acceptance Criteria

### AC-1: Capture an idempotent inquiry (FR-1, FR-2, NFR-S1, NFR-R2)
Given a published listing and valid contact details with consent
When the visitor submits the same inquiry twice with one idempotency key
Then both responses identify one lead and the database contains one initial history, conversation, message, notification, and audit event.

### AC-2: Reject unavailable inventory (FR-1, NFR-S1)
Given a draft, withdrawn, deleted, or unknown listing
When a visitor submits an inquiry
Then the API returns 404 without creating collaboration state or revealing private listing facts.

### AC-3: Isolate the lead pipeline (FR-3, NFR-S2)
Given agencies A and B own leads
When a member of agency A lists, reads, assigns, or updates a lead using agency B's UUID
Then agency B's lead is absent or 404 and remains unchanged.

### AC-4: Preserve pipeline history (FR-3, FR-4, NFR-R1)
Given a new lead
When an authorized member assigns it and moves it to contacted
Then current assignment/status, immutable history, first-response time, and audit evidence are saved atomically.

### AC-5: Exchange participant-scoped messages (FR-5, FR-6, NFR-S3)
Given a lead conversation
When its consumer or an authorized member sends a message and polls after the previous cursor
Then the new plain-text message appears once and a non-participant receives 404.

### AC-6: Schedule a viewing (FR-7, FR-8, NFR-R1)
Given a qualified owned lead and future zoned start/end times
When an authorized member creates and confirms a viewing
Then the agency and consumer projections agree and status history, notifications, lead stage, and audit evidence are consistent.

### AC-7: Reject invalid schedules (FR-7)
Given an end before the start, an invalid timezone, or a past start
When a viewing is created or updated
Then the API returns 422 and no viewing or notification is written.

### AC-8: Export a confirmed viewing (FR-9)
Given a confirmed participant-owned viewing
When its calendar endpoint is requested
Then the response is `text/calendar`, uses UTC event boundaries, and contains no private data beyond the authorized event projection.

### AC-9: Deliver and complete reminders (FR-10, FR-11, FR-12)
Given a due pending reminder assigned to a member
When due reminders are dispatched and the member reads/completes it
Then exactly one notification exists, only that user can read it, and the reminder becomes complete.

### AC-10: Report honest response analytics (FR-13)
Given leads with and without a first response
When an authorized analyst requests collaboration analytics
Then totals, responded count/rate, and average response seconds are derived from stored timestamps and missing data is represented as null or zero.

### AC-11: Operate the agency collaboration workspace (FR-14, NFR-A1, NFR-A2)
Given an authenticated agency member
When they use leads, viewings, messages, reminders, and notifications on desktop or 390-pixel mobile
Then API data and actions stay synchronized, loading/error/empty states are clear, and the body has no horizontal overflow.

### AC-12: Complete the consumer handoff (FR-15, NFR-A1)
Given a visitor on a published property and an authenticated consumer account
When they contact the agency and later open account collaboration
Then success is announced, the inquiry is not duplicated, and their messages/viewings are readable and operable by keyboard.

## Edge Cases

- EC-1: Missing/reused idempotency key with a different listing or payload returns 409 `IDEMPOTENCY_CONFLICT`.
- EC-2: Invalid email, phone, consent, or message length returns field-level 422 errors.
- EC-3: A stale lead version returns 409 `LEAD_VERSION_CONFLICT` with the current version.
- EC-4: Assignment to a member outside the lead agency returns 422 `LEAD_ASSIGNEE_INVALID`.
- EC-5: A non-participant conversation UUID returns 404.
- EC-6: Empty or oversized message content returns 422 without notifications.
- EC-7: Viewing overlap is allowed but returned as a machine-readable warning; it is not silently rejected.
- EC-8: Calendar export for an unconfirmed/cancelled viewing returns 409 `VIEWING_NOT_EXPORTABLE`.
- EC-9: Re-running reminder dispatch is idempotent and does not duplicate notifications.

## API Contracts

The primary public write is `POST /api/v1/public/listings/{listing}/leads`; participant and tenant endpoints follow the table below.

```ts
type LeadStatus = "new" | "contacted" | "qualified" | "viewing" | "won" | "lost";
type ViewingStatus = "requested" | "confirmed" | "completed" | "cancelled" | "no_show";

interface LeadProjection {
  id: string; listing_id: string; status: LeadStatus; priority: "low" | "normal" | "high";
  contact: { name: string; email: string; phone: string | null };
  assigned_member_id: string | null; first_responded_at: string | null; version: number;
}

interface ConversationProjection { id: string; lead_id: string; listing_id: string; messages: MessageProjection[]; }
interface MessageProjection { id: string; sender_user_id: string | null; body: string; created_at: string; }
interface ViewingProjection {
  id: string; lead_id: string; listing_id: string; assigned_member_id: string | null;
  starts_at: string; ends_at: string; timezone: string; status: ViewingStatus;
  notes: string | null; version: number;
  warnings: Array<{ code: "VIEWING_SCHEDULE_OVERLAP"; message: string; overlap_count: number }>;
}
interface CollaborationAnalytics { total_leads: number; responded_leads: number; response_rate: number; average_first_response_seconds: number | null; }
```

| Method | Path | Access | Result |
| --- | --- | --- | --- |
| POST | `/api/v1/public/listings/{listing}/leads` | public, limited | 201/200 lead receipt |
| GET/PATCH | `/api/v1/leads[/{lead}]` | `lead.manage` | tenant lead list/projection |
| GET/POST | `/api/v1/conversations/{conversation}/messages` | participant | cursor messages / 201 message |
| GET/POST/PATCH | `/api/v1/viewings[/{viewing}]` | participant or `lead.manage` | viewing projection |
| GET | `/api/v1/viewings/{viewing}/calendar` | participant | iCalendar stream |
| GET/POST/PATCH | `/api/v1/reminders[/{reminder}]` | owner / `lead.manage` | reminder projection |
| GET/PATCH | `/api/v1/notifications[/{notification}]` | user | notification list/read state |
| GET | `/api/v1/agency/analytics/collaboration` | `analytics.view` | response metrics |
| GET | `/api/v1/account/collaboration` | user | consumer messages/viewings |

Errors follow the Casaura error envelope with 401, 403, 404, 409, 422, and 429 responses.

## Data Models

| Entity | Key fields and constraints |
| --- | --- |
| `leads` | UUID, agency/listing/consumer/assignee, idempotency key/hash, contact fields, status, priority, version, response timestamps; unique agency/idempotency key |
| `lead_status_history` | lead, from/to status, from/to assignee, actor, note, timestamp; append-only |
| `viewing_requests` | agency, lead, listing, consumer, assignee, zoned schedule, status, notes, timestamps |
| `viewing_status_history` | viewing, from/to status, actor, note, timestamp; append-only |
| `conversations` | agency, lead, listing, subject, last-message timestamp |
| `conversation_participants` | conversation, user, role; unique pair |
| `messages` | conversation, sender user, plain-text body, timestamp; cursor index |
| `reminders` | agency, assigned user, optional lead/viewing, title, due/status, dispatched timestamp |
| `notifications` | user, optional agency, type, title/body/data, read timestamp, deduplication key |

## Out of Scope

- OS-1: Vendor email, SMS, push, websocket, and two-way calendar synchronization; ports and local adapters are delivered here.
- OS-2: Marketing newsletters and storefront growth flows — Phase 5.
- OS-3: Platform abuse moderation and global operations — Phase 6.
- OS-4: Automated lead scoring, AI replies, call recording, and payment/deposit collection.
