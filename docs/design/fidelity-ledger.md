# Visual fidelity and responsive acceptance ledger

Reviewed through 2026-08-19 against the accepted Casaura marketplace, agency-dashboard, properties, and listing-editor concepts in this directory. The implementation was captured from the running Next.js application with Chromium at desktop, tablet, Pixel 7, 390-pixel, and 375-pixel viewports.

| Comparison point | Marketplace result | Agency workspace result |
| --- | --- | --- |
| Composition | Split editorial hero, search panel, property rail, location mosaic, verified agencies, market report, agency CTA, and footer preserve the concept's order and visual rhythm. | Persistent rail, utility header, greeting/actions, four metrics, priorities, lead table, chart, setup, and viewings preserve the desktop concept hierarchy. |
| Typography | High-contrast display serif and restrained sans-serif UI maintain the intended premium/editorial voice at all breakpoints. | Display headings and compact operational text follow the concept while remaining legible in dense cards and tables. |
| Color and elevation | Forest, white, muted sage, coral accents, hairline borders, and restrained shadows match the accepted visual system. | Forest/sage semantic states and coral setup CTA match; cards remain mostly flat with structural borders. |
| Imagery | Original generated, production-owned property imagery replaces the exact concept houses while retaining warm natural light, modern architecture, and editorial crops. | Listing thumbnails reuse the same owned image set; avatars intentionally use initials to avoid fabricated profile photography. |
| Responsive behavior | Mobile stacks the hero and image, keeps the search first, uses a deliberate horizontal property rail, and turns the location mosaic and footer into clear single/two-column flows. | Mobile removes the desktop rail, presents metrics as a 2×2 grid, keeps primary actions reachable, and provides a fixed bottom navigation. |
| Above-the-fold copy | Headline, supporting copy, search intents, primary search action, and conversational-search label match the accepted concept. | Greeting and attention copy match. The workspace labels accurately describe the implemented foundation. |
| Interaction honesty | Search submits real URL state. Provider-dependent market data is labeled development preview data. Property inquiry, account collaboration, reporting, and consented agency newsletter capture now persist through their API workflows. | “Add property” opens the persisted editor. Profile, storefront, collaboration, growth, team, campaign, analytics, moderation, flag, RBAC, and audit actions use real projections; the overview links to those sources instead of displaying invented counts or readiness. |

## Phase 2 listing-core comparison

| Comparison point | Properties workspace result | Listing editor result |
| --- | --- | --- |
| Composition | Preserves the approved utility bar, active property navigation, title/action row, four summary metrics, filter/tabs, inventory table, and publishing-readiness rail. | Preserves the top autosave/action bar, six-step rail, centered form canvas, quality inspector, and persistent mobile action bar. |
| Typography and hierarchy | Editorial serif title, compact operational labels, strong numeric summaries, and subdued secondary metadata match the established Casaura hierarchy. | The step heading, control labels, completion states, quality score, and primary continuation action retain the concept’s relative emphasis. |
| Color and state | Forest/sage is reserved for selection and progress; neutral borders and honest draft/attention states remain legible without decorative elevation. | Saved, conflict, validation, completion, and disabled-review states use semantic color while keeping the premium restrained palette. |
| Data honesty | Counts, table rows, filters, price, quality, and readiness totals come from the tenant API. Missing media uses an intentional neutral property glyph instead of fabricated photography. | Every shown value is persisted. The 40% captured quality score is calculated from completed basics/description and explicitly lists remaining requirements. |
| Responsive behavior | At 390×844 the sidebar becomes a fixed bottom navigation, metrics form a 2×2 grid, filters stack, and rows collapse to their essential facts with no page-level horizontal overflow. | At 390×844 the step rail becomes horizontally navigable, the form becomes one column, desktop actions collapse into the fixed Save/Continue bar, and the quality panel follows the form. |
| Above-the-fold copy | “Properties” and “Create, review, and publish your agency’s inventory.” match the approved concept exactly; the primary action remains “Add property.” | “Let’s start with the essentials,” its supporting sentence, both listing intents, and the save/review language match the approved concept. |
| Interaction and accessibility | Search, status/type filters, status tabs, sorting, cursor loading, edit links, empty/loading/error states, and active navigation are keyboard-operable and programmatically identified. | Inputs have visible labels, radio/checkbox groups use fieldsets, autosave uses a live region, errors use alert semantics, and all six steps/actions are keyboard-operable. |

## Phase 3 consumer-marketplace comparison

| Comparison point | Search and map result | Property detail result |
| --- | --- | --- |
| Composition | Preserves the concept's filter toolbar, result heading/sort, two-column card inventory, split map, price markers, selected-property tray, and mobile List/Map switch. | Preserves the media-first gallery, breadcrumb, editorial title/price, fact strip, engagement actions, descriptive content, feature/history/location panels, agency rail, estimate, and similar inventory. |
| Typography and hierarchy | Editorial serif result heading, bold price, compact filters, subdued metadata, and restrained map labels follow the accepted visual scale. | Large display title and price lead; facts, agency identity, disclosure copy, and panel headings retain the concept's relative emphasis across desktop and mobile. |
| Color and state | Forest controls and markers, sage map surfaces, white cards, hairline borders, and selected/favorite states use the established Casaura system. | Forest verified/status accents, neutral panels, disabled future handoffs, favorite/reaction states, and approximate radius remain semantically distinct without decorative noise. |
| Data honesty | Result counts, filters, cards, public locations, markers, and selected tray come from the real API. The capture uses test-published inventory and tiny test images rather than fabricated polished listings. | Copy, facts, price history, media count, agency, engagement, similar listings, and location policy are real projections; cost copy is explicitly illustrative and excludes unmodeled expenses. |
| Spatial privacy | Search-area bounds reach the real spatial API, and public markers use only exact/approximate/hidden policy outputs. A visible methodology note explains approximate locations. | The detail map renders a radius rather than an exact pin when policy is approximate and states that displacement protects the address. No private address or storage key reaches the browser. |
| Responsive behavior | At 390 pixels, filters condense behind an accessible disclosure, List/Map becomes an explicit segmented control, cards become one column, and the selected map tray remains reachable. | The mobile sequence is gallery, title/facts/actions, content panels, agency handoff, then similar listings; the acceptance pass corrected an earlier agency-first ordering. |
| Above-the-fold copy | The concept's location-specific “Homes near Oakridge” becomes “Homes for you” until a location query is applied; published count, sort, search control, and “Search this area” retain equivalent meaning without inventing locality context. | Title/location/price are dynamic listing values. “Approximate location” and the secure-handoff disclosure match the intended trust language; enquiry/viewing controls truthfully state their Phase 4 availability. |
| Interaction and accessibility | Query/filter submission, sorting, list/map toggle, search-this-area, selection, property navigation, loading/error/empty states, and favorites are keyboard and touch operable. | Favorite, like/dislike, share, gallery/media delivery, account hydration, canonical metadata, JSON-LD, 404 behavior, and responsive landmarks are implemented; unavailable handoffs are disabled rather than simulated. |

## Phase 4–6 operational experience comparison

| Comparison point | Public and consumer result | Agency and platform result |
| --- | --- | --- |
| Composition | Property inquiry and abuse reporting remain subordinate to the media-first property narrative. Agency storefronts retain the editorial identity/story/team/listing sequence, with hours/contact/newsletter in a clear supporting rail. | Leads use a queue/detail/operations-rail layout; growth groups readiness, hours/closures, team, campaigns, and honest analytics; administration uses health plus permission-aware operational tabs. |
| Data honesty | Inquiry success describes contact handoff without promising an anonymous account conversation. Storefront listings, team, hours, subscription state, account conversations, and viewings come from API projections. | The dashboard contains no demo person, synthetic zero metrics, or invented completion score. The profile form hydrates the selected tenant before enabling edits; custom-role permission changes synchronize through the API. |
| State and safety | Public 404s, feature-disabled newsletter capture, authentication-required reports, empty inventory, private account state, calendar downloads, and polling errors are explicit. | Version conflicts, viewing warnings/transitions, reminder outcomes, feature gates, quotas, partial platform permissions, secret redaction, immutable system roles, and denied access are visible without simulating success. |
| Responsive behavior | Long agency/listing names, contact values, consent text, and property facts remain contained at 375 and 390 pixels with no body overflow. | Dense records collapse or wrap without page-level horizontal scrolling. Mobile operational controls and consent targets are at least 44 pixels; labels/control text use the 13/14/15-pixel phase scale. |
| Accessibility | Forms have visible labels, consent is explicit, messages use live regions/log semantics, keyboard focus remains visible, and touch actions meet the accepted minimum target. | Console tabs, queue selection, status actions, schedule/campaign forms, role permission checkboxes, and responsive navigation are keyboard operable with programmatic labels. |
| Visual acceptance | Forest/sage/terracotta accents, serif editorial headings, restrained borders, and owned listing imagery preserve the established Casaura voice. | Independent round-two evaluation passed at 10/12 with no blocking items after desktop/mobile capture, computed typography/touch checks, and long-content fixtures. |

## Intentional deviations

- The hero and property photography is newly generated for Casaura rather than copied from the concepts.
- A small “Workspace protected” panel makes tenant and permission enforcement explicit below the main dashboard fold.
- Newsletter capture is shown only on an enabled agency storefront and records explicit consent through the persisted subscription endpoint; disabled agencies show an honest unavailable state.
- The marketplace concept is a compressed 864-pixel-wide desktop artboard, so the implementation comparison used a practical 1440-pixel desktop viewport with a similar full-page aspect ratio; mobile was verified separately at 390 pixels.
- Phase 2 inventory captures use three real test drafts instead of the concept’s larger polished catalogue. Their neutral glyph thumbnails and 40% quality/readiness values accurately represent missing media/location/features.
- The properties table uses a direct “Edit” action instead of an ambiguous overflow menu; pagination is an honest cursor “Load more” action rather than numbered pages.
- The editor allows 160 title characters instead of the concept’s illustrative 80-character counter, matching the approved API constraint and specification.
- Phase 3 uses a provider-free, data-derived map surface because no external tile-provider account is configured. Real bounds/radius filtering and safe public coordinates are implemented behind the visual abstraction.
- Phase 3 acceptance inventory is generated through real E2E publication and intentionally contains unique test labels and tiny test images. Empty media areas stay neutral rather than substituting deceptive stock photography.
- External newsletter delivery, calendar synchronization, and notification transports remain behind replaceable ports; local adapters provide deterministic development/test behavior until providers are selected.

## Corrections made during acceptance

- Disabled the hidden required consent checkbox during agency-registration step one so the form can progress and each step owns only its active constraints.
- Removed the Next.js development indicator from acceptance captures.
- Kept mobile cards and workspace navigation within the viewport with no horizontal page overflow; the property rail alone scrolls horizontally by design.
- Replaced non-functional map and workspace controls with honest status or navigation elements.
- Changed the new-editor state from an inaccurate initial “Draft saved” label to “Draft not saved,” then synchronized the saved label with the persisted dynamic edit URL before reload.
- Re-captured the desktop editor only after dynamic-route hydration so the acceptance artifact represents the stable implementation rather than its loading state.
- Removed the mobile property-detail agency-first ordering after visual inspection so listing identity, price, facts, and engagement precede the future contact handoff.
- Replaced the dashboard's demo identity, invented readiness percentage, and synthetic metrics with neutral navigation to live API-backed workspaces.
- Replaced seeded profile values with active-tenant loading, controlled edits, save/reload persistence, and explicit loading/error states.
- Raised operational typography to the phase scale, expanded mobile controls and consent labels to accepted touch targets, and contained long dynamic storefront/property content at both 375 and 390 pixels.
- Added editable custom-role permission synchronization and verified it with an authorized platform fixture.

The required visual and interaction checks used installed Playwright Chromium. All accepted concept files and final desktop/mobile implementation captures were inspected in the same comparison passes. The required independent evaluator passed the revised implementation at 10/12 with no blockers; temporary services and capture scripts were stopped or removed after acceptance.
