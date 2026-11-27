<?php
/**
 * POC-only authenticated WordPress bridge to the isolated single-page extractor.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_capture_page( string $url, string $mode, bool $responsive = false ) {
    if ( ! syndamorph_internal_configured( 'backend' ) ) {
        return new WP_Error( 'syndamorph_backend_disabled', 'Capture backend is not configured.', array( 'status' => 503 ) );
    }
    $url = esc_url_raw( $url );
    $parts = wp_parse_url( $url );
    $hosts = syndamorph_capture_hosts();
    if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ||
        ! in_array( strtolower( $parts['scheme'] ), array( 'https', 'http' ), true ) ||
        ! in_array( strtolower( $parts['host'] ), $hosts, true ) ||
        isset( $parts['user'] ) || isset( $parts['pass'] ) || strlen( $url ) > 2048 ) {
        return new WP_Error( 'syndamorph_invalid_url', 'URL must use an approved capture host.', array( 'status' => 400 ) );
    }
    $response = syndamorph_internal_request( 'backend', 'POST', '/capture', array(
        'timeout' => $responsive ? 105 : 45,
        'redirection' => 0,
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body' => wp_json_encode( array( 'url' => $url, 'mode' => $mode, 'responsive' => $responsive ) ),
        'limit_response_size' => 24000001,
    ) );
    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'syndamorph_capture_unavailable', 'Capture backend is unavailable.', array( 'status' => 503 ) );
    }
    $raw = wp_remote_retrieve_body( $response );
    $body = strlen($raw)<=24000000 ? json_decode($raw,true) : null;
    if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $body['sections'] ?? null ) ) {
        return new WP_Error( 'syndamorph_capture_failed', 'Capture failed.', array( 'status' => 422 ) );
    }
    return $body;
}

add_action( 'rest_api_init', static function (): void {
    register_rest_route( 'syndamorph/v1', '/capture-url', array(
        'methods' => 'POST',
        'permission_callback' => static function (): bool { return current_user_can( 'edit_pages' ); },
        'args' => array(
            'url' => array( 'type' => 'string', 'required' => true ),
            'mode' => array( 'type' => 'string', 'default' => 'auto', 'enum' => array( 'auto', 'static', 'browser' ) ),
        ),
        'callback' => static function ( WP_REST_Request $request ) {
            $result = syndamorph_capture_page( (string)$request['url'], (string)$request['mode'] );
            return is_wp_error($result) ? $result : rest_ensure_response($result);
        },
    ) );
} );
