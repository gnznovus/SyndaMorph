# SyndaMorph

**From design to editable.**

SyndaMorph is a standalone WordPress plugin that imports static HTML into editable native Gutenberg blocks.

## Local development

Requirements: Docker Desktop with Compose.

```bash
docker compose up -d
```

Open **http://localhost:8085**, complete the WordPress installation, then activate **SyndaMorph** under **Plugins**. The plugin is bind-mounted from `./plugin`, so code edits are reflected without rebuilding the image.

Go to **Tools → SyndaMorph** to paste HTML or select an `.html`/`.htm` file. Select **Create Gutenberg Draft**, then edit the result in the native WordPress editor.

Stop the environment with `docker compose down`. To discard the local WordPress database and content, run `docker compose down -v` (**destructive**).

## Current POC scope

- Creates a new WordPress **draft page**, leaving existing pages untouched.
- Converts `h1`–`h6`, `p`, and `img` into separate native Gutenberg blocks.
- Accepts pasted HTML or a local HTML file (up to 2 MB).
- Executes no imported scripts. No proprietary package, custom editor, or WPML dependency.
- Does not preserve external CSS, layout fidelity, JavaScript-driven DOM, or import media files.
- Image URLs must be reachable for previews; broken sources can be replaced from the editor.
- Maximum 500 supported elements per import. Intended as a POC, not production hardened.

## Structure

```text
plugin/
  syndamorph.php
  assets/import.js
tests/fixtures/basic.html
compose.yaml
```

## Origin

The first POC was validated in `gnznovus/syndacast-layout-engine` on branch `poc/html-to-gutenberg` (commit `aa736d9`). This repository carries forward the working importer under the SyndaMorph product identity, without importing the old Layout Engine architecture or history.
