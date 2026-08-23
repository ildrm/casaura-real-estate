# Architecture deliverables

Current status: Phases 1–10 and repository production hardening are implemented and verified. Live provider/infrastructure activation and organizational approvals remain external launch gates in [milestones.md](milestones.md).

The living architecture package is split into documents that evolve with each verified milestone:

- [assessment.md](assessment.md) — architecture assessment, assumptions, repository structure, system context, domain boundaries, and critical flows.
- [data-model.md](data-model.md) — ER diagram, table catalogue, indexes, tenant model, retention, provenance, and RESO strategy.
- [api.md](api.md) — versioning, conventions, endpoint map, authentication, errors, and pagination.
- [product-map.md](product-map.md) — navigation/site map, role-permission matrix, and feature-flag matrix.
- [milestones.md](milestones.md) — reconciled release-scope checklist separating completed repository work, external launch gates, and the post-launch roadmap.
- [production-release-plan.md](production-release-plan.md) — implemented hardening, verification sequence, and external launch gates.
- [adr-001-launch-profile.md](adr-001-launch-profile.md) — narrow agency-first launch profile and approval boundary.
- [../design/design-system.md](../design/design-system.md) — visual language and responsive rules extracted from the accepted concepts.
- [../design/fidelity-ledger.md](../design/fidelity-ledger.md) — concept comparison, responsive acceptance results, and intentional deviations.
- [../specs/phase-2-listing-core.md](../specs/phase-2-listing-core.md) — approved Phase 2 listing, workflow, media, and responsive acceptance contract.
- [../specs/phase-3-consumer-marketplace.md](../specs/phase-3-consumer-marketplace.md) — approved Phase 3 public search, property detail, privacy, engagement, and responsive acceptance contract.
- [../specs/phase-4-leads-collaboration.md](../specs/phase-4-leads-collaboration.md) — approved Phase 4 lead routing, CRM, viewings, messaging, reminders, notifications, calendar, and response analytics contract.
- [../specs/phase-5-agency-product.md](../specs/phase-5-agency-product.md) — approved Phase 5 storefront, team, opening-hours, newsletter, and agency analytics contract.
- [../specs/phase-6-administration.md](../specs/phase-6-administration.md) — approved Phase 6 moderation, settings, RBAC, flags, health, audit, and responsive operations contract.
- [../specs/phase-7-data-integrations.md](../specs/phase-7-data-integrations.md) — approved Phase 7 RESO connection, mapping, incremental ingestion, provenance, quarantine, and duplicate-review contract.
- [../specs/phase-8-advanced-marketplace.md](../specs/phase-8-advanced-marketplace.md) — approved Phase 8 collections, comparison, organic recommendations, privacy-thresholded maps, and market analytics contract.
- [../specs/phase-9-grounded-ai.md](../specs/phase-9-grounded-ai.md) — approved Phase 9 grounded search/comparison/listing assistance, citation, redaction, safety, and human-approval contract.
- [../specs/phase-10-monetization.md](../specs/phase-10-monetization.md) — approved Phase 10 Stripe billing, webhook, invoices, promotion-policy, sponsored-labeling, and ranking-isolation contract.
- [../specs/production-release-p0-authorization.md](../specs/production-release-p0-authorization.md) — approved principal and authority containment contract.
- [../specs/production-release-p0-entitlements.md](../specs/production-release-p0-entitlements.md) — approved centralized feature enforcement contract.
- [../specs/production-release-p1-identity.md](../specs/production-release-p1-identity.md) — approved verification, recovery, MFA, consent, and credential lifecycle contract.
- [../specs/production-release-p1-membership.md](../specs/production-release-p1-membership.md) — approved invitation and membership lifecycle contract.
- [../specs/production-release-p1-workflows.md](../specs/production-release-p1-workflows.md) — approved listing, lead, search, and runtime correctness contract.

The release-candidate machine-readable API contract lives at [packages/contracts/openapi.yaml](../../packages/contracts/openapi.yaml). Production operator procedures live under [../runbooks](../runbooks), including the [RESO/OpenAI/Stripe activation checklist](../runbooks/provider-activation.md).
