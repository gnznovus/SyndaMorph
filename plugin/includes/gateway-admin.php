<?php
/**
 * SyndaMorph Secure Gateway administration.
 *
 * WordPress administrator -> nonce-checked AJAX -> same-site PHP proxy
 * -> configured Gateway. The Gateway administrator secret is never sent to JS.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_gateway_admin_configured(): bool {
    return defined( 'SYNDAMORPH_GATEWAY_URL' ) && defined( 'SYNDAMORPH_GATEWAY_ADMIN_TOKEN' )
        && is_string( SYNDAMORPH_GATEWAY_URL ) && is_string( SYNDAMORPH_GATEWAY_ADMIN_TOKEN )
        && strlen( SYNDAMORPH_GATEWAY_ADMIN_TOKEN ) >= 32;
}

function syndamorph_gateway_render_tab(): void {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    wp_enqueue_script(
        'syndamorph-gateway-admin',
        plugin_dir_url( dirname( __DIR__ ) . '/syndamorph.php' ) . 'assets/gateway-admin.js',
        array(),
        (string) filemtime( dirname( __DIR__ ) . '/assets/gateway-admin.js' ),
        true
    );
    wp_add_inline_script( 'syndamorph-gateway-admin', 'window.SyndaMorphGatewayAdmin=' . wp_json_encode( array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'syndamorph_gateway_admin' ),
        'configured' => syndamorph_gateway_admin_configured(),
    ) ) . ';', 'before' );
    ?>
    <div class="wrap" id="syndamorph-gateway-admin">
        <h1>SyndaMorph · Client Access Manager</h1>
        <p>Customers, client-level API keys, private packages, and package leases.</p>
        <?php if ( ! syndamorph_gateway_admin_configured() ) : ?>
            <div class="notice notice-warning"><p>Secure Gateway is not configured. Set the Gateway URL and administrator token in server-side WordPress configuration. No client records can be changed.</p></div>
        <?php endif; ?>
        <div id="syndamorph-gateway-status" role="status" aria-live="polite"></div>
        <div id="syndamorph-gateway-root"></div>
    </div>
    <?php
}

add_action( 'wp_ajax_syndamorph_gateway_admin', 'syndamorph_gateway_admin_ajax' );
function syndamorph_gateway_admin_ajax(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
    }
    check_ajax_referer( 'syndamorph_gateway_admin', 'nonce' );
    if ( ! syndamorph_gateway_admin_configured() ) {
        wp_send_json_error( array( 'error' => 'gateway_not_configured' ), 503 );
    }
    $base = untrailingslashit( SYNDAMORPH_GATEWAY_URL );
    $scheme = wp_parse_url( $base, PHP_URL_SCHEME );
    $host = wp_parse_url( $base, PHP_URL_HOST );
    if ( ! is_string( $host ) || ! is_string( $scheme ) ||
        ( 'https' !== $scheme && !( 'http' === $scheme && in_array( strtolower( $host ), array( 'localhost', '127.0.0.1' ), true ) ) ) ||
        wp_parse_url( $base, PHP_URL_QUERY ) || wp_parse_url( $base, PHP_URL_FRAGMENT ) ||
        wp_parse_url( $base, PHP_URL_USER ) || wp_parse_url( $base, PHP_URL_PASS ) ) {
        wp_send_json_error( array( 'error' => 'invalid_gateway_configuration' ), 503 );
    }

    $operation = isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
    $raw = isset( $_POST['payload'] ) && is_string( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '{}';
    $body = json_decode( $raw, true );
    if ( ! is_array( $body ) || strlen( $raw ) > 8192 ) {
        wp_send_json_error( array( 'error' => 'invalid_request' ), 400 );
    }
    $customer = isset( $body['customerId'] ) && is_string( $body['customerId'] ) && preg_match( '/^cus_[a-f0-9]{24}$/D', $body['customerId'] ) ? $body['customerId'] : '';
    $package = isset( $body['packageId'] ) && is_string( $body['packageId'] ) && preg_match( '/^pkg_[a-f0-9]{24}$/D', $body['packageId'] ) ? $body['packageId'] : '';
    $lease = isset( $body['leaseId'] ) && is_string( $body['leaseId'] ) && preg_match( '/^lease_[a-f0-9]{24}$/D', $body['leaseId'] ) ? $body['leaseId'] : '';
    $key = isset( $body['keyId'] ) && is_string( $body['keyId'] ) && preg_match( '/^key_[a-f0-9]{24}$/D', $body['keyId'] ) ? $body['keyId'] : '';
    $route = ''; $method = 'POST'; $payload = array();

    switch ( $operation ) {
        case 'overview':
            $method = 'GET'; $route = '/admin/v1/overview'; break;
        case 'create_customer':
            $route = '/admin/v1/customers';
            $payload = array( 'name' => isset( $body['name'] ) && is_string( $body['name'] ) ? sanitize_text_field( $body['name'] ) : '' );
            break;
        case 'set_customer_status':
            if ( ! $customer ) { break; }
            $method = 'PATCH'; $route = "/admin/v1/customers/$customer/status";
            $payload = array( 'status' => $body['status'] ?? '' ); break;
        case 'issue_key':
            if ( $customer ) { $route = "/admin/v1/customers/$customer/keys"; } break;
        case 'revoke_key':
            if ( $customer && $key ) { $route = "/admin/v1/customers/$customer/keys/$key/revoke"; } break;
        case 'create_package':
            if ( ! $customer ) { break; }
            $route = "/admin/v1/customers/$customer/packages";
            $payload = array( 'name' => isset( $body['name'] ) && is_string( $body['name'] ) ? sanitize_text_field( $body['name'] ) : '' ); break;
        case 'create_lease':
            if ( ! $customer || ! $package ) { break; }
            $route = "/admin/v1/customers/$customer/packages/$package/leases";
            $payload = array( 'expiresAt' => $body['expiresAt'] ?? null ); break;
        case 'update_lease':
            if ( ! $customer || ! $lease ) { break; }
            $method = 'PATCH'; $route = "/admin/v1/customers/$customer/leases/$lease";
            $payload = array( 'status' => $body['status'] ?? '', 'expiresAt' => $body['expiresAt'] ?? null ); break;
    }
    if ( '' === $route ) { wp_send_json_error( array( 'error' => 'invalid_operation' ), 400 ); }
    $response = wp_remote_request( $base . $route, array(
        'method' => $method,
        'timeout' => 8,
        'redirection' => 0,
        'sslverify' => true,
        'headers' => array(
            'X-SyndaMorph-Admin-Token' => SYNDAMORPH_GATEWAY_ADMIN_TOKEN,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ),
        'body' => 'GET' === $method ? null : wp_json_encode( $payload ),
        'limit_response_size' => 262144,
    ) );
    if ( is_wp_error( $response ) ) {
        wp_send_json_error( array( 'error' => 'gateway_unreachable' ), 502 );
    }
    $code = wp_remote_retrieve_response_code( $response );
    $result = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $result ) ) {
        wp_send_json_error( array( 'error' => 'invalid_gateway_response' ), 502 );
    }
    if ( $code < 200 || $code >= 300 ) {
        wp_send_json_error( array( 'error' => isset( $result['error'] ) && is_string( $result['error'] ) ? sanitize_key( $result['error'] ) : 'gateway_error' ), $code >= 400 && $code < 600 ? $code : 502 );
    }
    nocache_headers();
    wp_send_json_success( $result );
}
