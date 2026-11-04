<?php
/**
 * Development-only bridge from authenticated WordPress admin to the isolated
 * Playwright worker. No browser execution happens inside WordPress.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'rest_api_init', static function (): void {
    register_rest_route( 'syndamorph/v1', '/render-dom', array(
        'methods' => 'POST',
        'permission_callback' => static function (): bool {
            return current_user_can( 'edit_pages' );
        },
        'args' => array(
            'html' => array( 'required' => true, 'type' => 'string' ),
        ),
        'callback' => static function ( WP_REST_Request $request ) {
            $url = getenv( 'SYNDAMORPH_RENDERER_URL' );
            // For the POC, only the known internal Docker service is allowed.
            if ( 'http://renderer:3000/render' !== $url ) {
                return new WP_Error( 'syndamorph_renderer_disabled', 'The JS renderer is not configured. Use static HTML mode.', array( 'status' => 503 ) );
            }
            $html = (string) $request->get_param( 'html' );
            if ( '' === trim( $html ) || strlen( $html ) > 2 * 1024 * 1024 ) {
                return new WP_Error( 'syndamorph_render_invalid', 'HTML must be between 1 byte and 2 MB.', array( 'status' => 400 ) );
            }
            $response = wp_remote_post( $url, array(
                'timeout' => 12,
                'redirection' => 0,
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body' => wp_json_encode( array( 'html' => $html ) ),
            ) );
            if ( is_wp_error( $response ) ) {
                return new WP_Error( 'syndamorph_render_unavailable', 'Isolated renderer unavailable. Check Docker Compose logs.', array( 'status' => 503 ) );
            }
            $payload = json_decode( wp_remote_retrieve_body( $response ), true );
            $code = wp_remote_retrieve_response_code( $response );
            if ( 200 !== $code || ! is_array( $payload ) || ! isset( $payload['html'] ) || ! is_string( $payload['html'] ) ) {
                $message = is_array( $payload ) && isset( $payload['error'] ) ? sanitize_text_field( $payload['error'] ) : 'DOM renderer returned an invalid result.';
                return new WP_Error( 'syndamorph_render_failed', $message, array( 'status' => 422 ) );
            }
            return rest_ensure_response( array( 'html' => $payload['html'] ) );
        },
    ) );
} );
