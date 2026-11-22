<?php
/**
 * Plugin Name: SyndaMorph
 * Description: Transform static HTML into independently editable native Gutenberg blocks.
 * Version: 0.11.1
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Update URI: https://github.com/gnznovus/SyndaMorph
 * Text Domain: syndamorph
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once plugin_dir_path( __FILE__ ) . 'includes/render-endpoint.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/capture-endpoint.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/package-import.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/updater.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ai-integration.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/purr-poc.php';

add_action( 'admin_menu', static function (): void {
    add_management_page(
        'SyndaMorph',
        'SyndaMorph',
        'edit_pages',
        'syndamorph',
        'syndamorph_render_import_page'
    );
} );

function syndamorph_render_import_page(): void {
    if ( ! current_user_can( 'edit_pages' ) ) { return; }
    if ( isset( $_GET['tab'] ) && 'ai' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
        syndamorph_ai_render_tab();
        return;
    }
    ?>
    <div class="wrap" id="syndamorph-html-import">
        <div class="sm-dashboard">
            <aside class="sm-sidebar" aria-label="Operations navigation">
                <div class="sm-brand">
                    <span class="sm-brand-mark" aria-hidden="true">S</span>
                    <div><div class="sm-brand-name">SyndaMorph</div><div class="sm-brand-caption">Operations</div></div>
                </div>
                <div>
                    <p class="sm-nav-label">Workspace</p>
                    <nav class="sm-nav" aria-label="SyndaMorph modules">
                        <a class="sm-nav-item is-active" aria-current="page" href="<?php echo esc_url( admin_url( 'tools.php?page=syndamorph' ) ); ?>">
                            <span class="dashicons dashicons-upload" aria-hidden="true"></span> Source Importer
                        </a>
                        <?php if ( current_user_can( 'manage_options' ) ) : ?>
                        <a class="sm-nav-item" href="<?php echo esc_url( admin_url( 'tools.php?page=syndamorph&tab=ai' ) ); ?>">
                            <span class="dashicons dashicons-admin-network" aria-hidden="true"></span> AI Integration
                        </a>
                        <?php endif; ?>
                    </nav>
                </div>
                <div class="sm-sidebar-footer"><strong>Internal MVP</strong><br>Capture, preview, and convert source content to editable Gutenberg blocks.</div>
            </aside>
            <main class="sm-main">
                <div class="sm-topline">
                    <span class="sm-breadcrumb">Workspace / <strong>Source Importer</strong></span>
                    <span class="sm-environment">MVP · DEVELOPMENT</span>
                </div>
                <p class="sm-kicker">Production workspace</p>
                <h1 class="sm-page-title">Import your source</h1>
                <p class="sm-page-description">Start with a live URL, a ZIP package, or HTML. Review captured content before creating editable Gutenberg drafts or patterns.</p>
                <div class="sm-import-grid">
                <div class="sm-import-column">
                    <div class="sm-import-section-label">Source capture</div>
        <section class="syndamorph-package-section" aria-labelledby="syndamorph-url-heading">
            <h2 id="syndamorph-url-heading">Capture a wireframe URL (V2 POC)</h2>
            <p>Captures one page only. Choose a detected section to preview using the existing Gutenberg converter. External assets remain URL references.</p>
            <p><label for="syndamorph-url-input"><strong>Exact page URL</strong></label></p>
            <input id="syndamorph-url-input" type="url" class="large-text" style="max-width:800px" placeholder="https://www.syndacast.com/2026/BKKGOPrototype1/index.html">
            <div class="sm-capture-actions">
                <label for="syndamorph-url-mode"><strong>Rendering</strong></label>
                <select id="syndamorph-url-mode"><option value="auto">Automatic browser capture</option><option value="static">Static HTML only</option><option value="browser">Force Playwright</option></select>
                <button type="button" class="button button-primary" id="syndamorph-url-capture">Detect Sections</button>
            </div>
            <p role="status" aria-live="polite" id="syndamorph-url-status"></p>
            <details id="syndamorph-url-diagnostics" hidden style="margin:12px 0;max-width:860px">
                <summary><strong>Capture diagnostics</strong> — rendering and section-detection evidence</summary>
                <p id="syndamorph-url-diagnostics-summary"></p>
                <p><button type="button" class="button" id="syndamorph-url-copy-diagnostics">Copy Diagnostic JSON</button></p>
                <pre id="syndamorph-url-diagnostics-json" style="max-height:320px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;background:#fff;border:1px solid #ddd;padding:12px"></pre>
            </details>
            <div id="syndamorph-url-results" hidden>
                <p><label for="syndamorph-url-sections"><strong>Detected section</strong></label></p>
                <select id="syndamorph-url-sections" style="min-width:320px;max-width:100%"></select>
                <button type="button" class="button button-secondary" id="syndamorph-url-load">Load into HTML Preview</button>
                <p id="syndamorph-url-section-notes" class="description" role="status"></p>
                <details id="syndamorph-url-excluded" hidden>
                    <summary>Header, footer and overlay roots</summary>
                    <ul id="syndamorph-url-excluded-list"></ul>
                </details>
                <p class="description" id="syndamorph-url-warning"></p>
            </div>
        </section>
                    <div class="sm-import-section-label">Batch import</div>
        <div class="syndamorph-package-section">
            <h2>Import a ZIP package</h2>
            <p>Upload a package with one or more HTML pages and relative CSS/images. Select the pages, review each converted preview, then approve drafts or patterns.</p>
            <div class="syndamorph-package-upload">
                <input id="syndamorph-package-file" type="file" accept=".zip,application/zip">
                <button type="button" class="button button-secondary" id="syndamorph-package-scan">Scan Package</button>
            </div>
            <p id="syndamorph-package-status" role="status" aria-live="polite"></p>
            <div id="syndamorph-package-results" hidden>
                <p id="syndamorph-package-summary"></p>
                <div id="syndamorph-package-pages" class="syndamorph-package-pages"></div>
                <p><button type="button" class="button button-primary" id="syndamorph-package-preview">Preview Selected Pages</button></p>
            </div>
            <section id="syndamorph-package-modal" class="syndamorph-preview-modal" hidden role="dialog" aria-modal="true" aria-labelledby="syndamorph-package-modal-title">
                <div class="syndamorph-preview-card">
                    <div class="syndamorph-preview-heading">
                        <div><strong id="syndamorph-package-modal-title">Package Preview</strong><p id="syndamorph-package-progress"></p></div>
                        <button type="button" id="syndamorph-package-close" class="button-link syndamorph-preview-close" aria-label="Close package preview">&times;</button>
                    </div>
                    <div id="syndamorph-package-warnings" class="syndamorph-asset-report" hidden></div>
                    <iframe id="syndamorph-package-frame" title="Converted package page preview" sandbox="" referrerpolicy="no-referrer"></iframe>
                    <div class="syndamorph-preview-footer">
                        <div class="syndamorph-package-navigation">
                            <button type="button" class="button" id="syndamorph-package-prev">Previous</button>
                            <button type="button" class="button" id="syndamorph-package-next">Next</button>
                        </div>
                        <div class="syndamorph-preview-actions">
                            <select id="syndamorph-package-mode" aria-label="Save selected pages as">
                                <option value="draft">Draft pages</option>
                                <option value="pattern">Unsynced patterns</option>
                            </select>
                            <button type="button" class="button button-primary" id="syndamorph-package-approve" disabled>Approve &amp; Import All</button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
                </div>
                <div class="sm-import-column">
                    <div class="sm-import-section-label">HTML conversion</div>
                    <div class="sm-html-panel">
                        <div class="sm-html-header">
                            <span class="dashicons dashicons-editor-code" aria-hidden="true"></span>
                            <div>
                                <h2>Import HTML</h2>
                                <p>Paste markup or upload an HTML file to preview conversion.</p>
                            </div>
                        </div>
                        <div class="sm-html-body">
            <p><label for="syndamorph-html-file"><strong>HTML file (optional)</strong></label></p>
            <input id="syndamorph-html-file" type="file" accept=".html,.htm,text/html">
            <p><label for="syndamorph-html-input"><strong>Paste HTML</strong></label></p>
            <textarea id="syndamorph-html-input" rows="15" class="large-text code" placeholder="<section><h1>Welcome</h1><p>Edit me in Gutenberg.</p></section>"></textarea>
            <p><label><input id="syndamorph-js-render" type="checkbox"> <strong>Render inline JavaScript (experimental)</strong></label></p>
            <p class="description">Runs the imported document in an isolated Docker browser before conversion. Only inline JavaScript is supported; network requests and external scripts are blocked. Requires the local renderer service.</p>
            <p><label for="syndamorph-page-title"><strong>Draft title</strong></label></p>
            <div class="sm-inline-action">
                <input id="syndamorph-page-title" class="regular-text" type="text" value="Imported HTML" maxlength="200">
                <button id="syndamorph-convert" type="button" class="button button-primary">Preview Import</button>
            </div>
            <div id="syndamorph-result" role="status" aria-live="polite"></div>
            <section id="syndamorph-preview" class="syndamorph-preview-modal" hidden role="dialog" aria-modal="true" aria-labelledby="syndamorph-preview-title" aria-describedby="syndamorph-preview-description">
                <div class="syndamorph-preview-card">
                    <div class="syndamorph-preview-heading">
                        <div>
                            <strong id="syndamorph-preview-title">Import Preview</strong>
                            <p id="syndamorph-preview-description">Converted Gutenberg preview · theme styles may differ</p>
                        </div>
                        <button id="syndamorph-close-preview" type="button" class="button-link syndamorph-preview-close" aria-label="Close preview">&times;</button>
                    </div>
                    <div id="syndamorph-capture-report" class="syndamorph-asset-report" hidden role="status">
                        <strong>Review captured functionality and visual differences</strong>
                        <ul id="syndamorph-capture-notices"></ul>
                    </div>
                    <div id="syndamorph-asset-report" class="syndamorph-asset-report" hidden role="status"></div>
                    <div id="syndamorph-css-report" class="syndamorph-asset-report syndamorph-css-report" hidden role="status">
                        <div class="syndamorph-css-report-details">
                            <strong id="syndamorph-css-report-title">Missing CSS detected</strong>
                            <ul id="syndamorph-css-list"></ul>
                            <p>External CSS is not imported. Quick Fix copies your HTML plus a repair prompt and opens ChatGPT in a new tab. Paste the copied text there, then paste the returned HTML back here and preview again. Review your HTML before sharing it with any external service.</p>
                            <p id="syndamorph-css-fix-status" aria-live="polite"></p>
                        </div>
                        <button id="syndamorph-quick-fix" type="button" class="button button-secondary">Quick Fix</button>
                    </div>
                    <iframe id="syndamorph-preview-frame" title="Imported HTML preview" sandbox="" referrerpolicy="no-referrer"></iframe>
                    <div class="syndamorph-preview-footer">
                        <span>Review before creating a WordPress draft.</span>
                        <div class="syndamorph-preview-actions">
                            <button id="syndamorph-cancel-preview" type="button" class="button">Close</button>
                            <button id="syndamorph-save-pattern" type="button" class="button">Save as Pattern</button>
                            <button id="syndamorph-create-draft" type="button" class="button button-primary">Create Draft</button>
                        </div>
                    </div>
                </div>
            </section>
            <p class="description">POC scope: native headings, paragraphs, images, buttons, lists, tables, dividers, semantic groups, and simple columns. Supported CSS colors, background images, font size/weight/line height, spacing, column gaps, fractional grid tracks, vertical alignment, and proportional widths become native block settings. Complex CSS and external stylesheets are not imported. Scripts are not executed.</p>
                        </div>
                    </div>
                </div>
                </div>
            </main>
        </div>
    </div>
    <?php
}

add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {
    if ( 'tools_page_syndamorph' !== $hook || ! current_user_can( 'edit_pages' ) ||
        ( isset( $_GET['tab'] ) && 'ai' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) ) { return; }
    wp_enqueue_style( 'syndamorph-operations', plugin_dir_url( __FILE__ ) . 'assets/operations-dashboard.css', array(), (string) filemtime( plugin_dir_path( __FILE__ ) . 'assets/operations-dashboard.css' ) );
    wp_enqueue_style(
        'syndamorph-admin',
        plugin_dir_url( __FILE__ ) . 'assets/admin.css',
        array(),
        (string) filemtime( plugin_dir_path( __FILE__ ) . 'assets/admin.css' )
    );
    wp_enqueue_script(
        'syndamorph-pattern-dialog',
        plugin_dir_url( __FILE__ ) . 'assets/pattern-dialog.js',
        array(),
        (string) filemtime( plugin_dir_path( __FILE__ ) . 'assets/pattern-dialog.js' ),
        true
    );
    wp_enqueue_script(
        'syndamorph-import',
        plugin_dir_url( __FILE__ ) . 'assets/import.js',
        array( 'syndamorph-pattern-dialog' ),
        (string) filemtime( plugin_dir_path( __FILE__ ) . 'assets/import.js' ),
        true
    );
    $preview_font_family = wp_get_global_styles( array( 'typography', 'fontFamily' ), array( 'transforms' => array( 'resolve-variables' ) ) );
    wp_add_inline_script(
        'syndamorph-import',
        'window.SyndaMorphImport=' . wp_json_encode( array(
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'editUrl' => admin_url( 'post.php' ),
            'restUrl' => rest_url( 'wp/v2/pages' ),
            'placeholderUrl' => plugin_dir_url( __FILE__ ) . 'assets/missing-image.svg',
            'previewFontFamily' => is_string( $preview_font_family ) ? $preview_font_family : '',
            'patternUrl' => rest_url( 'syndamorph/v1/patterns' ),
            'renderUrl' => rest_url( 'syndamorph/v1/render-dom' ),
            'rendererAvailable' => 'http://renderer:3000/render' === getenv( 'SYNDAMORPH_RENDERER_URL' ),
        ) ) . ';',
        'before'
    );
    wp_enqueue_script(
        'syndamorph-url-capture',
        plugin_dir_url( __FILE__ ) . 'assets/url-capture.js',
        array( 'syndamorph-import' ),
        (string) filemtime( plugin_dir_path( __FILE__ ) . 'assets/url-capture.js' ),
        true
    );
    wp_add_inline_script(
        'syndamorph-url-capture',
        'window.SyndaMorphCapture=' . wp_json_encode( array(
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'endpoint' => rest_url( 'syndamorph/v1/capture-url' ),
        ) ) . ';',
        'before'
    );
    wp_enqueue_script(
        'syndamorph-package',
        plugin_dir_url( __FILE__ ) . 'assets/package.js',
        array( 'syndamorph-import' ),
        (string) filemtime( plugin_dir_path( __FILE__ ) . 'assets/package.js' ),
        true
    );
    wp_add_inline_script(
        'syndamorph-package',
        'window.SyndaMorphPackage=' . wp_json_encode( array(
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'scan' => rest_url( 'syndamorph/v1/package-scan' ),
            'page' => rest_url( 'syndamorph/v1/package-page' ),
            'commit' => rest_url( 'syndamorph/v1/package-commit' ),
        ) ) . ';',
        'before'
    );
} );

/**
 * Create an unsynced, independently editable WordPress pattern.
 * Both the import preview and the block editor use this one endpoint.
 */
add_action( 'rest_api_init', static function (): void {
    register_rest_route( 'syndamorph/v1', '/patterns', array(
        'methods'             => 'POST',
        'permission_callback' => static function (): bool {
            return current_user_can( 'edit_pages' );
        },
        'args'                => array(
            'title'   => array( 'type' => 'string', 'required' => true ),
            'content' => array( 'type' => 'string', 'required' => true ),
            'post_id' => array( 'type' => 'integer', 'required' => false ),
        ),
        'callback'            => static function ( WP_REST_Request $request ) {
            $title = sanitize_text_field( $request->get_param( 'title' ) );
            $content = (string) $request->get_param( 'content' );
            $post_id = absint( $request->get_param( 'post_id' ) );
            if ( '' === $title || '' === trim( $content ) || strlen( $content ) > 2 * 1024 * 1024 ) {
                return new WP_Error( 'syndamorph_invalid_pattern', 'Provide a pattern name and block content under 2 MB.', array( 'status' => 400 ) );
            }
            if ( $post_id && ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) ) {
                return new WP_Error( 'syndamorph_forbidden_source', 'You cannot use this source post.', array( 'status' => 403 ) );
            }
            if ( ! has_blocks( $content ) ) {
                return new WP_Error( 'syndamorph_not_blocks', 'Pattern must contain Gutenberg block markup.', array( 'status' => 400 ) );
            }
            $id = wp_insert_post( wp_slash( array(
                'post_type'    => 'wp_block',
                'post_status'  => 'publish',
                'post_title'   => $title,
                'post_content' => $content,
            ) ), true );
            if ( is_wp_error( $id ) ) { return $id; }
            if ( ! $id ) {
                return new WP_Error( 'syndamorph_pattern_failed', 'Could not save the pattern.', array( 'status' => 500 ) );
            }
            update_post_meta( $id, 'wp_pattern_sync_status', 'unsynced' );

            // User-created patterns use the wp_pattern_category taxonomy, not only
            // register_block_pattern_category(), which categorizes code-defined patterns.
            $category = term_exists( 'syndamorph', 'wp_pattern_category' );
            if ( ! $category ) {
                $category = wp_insert_term( 'SyndaMorph', 'wp_pattern_category', array( 'slug' => 'syndamorph' ) );
            }
            if ( is_wp_error( $category ) ) {
                wp_delete_post( $id, true );
                return new WP_Error( 'syndamorph_pattern_category_failed', 'Could not create the SyndaMorph category.', array( 'status' => 500 ) );
            }
            $term_id = is_array( $category ) ? (int) $category['term_id'] : (int) $category;
            $assigned = wp_set_object_terms( $id, array( $term_id ), 'wp_pattern_category', false );
            if ( is_wp_error( $assigned ) ) {
                wp_delete_post( $id, true );
                return new WP_Error( 'syndamorph_pattern_category_failed', 'Could not assign the SyndaMorph category.', array( 'status' => 500 ) );
            }

            return new WP_REST_Response( array(
                'id'     => $id,
                'title'  => $title,
                'synced' => false,
                'category' => 'SyndaMorph',
            ), 201 );
        },
    ) );
} );

/**
 * Provide the same Save as Pattern action for already-created drafts/pages.
 */
add_action( 'enqueue_block_editor_assets', static function (): void {
    $screen = get_current_screen();
    if ( ! $screen || 'post' !== $screen->base || ! in_array( $screen->post_type, array( 'page', 'post' ), true ) || ! current_user_can( 'edit_pages' ) ) {
        return;
    }
    wp_enqueue_style(
        'syndamorph-editor-admin',
        plugin_dir_url( __FILE__ ) . 'assets/admin.css',
        array(),
        (string) filemtime( plugin_dir_path( __FILE__ ) . 'assets/admin.css' )
    );
    wp_enqueue_script(
        'syndamorph-pattern-dialog',
        plugin_dir_url( __FILE__ ) . 'assets/pattern-dialog.js',
        array(),
        (string) filemtime( plugin_dir_path( __FILE__ ) . 'assets/pattern-dialog.js' ),
        true
    );
    wp_enqueue_script(
        'syndamorph-editor-pattern',
        plugin_dir_url( __FILE__ ) . 'assets/editor-pattern.js',
        array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-data', 'wp-api-fetch', 'syndamorph-pattern-dialog' ),
        (string) filemtime( plugin_dir_path( __FILE__ ) . 'assets/editor-pattern.js' ),
        true
    );
    wp_add_inline_script(
        'syndamorph-editor-pattern',
        'window.SyndaMorphPattern=' . wp_json_encode( array(
            'endpoint' => rest_url( 'syndamorph/v1/patterns' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
        ) ) . ';',
        'before'
    );
} );
