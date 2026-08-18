# Casaura design system

Accepted concepts:

- `casaura-marketplace-concept.png` — complete public homepage at 864 × 1792.
- `casaura-agency-dashboard-concept.png` — agency dashboard at 1536 × 1024, including mobile reference.

## Visual thesis

Casaura pairs editorial property storytelling with precise SaaS controls. Properties remain the focus; trust is communicated through restraint, provenance cues, and clear ownership rather than decoration.

## Tokens

| Token | Value | Use |
| --- | --- | --- |
| `canvas` | `#ffffff` | Main public background; true white is locked |
| `workspace` | `#f7f8f6` | Agency main canvas only |
| `ink` | `#14231d` | Primary text |
| `muted` | `#66716c` | Supporting text |
| `forest-900` | `#073d2e` | Primary action, brand, dark band |
| `forest-700` | `#145c45` | Hover and data visualization |
| `sage-100` | `#e8efe7` | Selected navigation and soft icon wells |
| `terracotta` | `#dc6747` | Rare acquisition/attention action |
| `line` | `#dfe4e1` | Thin dividers and input borders |
| `focus` | `#2d6cdf` | 3px visible keyboard ring |

Typography uses a characterful editorial serif for display headings and a humanist sans for UI/content. Display: Fraunces-like variable serif, 600 weight, tight tracking. UI: Geist, 400–650 weights. Controls are explicitly 13–15px; body copy is 15–18px. Do not use browser-default control type.

Spacing follows a 4px base: `4, 8, 12, 16, 24, 32, 48, 64, 96`. Public content max width is 1440px with 24px mobile, 40px tablet, and 56px desktop gutters. Dashboard density uses 16–24px panel padding.

Radii: inputs/actions 8–10px; property media 12–16px; panels 12–16px; circles only for icon buttons/avatars. Shadows are rare and low-contrast. The default container model is open sections with dividers; cards only frame a property, a form, or an actionable dashboard panel.

Icons use consistent rounded 1.75px strokes, `currentColor`, 20px standard size. Status icons may be filled when needed for fast scanning. Focus, hover, active, disabled, and error states are required variants.

## Responsive rules

- Header collapses to brand + menu + sign-in action below 900px.
- Hero becomes a single column; image remains above the fold on mobile but follows search hierarchy.
- Property rails use horizontal snap on mobile and four columns at wide desktop.
- Location mosaic becomes an accessible list/grid; labels never rely only on image contrast.
- Agency workspace sidebar collapses below 1080px and becomes a bottom navigation plus drawer below 720px.
- Tables preserve semantic rows on desktop and become labeled record groups on mobile, never an unlabeled horizontal scroll trap.
- Motion duration 160–240ms, transform/opacity only where possible; disabled under `prefers-reduced-motion`.

## Allowed first-viewport copy

Header: `Casaura`, `Buy`, `Rent`, `New homes`, `Agencies`, `Insights`, `List a property`, `Sign in`.

Hero: `Find a place that fits your life.`, `Search verified homes, compare the details that matter, and connect directly with trusted local agencies.`, `Buy`, `Rent`, `Land`, `Commercial`, `City, neighborhood or address`, `Search homes`, `Try conversational search`.

No decorative eyebrow, kicker, badge, proof chips, or invented stats may be added above the hero heading.
