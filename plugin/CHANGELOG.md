# SyndaMorph Changelog

All notable changes to SyndaMorph are recorded here. The release workflow
includes this file inside the installable plugin ZIP and publishes the matching
version entry as the GitHub Release notes.

This changelog describes implemented work, not acceptance or production readiness.

## [0.12.54] - 2026-11-27

### Security

- The administrator transport check validates its nonce before WordPress starts page output. Invalid submissions return HTTP 403 without running service probes, including under PHP-FPM.

## [0.12.53] - 2026-11-27

### Security

- Renderer denials and unauthenticated routes omit inspection metadata. Authenticated inspection keeps its protocol and build headers.
- The WordPress transport check verifies a real framed Chromium sample, including literal copy, response limits, and the exact source frame receipt. Staging Compose validation checks adjacent credential loading, fixed build contexts, and the opt-in loopback renderer port.

## [0.12.52] - 2026-11-27

### Added

- Recorded Header is an editable Gutenberg block for a sticky desktop header, desktop flyouts, and a compact drawer. It preserves declared links and keeps source placeholders inert. An opt-in block-theme canvas lets the draft supply its own page framing.

### Verified

- The local BKKGO candidate preserved 427 valid blocks through Gutenberg save and reload. The source-aligned desktop first fold measured 0.129% difference with the offer open and 0.367% after dismissal. Both compact drawers matched their archived screenshots byte for byte. Compact first-fold, automatic placement, and Full Page QA remain pending. No model calls were made.

## [0.12.51] - 2026-11-27

### Added

- Desktop Offer is an editable Gutenberg block with breakpoint-controlled visibility, an inert promotional label and a local dismissal button. A source-marked hero Group can keep its lead text hidden while the offer is open without changing the booking bar.

### Verified

- The unpublished BKKGO trial preserved 426 valid blocks through Gutenberg save and reload. The card matched its archived geometry and measured 0% differing pixels at 1440 px. The combined initial and dismissed hero comparisons still exceeded the visual gate, so Full Page acceptance remains pending. No model calls were made.

## [0.12.50] - 2026-11-27

### Added

- Full Page Production Setup can record separate settled hero and adjacent region crops after a confirmed local dismissal. Recorded replay reproduces the original hero screenshot byte for byte before dismissal and verifies stable copy, geometry and screenshots afterward. The original screenshot and production gate remain in place.
- Developer surgery supports measured grayscale text painting on an individual native Heading as well as a Paragraph.

### Verified

- The original BKKGO scope recorded both settled desktop crops. An unpublished Gutenberg-saved draft retained all 425 valid blocks and the existing booking controls. The settled 1440 px hero and combined hero and intro measured 0.439% and 0.454% difference. The original pinned hero still measured 1.811%, and initial-state and Full Page QA remain pending. No model calls were made.

## [0.12.49] - 2026-11-27

### Security

- The host WordPress transport check verifies Gateway authentication and protocol through a bounded response without reading customer records. The renderer's public liveness probe exposes only a status. Authenticated inspection retains its protocol and build headers.

### Fixed

- The private HTTP allowlist accepts the existing Gateway lease update route, restoring status changes from WordPress administration.

## [0.12.48] - 2026-11-27

### Added

- Booking Summary is an editable Gutenberg block with local guest counters and rate selection. For the desktop hero, it supports a measured 44 px overlap. The archived date and availability labels remain display text. Automated booking placement remains pending.

### Fixed

- Fresh Full Page capture waits briefly for finite, visible opacity and position transitions after initial layout. Archived replay preserves the original screenshot path.

### Verified

- The Gutenberg-saved desktop BKKGO draft kept all 425 blocks valid and reproduced the three counter ranges, five rates and measured bar overlap without booking requests. Its hero passed the settled desktop crop at 0.459% difference. The combined crop remains pending at 0.701%. No model calls were made.

## [0.12.47] - 2026-11-27

### Added

- The native plan compiler preserves explicit plain-text heading breaks and measured heading line heights below 1. It supports editable fixed-track Groups and one right or bottom border on a Group. Surgery protects the authored breaks as part of the heading's content.

### Verified

- The exact Gutenberg-saved compact BKKGO hero passed the original visual gate at 768 and 375 px. An unpublished 420-block page proved that the intro title appears once across nine widths and that Rooms keeps one anchor. The original scope retains eight accepted placements while desktop booking and combined visual QA remain pending. No model calls were made.

## [0.12.46] - 2026-11-27

### Fixed

- Developer Adaptive Surgery can select grayscale glyph rendering on an individual native Paragraph when replay shows a source antialiasing mismatch. The saved block retains its original text, wrapping and identity. The compiler applies the same scoped style in preview, editor and frontend.

### Verified

- The exact Gutenberg-saved BKKGO intro measured 0% difference against its pinned 1440 px region-only crop, down from 1.231% under the existing gate. All five blocks survived edit, save and reload. The compact hero and combined page QA still hold placement pending. No model calls were made.

## [0.12.45] - 2026-11-27

### Security

- WordPress uses one private HTTP transport for capture, production source, rendering, inspection, recovery, and Gateway administration. It accepts only the existing Docker service origins or their fixed IPv4 loopback ports, disables redirects and proxies for these calls, and keeps credentials out of WordPress HTTP diagnostics.
- Renderer jobs require a dedicated server credential across all Chromium and image-processing routes. The unauthenticated health probe returns a bounded status. Authenticated inspection retains its protocol, framing, and evidence limits.

### Added

- A services-only Plesk staging Compose file and an administrator-only WordPress transport check support an existing host-installed WordPress. The renderer's port uses an opt-in Compose profile.

## [0.12.44] - 2026-11-27

### Added

- Full Page recorded replay can save a separate desktop region crop when a verified adjacent region paints across its boundary. It reproduces the original composed image byte for byte before hiding the recorded overhang and checks that the target copy and layout still match. The original capture remains the page-level visual reference.

### Verified

- The BKKGO intro crop reproduced the adjacent hero's 44 px booking bar overlap and recorded the 592 px intro crop separately in History. The unpublished five-block intro passed Gutenberg edit, save and reload. Native visual QA remains pending at 1.231% difference against the original 0.5% aggregate limit.

## [0.12.43] - 2026-11-27

### Added

- Full Page Production Setup can verify when a desktop region's title moves to a different source region at compact widths. Recorded Touch checks for one visible copy and binds each observation to the archive and both source checkpoints. The native conversion gate stays pending.

### Verified

- The original BKKGO scope recorded the standalone intro title at 1440 px and the same title inside the hero at 768 and 375 px. The scope kept eight accepted Patterns, and the unpublished page and recorded model usage were preserved.

## [0.12.42] - 2026-11-27

### Fixed

- Native section generation now uses the approved Designer measurements to choose the outer Group's WordPress alignment. Edge-to-edge sections save with Full width, theme-wide sections use Wide width, and theme-content sections keep their normal alignment. Inner layouts retain their own widths.
- The alignment travels with the working plan through compilation, repair and WordPress serialization. Isolated native QA respects the selected theme width for bounded sections.

### Verified

- The revised BKKGO SIP & DINE Pattern opened at Full width and retained all 68 editable blocks through Gutenberg save and reload. Its normal WordPress draft matched the approved section width at 1440, 768 and 375 px. Source visual, responsive and scroll checks passed without paid model calls.

## [0.12.41] - 2026-11-28

### Added

- Production Setup can verify and compile a recorded Rooms showcase into editable Core blocks. The desktop carousel preserves automatic progression, wraparound, direct photo selection and category resets. Compact widths retain the measured label strip and its scrolling behavior. Its apparent booking links remain inert where the archive showed no destination.

### Verified

- BKKGO Rooms passed pinned visual comparisons at 1440, 768 and 375 px with differences of 0.917%, 0% and 0%. The private unsynced 103-block Pattern became the eighth verified placement. The original unpublished draft passed Gutenberg edit, save and reload with all 400 blocks valid. No paid model calls were used.

## [0.12.40] - 2026-11-27

### Added

- Production Setup can verify an archived desktop reveal grid and compile it into editable Core blocks. The recorded receipt binds each card to its copy, hover and exit states, and inert label. Independent browser QA checks pointer behavior, visible keyboard focus, and absence of reveal controls in the compact variant.

### Verified

- BKKGO Neighborhood passed pinned visual QA with pixel differences of 0.685%, 0.128%, and 0.026% at 1440, 768, and 375 px. Private unsynced Pattern 106 became the seventh verified placement. A copy of the original unpublished draft kept all 297 blocks valid through Gutenberg edit, save and reload. No paid model calls were used.

## [0.12.39] - 2026-11-27

### Added

- Production Setup can verify and save a measured editorial layout as an editable Core Pattern. It binds the declared link and the original PNG receipt to the source checkpoint. The rendered asset must match its bytes, alt text, dimensions, paint and viewport visibility. Stylesheet changes require fresh browser QA on the saved Pattern.

### Verified

- BKKGO Our Story passed source visual, content, link, and asset checks at all three required widths and passed 14 responsive samples. Private unsynced Pattern 100 became the sixth verified placement in the original unpublished draft. Gutenberg edit, save and reload preserved all 240 blocks. Whole-page QA remains pending. The checkpoint used zero paid model calls.

## [0.12.38] - 2026-11-27

### Added

- Recorded source replay verifies that a section keeps the same declared same-origin HTTPS link and uses an exact archived PNG at its observed widths. WordPress retains a source-bound image and link receipt and imports the original PNG into the Media Library. Native production QA remains pending until an eligible artifact passes the existing gates.

### Verified

- The original BKKGO Our Story region retained its desktop illustration and exact link at all three required widths. A disposable 15-block trial passed the pinned visual limits and Gutenberg edit, save and reload. The trial used prototype CSS and remains outside production acceptance. The original scope still has five verified placements. The checkpoint used zero paid model calls.

## [0.12.37] - 2026-11-27

### Added

- Verified single-image strips can be saved as private unsynced Patterns using editable Core Group and Image blocks. Independent QA checks the original PNG bytes, source screenshots, responsive overflow, alt text, and compact pointer and keyboard behavior before save.

### Verified

- The BKKGO brand strip matched its pinned screenshots at 1440, 768 and 375 px with zero pixel difference. Real WordPress reproduced the 80 px and 473 px compact scroll ranges. Pattern 89 became the fifth verified placement in the original draft, which passed Gutenberg edit, save and reload with 225 valid blocks. The original Full Page still has 11 required regions pending. The checkpoint used zero paid model calls.

## [0.12.36] - 2026-11-27

### Added

- An authenticated source action replays a single archived PNG at the three required widths, verifies its alt text and observed scroll endpoints, and imports the exact original bytes through the existing Media Library path. A repeated successful action reuses the verified attachment.

### Verified

- The BKKGO brand strip retained its 2650 × 590 PNG and exact alt text. Compact source replays reached 80 px and 473 px. The local Core Image block retained its asset and alt text through edit, save, and reload. An isolated framing trial measured zero pixel difference at all three widths. Conversion stays blocked pending native single-image scroll QA.

## [0.12.35] - 2026-11-27

### Changed

- Editable painted Groups support the measured 3:4 portrait ratio. Browser inspection preserves the identity of a compiled native scroll strip and reports measured borders on visible labels and dividers.
- Staged reviews can resume after fresh evidence resolves a scroll identity or border diagnostic. Verified keyboard, focus, initial state and inert text receipts bind to their exact requirements. A native-only recheck retains the recorded design decision and reruns required checks before Validator.

### Verified

- The corrected Dining Section completed as private unsynced Pattern 79 with 68 editor-verified blocks. Native source difference measured 0.172%, 0.117%, and 0.340% at 1440, 768 and 375 px. Its staged job used eight metered calls totaling $0.057829.
- Local BKKGO draft 66 contains four accepted placements and 223 editor-verified blocks. Dining reproduced the pinned first scroll movements at 768 and 375 px. Whole-page QA remains pending while required regions are missing.

## [0.12.34] - 2026-11-27

### Fixed

- Isolated inspection recognizes quoted local font names in captured inline CSS, including HTML-escaped quotes. The source fragment stays byte-identical, and the existing installed-font allowlist still determines which files can be loaded.

### Verified

- Offline SIP & DINE design surgery restored the three-card compact strip and exact rounded section heights. The local trial passed the pinned source comparison at 0.139%, 0.117%, and 0.340% and all 11 responsive widths. GEN-000056 retains its original design hold while its work order still predates the verified scroll evidence. No model calls were used for this trial.

## [0.12.33] - 2026-11-27

### Changed

- Capped Developer streams can resume on the original job with a completed plan that preserves the authored content and identities of every complete block. A retained artifact held exclusively on model findings can receive one independent review with fresh browser evidence and the same work order.
- Native plans support non-stacking Core Columns, bounded solid Group and Paragraph borders, Row spacing, and painted 4:3 Group tiles. The preview uses WordPress text alignment rules. Full Page reuse restores the source scroll identity from the approved plan only when it reproduces the saved Pattern exactly.

### Verified

- Exclusive Offers completed as private unsynced Pattern 73 with 99 editor-verified blocks. The pinned source comparison measured 0.060%, 0.000%, and 0.000% at 1440, 768, and 375 px. The original job recorded $0.146740 in metered spend.
- The original local BKKGO draft now contains three verified placements and 155 editor-verified blocks. The actual 375 px preview reproduced the 213 px Offers scroll range by wheel and keyboard with visible focus. Whole-page QA is pending while required regions are missing.

## [0.12.32] - 2026-11-26

### Changed

- Designer reviews include exact bounded literal-copy differences and visible text near the end of each viewport. Diagnostic projections favor visible elements and report their coverage. An omitted element cannot serve as proof of absence.
- Source surgery rolls back any increase in pixel difference at a required width without a recorded layout authorization. A repaired design can resume after a dispute only when pinned source and responsive QA pass.
- An oversized review diagnostic can resume on the retained reference with bounded browser evidence. A metered Developer response that reached the output cap without emitting text can retry once from its exact approved handoff and original budget.

### Verified

- The repaired Offers reference passed source QA at 0.190%, 0.031%, and 0.066% pixel difference and passed all 13 responsive samples. With measured compact footer and WordPress contrast evidence, Bossy approved the reference and handed the original job to Developer. The first Developer call exhausted the cap without text. The retry retained a 3,255-byte partial plan. Implementation QA remains held. The original job recorded $0.128741 in metered spend.

## [0.12.31] - 2026-11-26

### Added

- A retained Designer response that reaches its output limit can resume on the original job when its complete HTML and completed CSS rules are recoverable. The continuation supplies only CSS, retains the work order and metering, and still requires fresh source and design gates. Missing markup, a cut declaration and unsafe continuation are held.

### Verified

- Exclusive Offers GEN-000058 continued after hitting the 16,384-token Designer cap. Review retained the design and held its source visual requirements. A zero-call copy repair passed exact visible copy and local scroll checks at all mandatory widths. A separate wide-viewport typography trial measured 3.940%, 3.687% and 5.863% source difference. Neither trial passed visual QA. The job used 10 completed calls plus the metered capped response, totaling $0.070055.
- Exact archived scroll evidence was bound to Exclusive Offers and Sip & Dine on the original BKKGO Full Page scope. A fresh network-isolated replay reproduced 213 px for Exclusive Offers and 594 px and 280 px for Sip & Dine at their active widths. Full Page acceptance remains two of 16.

## [0.12.30] - 2026-11-26

### Added

- An editable Core Paragraph can preserve observed `text-wrap: pretty` through a registered class. The original staged review can recover through a single source-bound wrapping edit after a design rejection. Both the native artifact and the semantic reference must improve under pinned browser comparison without losing copy, links, height or any requested width.
- Staged disclosure review can replay an exact Bossy decision when its only remaining design checklist item is explicitly native-only keyboard verification. The compiled artifact still has to pass Enter, Space and visible focus independently before Validator.

### Verified

- BKKGO FAQ GEN-000057 completed on the original job. One Paragraph edit improved the desktop source difference from 1.172% to 0.680%. The 768 px and 375 px comparisons remained 0.034% and 0.058%. All 12 responsive samples, disclosure interactions and 21 Gutenberg blocks passed. Five completed model calls cost $0.024752 in total.
- Private FAQ Pattern 68 was placed after Through Our Lens in local draft 66. The actual draft passed both placements' viewport and interaction checks at 1440, 768 and 375 px. An exact copy passed Gutenberg edit, save and reload with 56 valid blocks. Full Page acceptance is two of 16 regions and whole-page QA is pending.

## [0.12.29] - 2026-11-26

### Added

- An exact retained native candidate can enter the existing Section review workflow when the source checkpoint, archive and compiled artifact match. Bossy and Validator still own their independent approvals. Planning recovery can retry the recorded disclosure classification once on the same job. A schema-only recovery can move one complete sentence from an oversized requirement to its acceptance field.
- The isolated Full Page WordPress template emits the active WordPress font-face definitions so saved draft previews can load the registered font presets.

### Verified

- BKKGO FAQ GEN-000057 recovered the exact disclosure ID and reached Bossy design review after three metered calls costing $0.014236. In a renderer trial the source's `text-wrap: pretty` lowered desktop pixel difference from 1.172% to 0.680% at the same 424 px paragraph width. The trial left both compact ratios unchanged. FAQ remains held and Full Page acceptance remains one of 16.
- Actual local draft 66 loaded both registered Beausite families and retained the 4/2/2 gallery grid at 1440/768/375 px.

## [0.12.28] - 2026-11-26

### Added

- Source scopes can verify a recorded disclosure group across the pinned viewports. The receipt binds the archive, region, ordered panel copy, first-open state, six-action FAQ trace and repeated local chevron. Unrelated controls, artwork and incomplete replay remain blocked.
- Native disclosure groups compile to editable Core Details and Paragraphs. Chromium independently checks both the approved reference and native implementation for exact panel copy, exclusive click states, keyboard focus and repeated-pattern isolation.

### Verified

- BKKGO FAQ became eligible on the existing Full Page record, raising eligibility from three to four of 16. Its retained candidate passed pinned source comparisons at 1.172%, 0.034% and 0.058%, all 12 responsive samples and independent disclosure checks. All 21 blocks survived a Gutenberg edit, save and reload. Model approval remains pending. This checkpoint used no AI calls.

## [0.12.27] - 2026-11-26

### Added

- Full Page can reuse an independently verified Section Pattern when the selected region, source archive, exact captured evidence and applicable work order agree. The adapter rechecks the region source, stores its original proof and keeps provider spend on the original job.

### Verified

- BKKGO Full Page REF-000030 accepted its Through Our Lens placement and assembled unpublished WordPress draft 66 with zero additional AI calls. All 35 blocks survived Gutenberg editing and reload in a disposable copy of the exact draft. The Full Page job remains uncompleted with one of 16 placements.

## [0.12.26] - 2026-11-26

### Added

- Content Tabs can use the registered standard appearance in the Designer reference and native block. The same stylesheet supplies control sizes, states and panel typography, scoped to each identified widget.
- A retained native plan can receive fresh design and implementation decisions after a hash-bound correction to its saved reference. Recovered implementation review requires the exact visual, functional, responsive and host evidence before it calls the Validator.

### Verified

- GEN-000055 completed on the original job with an unpublished WordPress draft. The patched native block matched the newly approved reference at 1440, 768 and 375 px. It passed six functional requirements and eight responsive samples. Four valid blocks and the edited panel text survived Gutenberg save and reload. The two authorized reviews added $0.006613, for $0.023836 total estimated spend.

## [0.12.25] - 2026-11-26

### Added

- Source-backed Sections can resume at implementation review from an exact retained native plan and its approved Designer reference. Compiler, source, responsive and host checks must pass before the paid review. The continuation keeps the original usage and sends no Designer or Developer requests.
- Native horizontal Row groups support editable labels. Decorative Grid tiles can retain an explicitly required minimum height.

### Verified

- GEN-000051 completed on its original job and saved private, unsynced Pattern 64 in local WordPress. Pinned source differences were 0.310%, 0% and 0%. The approved Designer-to-native comparison measured 0% at all three required widths. All 35 blocks survived an actual Gutenberg edit, save and reload. Three implementation reviews cost an estimated $0.016709. The original job finished with $0.150708 in estimated spend and $0.021800 reserved.

## [0.12.24] - 2026-11-26

### Fixed

- Native Group Flow and Column width and vertical alignment support preserve vertical document order and desktop header alignment. Typography QA ignores browser-confirmed hidden variants when matching visible text.

### Verified

- GEN-000051 passed strict pinned source fidelity at 0.382%, 0% and 0% across 1440, 768 and 375 px. WordPress Page accessibility and all 12 responsive checks passed. All 34 blocks survived a private Gutenberg edit, save and reload. Recovery made zero AI calls. The final implementation decision remains pending.

## [0.12.23] - 2026-11-26

### Added

- Decorative square tiles can compile to native Gutenberg Grid groups with two to four columns. Structural repair converts an explicitly authorized, rectangular Columns matrix of empty painted groups into a Grid while preserving tile identities, paint and visual row order.
- Native Paragraphs support the standard underline setting for literal source labels.

### Verified

- The retained GEN-000051 candidate improved source pixel differences from 31.278%, 41.897% and 33.896% to 9.364%, 1.032% and 3.100% at 1440, 768 and 375 px. Source copy, responsive checks and WordPress Page accessibility checks passed. All 34 native blocks survived editing, saving and reloading in a private Gutenberg draft. The original job retains the candidate and remains uncompleted pending source visual fidelity. Recovery made zero AI calls.

## [0.12.22] - 2026-11-26

### Added
- Source-backed Section work orders can require browser-measured heading hierarchy and text contrast in the configured WordPress Page context. The check covers the Designer reference and native output. Pattern and completion guards require current, artifact-bound native proof.

### Fixed
- A timed-out Developer can resume on the original job from the reviewed design and its exact work order. The application remeasures the saved approval checklist before retrying Developer and preserves the provider reservation.

### Verified
- GEN-000051 resumed at Developer and retained its first native artifact. All 40 blocks survived a real Gutenberg edit, save and reload. The native source pixel differences of 31.278%, 41.897% and 33.896% kept the job uncompleted. The scoped repair escalated because the tile layout requires structural work.

## [0.12.21] - 2026-11-26

### Fixed
- A held source-backed work order can be corrected and reverified on the original job. Exact literal targets disambiguate style checks, and verified static source labels remain noninteractive. History retains both work orders with the pinned evidence and Designer checksum.

### Verified
- GEN-000051 passed its corrected typography and source-label checks without regenerating the reference. One Bossy review cost an estimated $0.006940. The Developer handoff remained held on host heading hierarchy and contrast.
- A disposable WordPress draft containing the exact sketch showed an H1 followed by the section H2 at all required widths. The minimum measured text contrast was 6.519:1. The proof is for the hosted sketch, with native candidate verification still open.

## [0.12.20] - 2026-11-26

### Fixed
- Pinned Section comparisons reproduce the recorded source screenshot origin and encoder. Layout and accessibility are measured before the temporary translation. A mismatched frame leaves evidence pending.
- A bounded, verified surgical edit can resume an original job after a rejected Designer surgery. Clean WordPress inspection tests explicitly load the native compiler.

### Verified
- GEN-000051 passed deterministic source fidelity at 0.310%, 0%, and 0% and retained the repaired Designer checkpoint. Bossy approved the same resumed job. The engine held the handoff because four required design checks remain unresolved. Two new metered review calls cost an estimated $0.012275.

## [0.12.19] - 2026-11-26

### Added
- Production can use an installed WordPress font preset in Designer inspection and native Group, Heading and Paragraph blocks. Chromium receives verified local font bytes through the isolated renderer, and Gutenberg stores editable preset references.

### Verified
- The archived GEN-000051 sketch improved from 1.797%, 1.451% and 4.239% to 0.636%, 0.924% and 1.458% at 1440, 768 and 375 px after loading its recorded fonts and correcting the mobile title width. The 1.006% average still exceeds the 0.5% gate. No model call was needed.
- A private Gutenberg draft retained the Manrope and Fira Code presets after a content edit, save and reload. Both fonts loaded at all three required front-end widths.

## [0.12.18] - 2026-11-26

### Added
- Responsive source capture can qualify a bounded mobile or compact scroll strip against exact card geometry, ordered text and a stable Touch target. The existing source adapters require observed movement on the pinned recording before any provider call. Source QA checks the exact component and card copy at each required width.

### Verified
- Offline replay found three eligible BKKGO regions out of 16 with the new source binding. The two strips passed their exact recorded gestures. A missing gesture held production before any provider call. No BKKGO region had passed full source and native QA at this checkpoint.

## [0.12.17] - 2026-11-26

### Added
- The native production compiler supports a compact or mobile scroll strip built from editable Gutenberg Groups. Independent Chromium QA checks exact-width visibility, wheel and keyboard reachability, focus, and ordered card copy against the approved design.

### Verified
- Both archived BKKGO card sets passed the native capability check at mandatory and breakpoint-adjacent widths. Gutenberg preserved 13 valid blocks and the edited card through save and reload. Source admission and end-to-end acceptance remain open.

## [0.12.16] - 2026-11-26

### Fixed
- A scoped Bossy design hold preserves the existing candidate, shows the recorded reason, and blocks a paid retry while a decision is pending. Independent Full Page regions can continue.

### Verified
- GEN-000056's live SIP & DINE design hold rejected a retry with zero provider calls and retained its exact archived candidate and accounting.

## [0.12.15] - 2026-11-26

### Fixed
- Capture recognizes anonymous horizontal scroll containers as interaction requirements. Touch can replay bounded horizontal wheel movement against a discovered target and preserve the measured local state.

### Verified
- Recorded BKKGO replay proved horizontal scrolling in SIP & DINE at 768 and 375 px and Exclusive Offers at 375 px. Both need a native interaction contract before conversion. Through Our Lens remains the only one of the original three candidates without this blocker.

## [0.12.14] - 2026-11-26

### Fixed
- Browser text checks retain paragraph-sized literals and optional selectors. Incomplete element evidence leaves the scoped check uncertain.
- A recoverable scoped planning response can be validated from History and continued on the same GEN without repeating Bossy classification or losing its recorded cost.

### Verified
- The exact 253-character SIP & DINE paragraph in GEN-000056's archived work order passed the scoped browser check at its required width.
- Live continuation on GEN-000056 proceeded to Designer and Bossy with the saved work order. Source markup passed and visual acceptance was held before Developer.
- GEN-000055's native Group gap correction improved the mobile visual result to 1.753%. The artifact passed six functional requirements, eight responsive widths and isolated Gutenberg edit, save and reload. Visual acceptance remains open.

## [0.12.13] - 2026-11-26

### Fixed
- Scoped visual comparisons report where the sampled differences concentrate. Bossy and Designer receive the same measured source evidence and retained surgery context. Rejected patch contracts preserve the exact error and retained reference hash.

### Verified
- Replaying the retained GEN-000051 candidate through local WordPress preserved the exact source ratios and verdict. Its largest differences concentrated near the heading and introduction at 1440 and 375 px.
- The offline Bossy matrix held 28 defective cases, accepted six controls and exercised four trust-boundary cases without paid calls.

## [0.12.12] - 2026-11-26

### Fixed
- Section and Full Page preserve a recoverable Bossy design review on the existing GEN after a transport failure. Recovery verifies the pinned assignment, source, design hash and prior costs, including conservative reservations for unmetered requests.
- Touch distinguishes detected control changes from the browser's automatic scroll. Agent handoffs carry the source archive, target identity, action, state, and limits of each observation.

### Verified
- A live continuation of GEN-000051 reached Bossy and Designer surgery without rerunning classification or initial design. The saved candidate passed the individual 768 px source check. Desktop, mobile and aggregate source approval remain open.
- A pinned BKKGO replay verified a local FAQ visible-copy change at all three widths. A 16-region inventory tracks source capture, conversion eligibility and acceptance separately.

## [0.12.11] - 2026-11-26

### Fixed
- Designer surgery measures per-width source, markup and height preservation before promotion. An incomplete candidate may retain a measured improvement while source approval remains gated.
- Developer's measured alignment repair recognizes a shared reference container across nested interactive content. Tabs panel identity and inactive copy remain protected during structural repair.

### Verified
- A bounded patch to GEN-000051 removed three invented links, preserved source copy at all three required widths and passed all 15 responsive samples. Source pixel difference at 375 px fell from 5.265% to 4.450%. Source approval remains open.
- Two native layout edits to GEN-000055 reduced mobile visual difference from 2.456% to 1.788%. The patched artifact passed native checks, six functional requirements, eight responsive samples and isolated Gutenberg edit, save and reload. Visual acceptance remains open.

## [0.12.10] - 2026-11-26

### Fixed
- The existing native plan recovery can close a fully emitted root block when exactly the outer array and object delimiters are absent. History records the before and after hashes. The complete compiler contract still applies.
- A frozen tabs design can bind a visible tab label through reciprocal ARIA IDs within its own widget. Developer receives the exact approved panel IDs and copy.

### Verified
- The archived GEN-000055 Developer response was recovered offline using the delimiter repair, two compiler field moves and two explicit identifier alignments. The result passed all six linked functional checks, eight responsive samples, and a real Gutenberg edit, save, and reload.
- GEN-000055 visual fidelity remains open. The native recovery measured 2.456% pixel difference at 375 px. The original live job was held and its original response and cost remain archived.

## [0.12.9] - 2026-11-26

### Added
- One Content Tabs interaction can link multiple requirements to explicit compiled-artifact checks. Design retains ordinary appearance and content checks. Native QA owns runtime focus, ARIA, styling and behavior.
- Chromium measures native tab styling and keyboard focus as part of the existing artifact-bound functional receipt. Each linked requirement receives proof from the same independently tested block.

### Verified
- Real WordPress and Chromium verified the multi-requirement receipt at all three required widths and blocked an injected missing keyboard-focus indicator. The contract rejects tests assigned to an unrelated quality dimension.
- The previous checkpoint's live follow-up detected 11 of 12 distinct faults, including one transport failure in the denominator. All 14 defective trials were held and all four controls passed.

## [0.12.8] - 2026-11-26

### Added
- Bossy receives browser-owned requirement verdicts before its first review. Work orders can explicitly test viewport centering and computed vertical padding.
- Scoped source diagnostics show expected and observed link inventories when they differ, including unexpected placeholder destinations.

### Verified
- With forced model approval, all 28 defective fixture/mode combinations held and all six valid combinations advanced. Real Chromium confirmed diagnostic evidence for a placeholder link when the captured source contained none.
- The prior live benchmark held all 14 defective trials and passed all four controls. The separate Through Our Lens production job held at source and design QA. Its saved Designer artifact and exact reported metering were retained.

## [0.12.7] - 2026-11-26

### Added
- Independent continuous responsive QA for the retained Gutenberg candidate and assembled Full Page draft. The browser samples required widths, intermediate sizes and breakpoint neighbors, then records findings and screenshot hashes.
- Browser-only recovery for pending native responsive QA. A supplemental receipt ties to the exact archived WordPress content and rendered preview. Other required gates and editor validation still apply.

### Verified
- Pipeline regressions detect overflow at 900 px, hold incomplete evidence, preserve the compiled draft and retry its browser check with zero additional model calls.
- The BKKGO archive extension to the deterministic benchmark held 26 of 28 scripted defect approvals. All six valid combinations passed. Live performance remains unmeasured on the upgraded workflow.

## [0.12.6] - 2026-11-26

### Added
- Section and Full Page can record an administrator-approved layout change at 768 or 375 px. The receipt pins the source checkpoint, exact widths, account and reason. Raw visual comparisons stay in History.
- Compact layout approval still requires source copy, links, assets, controls, paint, and text identity, plus ordinary browser and editor gates. Desktop retains its source comparison.
- The offline design-review benchmark now runs candidate-independent work orders with typed browser assertions for the existing hotel hero fixtures.

### Verified
- Disposable WordPress accepted a mobile reflow with explicit approval and blocked that same change without it. Tablet-only approval, a mobile paint change, altered source copy and a desktop authorization request were rejected.
- Section and Full Page replay resumed a partial page to a verified Gutenberg draft with all three patterns and no paid calls.
- An authenticated BKKGO capture replayed offline with zero pixel differences at all three widths. Three regions were eligible for conversion and 13 remained blocked by source preflight. Both adapters kept unverified artifacts from Pattern promotion.
- The offline design benchmark held 25 of 27 scripted defect approvals and passed all six valid fixture/mode combinations. Updated live model detection and escape rates remain to be measured.

## [0.12.5] - 2026-11-26

### Added
- Typed interaction classification in Bossy's work order and a separate implementation QA role with read-only tool access.
- Editable Content Tabs with local click and keyboard behavior, 2–6 panels and a compiler contract that preserves approved labels, headings, and copy.
- Exact-width Chromium functional receipts tied to the compiled artifact. Missing, changed or unsupported requirements hold completion while the draft remains in History.

### Verified
- On disposable WordPress, the native compiler and actual Gutenberg registry accepted two independent Content Tabs blocks. Gutenberg edited, saved and reloaded a panel. The WordPress preview passed interaction at 1440, 768 and 375 px.
- Chromium and PHP tests covered missing widgets, mismatched approved panel copy, unsupported external services and broken ARIA structure. A changed artifact could not reuse a passing receipt. The tests used zero live model calls.
- Captured-action mapping remains gated in the source adapters. BKKGO menus, carousels and external booking still need implementation and acceptance evidence.

## [0.12.4] - 2026-11-26

### Added
- Compact Bossy browser evidence for visible DOM identity, computed typography and paint, link destinations, keyboard reachability, fragment targets and accessibility findings.
- Requirement-level verification with stable evidence IDs, deterministic checks for literal text, element presence, local links and computed styles, and explicit handoff for implementation checks.
- A bounded selector and viewport for targeted inspection, with repeat-evidence rejection. The renderer can generate a supplemental overlap image without changing its pixel comparison.

### Verified
- Controlled browser and PHP tests cover missing visible elements, broken fragments, equivalent CSS colors and rejection of a model's approval when a browser-owned requirement fails.
- Live detection and escape targets remain unmeasured on this version. Authorized responsive source deviations and implementation acceptance remain additional work.

## [0.12.3] - 2026-11-26

### Added
- Intermediate viewport and CSS breakpoint-boundary probes on the existing Designer artifact.
- Application-owned responsive findings and a design approval gate for measured overflow, clipped text and sibling text collisions.
- Designer surgery records and rollback when a patch introduces responsive defects, removes measured component IDs or breaks previously passing source fidelity checks.

### Verified
- A real Chromium fixture passed 1440, 768 and 375 px while failing around 900 px. The new probes detected the failure.
- Pipeline regressions blocked a scripted false approval and retained the previous Designer artifact after a regressing patch.

## [0.12.2] - 2026-11-26

### Added
- A Bossy Touch tool for bounded click, hover, scroll, drag and resize against verified source runtime receipts.
- Checksummed response recording and isolated JavaScript replay from private capture artifacts, with browser egress sealed before the first gesture.
- Archived screenshots and structured state transitions with observed, inferred or unknown outcomes, carried as scenario references through the production handoffs.

### Verified
- The captured BKKGO 375 px menu opened using the recorded runtime and local-only Touch actions.
- Existing visual-only archives remain readable. Interaction inspection requires a new runtime capture when the old archive lacks recorded responses.

## [0.12.1] - 2026-11-25

### Added
- Opt-in SingleFile acquisition in the existing capture service with separate 1440, 768 and 375 px source and offline archive evidence.
- A guarded browser transport, immutable archive files, and application-validated receipt provenance through Section, Full Page and manually selected source handoffs.
- Real Chromium regressions for offline replay, divergent responsive trees, missing widths and mismatched saved HTML.

### Limitations
- Archives are static visual references. Interactive discovery and acceptance are separate checkpoints.
- The opt-in service requires Node.js 24 or later and the pinned SingleFile dependencies. The WordPress ZIP contains the receipt contract only.

## [0.12.0] - 2026-11-25

### Added
- Versioned Bossy work orders with section hierarchy, requirement IDs, evidence basis and observable acceptance criteria.
- A specification receipt that binds the original brief, pinned source evidence and required viewports to the Designer and Developer handoffs.

### Verified
- The shared production path carries one specification through design generation, review, native implementation and QA. Existing structured and legacy planning fixtures exercise the same entry point.
- The public release remains v0.11.1. Reference acquisition, Touch, continuous responsiveness, requirement-level verdicts and independent interactive acceptance are later checkpoints.

## [0.11.1] - 2026-11-20

### Added
- Native WordPress plugin update integration using published GitHub Releases and a verified `syndamorph.zip` asset.
- Automated release packaging with a stable `syndamorph/` root directory and forward-slash archive paths.
- This bundled changelog and release-note validation.
- A direct-`div`-text regression fixture, including mixed inline/nested content.
- Offline updater failure/isolation tests and local release-package validation.

### Fixed
- Preserve meaningful text nodes and line breaks inside `div` containers instead of silently dropping them.
- Preserve direct text appearing before, between, or after nested blocks.
- Keep adjacent Flex and Grid labels separated when converting them to editable paragraph text.
- Preserve links on imported images and logos using native Gutenberg Image blocks.
- Show a background image in the import preview when it belongs to the first Group.
- Preserve Columns margins in the import preview when a gap is also set.
- Reject malformed release metadata and unrelated/incomplete release assets without PHP warnings.
- Supply equal/older release metadata for WordPress's native no-update and auto-update controls without offering a downgrade ZIP.
- Validate release tag ancestry, dated changelog notes, and exact packaged files before attaching the update ZIP.

## [0.11.0] - Unreleased

### Added
- Native Gutenberg Columns conversion for simple 2–6 track CSS Grid layouts.
- Fractional widths for `fr` and percentage grid tracks; nested grid and flex layouts.
- Vertical alignment support for compatible columns.

## [0.10.0] - Unreleased

### Added
- CSS background images mapped to native Group attributes.
- Font weight and line height mapped to Gutenberg typography settings.
- Improved preview rendering of native Group background attributes.

## [0.9.0]

### Added
- ZIP package scan, preview and approved multi-page import.
- Relative CSS and image asset resolution; referenced-only media imports.
- Asset deduplication and best-effort rollback on failed imports.

## [0.8.0]

### Added
- Native spacing controls, font sizes, gaps and proportional column widths.

## [0.7.0]

### Added
- Native buttons, lists, tables, separators and formatted inline text.
- Experimental isolated JavaScript DOM capture.
