<?php
/**
 * Offline credential lifecycle acceptance tests. No WordPress installation or
 * third-party API access required. Exercise the actual plugin functions/routes.
 */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_DEBUG', true );
putenv( 'SYNDAMORPH_MOCK_AI_ENABLED=1' );

$GLOBALS['sm_test_options'] = array();
$GLOBALS['sm_test_routes'] = array();
$GLOBALS['sm_test_actions'] = array();
$GLOBALS['sm_test_authorized'] = true;

function add_action( $name, $callback ) { $GLOBALS['sm_test_actions'][$name][] = $callback; }
function register_rest_route( $namespace, $path, $config ) { $GLOBALS['sm_test_routes'][$path] = $config; }
function current_user_can( $cap ) { return $GLOBALS['sm_test_authorized']; }
function get_option( $name, $default = false ) { return $GLOBALS['sm_test_options'][$name] ?? $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['sm_test_options'][$name] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['sm_test_options'][$name] ); return true; }
function delete_transient( $name ) { return true; }
function wp_salt( $purpose ) { return 'isolated-mock-test-salt-' . $purpose; }
function rest_ensure_response( $value ) { return $value; }
function rest_url( $path ) { return '/wp-json/' . $path; }
function wp_json_encode( $data ) { return json_encode( $data ); }

class WP_REST_Request {
    private $params;
    public function __construct( array $params = array() ) { $this->params = $params; }
    public function get_param( $key ) { return $this->params[$key] ?? null; }
}
class WP_Error {
    public $code;
    public function __construct( $code, $message = '', $data = array() ) { $this->code = $code; }
}

require __DIR__ . '/../plugin/includes/ai-integration.php';
foreach ( $GLOBALS['sm_test_actions']['rest_api_init'] as $callback ) { $callback(); }

function sm_assert( $condition, $message ) {
    if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); }
}
function sm_route( $path, $params = array() ) {
    return $GLOBALS['sm_test_routes'][$path]['callback']( new WP_REST_Request( $params ) );
}
function sm_delete_route( $provider ) {
    return sm_route( '/ai/keys/(?P<provider>[a-z]+)', array( 'provider' => $provider ) );
}
$success = 'sm_mock_success_2026';
$rejected = 'sm_mock_reject_2026';
$storageKey = 'syndamorph_ai_key_mock';

sm_assert( syndamorph_ai_mock_enabled(), 'Mock must be enabled only for explicit dev environment.' );
sm_assert( array_key_exists( 'mock', syndamorph_ai_providers() ), 'Mock provider not registered.' );
sm_assert( ! syndamorph_ai_key_status( 'mock' )['configured'], 'Mock must start without saved key.' );

$GLOBALS['sm_test_authorized'] = false;
foreach ( array( '/ai/providers', '/ai/test', '/ai/keys/(?P<provider>[a-z]+)' ) as $path ) {
    sm_assert( ! $GLOBALS['sm_test_routes'][$path]['permission_callback'](), 'Unauthorized route access: ' . $path );
}
$GLOBALS['sm_test_authorized'] = true;

$result = sm_route( '/ai/test', array( 'provider' => 'mock', 'key' => $success ) );
sm_assert( $result['ok'] && 200 === $result['http_status'], 'Synthetic credential must pass local mock.' );
sm_assert( $result['key']['configured'], 'Mock must use real persistent credential storage.' );
sm_assert( 'sm_m••••••••' === $result['key']['preview'], 'Status must contain only four leading characters.' );
sm_assert( isset( $GLOBALS['sm_test_options'][$storageKey] ), 'Encrypted option missing.' );
sm_assert( ! str_contains( $GLOBALS['sm_test_options'][$storageKey], $success ), 'Stored option contains plaintext key.' );
sm_assert( ! str_contains( json_encode( $result ), $success ), 'Full credential leaked through test response.' );

$status = sm_route( '/ai/providers' );
sm_assert( $status['mock']['configured'], 'Saved credential not visible after reload.' );
sm_assert( ! str_contains( json_encode( $status ), $success ), 'Provider status exposed saved key.' );
$result = sm_route( '/ai/test', array( 'provider' => 'mock' ) );
sm_assert( $result['ok'], 'Stored key cannot be reused without resubmission.' );
sm_assert( ! str_contains( json_encode( $result ), $success ), 'Reused secret leaked to the browser.' );

$result = sm_route( '/ai/test', array( 'provider' => 'mock', 'key' => $rejected ) );
sm_assert( ! $result['ok'] && 401 === $result['http_status'], 'Rejected mock scenario failed.' );
sm_assert( ! str_contains( json_encode( $result ), $rejected ), 'Rejected key leaked in response.' );

$deleted = sm_delete_route( 'mock' );
sm_assert( $deleted['deleted'] && ! $deleted['key']['configured'], 'Server-side mock credential deletion failed.' );
sm_assert( ! array_key_exists( $storageKey, $GLOBALS['sm_test_options'] ), 'Deleted ciphertext persists in options.' );
sm_assert( ! sm_route( '/ai/providers' )['mock']['configured'], 'Provider remains configured after deletion.' );
$missing = sm_route( '/ai/test', array( 'provider' => 'mock' ) );
sm_assert( $missing instanceof WP_Error && 'syndamorph_ai_missing' === $missing->code, 'Deleted mock key remains usable.' );

// Real and mock providers must share key storage/deletion mechanisms.
update_option( 'syndamorph_ai_key_openai', syndamorph_ai_encrypt( 'synthetic-real-provider-key' ), false );
sm_assert( syndamorph_ai_key_status( 'openai' )['configured'], 'Real provider key storage regression.' );
$deleted = sm_delete_route( 'openai' );
sm_assert( $deleted['deleted'] && ! $deleted['key']['configured'], 'Real provider deletion must use the same path.' );

putenv( 'SYNDAMORPH_MOCK_AI_ENABLED=0' );
sm_assert( ! syndamorph_ai_mock_enabled(), 'Mock remains enabled after dev flag is disabled.' );
sm_assert( ! array_key_exists( 'mock', syndamorph_ai_providers() ), 'Mock leaked into non-mock provider list.' );
echo "PASS: AI credential lifecycle, sanitization, authorization, mock isolation and deletion.\n";
