# Production standards and readable agent communication


> **Current project status (2026-11-24):** Version 1.2.0 below is a documented historical standards baseline, not proof of every future capability. See [Master Plan](master-plan.md). Surgery v2 (commit [`54a1833`](https://github.com/gnznovus/SyndaMorph/commit/54a1833354081a04421138b6e993b2c347ccdc1e)) adds evidence-based native acceptance and learning tracking; it does **not** turn prompt-only standards into security enforcement. Future **AI Knowledge** management proposes Skills / Techniques / Rules / Adaptive Learning with Global, Boss, Designer and Developer scopes. Security, budget, authorization, content and compiler invariants must remain enforced in code.

## Production standard v1.2.0

`plugin/includes/production-standards.php` is the shared, versioned source of truth. Role-specific skills, shared techniques and default acceptance criteria are injected into each agent's system instructions. The user still supplies only a brief.

| Criterion | Scope |
| --- | --- |
| SEO-01 | Semantic HTML, meaningful headings and links |
| AIO-01 | Clear, factual, structured content; no ranking guarantees |
| RES-01 | Mobile/tablet/desktop layout, reflow, overflow |
| FLUID-01 | Fluid type, spacing, flexible layout and useful fallbacks |
| A11Y-01 | WCAG 2.2 AA target; distinguish static and runtime evidence |
| PERF-01 | Dependency and markup efficiency; no unmeasured claims |
| MAINT-01 | Scoped CSS, tokens and reusable structure |
| SAFE-01 | No executable scripts, external assets or unsupported integrations |
| WP-01 | Native Gutenberg compatibility planning; conversion not yet verified |

The deliverable remains an embeddable section with scoped CSS, not a standalone document. The preview host supplies viewport metadata. Page-level SEO belongs to the final host document. The Validator must report every criterion as Pass, Fail, N/A or Unverified with supporting evidence, and must not demand document wrappers in a section.

These are prompt-level requirements, not proof that a criterion passed. The existing four structural checks remain independent and unchanged. For native Gutenberg jobs, three-width browser snapshots, axe-core accessibility checks, screenshot similarity and vision reviews are collected by the collaborative engine; only affected components receive revision-specific AI reviews. WordPress registered-block validation is an independent client-side gate before completion. HTML-only jobs retain the original four-agent sequential flow and static checks.

## Shared report renderer

`plugin/assets/production-rich-content.js` is used in both the live feed and Results. It renders headings, emphasis, inline code, lists, blockquotes, tables and fenced code. Raw mode preserves the original text. Code blocks include copy controls. Developer HTML remains inspectable source, separate from the isolated HTML preview.

Diagram support is a local, bounded subset of Mermaid flowchart notation: flowchart/graph TD, TB, LR, RL or BT; named nodes; single edges per line; optional edge labels. Unsupported syntax falls back to source text. This is not the full Mermaid engine and does not support sequence diagrams or arbitrary Mermaid directives. Code is displayed in monospace, not token-level syntax highlighting. Markdown rendering is similarly a limited subset, not a complete CommonMark/GFM engine.

The renderer constructs DOM/SVG nodes with textContent; raw HTML and Markdown links are never executed or activated. It does not request external assets or use a CDN. During streaming, repaints are throttled; diagrams appear only when the closing fence is present.

## Acceptance

1. Open Setup and start a brief-only job; verify the feed renders headings and lists incrementally.
2. Inspect the Validator report: all nine criteria should be addressed, and the section must not fail solely for missing host metadata.
3. Check a generated Markdown table, inline code, and Raw/Rendered switching in both feed and Results.
4. Request an optional simple Mermaid flowchart in the brief; check the diagram and original source. Unsupported diagrams should remain readable source.
5. Confirm a model-generated HTML tag in a report is displayed as text, not executed.
6. Inspect the Developer HTML preview at different widths; it is review-only and not browser QA.
7. Verify Source Import behavior and reset remain unchanged.

Run the MVP CI workflow for PHP/JS syntax and mocked orchestration checks. A live paid run and UI acceptance are separate from CI.

## AI-first architecture decision

AI Production is the primary development and workspace path. Deterministic Source Import remains available as an explicit fallback. HTML mode is brief-only; Native Gutenberg mode generates a separate HTML/CSS design reference internally, then compiles a restricted native block plan and validates browser evidence. Source Import is **not yet** connected as an externally supplied AI design reference. Retain capture, extraction, asset management, history, previews and save endpoints. Benchmark AI-native Gutenberg against the fallback on Fidelity > Editable > Reusable before considering retirement. No changes to the stable release.

## Production failure diagnostics

Terminal errors are displayed in the live feed and recorded in History. Model generation failure is distinct from successful generation followed by deterministic compiler rejection. The error payload includes stage, code, role, completed model calls and, where available, rejected field path, stop reason and usage metrics. Replay displays recorded failures without inference. Existing archived errors retain only the diagnostics originally recorded. HTML mode makes no automatic repair calls. Gutenberg mode can make paid, budget-authorized, component-scoped repair calls after real QA evidence; see [collaborative production](collaborative-production.md).
