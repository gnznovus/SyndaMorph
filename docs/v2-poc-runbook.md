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
- Sections are initially grouped by direct children of `main` and top-level header/footer.
  Complex nested sections may need manual adjustments.
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
