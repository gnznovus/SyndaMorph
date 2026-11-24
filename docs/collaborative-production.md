# Collaborative Gutenberg Production — development branch

This specification applies only when **Native Gutenberg** is selected. HTML/CSS production retains its four-agent stream and explicit manual conversion. No automatic publishing or paid retries occur.

## Contract

1. Boss defines criteria; Designer specifies visual direction.
2. HTML Reference agent generates a scoped, self-contained HTML/CSS section; it is the *visual target*, **not** the Gutenberg deliverable.
3. Developer emits a constrained version-1 native block plan with stable component IDs. The deterministic PHP compiler produces core blocks; arbitrary CSS/HTML is rejected.
4. A network-isolated Playwright renderer inspects HTML reference and native preview independently at 1440, 768 and 390 CSS px. JS is disabled for inspection, external requests blocked. Screenshots, element geometry, computed CSS, horizontal overflow, accessible-name indicators and automated WCAG axe-core findings become QA evidence.
5. The same isolated service calculates screenshot pixel difference using previously collected JPEGs. This is a coarse visual-difference metric (lossy JPEG, sampled pixels), **not** a pixel-perfect equivalence assertion. DOM/typography comparison maps matched text nodes back to plan IDs.
6. The Validator receives measured evidence and four genuine desktop/mobile screenshots as Anthropic image messages. It records findings by component ID and must identify unverified issues.
7. The orchestrator chooses only pending impacted components. Boss makes a scoped delegation decision and can request a narrowly focused Designer correction before Developer changes a component. A revision modifies a single existing node with the same ID and type. The original HTML reference, unaffected siblings and their findings are preserved. Per-component fingerprints distinguish direct edits from ancestor changes; descendant findings are invalidated while unrelated sibling evidence is reused. Every candidate is compiled and rendered again at all widths; a scoped vision review covers the modified component; the deterministic findings and visual score determine whether a candidate is retained. We preserve the best-scoring artifact.
8. Cost admission is deterministic and based on configured per-million input/output token prices. Each request pre-reserves expected input/maximum output and image overhead; before revision, conservative projected spending must remain under **80%** of max-job USD. This reserve does not promise exact provider billing: caching and image token usage may vary. At most three revisions are attempted. Failed or over-budget repairs do not trigger unrestricted retries.
9. The terminal artifact, explicit stopping reason, impact/reuse metadata and issue/revision history are archived. Pending findings => **Uncompleted** and best artifact remains exportable. No outstanding findings => **Needs review** until the WordPress block editor parser and registered block validation verify the exact generated block content. Only eligible records with verified evidence become **Completed**. Browser output preview is never evidence of a full WordPress editor round-trip.

## Data and evidence

- `artifact`: `schemaVersion`, `content`, `preview`, `contentSha256`, `checks`, `blockCount`
- `referenceHtml`: immutable HTML design target
- `quality.issues`: structured outstanding findings with stable component owner IDs
- `quality.revisions`: attempted changes, previous/new issue counts, accepted/rejected decision, evidence hashes
- `quality.evidence`: three-width DOM snapshots and hashes, bounded base64 desktop/mobile screenshots, separately for reference and best native artifact
- `quality.pixelComparison`: per-width sampled image differences
- `usage`, `agentMetrics`, `estimatedCostUsd`, `budgetUsd`: estimates and per-request usage

Archived jobs are private to administrators; image evidence is not served by an unauthenticated route. The browser renderer has its own Docker network. Explicit draft/pattern save is unchanged; output status alone never publishes a page.

## Deployment and acceptance

Use `compose.yaml` with `SYNDAMORPH_RENDERER_URL=http://renderer:3000/render` for the private WordPress-to-renderer link. The native collaborative branch requires the same renderer service to handle `/inspect` and `/compare`; if unavailable it must not report QA success.

- Run `php tests/real-ai-poc-test.php` and `node --check` on changed JS, plus `php -l` on PHP.
- Build the renderer Docker image; run `node --test renderer/inspect.test.js` against port 3999 in CI to exercise real Chromium, axe-core, pixel comparisons and the legacy /render endpoint.
- Test HTML-only production with existing four-call mock regression; verify source import / conversion never changes.
- Test Gutenberg mode in a disposable WordPress environment: verify real HTML and native block output; inspect browser QA and revisions; check spending thresholds and History, use Save & Open Editor and ensure no Gutenberg invalid-block recovery warnings.
- Never spend actual model tokens without authorized API settings. Automated CI mocks provider calls; live visual fidelity and WordPress Gutenberg browser acceptance remain separate checks.
