# Information architecture, RBAC, and feature flags

## Page and navigation map

This is the implemented information architecture for the agency-first Phase 1–10
release candidate. Live provider-dependent nodes remain feature-gated until their
external activation checklists are approved.

```text
Public
├── Home
├── Discover
│   ├── Buy / Rent / Land / Commercial / New construction
│   ├── Search + map
│   ├── Property detail
│   ├── Compare
│   ├── Property assistant
│   ├── Cities / neighborhoods
│   └── Developments
├── Professionals
│   ├── Agency directory
│   ├── Agency storefront
│   └── Agent profile
├── Insights
│   ├── Market reports
│   └── Guides / blog
└── Sign in / register / legal / support

Customer account
├── Overview
├── Favorites / liked / disliked
├── Collections / comparison history
├── Saved searches / alerts
├── Viewings / messages
├── Followed agencies / newsletters
└── Profile / notifications / privacy and data controls

Agency workspace
├── Overview
├── Properties / add property / imports
├── Leads / customers / viewings / messages
├── Newsletter / analytics
├── Team / agency profile
├── RESO integrations / billing and promotion
├── Listing assistant
└── Settings

Platform administration
├── Dashboard / system health / jobs / audit
├── Agencies / customers / properties / developments
├── Moderation / comments / ratings / reports / media
├── Taxonomy / locations / search / SEO / CMS
├── Integrations / MLS-RESO / storage / maps / AI safety
├── Plans / billing / promotion policies / newsletters
└── Roles / permissions / feature flags / settings
```

Mobile uses a list/map switcher for discovery, a sticky property action bar, and role-specific bottom navigation for the highest-frequency workspace tasks. Secondary items move into an accessible drawer.

## Role-permission matrix

Legend: `A` all, `M` manage, `R` read, `O` assigned/owned only, `—` denied. Permissions are authoritative; role names are seed templates.

| Capability | Consumer | Owner | Manager | Agent | Content | Analyst | Moderator | Support | Admin | Super |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Public discovery/account | A | A | A | A | A | A | A | A | A | A |
| `agency.manage_profile` | — | M | M | R | M | R | — | R | M | A |
| `agency.manage_members` | — | M | M | — | — | — | — | R | M | A |
| `property.create` | — | M | M | O | M | — | — | R | M | A |
| `property.publish` | — | M | M | O | O* | — | — | — | M | A |
| `property.delete` | — | M | M | — | — | — | — | — | M | A |
| `listing.view` | — | M | M | M | M | — | — | R | M | A |
| `listing.create` | — | M | M | M | M | — | — | — | M | A |
| `listing.update` | — | M | M | M | M | — | — | — | M | A |
| `listing.publish` | — | M | M | — | — | — | — | — | M | A |
| `listing.delete` | — | M | M | — | — | — | — | — | M | A |
| `media.manage` | — | M | M | M | M | — | — | — | M | A |
| `lead.manage` | — | M | M | O | — | R | — | R | M | A |
| `analytics.view` | — | M | M | O | R | R | — | — | M | A |
| `billing.manage` | — | M | — | — | — | — | — | — | M | A |
| `integration.configure` | — | M | M | — | — | — | — | — | M | A |
| `comment.moderate` | — | — | — | — | — | — | M | R | M | A |
| `platform.settings` | — | — | — | — | — | — | — | — | M | A |
| `audit.view` | — | O | O | — | — | — | R | R | M | A |

`*` Content manager publication can be removed independently; default is submit-for-review rather than final publish.

Sensitive operations additionally check agency ownership, resource state, subscription entitlement, feature flag, and—where configured—four-eyes approval.

## Feature-flag matrix

Resolution: emergency environment override → agency override → plan entitlement → global default. `off` at a higher-priority safety scope wins. Changes append audit history.

| Key | Launch default | Scopes | Phase | Notes |
| --- | --- | --- | --- | --- |
| `agency_registration` | on | env/global/agency | 1 | Can close acquisition without deployment |
| `customer_registration` | off | env/global | 1 | Remains off for the agency-first GA profile |
| `agency_storefronts` | on | global/plan/agency | 1/5 | Public profile foundation in Phase 1 |
| `team_management` | on | plan/agency | 1 | Quota comes from entitlement |
| `listing_creation` | on | plan/agency | 2 | No hard-coded launch promotion |
| `comments` | off | global/plan/agency | 3 | Moderation mode is separate setting |
| `ratings` | off | global/agency | 3 | Property and agency ratings independent |
| `likes` / `dislikes` | on | global/agency | 3 | Public counts are separate flags |
| `comparisons` | entitled plan | global/plan | 8 | Private 2–5 property comparison and history |
| `collaborative_collections` | entitled plan | global/plan | 8 | Ordered collections with single-use invitations and revocation |
| `viewings` | on | global/plan/agency | 4 | Timezone-aware scheduling |
| `messaging` | on | global/plan/agency | 4 | Reporting/rate limits required |
| `newsletters` | off | env/global/plan/agency | 5 | Requires configured mail compliance |
| `video` / `three_d` | off | global/plan/agency | 2/8 | Safe media pipeline and quotas |
| `telegram_storage` | off | env/global/agency | 7 | Not part of the approved production launch profile |
| `mls` | entitled plan; external gate | env/global/plan/agency | 7 | RESO adapter implemented; enable only after provider license/certification |
| `ai_search` | entitled plan; external gate | env/global/plan/agency | 9 | Proposed filters require confirmation; enable after OpenAI acceptance |
| `ai_listing_writer` | entitled plan; external gate | env/global/plan/agency | 9 | Version-bound human approval and provenance |
| `sponsored_listings` | entitled plan; external gate | env/global/plan/agency | 10 | Always labeled and fetched separately from organic rank |
| `payments` | entitled plan; external gate | env/global/plan/agency | 10 | Stripe-hosted billing; promotion dates and paid state drive access |
