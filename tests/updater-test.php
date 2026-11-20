<?php
/** Offline WordPress hook/HTTP contract tests. No WordPress database or network. */
error_reporting( E_ALL );
set_error_handler( static function ( $level, $message, $file, $line ) {
    throw new ErrorException( $message, 0, $level, $file, $line );
} );
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
class WP_Error {}
$hooks = array();
$cache = array();
$requests = array();
$now = 0;
$assertions = 0;
function add_filter( $name, $callback, $priority, $accepted ) {
    $GLOBALS['hooks'][ $name ][] = array( $callback, $accepted );
}
function apply_filters( $name, $value, ...$args ) {
    foreach ( $GLOBALS['hooks'][ $name ] ?? array() as [ $callback, $accepted ] ) {
        $value = $callback( ...array_slice( array_merge( array( $value ), $args ), 0, $accepted ) );
    }
    return $value;
}
function get_transient( $key ) {
    $item = $GLOBALS['cache'][ $key ] ?? null;
    return $item && $item[1] > $GLOBALS['now'] ? $item[0] : false;
}
function set_transient( $key, $value, $ttl ) {
    $GLOBALS['cache'][ $key ] = array( $value, $GLOBALS['now'] + $ttl );
}
function wp_remote_get( $url, $args ) {
    $GLOBALS['requests'][] = array( $url, $args );
    return $GLOBALS['http'];
}
function is_wp_error( $response ) { return $response instanceof WP_Error; }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code'] ?? ''; }
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }
function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function check( $condition, $message ) {
    ++$GLOBALS['assertions'];
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
require __DIR__ . '/../plugin/includes/updater.php';
function fixture( $tag = 'v0.11.2' ) {
    return array(
        'tag_name' => $tag, 'draft' => false, 'prerelease' => false,
        'published_at' => '2026-11-20T00:00:00Z', 'body' => "## [0.11.2]\n<script>alert(1)</script>",
        'zipball_url' => 'https://api.github.com/repos/gnznovus/SyndaMorph/zipball/' . $tag,
        'assets' => array( array(
            'name' => 'syndamorph.zip', 'state' => 'uploaded', 'size' => 1024,
            'browser_download_url' => SYNDAMORPH_UPDATE_URI . '/releases/download/' . $tag . '/syndamorph.zip',
        ) ),
    );
}
function respond( $release, $code = 200 ) {
    $GLOBALS['cache'] = array( 'other_plugin_release' => array( 'untouched', PHP_INT_MAX ) );
    $GLOBALS['requests'] = array();
    $GLOBALS['http'] = array( 'response' => array( 'code' => $code ), 'body' => is_string( $release ) ? $release : json_encode( $release ) );
}
function update( $installed = '0.11.1', $incoming = false, $file = 'syndamorph/syndamorph.php', $uri = SYNDAMORPH_UPDATE_URI ) {
    return apply_filters( 'update_plugins_github.com', $incoming, array( 'Version' => $installed, 'UpdateURI' => $uri ), $file, array( 'en_US' ) );
}

foreach ( array( 'v0.11.2', '0.11.2', 'v1.0.0', 'v0.0.0' ) as $tag ) {
    $parsed = syndamorph_parse_release( fixture( $tag ) );
    check( $parsed['version'] === ltrim( $tag, 'v' ), 'Stable version normalization' );
    check( str_ends_with( $parsed['package'], '/' . $tag . '/syndamorph.zip' ), 'Exact attached package' );
}
foreach ( array( 'v0.11.2-beta.1', 'v0.11.2+build', 'v01.11.2', 'v0.11', 'main', "v0.11.2\n", 112, array() ) as $tag ) {
    $release = fixture();
    $release['tag_name'] = $tag;
    check( array() === syndamorph_parse_release( $release ), 'Reject nonstable/malformed tag' );
}
foreach ( array( null, false, 123, 'invalid', array(), array( 'assets' => false ) ) as $release ) {
    check( array() === syndamorph_parse_release( $release ), 'Reject malformed metadata' );
}
foreach ( array( 'draft', 'prerelease' ) as $flag ) {
    foreach ( array( true, null, 0, 'false' ) as $value ) {
        $release = fixture(); $release[ $flag ] = $value;
        check( array() === syndamorph_parse_release( $release ), 'Require explicit stable published flags' );
    }
    $release = fixture(); unset( $release[ $flag ] );
    check( array() === syndamorph_parse_release( $release ), 'Require stable flag' );
}
foreach ( array( null, '', false, array() ) as $published ) {
    $release = fixture(); $release['published_at'] = $published;
    check( array() === syndamorph_parse_release( $release ), 'Require publication' );
}
foreach ( array( null, false, 123, 'assets', array(), array( null, 7, 'asset', array() ) ) as $assets ) {
    $release = fixture(); $release['assets'] = $assets;
    check( array() === syndamorph_parse_release( $release ), 'Missing/malformed assets must be silent' );
}
foreach ( array(
    'http://github.com/gnznovus/SyndaMorph/releases/download/v0.11.2/syndamorph.zip',
    'https://github.com.evil.test/gnznovus/SyndaMorph/releases/download/v0.11.2/syndamorph.zip',
    'https://github.com/other/plugin/releases/download/v0.11.2/syndamorph.zip',
    SYNDAMORPH_UPDATE_URI . '/archive/refs/tags/v0.11.2.zip',
    SYNDAMORPH_UPDATE_URI . '/releases/download/v0.11.1/syndamorph.zip',
    SYNDAMORPH_UPDATE_URI . '/releases/download/v0.11.2/source.zip',
    SYNDAMORPH_UPDATE_URI . '/releases/download/v0.11.2/syndamorph.zip?download=1',
    null, array(),
) as $url ) {
    $release = fixture(); $release['assets'][0]['browser_download_url'] = $url;
    check( array() === syndamorph_parse_release( $release ), 'Reject unrelated or invalid package URL' );
}
foreach ( array( array( 'name', 'source.zip' ), array( 'state', 'starter' ), array( 'size', 0 ), array( 'size', '100' ), array( 'size', null ) ) as [ $field, $value ] ) {
    $release = fixture(); $release['assets'][0][ $field ] = $value;
    check( array() === syndamorph_parse_release( $release ), 'Require complete attached ZIP' );
}
$release = fixture(); array_unshift( $release['assets'], null, array( 'name' => 'checksums.txt' ) );
check( '0.11.2' === syndamorph_parse_release( $release )['version'], 'Ignore unrelated assets' );

respond( fixture() );
$metadata = syndamorph_release_metadata();
check( $metadata === syndamorph_release_metadata() && 1 === count( $requests ), 'Successful checks cached' );
check( $cache[ SYNDAMORPH_RELEASE_CACHE ][1] - $now === 6 * HOUR_IN_SECONDS, 'Success cache TTL' );
check( SYNDAMORPH_RELEASE_API === $requests[0][0] && 6 === $requests[0][1]['timeout'], 'Bounded API request' );
check( 1024 * 1024 === $requests[0][1]['limit_response_size'], 'Bounded response' );
$now += 6 * HOUR_IN_SECONDS + 1;
syndamorph_release_metadata();
check( 2 === count( $requests ), 'Expired success cache refreshed' );

foreach ( array( new WP_Error(), 403, 404, 429, 500, 'not json', 'null', '{"assets":7}', fixture( 'v0.11.2-rc.1' ), array_replace( fixture(), array( 'assets' => array() ) ) ) as $failure ) {
    respond( is_int( $failure ) ? fixture() : $failure, is_int( $failure ) ? $failure : 200 );
    if ( $failure instanceof WP_Error ) { $http = $failure; }
    ob_start();
    check( false === update(), 'Failed check offers no update' );
    check( false === update(), 'Failed checks cached' );
    check( '' === ob_get_clean(), 'Failed check emits no output' );
    check( 1 === count( $requests ), 'Failure causes no repeated requests' );
    check( $cache[ SYNDAMORPH_RELEASE_CACHE ][1] - $now === 15 * MINUTE_IN_SECONDS, 'Failure backoff TTL' );
    check( 'untouched' === get_transient( 'other_plugin_release' ), 'Other plugin state preserved' );
    $sentinel = new stdClass();
    check( $sentinel === apply_filters( 'plugins_api', $sentinel, 'plugin_information', (object) array( 'slug' => 'syndamorph' ) ), 'Failure preserves information result' );
    $http = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( fixture() ) );
    $now += 15 * MINUTE_IN_SECONDS + 1;
    check( '0.11.2' === update()['version'], 'Check recovers after backoff' );
}

foreach ( array( '0.11.1' => true, '0.11.2' => false, '0.11.3' => false, '0.9.9' => true, '0.11.10' => false, '0.11.2-beta.1' => true ) as $installed => $newer ) {
    respond( fixture() );
    $result = update( $installed );
    check( '0.11.2' === $result['version'], 'Metadata available to native response/no_update handling' );
    check( $newer === version_compare( $result['version'], $installed, '>' ), 'Native semantic version comparison' );
    check( $newer === ( '' !== $result['package'] ), 'Only newer releases have an update package' );
    check( 'syndamorph' === $result['slug'] && SYNDAMORPH_UPDATE_URI === $result['id'], 'Native update identity' );
    check( '6.5' === $result['requires'] && '8.1' === $result['requires_php'], 'Compatibility requirements' );
    check( ! isset( $result['autoupdate'] ), 'Site controls automatic updates' );
}
respond( fixture( 'v0.12.0' ) );
check( '0.12.0' === update( '0.11.99' )['version'], 'Minor version comparison' );
respond( fixture( 'v0.11.10' ) );
check( '' !== update( '0.11.2' )['package'], 'Multi-digit patch update' );
respond( fixture( 'v1.0.0' ) );
check( '1.0.0' === update( '0.99.99' )['version'], 'Major version comparison' );

respond( fixture() );
$sentinel = (object) array( 'other' => 'untouched' );
check( $sentinel === update( '0.11.1', $sentinel, 'other/other.php' ), 'Other GitHub plugin result preserved' );
check( false === update( '0.11.1', false, 'other/other.php' ), 'Other GitHub plugin not queried' );
check( false === update( '0.11.1', false, 'syndamorph/syndamorph.php', 'https://github.com/other/plugin' ), 'Other URI not queried' );
check( $sentinel === update( '0.11.1', $sentinel ), 'Earlier updater result preserved' );
foreach ( array( 'query_plugins', 'hot_tags' ) as $action ) {
    check( $sentinel === apply_filters( 'plugins_api', $sentinel, $action, (object) array( 'slug' => 'syndamorph' ) ), 'Other API actions preserved' );
}
check( $sentinel === apply_filters( 'plugins_api', $sentinel, 'plugin_information', (object) array( 'slug' => 'other' ) ), 'Other plugin dialog preserved' );
check( $sentinel === apply_filters( 'plugins_api', $sentinel, 'plugin_information', null ), 'Invalid dialog args preserved' );
check( 0 === count( $requests ), 'Unrelated filters never request metadata' );
$info = apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => 'syndamorph' ) );
check( is_object( $info ) && '0.11.2' === $info->version, 'Plugin information object' );
check( str_ends_with( $info->download_link, '/v0.11.2/syndamorph.zip' ), 'Dialog uses attached ZIP' );
// Keep advertised compatibility and identity aligned with the installed header.
$plugin_header = file_get_contents( __DIR__ . '/../plugin/syndamorph.php' );
preg_match( '/\* Requires at least: ([^\r\n]+)/', $plugin_header, $wp_header );
preg_match( '/\* Requires PHP: ([^\r\n]+)/', $plugin_header, $php_header );
check( $wp_header[1] === $info->requires, 'WordPress requirement matches plugin header' );
check( $php_header[1] === $info->requires_php, 'PHP requirement matches plugin header' );
check( str_contains( $plugin_header, 'Update URI: ' . SYNDAMORPH_UPDATE_URI ), 'Update URI matches plugin header' );
check( str_contains( $info->sections['changelog'], '&lt;script&gt;' ) && ! str_contains( $info->sections['changelog'], '<script>' ), 'Release notes escaped' );
$release = fixture(); $release['body'] = null; respond( $release );
$info = apply_filters( 'plugins_api', false, 'plugin_information', (object) array( 'slug' => 'syndamorph' ) );
check( str_contains( $info->sections['changelog'], 'CHANGELOG.md' ), 'Missing notes fallback' );
echo "PASS: $assertions updater assertions (offline WordPress contracts; warnings are failures)\n";
