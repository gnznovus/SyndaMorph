# SyndaMorph Changelog

All notable changes to SyndaMorph are recorded here. The release workflow
includes this file inside the installable plugin ZIP and publishes the matching
version entry as the GitHub Release notes.

This changelog describes implemented work, not acceptance or production readiness.

## [0.12.2] - 2026-11-26

### Added
- A Bossy Touch tool for bounded click, hover, scroll, drag and resize against verified source runtime receipts.
- Checksummed response recording and isolated JavaScript replay from private capture artifacts, with browser egress sealed before the first gesture.
- Archived screenshots and structured state transitions with observed, inferred or unknown outcomes, carried as scenario references through the production handoffs.

### Verified
- The captured BKKGO 375 px menu opened using the recorded runtime and local-only Touch actions.
- Existing visual-only archives remain readable. Interaction inspection requires a new runtime capture when the old archive lacks recorded responses.

## [0.12.1] - 2026-11-25

### Added
- Opt-in SingleFile acquisition in the existing capture service with separate 1440, 768 and 375 px source and offline archive evidence.
- A guarded browser transport, immutable archive files, and application-validated receipt provenance through Section, Full Page and manually selected source handoffs.
- Real Chromium regressions for offline replay, divergent responsive trees, missing widths and mismatched saved HTML.

### Limitations
- Archives are static visual references. Interactive discovery and acceptance are separate checkpoints.
- The opt-in service requires Node.js 24 or later and the pinned SingleFile dependencies. The WordPress ZIP contains the receipt contract only.

## [0.12.0] - 2026-11-25

### Added
- Versioned Bossy work orders with section hierarchy, requirement IDs, evidence basis and observable acceptance criteria.
- A specification receipt that binds the original brief, pinned source evidence and required viewports to the Designer and Developer handoffs.

### Verified
- The shared production path carries one specification through design generation, review, native implementation and QA. Existing structured and legacy planning fixtures exercise the same entry point.
- The public release remains v0.11.1. Reference acquisition, Touch, continuous responsiveness, requirement-level verdicts and independent interactive acceptance are later checkpoints.

## [0.11.1] - 2026-11-20

### Added
- Native WordPress plugin update integration using published GitHub Releases and a verified `syndamorph.zip` asset.
- Automated release packaging with a stable `syndamorph/` root directory and forward-slash archive paths.
- This bundled changelog and release-note validation.
- A direct-`div`-text regression fixture, including mixed inline/nested content.
- Offline updater failure/isolation tests and local release-package validation.

### Fixed
- Preserve meaningful text nodes and line breaks inside `div` containers instead of silently dropping them.
- Preserve direct text appearing before, between, or after nested blocks.
- Keep adjacent Flex and Grid labels separated when converting them to editable paragraph text.
- Preserve links on imported images and logos using native Gutenberg Image blocks.
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
