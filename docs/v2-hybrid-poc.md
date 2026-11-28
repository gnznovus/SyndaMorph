# SyndaMorph V2 — Hybrid Engine POC


> **Current project status (2026-11-24):** This V2 hybrid POC is historical input to the [canonical Master Plan](master-plan.md). Native/editable Gutenberg conversion and a deterministic browser service remain core architectural decisions. The future full website design requires **Boss source-code + rendered-visual + interaction classification**, **Developer custom interactive Gutenberg block engineering**, and reusable packaging; those capabilities are **planned**, not delivered by this earlier single-page URL POC. Surgery v2 in the separate collaborative pipeline is implemented but still awaits live-provider acceptance.

Status: experimental. This branch must not change the released plugin behavior or release pipeline without explicit acceptance.

## Product target

Convert a specified wireframe page into detachable, native editable Gutenberg Patterns.

Priority: **Fidelity > Editable > Reusable**. A visually faithful result that cannot be edited as native Gutenberg blocks does not pass POC acceptance.

## Architecture

- **WordPress SyndaMorph plugin:** user-facing dashboard, job submission, preview/section selection, WordPress-native Pattern creation, Media Library management.
- **External engine:** single-page HTML/URL acquisition, browser rendering when needed, visual evidence capture, section detection, conversion work.
- **WordPress/Gutenberg:** final serialization and browser-based fidelity/editability verification.

Existing `renderer/` is an inline-HTML, network-denied development renderer. It is **not** a live-URL extractor; retain its existing behavior while developing the new engine separately.

## URL extraction contract

Input: one explicit http(s) URL. **Do not crawl** linked pages, sitemap indexes, or other destinations. Fetch only dependencies necessary for rendering the requested page.

Capture:
1. Obtain original HTTP HTML and effective URL, retaining response/error evidence.
2. Evaluate static completeness; use bounded Playwright rendering when needed or explicitly requested.
3. Preserve rendered DOM, URL/base resolution, computed visual styles, element geometry, screenshots, and referenced asset metadata.
4. Keep section boundaries tied to source DOM nodes and stable IDs so sections can be detached without re-detecting after conversion.

Security: reject non-http(s) schemes, credentials, loopback/private/link-local/metadata addresses and unsafe redirect targets; prevent DNS rebinding; constrain network egress, browser permissions, page size, time, concurrency, and resource use. Never execute arbitrary URL capture from the public WordPress request lifecycle.

## POC acceptance

Fixture: `https://www.syndacast.com/2026/BKKGOPrototype1/index.html`.

1. The WordPress dashboard submits the exact URL, obtains a job ID, and displays bounded processing status.
2. The engine captures one page and required assets without recursively crawling other pages.
3. One identified section can be selected and converted into native Gutenberg blocks.
4. Create a Gutenberg Pattern in the test WordPress instance and verify its editability after save/reload.
5. Compare the Pattern against the reference at a defined viewport; record fidelity differences and unsupported features instead of silently flattening.
6. Existing V1 plugin tests and release workflows remain unchanged.

## Explicitly out of POC

Production deployment, AI assistance, multi-page crawl, portable ZIP distribution, unpacker design, automatic cross-site Pattern installation, and full-site import.

## Implementation sequence

1. Isolated, safe single-page URL acquisition + static/browser snapshot contract and tests.
2. External job API and WordPress dashboard integration.
3. Section identification/selection and a single native Pattern conversion.
4. Real Gutenberg browser acceptance and fidelity report.

## Fidelity recovery

The URL capture dashboard offers content roots first and lists interactive components for review. It records header, footer and overlay roots in a separate disclosure. Each captured root carries a source selector and its bounds. Positioned elements extending beyond a section are reported before preview.

Browser captures convert supported grid placements and growing Flex children to native Columns. Captured typography and image sizing use native block settings. Columns stack on small viewports. The dashboard shows source font dependencies and the actions that need a separate integration. FAQ rows with an available answer become native Details blocks. A recognizable carousel keeps the visible slide in the layout and stores the other captured slides in a closed Details block.

Acceptance for this iteration uses the live Hotel Indigo wireframe at 1440px and 390px. Check Hero, Hotel intro, Our Story, Rooms and FAQs. Preview and save each as an unsynced Pattern. Insert the saved blocks into a disposable page, edit text, save and reload. Confirm that the edits persist and Gutenberg reports zero invalid blocks. Inspect the saved column widths and toggle native Details on both viewports. Compare the original mobile markup with the responsive Pattern since the wireframe has separate desktop and mobile DOM trees.

The remaining fidelity work is to carry device-specific composition across to a single editable Pattern, close differences caused by theme styling and font availability, and handle richer grid placements. Booking, form submissions, room switching and missing FAQ states require separate source content or integrations. Production capture isolation and asset portability remain separate milestones.
