# AI Production Team upgrade

The v0.12 development series extends the existing Native Gutenberg production engine on `feat/secure-gateway-leasing-v1`. The experimental baseline was `82bfbd8`. Section and Full Page both schedule work through that engine. Completed and paused records remain in Production History, and stable v0.11.1 remains the published release.

| Owner | Deliverable | Evidence required to advance |
| --- | --- | --- |
| Bossy, classification | Versioned sections, requirements and acceptance criteria | Original brief and pinned source evidence identities |
| Capture service | Live source baseline and verified offline references | Matched viewport identities, immutable evidence, archive checksum, structural and visual replay results |
| Bossy, Touch | Component inventory, observed actions and replayable scenarios | Before and after browser state plus an evidence classification of observed, inferred or unknown |
| Designer | Responsive semantic HTML and visual states | Browser measurements at mandatory and intermediate widths, plus scoped repair transactions |
| Bossy, design validation | Requirement decisions and approved reference | Artifact identity and verified, failed, uncertain or not-applicable requirements with evidence references |
| Developer | Editable Gutenberg plan and interactive capability implementation | Approved design specification, relevant scenarios, compiler receipt and reversible repairs |
| Independent QA | Implementation acceptance | Fresh visual and interaction evidence, native serialization and Gutenberg round-trip |

## v0.12.0 specification checkpoint

The native work order now accepts a versioned classification with section identities, parent links, requirement IDs, scope, dimension, basis and observable acceptance criteria. Parents precede children. Requirements bind to a section or the page. The orchestrator computes a receipt over the normalized specification, original brief, pinned source evidence when present, and exact viewport widths. Designer, Bossy, Developer and implementation QA receive the same normalized requirements. History includes the receipt at assignment and approved-design handoff. A classification establishes work to verify. Approval authority remains with the application gates.

Archived list-based planning outputs can be normalized without inventing an evidence basis or verification status. Historical completed records remain readable. In-flight resume still checks the engine signature, and a checkpoint from an incompatible engine remains inspectable in History. This phase uses the existing history schema.

## v0.12.1 reference acquisition

SingleFile 2.27.3 runs inside the existing Node capture service with its dependencies pinned separately from the default installation. Section and Full Page use the same discovery path. Manual URL acquisition attaches a receipt when a section is selected. Capture remains deterministic and makes zero provider calls.

The service renders each required width, scrolls to load deferred content, waits for stable layout, text, fonts and images, and pins the original DOM and full-page PNG. SingleFile archives that browser state. A fresh browser context blocks page JavaScript and external requests, then verifies matching geometry, text, computed typography, loaded fonts and visible image dimensions. The pixel gate uses full-page PNGs with a 1.5% maximum difference. The three verified views are required as a set. Original source evidence remains the authority for later comparisons.

The archive browser uses a dead proxy, with a service-controlled read path for allowlisted public resources. The transport pins validated IPv4 addresses, strips cookies, enforces size and time limits, and reuses the actual responses fetched by that capture. The archive path rejects redirects and additional document navigation. Use the canonical source URL and add only required resource hosts to the existing allowlist.

WordPress validates the receipt’s exact widths, source URL, hashes and offline verification before admitting it. Bossy receives compact receipt metadata with the original source evidence. The HTML, original DOM, screenshots, and measurement files stay in the capture service’s private volume under the receipt ID. Legacy history records and default source capture still use their existing format.

```sh
docker compose -f compose.yaml -f compose.reference.yaml up -d --build backend wordpress
```

The Compose override installs the optional dependencies, enables both ends of the receipt contract and mounts the archive volume. The default Compose configuration leaves archive acquisition disabled. SingleFile is an AGPL-3.0-or-later dependency of the opt-in capture service and is absent from the WordPress ZIP. [SingleFile version and license](https://github.com/gildas-lormeau/single-file-cli/tree/v2.27.3)

Monolith remains an alternative for a future capture-service evaluation. The standalone comparison does not establish equivalent rendered browser state. This implementation installs only the selected SingleFile capture dependency.

The authenticated BKKGO capture returned HTTP 200, 16 regions, and receipt `d92c36a68a0fe3ac4fbbc6f7716b8cc32ac5deab5eb66b3933021f6d94e65515`. All three offline views had zero changed pixels, zero external requests, and all five live font faces. Desktop and compact source DOM hashes differed. A separate mobile attempt timed out loading a required React script from the CDN and returned incomplete evidence. The verifier rejected it before storing a verified set.

## v0.12.2 Touch checkpoint

Bossy can request `playwright.touch` during source classification when the work order has a verified runtime receipt. The existing registry supplies the archive identity and allows one inventory request and a bounded follow-up within the job's existing tool limits. The browser discovers target IDs. Agents provide no URLs, selectors, request bodies or executable code.

Reference capture records the public HTTP responses already used by the live browser. Their hashes bind the response inventory to the archive receipt. Touch boots a fresh Chromium context using only those responses, verifies the live-rendered copy against the corresponding pinned width, and seals the request route before any action. HTTP, document navigation, WebSockets and downloads remain unavailable during interaction. The action guard also rejects links that leave the document, sensitive controls and forms. It uses a maximum of five gestures.

An observed result records a browser state change with before and after screenshot hashes, measured target state and a replayable action. A link can carry an inferred destination without testing navigation. Unavailable or unsafe behavior remains unknown. Remote effects are always unverified. Raw screenshots stay in private History. Designer, Bossy and Developer receive compact scenario references. QA will independently replay implemented behavior during its later checkpoint.

On BKKGO at 375 px, `Open menu` exposed `Close menu` with the isolated recorded runtime. The authenticated test used archive `6efe86844a718453c4c9ff87d577fcfff25e44d6f53e338fdbafa0295f5290fd`. The browser fixture also verified that local state can change while GET and POST attempts are blocked. A prior prototype that booted from the live site rejected mismatched copy. Touch now requires recorded responses, including for replay of a saved scenario.

## v0.12.3 Designer responsiveness checkpoint

Designer uses the 1440 px source to anchor the design and targets continuous layout down to 375 px. Application-owned browser probes combine the mandatory and explicitly requested design widths with fixed interval samples and widths on both sides of declared media boundaries. The probe planner supports literal pixel, em and rem boundaries. Its `em` and `rem` estimates use the fresh renderer's 16 px initial font size. More than 32 required widths returns a bounded diagnostic. Existing inspection recovery leaves visual QA pending when a required viewport is unavailable.

The application checks document overflow, text outside the viewport, and overlapping sibling text lines. The responsive receipt reaches Bossy and cannot be waived by an approval. A scripted repair that introduces findings, removes observed element IDs or breaks a previously passing pinned source result restores the prior reference. Candidate HTML, hashes and observations remain in History. The current probe suite samples the range. More complex component behaviors and authorized source deviations require explicit requirements and dedicated checks.

The Chromium regression deliberately introduced overflow only from 899 through 901 px. The design passed all three mandatory checks, with the extra probes catching the failure at 900 px and testing both boundary neighbors. Scripted orchestration tests verified rejection and rollback on the actual shared pipeline entry point.

## v0.12.4 Bossy validation checkpoint

Each structured requirement can carry a bounded browser predicate for literal text, a visible element, a keyboard-reachable link and local target, or a computed style. The browser owns the verdict for these predicates. Bossy's other design claims cite the available per-viewport or element evidence IDs. The orchestrator holds failed or uncertain design requirements. A justified not-applicable result is available for standard criteria, and functional or native implementation requirements that require a compiled artifact are deferred as uncertain to implementation QA. Archived list-only classifications retain their original contract.

The compact evidence includes tags, DOM IDs and classes, computed typography and paint, link and fragment data, layout, accessible-name context and bounded axe findings. Targeted inspection can select a single tag, class or ID and one of the exact required widths. The design loop rejects a diagnostic that supplies the same observation again for the same artifact.

The renderer's optional overlap image distinguishes matching pixels, source-only extent and Designer-only extent. Overlapping pixels with different paint are shown separately as unresolved. Tests verify these extent categories and show that the overlay leaves the numerical fidelity score unchanged. A localized pixel difference can indicate paint, geometry or content. It supplies no proof by itself about a particular element or behavior.

The historical live audit remains the detection baseline. These deterministic tests establish rule behavior for the fixtures. Intentional source deviations still need an application-owned authorization path. Pinned fidelity thresholds continue to apply.

## v0.12.5 Developer and independent QA checkpoint

Bossy classifies an interactive component as native, reusable, new special block, external, or unsupported. Each entry names the corresponding functional requirement and stable component ID and states the required behavior. The normalized specification reaches Designer, Developer and implementation QA. A native local link with an explicit browser predicate can be checked against the compiled candidate. An unclassified functional requirement or an external service without verifiable behavior holds acceptance.

Content Tabs is the first reusable special block. Designer supplies the approved panel identities and literal content. Developer uses the same IDs in the persistent native plan. The existing compiler serializes the registered `syndamorph/tabs` block, and the existing surgical work order governs plan edits and rollback. Gutenberg provides editable panel labels, headings and body content. WordPress renders buttons and accessible tab panels with a local script, independent state, and support for arrow keys, Home and End.

Implementation QA receives the compiled candidate and has separate agent authority and read-only tool grants. The application also checks the approved panel copy against the compiled plan and runs an isolated Chromium check at each requested width. The check exercises each tab, keyboard navigation, active panel visibility, two-way ARIA references, and independent state across widgets. Its receipt pins the exact compiled content hash. A failed or missing receipt prevents completion and Pattern promotion. Draft artifacts and measurements remain available to review.

The isolated WordPress regression compiled two instances, edited and saved a panel in Gutenberg, reloaded it, and verified frontend click and keyboard behavior at 1440, 768 and 375 px. Tests also cover duplicate IDs, omitted widgets, changed inactive panel copy, broken ARIA structure, unsupported external requirements and stale artifact hashes. The runtime has no remote dependencies. Captured site actions still enter the source adapters' existing preflight gate. A captured BKKGO menu or booking workflow requires its own native implementation and functional evidence.

## v0.12.6 source layout authorization

Before production starts, the Section and Full Page controls can authorize a responsive layout change at 768 px, 375 px, or both. The administrator supplies a reason. The server binds the authorization to the current source scope, selected checkpoint identities, and account. A fresh capture requires a new authorization. Manually paired viewport variants keep their strict source policy.

Chromium still captures and compares every required view. Exact copy, links, assets, controls, and responsive QA remain required. Pinned background paint and text family, weight, and color must match where layout changes are authorized. Missing protected evidence holds the gate. Desktop keeps its pixel and height conditions. An assembled page requires the same authorization and artifact hash for each accepted region, the source section order, and its own browser and editor evidence.

The WordPress fixture retained the original mobile visual and height mismatch in the receipt and accepted the explicitly authorized reflow. The same candidate failed under strict or tablet-only settings. Changing its paint or copy still failed. The Section and Full Page regression exercised an isolated native conversion with three verified patterns and a 12-block assembled draft. These checks used zero live AI calls.

Both adapters admitted the captured BKKGO source archive `5d804f9fb95df28cd4367931611790fc3821a0de1822c945531eb2d002327523`. Full-page offline replay had zero changed pixels and zero external requests at 1440, 768 and 375 px. Production preflight found three eligible regions out of 16 and held 13 for missing variants, unsupported controls, assets, links or placement. Both scopes pinned the same Through Our Lens evidence, rejected a text-only visual probe and withheld unverified Pattern promotion. No BKKGO page was accepted for publication.

## v0.12.7 independent responsive QA

QA runs the responsive probe planner on the retained Gutenberg preview and on the assembled page draft. The intermediate and breakpoint samples look for horizontal overflow, text clipping, and sibling text collisions. The receipt records exact sample widths, screenshot hashes, findings, and the SHA-256 of the content and preview. A measured defect or missing receipt holds the native quality gate.

When required browser evidence is missing, the existing History reinspection action can verify the saved native preview deterministically. The original job result, artifacts, and token records are preserved. A recovered receipt supplements only an exact matching artifact and preview. Editor verification and independent source, functional and visual gates still apply. The scoped fixture verifies that a pending receipt blocks promotion, and a successful browser-only retry restores editor review.

## v0.12.8 measured review evidence

In v0.12.8, Bossy receives the measured verdicts alongside the evidence. Explicit work orders can also measure viewport centering and vertical padding. Scoped source review includes the expected and observed link inventory when they differ. Deterministic replay held all 28 scripted defect approvals and passed all six valid combinations.

## v0.12.9 native interaction proof

A tab work order now links each requirement to an application-supported check. Design still validates its appearance, hierarchy, responsive sketch and exact panel content. Compiled-artifact QA owns registration, local state, click, keyboard, native styling, ARIA and visible focus. The bounded proof list must match the interaction scope and the declared requirement dimension. A final receipt records the linked requirements only after all exact-width browser checks pass.

A disposable WordPress test verified three linked requirements for one block, independent state across widgets, visible keyboard focus, selection styling and rejection of an injected missing focus indicator. Earlier live tab jobs exposed the ownership gap and an overly strict planning shape. Their archived attempts remain distinct from the controlled WordPress proof.

## v0.12.10 archived Developer recovery

GEN-000055 used live planning, design, design review, a targeted design correction and Developer generation. The approved sketch assigned six tests to implementation QA. Developer returned every block and literal value but omitted the outer array and object closers. The new decoder can restore only those two delimiters when the result is one complete root group. All schema and QA conditions remain enforced.

Tabs design proof now also recognizes reciprocal in-widget ARIA references for a visible label. In the offline recovery of the archived response, the existing normalizer moved two layout properties under style and the two panel IDs were explicitly aligned to the frozen design. No copy was changed in recovery. Artifact `44d8561fb20de9480cf610e60ec635de20b9b535592109f4127060ac3ac2cf9a` passed all six linked tab checks, all eight responsive samples, and Gutenberg edit, save, reload, and frontend interaction at 1440, 768 and 375 px. The recovered artifact still measured 1.047%, 1.491%, and 2.456% visual differences at those widths. Its visual acceptance remains open and the original job retains its failure status.

## v0.12.11 source and native surgery checkpoint

Designer surgery now compares each required source viewport before retaining a scoped edit. Loss of a passing visual or height check, verified responsive layout identity, literal copy, exact links, assets or functions rolls back the operation. Partial improvement can be retained while the existing source approval thresholds remain enforced. The surgery receipt includes the before and after measurements.

Developer's deterministic Stack repair uses the nearest shared measured reference container when matching visible text is nested across several branches. This lets the same repair reach a constrained Group that contains a native Tabs block. Structural surgery also protects the exact Tabs component and panel records, including inactive copy.

| Archived job | 1440 px | 768 px | 375 px | Verified after repair |
| --- | ---: | ---: | ---: | --- |
| GEN-000051 | 1.875% → 1.796% | 2.668% → 2.100% | 5.265% → 4.450% | Exact copy and links at all mandatory widths. All 15 responsive samples passed. |
| GEN-000055 | 1.047% → 0.641% | 1.491% → 1.093% | 2.456% → 1.788% | Native blocks, all six functional requirements, eight responsive samples, and Gutenberg edit, save, reload and keyboard operation. |

GEN-000051's archived reference used placeholder anchors for source labels that had no link or control behavior. The bounded edits restore label semantics and adjust scoped typography, borders, spacing and the compact grid gap. The archived source uses embedded Beausite faces. Reproducing those fonts through the native pipeline still requires evidence and a supported asset contract. Candidate SHA-256 `1de1e06d72bfd891a9ddbb433268b776baeb70847cb44b7b0c7b826404f83755` remains below acceptance. Its archived final review transport failure is still open at this checkpoint.

GEN-000055's native Stack allocated its constrained Group at the start edge and introduced extra nested padding. The measured repair changed only the Stack's `justifyContent` and the column's padding. Candidate SHA-256 `06d8d11cda916c7991cd8a6857cebe9424253e5ca22d6f61c32b25ead01131ac` was tested from isolated WordPress draft 41. The frozen sketch renders shorter browser-default tab controls than the verified native controls. Visual acceptance remains open. These archived replays used zero new model calls and created no publication.

## v0.12.12 transport recovery and Touch evidence

When an existing scoped Bossy design review ends in a transport failure, the scope can resume that review from the same GEN. It checks the exact assignment receipt, original source and capture checkpoint, design hash, model and rates. Recorded usage and conservative reservations carry forward under the original job and parent scope limits. A resume neither reclassifies the source nor repeats initial Designer generation. Normal approval and independent QA still determine whether Developer can start.

The live Through Our Lens continuation kept GEN-000051 under REF-000028. The first resumed Bossy review requested a browser check. Designer surgery improved the retained candidate to 1.797%, 1.451%, and 4.239% at 1440, 768, and 375 px. A subsequent height-only change measured no improvement and rolled back. A later Designer response failed the bounded patch contract. Candidate `7dc8bf6f33e88157bfb94cc05009a6ff54b065f6026c47e140c7ce5e273e1bdd` stays in History. Developer did not start because the required source gate remains closed. The continuation added 10 metered calls at an estimated USD 0.042555. The job has 21 completed calls, USD 0.083855 in metered estimates and a separate USD 0.011048 reservation for the original timeout.

Touch now requires a measured result from the requested action. Scrolling into view as a side effect of a click is insufficient. A recorded action can identify a target state, appearance, local fragment, hidden target or visible copy change. Scroll and resize retain their own explicit checks. The downstream receipt carries the exact source archive, target ID, action status, state and evidence fingerprints. External side effects remain unverified. [BKKGO region inventory](production-pilot-bkkgo.md#production-reliability-region-inventory)

The isolated WordPress regression recovered a timed-out design review on the existing GEN and rejected an invented-link patch before a provider call. A separate Section and Full Page fixture saved three editable Patterns, recovered its partial draft, and passed editor verification and first-fold, full-extent, and continuous responsive QA. The fixture used 34 mocked model responses and zero paid requests. Its local success is independent of the live BKKGO job outcomes.

## v0.12.13 source mismatch localization

The renderer now identifies up to six cells with the most changed samples in a three-column, eight-row grid over the compared section extents. Each cell reports its position, sample count and changed count. Bossy and Designer receive the same comparison. A CSS diagnosis still requires a measured source box or style fact. The existing source thresholds and sampling stay in use.

The retained GEN-000051 candidate reproduced 1.797%, 1.451%, and 4.239% through the updated renderer. At 1440 px the second row contained 1,180 of 1,294 changed samples. At 375 px the first two rows dominated, with an additional discrepancy around the bottom label. The source and candidate extents agree at each width. This directs investigation to typography and spacing in the intro and footer.

An offline Chromium trial loaded only the Beausite faces embedded in the pinned archive. The measured difference fell to approximately 0.61%, 0.92%, and 1.79% at 1440, 768, and 375 px. This indicates a font contribution while leaving further source differences and the production font-delivery contract open. The trial stayed outside the production approval path.

GEN-000055 now retains the patched artifact and separate native, interaction, editor, responsiveness and visual receipts on the original History record. Prospective Tabs typography corrections improved some measurements in a preview-only diagnostic. The saved artifact was independently validated against the original approved sketch. Its visual gate remains open.

The v0.12.8 offline matrix ran with the archived BKKGO negative control. All 28 deterministic defects were held, six controls advanced, and the four trust cases retained their expected guards. This replay used zero paid calls. The historical live statistic remains the recorded 11/12 distinct detections, 14/14 holds, and 4/4 clean passes.

## v0.12.14 saved planning and paragraph evidence

The live SIP & DINE Section exposed a 240-character limit on browser checks. Its 253-character paragraph was rejected before Designer started. The parser now accepts bounded paragraph-sized text and retains a valid optional selector. The renderer measures the literal text within the selected element, including longer or nested copy. Incomplete selected-element evidence stays uncertain. A matching phrase outside an explicitly selected paragraph cannot satisfy the check.

The initial GEN-000056 result contained one complete Bossy planning response, with SHA-256 `916a81935ec0da765daf0eb4258c521b8c9901bb2e8b0b728986bde27fac227e`. The exact archived response now validates and verifies the intro paragraph at 1440 px. Scoped recovery can continue from such an archive when the prior attempt ended at the check parser, the work order now validates, and pinned source, pricing and usage also match. Recovery uses the original GEN and budget.

The live continuation ran Designer and Bossy on the same GEN after the saved planning response. Its retained design `1652f3882745d1238fd6d8a4d37c30de8f4709c0fbb9590a49d85b0a134ff12e` passed exact source markup. Visual differences measured 7.406%, 56.881%, and 46.662%. Bossy requested a design decision for the compact clipped card strip and reported missing Beausite faces and desktop typography collision. Developer did not start. Three metered requests, including the initial planning call, cost an estimated USD 0.018427.

The current GEN-000055 recovery candidate `d6a34bf308973d05e47a9f458d56baa7485d04ba81eef8357b5ff5b6d1a454b4` removes a theme default 19.2 px sibling gap at 375 px in favor of the existing 16 px heading margin. The earlier 48.5 px tab control height remains intact on mobile. All six functional requirements and eight responsive widths passed, together with repeat Gutenberg edit, save, reload and keyboard operation in isolated draft 50. The pixel difference is 0.641%, 1.093%, and 1.753%. The visual gate is still open.

The actual BKKGO Full Page adapter read all 16 pinned regions in REF-000030. Only three were eligible. Assembly returned `sm_scope_empty` and left WordPress without a new BKKGO page because none had a verified native artifact.

The isolated Section and Full Page regression verified both original-GEN recovery paths with 38 mocked model responses and zero paid requests. The fixture's full and first-fold screenshots matched at all three mandatory widths. All eight responsive samples passed, and the same draft persisted through partial assembly, section recovery, and full Gutenberg verification.

## v0.12.15 horizontal source interactions

Source capture now recognizes horizontal scrolling without depending on a button, role or ARIA label. A visible element qualifies for inspection when it has `overflow-x: auto` or `scroll` and measured content wider than its scroll area. A passive CSS clip does not qualify on that evidence alone. Scoped conversion still requires a supported implementation and independent functional tests.

Touch binds the gesture to a discovered target at the pinned viewport and records its starting and ending `scrollLeft`. Vertical scroll from locating the element does not prove horizontal behavior. The browser remains network-isolated during the gesture.

In the BKKGO archive, SIP & DINE moved from 0 to 594 px at 768 px and from 0 to 280 px at 375 px with the same compact target ID. Exclusive Offers moved from 0 to 213 px at 375 px. Through Our Lens had no discovered horizontal control at any required width. The archived 3/16 preflight remains preserved. With the additional measured requirements, only one of those three is eligible for the current compiler contract. No BKKGO region has full native acceptance.

## v0.12.16 scoped design decision hold

A scoped `design_review_required` decision now displays as **Design decision pending** with Bossy's archived reason. The held scope keeps its source, candidate and usage. A paid retry receives `sm_scope_design_decision`. Other Full Page regions remain independently runnable under the existing scope budget.

The guard was checked against the actual GEN-000056 record. Its previously verified Designer artifact and original costs remained intact. The retry consumed zero model calls. SIP & DINE still requires a native scroll-strip capability, source typography and visual approval before Developer can start.

The isolated Section and Full Page browser regression passed with 43 mocked model responses. It verified that a pending design decision keeps its region held while another region runs and completes its own QA. The ordinary Full Page fixture also completed partial-to-full assembly, native block validation, and full-extent, first-fold and intermediate responsive checks.

## v0.12.17 native scroll strip capability

Designer can describe a classified horizontal scroll strip with explicit reference and card markers. Developer can use `scrollStrip: true` on a nested core Group with its approved interaction ID, `style.visibility` set to `mobile` or `compact`, and two to six direct editable Group cards. The shared stylesheet follows the measured BKKGO layout with an 80% card basis, a 12 px gap, 20 px inline-end padding, and mandatory start snapping. WordPress supplies a focusable, named group when it renders the block. Layout surgery preserves the direct card boundaries.

The isolated renderer uses native wheel and arrow-key actions from separate initial states with document scripts disabled. The functional receipt checks the first and last positions, every card, visible focus, exact viewport visibility and ordered copy against the approved reference. Unclassified strips, clipped substitutes and changed card copy fail the gate. Responsive checks retain document overflow and inner clipping failures while recognizing reachable text in a measured local scroll area.

The pinned BKKGO capture supplied three SIP & DINE cards and two Exclusive Offers cards, including the empty second offer card. Both compiled into an isolated WordPress draft and passed native behavior and exact ordered card copy at 1440, 1024, 1023, 768, 600, 599 and 375 px. The draft rendered zero, one and two active strips at the three mandatory widths respectively. A card edit survived Gutenberg save and reload with all 13 blocks valid. The draft establishes the reusable block capability. Source admission still needs an exact captured-control and Touch binding before either region can enter live source acceptance.

## v0.12.18 source-bound scroll strips

Responsive capture records a scroll profile only for a local, left-to-right row of two to six ordered cards matching the proved native geometry. It pins the target ID, card count and ordered card copy at the original widths. Unmatched, desktop-only or mixed controls remain blocked. Section and Full Page use the same admission rule and require a verified SingleFile archive with recorded runtime.

The engine replays each active strip before Bossy planning. The exact archived target must move from its initial position with matching viewport, source copy, and before and after evidence. A missing or uncertain replay records a Pending diagnostic, including the width, target, archive and reason. No provider request starts for that region. Bossy must classify the captured ID as a native horizontal scroll interaction. Designer and Developer receive the measured visibility, card copy and replay evidence. Independent source checks compare the active component and ordered card fingerprint at each mandatory width, alongside the existing visual and functional gates.

Offline replay of all 16 pinned BKKGO regions restored eligibility for SIP & DINE and Exclusive Offers. SIP & DINE moved 594 px at 768 px and 280 px at 375 px. Exclusive Offers moved 213 px at 375 px. The total is three eligible regions out of 16, with 12 complete sets of mandatory-width evidence and zero accepted regions. This checkpoint used no paid calls. [BKKGO region inventory](production-pilot-bkkgo.md#production-reliability-region-inventory)

## v0.12.19 installed native typography

The production compiler accepts `style.fontFamily` for a local WordPress preset on Group, Heading and Paragraph blocks. The saved block stores the preset slug. WordPress applies its own theme or Font Library rules in the editor and frontend. Designer receives the installed family names. Developer receives their preset slugs. Isolated inspection supplies only referenced local theme or Font Library files, validates their format and byte limits, and requires Chromium evidence for the exact supplied stylesheet. Font bytes stay out of the model payload, generated draft and plugin package. Hosts with different fonts must install compatible presets before relying on the same appearance.

In an isolated WordPress fixture, the exact recorded BKKGO Beausite faces brought the retained GEN-000051 sketch to 0.636%, 0.924% and 1.825% difference at 1440, 768 and 375 px. A targeted mobile heading edit removed an artificial 280 px cap from the 335 px source column. The resulting candidate `8b4ddcaf85ef443783e10c84edc0c9829c7996a868852fc417396fa7a753619c` measured 0.636%, 0.924% and 1.458%. The three heights, literal content and links were preserved. Fifteen responsive samples produced no findings. A separate footer line-height adjustment increased measured height and regressed tablet fidelity, so that candidate was discarded. The 1.006% average still exceeds the unchanged 0.5% gate. The original History candidate remains held. This work used no paid model calls.

A separate native fixture retained its Manrope and Fira Code presets through Gutenberg edit, save and reload. The private WordPress draft rendered both fonts at 1440, 768 and 375 px. This verifies the installed typography contract. GEN-000051 still needs source visual and downstream native acceptance.

## v0.12.20 pinned section screenshot framing

Source sections were photographed at their document offsets, then compared with a candidate photographed at the top of an isolated page. Chromium's raster phase and the JPEG settings introduced differences even when text boxes and decorative tiles agreed. Pinned Section comparison now reuses the recorded origin and the capture encoder. The renderer measures actual layout and accessibility first, checks that the temporary translation preserves element geometry, and verifies the exact frame with WordPress. Missing or conflicting frame evidence stays pending. The per-viewport 1.5% and aggregate 0.5% limits are unchanged. The framing regression checks include a genuine color change and an invalid frame.

On GEN-000051, the installed archive fonts and four bounded edits repaired the narrow heading cap, underline offset, footer line height and eyebrow line height. The sketch `da5c3f9658a247b57eadb0d33086259a544fceebd35abe7bccab05fb157e2987` passed source fidelity at 0.310%, 0%, and 0%, and all 15 responsive samples had no findings. It preserved source copy and its verified absence of links. The original GEN resumed for two metered Bossy reviews. The second review approved this exact sketch. The deterministic handoff held four archived requirements. The introduction style assertion selected every paragraph instead of the intro, and the heading hierarchy, host contrast and link accessibility checks still need resolution. Developer did not start. The estimated job cost is $0.096130 plus a prior unmetered reservation of $0.011048, within the original $1 cap.

## v0.12.21 source-backed work-order recovery

When a source-passing Designer checkpoint is held on its work order, the existing resume operation can apply up to five explicit corrections. It first rechecks the same pinned source and exact saved reference. A style check may narrow a repeated tag to one verified literal text without changing the expected value or widths. A work order can recast an unsupported link claim as a literal label only when every required source view verifies zero anchors and controls. The ordinary content, interaction, accessibility and visual gates remain required. History stores the original and revised specification receipts and revalidates the revision if review recovery is needed again.

The original GEN-000051 retained the exact sketch and the old work order. Five verified corrections narrowed the introduction check and removed the claim that plain source labels had destinations. Bossy approved the revised design in one metered review. The corrected checks passed. The unchanged host heading and contrast requirements kept the Developer handoff on hold. The job estimate is $0.103070 plus its earlier $0.011048 reservation.

The same sketch rendered in a private disposable WordPress Page preview. The theme emitted the Page H1 before the section H2, the background under the visible text was white, and the minimum measured contrast was 6.519:1 at 1440 px and 12.635:1 at 768 and 375 px. This measurement applies to the hosted sketch. The production gate still needs evidence for the eventual native draft, and no Pattern was saved from the fixture.

## v0.12.22 WordPress Page accessibility and Developer recovery

Two executable checks bind the source-backed Section work order to the configured WordPress Page title level and theme background. Chromium measures the reference and native artifact across every required viewport in that context. A mismatched heading hierarchy or contrast below 4.5:1 fails. Unsupported background composition or missing viewport evidence remains pending. Completion binds the receipt to the precise native artifact, preview, template and global stylesheet. Pattern save rechecks the native fragment in the current Page context. A different destination still needs its own frontend verification.

If Developer transport fails after design approval, the original job can revalidate the saved work order, exact sketch and requirement checklist, then retry Developer directly. The existing reservation and metered usage carry forward. The original agent decision stays in History and the application logs the revalidation separately.

GEN-000051 reached Developer on the original record after the corrected Bossy checklist and the two browser-owned host checks passed. The first Developer request timed out. The original job resumed at Developer with the same reference `da5c3f9658a247b57eadb0d33086259a544fceebd35abe7bccab05fb157e2987`. Its retained native candidate `17229be4791361561fc5b78ae0f7fa35a6a663ee04723d24d3dcdfcd60f06502` passed compilation, source copy and links, continuous responsiveness and the independent host checks. All 40 native blocks were valid in a private Gutenberg draft, and an edited paragraph survived save and reload. Source visual differences were 31.278%, 41.897%, and 33.896% at 1440, 768 and 375 px. Core Columns stacked the compact tiles and the desktop header and grid also differed. Bossy reported an engineering hold because the remaining changes exceeded the assigned style repair scope. The candidate remains uncompleted and was never saved as a Pattern.

This checkpoint logged five new metered calls estimated at $0.030929 and one timed-out Developer request with a $0.010752 reservation. The original job estimate is $0.133999 plus $0.021800 in total reservations, under the $1 cap.

## v0.12.23 native decorative Grid recovery

The native compiler supports two to four equal Grid columns for empty, painted decorative Groups. The existing Developer repair operation can convert a rectangular Columns matrix after a structural assignment explicitly authorizes the matrix and every affected child. It retains each tile ID and paint and preserves the visual row order. WordPress and Playwright use the same square sizing rule. The responsive fixture verifies four desktop columns, two compact columns, and the placement of the paragraph after the final tile row. Style-only authority, incomplete child authority and content-bearing tiles are rejected.

GEN-000051 has an append-only private recovery event with candidate `b8011dda65d59b6b74bbd11dd8d2a1b952e14c1bc6500f7ae4d616660c92db03`. The improvement used the approved Designer reference and the original pinned source. The independent source comparison recorded zero regressions, source copy and links passed, and all 12 responsive samples and the WordPress Page accessibility checks passed. A private Gutenberg draft preserved both native Grid groups and all 34 valid blocks after editing a paragraph, saving and reloading.

| Width | Prior pixel difference | Grid pixel difference | Archived height | Grid height |
| --- | ---: | ---: | ---: | ---: |
| 1440 px | 31.278% | 9.364% | 1031 px | 1035 px |
| 768 px | 41.897% | 1.032% | 1713 px | 1713 px |
| 375 px | 33.896% | 3.100% | 1010 px | 1010 px |

The source visual gate still holds the job and no Pattern was saved. The final production record and the approved Designer checkpoint are preserved. The zero-call recovery left the ledger at $0.133999 estimated spend and $0.021800 reserved.

## v0.12.24 native Flow and visible typography matching

The desktop Grid wrapped beside the header when WordPress stretched a vertical flex Group. Using native Flow keeps the header and Grid in document order. A native Column width and bottom alignment preserve the desktop header. Explicit letter spacing removes the host theme’s inherited tracking from the pinned compact text. Browser measurements of hidden breakpoint variants no longer generate typography findings against visible content. Archived samples without a visibility field retain their prior matching behavior.

GEN-000051 retained native candidate `d4e71d2effed88b7a296e3b7635c3bede408e33761793c8cba6b26098bedad2c`. The pinned source comparison passed at 0.382%, 0%, and 0% at 1440, 768 and 375 px. All three section heights matched the archive. The approved Designer-to-native comparison measured 0.099%, 0%, and 0%, with equal copy and no deterministic implementation findings. The WordPress Page heading and contrast checks and all 12 responsive samples passed.

The exact candidate was opened as a private Gutenberg draft. Both Grid groups and all 34 blocks remained valid after a paragraph edit, save and reload. The append-only recovery record retains the tested artifact and scoped evidence. The existing job still needs an independent implementation decision on this exact artifact. It has no Pattern, and this checkpoint added no AI calls or provider spend.

## v0.12.25 review-only recovery and first BKKGO Pattern

The Section resume action binds a private native recovery checkpoint to its previous artifact and approved Designer reference. It recompiles the retained plan and checks the exact block hash. Required deterministic source, responsive and host evidence run before the Validator call. A held review remains on the original job and retains each decision and the provider ledger. The editor and Pattern gates apply to the newly reviewed artifact.

GEN-000051 used a native Row for the two desktop labels. Chromium measured a 24 px gap and verified that neither label had a destination. The desktop checkerboard stayed in its verified row order. The required minimum-height clamps resolved to 324 px, 362 px, and 165.5 px at the three mandatory widths. Samples of the two-pixel compact rule matched in the pinned earlier captures, the approved design and the native preview at 768 and 375 px. The section root stayed transparent.

The Validator approved `58be9945bcdab349fb9d33ae107c3f51719052d7c0032338bb30854081a77a45` with no open issues. Native source differences measured 0.310%, 0%, and 0% at 1440, 768 and 375 px. The approved Designer-to-native comparison measured 0% at each width. WordPress Page accessibility and all 12 responsive samples passed. The exact artifact opened as a private draft in local Gutenberg and all 35 blocks remained valid after a paragraph edit, save and reload. The original job then passed its editor receipt and saved private, unsynced Pattern 64 in the local WordPress installation with the same content hash.

Three implementation reviews used an estimated $0.016709. GEN-000051 ended with 32 completed calls, $0.150708 in estimated spend and $0.021800 reserved. This checkpoint used its previously approved Designer artifact and retained native plan. No page or release was published.

The current BKKGO archive still inventories 16 regions with three eligible. The Section run accepted Through Our Lens. The Full Page record contains the same pinned Through Our Lens source digest and still has no accepted region attached. GEN-000055 still measures 0.641%, 1.093%, and 1.753%. Its narrow reference uses 21 px browser-default controls, and its retained native controls measure 48.5 px. The native panel typography and spacing also differ. The retained interaction tests and mobile target size remain part of the next repair.

## v0.12.26 shared built-in Tabs styling and GEN-000055 completion

The GEN-000055 work order expressly requires built-in Tabs styling. The frozen reference used 21 px browser-default buttons. The native block used larger targets and inherited panel typography from the host theme. A native-only style trial that kept targets at least 44 px tall still measured 1.698% at 375 px. The recovered reference now carries the registered stylesheet scoped to its existing Tabs marker. The only native-plan edit opts that same Tabs block into the standard appearance. Panels, identifiers, active state, local behavior and surrounding layout are preserved. Existing Tabs without an appearance attribute retain their theme-inherited styling.

The old reference and native artifact remain in History. Bossy reviewed reference `72c76c5213e586921c5f8108ad086f513da33a0cb436519b764e88d0f18a93a3` and the Validator approved native artifact `de64d77cfd7031c4c79e27fa6381f7d99c8e7fa9be68b5f817d27e4ef91cf476`. The exact comparison measured 0% at 1440, 768 and 375 px. Native controls measured 45.2 px at 375 px. All six functional requirements and eight responsive samples passed. Gutenberg kept all four blocks valid and retained the appearance and edited panel text after save and reload. The private editor fixture also verified click and keyboard behavior on the WordPress frontend at all three required widths.

GEN-000055 is complete in the original History record and its approved content is saved as unpublished local draft 65. The correction reused the archived work order and native plan. It used two review calls totaling $0.006613 and no new Designer or Developer generation. GEN-000055 closed with eight completed calls and $0.023836 estimated spend.

## v0.12.27 verified Section reuse in Full Page

The Full Page adapter can attach an already accepted unsynced Pattern to an untouched ready region. The `reuse_section` action takes a region ID and the saved Section scope ID. It checks the source URL, archive and exact region capture, the work order when configured, and the original editor and QA receipts. It re-renders the Pattern against the Full Page region before recording the placement. A repeat returns the existing placement. A changed capture, Pattern or layout authorization requires fresh validation.

BKKGO Full Page REF-000030 reused private Through Our Lens Pattern 64 from Section REF-000028 and GEN-000051. Fresh region fidelity measured 0.310%, 0%, and 0% at the three required widths. It assembled unpublished draft 66 with 35 blocks, one of 16 ordered placements, and zero new provider calls. The exact assembled content was editable in a disposable Gutenberg draft and survived save and reload. Its Full Page frontend rendered four tile columns at 1440 px and two at 768 and 375 px. The original partial draft recorded editor verification. Whole-page QA remains pending while required regions are missing.

The disposable WordPress browser integration rejected a different compact capture, preserved the reused Pattern and zero spend across repeated requests, verified the partial draft, and still completed its independent three-region fixture through first-fold, full-extent and continuous responsive QA.

## v0.12.28 recorded native FAQ disclosures

The existing WordPress Source Scope `verify_disclosure` action requests Touch replay for the exact pinned region at 1440, 768 and 375 px. The capture service reads the recorded responses and seals network requests before actions. It checks the initial page copy hash, the bounded local controls and their repeated 20 px chevron, one initially open panel and the ordered sequence that opens each answer and closes the active answer. WordPress checks the recorded identity against the capture, including each transition's target, width and state fingerprint. The original source checkpoint remains in History. Failed or incomplete replay retains the blocker.

BKKGO Full Page REF-000030 reverified its five existing FAQ controls at all three widths and preserved their ordered summaries and answers. The desktop replay verified a local overlay dismissal prerequisite. The source keyboard behavior remains unverified. Native Chromium checks independently verified Enter and Space, visible focus and isolation between repeated Patterns. Bossy's handoff carries the required disclosure interaction ID, observed actions, ordered copy, initial state and missing source keyboard proof.

The native compiler uses a Group containing five Core Details blocks and editable answer paragraphs. The bordered appearance uses the same local chevron, responsive row padding and text sizing in the reference and the native preview. Pinned source QA inspects all answers, including collapsed copy. The functional gate separately runs the actual open, close and focus behavior and rejects unclassified groups, repeated-group state interference and mismatching reference answers.

The retained FAQ candidate `0a158cb0dfbd79aa01c42884558b797422f07e916bd2352dd67cd04c789efabc` passed pinned visual comparisons at 1.172%, 0.034% and 0.058%. Its twelve responsive samples and all ordered disclosure actions passed, and 21 valid blocks survived edit, save and reload in disposable Gutenberg draft 114. The edited fixture also passed its frontend click and keyboard checks at every required width. No model call was made for the candidate. Bossy and Validator approval and production Pattern acceptance remain pending.

Full Page coverage is 12 of 16 regions with complete required-width capture, four eligible regions and one accepted placement. Local draft 66 still contains the independently accepted Through Our Lens Pattern; no whole-page pass or additional placement was recorded.

## v0.12.29 exact staged review and planning recovery

A staged native plan can enter the existing Section workflow when the selected region, archive and exact content hash match, and both the retained preview and semantic review reference pass pinned source QA. The original plan, source identity, reference and usage are retained on a single GEN. A rejected disclosure identity permits one bounded planning retry. Work-order formatting can move one complete sentence when that is sufficient to meet the schema without changing the wording. The design and implementation gates still require fresh decisions and browser checks. A design review timeout resumes with the exact staged implementation.

BKKGO FAQ GEN-000057 kept native artifact `0a158cb0dfbd79aa01c42884558b797422f07e916bd2352dd67cd04c789efabc` and reference `d2e5b6481a36d1c75f586ad3883386894519caedc3403242e4994faa5c95a4fc`. Its original work order used the section ID `questions` in place of `disclosure-087281201f063bc13f4f30af`. The bounded retry returned the correct ID with one 294-character requirement. Reformatting the archived response preserved every sentence and passed the 240-character field limits without another planning call.

Bossy requested changes for the desktop introduction wrapping and the execution gate held. The source paragraph measures 424 px and uses `text-wrap: pretty` at 1440 px. Applying that style only to the retained desktop introduction in a renderer trial improved the pinned comparison from 1.172% to 0.680% at 1440 px and retained 0.034% and 0.058% at the compact widths. The trial did not change the saved reference or native content. Three completed calls totaled $0.014236. Validator, an accepted Pattern and a second Full Page placement are pending.

The local WordPress test site registered the four exact archived faces under the Beausite Grand and Classic presets. Actual local draft 66 loaded both families without font HTTP errors at 1440, 768 and 375 px. Its gallery kept four desktop columns and two tablet and mobile columns. The font files remain in the local test site's uploads.

## v0.12.30 source-bound FAQ wrapping repair and private placement

The original FAQ job restored the pinned desktop introduction's `text-wrap: pretty` on one editable Core Paragraph. The saved class appears in the editor, the native preview and the WordPress frontend. The recorded 424 px paragraph width, mandatory section heights, five disclosures, literal copy and original block identities were preserved. The saved reference and native artifact independently measured 0.680%, 0.034% and 0.058% source difference at 1440, 768 and 375 px. The bounded repair rejected a paragraph whose source evidence did not support the requested style.

Bossy approved reference `42da3ceab8370b5cdc0c5fb26c1cb2632d6b0ebf1cfb5773f109210076f7badf`. The first gate pass held the job because the archived work order scopes Enter, Space and visible focus exclusively to the compiled artifact. The retry replayed the exact Bossy decision and checked the unchanged reference and other checklist results. The native keyboard requirement remained open until independent Chromium verified it on artifact `5ac2e86ba085ce97fd5a4e75c639c75f1e144c6cfde19f1cf086859bcfd46b19`. Validator approved the same artifact. Source keyboard behavior remains unverified.

All 21 native blocks survived a real Gutenberg edit, save and reload in disposable draft 133. The authenticated editor receipt enabled private unsynced Pattern 68. GEN-000057 used five completed model calls totaling $0.024752. No new Designer or Developer artifact was generated. The second design gate pass used the recorded approval.

Full Page REF-000030 reused Pattern 68 against the same archive without additional model calls. Draft 66 now holds both approved placements in source order at positions 12 and 14. The actual draft verified gallery columns, both archived font families, the FAQ's click sequence and native keyboard focus at all three required widths. A disposable copy of the exact draft passed Gutenberg edit, save and reload with 56 blocks. The original draft retains its editor receipt for SHA-256 `9f3d2cf6d694a8a81a52604498328b27f510492730c5c95b6374d4efade11d03`. Required regions are still missing, so the Full Page job and whole-page QA remain open.

## v0.12.31 scoped Designer CSS continuation

A token-limited Designer response can resume when its archived stream contains complete section markup followed by complete CSS rules and a bounded selector tail. The orchestrator checks the recorded stop reason and metering, original brief, assignment, source archive, viewport widths and saved Touch evidence. The Designer contributes CSS only. The same GEN retains the original prefix and usage. An interrupted declaration or a changed markup response stays held. Normal source, responsive and review gates apply to the continued reference before Developer.

The original Full Page REF-000030 checksums, captured sequence, screenshots and literal copy matched the earlier scroll inventory for Exclusive Offers and Sip & Dine. The bound source checkpoints preserved the original snapshots. Fresh recorded Touch replay reproduced 213 px at 375 px for Exclusive Offers and 594 px at 768 px and 280 px at 375 px for Sip & Dine. These are eligibility receipts.

Exclusive Offers GEN-000058 reached the 16,384-token Designer limit after emitting complete markup and 2,320 bytes of complete CSS. Its CSS-only continuation kept the same markup digest `667e0cf48f3a77903d1a6c0599fae7e561467ed9719b06c7ca541c75510e5d30` and used the original work order. The first full reference measured 4.443%, 4.472% and 5.863%. Bossy held the design and both original artifacts remained in History. The job ended with 10 completed model calls and one metered capped response. Total recorded spend was $0.070055.

An offline evidence check found that the intro and footer were present. The literal mismatch was capitalization in the eyebrow and compact offer label. Changing their underlying copy while preserving uppercase appearance restored exact source copy and local scroll at all three required widths, with no pixel change. The best isolated typography trial used the archived Beausite Grand and Fit styles at the wider widths and measured 3.940%, 3.687% and 5.863%, preserving the 375 px rendering. It is saved privately on GEN-000058 as a trial. The Fit face was available only during that local comparison. Visual fidelity, native implementation, independent QA and placement for Exclusive Offers remain pending.

## v0.12.32 measured Offers design recovery

The original Offers work order and Designer artifacts remain in History. The earlier reviewer had interpreted bounded excerpts as a missing intro and footer. The full literal comparison identifies the exact casing changes and keeps visual uppercase styling independent of the source copy. A regression in any unwaived required viewport now rolls back the repair, even if the viewport already failed and the aggregate score decreases.

An isolated replay compared the pinned source and retained reference rectangles. It resolved the compact 4 px and 8 px spacing drift, the unequal desktop description heights and the second card's outlined reservation label. The exact repaired reference `2c116d5908e515bdc7cd69d4a2f0eea343405f368cad2a61c9b8143df9ddabb9` passed pinned source at 0.190%, 0.031%, and 0.066% with identical rounded section heights, exact copy and the approved local scroll contract. All 13 responsive widths passed. The existing local WordPress test site registered only the archived Beausite Fit face needed for this comparison, alongside the already installed local presets.

The saved design initially held on a mixed contrast and keyboard checklist row. Chromium measured minimum contrast of 5.30:1, 5.97:1 and 5.97:1 in the WordPress Page context. The original work-order wording was preserved. A second review had confused a hidden desktop label with the two visible compact footer labels. The evidence projection now passes their positive browser rectangles, the full copy verdict and the total number of elements measured. The follow-up resumed against the unchanged reference and Bossy handed it to Developer. Independent keyboard and focus verification remains required on the compiled result.

The first Developer call exhausted its 16,384-token cap without emitting JSON. A Developer-only retry replayed the exact approved handoff and emitted 3,255 bytes before cutting off inside the first desktop card. History preserves the partial stream. Both charges remain on GEN-000058. The job has 15 completed responses, three metered capped responses and $0.128741 in total recorded spend. The native artifact, editor verification and Full Page placement are still open.

In the disposable Gutenberg editor, Core Columns serialized and reparsed with non-stacking layout. Core Group preserved a 2 px top border and Core Paragraph preserved a 1 px border. These checks verified the native attribute mappings for the pending Offers implementation.

## v0.12.33 retained Offers implementation and Full Page reuse

The capped Developer response `6c256726d81f0364f358a7cce330e454a0ad6d4e87e1ae18bc52e15be11fb1af` yielded 12 complete block identities. The completed plan retained their authored literals, links, types and child order. It added the remaining blocks from the approved design within the original work order. A missing WordPress text alignment rule had moved desktop text by 456 px in the preview. Using the core rule repaired the preview without changing the compiled bytes. The compiler now emits the verified native Columns, Row and border attributes and the bounded painted 4:3 tile style.

The exact native artifact `a46a949514a92558c723f5e0c1bedd991a66881673732afbb582bfaf041162b1` measured 0.060%, 0.000%, and 0.000% against the pinned source. Against the frozen design it measured 0.575%, 0.028%, and 0.055% at 1440, 768, and 375 px. It passed all 12 native responsive samples, source copy and asset checks, WordPress Page context contrast, and the ordered mobile strip's wheel, keyboard and focus checks. The first Validator response raised seven findings. Chromium showed that the six measured typography properties it disputed matched across the affected text, and the compiler confirmed explicit non-stacking Columns. The same artifact and reference received one independent review with these measurements. Validator approved it with zero findings. The two implementation reviews added $0.017999 for a cumulative $0.146740 on GEN-000058, with no further Designer or Developer call.

All 99 blocks survived an edit, save, and reload in Gutenberg. GEN-000058 completed and saved private unsynced Pattern 73. Full Page reuse initially lost the nested scroll component ID in the verification render. The fresh check now recovers that identity from the approved plan only when compilation reproduces the exact saved Pattern. This requires no changes to the Pattern or additional model calls.

REF-000030 revalidated and placed the Pattern at source position 8. The local draft now places Offers, Through Our Lens and FAQs at positions 8, 12 and 14. Its SHA-256 is `a7cd21678acb9cb3dc0b637b7a35a78bed20aebf222de6e5d120e135e7c1a9e1`. Actual draft 66 loaded the three archived Beausite families and preserved the Offers card counts, gallery columns and FAQ disclosure at all required widths. On the 375 px draft, the two-card Offers strip began at zero and reached 213 px by wheel and keyboard, with visible focus. A disposable copy of the unchanged original draft passed Gutenberg edit, save and reload with 155 valid blocks. Acceptance is 3 of 16 regions, four are eligible, and 12 have complete required-width capture. Whole-page QA remains pending.

## v0.12.34 Dining source evidence replay

The original Dining capture and the later Full Page evidence share the same archive, exact screenshot and HTML at each required width. The only data change is the addition of the observed horizontal scroll control to the two compact inventories. The archived work order predates this capture correction and still describes the control and installed fonts as unsupported. The original decision and model usage remain in History.

Browser diagnosis found that captured inline CSS encodes quoted font names as HTML entities. The font scan had treated the entity terminator as a CSS semicolon and skipped those family names. After decoding quote entities for discovery, the pinned source and trial loaded the same three installed Beausite families from the same font-frame checksum. The renderer still receives the original fragment bytes with its existing, allowlisted font stylesheet.

An offline repair of the retained Designer reference restored the desktop card dividers and measured reservation text minimum height, the exact typography and the three-card compact strip with preserved ordered copy. Trial `af23af680ce5509f5956c63ee04974b66428beabbf58e559521276348ee3b776` passed pinned source at 0.139%, 0.117%, and 0.340%, with exact rounded heights of 1340, 1227 and 918 px. It passed all 11 responsive samples and independent wheel, keyboard, focus and end-reachability checks at both compact widths. This is a separate trial on GEN-000056. The original generation remains held until the verified source inventory and corrected work order can resume together. Its metered cost remains $0.018427.

## v0.12.35 native Dining recovery and fourth Full Page placement

The 68-block native Dining trial preserved the 3:4 portrait tiles, desktop 80 px middle-card offset, exact copy and the three-card compact strip. The initial native rendering inherited the host's −0.1 px tracking and an extra 50 px left inset on the constrained paragraph. Setting the measured tracking and explicit padding brought the native source difference from 2.911%, 2.524%, and 5.456% to 0.172%, 0.117%, and 0.340%. Section heights stayed at 1340, 1227 and 918 px. All 12 native responsive samples passed. The artifact `3dfbc50b54c15b01f40ec5d412e65b4b5b57d02e49a963de93ee8dd22893f775` passed a real Gutenberg edit, save and reload with all 68 blocks valid.

The original GEN-000056 remains on its earlier capture and work order. Corrected Section REF-000033 and GEN-000059 used the verified Full Page source checkpoint and the existing staged review path for the exact native bytes. Recorded Touch reproduced 594 px and 280 px with zero blocked requests. The staged job ran classification, design reviews and independent Validator without another Designer or Developer generation. A renderer projection had dropped the approved strip identity, then omitted measured borders on the three plain reservation labels. Fresh inspection resolved both issues. The exact keyboard and focus requirements and WordPress Page contrast passed browser checks. The native-only replay retained the approved design and still required independent functional, responsive, host and visual checks. Validator approved with zero actionable defects. The staged job used eight completed calls totaling $0.057829.

Local private unsynced Pattern 79 passed fresh source QA before REF-000030 reused it at source position 10. The original draft 66 now holds verified positions 8, 10, 12 and 14, with SHA-256 `8e1850fabf7b279265335f097aef8144d84bddf77fc1cfb18a2afc9ed80e1a37`. The actual preview reproduced Dining wheel movement of 594 px at 768 and 280 px at 375, then reached the last card by keyboard at 1043 px and 493 px with visible focus. Offers still reached 213 px on mobile, and gallery and FAQ checks passed. A disposable copy survived Gutenberg edit, save and reload with 223 valid blocks. All four currently eligible regions are accepted. Whole-page QA remains pending while the other 12 regions are blocked.

## v0.12.36 archived image provenance and Media Library import

The original Full Page scope can replay a single image-only PNG region through the authenticated recorded Touch service. Each response is bound to the original archive and source selector, its pinned page copy checksum, the exact image URL and alt text, and its own measured geometry and scroll range. An image behind a link, a changed archive response, an incomplete gesture, or unavailable source evidence stays held.

REF-000030 verified the brand image at all required widths and retained checkpoint 12146. Its local attachment 83 preserves all 208,268 bytes and SHA-256 `db40cc8d796b00278361b5cfb993a9658f1eb3778c468e9e68ad1f3552c3b3b5`. The import uses the existing Media Library path and disables large-image scaling during the verified import. A repeated action returns the same receipt. A local Gutenberg draft loaded the exact URL and kept the original alt text after a caption edit, save and reload.

An offline fragment built from the same attachment matched the pinned source screenshots at 1440, 768 and 375 px. All three comparisons measured zero pixel difference, with matching rounded heights of 414, 268 and 268 px. Compact source wheel movement reached 80 px and 473 px. Source keyboard behavior remains unverified. This evidence keeps the native scroll and independent acceptance gates open. The checkpoint used zero paid model calls.

The recorded browser changes from the desktop region to the compact region between 1024 and 1023 px. The source page holds the desktop region at 1280 px when the viewport is 1200 or 1024 px, creating page-level overflow. At 1023 and 900 px, the compact image fits and the scroll range is zero. Its range grows to 248 px at 600 and 249 px at 599. These intermediate observations are separate from the three required source comparisons and must inform the native responsive check.

## v0.12.37 native single-image scroll verification

The verified replay can stage one editable Core Group and Image. QA binds the original WordPress attachment and PNG hash to the pinned source event and candidate. The renderer independently compares the source screenshots and checks responsive containment, alt text, exact compact scroll endpoints, wheel reversal, keyboard movement and visible focus. Saving requires the matching Gutenberg parse and a fresh QA pass before creating a private unsynced Pattern. Assembly revalidates the stored candidate and original attachment.

Local Pattern 89 matched all three required source screenshots at 1440, 768 and 375 px with zero pixel difference. Actual WordPress served the original PNG and reached 80 px and 473 px at the compact widths using a wheel. Keyboard input moved the focused container, and a Gutenberg caption edit preserved the image and alt text after save and reload. At 1200 and 1024 px the native section fits the viewport and scales the artwork, while the captured site holds a 1280 px region and overflows the page. At 1023 px both used the compact treatment with no image overflow.

Original REF-000030 draft 66 now contains five verified placements at positions 8, 10, 12, 14 and 15. Its disposable copy accepted an edit and reloaded with all 225 blocks valid. Whole-page QA remains pending because 11 required regions are missing. This checkpoint used zero paid model calls.

## v0.12.38 archived illustration and link evidence

The existing image verification action can retain evidence for a static text region with one same-origin HTTPS link and one PNG that appears at a subset of required widths. Recorded Touch binds the immutable archive, exact region identity and matching rendered page copy at each width. It observes the link in the DOM without opening its destination. It records image presence, decoded dimensions, alt text, geometry and paint and imports the exact hash-verified original. Source verification leaves the conversion blockers in force until the production output supports the layout, link and image requirements.

Original REF-000030 checkpoint 12138 recorded the Our Story proof in event 23247. Local attachment 93 retained all 132,578 bytes of the 542 by 400 PNG. It was visible at 1440 px and absent at 768 and 375 px. The same declared Brand History destination appeared at all three widths. A separate read-only check observed a successful public response with the Brand History heading. The destination document was outside the pinned homepage archive.

A 15-block offline trial matched the pinned heights of 1452, 1457 and 1225 px. Pixel difference measured 0%, 0.413% and 0.757%. All 11 sampled widths passed the continuous responsive check. A disposable WordPress draft passed edit, save and reload with all 15 blocks valid and kept the image and link. The browser confirmed keyboard navigation to the exact URL with a local intercepted destination. This trial used stylesheet injection for its responsive layout and remains outside production approval. No model calls were needed.

## v0.12.39 editable linked editorial Pattern

Production Setup can compile the recorded two-column editorial layout and compact stack into editable Core blocks. Eligibility requires the exact archived structure, text order, column spans, dimensions, declared link and source image receipt. Chromium independently hashes the decoded visible PNG and measures its alt text, paint and visibility. The link must match the source at every required width and be keyboard reachable. Save verifies the Gutenberg block count and artifact digest, then repeats browser QA. The stylesheet digest binds the browser verdict and a change puts that verdict back into Pending. Rechecking a saved Pattern preserves its exact blocks and editor evidence.

BKKGO Our Story passed its pinned source comparisons at 0%, 0.413% and 0.757%, with matching heights of 1452, 1457 and 1225 px. Continuous QA passed 14 samples including 1024, 1023 and 1022 px. At 1200 and 1024 px the source region overflows to 1280 px. The native section fits those widths. An actual keyboard Tab and Enter reached the exact declared URL under test interception. The original PNG and text were verified separately, and altered alt text or a substituted receipt held the asset gate.

Local private unsynced Pattern 100 retained all 15 editable blocks. Original draft 66 now has six verified placements at source positions 7, 8, 10, 12, 14 and 15. Its exact Gutenberg copy passed an edit, save and reload with all 240 blocks valid, and the original draft retains its editor verification. Whole-page visual QA remains pending with 10 required regions outstanding. No model calls were used.

## v0.12.40 native pointer reveal cards

Recorded Touch verifies each desktop card against the archived region and viewport, captures before and after screenshot fingerprints and measures panel copy, paint, and pointer states. The Neighborhood desktop replay required the local offer overlay to be dismissed. Hover revealed each panel, clicking its Discover label produced no navigation or additional state change, and leaving closed the panel. Both compact source variants had zero interactive targets in this region.

The production compiler maps the measured desktop grid and compact stack to editable Core Group, Heading and Paragraph blocks. Native browser QA checks hover, inert label dispatch, pointer exit and visible keyboard focus in card order. The source cards had no keyboard entry point. The native implementation adds focus access and keeps the compact layout static. Invented destinations and altered source receipts fail QA. A stylesheet change invalidates the measured verdict while preserving the saved blocks.

Neighborhood matched pinned heights of 1253, 1140 and 824 px. Pixel differences were 0.685%, 0.128%, and 0.026% at the required widths. All 14 responsive samples passed. At 1200 and 1024 px the source overflows to 1280 px. The native layout fits those widths, with desktop reveals still available at 1024 px and absent at 1023 px. Both actual pointer and keyboard checks ran in WordPress, and all 57 blocks survived editing a revealed paragraph and reloading.

Local private unsynced Pattern 106 was placed at source position 11. Original draft 66 now has seven verified regions, with all 297 blocks editor-verified on an exact disposable copy. Whole-page QA remains pending with nine regions outstanding. This checkpoint used zero paid model calls.

## v0.12.41 editable Rooms showcase

Touch records the desktop carousel and its category disclosures together. The source starts on photo one, wraps in both directions, accepts a direct photo selection and resets to photo one when a different room category opens. All four categories carry their observed detail text, including the source's unconfirmed values. Sealed replay measured successive automatic advances at 3993 and 7990 ms and confirmed that the apparent booking labels had no destination or observable action. The compact label strip reproduced a 138 px forward and reverse wheel range and 40 px of keyboard movement at 375 px. At 768 px it had no overflow.

The native Pattern stores its text and slide placeholders in editable Core blocks. WordPress adds keyboard-operable carousel and category controls, a pause control and reduced-motion behavior. The isolated renderer checks the actual script, focus, selected and expanded states, and compact wheel and keyboard movement. It compares the pinned desktop screenshot with the recorded second-photo state and separately verifies first-photo startup. Altered receipts and stale runtime fingerprints are held.

Rooms passed visual QA at 0.917%, 0% and 0% for 1440, 768 and 375 px. All 11 responsive samples passed. Pattern 115 retained 103 blocks after Gutenberg edit, save and reload. The original unpublished draft 66 now contains eight verified regions and all 400 blocks passed a separate edit, save and reload. Whole-page QA remains pending with eight regions outstanding. This checkpoint used zero paid model calls.

## v0.12.43 recorded responsive title placement

Full Page Production Setup can verify a blocked desktop-only title against a separate region in the same pinned scope. The source and destination selectors come from their saved checkpoints. Recorded Touch verifies the page copy digest before checking that the standalone title appears once on desktop and only in the destination at the two compact widths. A repeated title, an unrelated destination, a changed page digest or an attempted network request holds the proof.

The receipt retains both region identities, their checkpoint IDs, the exact title digest, and the observed location and geometry at each required viewport. It is appended to History and shown on the scope row. Source placement is recorded independently from native visual, editor and page-level QA. REF-000030 still has eight verified regions, and the production budget and existing artifacts were preserved.

## v0.12.44 desktop crops with adjacent overlap

Recorded responsive title placement can save a separate desktop crop when the adjacent region owns a verified absolute overhang. Replay checks the owner's DOM relationship, the original composed JPEG digest and the target's copy and geometry. It hides the exact overhang for the auxiliary crop, then restores the original style. The auxiliary JPEG and digest are stored in a separate History event. Whole-page QA still uses the original composition. A crop failure leaves the separate title placement receipt available for follow-up.

## Remaining acceptance

The follow-up live audit held every defective trial and passed all four controls. Bossy identified 11 of 12 distinct faults including the unmetered request in the denominator. Through Our Lens and GEN-000055 completed their original jobs. Verified BKKGO Full Page assembly and broader region acceptance remain open. [Design validation audit](bossy-validation-audit.md#v0128-verification)

No release has been authorized for this development series. Each checkpoint records the version, actual verification and outstanding work in the plugin changelog.
