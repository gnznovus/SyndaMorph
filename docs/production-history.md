# Production History

History is the fourth Operations tab. Administrator-only WordPress REST endpoints expose persistent jobs and append-only events. GEN and REF have independent sequences; display format is `GEN-000001 : Job title`. IDs are allocated before execution; the Boss supplies a descriptive GEN title during its first response. REF titles derive from source conversion.

GEN records preserve the brief, model settings (not credentials), standards snapshot, streamed text deltas, handoffs, final artifacts, metrics and errors. Replay uses the live event consumer without contacting a model. Interrupted jobs retain committed events; a stale running record is displayed as interrupted after 25 minutes. Existing pre-history jobs cannot be reconstructed automatically.

REF currently means deterministic Source Import, not reference-conditioned AI generation. URL records retain available capture evidence; HTML records preserve original markup; ZIP records preserve the uploaded package and scan manifest. Converted pages and save receipts are recorded separately. Reference previews suppress remote assets; original ZIP is downloadable. Large archives may require database packet and PHP request limits to be adjusted. No automatic retention or cleanup policy is implemented; back up the WordPress database and restrict access to authorized administrators.

## Acceptance

1. Start one brief-only job and confirm GEN ID appears before generation, then the Boss title replaces the placeholder.
2. Reload the workspace; inspect, replay and export the saved job without API requests.
3. Convert an HTML reference, confirm independent REF sequence and original source/converted artifacts.
4. Test a ZIP reference, verify exported original checksum, and inspect available URL capture evidence.
5. Verify failure records and partial outputs remain accessible.
6. Verify search, sorting and pagination; confirm unauthorized users cannot read the archive.

CI checks syntax and identifier/wiring assertions. Real WordPress database, browser and large-reference acceptance remain required. Deterministic revalidation, historical fixture import, and reference-conditioned AI generation are not implemented in this slice.
