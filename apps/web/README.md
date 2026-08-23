# Casaura web

Next.js 16 App Router application for the public marketplace, consumer account, agency
workspace, and platform administration. It uses only the versioned Casaura API; demo
data is rejected in production.

Phase 7–10 routes include `/agency/integrations`, `/collections`, `/compare`, `/market`,
`/assistant`, `/agency/properties/{listing}/assistant`, `/agency/billing`, and
`/admin/release-controls`. Provider-backed actions expose honest unavailable/error states
until their corresponding server-side feature and live credential gates are enabled.

## Local setup

```bash
cp .env.example .env.local
npm run dev
```

Run the quality gates with:

```bash
npm run lint
npm run typecheck
npm run build
npm run test:e2e
```

The Playwright suite expects the web app at `PLAYWRIGHT_BASE_URL` and the API at
`PLAYWRIGHT_API_URL`. CI creates an isolated SQLite database, starts both services,
and runs desktop/mobile journeys. Its signed verification-link helper refuses to run
outside `local` or `testing`; production verification links are delivered only by the
configured mail provider.

A production build must provide explicit HTTPS API/site origins, locale, currency,
area unit, country, approved legal version, operator identity/jurisdiction/address,
and support email. The build fails on missing/placeholding production values. Public
variables are baked into the immutable web image, so each public origin has its own
promoted artifact.

The production container is built from [infra/docker/web/Dockerfile](../../infra/docker/web/Dockerfile).
