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

## Remaining acceptance

In v0.12.8, Bossy receives the measured verdicts alongside the evidence. Explicit work orders can also measure viewport centering and vertical padding. Scoped source review includes the expected and observed link inventory when they differ. Deterministic replay held all 28 scripted defect approvals and passed all six valid combinations.

The first updated live audit held all 14 defective trials with 8/11 actionable model detections among distinct faults with a complete verdict. The strict Through Our Lens conversion is still pending source fidelity and a completed design review. [Design validation audit](bossy-validation-audit.md#v0127-live-benchmark)

No release has been authorized for this development series. Each checkpoint records the version, actual verification and outstanding work in the plugin changelog.
