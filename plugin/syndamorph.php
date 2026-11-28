<?php
/**
 * Plugin Name: SyndaMorph
 * Description: Transform static HTML into independently editable native Gutenberg blocks.
 * Version: 0.12.64
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Update URI: https://github.com/gnznovus/SyndaMorph
 * Text Domain: syndamorph
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/includes/internal-transport.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/render-endpoint.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/capture-endpoint.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/package-import.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/updater.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ai-integration.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ai-models.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/ai-team-real.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/production-scope.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/purr-poc.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/production-team.php';
require_once __DIR__ . '/includes/production-booking.php';
require_once __DIR__ . '/includes/production-offer.php';
require_once __DIR__ . '/includes/production-header.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/gateway-admin.php';
require_once __DIR__ . '/includes/transport-status.php';

/**
 * The legacy Source Importer screen has been retired. Keep the converter and
 * REST endpoints intact: the Production Team uses them directly.
 */
add_action( 'admin_menu', static function (): void {
    add_management_page(
        'SyndaMorph',
        'SyndaMorph',
        'manage_options',
        'syndamorph',
        'syndamorph_render_operations_page'
    );
} );

function syndamorph_render_operations_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
    if ( 'gateway' === $tab ) {
        syndamorph_gateway_render_tab();
        return;
    }
    if ( 'ai' === $tab ) {
        syndamorph_ai_render_tab();
        return;
    }
    // The default route and bookmarked legacy/old team URLs all open the
    // Production Team, without exposing the obsolete Importer interface.
    syndamorph_team_render_tab();
}

/**
 * Create an unsynced, independently editable WordPress pattern.
 * Both the import preview and the block editor use this one endpoint.
 */
function syndamorph_insert_pattern( string $title, string $content, int $post_id = 0, string $scope_key = '', bool $private = false ) {
    $title = sanitize_text_field( $title );
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
        'post_status'  => $private ? 'private' : 'publish',
        'post_title'   => $title,
        'post_content' => $content,
        'meta_input'   => $scope_key === '' ? array() : array( '_syndamorph_scope_key' => $scope_key ),
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

    return array(
        'id'     => $id,
        'title'  => $title,
        'synced' => false,
        'category' => 'SyndaMorph',
    );
}

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
            $saved = syndamorph_insert_pattern( (string)$request['title'], (string)$request['content'], absint($request['post_id']) );
            return is_wp_error($saved) ? $saved : new WP_REST_Response($saved,201);
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
