# Architecture deliverables

The living architecture package is split into documents that evolve with each verified milestone:

- [assessment.md](assessment.md) — architecture assessment, assumptions, repository structure, system context, domain boundaries, and critical flows.
- [data-model.md](data-model.md) — ER diagram, table catalogue, indexes, tenant model, retention, provenance, and RESO strategy.
- [api.md](api.md) — versioning, conventions, endpoint map, authentication, errors, and pagination.
- [product-map.md](product-map.md) — navigation/site map, role-permission matrix, and feature-flag matrix.
- [milestones.md](milestones.md) — vertical-slice plan and live completion checklist.
- [../design/design-system.md](../design/design-system.md) — visual language and responsive rules extracted from the accepted concepts.
- [../design/fidelity-ledger.md](../design/fidelity-ledger.md) — concept comparison, responsive acceptance results, and intentional deviations.
- [../specs/phase-2-listing-core.md](../specs/phase-2-listing-core.md) — approved Phase 2 listing, workflow, media, and responsive acceptance contract.
- [../specs/phase-3-consumer-marketplace.md](../specs/phase-3-consumer-marketplace.md) — approved Phase 3 public search, property detail, privacy, engagement, and responsive acceptance contract.
- [../specs/phase-4-leads-collaboration.md](../specs/phase-4-leads-collaboration.md) — approved Phase 4 lead routing, CRM, viewings, messaging, reminders, notifications, calendar, and response analytics contract.
- [../specs/phase-5-agency-product.md](../specs/phase-5-agency-product.md) — approved Phase 5 storefront, team, opening-hours, newsletter, and agency analytics contract.
- [../specs/phase-6-administration.md](../specs/phase-6-administration.md) — approved Phase 6 moderation, settings, RBAC, flags, health, audit, and responsive operations contract.

The machine-readable API contract through Phase 6 lives at [packages/contracts/openapi.yaml](../../packages/contracts/openapi.yaml).
