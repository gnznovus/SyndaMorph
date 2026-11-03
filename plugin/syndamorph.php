<?php
/**
 * Plugin Name: SyndaMorph
 * Description: Transform static HTML into independently editable native Gutenberg blocks.
 * Version: 0.5.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: syndamorph
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

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
                            <button id="syndamorph-create-draft" type="button" class="button button-primary">Create Draft</button>
                        </div>
                    </div>
                </div>
            </section>
            <p class="description">POC scope: headings, paragraphs, images, semantic groups, and simple flex/grid columns. Basic inline and embedded CSS colors/alignment are translated to native block settings. Complex CSS and external stylesheets are not imported. Scripts are not executed.</p>
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
        'syndamorph-import',
        plugin_dir_url( __FILE__ ) . 'assets/import.js',
        array(),
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
        ) ) . ';',
        'before'
    );
} );
