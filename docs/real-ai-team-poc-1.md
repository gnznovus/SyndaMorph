# Real AI Production Team — POC #1 and live production streaming


> **Current project status (2026-11-24):** This is the archived first real-AI team POC and historical streaming contract. For current milestones see [Master Plan](master-plan.md). The later Native Gutenberg collaboration pipeline now includes **implemented Adaptive Surgery v2**, but savings reported from deterministic fixtures are **not measured live-provider savings**. The original sequential Boss → Designer → Developer → Validator workflow remains distinct from **planned independent queues and queued bidirectional steering**. Do not read the historical per-agent 16,384 output cap as the current task-specific Surgery v2 repair cap.

**Status:** Four-agent proof of concept completed with real Claude Haiku 5.5 calls. Administrator-only, explicitly initiated, paid generation. No automatic publishing. HTML preview runs in a sandboxed, script-disabled iframe.

## Architecture and boundaries

- **AI Production Team → Setup → Production brief → Task instructions** is the sole input for a real AI job. No source URL, HTML, ZIP, or separate POC textarea is required.
- **Start Production** is the single AI action, followed by **Reset**. The authored simulator and separate Purr communication POC controls have been removed from the shell.
- Source Import and **Convert & Preview** remain a separate deterministic Gutenberg conversion pipeline. A real AI run produces an HTML/CSS section **for review**, not a native Gutenberg pattern.
- The UI calls `POST /wp-json/syndamorph/v1/ai/team-stream` with `{"brief":"..."}`, a WordPress REST nonce and administrator permission. The legacy JSON `/ai/team-poc` endpoint remains for compatibility, not UI usage.
- `plugin/includes/ai-models.php`: dynamic catalog, pricing and Anthropic cURL SSE parser. Parsed text deltas are forwarded through a bounded callback; raw provider protocol frames and credentials never enter the UI.
- `plugin/includes/ai-team-real.php`: Boss → Designer → Developer → Validator, four bounded calls and contextual handoffs. Sends `ready`, `agent_start`, `agent_delta`, `agent_complete`, `job_complete` or `job_error` events over SSE. No automatic retry, script execution or publishing; output cap is 16,384 tokens per agent.
- `plugin/includes/production-team.php`: unified Setup/Production/Results shell; no standalone POC card.
- `plugin/assets/production-team.js`: Source Import selection state, tabs and reset; authored simulation execution is removed.
- `plugin/assets/ai-team-real.js`: incremental browser stream reader, live rich-content agent feed, metrics and inspectable results, including a sandboxed HTML preview.
- `plugin/assets/production-workflow.js`: existing deterministic Source Import inspection, conversion, preview and save actions (not changed by the AI POC).
- `tests/real-ai-poc-test.php`: offline mocked model and four-agent regression without paid calls.

## Running

1. Pull `feat/secure-gateway-leasing-v1`; hard-refresh the local WordPress UI (the plugin directory is bind-mounted).
2. In **AI Integration**, configure/test the Anthropic credential, load supported models, and save model selection plus maximum estimated per-job budget.
3. Open **AI Production Team → Setup**. Enter a clear task in **Task instructions** (12–2,000 characters). Source Import fields may remain untouched.
4. Select **Start Production** and confirm paid execution.
5. The Production tab streams each agent's generated text as the provider emits it. Status and token metrics are sent at each handoff. If a transport intermediary buffers responses, delivery can be delayed despite application-level streaming.
6. Inspect **Results**: each agent's full text, input/output tokens, duration, estimated cost, structural checks and an isolated HTML preview. Nothing is auto-published or converted into Gutenberg blocks.
7. **Reset** clears the current display. Source Import conversion and its review/save workflow remain independent.

## Successful baseline — 24 November 2026

One four-agent run on `claude-haiku-5-5`, with the prompt requesting a responsive premium hotel hero section.

| Agent | Input tokens | Output tokens | Duration | Estimated cost (USD) |
| --- | ---: | ---: | ---: | ---: |
| Boss | 238 | 869 | 4,975 ms | 0.000458 |
| Designer | 1,181 | 4,422 | 20,488 ms | 0.002329 |
| Developer | 3,850 | 3,648 | 13,265 ms | 0.002209 |
| Validator | 6,688 | 7,576 | 35,089 ms | 0.004457 |
| **Total** | **11,957** | **16,515** | **73,816 ms** | **0.009453** |

All four reported `end_turn`. Model produced a complete HTML/CSS section and a detailed inspection report. Structural checks passed, but that does **not** establish visual fidelity, responsive behavior, Gutenberg-native editability or validator approval.

The validator flagged missing full-document and viewport markup, although the Developer was explicitly instructed to return a **section**, not a complete HTML document. This was an agent deliverable-contract inconsistency, addressed in production standard v1.0.0; the new behavior still needs live acceptance.

## Limitations and safeguards

- SSE streams incremental generation and completion events through one long-lived HTTP request. There is no durable job persistence, cancellation API, replay or partial-result recovery after reload. Reverse proxies/PHP buffering may impede live delivery.
- Streaming alone does **not** remove Anthropic's `max_tokens` cap. Each agent has a high ceiling, not unlimited output.
- Budget enforcement is an **estimate**, not a guaranteed upper bound on provider billing. Actual pricing/caching may differ. Partial calls may be billed even if the job fails.
- The HTML preview is isolated in a sandboxed iframe with restrictive CSP; it is still untrusted model output and does not establish production-safe sanitization, visual fidelity or editable Gutenberg output.
- Only Anthropic generation is implemented; OpenAI/Gemini/Purr adapters are not yet plugged into this team runner.
- The authored simulation is no longer mounted in the Production Team shell.
- External assets, scripts, form controls and remote URLs are deliberately disallowed in the POC Developer output.
- For production use, refine agent contracts, limit verbosity, introduce durable progress/results, and add dedicated Gutenberg conversion and rendering verification.

## Verification

```bash
php tests/real-ai-poc-test.php
```

Offline CI covers catalog filtering/cache, four-role handoff, output cap/budget behavior, token usage aggregation, refusal and `max_tokens` error classification. Live provider behavior and UI flows still require manual testing after changes.


## Production standards and rich reports

The team now uses shared versioned skills, techniques and nine default criteria, plus a local Markdown and limited Mermaid flowchart renderer for live and final agent messages. The section contract explicitly delegates document metadata to the host. See [production standards and readable communication](production-standards.md) for supported formats, verification boundaries and acceptance steps.
