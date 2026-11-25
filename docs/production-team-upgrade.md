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

## Next checkpoints

1. Make SingleFile opt-in in the existing capture service. Keep it out of the WordPress ZIP. Verify offline references independently of the archiver and keep the live source baseline authoritative. BKKGO requires desktop and compact capture families. Monolith remains a documented alternative.
2. Grant Touch only bounded, isolated interaction against a pinned target with network and action guards. A workflow that cannot be executed safely stays unverified. Store reproducible scenarios with explicit provenance.
3. Validate continuous responsiveness with mandatory 1440, 768 and 375 CSS px checks and deterministic intermediate and breakpoint probes. Keep source deviations explicit in the specification and separate from content, behavior and native feasibility. Designer repairs the saved HTML through targeted operations with a measured accept or rollback.
4. Project compact link, typography, background, accessibility, geometry and interaction evidence into review. Verify structured requirements separately from model confidence. Use overlapping images as supplemental evidence of mismatches.
5. Trial an editable local tabbed-content block. The application owns its capability contract and scripts. Developer owns block architecture and recovery. Independent QA exercises browser state transitions and the WordPress editor rather than inheriting Bossy’s Touch observation.
6. Verify both production adapters and rerun the defect benchmark with approval for any paid calls. Report detection, escape, false rejections, uncertainty, latency and cost. BKKGO establishes a scenario, while broader acceptance is measured separately.

No release has been authorized for this development series. Each checkpoint records the version, actual verification and outstanding work in the plugin changelog.
