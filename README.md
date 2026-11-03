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

## Current POC scope

- **Preview-first approval:** renders an HTML reference preview inside an isolated, script-disabled iframe; no draft is created until **Create Draft** is selected. Changing the imported HTML invalidates the preview and requires review again.
- The preview displays the imported HTML/CSS reference, not a pixel-perfect Gutenberg rendering. The converted block content is generated at preview time and only saved on approval.

- Creates a new WordPress **draft page**, leaving existing pages untouched.
- Converts `h1`–`h6`, `p`, `img`, and standalone links into native Gutenberg blocks.
- Converts semantic containers (`header`, `main`, `section`, `article`, `nav`, `footer`) and `div` into native Group blocks.
- Converts simple `display: flex` or `display: grid` containers with 2–4 direct children into native Columns/Column blocks.
- Supports a narrow CSS subset from inline styles and simple `<style>` selectors: text alignment, hexadecimal text color, and hexadecimal group background color.
- Accepts pasted HTML or a local HTML file (up to 2 MB).
- Executes no imported scripts. No proprietary package, custom editor, or WPML dependency.
- Does not preserve external CSS, complete layout fidelity, responsive media queries, pseudo-selectors, CSS variables, JavaScript-driven DOM, or imported media files.
- CSS grid/flex layout is approximated as equal-width native columns, not pixel-perfect positioning. Gutenberg validates saved markup; visually check each imported block for recovery warnings.
- Image URLs must be reachable for previews; broken sources can be replaced from the editor.
- Maximum 500 generated blocks and 200 simple CSS rules per import. Intended as a POC, not production hardened.

## Structure

```text
plugin/
  syndamorph.php
  assets/import.js
  assets/admin.css
tests/fixtures/basic.html
compose.yaml
```

## Origin

The first POC was validated in `gnznovus/syndacast-layout-engine` on branch `poc/html-to-gutenberg` (commit `aa736d9`). This repository carries forward the working importer under the SyndaMorph product identity, without importing the old Layout Engine architecture or history.
