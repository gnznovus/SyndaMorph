# P3 + P4 Independence POC — Core pattern + local version archive

**Scope:** Opt-in experiment only. Does not alter the normal Save as Pattern or AI workflow.

## Goal

Verify that an unsynced Gutenberg pattern remains a normal `wp_block` post after
SyndaMorph is deactivated/deleted, while immutable physical snapshots under
`wp-content/uploads/syndamorph-artifacts-poc/` remain available independently
of the plugin, its API keys and Purr Gateway.

The archive is a **recovery copy**, not a front-end runtime dependency.
Published content comes from WordPress, never from the archive.

## POC endpoints (authenticated WordPress REST, edit_pages permission)

- `POST /wp-json/syndamorph/v1/artifact-poc/publish`:
  `{"title":"POC Hero","content":"<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->"}`.
  Returns `id`, `version`, `sha256`, archive path.
- Same POST, with `pattern_id`, saves a new version of a **POC-owned** `wp_block`.
- `GET /wp-json/syndamorph/v1/artifact-poc/versions/{id}` lists manifests.
- `POST /wp-json/syndamorph/v1/artifact-poc/restore/{id}/v000001` verifies
  checksum, restores saved markup and creates a **new** immutable version.

Use the logged-in WP admin browser with a valid `X-WP-Nonce` or WP-CLI;
REST endpoints disappear when the plugin is removed, but the content/versions
must not.

## Local folder (not in plugins/)

```text
wp-content/uploads/syndamorph-artifacts-poc/
  index.php / .htaccess / web.config
  pattern-123/
    .archive.lock
    versions/
      v000001/
        pattern.blocks.txt
        manifest.json
      v000002/
        pattern.blocks.txt
        manifest.json
```

Manifests record schema, pattern ID/title, version, UTC creation,
SHA-256, `runtime=wordpress-core`, `plugin_required=false`, and an
empty assets array. Versions are never overwritten. A restore appends a new
version rather than editing the historic snapshot.

**Security:** On Apache/IIS the POC writes directory guards. Nginx/Plesk
operators must explicitly deny HTTP access to the artifact directory;
`.htaccess` does not protect Nginx. No credentials or secrets belong in
archived content. Copy the archive in off-server backups, since plugin
independence does not mean disk-failure independence.

## WordPress CLI proof (local disposable environment)

The following script uses `wp eval` in the project WordPress container;
replace `<admin-username>` with an actual WordPress administrator account. The
publish/update functions check WordPress editing capabilities:

```sh
# 1. SyndaMorph enabled: create, update, restore, verify snapshots.
wp --user=<admin-username> eval '
$a = syndamorph_poc_publish("Independence POC", "<!-- wp:paragraph --><p>Version one</p><!-- /wp:paragraph -->");
if (is_wp_error($a)) { throw new Exception($a->get_error_message()); }
$id = $a["id"];
$b = syndamorph_poc_publish("Independence POC", "<!-- wp:paragraph --><p>Version two</p><!-- /wp:paragraph -->", $id);
if (is_wp_error($b)) { throw new Exception($b->get_error_message()); }
$versions = syndamorph_poc_versions($id);
if (count($versions) !== 2 || $versions[0]["version"] !== "v000001") { throw new Exception("Versions missing"); }
$first = syndamorph_poc_directory($id) . "/versions/v000001/pattern.blocks.txt";
$body = file_get_contents($first);
if (!hash_equals($versions[0]["content_sha256"], hash("sha256", $body))) { throw new Exception("Checksum mismatch"); }
$restored = syndamorph_poc_publish("Independence POC", $body, $id);
if (is_wp_error($restored) || $restored["version"] !== "v000003") { throw new Exception("Rollback failed"); }
update_option("syndamorph_poc_test_pattern_id", $id, false);
echo "Created and restored pattern $id; archive " . syndamorph_poc_directory($id) . "\n";
'
# 2. Deactivate SyndaMorph. Run the next eval in a NEW PHP process.
wp plugin deactivate syndamorph
wp eval '
$id = (int) get_option("syndamorph_poc_test_pattern_id");
$post = get_post($id);
if (!$post || $post->post_type !== "wp_block" || !has_blocks($post->post_content)) { throw new Exception("Pattern missing"); }
if (strpos($post->post_content, "Version one") === false) { throw new Exception("Restore content lost"); }
$base = wp_upload_dir()["basedir"] . "/syndamorph-artifacts-poc/pattern-" . $id . "/versions/";
foreach (array("v000001", "v000002", "v000003") as $version) {
  $dir = $base . $version;
  $manifest = json_decode(file_get_contents($dir . "/manifest.json"), true);
  $content = file_get_contents($dir . "/pattern.blocks.txt");
  if (!hash_equals($manifest["content_sha256"], hash("sha256", $content))) { throw new Exception("Corrupt " . $version); }
}
echo "PASS: pattern and three version files survive plugin deactivation\n";
'
# 3. On this disposable installation only: delete SyndaMorph and rerun
#    the second wp eval (the plugin-specific REST endpoints will be gone).
#    wp plugin delete syndamorph
```

Confirm in Gutenberg that the published pattern remains recognized/editable,
and that inserting it does not reference SyndaMorph scripts or endpoints.

## POC limitations (deliberate)

- Core text/layout only (no image/media, custom block, shortcode, HTML block,
  client-side JS, remote CSS, or embedded widgets).
- Only writes versions through the POC endpoint/functions; direct manual
  edits in Gutenberg are **not auto-archived yet**.
- Only local physical backups; off-server retention is not automated.
- No restored asset bundles, deduplication, migrations or production-grade
  concurrency handling yet.
- Plugin deletion cannot repair unrelated theme or WordPress compatibility.
- The POC's version/restore UI disappears with the plugin; restore files
  themselves remain and are manually recoverable without SyndaMorph.

## Exit criteria

1. POC publisher creates native `wp_block` markup.
2. Two publishes plus restore create three distinct immutable versions.
3. Every manifest's SHA-256 matches its content.
4. Pattern and physical versions remain after plugin deactivation/deletion.
5. WordPress editor can still edit/insert the pattern.
6. Expired API key / offline Gateway does not affect pattern use.

**Until tested on a live local WordPress instance, these are acceptance
checks, not claims of completed plugin-removal verification.**
