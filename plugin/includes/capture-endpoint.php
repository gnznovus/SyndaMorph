<?php
/**
 * POC-only authenticated WordPress bridge to the isolated single-page extractor.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'rest_api_init', static function (): void {
    register_rest_route( 'syndamorph/v1', '/capture-url', array(
        'methods' => 'POST',
        'permission_callback' => static function (): bool { return current_user_can( 'edit_pages' ); },
        'args' => array(
            'url' => array( 'type' => 'string', 'required' => true ),
            'mode' => array( 'type' => 'string', 'default' => 'auto', 'enum' => array( 'auto', 'static', 'browser' ) ),
        ),
        'callback' => static function ( WP_REST_Request $request ) {
            $endpoint = getenv( 'SYNDAMORPH_BACKEND_URL' );
            $token = getenv( 'SYNDAMORPH_BACKEND_TOKEN' );
            if ( 'http://backend:3100/capture' !== $endpoint || ! is_string( $token ) || '' === $token ) {
                return new WP_Error( 'syndamorph_backend_disabled', 'Hybrid POC backend is not configured.', array( 'status' => 503 ) );
            }
            $url = esc_url_raw( (string) $request->get_param( 'url' ) );
            $parts = wp_parse_url( $url );
            $hosts = array_filter( array_map( 'trim', explode( ',', (string) getenv( 'SYNDAMORPH_CAPTURE_HOSTS' ) ) ) );
            if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ||
                ! in_array( strtolower( $parts['scheme'] ), array( 'https', 'http' ), true ) ||
                ! in_array( strtolower( $parts['host'] ), $hosts, true ) ||
                isset( $parts['user'] ) || isset( $parts['pass'] ) || strlen( $url ) > 2048 ) {
                return new WP_Error( 'syndamorph_invalid_url', 'URL must use an approved POC host.', array( 'status' => 400 ) );
            }
            $response = wp_remote_post( $endpoint, array(
                'timeout' => 45,
                'redirection' => 0,
                'headers' => array( 'Content-Type' => 'application/json', 'X-SyndaMorph-Token' => $token ),
                'body' => wp_json_encode( array( 'url' => $url, 'mode' => $request->get_param( 'mode' ) ) ),
            ) );
            if ( is_wp_error( $response ) ) {
                return new WP_Error( 'syndamorph_capture_unavailable', 'POC capture backend is unavailable.', array( 'status' => 503 ) );
            }
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $body ) || ! isset( $body['sections'] ) || ! is_array( $body['sections'] ) ) {
                $message = is_array( $body ) && isset( $body['error'] ) ? sanitize_text_field( $body['error'] ) : 'Capture failed.';
                return new WP_Error( 'syndamorph_capture_failed', $message, array( 'status' => 422 ) );
            }
            return rest_ensure_response( $body );
        },
    ) );
} );
