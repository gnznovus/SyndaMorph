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

The authenticated BKKGO capture returned HTTP 200, 16 regions, and receipt `d92c36a68a0fe3ac4fbbc6f7716b8cc32ac5deab5eb66b3933021f6d94e65515`. All three offline views had zero changed pixels, zero external requests, and all five live font faces. Desktop and compact source DOM hashes differed. A separate mobile attempt timed out loading a required React script from the CDN and returned incomplete evidence. The verifier rejected it before storing a verified set.

## v0.12.2 Touch checkpoint

Bossy can request `playwright.touch` during source classification when the work order has a verified runtime receipt. The existing registry supplies the archive identity and allows one inventory request and a bounded follow-up within the job's existing tool limits. The browser discovers target IDs. Agents provide no URLs, selectors, request bodies or executable code.

Reference capture records the public HTTP responses already used by the live browser. Their hashes bind the response inventory to the archive receipt. Touch boots a fresh Chromium context using only those responses, verifies the live-rendered copy against the corresponding pinned width, and seals the request route before any action. HTTP, document navigation, WebSockets and downloads remain unavailable during interaction. The action guard also rejects links that leave the document, sensitive controls and forms. It uses a maximum of five gestures.

An observed result records a browser state change with before and after screenshot hashes, measured target state and a replayable action. A link can carry an inferred destination without testing navigation. Unavailable or unsafe behavior remains unknown. Remote effects are always unverified. Raw screenshots stay in private History. Designer, Bossy and Developer receive compact scenario references. QA will independently replay implemented behavior during its later checkpoint.

On BKKGO at 375 px, `Open menu` exposed `Close menu` with the isolated recorded runtime. The authenticated test used archive `6efe86844a718453c4c9ff87d577fcfff25e44d6f53e338fdbafa0295f5290fd`. The browser fixture also verified that local state can change while GET and POST attempts are blocked. A prior prototype that booted from the live site rejected mismatched copy. Touch now requires recorded responses, including for replay of a saved scenario.

## Next checkpoints

1. Validate continuous responsiveness with mandatory 1440, 768 and 375 CSS px checks and deterministic intermediate and breakpoint probes. Keep source deviations explicit in the specification and separate from content, behavior and native feasibility. Designer repairs the saved HTML through targeted operations with a measured accept or rollback.
2. Project compact link, typography, background, accessibility, geometry and interaction evidence into review. Verify structured requirements separately from model confidence. Use overlapping images as supplemental evidence of mismatches.
3. Trial an editable local tabbed-content block. The application owns its capability contract and scripts. Developer owns block architecture and recovery. Independent QA exercises browser state transitions and the WordPress editor rather than inheriting Bossy’s Touch observation.
4. Verify both production adapters and rerun the defect benchmark with approval for any paid calls. Report detection, escape, false rejections, uncertainty, latency and cost. BKKGO establishes a scenario, while broader acceptance is measured separately.

No release has been authorized for this development series. Each checkpoint records the version, actual verification and outstanding work in the plugin changelog.
