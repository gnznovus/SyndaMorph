<?php
/**
 * Internal MVP provider connectivity test. Credentials never enter REST responses.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_ai_mock_enabled(): bool {
    return defined( 'WP_DEBUG' ) && WP_DEBUG && '1' === getenv( 'SYNDAMORPH_MOCK_AI_ENABLED' );
}

function syndamorph_ai_providers(): array {
    $providers = array( 'anthropic' => 'Anthropic (Claude)', 'openai' => 'OpenAI', 'google' => 'Google (Gemini)' );
    if ( syndamorph_ai_mock_enabled() ) {
        $providers['mock'] = 'Mock Provider (Local Test)';
    }
    return $providers;
}

function syndamorph_ai_mock_test( string $key ): array {
    $outcomes = array(
        'sm_mock_success_2026' => array( 200, 'Mock connection succeeded.' ),
        'sm_mock_reject_2026' => array( 401, 'Mock credential rejected.' ),
        'sm_mock_unavailable_2026' => array( 0, 'Mock provider unavailable.' ),
    );
    $outcome = $outcomes[ $key ] ?? array( 401, 'Mock credential rejected.' );
    return array(
        'provider' => 'mock',
        'ok' => 200 === $outcome[0],
        'http_status' => $outcome[0],
        'latency_ms' => 0,
        'result' => $outcome[1],
        'key' => syndamorph_ai_key_status( 'mock' ),
        'diagnostics' => array(
            'transport' => 'Local in-process mock (no external request)',
            'response_body_exposed' => false,
            'simulated' => true,
        ),
    );
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
        <div class="sm-dashboard">
            <aside class="sm-sidebar" aria-label="Operations navigation">
                <div class="sm-brand">
                    <span class="sm-brand-mark" aria-hidden="true">S</span>
                    <div><div class="sm-brand-name">SyndaMorph</div><div class="sm-brand-caption">Operations</div></div>
                </div>
                <div>
                    <p class="sm-nav-label">Workspace</p>
                    <nav class="sm-nav" aria-label="SyndaMorph modules">
                        <a class="sm-nav-item" href="<?php echo esc_url( admin_url( 'tools.php?page=syndamorph' ) ); ?>">
                            <span class="dashicons dashicons-groups" aria-hidden="true"></span> AI Production Team
                        </a>
                        <a class="sm-nav-item is-active" href="<?php echo esc_url( admin_url( 'tools.php?page=syndamorph&tab=ai' ) ); ?>" aria-current="page">
                            <span class="dashicons dashicons-admin-network" aria-hidden="true"></span> AI Integration
                        </a>
                        <a class="sm-nav-item" href="<?php echo esc_url( admin_url( 'tools.php?page=syndamorph&tab=gateway' ) ); ?>">
                            <span class="dashicons dashicons-shield" aria-hidden="true"></span> Client Access Manager
                        </a>
                    </nav>
                </div>
                <div class="sm-sidebar-footer">
                    <strong>Internal MVP</strong><br>
                    AI credentials and provider checks are managed from this WordPress installation.
                </div>
            </aside>
            <main class="sm-main">
                <div class="sm-topline">
                    <span class="sm-breadcrumb">Workspace / <strong>AI Integration</strong></span>
                    <span class="sm-environment">MVP · DEVELOPMENT</span>
                </div>
                <p class="sm-kicker">Integration setup</p>
                <h1 class="sm-page-title">Connect your AI providers</h1>
                <p class="sm-page-description">
                    Configure provider credentials and verify connectivity. A successful connection check
                    does not yet enable the autonomous production pipeline.
                </p>
                <div class="sm-stats" aria-label="Provider summary">
                    <div class="sm-stat">
                        <span class="sm-stat-label">Available providers</span>
                        <span class="sm-stat-value" id="syndamorph-ai-provider-count"><?php echo esc_html( str_pad( (string) count( syndamorph_ai_providers() ), 2, '0', STR_PAD_LEFT ) ); ?></span>
                    </div>
                    <div class="sm-stat">
                        <span class="sm-stat-label">Keys configured</span>
                        <span class="sm-stat-value" id="syndamorph-ai-configured">—</span>
                    </div>
                    <div class="sm-stat">
                        <span class="sm-stat-label">Last connection test</span>
                        <span class="sm-stat-value sm-stat-small" id="syndamorph-ai-last-test">Not tested</span>
                    </div>
                </div>
                <section class="sm-card" aria-labelledby="sm-model-config-title" style="margin-bottom:24px">
                    <div class="sm-card-header">
                        <h2 class="sm-card-title" id="sm-model-config-title">AI model configuration</h2>
                        <p class="sm-card-caption">Discover models from your connected provider. Set a model and verified USD token rates for cost estimates; model discovery does not include pricing.</p>
                    </div>
                    <div class="sm-card-body" style="display:grid;gap:16px">
                        <div class="sm-field">
                            <label class="sm-field-label" for="syndamorph-model-choice">Available models</label>
                            <select class="sm-control" id="syndamorph-model-choice"><option value="">Load available models first</option></select>
                            <p class="sm-hint" id="syndamorph-model-status">Anthropic catalog available after saving a provider key. Other provider adapters are pending.</p>
                            <div><button type="button" class="sm-secondary" id="syndamorph-model-refresh">Refresh provider models</button></div>
                        </div>
                        <div class="sm-stats" style="margin:0">
                            <div class="sm-stat sm-field">
                                <label class="sm-field-label" for="syndamorph-model-input-price">Input USD / 1M tokens</label>
                                <input class="sm-control" id="syndamorph-model-input-price" type="number" min="0" max="10000" step="0.01" placeholder="Provider rate">
                            </div>
                            <div class="sm-stat sm-field">
                                <label class="sm-field-label" for="syndamorph-model-output-price">Output USD / 1M tokens</label>
                                <input class="sm-control" id="syndamorph-model-output-price" type="number" min="0" max="10000" step="0.01" placeholder="Provider rate">
                            </div>
                            <div class="sm-stat">
                                <span class="sm-stat-label">Illustrative POC estimate</span>
                                <strong class="sm-stat-value" id="syndamorph-model-cost">Unknown</strong>
                                <span class="sm-hint">Assumes 6K input + 4K output tokens</span>
                            </div>
                        </div>
                        <p class="sm-hint">Rates are administrator-entered, not provider-verified. Real usage is measured; computed cost is an estimate and excludes special cache or billing adjustments.</p>
                        <div><button type="button" class="sm-primary" id="syndamorph-model-save">Save model and pricing</button></div>
                        <p id="syndamorph-model-save-status" role="status" aria-live="polite"></p>
                    </div>
                </section>
                <div class="sm-workspace">
                    <section class="sm-card" aria-labelledby="sm-connection-title">
                        <div class="sm-card-header">
                            <h2 class="sm-card-title" id="sm-connection-title">Provider configuration</h2>
                            <p class="sm-card-caption">Select a provider, enter a credential, and run a connectivity check.</p>
                        </div>
                        <div class="sm-card-body">
                            <div class="sm-field">
                                <label for="syndamorph-ai-provider" class="sm-field-label">AI provider</label>
                                <select class="sm-control" id="syndamorph-ai-provider">
                                    <?php foreach ( syndamorph_ai_providers() as $id => $name ) : ?>
                                        <option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="sm-hint">Choose the API service you want to verify.</p>
                            </div>
                            <div class="sm-field">
                                <label for="syndamorph-ai-key" class="sm-field-label">Secret API key</label>
                                <div class="sm-key-row">
                                    <input class="sm-control sm-masked-key" type="text" id="syndamorph-ai-key" name="syndamorph-provider-token" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" aria-describedby="syndamorph-ai-key-hint" placeholder="Paste a new API key">
                                    <button type="button" class="sm-icon-button" id="syndamorph-ai-toggle-key" aria-label="Show API key" aria-pressed="false">
                                        <span class="dashicons dashicons-visibility" aria-hidden="true"></span>
                                    </button>
                                </div>
                                <p class="sm-hint" id="syndamorph-ai-key-hint">Leave empty to test with a previously saved key. New keys are stored server-side.</p>
                                <?php if ( syndamorph_ai_mock_enabled() ) : ?>
                                <p class="sm-hint" id="syndamorph-ai-mock-hint" hidden>Use the synthetic test key provided for the acceptance test. It is saved using the real encrypted credential store and never sent externally.</p>
                                <?php endif; ?>
                                <div class="sm-saved-key">
                                    <div class="sm-key-status" id="syndamorph-ai-preview" role="status" aria-live="polite">Checking saved credentials…</div>
                                    <button type="button" class="sm-icon-button sm-delete-key" id="syndamorph-ai-delete-key" aria-label="Delete saved API key" title="Delete saved credential" hidden>
                                        <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="sm-card-actions">
                            <span class="sm-privacy"><span class="dashicons dashicons-lock" aria-hidden="true"></span> Credentials never appear in output</span>
                            <button type="button" class="sm-primary" id="syndamorph-ai-test">
                                <span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
                                <span id="syndamorph-ai-test-label">Run connection test</span>
                            </button>
                        </div>
                    </section>
                    <section class="sm-card" aria-labelledby="sm-output-title">
                        <div class="sm-card-header sm-console-header">
                            <div>
                                <h2 class="sm-card-title" id="sm-output-title">Connection diagnostics</h2>
                                <p class="sm-card-caption">Verbose response · sanitized</p>
                            </div>
                            <span class="sm-status-pill" id="syndamorph-ai-state" data-state="idle">Idle</span>
                        </div>
                        <div class="sm-card-body">
                            <div class="sm-console-toolbar">
                                <span class="sm-console-dots" aria-hidden="true"><i></i><i></i><i></i></span>
                                <span>test-output.json</span>
                                <button type="button" class="sm-console-copy" id="syndamorph-ai-copy">Copy log</button>
                            </div>
                            <pre class="sm-console" id="syndamorph-ai-output" role="status" aria-live="polite">[ready] Select a provider and run a connection test.
[secure] API secrets are omitted from diagnostics.</pre>
                            <div class="sm-console-note">
                                <span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
                                <span>Connection tests validate API access only. Model capability, billing limits, and conversion fidelity are separate checks.</span>
                            </div>
                        </div>
                    </section>
                </div>
            </main>
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
    register_rest_route( 'syndamorph/v1', '/ai/keys/(?P<provider>[a-z]+)', array(
        'methods' => 'DELETE',
        'permission_callback' => $permission,
        'callback' => static function ( WP_REST_Request $request ) {
            $provider = (string) $request->get_param( 'provider' );
            if ( ! array_key_exists( $provider, syndamorph_ai_providers() ) ) {
                return new WP_Error( 'syndamorph_ai_provider', 'Unsupported provider.', array( 'status' => 400 ) );
            }
            // Remove the actual encrypted option, not merely the UI representation.
            delete_option( 'syndamorph_ai_key_' . $provider );
            return rest_ensure_response( array(
                'provider' => $provider,
                'key' => syndamorph_ai_key_status( $provider ),
                'deleted' => ! get_option( 'syndamorph_ai_key_' . $provider, false ),
            ) );
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
            if ( 'mock' === $provider ) {
                // Use the decrypted saved key; only the provider operation is simulated.
                return rest_ensure_response( syndamorph_ai_mock_test( $key ) );
            }
            $started = microtime( true );
            $request_args = array( 'timeout' => 20, 'redirection' => 0, 'headers' => array(), 'body' => '' );
            if ( 'anthropic' === $provider ) {
                $url = 'https://api.anthropic.com/v1/models';
                $request_args['method'] = 'GET';
                $request_args['headers'] = array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' );
                unset( $request_args['body'] );
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
    wp_enqueue_style( 'syndamorph-operations', plugin_dir_url( __DIR__ ) . 'assets/operations-dashboard.css', array(), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/operations-dashboard.css' ) );
    wp_enqueue_script( 'syndamorph-ai-tab', plugin_dir_url( __DIR__ ) . 'assets/ai-integration.js', array(), (string) filemtime( plugin_dir_path( __DIR__ ) . 'assets/ai-integration.js' ), true );
    wp_add_inline_script( 'syndamorph-ai-tab', 'window.SyndaMorphAI=' . wp_json_encode( array(
        'nonce' => wp_create_nonce( 'wp_rest' ),
        'statusUrl' => rest_url( 'syndamorph/v1/ai/providers' ),
        'testUrl' => rest_url( 'syndamorph/v1/ai/test' ),
        'deleteBaseUrl' => rest_url( 'syndamorph/v1/ai/keys/' ),
        'modelsUrl' => rest_url( 'syndamorph/v1/ai/models' ),
        'modelSettingsUrl' => rest_url( 'syndamorph/v1/ai/models/settings' ),
    ) ) . ';', 'before' );
} );
