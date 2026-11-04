<?php
/**
 * Plugin Name: SyndaMorph
 * Description: Transform static HTML into independently editable native Gutenberg blocks.
 * Version: 0.7.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: syndamorph
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once plugin_dir_path( __FILE__ ) . 'includes/render-endpoint.php';

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
    ?>
    <div class="wrap" id="syndamorph-html-import">
        <h1>SyndaMorph <span style="font-weight:normal;font-size:13px;">POC</span></h1>
        <p>Paste static HTML or choose an .html file. Review the preview, then approve draft creation.</p>
        <div style="max-width:900px;">
            <p><label for="syndamorph-html-file"><strong>HTML file (optional)</strong></label></p>
            <input id="syndamorph-html-file" type="file" accept=".html,.htm,text/html">
            <p><label for="syndamorph-html-input"><strong>Paste HTML</strong></label></p>
            <textarea id="syndamorph-html-input" rows="15" class="large-text code" placeholder="<section><h1>Welcome</h1><p>Edit me in Gutenberg.</p></section>"></textarea>
            <p><label><input id="syndamorph-js-render" type="checkbox"> <strong>Render inline JavaScript (experimental)</strong></label></p>
            <p class="description">Runs the imported document in an isolated Docker browser before conversion. Only inline JavaScript is supported; network requests and external scripts are blocked. Requires the local renderer service.</p>
            <p><label for="syndamorph-page-title"><strong>Draft title</strong></label></p>
            <input id="syndamorph-page-title" class="regular-text" type="text" value="Imported HTML" maxlength="200">
            <p><button id="syndamorph-convert" type="button" class="button button-primary">Preview Import</button></p>
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
            <p class="description">POC scope: native headings, paragraphs, images, buttons, lists, tables, dividers, semantic groups, and simple columns. Basic inline and embedded CSS colors/alignment are translated to native block settings. Complex CSS and external stylesheets are not imported. Scripts are not executed.</p>
        </div>
    </div>
    <?php
}

add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {
    if ( 'tools_page_syndamorph' !== $hook || ! current_user_can( 'edit_pages' ) ) { return; }
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
    wp_add_inline_script(
        'syndamorph-import',
        'window.SyndaMorphImport=' . wp_json_encode( array(
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'editUrl' => admin_url( 'post.php' ),
            'restUrl' => rest_url( 'wp/v2/pages' ),
            'placeholderUrl' => plugin_dir_url( __FILE__ ) . 'assets/missing-image.svg',
            'patternUrl' => rest_url( 'syndamorph/v1/patterns' ),
            'renderUrl' => rest_url( 'syndamorph/v1/render-dom' ),
            'rendererAvailable' => 'http://renderer:3000/render' === getenv( 'SYNDAMORPH_RENDERER_URL' ),
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
