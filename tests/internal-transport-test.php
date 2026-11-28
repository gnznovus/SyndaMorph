<?php
/** Standalone tests for the deployment trust boundary. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    public function __construct( public string $code, public string $message ) {}
}
$requests = array();
function wp_remote_post( $url, $args ) {
    $GLOBALS['requests'][] = array( $url, $args );
    return array();
}
function wp_remote_get( $url, $args ) { return wp_remote_post( $url, $args ); }
function wp_remote_request( $url, $args ) { return wp_remote_post( $url, $args ); }
require dirname( __DIR__ ) . '/plugin/includes/internal-transport.php';

function transport_expect( bool $ok, string $label ): void {
    if ( ! $ok ) { throw new RuntimeException( $label ); }
}

putenv( 'SYNDAMORPH_CAPTURE_HOSTS= , Approved.Example,  ' );
transport_expect( array_values( syndamorph_capture_hosts() ) === array( 'approved.example' ), 'Capture host normalization differed from the backend' );
putenv( 'SYNDAMORPH_CAPTURE_HOSTS= , ' );
transport_expect( syndamorph_capture_hosts() === array(), 'Empty capture allowlist accepted a host' );

putenv( 'SYNDAMORPH_BACKEND_TOKEN=backend-transport-test-fixture' );
putenv( 'SYNDAMORPH_RENDERER_TOKEN=renderer-transport-test-fixture-v1' );
putenv( 'SYNDAMORPH_GATEWAY_ADMIN_TOKEN=gateway-transport-test-fixture-1' );
foreach ( array( 'backend' => 3100, 'renderer' => 3000, 'gateway' => 3200 ) as $service => $port ) {
    $setting = 'SYNDAMORPH_' . strtoupper( $service ) . '_URL';
    putenv( $setting );
    transport_expect( syndamorph_internal_base( $service ) === "http://$service:$port", 'Docker default rejected' );
    foreach ( array( "http://127.0.0.1:$port", "http://127.0.0.1:$port/" ) as $url ) {
        putenv( "$setting=$url" );
        transport_expect( syndamorph_internal_base( $service ) === "http://127.0.0.1:$port", 'Exact loopback rejected' );
    }
    foreach ( array( '', "http://127.0.0.1:1", "http://user@127.0.0.1:$port", "http://127.0.0.1@$service:$port",
        "http://127.0.0.1:$port?route=1", "http://127.0.0.1:$port/#a", "http://127.0.0.1:$port//",
        "http://127.0.0.1:$port/%2e%2e", "http://localhost:$port", "http://[::1]:$port",
        "http://0177.0.0.1:$port", "http://2130706433:$port", "http://127.0.0.1:0$port",
        "http://127.0.0.1:$port\n", "http://$service.example:$port", "http://$service.:$port", "https://remote.invalid:$port",
        "http://10.0.0.3:$port", "http://127.0.0.1.nip.io:$port", "http://$service:$port/redirect" ) as $bad ) {
        putenv( "$setting=$bad" );
        transport_expect( syndamorph_internal_base( $service ) === null, 'Unexpected URL accepted' );
    }
    putenv( "$setting=http://127.0.0.1:$port" );
}
putenv( 'SYNDAMORPH_BACKEND_URL=http://127.0.0.1:3100/capture' );
putenv( 'SYNDAMORPH_RENDERER_URL=http://127.0.0.1:3000/render' );
putenv( 'SYNDAMORPH_RENDERER_INSPECT_URL=http://renderer:3000/inspect' );
transport_expect( syndamorph_internal_base( 'renderer' ) === null, 'Cross-service inspection origin accepted' );
putenv( 'SYNDAMORPH_RENDERER_INSPECT_URL=http://127.0.0.1:3000/inspect' );
putenv( 'SYNDAMORPH_RENDERER_TOKEN=' );
transport_expect( syndamorph_internal_request( 'renderer', 'POST', '/inspect' ) instanceof WP_Error, 'Empty renderer token accepted' );
putenv( 'SYNDAMORPH_RENDERER_TOKEN=gateway-transport-test-fixture-1' );
transport_expect( ! syndamorph_internal_configured( 'renderer' ), 'Shared tokens accepted' );
putenv( 'SYNDAMORPH_RENDERER_TOKEN=renderer-transport-test-fixture-v1' );
transport_expect( syndamorph_internal_request( 'renderer', 'POST', '//other.invalid/inspect' ) instanceof WP_Error, 'Arbitrary route accepted' );
transport_expect( syndamorph_internal_request( 'backend', 'POST', '/check-tabs' ) instanceof WP_Error, 'Cross-service route accepted' );
$customer = '/admin/v1/customers/cus_' . str_repeat( 'a', 24 );
$lease = 'lease_' . str_repeat( 'b', 24 );
transport_expect( syndamorph_internal_request( 'gateway', 'PATCH', $customer . '/packages/pkg_' . str_repeat( 'c', 24 ) . '/leases/' . $lease ) instanceof WP_Error,
    'A nonexistent nested lease update route was accepted' );
transport_expect( ! $requests, 'Invalid request reached WordPress' );
syndamorph_internal_request( 'backend', 'POST', '/production-source', array( 'redirection' => 10, 'limit_response_size' => 1234 ) );
syndamorph_internal_request( 'renderer', 'POST', '/check-tabs' );
syndamorph_internal_request( 'gateway', 'GET', '/admin/v1/overview' );
syndamorph_internal_request( 'gateway', 'PATCH', $customer . '/leases/' . $lease );
transport_expect( array_column( $requests, 0 ) === array( 'http://127.0.0.1:3100/production-source',
    'http://127.0.0.1:3000/check-tabs', 'http://127.0.0.1:3200/admin/v1/overview',
    'http://127.0.0.1:3200' . $customer . '/leases/' . $lease ), 'Fixed routes used the wrong origins' );
transport_expect( $requests[0][1]['redirection'] === 0 && $requests[0][1]['limit_response_size'] === 1234,
    'Redirect or response limit policy changed' );
transport_expect( ! str_contains( json_encode( $requests ), '-transport-test-fixture' ), 'Private tokens reached WordPress HTTP arguments' );
define( 'SYNDAMORPH_BACKEND_URL', 'http://backend:3100/capture' );
transport_expect( syndamorph_internal_base( 'backend' ) === 'http://backend:3100', 'wp-config did not take precedence over environment' );
define( 'SYNDAMORPH_GATEWAY_URL', false );
define( 'SYNDAMORPH_RENDERER_INSPECT_URL', false );
transport_expect( syndamorph_internal_base( 'gateway' ) === null, 'An invalid endpoint constant fell back to the Docker default' );
transport_expect( syndamorph_internal_base( 'renderer' ) === null, 'An invalid inspection override was ignored' );
echo "PASS: fixed endpoint origins and routes, wp-config precedence, separate credentials, redirect and response limits\n";
