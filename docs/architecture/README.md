# Architecture deliverables

The Phase 1 architecture package is split into documents that can evolve independently:

- [assessment.md](assessment.md) — architecture assessment, assumptions, repository structure, system context, domain boundaries, and critical flows.
- [data-model.md](data-model.md) — ER diagram, table catalogue, indexes, tenant model, retention, provenance, and RESO strategy.
- [api.md](api.md) — versioning, conventions, endpoint map, authentication, errors, and pagination.
- [product-map.md](product-map.md) — navigation/site map, role-permission matrix, and feature-flag matrix.
- [milestones.md](milestones.md) — vertical-slice plan and live completion checklist.
- [../design/design-system.md](../design/design-system.md) — visual language and responsive rules extracted from the accepted concepts.
- [../design/fidelity-ledger.md](../design/fidelity-ledger.md) — concept comparison, responsive acceptance results, and intentional deviations.

The machine-readable Phase 1 API contract lives at [packages/contracts/openapi.yaml](../../packages/contracts/openapi.yaml).
