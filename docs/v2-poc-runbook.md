# Hybrid engine POC — Local verification

Branch: `poc/hybrid-engine`. V1 remains available under **Tools → SyndaMorph**.

## Start

```sh
docker compose up -d --build
```

Open `http://localhost:8085/wp-admin/tools.php?page=syndamorph`.
Under **Capture a wireframe URL (V2 POC)** enter
`https://www.syndacast.com/2026/BKKGOPrototype1/index.html`, press **Detect Sections**,
choose a section, and press **Load into HTML Preview**. Review the existing preview and
use **Save as Pattern** to exercise the Gutenberg flow.

The backend is a separate container (`backend/`), not PHP processing. The old `renderer/`
remains unchanged and network-denied for inline-JavaScript conversion.

## POC limitations

- Single page and its same-host resources only; no sitemap, recursive crawl, AI, portable ZIP, or asset transfer.
- Only `www.syndacast.com` is approved in local Compose. Other sites require deliberate approval in
  both WordPress and backend environment settings. Never expose the service directly to the internet.
- Browser capture is used by default for accurate rendered DOM; static mode disables JavaScript.
  This is not yet a static-first detector, despite the planned V2 direction.
- Sections are identified using semantic section containers and geometry-aware fallback through layoutless wrappers. Unsupported complex structures may still need manual adjustments.
- Document metadata, linked stylesheet tags, script/iframe elements and event-handler attributes are excluded from converted sections. Large or zero-height sections are omitted from the selector rather than handed to the converter.
- The existing 500-block conversion limit remains unchanged; hitting it after this patch indicates an oversized detected section that needs further segmentation.
- Captured inline computed styles are best-effort. External assets are still referenced remotely.
- Capture is a synchronous request in this first POC; asynchronous job IDs, polling, cancellation,
  persistent progress and long-running workers are future work.
- This does not guarantee pixel-perfect fidelity or behavior of JavaScript controls. Verify in
  the real WordPress editor after save/reload and record visual differences.
- Network safety presently uses a strict configured hostname allowlist, initial DNS checks and
  request routing restrictions. Before production URL inputs, add an egress firewall or hardened
  DNS/proxy layer that enforces resolved destination IPs to close rebinding gaps.
- The shared local backend token is a development placeholder. Use a secret in any deployment.

## Test

```sh
npm install --prefix backend
npm test --prefix backend
node --check plugin/assets/url-capture.js
php -l plugin/includes/capture-endpoint.php
docker compose config --quiet
```

No live browser/Gutenberg integration check is claimed until manually verified.

## Regression: Hotel Indigo sections (2026-11-20)

1. Rebuild the capture container and reload the WordPress dashboard.
2. Paste the Hotel Indigo prototype URL and click **Detect Sections**.
3. Confirm multiple non-zero-height sections; there should be no 0px catch-all `x-dc` wrapper.
4. Select a smaller section. The HTML textarea should **not** contain `<helmet>`, document `<link>`, `<style>` or `<script>` tags.
5. Click **Preview Import** and confirm no 500-block-limit failure for that section.
6. Save as a Pattern and check editor save/reload. Record any remaining fidelity differences.

Run unit tests with `npm install --prefix backend && npm test --prefix backend`.
The automated tests exercise layoutless wrappers and serializer cleanup; real-page fidelity still needs browser acceptance.

## Render diagnostics checkpoint

Click **Detect Sections** and expand **Capture diagnostics**. The report contains
bounded HTML structure summaries, headings, body/main geometry, stylesheet
availability, blocked-resource counts and browser JS errors. It deliberately
does not include the full page HTML or any form values.

For the Hotel Indigo prototype, use **Copy Diagnostic JSON** and inspect:

- `visibleElements`, `body/main` dimensions: can the browser measure layout?
- `network.denied` and `network.samples`: did capture block styles/scripts?
- `stylesheets`: were CSS rules attached/readable?
- `headings` and `bodyChildren/mainChildren`: do actual section boundaries exist?
- `semanticSections` compared to `sectionCount`: are we losing groups in section detection?

Only after diagnosis should we alter segmentation. The previous 3-section
structure-only output is **not accepted as fidelity evidence**.

## Rendering root cause — blocked runtime dependencies

The Hotel Indigo diagnostic capture found `<x-dc style=display:none>`, zero
visible elements, no main landmark, and failed React loading from `unpkg.com`.
This is an **unrendered template**, not proof that the page has three sections.

The capture backend now distinguishes permitted page navigation hosts from a
small, explicit third-party resource-host allowlist. Local Compose approves
`unpkg.com`, `cdn.jsdelivr.net`, `fonts.googleapis.com`, and
`fonts.gstatic.com` for assets only. Additional missing dependencies must be
identified from a new diagnostic report before approving them. Browser readiness
is bounded; when visible content still does not render, no candidate sections
are offered and the dashboard reports the missing rendering prerequisite.
Do not return structure-only heading fragments from a known-hidden template.

This is a **development-only** allowlist, not a complete production network
sandbox. Before public URL input, enforce destination IP policies at network
egress and block DNS rebinding, IPv6 private ranges and redirects. Never expose
the capture service publicly.
