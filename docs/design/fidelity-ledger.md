# Visual fidelity and responsive acceptance ledger

Reviewed on 2026-08-18 against the accepted Casaura marketplace and agency-dashboard concepts in this directory. The implementation was captured from the running Next.js application with Chromium at 1440×1100 desktop, 1536×1024 dashboard, and 390×844 mobile viewports.

| Comparison point | Marketplace result | Agency workspace result |
| --- | --- | --- |
| Composition | Split editorial hero, search panel, property rail, location mosaic, verified agencies, market report, agency CTA, and footer preserve the concept's order and visual rhythm. | Persistent rail, utility header, greeting/actions, four metrics, priorities, lead table, chart, setup, and viewings preserve the desktop concept hierarchy. |
| Typography | High-contrast display serif and restrained sans-serif UI maintain the intended premium/editorial voice at all breakpoints. | Display headings and compact operational text follow the concept while remaining legible in dense cards and tables. |
| Color and elevation | Forest, white, muted sage, coral accents, hairline borders, and restrained shadows match the accepted visual system. | Forest/sage semantic states and coral setup CTA match; cards remain mostly flat with structural borders. |
| Imagery | Original generated, production-owned property imagery replaces the exact concept houses while retaining warm natural light, modern architecture, and editorial crops. | Listing thumbnails reuse the same owned image set; avatars intentionally use initials to avoid fabricated profile photography. |
| Responsive behavior | Mobile stacks the hero and image, keeps the search first, uses a deliberate horizontal property rail, and turns the location mosaic and footer into clear single/two-column flows. | Mobile removes the desktop rail, presents metrics as a 2×2 grid, keeps primary actions reachable, and provides a fixed bottom navigation. |
| Above-the-fold copy | Headline, supporting copy, search intents, primary search action, and conversational-search label match the accepted concept. | Greeting and attention copy match. The workspace labels accurately describe the implemented foundation. |
| Interaction honesty | Search submits real URL state. Provider-dependent market data is labeled development preview data. Newsletter capture is withheld until a compliant provider/double-opt-in flow exists. | “Add property” is visibly disabled because listing creation belongs to Phase 2. Profile, storefront, setup, and registration routes are real; no fake successful controls are presented. |

## Intentional deviations

- The hero and property photography is newly generated for Casaura rather than copied from the concepts.
- The dashboard's “Add property” control is disabled until the Phase 2 listing workflow exists; the accepted concept showed the future enabled state.
- A small “Workspace protected” panel makes tenant and permission enforcement explicit below the main dashboard fold.
- The public footer explains why email capture is unavailable instead of simulating a newsletter subscription.
- The marketplace concept is a compressed 864-pixel-wide desktop artboard, so the implementation comparison used a practical 1440-pixel desktop viewport with a similar full-page aspect ratio; mobile was verified separately at 390 pixels.

## Corrections made during acceptance

- Disabled the hidden required consent checkbox during agency-registration step one so the form can progress and each step owns only its active constraints.
- Removed the Next.js development indicator from acceptance captures.
- Kept mobile cards and workspace navigation within the viewport with no horizontal page overflow; the property rail alone scrolls horizontally by design.
- Replaced non-functional map and workspace controls with honest status or navigation elements.

The in-app-browser integration was unavailable in this environment, so the required visual and interaction checks used the installed Playwright Chromium fallback. Both accepted concept files and final implementation captures were inspected in the same comparison pass.
