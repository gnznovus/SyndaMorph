# Bossy design validation reliability audit

The v0.12 work-order checkpoint gives the reviewer stable requirement IDs and a pinned specification receipt. The rates below remain the measured baseline. Requirement-level decisions and equivalent live retesting are [later checkpoints](production-team-upgrade.md).

SyndaMorph benchmarked the pre-Developer design gate at `d71f0321f064964c1d1ef523d249183505a96ff1` on the feature branch. Bossy explicitly identified 5 of 11 distinct defective designs for which Haiku 5.5 returned a verdict. The complete gate withheld handoff on 9 of 11, including three requests for more evidence. A missing gold marker and a wrong background reached handoff. A broken link also reached handoff in one of three trials with the same request. Bossy's approval needs independent evidence for requirements that can be measured.

## Approval contract

1. **Source → Designer.** Brief-only production starts with the requested design. Manual URL production adds a pinned section capture, viewport copy and source facts, plus desktop and mobile source screenshots. Section and Full Page production pin a viewport-specific region and inventory. The Boss work order and original brief guide Designer, which emits scoped, self-contained HTML.
2. **Designer → Chromium → Bossy.** The orchestrator checks the HTML and requests screenshots, DOM measurements and axe results from isolated Chromium through the bounded inspection path. Initial review includes the brief, design SHA-256, compiler capability notes, detected constraints, up to 24 compact measurement rows per viewport, and the first and last requested screenshots. The audit sent 1440 and 375 px screenshots and measurements at 1440, 768 and 375 px. Sourced runs also sent 1440 and 375 px source images and the literal-copy comparison. Scoped runs included a separate source comparison with per-viewport markup and pixel results. The initial request omitted complete Designer HTML, the 768 px screenshot, computed font family, candidate element tags and destinations, and individual axe findings. Later reviews send changed measurements and screenshots and can request bounded, read-only diagnostics against the reference.
3. **Bossy → execution gate.** The orchestrator records the model decision separately from the gate status. Explicit `approved` with an empty issues list is necessary. A review request holds the gate, a design decision requirement blocks it, and a change request can route back to Designer. The orchestrator can block an approval based on measured native constraints or pinned source checks. A syntactically valid change request can stop a correct design without a measurement demonstrating the defect.
4. **Gate → Developer.** The `approved_design` handoff records the reviewed reference SHA-256. The audit stopped at this boundary. Developer generation, native artifact QA, Gutenberg editor acceptance, and Full Page assembly were outside the measured outcome.

The baseline native constraints detect active unsupported effects such as geometry-changing balanced text wrap and a CTA switching from intrinsic to full width. In the baseline, manual URL design approval enforced pinned literal copy. Its source pixel comparison ran after Developer. Section and Full Page enforced source literal copy, links, asset and function inventory, visual extent, and horizontal overflow before Developer. Scoped pixel tolerances were at most 1.5% in any view and 0.5% across views. The scoped height allowance was the larger of 16 CSS px or 10% of source height. [Orchestrator](../plugin/includes/production-collaboration.php) · [Evidence projection](../plugin/includes/production-job-memory.php) · [Inspection contract](../plugin/includes/production-inspection.php) · [Tool grants and diagnostics](../plugin/includes/production-tools.php) · [Native constraints](../plugin/includes/production-repair.php) · [Manual source checks](../plugin/includes/production-source.php) · [Scoped source checks](../plugin/includes/production-scope-source.php)

## Fixtures and method

- The immutable approved hotel hero was archived from GEN-000002. Its HTML has SHA-256 `12107e8b92e69fb65a5a605704011a54dc5fba48a7bc32c7f60e7ddff21f9db9`, calculated on the archived text before its file-ending newline. The calibrated derivative removes modern-engine unsupported text-wrap declarations and starts from a zero body margin. It adds a 24 × 3 px marker in a fixed slot and gives the CTA variant a real destination. The harness renders and pins this derivative before applying each mutation. The manual URL fixture uses a fixed-viewport capture format and the scoped fixture uses a full-extent capture format. [Archived approved HTML](../tests/fixtures/bossy-approved-gen-000002.html) · [Case definitions](../tests/fixtures/bossy-design-cases.json)
- GEN-000036 supplies a real BKKGO source capture and a previously unsuccessful Designer reference. The harness rebuilds the reference from the archived Designer response and accepted surgical edits, then reruns the source comparison with Chromium. The capture and job history are local and included via the optional archive test.
- In deterministic runs, every design received a scripted Bossy approval. This measures what the application can independently stop. In live runs, each case used one first-pass Haiku 5.5 decision with the actual production request format, apart from an audit-only 4,096-token wire cap. Planning and Designer responses were fixed. Requested tool follow-ups and Designer repairs received no additional live reviews. The live experiment used the baseline approval policy. The post-change counts came from deterministic replay.

## Case results

Pixel deltas use 1440 / 768 / 375 px. Hotel hero measurements in this table use fixed-viewport images. The BKKGO row uses the production scoped extent comparator. In the deterministic column, **B**, **M** and **S** mean brief-only, manual URL, and Section or Full Page respectively. Each entry lists the modes that blocked a forced approval, before → after. Non-link hotel cases ran in all three modes, the two link mutations ran in B, mobile CTA width ran in M, and BKKGO ran in S.

| Case and expected outcome | Independent evidence | Deterministic blocks | Live baseline mode and verdict | Live gate |
| --- | --- | --- | --- | --- |
| Unchanged · pass | Identical at all widths | None → none | M · approved | Handoff |
| Equivalent color syntax · pass | Same RGB value, 0 / 0 / 0% change | None → none | S · approved | Handoff |
| Wrong headline font · block | Monospace replaces system-ui, 1.682 / 1.791 / 2.040% | S → M, S | M · verification required, correctly suspected monospace and asked for computed family | Pending |
| Vertical padding · block | 16 px at all widths vs 104 / 78.1 / 54.5 px, 22.510 / 17.693 / 14.655% | S → M, S | M · changes requested, identified the measured padding | Blocked |
| Tablet alignment · block | Left alignment at 768 px only, 0 / 3.523 / 0% | S → M, S | M · changes requested, identified computed tablet alignment | Blocked |
| Mobile wrapping · block | Two lines become one at 375 px, horizontal overflow, 0 / 0 / 9.178% | S → B, M, S | S · changes requested, identified clipping and overflow | Blocked |
| Missing marker · block | Required marker absent, 0.008 / 0.015 / 0.028%. Scoped deltas also passed at 0.009 / 0.029 / 0.066% | None → none | S · approved with passing global comparisons | **False handoff** |
| Wrong background · block | Red fill replaces navy gradient, 61.139 / 47.580 / 41.004% | S → M, S | M · approved, no background finding | **False handoff** |
| Altered headline · block | Different literal headline at every width, 1.677 / 1.856 / 1.825% | M, S → M, S | S · approved, treated the discrepancy as an authorized change | Blocked by source fidelity |
| Broken local link · block | `#nowhere` instead of `#rooms`, absent destination, 0% at all widths | None → none | B · verification required on first trial, with an unsupported assumption about the actual href | Pending on first trial |
| Static control · block | Anchor replaced by a span, 0% at all widths | None → none | B · verification required for tag, href and keyboard evidence | Pending |
| Desktop placement · block | Headline x shifts from 384 to 48 px only at 1440, 3.272 / 0 / 0% | S → M, S | S · provider transport failure, verdict unavailable | Indeterminate in live sample |
| Mobile CTA width · block | CTA becomes 328.5 px and full width at 375, 0 / 0 / 1.965% | M → M | M · changes requested, identified full-width CTA | Blocked |
| BKKGO compact heading · block | Captured two-line heading renders on one line, about 35 px shorter at 375, 1.778 / 2.056 / 7.336% | S → S | S · changes requested, identified wrap and extent defects | Blocked |

### Detection and error rates

| Validator or outcome | Population | Result |
| --- | --- | --- |
| Bossy explicitly identified the defect and requested correction | First completed verdict per distinct defective artifact | 5/11 (45.5%). One additional case correctly suspected the font defect and asked for proof. |
| Bossy withheld approval, including verification requests | Same 11 artifacts | 8/11 (72.7%) |
| Bossy false approvals | Same 11 artifacts | 3/11 (27.3%) |
| Combined gate prevented handoff | Same 11 artifacts | 9/11 (81.8%). Six blocked and three pending. |
| Combined gate prevented handoff, counting repeated trials | All 13 defective trials with a verdict | 10/13 (76.9%). Six blocked and four pending. |
| Deterministic gate independently blocked scripted approval | 12 distinct defective artifacts in their designated live modes, including the unavailable provider case | 5/12 (41.7%) baseline → 9/12 (75.0%) after fixes |
| Deterministic gate by mode | 28 defective artifact/mode combinations | Brief 0/10 → 1/10. Manual 2/9 → 8/9. Scoped 8/9 → 8/9. Total 10/28 (35.7%) → 17/28 (60.7%). |
| Valid references approved and handed off | Two distinct live controls, four trials with repeats | 2/2 unique and 4/4 trials. Deterministic controls passed all six artifact/mode combinations before and after. |

The valid unchanged reference returned `approved` on all three identical requests (`7036b67a…`). The broken link returned `verification_required`, `verification_required`, then `approved` on three identical requests (`c99ea714…`). On approval, Bossy asserted that the candidate anchor targeted `#rooms`. The fixture targets `#nowhere`. Handoff followed that decision. Across the entire defective live sample, four approvals were wrong and three reached Developer handoff. There were no live false rejections in the two valid designs. A separate scripted, unsupported alignment complaint against the unchanged fixture blocked handoff, proving that the orchestration can act on an unsupported model rejection.

### Inspection trust boundary

| Injection | Baseline | After fixes |
| --- | --- | --- |
| Chromium evidence omits 375 px and escalation attempts approval | Requested `[1440,768,375]`, retried only `[375]` twice, kept the checkpoint, left visual QA pending, and made no design approval call | Same |
| Chromium response supplies valid screenshots and empty measurement samples | A scripted approval reached Developer | Saved the reference checkpoint and deferred design inspection before the visual review. |
| Bossy returns `approved` with open issues | Contract rejected it and Developer did not start | Same |
| Bossy returns a structured but unsupported correction | Correct fixture held from handoff | Same |

## Findings and priorities

1. **High · Structural and interaction requirements need direct evidence.** Wrong CTA semantics and destinations produced identical pixels. The initial brief-only review gave Bossy neither the actual tag nor the href. Pass measured tag, href, fragment resolution and keyboard focusability in a compact per-viewport control record. For a required link, verify those fields before accepting approval and distinguish an explicitly host-owned destination from a missing required target.
2. **High · Enforce existing visual and inspection checks before spending on conversion.** This audit adds the existing source pixel limits to manual URL design approval, treats observed horizontal overflow as a handoff blocker in every mode, and defers review when Chromium supplies an empty element snapshot. Missing inspection retains the saved artifact and follows the existing bounded escalation. Existing source thresholds and scoped acceptance remain in force.
3. **Medium · Page-wide pixel tolerances can miss required small elements.** The tiny marker stayed below both pixel thresholds even though it was absent. Use element-level existence and geometry checks or focused crops when the brief or pinned source explicitly requires a small element.
4. **Medium · Improve evidence behind model decisions.** Include computed font family and relevant background or color values. Supply a tablet crop when its evidence materially differs and a compact summary of relevant axe findings. Require a checkable evidence reference or a pending diagnostic for change requests that claim a measurable defect. This would constrain unsupported rejection as well as approval.

## Reproducing the audit

From the repository root, use a running local WordPress and renderer stack. The default replay makes zero provider requests.

```sh
docker compose exec -T wordpress mkdir -p /tmp/sm-bossy-audit
docker compose cp tests/fixtures/bossy-approved-gen-000002.html wordpress:/tmp/sm-bossy-audit/bossy-approved-gen-000002.html
docker compose cp tests/fixtures/bossy-design-cases.json wordpress:/tmp/sm-bossy-audit/bossy-design-cases.json
docker compose exec -T -e SM_BOSS_AUDIT_REQUIRE_FIXES=1 wordpress php < tests/bossy-design-audit.php
```

The harness writes JSONL with measured mutations, decisions, checksums and gate results. Use `SM_BOSS_AUDIT_ARCHIVE=1` only where GEN-000036 and its stored replies are available. CI uses `SM_BOSS_AUDIT_TRUST_ONLY=1` to cover the new handoff checks, intact controls and inspection failures on disposable WordPress and real Chromium. [Audit runner](../tests/bossy-design-audit.php) · [MVP pipeline CI](../.github/workflows/mvp-pipeline-check.yml)

The authorized live batch made 18 isolated requests, with 17 returned decisions and one transport failure without usage. Reported usage was 147,303 input tokens, 30,125 output tokens, 27,552 cache-read tokens and 3,673 cache-write tokens. Price-based metered cost was **$0.03052745**. The unavailable request retained a conservative **$0.03565125** reservation, for **$0.06617870** committed against the authorized $0.20 estimate. The sample covers one model, two valid fixtures, planted defects, and one actual archived regression. It measures first-pass judgment and handoff behavior under the baseline, with no live retest of the fixes. [Claude Haiku 5.5 rates](https://platform.claude.com/docs/en/models/haiku-5-5/overview)
