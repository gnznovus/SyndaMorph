<?php
/**
 * Plugin Name: SyndaMorph
 * Description: Transform static HTML into independently editable native Gutenberg blocks.
 * Version: 0.1.0
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
        <p>Paste static HTML or choose an .html file. Convert it into a new WordPress draft and edit using Gutenberg.</p>
        <div style="max-width:900px;">
            <p><label for="syndamorph-html-file"><strong>HTML file (optional)</strong></label></p>
            <input id="syndamorph-html-file" type="file" accept=".html,.htm,text/html">
            <p><label for="syndamorph-html-input"><strong>Paste HTML</strong></label></p>
            <textarea id="syndamorph-html-input" rows="15" class="large-text code" placeholder="<section><h1>Welcome</h1><p>Edit me in Gutenberg.</p></section>"></textarea>
            <p><label for="syndamorph-page-title"><strong>Draft title</strong></label></p>
            <input id="syndamorph-page-title" class="regular-text" type="text" value="Imported HTML" maxlength="200">
            <p><button id="syndamorph-convert" type="button" class="button button-primary">Create Gutenberg Draft</button></p>
            <div id="syndamorph-result" role="status" aria-live="polite"></div>
            <p class="description">POC scope: static HTML only. Scripts are not executed. External stylesheets are not imported. Missing images may need replacement in Gutenberg. Created drafts remain ordinary WordPress pages.</p>
        </div>
    </div>
    <?php
}

add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {
    if ( 'tools_page_syndamorph' !== $hook || ! current_user_can( 'edit_pages' ) ) { return; }
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
        ) ) . ';',
        'before'
    );
} );
