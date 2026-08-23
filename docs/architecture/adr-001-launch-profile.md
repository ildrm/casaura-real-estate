# ADR-001: Narrow launch profile and approval boundary

Status: proposed; external approval required before production launch.

## Decision

The first release is agency-first. Public visitors may search published inventory,
view opted-in storefront/team data, submit consented inquiries, and subscribe only
where newsletters are enabled. Agency owners and invited staff use verified accounts;
owners and platform operators require MFA. Consumer self-registration, newsletters,
external data integrations, advanced recommendations, AI, and billing remain disabled
unless their feature, provider, compliance, and operational gates are separately met.

Production builds require explicit country, locale, currency, area unit, legal version,
operator identity/jurisdiction/address, support email, API origin, and site origin. The
API refuses insecure production infrastructure, delivery, storage, scanner, cookie,
CORS, logging, or secret defaults.

## Rationale

This profile preserves the fully implemented agency/listing/marketplace/lead value
chain while avoiding unsupported claims about consumer lifecycle, provider delivery,
billing, AI, or a jurisdiction that the business has not selected. It also gives every
external assumption a deploy-time owner rather than hiding it in source defaults.

## Approval boundary

Business and counsel must replace the proposed status with accepted evidence for the
launch jurisdiction and versioned legal documents. Operations must attach hosting,
backup/restore, alerting, load, and incident-drill evidence. Until then, the software
is an engineering release candidate, not an authorized production service.
