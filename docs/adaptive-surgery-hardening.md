# Adaptive Surgery hardening

**Branch** `feat/secure-gateway-leasing-v1`

**Date** November 25, 2026 (Asia/Bangkok)

**Acceptance state** Engineering implemented and deterministically verified. Live Haiku 5.5 effectiveness and production acceptance require review.

## Independent investigation of GEN-000019 through GEN-000023

GEN-000023 used the budget-aware scheduler and reached implementation QA. It still stopped with three open findings and editor verification pending. The archive establishes the following causes in severity order.

| Severity | Confirmed cause | Correction and regression evidence |
| --- | --- | --- |
| High | The native browser preview approximated core's layout and omitted theme-owned defaults. Both compiler repairs in GEN-000023 preserved the requested values. The layout mismatch was a vertical Stack allocating its constrained child at the start edge, with additional padding on the actual core rendering. | Production previews now render the parsed blocks through WordPress core and collect the applicable style-engine rules. The editable serialization stays separate and unchanged. The compiler reports the applied native layout attributes and exact field moves. A measured single-child Stack correction can stretch the parent and remove undeclared theme padding only when the centered reference provides evidence for both. |
| High | Boss approved an HTML reference with observed native-incompatible wrapping and a mobile-only full-width CTA. The model's narrative deviation did not make those effects native. Reference and native captures also started with different default body margins. | Inspection protocol 2 puts both fragments in the same browser frame. It observes percentage/auto CTA widths and checks whether removing special wrapping actually changes line geometry. The Orchestrator preserves Boss's decision and blocks conversion until the effect is revised or Boss requests a human design decision. Quality acceptance still compares the approved artifact to browser evidence. |
| High | The first Developer repair used the role name as the assignment ID. No edit reached the compiler. The generic rejection led Developer to treat the valid gap change as already tried. Later requests could inspect the reference but had no tool access to the retained native artifact. | Repair requests now bind to the server-owned assignment and use explicit edit targets. A legacy owner echo is still validated. Rejections record the expected ID and whether edits ran. The read-only tool exposes the verified native artifact only during implementation and scoped repair, with a hash and cache receipt. |
| Medium | The schema excluded alignment of constrained children even though WordPress supports horizontal justification in a constrained Group. That made it difficult to reproduce a narrower, left-aligned intro inside a centered column. | The constrained Group now accepts contentAlignment with its native width. This is separate from vertical Stack alignment. A real Gutenberg fixture round-trips and renders the left-aligned constraint. |
| Historical | GEN-000019 needed safe shorthand repairs, GEN-000020 had a false added-copy finding and a real CTA discrepancy, GEN-000021 retained a partial improvement, and GEN-000022 exhausted a fixed QA response allowance at 6,144 tokens. | Existing compiler, copy and partial-retention safeguards remain active. GEN-000022's allocation fix is in the starting checkpoint. The budget regression fixture now tests the spend boundary with the adaptive allocator. |

WordPress 7.1.2 renders per-block responsive styles. A direct core probe generated a mobile 100% width on the Button wrapper while the visible anchor stayed intrinsic. Responsive element selectors also missed that anchor. The single-CTA plan retains fixed full-width support. Mobile-only full width remains a limitation of the validated single-Button mapping. Fluid direct blockGap is still limited by core sanitization. Site-specific theme defaults remain visible in evidence. [WordPress responsive styles](https://make.wordpress.org/core/2026/08/05/responsive-block-styles-and-configurable-viewports-in-wordpress-7-1/)

### Read-only replay results

All five archived jobs were inspected or recompiled without provider calls. GEN-000019 compiled after its two historical deterministic field corrections. GEN-000020's complete reference and native copy matched and its mobile-only CTA width was measured. GEN-000021 still contained different literal copy, and the reference used effective balanced wrapping and a mobile-only full-width CTA. GEN-000022's recovered Developer plan compiled after three alignment-location fixes. Its last Boss response was incomplete and was excluded from replay.

GEN-000023's inner Group compiled to core constrained layout with a 44rem content size and 1.5rem block gap. Its first patch was rejected solely at the owner field. The browser measurements from the same compiled plan show the reproduced implementation defect and the effect of the scoped native corrections.

| Evidence | Old archived preview | WordPress core baseline | After the measured parent and intro corrections | Normalized reference |
| --- | ---: | ---: | ---: | ---: |
| Heading left edge at 375 CSS px | 20 | 50 | 20 | 20 |
| Heading left edge at 768 CSS px | 38.4 | 76.8 | 38.4 | 38.4 |
| Heading left edge at 1440 CSS px | 64 | 114 | 368 | 368 |
| Intro lines at 768 CSS px | 2 | 3 | 3 | 3 |

Stretching the parent alone initially left the intro at two lines at 768 px. The additional left-aligned constrained Group restored the intended paragraph measure and three-line wrap. The final probe kept complete literal copy and native IDs. The original reference's special heading wrapping and mobile-only CTA remained visible limitations. No artifact was promoted.

Build the plugin and renderer together before native production. Old snapshots remain readable in History and are never rewritten. A protocol 1 renderer fails the new preflight before any provider calls. Existing published versions remain isolated. Paid AI production was not used to validate this investigation.

## GEN-000022 output budget correction

GEN-000022 exhausted an artificial 6,144-token Boss QA cap while most of the $1.00 job budget remained. Per-task quotas are removed: each call receives an output allowance determined by remaining authorized dollars, conservative input reservations and the selected provider/model output ceiling (Haiku 5.5: 128K). The 80% corrective-work gate and protected 20% Boss reserve are unchanged. Future scoped QA reserves dollars rather than a hard 4,096-token allowance. The HTML POC uses the same budget-aware calculation. Independent streaming size/safety bounds and complete-response validation still apply. **Live verification is pending.**

## GEN-000019 through GEN-000021

| Finding | Confirmed root cause and engineering change |
| --- | --- |
| F01 Structural execution | GEN-000021 made three style-only attempts. The wrapper executor existed, but the scheduler withheld wrap authority until a later strategy and required at least two direct children. The root had one child group. A layout assignment now exposes eligible group/column parents and consecutive direct children immediately. A structural-only patch or a combined style and structural patch can wrap even one child. |
| F02 Handoff and authority | Measured findings could replace model findings in the issue registry, losing Boss's proposed correction in the Developer prompt. The original Boss decision and findings now travel independently of the scored issue registry. The repair handoff contains the unchanged matching instructions, node IDs and wrap parents. Scoped QA receives the exact Developer operation with the candidate fingerprint. Explicit style, structure or hybrid requests require a rationale for a different choice. Unsupported requests route to Boss scope resolution. The Orchestrator emits its own execution gates and does not alter agent decisions. |
| F03 Native capabilities | GEN-000019's vertical margin belonged in a native sides object. GEN-000020's casing mismatch was a missing plan/compiler mapping. GEN-000021 combined unsupported fluid block gap, a safe CSS subtraction syntax the compiler previously rejected, and a layout that needed different groups. The capability matrix below records the WordPress boundary. |
| F04 Compiler Auto-Repair | The branch already ran safe normalizers before paid schema repair, but treated deletion of unsupported Buttons margin as safe and committed intermediate schema drafts. The compiler now preserves valid vertical margin, recompiles staged changes, and commits only once the entire plan and identity validate. Unsafe or unsupported corrections retain the original and expose the diagnostic. Successful History records carry original and corrected plans. The private staged checkpoints expire after 24 hours. |
| F05 Repetition | GEN-000021's last patch used a fluid gap that core discarded. The earlier attempt history was keyed to the entire plan and could reopen a failed local strategy after an unrelated edit. The scheduler uses scoped fingerprints and current measurements, carries rejection evidence and actual operation modes, and checks equivalent candidates even when wrapper IDs differ. New unsupported fluid gaps skip browser and model QA. No global QA attempt ceiling was added. |
| F06 Copy and acceptance | GEN-000020's approved reference and native intro both contained the allegedly extra sentence. Geometry excerpts did not contain complete copy. Inspection now keeps complete normalized literal copy independently of rendered casing, and QA uses deterministic comparison before treating a copy issue as real. GEN-000021's 26-to-27 result added a low-severity newly reported gap while screenshot differences improved on mobile and tablet. Comparable score stayed 26. A fresh report is distinguishable from a confirmed pre-existing defect and a proven regression. A verified blocking regression vetoes retention before paid scoped review. |
| F07 Repair economics | GEN-000021 spent about 67.2% of its estimated cost on Developer repair and scoped QA, or 71.9% with Boss escalation. Developer had a 16,384-token output reservation despite returning bounded patches. Native Developer repair uses budget-aware admission instead of the former artificial 4,096-token output cap. The initial QA handoff uses component context rather than a repeated full plan. Cumulative provider usage, response bytes, cache usage, and conservative reserves for unmetered calls are reported independently. |
| F08 Reusable surgery | Single- and multi-component edits use the same native plan and compiler. Style, structural and hybrid edits compile atomically. QA keeps the last verified improvement and records failed operations, measured outcomes, and eligible verified learning. The AI Knowledge Management UI remains planned. |

### Archive replay

- **GEN-000019** originally failed compilation after eight completed Haiku calls. The archived plan now passes after two deterministic corrections with zero new calls. Equivalent gradient endpoint stops normalize away and the Buttons top margin stays `0.5rem`. Approved text and links match the archived input.
- **GEN-000020** replayed against Chromium at 375, 768 and 1440 CSS px. Literal copy matched at every width, including the disputed closing sentence. The mobile-only CTA expectation remains a native capability issue.
- **GEN-000021** replayed with its original Boss QA findings. Both the root and the nested content group were available as wrap parents on the first assignment. An offline hybrid probe retained minimum height, used root vertical alignment, and added an inner 40rem constraint. The desktop title wrapping error went from one line to zero. Screenshot difference ratios at 375, 768 and 1440 px were `0.21631 / 0.13449 / 0.13684` before and `0.16114 / 0.12012 / 0.13915` after. The desktop alignment residual increased and the archived plan contained a literal casing difference. This probe demonstrates structural execution. Full fidelity for the archived design remains open. The historical fluid-gap patch was rejected by the capability gate in replay.
- **GEN-000021 cost** was estimated at **$0.583756** on Sonnet 5.5, with 90,130 uncached input tokens, 39,711 output tokens, 14,190 cache-read tokens and 1,419 cache-write tokens. Developer's three repair responses were 1,806, 1,536 and 1,508 text bytes, against reported output totals of 7,656, 8,249 and 6,518 tokens. The archive cannot attribute the difference to internal reasoning or reconcile an invoice. Live effects of the smaller cap and smaller prompt remain unverified. [Anthropic streaming usage](https://platform.claude.com/docs/en/build-with-claude/streaming), [Anthropic cache usage](https://platform.claude.com/docs/en/build-with-claude/prompt-caching)

### Native capability contract

| Capability | Boundary and validation |
| --- | --- |
| CTA width | `button.style.width: "100%"` is available when active core registers the button dimensions width support. This is fixed at every viewport and fills a correctly sized Buttons container. Preview and the WordPress front end use the same width behavior in the browser fixture. A desktop intrinsic CTA that becomes full width only on mobile remains outside this native contract. Route an unresolved breakpoint requirement to Boss. |
| Typography casing | `style.textTransform` maps to core typography for headings, paragraphs and buttons. The native label preserves its literal casing. Supported values are `none`, `uppercase`, `lowercase` and `capitalize`. |
| Fluid gap | WordPress 7.1.2 discards `clamp()` for layout block gap. A supported simple length can depend on theme block-gap settings. Legacy plans stay readable and produce layout notes. New unsupported gap surgery is rejected before rendering. Responsive child spacing is available where its semantic effect fits. |
| Vertical alignment and constrained width | Core flex and constrained layouts require different groups. An outer vertical flex group can retain minimum height and center a nested constrained group. The width applies to children of that constrained group. Theme-owned padding and other defaults can still affect front-end geometry. |
| Responsive spacing and lengths | Fluid native padding and margins support the bounded length grammar, including one spaced addition or subtraction in the middle of `clamp()`. Group, Columns and Buttons margin support top and bottom. Per-breakpoint style objects remain outside the contract. |
| Native nesting | Structural surgery wraps ordered, consecutive authorized direct children of an existing Group or Column into a new Group. It can combine up to two wraps and up to eight operations total with styles. The compiler validates native block relationships, block limits, identities and serialized output. Custom HTML and arbitrary CSS remain outside the plan schema. |

Core references for these mappings. [Block Supports](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-supports/), [Button metadata](https://github.com/WordPress/gutenberg/blob/trunk/packages/block-library/src/button/block.json), [Core layout implementation](https://github.com/WordPress/gutenberg/blob/trunk/lib/block-supports/layout.php)

## Verification

The checked-in suites use mocks or deterministic artifacts. The three private History replays were performed locally by reading the archived Developer and Boss responses, compiling the plans in the development WordPress runtime and inspecting the reference and candidates with isolated Chromium. Private job artifacts are retained in History.

| Suite | Local result |
| --- | --- |
| PHP real-AI contract fixtures | Passed with mocked provider and mocked stages. Covers scope, unchanged Boss instructions and Developer operations, plus strategy and escalation controls, compiler transaction rollback, usage and budget admission. |
| WordPress/Gutenberg | Core serialization and browser parity passed for 28 fixtures, plus both malformed serialization controls. Browser geometry ran at 375, 768 and 1440 CSS px. |
| Renderer | Real Chromium inspection, complete literal copy, transport/runtime limits, and WordPress recovery passed. Two deterministic benchmark trials retained identical weighted results, 26 to 18 for the GEN-015 hero and 20 to 0 for the callout, with zero model calls. |
| Backend and regressions | 44 backend tests, 197 updater assertions, credential lifecycle, 17 packaging tests, two Chromium import regressions and Compose validation passed. |

Run these checks against an isolated development stack. The MVP workflow provisions the renderer, composes WordPress, executes transport and editor tests, and builds both backend images. The updater workflow covers updater, credential, syntax and packaging checks. [MVP test workflow](../.github/workflows/mvp-pipeline-check.yml), [updater test workflow](../.github/workflows/updater-check.yml)

```sh
php tests/real-ai-poc-test.php
npm install --prefix backend --ignore-scripts --no-audit --no-fund
npm test --prefix backend
docker compose config --quiet
node --test renderer/inspect.test.js
node tests/adaptive-surgery-benchmark.cjs
docker compose exec -T wordpress php < tests/inspection-wordpress.php
docker compose cp tests/gutenberg-native-fixture.php wordpress:/var/www/html/sm-native-fixture.php
node tests/gutenberg-native-browser.cjs
php tests/updater-test.php
php tests/ai-credential-lifecycle-test.php
python -m unittest discover -s tests -p 'test_release_plugin.py' -v
```

The renderer browser tests expect the isolated renderer at `127.0.0.1:3999` by default. The editor gate expects the test WordPress at `127.0.0.1:8085` and a matching Playwright Chromium installation. The archive remains compatible with old snapshots. Those snapshots show copy evidence as unavailable until re-inspected. Rebuild the renderer alongside the plugin. Existing paused jobs still require the implementation signature they started with.

## GEN-000022 acceptance after explicit approval

1. Use a disposable WordPress deployment of this feature branch and the rebuilt renderer. Confirm Haiku 5.5 and the existing per-job budget through AI Integration. The development instance reported Haiku 5.5 and a $1.00 budget during this handoff. Leave the 80% repair gate and 20% escalation reserve in place.
2. Reuse the approved brief plus the same 375, 768 and 1440 CSS px views. Identify the expected vertical centering, title and intro measure, literal copy, button behavior and any explicit capability decision before generation.
3. Record the job ID, model, output caps, reported usage and cost, measured issue deltas, readiness status, any capability blocker or strategy deviation, and standalone editor validation. Check preservation of text, links and IDs. Treat an unresolved material mismatch as blocked even if screenshots improve.
4. Review that one authorized job before any additional trial. Require a reviewed, clean Gutenberg round-trip and verified viewports before promoting any retained artifact.

This engineering handoff executed zero paid AI jobs. Stable `v0.11.1` and `main` remain isolated from this checkpoint.
