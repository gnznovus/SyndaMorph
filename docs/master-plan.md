# SyndaMorph — Master Plan & Progress Tracker

**Updated:** 2026-11-24 (Asia/Bangkok)  
**Repository:** https://github.com/gnznovus/SyndaMorph  
**Experimental branch:** `feat/secure-gateway-leasing-v1`  
**GEN-000025 progression:** [Best candidate baseline and outcome-aware repairs](adaptive-surgery-hardening.md#gen-000025-and-the-verified-best-candidate).

**GEN-000023 investigation:** [Ranked causes, replay, and remaining limits](adaptive-surgery-hardening.md#independent-investigation-of-gen-000019-through-gen-000023).

**November 25 hardening checkpoint:** [GEN-000019 through GEN-000021 findings, tests, and GEN-000022 gate](adaptive-surgery-hardening.md).

**Adaptive Surgery v2 checkpoint:** [`54a1833`](https://github.com/gnznovus/SyndaMorph/commit/54a1833354081a04421138b6e993b2c347ccdc1e)  
**Stable release:** `v0.11.1`; protect `main`, tags, release ZIPs and updater workflow unless explicitly authorized.

> **Status key:** DONE = implemented with specified evidence; VALIDATION NEEDED = implemented but representative live production performance unproven; PLANNED = agreed future work; RESEARCH = option not yet accepted. Historical POC docs are evidence of earlier stages, not always the current operational contract.

## 1. Mission and design priority

Convert HTML, ZIP packages, JS-rendered websites and wireframes into **high-fidelity, editable, reusable WordPress Gutenberg websites**. Deliver detachable sections and native patterns by default, with reusable **engineered interactive Gutenberg blocks** when core blocks cannot reproduce verified behavior. Never substitute an uneditable, static Custom HTML blob for a required functional component.

**Priority:** **Fidelity > Editable > Reusable.**

**North-star flow:**

`Source intake → deterministic Playwright discovery → Boss classification → Designer reference → Boss approval → Developer native/custom block implementation → deterministic compile → WordPress/browser QA → adaptive surgery → reusable components → package → Secure Gateway → lightweight WordPress Unpacker`

SyndaMorph is an **AI production studio** whose deterministic tools capture, measure, validate and compile. AI agents reason about decisions where judgment is required. Backend does heavy work; WordPress retains lightweight UI, pattern/editor integration and unpacking.

## 2. Core architecture and agent roles

| Role / subsystem | Responsibility |
|---|---|
| **Boss** | Source and visual discovery; classify components and feasibility; plan assignments, enforce budgets, approve designs, validate output, route corrections/clarifications, resolve native capability gaps. |
| **Designer** | Create and iterate faithful visual references, layout and responsive states; submit evidence for Boss approval; preserve agreed interaction requirements. |
| **Developer** | Implement native blocks/compositions/patterns; eventually engineer custom reusable interactive Gutenberg blocks, JavaScript and integrations; perform scoped surgical repairs. |
| **QA / Validator** | Deterministic browser measurements, screenshot comparisons, WordPress registered-block checks, accessibility findings, regression classification and evidence-based verdicts. |
| **Global Playwright renderer** | Shared non-AI browser service for rendered DOM, screenshots, viewport measurements, geometry and comparison; expand later to full source/interaction discovery. |
| **Gutenberg compiler and component registry** | Versioned intermediate plans, deterministic serialization, validated reusable block/pattern assets and compatibility/provenance tracking. |
| **Secure Gateway / Unpacker** | Authorized client/package access, portable delivery and lightweight native WordPress assembly; do not bind installed content availability to remote leases. |

Original team architecture includes a **temporary freelance worker** option for independent bottlenecks, subject to budget/authorization controls. It is not yet implemented.

## 3. Current status

| Milestone | Status | Evidence / remaining limits |
|---|---|---|
| HTML → Gutenberg import, missing assets, patterns, ZIP workflow | **DONE (legacy foundation)** | Earlier local POCs and `v0.11.1`; not proof of full AI-site conversion. |
| Stable plugin GitHub updater | **DONE** | `v0.11.1`; a future version bump must also publish the matching GitHub release/tag and `syndamorph.zip`. |
| Operations Board, real-AI POC and logical agent roles | **DONE (MVP)** | Setup / Production / Results / History and integration settings; not independent parallel queues yet. |
| Isolated Playwright renderer and bounded native compiler | **DONE (existing)** | Known gaps for native fluid `blockGap`, `text-transform`, and advanced dynamic behavior. |
| Adaptive Surgery v2 | **DONE (engineering)** | F01–F10 corrected; [commit `54a1833`](https://github.com/gnznovus/SyndaMorph/commit/54a1833354081a04421138b6e993b2c347ccdc1e); [MVP CI](https://github.com/gnznovus/SyndaMorph/actions/runs/45436215694) successful. |
| GEN-000019 through GEN-000021 hardening | **DETERMINISTICALLY VERIFIED** | Scoped hybrid surgery, immutable handoffs, compiler rollback, native mappings, literal-copy QA and conservative metering. [Handoff and remaining limits](adaptive-surgery-hardening.md). |
| Best Candidate Baseline and progressive repair eligibility | **DETERMINISTICALLY VERIFIED** | GEN-000025 replay, atomic promotion, rollback and duplicate detection. [Evidence](adaptive-surgery-hardening.md#gen-000025-and-the-verified-best-candidate). |
| Surgery v2 with live AI across representative pages | **VALIDATION NEEDED — NEXT** | Deterministic benchmark success does not quantify live repair success or savings. |
| Secure Gateway/client-key package leasing | **PARTIAL / EXPERIMENTAL** | Branch implementation and dedicated contract; not assumed production-accepted. |
| AI Knowledge, full source+visual Boss discovery, custom block factory | **PLANNED** | Not part of Surgery v2 implementation. |
| Campaigns, asynchronous agent queues and steering | **PLANNED** | Architecture agreed, not implemented. |
| Full BKKGO conversion | **FUTURE ACCEPTANCE** | Site-wide end-to-end validation not yet performed. |

## 4. Adaptive Surgery v2 — completed implementation

**Origin:** Repeated regeneration of giant blocks to correct tiny defects wasted tokens and discarded verified work. New principle: **generate once → persist → diagnose → fix coordinated defects atomically → render/QA → retain safe improvement → learn**. When a localized repair is insufficient, authorize scoped reconstruction, not automatic whole-site regeneration.

**Resolved F01–F10:**

1. **F01 Preview parity:** constrained centering, width, spacing, vertical alignment and stacking matched WordPress core in tested cases.
2. **F02 Regression identity:** recognize pre-existing defects despite changed IDs; veto genuine new regressions, including mobile overflow.
3. **F03 Surgery authority:** a single authorized transaction permits **up to eight combined changes** including **up to two scoped wraps**, with whole-plan validation and protected content, links, IDs and unrelated siblings.
4. **F04 Feedback:** ancestor/descendant work orders retain attempted changes, rejection reasons, deltas and remaining issues.
5. **F05 Evidence:** reference/native bounds and viewport-grouped evidence, default **375 / 768 / 1440 px** with supported explicit widths.
6. **F06 Boss routing:** rank severe blockers, report rejected strategies and require explicit disposition of native capability gaps.
7. **F07 Retry and budgeting:** eliminate arbitrary stagnation caps, require distinct next strategy, and reserve Developer + QA together under **80% working budget**, protecting **20% for Boss reporting/escalation**.
8. **F08 Token discipline:** relevant image pair plus all requested measurement metadata, bounded output by task, measured usage and cost for success/failure.
9. **F09 Learning:** verified lessons keyed by defect, block, layout and compiler revision, recording retrieval/injection/application/outcome.
10. **F10 Tests:** acceptance, budget, renderer/WordPress and Gutenberg coverage updated; invalid fixtures rejected.

**Replay:** GEN-015 originally rejected four repair candidates (final weighted score 38), even though attempt three could reduce 38→34 with screenshot difference 15.24%→7.01%. New acceptance retains that improvement because the alleged centering regression was already present; introducing actual mobile overflow still blocks retention.

**Repeatable v2 Chromium fixture benchmarks (predefined patches, not live-model generation):**

| Fixture | Score | Retained score gain | Screenshot difference |
|---|---|---|---|
| Hotel hero | 26→18 | **30.8%** | 17.99%→9.17% |
| Gallery callout | 20→0 | **100%** | 3.12%→0.00% |

**Critical:** v2's score rubric and viewport coverage changed. The old GEN-015 score 38 cannot be compared directly with v2 26. The benchmarks prove the *pipeline can preserve* >25% gains; they do not establish production-wide results or live-provider savings.

**Controlled learning fixture:** matched scripted trials reduced repair/review calls **8→4**, total calls **18→14**, simulated repair input tokens **9,600→4,800** and output tokens **1,200→600**, with equivalent retained score. These are fixture outcomes, not measured live-model improvements. Archived GEN-015 consumed **36,572 uncached input**, **11,939 output**, and **8,514 cache-read** tokens at approximately **$0.009712**; its offline Chromium replay required no model calls.

**Remaining capability gaps:** fluid `blockGap` in the tested WordPress core, breakpoint-only CTA width and block spacing, and theme-dependent front-end defaults. Native casing and fixed CTA width are mapped where supported. [Current capability matrix](adaptive-surgery-hardening.md#native-capability-contract).

## 5. AI Colosseum — future model routing

Provider-neutral agents and model benchmarking, with champion classes **Cheap**, **Balance** and **Fast**. Rank model+role by total delivered quality, Gutenberg validity, repair success, cost and elapsed time rather than raw response quality or provider marketing. Initially one paid provider/model can represent multiple logical roles. Multi-provider credentials, benchmarking and automatic role-specific routing are **future milestones**, not implemented merely by the real-AI MVP.

## 6. AI Knowledge Operations Board — planned, deferred

Extend the existing Operations Board, rather than create a second control panel. Add an **AI Knowledge** sidebar destination with four tabs:

1. **Skills**
2. **Techniques**
3. **Rules**
4. **Adaptive Learning**

Each tab offers **Global / Boss / Designer / Developer** scope. Support active versions, provenance, assignment, safe import, rollback and evaluation. Move management controls for Adaptive Learning from **History** but retain immutable History as evidence and audit source. External `SKILL.md` material requires license/security checks and test-before-promote. Hard safety, spending, approval and compiler restrictions remain **immutable in code**, never overridable by skills or agent prompts.

## 7. Boss discovery & custom interactive Gutenberg block factory — planned

Boss must inspect **both source code and rendered browser behavior**. Code shows implementation/dependencies/events; browser evidence shows what actually works. Reconcile both; record uncertainty instead of inventing API behavior.

Produce a versioned **Component Discovery / Implementation Manifest** containing boundaries, native core/composition/custom block classification, responsive states, interaction contracts, data/API dependencies, reusable candidates, ownership and evidence.

Developer eventually creates proper registered reusable Gutenberg blocks: configurable editor attributes/controls, accessible functional frontend behavior, scoped assets/scripts, validation, package dependencies and WordPress editor/frontend tests. Do not call a visually convincing booking-bar mockup a functioning booking integration. Native patterns remain preferred when sufficient.

## 8. Production Campaigns — planned

Large sites (10–20+ eligible sections) exceed sensible single-job budgets. Use **one campaign** with many separately executed tasks, dependency graph, versioned artifacts, section-scoped QA and restart-safe checkpoints. Discovery/capture is reused; approved sections are not regenerated. Each job respects the 80/20 correction/report split, and the **campaign has its own hard overall spending ceiling** (splitting jobs does not evade it).

Integration QA checks combined site navigation, responsive consistency, shared assets, patterns/custom blocks and WordPress editability. Budget exhaustion means **paused/resumable**, not automatic loss of progress. Historical job and lesson links are retained.

## 9. Parallel independent queues and bidirectional steering — planned

Optimize **campaign throughput**, not multiple agents competing over the same section:

- Boss independently processes discovery, design/implementation reviews, approval and escalation queues.
- Designer finishes a section's reference, submits it to Boss's queue, then starts the next independent design.
- Developer finishes an approved section, submits it to validation, then takes the next approved build.
- Boss can route revisions to Designer or Developer; either agent can request clarifications/approvals from Boss.

**Steering v1 invariant:**

> **Clear or safely checkpoint the current assignment before sending or processing steering. Steering never interrupts active work.**

Steering is **queued bidirectional agent communication**, not forced preemption or human mid-call changes. An affected task waits on a request; the worker can continue other eligible tasks. Steering records sender/recipient, campaign and section, artifact version, evidence, request, priority, response and dependency. Use atomic claiming, worker leases, idempotent handoff, stale-artifact approval rejection and total campaign budget admission. High priority affects the **next** queued assignment only.

## 10. Packaging, gateway and lightweight Unpacker — planned integration

Version validated native patterns/custom blocks with their asset manifests, compatibility and source evidence. Build portable packages for pages and sites; avoid duplicate media/assets and preserve independent Gutenberg editing. Secure Gateway authorizes future discovery/download by customer credential, package ownership and active lease; do not revoke functionality of already-installed content. Client WordPress remains a lightweight Unpacker rather than hosting the AI/browser backend. Separate site-delivery acceptance is required before production claims.

## 11. Full-scale test: BKKGO

**Reference:** https://www.syndacast.com/2026/BKKGOPrototype1/index.html

JS-rendered Hotel Indigo Bangkok Thonglor prototype: two-level navigation, hero with overlapping booking selector, story, offers, rooms, dining, neighborhood, social, signup, FAQ and footer. The booking selector is an example of an interaction whose actual source/behavior must be inspected before custom-block classification.

**Final acceptance:** verified source + visual discovery; editable native sections; functionally correct custom blocks where justified; text, links and assets preserved; desktop/tablet/mobile fidelity; responsive QA; integrated WordPress editor/frontend checks; reusable patterns and secure portable deployment. **No full-site BKKGO acceptance has been recorded yet.**

## 12. Prioritized roadmap

| Order | Milestone | Status / exit evidence |
|---|---|---|
| 0 | HTML importer/patterns + released `v0.11.1` | **DONE**; stable branch/release protected. |
| 1 | Real AI Team MVP + native compiler/Playwright foundations | **DONE (POC)**; not full production conversion. |
| 2 | Adaptive Surgery v2 F01–F10 | **DONE (engineering)**; `54a1833`, CI, archived replay, deterministic benchmark. |
| 3 | **Authorized follow-up after GEN-000025** | **NEXT — VALIDATION NEEDED**; actual provider usage, outcomes, Gutenberg integrity, and safe regressions. |
| 4 | AI Knowledge management | **PLANNED**; board UI, role-scoped/versioned instructions, verified learning registry. |
| 5 | Boss source+visual discovery and implementation manifest | **PLANNED**; auditable structural/behavior classification. |
| 6 | Developer custom interactive block factory | **PLANNED**; registered reusable block with working editor/frontend. |
| 7 | Component registry, packaging, Secure Gateway and Unpacker integration | **PARTIAL foundation / PLANNED end-to-end**. |
| 8 | Persistent Production Campaigns | **PLANNED**; multi-section budgeted queue/checkpoint/recovery. |
| 9 | Independent agent queues + noninterruptive steering | **PLANNED**; continuous pipeline and directed corrections. |
| 10 | AI Colosseum automatic model routing | **RESEARCH / PLANNED**; comparable champion benchmarks. |
| 11 | Full BKKGO site conversion | **FUTURE ACCEPTANCE**; integrated fidelity/behavior/editability/package test. |

This order describes dependencies, **not promised release dates**. Do not expand active Codex/Surgery work into these future milestones implicitly.

## 13. Acceptance gates and evidence

- **Surgery improvement:** 15–20% retained weighted issue-score gain in testing; >=25% target for coordinated repair operations. Track failures and score distributions, not only best fixtures; allow smaller safe improvements without claiming KPI success.
- **Cost:** input/cache/output tokens, provider pricing, estimated USD, rejected attempts, accepted improvements/dollar, and campaign-wide cap.
- **Correctness:** no new critical regressions, unintended scope changes, content/link/ID loss or unauthorized code execution.
- **WordPress:** parse/serialize validity, registered-block checks, frontend/editor function, responsive layout and accessibility.
- **Learning:** retrieval, injection, adherence and verified effect; controlled learning-on/off study and reproducible source evidence.
- **Throughput:** completed/approved sections per elapsed time, queue idle/wait, blocker resolution, retries and integration defects.

## 14. Governance and documentation

- **Current branch:** `feat/secure-gateway-leasing-v1`; keep `main`, `v0.11.1`, published tags/releases and production behavior untouched until approval.
- **Canonical source:** Update **this file in place** when a milestone changes; other documents link here and preserve historical POC procedures.
- Record date, exact version/commit, tests/CI, actual limitations and acceptance class whenever moving a milestone.
- **Next action:** obtain explicit approval for a follow-up Haiku 5.5 run after rebuilding the feature branch renderer and reviewing the GEN-000025 findings. AI Knowledge remains planned.
