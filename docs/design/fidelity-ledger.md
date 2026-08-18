# Visual fidelity and responsive acceptance ledger

Reviewed on 2026-08-18 against the accepted Casaura marketplace, agency-dashboard, properties, and listing-editor concepts in this directory. The implementation was captured from the running Next.js application with Chromium at 1440×1100 desktop, 1536×1024 workspace, and 390×844 mobile viewports.

| Comparison point | Marketplace result | Agency workspace result |
| --- | --- | --- |
| Composition | Split editorial hero, search panel, property rail, location mosaic, verified agencies, market report, agency CTA, and footer preserve the concept's order and visual rhythm. | Persistent rail, utility header, greeting/actions, four metrics, priorities, lead table, chart, setup, and viewings preserve the desktop concept hierarchy. |
| Typography | High-contrast display serif and restrained sans-serif UI maintain the intended premium/editorial voice at all breakpoints. | Display headings and compact operational text follow the concept while remaining legible in dense cards and tables. |
| Color and elevation | Forest, white, muted sage, coral accents, hairline borders, and restrained shadows match the accepted visual system. | Forest/sage semantic states and coral setup CTA match; cards remain mostly flat with structural borders. |
| Imagery | Original generated, production-owned property imagery replaces the exact concept houses while retaining warm natural light, modern architecture, and editorial crops. | Listing thumbnails reuse the same owned image set; avatars intentionally use initials to avoid fabricated profile photography. |
| Responsive behavior | Mobile stacks the hero and image, keeps the search first, uses a deliberate horizontal property rail, and turns the location mosaic and footer into clear single/two-column flows. | Mobile removes the desktop rail, presents metrics as a 2×2 grid, keeps primary actions reachable, and provides a fixed bottom navigation. |
| Above-the-fold copy | Headline, supporting copy, search intents, primary search action, and conversational-search label match the accepted concept. | Greeting and attention copy match. The workspace labels accurately describe the implemented foundation. |
| Interaction honesty | Search submits real URL state. Provider-dependent market data is labeled development preview data. Newsletter capture is withheld until a compliant provider/double-opt-in flow exists. | “Add property” now opens the persisted Phase 2 editor. Profile, storefront, setup, registration, inventory filters, autosave, media, and review actions are real; no fake successful controls are presented. |

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

## Intentional deviations

- The hero and property photography is newly generated for Casaura rather than copied from the concepts.
- A small “Workspace protected” panel makes tenant and permission enforcement explicit below the main dashboard fold.
- The public footer explains why email capture is unavailable instead of simulating a newsletter subscription.
- The marketplace concept is a compressed 864-pixel-wide desktop artboard, so the implementation comparison used a practical 1440-pixel desktop viewport with a similar full-page aspect ratio; mobile was verified separately at 390 pixels.
- Phase 2 inventory captures use three real test drafts instead of the concept’s larger polished catalogue. Their neutral glyph thumbnails and 40% quality/readiness values accurately represent missing media/location/features.
- The properties table uses a direct “Edit” action instead of an ambiguous overflow menu; pagination is an honest cursor “Load more” action rather than numbered pages.
- The editor allows 160 title characters instead of the concept’s illustrative 80-character counter, matching the approved API constraint and specification.
- Phase 3 uses a provider-free, data-derived map surface because no external tile-provider account is configured. Real bounds/radius filtering and safe public coordinates are implemented behind the visual abstraction.
- Phase 3 acceptance inventory is generated through real E2E publication and intentionally contains unique test labels and tiny test images. Empty media areas stay neutral rather than substituting deceptive stock photography.
- Contact and viewing controls remain disabled with an explicit Phase 4 disclosure; they will only activate when lead routing, consent, notifications, and scheduling are persisted end to end.

## Corrections made during acceptance

- Disabled the hidden required consent checkbox during agency-registration step one so the form can progress and each step owns only its active constraints.
- Removed the Next.js development indicator from acceptance captures.
- Kept mobile cards and workspace navigation within the viewport with no horizontal page overflow; the property rail alone scrolls horizontally by design.
- Replaced non-functional map and workspace controls with honest status or navigation elements.
- Changed the new-editor state from an inaccurate initial “Draft saved” label to “Draft not saved,” then synchronized the saved label with the persisted dynamic edit URL before reload.
- Re-captured the desktop editor only after dynamic-route hydration so the acceptance artifact represents the stable implementation rather than its loading state.
- Removed the mobile property-detail agency-first ordering after visual inspection so listing identity, price, facts, and engagement precede the future contact handoff.

The in-app-browser integration was unavailable in this environment, so the required visual and interaction checks used the installed Playwright Chromium fallback. All accepted concept files and their final desktop/mobile implementation captures were inspected in the same comparison passes. Temporary capture scripts were removed; ignored `qa-*.png` artifacts remain local verification evidence only.
