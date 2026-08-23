# RESO, OpenAI, and Stripe production activation

Owner: release engineering with product, security/privacy, legal, operations, and
finance approvers. This runbook turns the completed provider adapters into live
services. It does not authorize procurement, data use, model processing, tax treatment,
or a production launch by itself.

## Activation rule

Keep `mls`, `ai_search`, `ai_listing_writer`, `payments`, and
`sponsored_listings` in `FEATURE_FORCE_OFF` until the relevant section below has a
named owner, dated evidence, rollback decision, and approval in the release record.
Deterministic adapters are for local/test use only. `ProductionEnvironmentGuard` must
pass with the live adapters before any traffic is admitted.

Never paste provider secrets into tickets, logs, source control, screenshots, or this
runbook. Inject OpenAI/Stripe values from the environment's secret manager. Mount RESO
client secrets as read-only files below `RESO_SECRET_DIRECTORY` and store only the
filename in each connection's `secret_reference`.

## Common preflight

- [ ] Release image digests, SBOM/provenance, vulnerability scan, and signatures are recorded.
- [ ] Staging uses production topology, HTTPS origins, PostgreSQL/PostGIS, authenticated Redis, private object storage, ClamAV, the delivery mailer, and `default,integrations` queue workers.
- [ ] `php artisan config:cache` and application boot complete without a production-guard violation.
- [ ] Liveness/readiness, worker/scheduler heartbeats, request/release IDs, paging, rollback, backup/restore, privacy, and incident procedures have dated evidence.
- [ ] Provider dashboards alert the named on-call owner without including request bodies or secrets.

## RESO Web API/OData

Configuration contract:

- `RESO_APPROVED_ORIGINS`: comma-separated licensed provider hostnames, without scheme or path.
- `RESO_SECRET_DIRECTORY`: absolute readable path to the read-only secret mount.
- `RESO_PAGE_SIZE`: no more than 500; production baseline is 200.
- `RESO_TIMEOUT_SECONDS`: positive and no more than the release-approved request timeout.
- `RESO_RAW_PAYLOAD_RETENTION_DAYS`: no longer than the provider contract permits.
- Connection fields: HTTPS API/token URLs, OAuth client ID, named secret reference,
  Property resource, Data Dictionary version, and approved attribution/photo/retention rights snapshot.

Acceptance evidence:

- [ ] Legal/data owner records the licensed resource set and display, attribution, photo, refresh, deletion, and retention obligations.
- [ ] DNS/TLS and redirect behavior are reviewed; only the approved HTTPS origins are reachable by the adapter.
- [ ] Live `$metadata` discovery returns OData 4.0/4.01 resources and bounded fields; the intended Property fields map to the canonical schema.
- [ ] A staged full sync reconciles fetched/imported/skipped/failed counts against the source and quarantines invalid USD/unit/type/address fixtures.
- [ ] An incremental sync resumes the last committed modification cursor, follows bounded pagination, and an exact replay creates no duplicate canonical record.
- [ ] Provider withdrawals remove the public search projection; transient failure leaves the prior cursor committed and retryable.
- [ ] An uncertain address match enters duplicate review; link/merge, reject, and reverse operations preserve the source record and audit evidence.
- [ ] Product/legal approve the visible attribution and media-rights behavior before removing `mls` from `FEATURE_FORCE_OFF` for an entitled pilot agency.

Rollback: force `mls` off, disable the connection, drain/stop the `integrations` queue,
preserve source/sync evidence, and withdraw affected public projections only according to
the provider contract. Do not delete ingestion evidence during an incident.

## OpenAI Responses API

Configuration contract:

- `AI_DRIVER=openai`
- `OPENAI_BASE_URL=https://api.openai.com`
- `OPENAI_API_KEY`: secret-manager value for a least-privilege production project.
- `OPENAI_MODEL`: release-approved model with structured Responses support.
- `AI_TIMEOUT_SECONDS`: 1–15; `AI_MAX_OUTPUT_TOKENS`: bounded to the tested budget.
- `AI_CONTENT_RETENTION_DAYS`: approved retention period; requests use `store: false`.

Acceptance evidence:

- [ ] Security/privacy/legal approve the data-processing purpose, subprocessor record, regional/data-retention position, user notice, deletion handling, and prohibited-use policy.
- [ ] Project access, service identity, spend cap, rate limits, budget alerts, and key rotation owner are recorded.
- [ ] Staging passes schema-valid search, comparison, and listing-copy fixtures with citations bound only to current public projections.
- [ ] Email, phone, and street-address fixtures are absent from provider requests and persisted output; administrative safety events remain redacted.
- [ ] Malformed output and provider failure retry once; search/comparison fall back deterministically and listing-copy generation fails without mutating the listing.
- [ ] Legal/tax/mortgage/investment and discriminatory/off-market requests produce the approved refusal/limits behavior.
- [ ] Proposed filters are never applied without user confirmation; listing suggestions require a human-selected apply against an unchanged source version.
- [ ] Latency, error, token, and cost budgets pass at anticipated load before removing `ai_search` or `ai_listing_writer` from `FEATURE_FORCE_OFF`.

Rollback: force both AI flags off, revoke/rotate the project key if compromise is
suspected, preserve redacted audit evidence, and continue core search/comparison without
calling the provider.

## Stripe Billing, Checkout, and automatic tax

Approved commercial profile: United States, USD, Stripe-hosted Checkout/Portal, Stripe
automatic tax. Tax/legal/finance approval is still required for the operator's actual
nexus, registrations, products, prices, invoices, refunds, and disputes.

Configuration contract:

- `BILLING_DRIVER=stripe`
- `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`: live restricted secret-manager values.
- `STRIPE_API_URL=https://api.stripe.com`
- `STRIPE_API_VERSION`: the tested pinned version; change only through a release.
- `STRIPE_PROFESSIONAL_PRICE_ID`: active recurring USD `price_...` identifier.
- HTTPS `BILLING_CHECKOUT_SUCCESS_URL`, `BILLING_CHECKOUT_CANCEL_URL`, and
  `BILLING_PORTAL_RETURN_URL` below the exact `FRONTEND_URL` origin.

Acceptance evidence:

- [ ] Finance/legal complete merchant, bank, identity, tax-registration, invoice, refund, dispute, and customer-support setup.
- [ ] Live product/price, customer portal policy, automatic-tax settings, branding, statement descriptor, email receipts, and allowed payment methods are approved.
- [ ] The production webhook targets `/api/v1/webhooks/stripe`, includes the required customer/checkout/subscription/invoice/payment/refund/dispute events, and uses the mounted signing secret.
- [ ] Test-to-live rehearsal covers Checkout success/cancel/expiry, Portal return, trial/paid/past-due/cancel states, invoices and safe hosted links, refund, dispute, and recovery.
- [ ] Invalid, stale, duplicate, out-of-order, unknown-customer, and replayed webhooks fail closed or remain unresolved without regressing newer state; stored evidence contains a payload hash and allowlisted summary, never the raw event.
- [ ] Entitlements reconcile with paid/trialing state, and finance reconciles provider totals/tax/currency/invoices to Casaura projections.
- [ ] Promotion policy versions, plan eligibility, disclosure, schedule, caps, visitor dedupe, unavailable-listing pause, and separate sponsored/organic queries pass product/legal review.
- [ ] Remove `payments` and `sponsored_listings` from `FEATURE_FORCE_OFF` only after the webhook replay window, paging, and finance reconciliation have been observed in staging.

Rollback: force both monetization flags off, preserve webhook/invoice evidence, disable
new Checkout creation, and use Stripe's hosted controls for existing subscriptions. Do
not alter historical promotion policies or billing events.

## Final release record

The launch remains **no-go** until the release record links all evidence above and names
the product, engineering, security, legal/privacy, finance, and operations approvers.
After approval, remove only the specifically accepted keys from `FEATURE_FORCE_OFF`,
deploy the same signed digest, run one tenant pilot, observe provider/queue/billing health,
and then expand through audited agency overrides or entitled plans.
