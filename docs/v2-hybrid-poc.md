# SyndaMorph V2 — Hybrid Engine POC

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
