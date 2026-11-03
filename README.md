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

## Missing CSS Quick Fix

When a linked stylesheet is missing (e.g., a same-origin 404 or empty/invalid link), the preview shows a **Missing CSS** warning and a **Quick Fix** button. Clicking Quick Fix opens `https://chatgpt.com/` in a new browser tab and copies a repair prompt containing the entire imported HTML to the clipboard. The user must **paste it into ChatGPT**, review the suggested CSS, and paste the repaired HTML back into SyndaMorph for another preview. No HTML is automatically transmitted to ChatGPT by the plugin. Check the HTML for sensitive material before choosing Quick Fix.

Cross-origin CSS that cannot be verified due to browser security restrictions is shown as **Unverified**, not assumed missing. External stylesheets are not otherwise imported into Gutenberg. Quick Fix is optional; users can still create a draft without it. This is a best-effort reconstruction, not guaranteed recovery of original design.

Smoke test: `tests/fixtures/missing-css.html`.

## Current POC scope

- **Preview-first approval:** renders an converted Gutenberg layout preview inside an isolated, script-disabled iframe; no draft is created until **Create Draft** is selected. Changing the imported HTML invalidates the preview and requires review again.
- The preview renders the **same converted Gutenberg markup** that will be saved on approval, using lightweight block-layout styling. It shows converted columns, constrained images, alignment and supported colors—not the original HTML/CSS. Active WordPress theme/editor styles may still differ.

- Creates a new WordPress **draft page**, leaving existing pages untouched.
- Converts `h1`–`h6`, `p`, `img`, and standalone links into native Gutenberg blocks.
- Converts semantic containers (`header`, `main`, `section`, `article`, `nav`, `footer`) and `div` into native Group blocks.
- Converts simple `display: flex` or `display: grid` containers with 2–4 direct children into native Columns/Column blocks.
- Supports a narrow CSS subset from inline styles and simple `<style>` selectors: text alignment, hexadecimal text color, and hexadecimal group background color.
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
  assets/admin.css
  assets/missing-image.svg
tests/fixtures/basic.html
tests/fixtures/layout.html
tests/fixtures/missing-assets.html
tests/fixtures/missing-css.html
compose.yaml
```

## Origin

The first POC was validated in `gnznovus/syndacast-layout-engine` on branch `poc/html-to-gutenberg` (commit `aa736d9`). This repository carries forward the working importer under the SyndaMorph product identity, without importing the old Layout Engine architecture or history.
