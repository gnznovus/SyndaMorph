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

## Production reliability region inventory

The pinned capture `c490c16d37152c757857d9d41267a758ee94bfcf4fb0de5e443b378e4593da07` contains 16 canonical regions. Its verified SingleFile receipt is `5d804f9fb95df28cd4367931611790fc3821a0de1822c945531eb2d002327523`.

| Region | Captured widths | Conversion blocker or capability |
| --- | --- | --- |
| Site header | 1440, 768, 375 | Sticky navigation, compact menu and desktop language controls need reusable navigation behavior. The logo, SVGs and declared destinations need asset and link resolution. The booking destination is external. |
| Hotel Indigo Bangkok Thonglor | 1440, 768, 375 | The desktop variant includes date, guest and rate controls, icons and `#rooms`. A booking handoff and the exact fragment destination must be proven. Compact and desktop contain different content. |
| Overlay · SPECIAL OFFER | 1440 | Desktop overlay and dismiss behavior were observed. Compact has no standalone captured variant. Visibility, overlay and dismissal need a reusable contract. |
| Live Thonglor’s rhythm from sunrise to skyline. | 1440 | No standalone compact variant. The title moves into the compact hero and the longer desktop introduction has no verified compact equivalent. Preserve the responsive transformation explicitly. |
| Best Rate Guarantee | 768, 375 | No standalone desktop variant. Compact badges require the three captured SVGs and a verified mapping for their visibility. |
| 12–14 DEC · 2 GUESTS | 768, 375 | No standalone desktop variant. Compact sticky placement needs a responsive behavior contract. Booking effects remain external or unverified. |
| OUR STORY | 1440, 768, 375 | Desktop illustration requires a verified native image import. The story URL is declared at all widths and needs a pinned destination contract. |
| EXCLUSIVE OFFERS | 1440, 768, 375 | Eligible via the native mobile scroll-strip contract. The original page scope holds the exact 213 px replay at 375 px. The source includes an empty second card. GEN-000058 recovered the source-passing reference with exact copy and scroll and received Bossy design approval. Native acceptance remains pending. |
| Rooms | 1440, 768, 375 | Desktop photo navigation and room selection have separate behaviors. Reusable carousel and disclosure capabilities need proof. Booking effects remain unverified. |
| SIP & DINE | 1440, 768, 375 | Eligible via the native compact scroll-strip contract. The original Full Page scope now holds the 594 px replay at 768 px and the 280 px replay at 375 px. The earlier GEN-000056 still holds its design before Developer. Reservation labels remain source copy. |
| THE NEIGHBORHOOD | 1440, 768, 375 | Desktop custom cards need Touch evidence for their states and actions before any static or reusable mapping can qualify. |
| THROUGH OUR LENS | 1440, 768, 375 | GEN-000051 passed and saved a private, unsynced native Pattern. The matching Full Page capture revalidated the Pattern and placed it in its unpublished draft. |
| Newsletter signup | 1440, 768, 375 | Country selection and SVG require component and asset support. Submission and consent need a verified external integration. |
| FAQs | 1440, 768, 375 | GEN-000057 passed and saved a private unsynced native Pattern after the desktop introduction wrapping repair. The matching Full Page archive revalidated the Pattern and placed it in the unpublished draft. |
| IHG Hotels & Resorts brands and IHG One Rewards | 1440, 768, 375 | The region is artwork with an exact alt attribute. Image import and an asset-only native contract are required. |
| Site footer | 1440, 768, 375 | Image and declared navigation destinations need asset and link resolution. Placeholder hash links and cookie preferences provide no verified integration contract. |

- **Discovery** has browser screenshots and visible inventories for 12 of 16 regions at every required width. Four regions have a variant gap. Separate runtime observations are needed before claiming complete behavioral understanding of each interactive region.
- **Conversion eligibility** is 3 of 16 with v0.12.18. The original preflight overlooked two scroll strips and the corrected v0.12.15 replay held both, leaving 1 of 16. The exact archived controls now qualify under the native scroll-strip contract. A run still requires recorded movement at every active width before Bossy planning.
- **Current conversion eligibility** is 4 of 16 with v0.12.28. Verified FAQ records cover all three widths and bind five native disclosures and their repeated indicator. The original source is preserved.
- **Acceptance** is 2 of 16 as of v0.12.30. REF-000030 has verified Through Our Lens and FAQ placements. The unpublished partial draft has 56 editor-verified blocks. Whole-page acceptance remains pending.

The five native FAQ blocks and their ordered answer text survived edit, save and reload in isolated draft 59. The source's second click could close the active panel, leaving all questions closed. Native grouped Details reproduced this behavior at 1440, 768 and 375 px. The source's Enter test opened no panel, while native Details opened the selected item. This capability proof did not use the production compiler or grant source visual approval.

The Full Page archive confirms five FAQ controls and five SVGs at each required width. Each SVG is a single untitled path nested in its own disclosure control, with a 24 by 24 view box. The region has no links. The recorded proof verified the medium and narrow source states without an overlay action and the desktop states after local dismissal. GEN-000057 kept the 424 px desktop introduction and restored the captured `text-wrap: pretty`. Bossy, Validator and independent browser checks accepted the retained Details, ordered panel copy and chevrons. Private Pattern 68 and its placement cost $0.024752 in total for the original Section job. Full Page reuse added no model spend.
