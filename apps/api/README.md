# Casaura API

Laravel 13 JSON API for Casaura's multi-tenant marketplace and agency workspace.
The API owns identity, membership/RBAC, tenant policy, listings/media, public search,
engagement, leads/collaboration, agency product, privacy, moderation, RESO ingestion,
collaborative discovery, grounded AI, Stripe billing/promotion, and operations.

## Local setup

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Tests default to isolated SQLite. CI also runs the complete suite on PostgreSQL/PostGIS.

```bash
./vendor/bin/pint --test
composer validate --strict
composer audit --locked
php artisan test
```

Production requires PostgreSQL, authenticated Redis, private S3-compatible storage,
ClamAV, a delivery mailer, secure sessions/CORS, worker and scheduler processes, and
structured stderr logging. `ProductionEnvironmentGuard` rejects unsafe production
configuration at boot, including deterministic or incomplete RESO/OpenAI/Stripe
adapters. The worker must listen to both `default` and `integrations`; provider secrets
must be mounted read-only and referenced by name. Use `/api/v1/health/live` for liveness and
`/api/v1/health/ready` for traffic readiness.

The exact API contract is [packages/contracts/openapi.yaml](../../packages/contracts/openapi.yaml).
Deployment and operator procedures are under [docs/runbooks](../../docs/runbooks), with
live provider activation in [provider-activation.md](../../docs/runbooks/provider-activation.md).
