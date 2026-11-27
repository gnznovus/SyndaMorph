<?php
/** Administrator-only, non-mutating checks for the private services. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_transport_status( $response ): string {
    if ( is_wp_error( $response ) ) {
        return match ( $response->get_error_code() ) {
            'sm_internal_config' => 'Check the configured endpoint and server credential.',
            'sm_internal_proxy' => 'WordPress HTTP policy sends this endpoint through a proxy.',
            'http_request_not_executed' => 'WordPress HTTP policy blocked this endpoint.',
            'sm_internal_transport' => 'The private connection could not be verified. Check PHP curl and the network.',
            default => 'The private service did not respond.',
        };
    }
    return match ( (int) wp_remote_retrieve_response_code( $response ) ) {
        200 => 'Verified',
        401, 403 => 'The service rejected its server credential.',
        default => 'The service did not return the expected response.',
    };
}

function syndamorph_transport_self_test(): array {
    $backend = syndamorph_internal_request( 'backend', 'GET', '/healthz', array( 'timeout' => 4, 'limit_response_size' => 2048 ) );
    $backend_status = syndamorph_transport_status( $backend );
    if ( 'Verified' === $backend_status && json_decode( wp_remote_retrieve_body( $backend ), true ) !==
        array( 'service' => 'syndamorph-backend', 'protocol' => 1 ) ) {
        $backend_status = 'The capture protocol did not match.';
    }

    $widths = array( 375 );
    $renderer = syndamorph_internal_request( 'renderer', 'POST', '/inspect', array(
        'timeout' => 15,
        'limit_response_size' => SYNDAMORPH_INSPECTION_RESPONSE_BYTES + 1,
        'headers' => array( 'Content-Type' => 'application/json', 'Accept-Encoding' => 'identity' ),
        'body' => wp_json_encode( array( 'html' => '<section><h2>Private renderer check</h2></section>',
            'viewports' => $widths, 'inspectionProtocol' => SYNDAMORPH_INSPECTION_PROTOCOL,
            'extent' => 'section', 'sourceFrames' => array( 375 => 12.5 ) ) ),
    ) );
    $renderer_status = syndamorph_transport_status( $renderer );
    if ( 'Verified' === $renderer_status ) {
        $samples = syndamorph_inspection_validate( $renderer, $widths, false, 'section' );
        if ( is_wp_error( $samples ) || ( $samples[0]['snapshot']['copy'] ?? null ) !== 'Private renderer check' ||
            ( $samples[0]['sourceFrame']['top'] ?? null ) !== 12.5 ||
            ( $samples[0]['sourceFrame']['screenshotTop'] ?? null ) !== 13 ) {
            $renderer_status = 'The renderer protocol or Chromium evidence did not match.';
        }
    }

    $gateway = syndamorph_internal_request( 'gateway', 'GET', '/admin/v1/healthz',
        array( 'timeout' => 4, 'limit_response_size' => 2048 ) );
    $gateway_status = syndamorph_transport_status( $gateway );
    if ( 'Verified' === $gateway_status && json_decode( wp_remote_retrieve_body( $gateway ), true ) !==
        array( 'service' => 'syndamorph-gateway', 'protocol' => 1 ) ) {
        $gateway_status = 'The gateway protocol did not match.';
    }

    return array( 'Capture' => $backend_status, 'Renderer' => $renderer_status, 'Gateway' => $gateway_status );
}

add_action( 'admin_menu', static function(): void {
    $results = array();
    $page = add_management_page( 'SyndaMorph Transport', 'SyndaMorph Transport', 'manage_options', 'syndamorph-transport',
        static function() use ( &$results ): void {
            if ( ! current_user_can( 'manage_options' ) ) { return; }
            ?>
            <div class="wrap">
                <h1>SyndaMorph Transport</h1>
                <p>Check authenticated access to the capture service, Chromium renderer, and gateway from this WordPress installation.</p>
                <form method="post">
                    <?php wp_nonce_field( 'syndamorph_check_transport' ); ?>
                    <button type="submit" name="syndamorph_check_transport" value="1" class="button button-primary">Run private service checks</button>
                </form>
                <?php if ( $results ) : ?>
                    <dl>
                    <?php foreach ( $results as $service => $result ) : ?>
                        <dt><?php echo esc_html( $service ); ?></dt>
                        <dd><?php echo esc_html( $result ); ?></dd>
                    <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
            </div>
            <?php
        }
    );
    if ( $page ) {
        add_action( 'load-' . $page, static function() use ( &$results ): void {
            if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['syndamorph_check_transport'] ) ) { return; }
            check_admin_referer( 'syndamorph_check_transport' );
            $results = syndamorph_transport_self_test();
        } );
    }
} );
