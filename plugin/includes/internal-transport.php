<?php
/** Server-owned HTTP transport for the three private SyndaMorph services. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_internal_setting( string $name ) {
    return defined( $name ) ? constant( $name ) : getenv( $name );
}

function syndamorph_internal_services(): array {
    return array(
        'backend' => array( 3100, 'SYNDAMORPH_BACKEND_URL', '/capture', 'SYNDAMORPH_BACKEND_TOKEN', 'X-SyndaMorph-Token', 1 ),
        'renderer' => array( 3000, 'SYNDAMORPH_RENDERER_URL', '/render', 'SYNDAMORPH_RENDERER_TOKEN', 'X-SyndaMorph-Renderer-Token', 32 ),
        'gateway' => array( 3200, 'SYNDAMORPH_GATEWAY_URL', '', 'SYNDAMORPH_GATEWAY_ADMIN_TOKEN', 'X-SyndaMorph-Admin-Token', 32 ),
    );
}

/** Normalize only the exact service URL and its historical entry path. */
function syndamorph_internal_base( string $service ): ?string {
    $spec = syndamorph_internal_services()[ $service ] ?? null;
    if ( ! $spec ) { return null; }
    list( $port, $setting, $legacy ) = $spec;
    $docker = 'http://' . $service . ':' . $port;
    $loopback = 'http://127.0.0.1:' . $port;
    $value = syndamorph_internal_setting( $setting );
    if ( $value === false && ! defined( $setting ) ) { $value = $docker; }
    foreach ( array( $docker, $loopback ) as $base ) {
        if ( ! in_array( $value, array( $base, $base . '/', $base . $legacy ), true ) ) { continue; }
        if ( 'renderer' === $service ) {
            $inspect = syndamorph_internal_setting( 'SYNDAMORPH_RENDERER_INSPECT_URL' );
            if ( ( $inspect !== false || defined( 'SYNDAMORPH_RENDERER_INSPECT_URL' ) ) &&
                $inspect !== $base . '/inspect' ) { return null; }
        }
        return $base;
    }
    return null;
}

function syndamorph_internal_token( string $service ): ?string {
    $services = syndamorph_internal_services();
    $spec = $services[ $service ] ?? null;
    if ( ! $spec ) { return null; }
    $token = syndamorph_internal_setting( $spec[3] );
    if ( ! is_string( $token ) || ! preg_match( '/^[!-~]{' . $spec[5] . ',256}$/D', $token ) ) { return null; }
    foreach ( $services as $name => $other ) {
        if ( $name !== $service && $token === syndamorph_internal_setting( $other[3] ) ) { return null; }
    }
    return $token;
}

function syndamorph_internal_configured( string $service ): bool {
    return syndamorph_internal_base( $service ) !== null && syndamorph_internal_token( $service ) !== null;
}

function syndamorph_internal_route_allowed( string $service, string $method, string $path ): bool {
    $routes = array(
        'backend' => array( 'GET' => array( '/healthz' ),
            'POST' => array( '/capture', '/production-source', '/production-touch', '/purr/communication-poc' ) ),
        'renderer' => array( 'GET' => array( '/healthz' ), 'POST' => array( '/render', '/inspect', '/compare', '/crop',
            '/check-tabs', '/check-scroll-strips', '/check-disclosures', '/check-image-strip', '/check-reveal-cards', '/check-rooms' ) ),
        'gateway' => array( 'GET' => array( '/admin/v1/healthz', '/admin/v1/overview' ), 'POST' => array( '/admin/v1/customers' ) ),
    );
    if ( in_array( $path, $routes[ $service ][ $method ] ?? array(), true ) ) { return true; }
    if ( 'gateway' !== $service ) { return false; }
    $customer = '/admin/v1/customers/cus_[a-f0-9]{24}';
    if ( 'POST' === $method ) {
        return (bool) preg_match( '~^' . $customer . '/(?:keys(?:/key_[a-f0-9]{24}/revoke)?|packages(?:/pkg_[a-f0-9]{24}/leases)?)$~D', $path );
    }
    return 'PATCH' === $method && (bool) preg_match( '~^' . $customer . '/(?:status|leases/lease_[a-f0-9]{24})$~D', $path );
}

function syndamorph_internal_docker_address( string $host ): ?string {
    // Bare Compose aliases must never fall through to the host's DNS search path.
    $resolver = is_file( '/.dockerenv' ) ? @file_get_contents( '/etc/resolv.conf', false, null, 0, 8192 ) : false;
    if ( ! is_string( $resolver ) ) { return null; }
    preg_match_all( '/^\s*nameserver\s+(\S+)/m', $resolver, $servers );
    if ( $servers[1] !== array( '127.0.0.11' ) ) { return null; }
    $addresses = gethostbynamel( $host . '.' );
    if ( ! is_array( $addresses ) || count( $addresses ) !== 1 ) { return null; }
    $address = $addresses[0];
    if ( ! filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ||
        ! preg_match( '/^(?:10\.|172\.(?:1[6-9]|2[0-9]|3[01])\.|192\.168\.)/', $address ) ) { return null; }
    return $address;
}

function syndamorph_internal_request( string $service, string $method, string $path, array $args = array() ) {
    $base = syndamorph_internal_base( $service );
    $token = syndamorph_internal_token( $service );
    if ( $base === null || $token === null || ! syndamorph_internal_route_allowed( $service, $method, $path ) ) {
        return new WP_Error( 'sm_internal_config', 'The private service is not configured.' );
    }
    $url = $base . $path;
    // These URLs are fixed here. WordPress's generic unsafe-URL test disallows our private IPs.
    // The site-wide HTTP block policy remains in force.
    $args['redirection'] = 0;
    $args['reject_unsafe_urls'] = false;
    $args['limit_response_size'] = $args['limit_response_size'] ?? 262144;
    $args['method'] = $method;
    $request = static function() use ( $url, &$args, $method ) {
        if ( 'POST' === $method ) { return wp_remote_post( $url, $args ); }
        if ( 'GET' === $method ) { return wp_remote_get( $url, $args ); }
        return wp_remote_request( $url, $args );
    };

    // WordPress's offline test doubles have no HTTP transport.
    if ( ! class_exists( 'WP_Http' ) ) { return $request(); }
    if ( ! class_exists( '\WpOrg\Requests\Transport\Curl' ) || ! \WpOrg\Requests\Transport\Curl::test() ) {
        return new WP_Error( 'sm_internal_transport', 'The private service requires the PHP curl transport.' );
    }
    $proxy = new WP_HTTP_Proxy();
    if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
        return new WP_Error( 'sm_internal_proxy', 'WordPress routes this private service through an HTTP proxy.' );
    }
    $host = parse_url( $base, PHP_URL_HOST );
    $port = parse_url( $base, PHP_URL_PORT );
    $header = syndamorph_internal_services()[ $service ][4];
    $mark = new stdClass();
    $args['_syndamorph_private_request'] = $mark;
    $curl = static function( $handle, array $parsed, string $target ) use ( $mark, $url, $host, $port, $header, $token ): void {
        if ( $target !== $url || ( $parsed['_syndamorph_private_request'] ?? null ) !== $mark ) { return; }
        if ( $parsed['redirection'] !== 0 ) { throw new \RuntimeException( 'Private HTTP redirects are disabled.' ); }
        $options = array( CURLOPT_URL => $url, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_VERBOSE => false );
        if ( $host !== '127.0.0.1' ) {
            $address = syndamorph_internal_docker_address( $host );
            if ( $address === null ) { throw new \RuntimeException( 'Private Docker address could not be verified.' ); }
            $options[ CURLOPT_RESOLVE ] = array( $host . ':' . $port . ':' . $address );
        }
        // Add the credential below the WordPress hook/debug layer, only on this pinned request.
        $headers = array( $header . ': ' . $token, 'Expect:' );
        foreach ( $parsed['headers'] as $name => $value ) {
            if ( in_array( strtolower( $name ), array( strtolower( $header ), 'host', 'expect' ), true ) ) { continue; }
            if ( is_string( $value ) ) { $headers[] = $name . ': ' . $value; }
        }
        $options[ CURLOPT_HTTPHEADER ] = $headers;
        if ( ! curl_setopt_array( $handle, $options ) ) { throw new \RuntimeException( 'Private HTTP transport could not be configured.' ); }
    };
    add_action( 'http_api_curl', $curl, PHP_INT_MAX, 3 );
    try {
        return $request();
    } catch ( \Throwable $error ) {
        return new WP_Error( 'sm_internal_transport', 'The private HTTP connection could not be verified.' );
    } finally {
        remove_action( 'http_api_curl', $curl, PHP_INT_MAX );
    }
}
