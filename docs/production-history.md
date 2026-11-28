# Production History


> **Current project status (2026-11-24):** See [Master Plan](master-plan.md) for implemented vs planned capabilities. Adaptive Surgery v2 now records richer accepted/rejected repairs, browser evidence, operation costs, scoped feedback and verified-learning events. The historical GEN/REF sequences and append-only History contract below remain; **campaign-level persistent queues, steering events, and campaign checkpoints are planned, not already supported.** Future AI Knowledge Management will move *learning management controls* out of History while preserving History as immutable evidence.

History is the fourth Operations tab. Administrator-only WordPress REST endpoints expose persistent jobs and append-only events. GEN and REF have independent sequences; display format is `GEN-000001 : Job title`. IDs are allocated before execution; the Boss supplies a descriptive GEN title during its first response. REF titles derive from source conversion.

GEN records preserve the brief, model settings (not credentials), standards snapshot, streamed text deltas, handoffs, final artifacts, metrics and errors. Replay uses the live event consumer without contacting a model. Interrupted jobs retain committed events; a stale running record is displayed as interrupted after 25 minutes. Existing pre-history jobs cannot be reconstructed automatically.

REF currently means deterministic Source Import, not reference-conditioned AI generation. URL records retain available capture evidence; HTML records preserve original markup; ZIP records preserve the uploaded package and scan manifest. Converted pages and save receipts are recorded separately. Reference previews suppress remote assets; original ZIP is downloadable. Large archives may require database packet and PHP request limits to be adjusted. No automatic retention or cleanup policy is implemented; back up the WordPress database and restrict access to authorized administrators.

The November 25 hardening extends History with immutable repair handoffs, compiler correction outcomes, provider metering and literal-copy checksums. Original and corrected successful schema artifacts are preserved in private History. [Replay evidence and compatibility](adaptive-surgery-hardening.md#archive-replay)


Verified GEN candidate versions have private full-artifact checkpoints. The latest checkpoint is authoritative during pause and stop, and its identity gates Resume before new provider calls. Revision records expose the before and after identities, operation signature, outcome and unresolved issues. [Progression and evidence contract](adaptive-surgery-hardening.md#gen-000025-and-the-verified-best-candidate)
## Acceptance

1. Start one brief-only job and confirm GEN ID appears before generation, then the Boss title replaces the placeholder.
2. Reload the workspace; inspect, replay and export the saved job without API requests.
3. Convert an HTML reference, confirm independent REF sequence and original source/converted artifacts.
4. Test a ZIP reference, verify exported original checksum, and inspect available URL capture evidence.
5. Verify failure records and partial outputs remain accessible.
6. Verify search, sorting and pagination; confirm unauthorized users cannot read the archive.

CI checks syntax and identifier/wiring assertions. Real WordPress database, browser and large-reference acceptance remain required. Deterministic revalidation, historical fixture import, and reference-conditioned AI generation are not implemented in this slice.

## Export AI results to WordPress

From Results (including History → Open Results), use Convert & Preview to prepare Gutenberg blocks with the existing converter, then choose Draft page or Unsynced pattern. Save Draft and Save & Open Editor are available for draft mode; Save as Pattern is available for pattern mode. No AI inference or automatic page publishing occurs. The generated HTML remains unchanged. An export receipt records the saved WordPress object ID, preventing ordinary duplicate saves when replaying a recorded job. This is not a transactional cross-request idempotency guarantee. Review the converted preview and verify editor validity manually. Deleted or changed saved objects are not reconciled automatically.
