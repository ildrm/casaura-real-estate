# Spec: Phase 10 Monetization

**Author:** Casaura Engineering
**Date:** 2026-08-23
**Status:** Approved
**Reviewers:** Product owner, approved Stripe Billing/Checkout, United States, USD, and Stripe tax on 2026-08-23
**Related specs:** Entitlement hardening, Phase 6 administration, Phase 8 advanced marketplace, ADR-001 launch profile

## Context

Casaura already stores plans, entitlements, one subscription per agency, promotional periods, feature flags, billing.manage permission, audit evidence, and fail-closed entitlement resolution. It does not yet create customers or checkout sessions with a processor, consume signed billing events, project invoices, expose a billing workspace, or manage clearly labelled sponsored inventory.

Phase 10 adds a provider-neutral billing port plus a Stripe Billing/Checkout production adapter for a US/USD launch profile with Stripe tax. Card data remains entirely on Stripe-hosted surfaces. Deterministic adapters and signed fixture events cover tests. Merchant onboarding, tax registration, production webhook endpoints, and legal approval remain external activation gates.

## Functional Requirements

- FR-1: The billing boundary MUST expose a provider-neutral interface with deterministic and Stripe adapters selected by configuration.
- FR-2: Members with billing.manage MUST be able to inspect active public plans, current subscription state, invoice history, and promotion eligibility for their agency.
- FR-3: Authorized owners MUST be able to create an idempotent Stripe-hosted Checkout session for an active USD plan using automatic tax and approved success/cancel origins.
- FR-4: Authorized owners MUST be able to create a short-lived Stripe-hosted billing portal session for payment-method, invoice, cancellation, and subscription management.
- FR-5: The webhook endpoint MUST verify Stripe signatures against the raw body, reject stale/invalid signatures, and process each provider event at most once.
- FR-6: Supported customer, checkout, subscription, invoice, payment, dispute, and refund events MUST project into local billing records and subscription billing status.
- FR-7: Entitlements MUST remain fail-closed for incomplete, past_due, unpaid, paused, canceled, or expired paid subscriptions according to the documented billing-state policy.
- FR-8: Invoice projections MUST expose amount, currency, tax, status, period, number, and Stripe-hosted invoice/PDF URLs only to billing.manage members in the owning agency.
- FR-9: Platform administrators MUST be able to create, version, activate, pause, and end promotion policies with placement, date, plan eligibility, label, and inventory caps.
- FR-10: Eligible agencies MUST be able to create bounded sponsored campaigns for their own currently published listings without changing organic rank.
- FR-11: Public sponsored placements MUST be clearly labelled, separately selected from organic results, frequency capped, date bounded, and excluded from organic recommendation and market-analytics calculations.
- FR-12: The web app MUST provide responsive agency billing, invoice, checkout/portal handoff, promotion campaign, and platform promotion-policy flows with loading, empty, disabled, redirect, payment-state, and error states.

## Non-Functional Requirements

- NFR-S1: Casaura MUST NOT collect, proxy, log, or store primary account numbers, CVCs, payment method details, Stripe secret keys, or webhook secrets.
- NFR-S2: Checkout/portal return URLs MUST be derived from configured approved origins and MUST not accept arbitrary client redirect URLs.
- NFR-S3: Billing APIs MUST enforce tenant ownership, billing.manage, verified identity, and privileged MFA before object lookup or provider calls.
- NFR-R1: Checkout creation MUST use an idempotency key bound to agency, plan, actor, and payload hash.
- NFR-R2: Webhook receipt, event idempotency, invoice/subscription projection, entitlement transition, and audit evidence MUST commit atomically.
- NFR-P1: Webhook acknowledgement SHOULD complete within two seconds after durable receipt; slow reconciliation MAY continue in a queue.
- NFR-O1: Billing logs MUST include request/release IDs, provider event ID/type, agency, transition, and stable error code without secrets or payment details.
- NFR-A1: Billing and promotion controls MUST be keyboard accessible with visible focus and status announcements.
- NFR-A2: Desktop and 390-pixel layouts MUST have no page-level horizontal overflow.
- NFR-C1: All monetary values MUST use integer minor units and ISO 4217 USD; Stripe tax amounts MUST remain distinct from subtotal and total.

## Acceptance Criteria

### AC-1: Use one billing contract (FR-1, NFR-S1)
Given deterministic and Stripe billing configurations
When plan, checkout, portal, or event operations run
Then both adapters use canonical envelopes and no secret or card data enters application persistence.

### AC-2: Inspect tenant billing safely (FR-2, FR-8, NFR-S3)
Given two agencies with subscriptions and invoices
When each billing owner requests the workspace
Then only its own plan, subscription, invoices, and promotion eligibility are returned and guessed cross-tenant IDs return 404.

### AC-3: Create idempotent hosted checkout (FR-3, NFR-S2, NFR-R1, NFR-C1)
Given a verified MFA-upgraded owner and an active USD plan
When checkout is requested twice with the same key and payload
Then one Stripe Checkout session is created with automatic tax and approved return origins.

### AC-4: Open a hosted billing portal (FR-4, NFR-S2, NFR-S3)
Given an agency with a Stripe customer
When its verified MFA-upgraded owner requests the portal
Then a short-lived provider URL is returned and another agency cannot reuse the customer identity.

### AC-5: Verify and deduplicate webhooks (FR-5, NFR-R2, NFR-P1)
Given valid, invalid, stale, and replayed Stripe event fixtures
When they reach the webhook endpoint
Then only the valid fresh event is durably processed once and invalid signatures cause no billing mutation.

### AC-6: Project billing lifecycle (FR-6, FR-7, FR-8, NFR-R2)
Given checkout, subscription, invoice, payment, dispute, and refund events
When they are processed in or out of order
Then monotonic provider timestamps produce one current subscription/invoice projection and entitlement eligibility follows the documented fail-closed state.

### AC-7: Version promotion policies (FR-9)
Given a platform administrator and an active policy
When the administrator creates a replacement, pauses it, and ends it
Then immutable versions and audit evidence preserve every effective interval and stale updates return 409.

### AC-8: Sponsor only eligible owned listings (FR-10, NFR-S3)
Given an eligible paid agency and one published owned listing
When its billing owner creates a bounded campaign
Then the campaign is date/placement/cap constrained; draft, foreign, or ineligible listings are rejected.

### AC-9: Keep promotion separate and labelled (FR-11)
Given eligible campaigns and organic search/recommendation/analytics results
When public inventory is rendered
Then sponsored cards carry the configured label and disclosure, frequency caps apply, and organic ordering and aggregates remain unchanged.

### AC-10: Complete monetization web flows (FR-12, NFR-A1, NFR-A2)
Given authorized, denied, disabled, redirect-return, past-due, and active states on desktop or mobile
When users operate billing and promotion surfaces
Then the interface reflects canonical provider state, focus/status handling works, and the body has no horizontal overflow.

## Edge Cases

- EC-1: An idempotency key is reused with another plan; return 409 and do not call Stripe.
- EC-2: A webhook references an unknown customer; retain a redacted unresolved event for reconciliation without creating an agency.
- EC-3: Events arrive out of order; older provider timestamps cannot regress a newer projection.
- EC-4: Stripe is unavailable during checkout or portal creation; return a retryable safe error and persist no active session URL.
- EC-5: A hosted invoice URL is absent; return null rather than constructing a URL.
- EC-6: Currency is not USD; reject before provider invocation.
- EC-7: A sponsored listing becomes withdrawn; pause its campaign and remove it from placement.
- EC-8: Promotion inventory is exhausted; return organic results without an empty sponsored placeholder.
- EC-9: A dispute or unpaid state arrives after a successful invoice; update billing status and fail closed without deleting historical invoices.
- EC-10: A webhook secret is missing in production; the production guard fails startup.

## API Contracts

POST /api/v1/billing/checkout-sessions creates a Stripe-hosted checkout URL. POST /api/v1/webhooks/stripe is public only for signature-verified raw provider events and does not use session authentication.

| Method | Path | Access | Result |
| --- | --- | --- | --- |
| GET | /api/v1/billing | billing.manage | plans/subscription/invoices/eligibility |
| POST | /api/v1/billing/checkout-sessions | billing.manage, MFA | hosted checkout session |
| POST | /api/v1/billing/portal-sessions | billing.manage, MFA | hosted portal session |
| POST | /api/v1/webhooks/stripe | signed provider | durable event receipt |
| GET/POST/PATCH | /api/v1/admin/promotion-policies[/{policy}] | platform.settings | versioned policies |
| GET/POST/PATCH | /api/v1/billing/promotion-campaigns[/{campaign}] | billing.manage | tenant campaigns |
| GET | /api/v1/public/sponsored-listings | public, flagged | labelled placement inventory |

## Data Models

| Entity | Key fields and constraints |
| --- | --- |
| billing_customers | agency, provider, provider customer ID, version; unique agency/provider and provider/customer |
| billing_checkout_sessions | agency, plan, actor, idempotency key/hash, provider session ID, status, expiry; unique agency/key |
| billing_events | provider event ID/type/created time, payload hash, agency resolution, status/failure, processed time; unique provider/event ID |
| invoices | agency, subscription, provider invoice ID, number, status, subtotal/tax/total minor, currency, period, hosted/PDF URLs, provider updated time |
| subscriptions extension | provider IDs, provider status/update time, cancellation and grace timestamps; no payment method data |
| promotion_policies | immutable family/version, placement, label/disclosure, plan eligibility, interval, caps, status, actor |
| promotion_campaigns | agency, listing, policy version, placement, interval, impression cap/count, status, version |
| promotion_impressions | campaign, anonymous bounded dedupe key, placement, occurred time; retained under analytics policy |

## Out of Scope

- OS-1: Card collection, custom payment forms, storing payment methods, payouts, marketplace commissions, escrow, Stripe Connect, or seller onboarding.
- OS-2: Non-USD checkout, currency conversion, manual tax advice, tax registration, chargeback representation, or accounting-ledger replacement.
- OS-3: Production merchant activation, pricing approval, tax nexus configuration, webhook/DNS provisioning, refund policy approval, or legal sign-off.
- OS-4: Pay-to-rank organic search, undisclosed native advertising, personalized sensitive-category targeting, or sponsored influence on recommendations/analytics.
