# Real AI Production Team POC #1 — provider adapter

**Status:** Prototype for manual, administrator-only, paid Anthropic testing. Never publishes WordPress content.

## Architecture

- `plugin/includes/ai-models.php`: provider-facing catalog and generation interface. Only the Anthropic adapter is implemented; unsupported providers return an explicit error. Provider model IDs/display names are fetched from `GET /v1/models`, not hardcoded.
- `plugin/includes/ai-team-real.php`: provider-neutral sequential Boss → Designer → Developer → Validator orchestration. Four calls using the selected model. No autonomous retries, no code execution, no publishing.
- `plugin/assets/ai-integration.js`: model picker, live refresh, administrator-entered token prices, estimated cost, and budget.
- `plugin/assets/ai-team-real.js`: standalone manual execution UI that does not interfere with the mock production workflow.
- `tests/real-ai-poc-test.php`: mocked, paid-call-free catalog/handoff/cost-limit regression.

## First local run

1. `git switch feat/secure-gateway-leasing-v1 && git pull` on the SyndaMorph repository.
2. If Docker and WordPress are already up, the bind-mounted plugin files update without a rebuild; hard-refresh WordPress.
3. Open **Tools → SyndaMorph → AI Integration**. Enter/test your Anthropic API key if not saved on this installation.
4. Choose Anthropic, then **Refresh provider models**. Pick an actual available model ID from the API response.
5. Enter **input and output USD per 1M tokens** from your verified provider billing tariff; these are not provided by model discovery and must not be guessed.
6. Set an **estimated per-job USD budget** (default UI value USD 1.00) and save.
7. Open **AI Production Team** → **Real AI Production Team POC #1**. Edit the example hero brief, click **Run real AI team**, and confirm the paid request.
8. Review agent outputs, deterministic checks, token usage and estimated cost. Generated HTML is presented as escaped text, not executed or inserted into WordPress.

**Important limitations**

- The positive rate and job budget are a preflight/stepwise **estimated guard**, not a guaranteed cap on provider billing. Real token charges, cache writes/reads, and special model pricing can differ.
- This POC uses 4 synchronous WordPress server-side Anthropic API requests. WordPress/PHP/network timeouts are possible; a durable queue and streaming status are future work.
- Validator's assessment is an AI opinion; the separate deterministic checks only verify minimum structural/safety signals. There is **no browser render, screenshot comparison, actual Gutenberg compilation, or fidelity benchmark** in this first pass.
- The model catalog caches successful lookups for 15 minutes and permits explicit refresh. If Anthropic is unreachable, last successful models are returned marked stale. Catalog is invalidated when the saved API key is replaced/deleted.
- The first release implements Anthropic only. The UI still lists OpenAI and Gemini for connectivity checks, but their generation/model adapters aren't wired. Purr will implement the same interface later.
- Cost pricing is clearly labeled administrator-entered, not current-provider-verified, and does not cover all token billing dimensions.
- This is administrator-only, local/internal POC code; there is no customer-facing publishing route.

## Automated verification

```bash
php tests/real-ai-poc-test.php
```

The test covers dynamic (mock provider HTTP) catalog filtering/caching/refresh, four distinct role handoffs, usage summation, basic deterministic checks, and budget rejection **without paid API calls**.
