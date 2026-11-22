<?php
/**
 * Internal MVP provider connectivity test. Credentials never enter REST responses.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_ai_providers(): array {
    return array( 'anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI', 'google' => 'Google (Gemini)' );
}

function syndamorph_ai_key_material(): string {
    return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
}

function syndamorph_ai_encrypt( string $key ) {
    if ( ! function_exists( 'openssl_encrypt' ) ) { return false; }
    $iv = random_bytes( 12 );
    $tag = '';
    $encrypted = openssl_encrypt( $key, 'aes-256-gcm', syndamorph_ai_key_material(), OPENSSL_RAW_DATA, $iv, $tag );
    return false === $encrypted ? false : base64_encode( $iv . $tag . $encrypted );
}

function syndamorph_ai_decrypt( string $stored ): string {
    if ( ! function_exists( 'openssl_decrypt' ) ) { return ''; }
    $raw = base64_decode( $stored, true );
    if ( false === $raw || strlen( $raw ) < 29 ) { return ''; }
    $value = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', syndamorph_ai_key_material(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
    return false === $value ? '' : $value;
}

function syndamorph_ai_saved_key( string $provider ): string {
    $stored = get_option( 'syndamorph_ai_key_' . $provider, '' );
    return is_string( $stored ) ? syndamorph_ai_decrypt( $stored ) : '';
}

function syndamorph_ai_key_status( string $provider ): array {
    $key = syndamorph_ai_saved_key( $provider );
    return array( 'configured' => '' !== $key, 'preview' => '' !== $key ? substr( $key, 0, 4 ) . '••••••••' : '' );
}

function syndamorph_ai_render_tab(): void {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    ?>
    <div class="wrap" id="syndamorph-ai-integration">
        <h1>SyndaMorph — AI Integration</h1>
        <nav class="nav-tab-wrapper" aria-label="SyndaMorph sections">
            <a class="nav-tab" href="<?php echo esc_url( admin_url( 'tools.php?page=syndamorph' ) ); ?>">Importer</a>
            <a class="nav-tab nav-tab-active" href="<?php echo esc_url( admin_url( 'tools.php?page=syndamorph&tab=ai' ) ); ?>" aria-current="page">AI Integration</a>
        </nav>
        <div style="max-width:780px;margin-top:24px">
            <p>Test provider connectivity for the internal MVP. Saved credentials stay server-side.</p>
            <table class="form-table" role="presentation">
                <tr><th scope="row"><label for="syndamorph-ai-provider">AI Provider</label></th><td>
                    <select id="syndamorph-ai-provider">
                        <?php foreach ( syndamorph_ai_providers() as $id => $name ) : ?>
                            <option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td></tr>
                <tr><th scope="row"><label for="syndamorph-ai-key">API Key</label></th><td>
                    <input type="password" id="syndamorph-ai-key" class="regular-text" autocomplete="new-password" spellcheck="false" placeholder="Enter new key to save">
                    <p class="description">Leave blank to use an existing key. Never paste credentials into debug logs.</p>
                    <p id="syndamorph-ai-preview" aria-live="polite"></p>
                </td></tr>
            </table>
            <p><button type="button" class="button button-primary" id="syndamorph-ai-test">Run Test</button></p>
            <h2>Verbose / Debug</h2>
            <pre id="syndamorph-ai-output" role="status" aria-live="polite" style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:390px;overflow:auto;background:#fff;border:1px solid #c3c4c7;padding:16px">No test executed.</pre>
        </div>
    </div>
    <?php
}

add_action( 'rest_api_init', static function (): void {
    $permission = static function (): bool { return current_user_can( 'manage_options' ); };
    register_rest_route( 'syndamorph/v1', '/ai/providers', array(
        'methods' => 'GET',
        'permission_callback' => $permission,
        'callback' => static function () {
            $statuses = array();
            foreach ( syndamorph_ai_providers() as $id => $label ) { $statuses[ $id ] = syndamorph_ai_key_status( $id ); }
            return rest_ensure_response( $statuses );
        },
    ) );
    register_rest_route( 'syndamorph/v1', '/ai/test', array(
        'methods' => 'POST',
        'permission_callback' => $permission,
        'callback' => static function ( WP_REST_Request $request ) {
            $provider = (string) $request->get_param( 'provider' );
            if ( ! array_key_exists( $provider, syndamorph_ai_providers() ) ) {
                return new WP_Error( 'syndamorph_ai_provider', 'Unsupported provider.', array( 'status' => 400 ) );
            }
            $new_key = $request->get_param( 'key' );
            if ( null !== $new_key && ! is_string( $new_key ) ) {
                return new WP_Error( 'syndamorph_ai_key', 'API key must be text.', array( 'status' => 400 ) );
            }
            if ( is_string( $new_key ) && '' !== $new_key ) {
                if ( strlen( $new_key ) > 4096 || preg_match( '/[\x00-\x1f\x7f]/', $new_key ) ) {
                    return new WP_Error( 'syndamorph_ai_key', 'Invalid key format.', array( 'status' => 400 ) );
                }
                $encrypted = syndamorph_ai_encrypt( $new_key );
                if ( false === $encrypted ) {
                    return new WP_Error( 'syndamorph_ai_storage', 'Credential encryption unavailable.', array( 'status' => 503 ) );
                }
                update_option( 'syndamorph_ai_key_' . $provider, $encrypted, false );
            }
            $key = syndamorph_ai_saved_key( $provider );
            if ( '' === $key ) {
                return new WP_Error( 'syndamorph_ai_missing', 'No saved key for this provider.', array( 'status' => 400 ) );
            }
            $started = microtime( true );
            $request_args = array( 'timeout' => 20, 'redirection' => 0, 'headers' => array(), 'body' => '' );
            if ( 'anthropic' === $provider ) {
                $url = 'https://api.anthropic.com/v1/messages';
                $request_args['headers'] = array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' );
                $request_args['body'] = wp_json_encode( array( 'model' => 'claude-sonnet-4-5', 'max_tokens' => 8, 'messages' => array( array( 'role' => 'user', 'content' => 'Reply OK.' ) ) ) );
            } elseif ( 'openai' === $provider ) {
                $url = 'https://api.openai.com/v1/models';
                $request_args['method'] = 'GET';
                $request_args['headers'] = array( 'Authorization' => 'Bearer ' . $key );
                unset( $request_args['body'] );
            } else {
                $url = 'https://generativelanguage.googleapis.com/v1beta/models';
                $request_args['method'] = 'GET';
                $request_args['headers'] = array( 'x-goog-api-key' => $key );
                unset( $request_args['body'] );
            }
            $response = wp_remote_request( $url, $request_args );
            $elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );
            $status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
            $success = ! is_wp_error( $response ) && $status >= 200 && $status < 300;
            // Intentionally omit headers, request payloads and upstream response bodies.
            return rest_ensure_response( array(
                'provider' => $provider,
                'ok' => $success,
                'http_status' => $status,
                'latency_ms' => $elapsed,
                'result' => $success ? 'Provider connection succeeded.' : ( 0 === $status ? 'Provider unavailable or connection failed.' : 'Provider rejected the test request or returned an error.' ),
                'key' => syndamorph_ai_key_status( $provider ),
                'diagnostics' => array( 'transport' => 'WordPress server-side HTTP', 'response_body_exposed' => false ),
            ) );
        },
    ) );
} );

add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {
    if ( 'tools_page_syndamorph' !== $hook || ! current_user_can( 'manage_options' ) ||
         ! isset( $_GET['tab'] ) || 'ai' !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) { return; }
    wp_enqueue_script( 'syndamorph-ai-tab', plugin_dir_url( __DIR__ ) . 'assets/ai-integration.js', array(), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/ai-integration.js' ), true );
    wp_add_inline_script( 'syndamorph-ai-tab', 'window.SyndaMorphAI=' . wp_json_encode( array(
        'nonce' => wp_create_nonce( 'wp_rest' ),
        'statusUrl' => rest_url( 'syndamorph/v1/ai/providers' ),
        'testUrl' => rest_url( 'syndamorph/v1/ai/test' ),
    ) ) . ';', 'before' );
} );
