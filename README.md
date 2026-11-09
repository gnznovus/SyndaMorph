# SyndaMorph

**From design to editable.**

SyndaMorph is a standalone WordPress plugin that imports static HTML into editable native Gutenberg blocks.

## Local development

Requirements: Docker Desktop with Compose.

```bash
docker compose up -d
```

Open **http://localhost:8085**, complete the WordPress installation, then activate **SyndaMorph** under **Plugins**. The plugin is bind-mounted from `./plugin`, so code edits are reflected without rebuilding the image.

Go to **Tools → SyndaMorph** to paste HTML or select an `.html`/`.htm` file. Select **Preview Import** to inspect the HTML in a sandboxed preview card. Only after reviewing it, select **Create Draft** in the modal footer to save a new page and open Gutenberg.

Stop the environment with `docker compose down`. To discard the local WordPress database and content, run `docker compose down -v` (**destructive**).

## P3 — Multi-page ZIP package import (development acceptance pending)

This adds a separate ZIP workflow under **Tools → SyndaMorph**, without replacing the existing single-HTML importer.

1. Select a **ZIP package** (32 MB compressed maximum) and click **Scan Package**.
2. The plugin discovers all `.html` and `.htm` pages (up to 30), shows their relative paths and lets you select any subset.
3. Click **Preview Selected Pages** and review every selected page. CSS linked by each HTML file is read from the ZIP and embedded for conversion; image `src` references are resolved relative to that HTML file and served through short-lived signed preview URLs. No images are uploaded to the Media Library at this stage.
4. Switch between pages with **Previous / Next**. **Approve & Import All** stays disabled until every selected page has a valid converted preview.
5. Choose **Draft pages** (separate Gutenberg page drafts) or **Unsynced patterns** (categorized under SyndaMorph). On approval, only images referenced by the approved Gutenberg content are uploaded to WordPress; identical image content reuses the same attachment using SHA-256. The importer rewrites package preview URLs to permanent Media Library URLs.
6. When successful, private staged ZIP data is deleted. On a failed batch, newly created pages and imported attachments are rolled back where possible. A canceled preview leaves no Media Library uploads. Staging expires after one hour and is cleaned on subsequent uploads.

**Designed for real Morpher ZIP exports:** HTML and matching CSS side by side, nested `assets/` folders, shared media, and separate pages. Use the provided `native(4).zip` package with its three HTML pages for local acceptance; the package itself is not committed to the repository.

### Security and scope

- ZIPs are stored in a private temporary directory outside the web root, with path validation and total/individual decompressed-size limits. Temporary image-preview URLs use short-lived HMAC signatures because image loads cannot attach REST nonce headers; signatures are bearer credentials, so do not share their URLs. All administrative endpoints require WordPress `edit_pages` permission.
- Relative linked CSS is **loaded**, but only SyndaMorph's supported native Gutenberg CSS subset is **converted**. Complex Morpher positioning, fixed pixel layout, backgrounds expressed as CSS `url()`, media queries and custom fonts are **not** reproduced automatically.
- Font files are detected within packages but are **not uploaded or registered as theme font families** in this first slice. Fonts and unused images are left untouched. Images referenced solely in CSS are not uploaded. External linked CSS is not fetched.
- Optional package-local `.js` scripts can be inlined only when the experimental **Render inline JavaScript** mode is explicitly enabled with the existing Docker renderer. Production-grade sandboxing remains outstanding.
- Assets are uploaded only upon approval, but WordPress Media Library attachment creation is not a database transaction. Unexpected server termination can leave partial uploads; the rollback is best-effort, not crash-safe journaling.

### Local testing

```bash
git fetch origin
git switch --track origin/feat/p3-package-import
docker compose up -d --build
```

Use **Tools → SyndaMorph → Import a ZIP package**. The Docker local PHP configuration permits uploads up to 40 MB; ZIP packages are limited by SyndaMorph to 32 MB.

For a small reproducible fixture, run `python tests/build-p3-fixture.py` to generate `tests/fixtures/p3-sample.zip`; scan it, preview both pages, and verify **one** image is uploaded even though two image filenames reference identical bytes. The third image is unused and must not be uploaded.

With `native(4).zip`, verify: **3 HTML pages** detected; each page's linked CSS and image references resolve; all three previews can be reviewed; batch draft import creates **3 separate editable pages**, or batch pattern import creates **3 unsynced SyndaMorph patterns**. Confirm no old temporary preview URL remains in saved image src attributes.

### P3 limits

No automatic import of fonts, CSS background images, absolute/fixed-position layouts, or browser/runtime behaviors. Package processing is synchronous in this prototype and can be slow on large image batches. A dedicated asset-index UI and production-grade resumable imports remain future work.

## P2 — Native Layout & CSS (accepted)

This initial P2 slice improves the editable conversion of explicit CSS values without introducing a rendering engine:

- Group, Columns and Heading/Paragraph blocks: `padding` and `margin` with explicit per-side values; generated as Gutenberg `style.spacing` attributes and corresponding inline CSS.
- Headings/paragraphs: `font-size` translated to `style.typography.fontSize`.
- Flex/Grid columns: `gap` or `column-gap` translated to the native Columns block's `style.spacing.blockGap`.
- Children of a 2–4-item flex/grid container: explicit percentage `width` or `flex-basis` becomes the native Column block `width` attribute (e.g. 65% / 35%).
- Supported source lengths are nonnegative `px`, `em`, `rem`, and `%`, with a conservative maximum numeric component; unsupported `calc()`, `auto`, negative offsets, CSS variables, and arbitrary CSS are intentionally ignored.

These are best-effort native settings, **not pixel-perfect CSS preservation**. Responsive behavior follows Gutenberg's built-in Columns block (the source CSS media-query breakpoints are not imported). CSS grid layouts with more than four direct children, nested complex grid placement, and absolute positioning remain future work. Static and JS-rendered DOM inputs share this conversion.

### P2 serialization regression

A P2 Gutenberg validation failure was traced to serializing Columns `blockGap` as a literal inline `gap:` declaration. Native Gutenberg stores the gap in the block attribute and generates the corresponding layout CSS separately. The saved Columns markup no longer includes the extra inline gap; the import preview adds it only to its temporary iframe markup. **Reimport the HTML and create a new draft after pulling the patch**: existing invalid draft block markup is not retroactively rewritten.

### P2 local acceptance

Use `tests/fixtures/p2-layout-spacing.html` in **static mode** and verify the heading's font size, 40px/24px hero padding, 32px gap, and 65%/35% columns in Gutenberg. Change padding and column widths from block controls; ensure **Preview Import → Create Draft** and **Save as Pattern** still behave as in P1. Recheck at desktop and mobile editor widths. No runtime acceptance has been claimed yet.

## P1 — HTML Rendering & Conversion (experimental)

**Static mode (default):** Parse HTML directly. No JavaScript is executed.

**Render inline JavaScript (experimental):** When running the Docker stack, check the option on **Tools → SyndaMorph** before **Preview Import**. WordPress passes the HTML to a separate Playwright renderer. The disposable browser worker captures the DOM after scripts run and returns it to the **same converter, preview, missing-assets checks, and pattern/draft approval flow** as static HTML.

- The renderer is an internal Docker service with **no published port**; the WordPress REST bridge requires `edit_pages` permission.
- Browser HTTP requests are aborted; **external JS bundles, CSS files, fetch APIs, framework CDN imports, and authenticated web apps are not supported**. Inline vanilla JS and short `DOMContentLoaded` / `setTimeout` DOM updates are the intended first use cases.
- A new browser process is started per render. The worker is terminated at 8 seconds if it hangs; input/output are capped at 2 MB. JavaScript is **never executed inside WordPress admin**, and scripts are removed before saving.
- The Playwright container runs with a non-root account, reduced capabilities, a read-only filesystem, resource limits and a separate internal network. **The Chromium sandbox is disabled**, however, so this is a **development-only proof of concept** and **must not be exposed as a public production service or used with untrusted HTML at scale**. Additional isolation and security review are required before production use.

P1-A additionally converts HTML **buttons / CTA links**, **ordered and unordered lists**, **tables**, **horizontal rules**, and basic inline formatting into editable native blocks. Complex nested lists, interactivity, advanced table styling, React/Vue bundling, network-backed components, and script-driven behavior after import are not covered.

### Verify on Docker 8085

```bash
docker compose up -d --build
docker compose logs -f renderer
```

Then test:

- `tests/fixtures/p1-native-blocks.html` in **static mode**: inspect native Button, List, Table, Separator blocks in Gutenberg
- `tests/fixtures/p1-js-dom.html` in **static mode**: dynamic room cards should not exist yet
- Import the same JS fixture with **Render inline JavaScript** checked: dynamic rooms, JS-updated heading, CTA, and missing-image placeholder should appear as editable native blocks
- `tests/fixtures/p1-render-timeout.html` **only in JS mode**: conversion should report a timeout instead of freezing WordPress; retry a valid fixture afterwards
- Confirm **Save as Pattern** and **Create Draft** still work and do not persist executable scripts

If the renderer is unavailable, JS mode reports a visible error; static mode remains usable.

## Missing CSS Quick Fix

When a linked stylesheet is missing (e.g., a same-origin 404 or empty/invalid link), the preview shows a **Missing CSS** warning and a **Quick Fix** button. Clicking Quick Fix opens `https://chatgpt.com/` in a new browser tab and copies a repair prompt containing the entire imported HTML to the clipboard. The user must **paste it into ChatGPT**, review the suggested CSS, and paste the repaired HTML back into SyndaMorph for another preview. No HTML is automatically transmitted to ChatGPT by the plugin. Check the HTML for sensitive material before choosing Quick Fix.

Cross-origin CSS that cannot be verified due to browser security restrictions is shown as **Unverified**, not assumed missing. External stylesheets are not otherwise imported into Gutenberg. Quick Fix is optional; users can still create a draft without it. This is a best-effort reconstruction, not guaranteed recovery of original design.

Smoke test: `tests/fixtures/missing-css.html
tests/fixtures/p1-native-blocks.html
tests/fixtures/p1-js-dom.html
tests/fixtures/p1-render-timeout.html`.

## Scenario #2 — Save as Pattern

SyndaMorph can save the current converted preview **or** the current content in the Gutenberg page/post editor as a native WordPress **unsynced pattern**. Pattern instances remain independently editable; editing an inserted copy does not update other copies.

- **Before creating a draft:** Tools → SyndaMorph → **Preview Import** → **Save as Pattern** → enter a name in the SyndaMorph dialog. No page draft is created.
- **From an existing draft/page/post:** Open the Gutenberg editor → editor options (three-dot menu) → **Save as Pattern (SyndaMorph)** → enter a name in the SyndaMorph dialog. The current editor content is saved as a pattern snapshot, including unsaved changes; it does **not** publish or modify the draft.
- Insert the saved design from Gutenberg's **Patterns → SyndaMorph** category.

Both actions store the same standard Gutenberg block markup as `wp_block` posts with `wp_pattern_sync_status=unsynced`, assigned to the native `wp_pattern_category` term **SyndaMorph**. The custom name dialog replaces browser prompts in both workflows. Patterns created before v0.6.1 remain in their previous category; only newly saved patterns are automatically categorized. Pattern creation requires permission to edit pages. This is a **full-content snapshot**, not a selection-only pattern; missing-image placeholders remain replaceable native Image blocks.

## Current POC scope

- **Preview-first approval:** renders an converted Gutenberg layout preview inside an isolated, script-disabled iframe; no draft is created until **Create Draft** is selected. Changing the imported HTML invalidates the preview and requires review again.
- The preview renders the **same converted Gutenberg markup** that will be saved on approval, using lightweight block-layout styling. It shows converted columns, constrained images, alignment and supported colors—not the original HTML/CSS. Active WordPress theme/editor styles may still differ.

- Creates a new WordPress **draft page**, leaving existing pages untouched.
- Converts `h1`–`h6`, `p`, `img`, and standalone links into native Gutenberg blocks.
- Converts semantic containers (`header`, `main`, `section`, `article`, `nav`, `footer`) and `div` into native Group blocks.
- Converts simple `display: flex` or `display: grid` containers with 2–4 direct children into native Columns/Column blocks.
- Supports a narrow CSS subset from inline styles and simple `<style>` selectors: text alignment, hexadecimal text and background color, explicit spacing, text sizes, and column gaps/widths.
- Accepts pasted HTML or a local HTML file (up to 2 MB).
- Executes no imported scripts. No proprietary package, custom editor, or WPML dependency.
- Does not preserve external CSS, complete layout fidelity, responsive media queries, pseudo-selectors, CSS variables, JavaScript-driven DOM, or imported media files.
- CSS grid/flex layout is approximated as equal-width native columns, not pixel-perfect positioning. Gutenberg validates saved markup; visually check each imported block for recovery warnings.
- **Missing image handling:** before preview approval, browser-load checks validate referenced images (up to 5 seconds per unique source, six parallel checks). Broken, absent, and unavailable sources become local visual placeholders in **native Image blocks**. The preview lists their original paths, and the editor's **Replace** control lets users select or upload replacement media. Missing assets never block draft creation.
- External images are referenced by URL, not downloaded into the Media Library. Network issues and slow endpoints may be classified as unavailable; retry preview if necessary.
- Maximum 500 generated blocks and 200 simple CSS rules per import. Intended as a POC, not production hardened.

## Structure

```text
plugin/
  syndamorph.php
  assets/import.js
  assets/editor-pattern.js
  assets/pattern-dialog.js
  assets/admin.css
  assets/missing-image.svg
  includes/render-endpoint.php
  includes/package-import.php
  assets/package.js
config/syndamorph-upload.ini
tests/build-p3-fixture.py
tests/fixtures/basic.html
tests/fixtures/layout.html
tests/fixtures/missing-assets.html
tests/fixtures/missing-css.html
compose.yaml
renderer/
  Dockerfile
  package.json
  server.js
  render-worker.js
```

## Origin

The first POC was validated in `gnznovus/syndacast-layout-engine` on branch `poc/html-to-gutenberg` (commit `aa736d9`). This repository carries forward the working importer under the SyndaMorph product identity, without importing the old Layout Engine architecture or history.
