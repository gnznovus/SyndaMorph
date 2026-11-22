# SyndaMorph demo MVP — implementation track

Target: **demo**, not a production-ready remote deployment platform.

## Outcome

Source reference → capture → classify → native/custom blocks → compile → autonomous WordPress/browser QA → portable package → lightweight client Unpacker → Gutenberg assembly.

SyndaMorph and the AI/browser toolchain stay on the internal build system. The client receives only its installed components and Patterns. A unique revocable site API key gates future Unpacker service access, **not** the functionality of already delivered software.

## Current slice (P0: autonomous pipeline foundation)

Branch: `feat/mvp-autonomous-pipeline` from `poc/hybrid-engine`; stable v0.11.1 and main remain untouched.

- `backend/mvp/plan.js`: versioned intermediate layout plan with distinct desktop/mobile order and source content references. Reject raw HTML and JS in a model response.
- `backend/mvp/registry.js`: approved, reusable custom block metadata and capability matching.
- `backend/mvp/loop.js`: injected deterministic compiler, independent validator and optional AI repair adapter. Bounded to 5 repairs, preserving best candidate and never accepting unresolved failures.
- `backend/mvp/loop.test.js`: contract, reuse, acceptance, regression and unsafe-output tests.

**Not yet connected:** live Playwright capture to layout-plan generation, AI adapters, real Gutenberg rendering/verdict metrics, generated custom block artifacts, package gateway or Unpacker. These files establish a tested orchestration contract; they do not claim an end-to-end demo yet.

## Demo acceptance

1. Capture the Hotel Indigo source at desktop (1440) and mobile (390); preserve independent responsive compositions.
2. Build five sections (Hero, Intro, Our Story, Rooms, FAQs) as editable Gutenberg Patterns; zero invalid blocks after edit/save/reload.
3. Build or reuse one interactive custom block and verify its actual browser interaction. Integrations without supplied API contracts stay explicitly unconfigured.
4. Run bounded source-vs-target visual comparisons; report measured differences and best valid candidate automatically.
5. Generate an installable component plugin, Patterns and media manifest.
6. Install in disposable client WordPress through a single-click prototype Unpacker and assemble a page.
7. Verify re-running an install does not create duplicates; revoking a demo site's API key blocks future package acquisition only.

## Boundaries

- `Fidelity > Editable > Reusable`; never pass visual match while block validity, content integrity or behavior fails.
- Use an approved component from the registry before proposing a new block; generated PHP/JS is build-time validated and never executed from an AI response directly.
- Claude access is not confirmed. A mock adapter and recorded evidence are sufficient for P0. API and CLI adapters will share the same plan contract.
- No automatic production publication, unrestricted site capture, privileged remote execution or claims of transactionally atomic WordPress rollback in the demo.
