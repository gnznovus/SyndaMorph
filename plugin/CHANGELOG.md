# SyndaMorph Changelog

All notable changes to SyndaMorph are recorded here. The release workflow
includes this file inside the installable plugin ZIP and publishes the matching
version entry as the GitHub Release notes.

This changelog describes implemented work, not acceptance or production readiness.

## [0.11.1] - Unreleased

### Added
- Native WordPress plugin update integration using published GitHub Releases and a verified `syndamorph.zip` asset.
- Automated release packaging with a stable `syndamorph/` root directory and forward-slash archive paths.
- This bundled changelog and release-note validation.
- A direct-`div`-text regression fixture, including mixed inline/nested content.
- Offline updater failure/isolation tests and local release-package validation.

### Fixed
- Preserve meaningful text nodes and line breaks inside `div` containers instead of silently dropping them.
- Preserve direct text appearing before, between, or after nested blocks.
- Show a background image in the import preview when it belongs to the first Group.
- Preserve Columns margins in the import preview when a gap is also set.
- Reject malformed release metadata and unrelated/incomplete release assets without PHP warnings.
- Supply equal/older release metadata for WordPress's native no-update and auto-update controls without offering a downgrade ZIP.
- Validate release tag ancestry, dated changelog notes, and exact packaged files before attaching the update ZIP.

## [0.11.0] - Unreleased

### Added
- Native Gutenberg Columns conversion for simple 2–6 track CSS Grid layouts.
- Fractional widths for `fr` and percentage grid tracks; nested grid and flex layouts.
- Vertical alignment support for compatible columns.

## [0.10.0] - Unreleased

### Added
- CSS background images mapped to native Group attributes.
- Font weight and line height mapped to Gutenberg typography settings.
- Improved preview rendering of native Group background attributes.

## [0.9.0]

### Added
- ZIP package scan, preview and approved multi-page import.
- Relative CSS and image asset resolution; referenced-only media imports.
- Asset deduplication and best-effort rollback on failed imports.

## [0.8.0]

### Added
- Native spacing controls, font sizes, gaps and proportional column widths.

## [0.7.0]

### Added
- Native buttons, lists, tables, separators and formatted inline text.
- Experimental isolated JavaScript DOM capture.
