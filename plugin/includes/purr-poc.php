<?php
/**
 * Administrator-only Purr communication POC bridge.
 * Client credentials never enter WordPress, REST responses, or browser code.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'rest_api_init', static function (): void {
    register_rest_route( 'syndamorph/v1', '/purr/communication-poc', array(
        'methods' => 'POST',
        'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ); },
        'callback' => static function ( WP_REST_Request $request ) {
            if ( ! syndamorph_internal_configured( 'backend' ) ) {
                return new WP_Error( 'syndamorph_purr_unavailable', 'POC backend is not configured.', array( 'status' => 503 ) );
            }
            $job = $request->get_param( 'job' );
            if ( ! is_string( $job ) || '' === trim( $job ) || strlen( $job ) > 200 ||
                 preg_match( '/[\x00-\x1f\x7f]/', $job ) ) {
                return new WP_Error( 'syndamorph_purr_job', 'Provide a POC job under 200 characters.', array( 'status' => 400 ) );
            }
            $response = syndamorph_internal_request( 'backend', 'POST', '/purr/communication-poc', array(
                'timeout' => 65,
                'redirection' => 0,
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body' => wp_json_encode( array( 'job' => $job ) ),
            ) );
            if ( is_wp_error( $response ) ) {
                return new WP_Error( 'syndamorph_purr_unavailable', 'POC backend connection failed.', array( 'status' => 503 ) );
            }
            $status = (int) wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( 200 !== $status || ! is_array( $body ) || empty( $body['success'] ) ) {
                return new WP_Error( 'syndamorph_purr_failed', 'Purr communication POC failed. Check backend configuration or Railway availability.', array( 'status' => 502 ) );
            }
            // Whitelist returned fields rather than forwarding arbitrary upstream data.
            $conversation = array();
            foreach ( array_slice( $body['conversation'] ?? array(), 0, 4 ) as $item ) {
                if ( ! is_array( $item ) ) { continue; }
                $conversation[] = array(
                    'step' => absint( $item['step'] ?? 0 ),
                    'agent' => sanitize_text_field( $item['agent'] ?? '' ),
                    'action' => sanitize_key( $item['action'] ?? '' ),
                    'message' => sanitize_textarea_field( $item['message'] ?? '' ),
                    'status' => sanitize_text_field( $item['status'] ?? '' ),
                );
            }
            return rest_ensure_response( array(
                'success' => true,
                'mode' => 'deterministic communication POC',
                'session_created' => ! empty( $body['session_created'] ),
                'steps_completed' => count( $conversation ),
                'conversation' => $conversation,
            ) );
        },
    ) );
} );
