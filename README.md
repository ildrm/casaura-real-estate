# Casaura

Casaura is an API-first, multi-tenant real-estate marketplace and agency workspace. This repository contains the verified foundation through Phase 6: identity and tenancy, listing workflow and media, consumer search and engagement, lead collaboration, agency growth, and platform administration.

## Repository

```text
apps/
  api/                 Laravel 13 JSON API and domain modules
  web/                 Next.js 16 marketplace and agency workspace
docs/
  architecture/        Product, system, data, API, and delivery decisions
  design/              Accepted visual concepts and extracted design system
infra/docker/          Local service configuration
packages/contracts/    OpenAPI and future generated API types
```

## Quick start

Prerequisites: Docker, Node.js 22+, npm 11+, PHP 8.3+, and Composer 2.

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

Open `http://localhost:3000`. The API health endpoint is `http://localhost:8000/api/v1/health`.

## Quality commands

```bash
cd apps/web && npm run lint && npm run typecheck && npm run build
cd apps/web && npm run test:e2e
cd apps/api && composer test && ./vendor/bin/pint --test
```

Architecture and product decisions are indexed in [docs/architecture/README.md](docs/architecture/README.md). The live implementation checklist is in [docs/architecture/milestones.md](docs/architecture/milestones.md).
The accepted concepts and implementation comparison are in [docs/design/fidelity-ledger.md](docs/design/fidelity-ledger.md).
