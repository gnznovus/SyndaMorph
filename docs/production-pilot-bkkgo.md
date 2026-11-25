# Production pilot 1 ? BKKGO homepage

The real SyndaMorph Production Team produced an editor-validated native draft for the FAQ introduction. The section still failed source and design fidelity, so the GEN remains **Uncompleted**. No homepage was assembled or published. This is the strongest independently validated partial result from one controlled trial. [BKKGO source](https://www.syndacast.com/2026/BKKGOPrototype1/index.html)

## Browser discovery

Playwright Chromium waited for rendered visible content and document fonts at 1440, 768 and 375 CSS px. The 1440 px browser used the desktop Home tree. Both narrower browsers used the distinct Home mobile tree, with different IDs, sections and some copy. The page heights were 10,789, 10,222 and 8,782 px respectively. Each viewport yielded 14 major regions and a separate FAQ introduction range. The three views loaded the logo and brand strip imagery and available Beausite Grand, Classic and Fit faces. Network capture recorded 16 successful responses per view, no failed allowed responses and no page exceptions. The allowlist blocked Cloudflare analytics.

| Logical scope | 1440 px | 768 and 375 px | Pilot outcome |
| --- | --- | --- | --- |
| Navigation | Site header with navigation and rate CTA | Compact header with Book and menu | Discovery only. Preserve navigation destinations and menu behavior. |
| Hero and booking | Hotel hero with an overlapping booking bar | Hero with booking controls, a Best Rate Guarantee panel and a sticky booking bar | Discovery only. Booking needs action, state and responsive-placement verification. |
| Offer overlay | Separate SPECIAL OFFER overlay | Absent in captured initial state | Dismissal verified in Chromium. It needs its own lifecycle and accessibility checks. |
| Introductory story | Live Thonglor's rhythm ? | Headline moves into the hero and the separate prose is absent | Discovery only. Responsive copy varies by template. |
| Our story | OUR STORY | OUR STORY | Discovery only. Preserve imagery and source destinations. |
| Offers | EXCLUSIVE OFFERS | EXCLUSIVE OFFERS | Discovery only. Visible content differs by template. |
| Stay | Rooms and carousel controls | STAY | Discovery only. Preserve selector and carousel behavior when present. |
| Dining | SIP & DINE | SIP & DINE | Discovery only. |
| Neighborhood | THE NEIGHBORHOOD and map treatment | THE NEIGHBORHOOD | Discovery only. Verify the map implementation and data. |
| Gallery/social | THROUGH OUR LENS | Thonglor Through Our Lens | Discovery only. |
| Newsletter | Newsletter signup with consent copy | Newsletter signup with mobile-specific content | Discovery only. Verify controls, integration and consent behavior before conversion. |
| FAQs | FAQs with a side-by-side intro and accordion | FAQs with stacked intro and accordion | FAQ intro alone was trialed. The first question visibly closed in all three browsers. The remaining accordion is outside this draft. |
| Brand strip | IHG Hotels & Resorts and rewards imagery | Equivalent brand strip | Discovery only. Portable image support required. |
| Footer | Full link groups and contact copy | Compact expandable footer groups | Discovery only. Verify links, responsive copy and expansion. |

The manifest records version, source URL, stable per-selector identifiers, observed viewport bounds, fonts, image/link/control counts, overhangs and copy digests. The desktop intro is a container. Its smaller variants are the adjacent heading and paragraph in their original flow. The job archive binds these three observations and source screenshots to one identity. The checked-in manifest is a separate reproducible inventory capture. A second Chromium run produced the identical manifest hash, fe508fc3aea85f8069616df8d9625a6e3c0487b8b06738af8821313d23ce206e. [Responsive manifest](fixtures/production-pilot-bkkgo-manifest.json)

## Implementation

The authenticated source endpoint reuses the capture service, browser request boundaries and section detection. It reads raw DOM evidence and selects a single region per viewport. The source-only inventory path skips creation of manual importer markup. Production Setup supports explicit variant binding when a site uses separate layouts. A short-lived, user-bound capture handle admits pinned measurements and screenshots through the existing native Production Team stream. The archival job carries the same evidence through pause and resume.

Boss receives the live screenshots and facts and classifies the selected source. Designer creates an explicit native-compatible reference. Boss approves that reference, and Developer uses the existing compiler, QA, Adaptive Surgery and Learning. Literal copy must match before Developer is called. Independently, the final candidate is compared with the captured source at all three widths. Its source result stays separate from QA against Designer's reference and is bound to the compiled artifact. WordPress completion requires both QA layers and the existing editor validation. Existing original-design jobs continue on the same workflow.

The source gate uses the existing screenshot limits of 1.5 percentage points per viewport and 0.5 percentage points on the mean as a conservative source comparison from zero. The progress/regression rubric for Adaptive Surgery stays the same. Link, image, interaction and sections taller than the comparison frame require additional independent verification before source acceptance. The pilot does not add site-specific conversion behavior. The archived source identity and section-local candidate can be inputs to a future campaign without changing the existing Production Team contract.

## Live trial

**GEN-000029** ran Claude Haiku 5.5 once with the configured USD 1.00 ceiling. The gated scopes of the pilot paid for 11 calls. Anthropic reported 89,220 uncached input, 25,624 output, 18,370 cache-read and 1,837 cache-write tokens. The configured rate estimate was **USD 0.022147**, leaving **USD 0.977853**. Browser capture, editor validation, draft staging and offline replay consumed zero additional model calls.

Boss classified the FAQ introduction as an editable heading and paragraph in a native section. It approved the Designer reference with source-font and layout limitations. Developer compiled three blocks after one schema repair for the same CSS clamp expression written with a negative first term. A generic compiler normalization now rewrites that equivalent expression before requesting a patch. The first visual proposal missed a required strategy-deviation receipt and did not render. The second provided the receipt and improved the candidate. Comparable score fell 36 to 26. A newly reported desktop alignment finding brought the raw score back to 36. The third operation escalated the viewport-dependent width constraint. Version 2 remained retained.

| Viewport | Source intro bounds (x, page y, width, height) | Pixel difference vs source | Difference vs Designer | Literal copy |
| --- | --- | ---: | ---: | --- |
| 1440 | 60, 9284.4, 424, 197.5 | 1.078% | 0.559% | Equal |
| 768 | 20, 8815.6, 728, 100.8 | 1.260% | 0% | Equal |
| 375 | 20, 7131.5, 335, 152 | 2.705% | 0% | Equal |

The native editor validated all three blocks through the authenticated History UI. Opening the draft in the actual pattern editor independently confirmed a valid Group, Heading and Paragraph. WordPress stored the exact artifact as local draft unsynced pattern **82**, SHA-256 **b3ceb2e7ec4338b39422e1fd0424610175f0bf23515f7ad523acf0b52b4fd9d3**. The draft retains its source QA blocker. No portion is represented as source-fidelity accepted, and no full homepage was assembled.

The reference constrains paragraph width at desktop while letting it span the tablet/mobile layout. The current compiler exposes one fixed Group contentWidth. Applying that fixed desktop width at tablet would introduce an unrelated regression. At desktop the native paragraph used one line where the Designer reference used three, and the native section was 60.3 CSS px shorter. The browser loaded Beausite Grand and Classic in the source; the native artifact continued to use the host font. Scope resolution requested design review. An offline raw Core 7.1.2 probe confirmed native responsive Group width attributes can produce paragraph widths of 424, 728 and 335 px at the three specified viewports. The source uses a different desktop breakpoint from the host defaults, and that probe did not resolve the font or desktop line-height differences. Mapping these attributes into the compiler and editor contract, resolving font deployment and validating the remaining complete sections are follow-up engineering work. A custom block is justified only for verified behavior that core blocks cannot preserve.

## Reproduce without model calls

Select Native Gutenberg in Production Setup and use **Discover sections**, then choose the same content at 1440, 768 and 375 px. Discovery is free and uses only the guarded browser backend. An administrator can also call POST /syndamorph/v1/production-source with a URL and optional sectionId or the three variants keyed by viewport. A selected descriptor returns an opaque capture handle for the existing team stream. Standalone Playwright/WordPress tests cover inventory boundaries, manifest canonicalization, evidence ownership, source copy and visual gating. Keep the recorded GEN as proof of live behavior.
