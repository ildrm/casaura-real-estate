# Casaura

Casaura is an API-first, multi-tenant real-estate marketplace and agency workspace. This repository contains the engineering release candidate through Phase 10: core marketplace and agency operations, licensed RESO ingestion, collaborative discovery, grounded AI, Stripe billing/promotion, and production hardening. Production launch is still a no-go until the external provider activation, approvals, and environment drills in the [release plan](docs/architecture/production-release-plan.md) are complete.

## Repository

```text
apps/
  api/                 Laravel 13 JSON API and domain modules
  web/                 Next.js 16 marketplace and agency workspace
docs/
  architecture/        Product, system, data, API, and delivery decisions
  design/              Accepted visual concepts and extracted design system
infra/docker/          Production images and local service configuration
deploy/                Production process topology and environment contract
packages/contracts/    OpenAPI and future generated API types
docs/runbooks/         Deployment, restore, incident, privacy, and SLO procedures
```

## Quick start

Prerequisites: Docker, Node.js 22+, npm 10+, PHP 8.3+, and Composer 2.

```bash
cp .env.example .env
docker compose up -d postgres redis opensearch minio mailpit

cd apps/api
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve

cd ../web
cp .env.example .env.local
npm run dev
```

Open `http://localhost:3000`. API liveness is at `http://localhost:8000/api/v1/health/live`; readiness is at `/api/v1/health/ready`.

## Quality commands

```bash
npm run lint:web
npm run typecheck:web
npm run build:web
npm run test:e2e
cd apps/api && composer validate --strict && composer audit --locked
cd apps/api && ./vendor/bin/pint --test && php artisan test
./scripts/check-secrets.sh
npx --yes @redocly/cli@1.34.5 lint packages/contracts/openapi.yaml
```

Architecture and product decisions are indexed in [docs/architecture/README.md](docs/architecture/README.md). The live implementation checklist is in [docs/architecture/milestones.md](docs/architecture/milestones.md).
The accepted concepts and implementation comparison are in [docs/design/fidelity-ledger.md](docs/design/fidelity-ledger.md).
Production images, CI gates, promotion expectations, and operator runbooks are described in [deploy/README.md](deploy/README.md) and [docs/runbooks](docs/runbooks).
Live RESO, OpenAI, and Stripe activation is intentionally separate from source-code completion; follow the [provider activation runbook](docs/runbooks/provider-activation.md).
