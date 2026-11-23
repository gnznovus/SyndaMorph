# Real AI Production Team — POC #1 (Integrated)

**Status:** Four-agent proof of concept completed with real Claude Haiku 5.5 calls. Administrator-only, explicitly initiated, paid generation. No automatic publishing or executable preview.

## Architecture and boundaries

- **AI Production Team → Setup → Production brief → Task instructions** is the sole input for a real AI job. No source URL, HTML, ZIP, or separate POC textarea is required.
- **Run real AI team** appears between **Start simulation** and **Reset**. Simulation remains an optional, clearly labeled demo. Real runs invoke the actual backend, not authored dialogue.
- Source Import and **Convert & Preview** remain a separate deterministic Gutenberg conversion pipeline. A real AI run produces an HTML/CSS section **for review**, not a native Gutenberg pattern.
- The real route remains `POST /wp-json/syndamorph/v1/ai/team-poc`, taking `{"brief":"..."}` and protected by the WordPress administrator capability and REST nonce.
- `plugin/includes/ai-models.php`: dynamic provider catalog, separately maintained reference prices, and Anthropic generation adapter. Server-side cURL SSE is accumulated into bounded text; raw events are never intentionally exposed to the browser.
- `plugin/includes/ai-team-real.php`: synchronous Boss → Designer → Developer → Validator, four bounded calls with contextual handoffs. No retries, executing generated scripts, or publishing. Output caps are 16,384 tokens/agent for the streaming baseline.
- `plugin/includes/production-team.php`: unified Setup/Production/Results shell; no standalone POC card.
- `plugin/assets/production-team.js`: simulation, selection state, and unified job/reset lifecycle.
- `plugin/assets/ai-team-real.js`: real-job submit, safe text-only per-agent reports, metrics, feedback, and errors in the existing Production and Results panels.
- `plugin/assets/production-workflow.js`: existing deterministic Source Import inspection, conversion, preview and save actions (not changed by the AI POC).
- `tests/real-ai-poc-test.php`: offline mocked model and four-agent regression without paid calls.

## Running

1. Pull `feat/secure-gateway-leasing-v1`; hard-refresh the local WordPress UI (the plugin directory is bind-mounted).
2. In **AI Integration**, configure/test the Anthropic credential, load supported models, and save model selection plus maximum estimated per-job budget.
3. Open **AI Production Team → Setup**. Enter a clear task in **Task instructions** (12–2,000 characters). Source Import fields may remain untouched.
4. Select **Run real AI team** and confirm paid execution.
5. The Production tab indicates that the job is in progress. **Important:** Anthropic streams to WordPress, but the current synchronous REST endpoint does **not** push live per-agent status to the browser. Detailed events and token metrics become available only after completion.
6. Inspect **Results**: individual Boss, Designer, Developer and Validator output panels; input/output tokens, durations, estimated costs, and basic deterministic checks. Content is rendered as plain text and is **not** auto-published.
7. **Start simulation** still runs the authored dialogue demo; it is not proof of AI inference. **Reset** clears the current session display. Source Import conversion and its review/save workflow remain independent.

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

The validator flagged missing full-document and viewport markup, although the Developer was explicitly instructed to return a **section**, not a complete HTML document. This is an agent deliverable-contract inconsistency to refine, not proof that the section itself was invalid.

## Limitations and safeguards

- Both streaming and orchestration are synchronous server-side; the browser gets one JSON response at job completion. A distinct durable progress channel, job persistence, cancellation and partial-result recovery are not implemented.
- Streaming alone does **not** remove Anthropic's `max_tokens` cap. Each agent has a high ceiling, not unlimited output.
- Budget enforcement is an **estimate**, not a guaranteed upper bound on provider billing. Actual pricing/caching may differ. Partial calls may be billed even if the job fails.
- Raw generated HTML is not injected as executable DOM. Do not interpret deterministic structural checks as browser tests or sanitization sufficient for publishing.
- Only Anthropic generation is implemented; OpenAI/Gemini/Purr adapters are not yet plugged into this team runner.
- Keep the simulation labeled separately so authored dialogue is never mistaken for inference.
- External assets, scripts, form controls and remote URLs are deliberately disallowed in the POC Developer output.
- For production use, refine agent contracts, limit verbosity, introduce durable progress/results, and add dedicated Gutenberg conversion and rendering verification.

## Verification

```bash
php tests/real-ai-poc-test.php
```

Offline CI covers catalog filtering/cache, four-role handoff, output cap/budget behavior, token usage aggregation, refusal and `max_tokens` error classification. Live provider behavior and UI flows still require manual testing after changes.
