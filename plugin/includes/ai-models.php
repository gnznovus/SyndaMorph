<?php
/**
 * Provider-neutral model catalog and execution adapter.
 * Only Anthropic is implemented in this POC; new providers implement these
 * functions without changing job orchestration.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Published standard Claude API token rates, USD per 1M tokens.
 * This is a maintained pricing snapshot, NOT the dynamic model catalog.
 * Source: https://platform.claude.com/docs/en/about-claude/pricing
 * Verified: 2026-11-24. Anthropic does not include tariffs in GET /v1/models.
 * Haiku 5.5 has a higher rate when a request exceeds 100K prompt tokens.
 */
function syndamorph_anthropic_pricing( string $model_id ): ?array {
    $rates = array(
        'claude-fable-5-2' => array( 8.0, 40.0 ),
        'claude-haiku-5-5' => array( 0.1, 0.5 ),
        'claude-sonnet-5-5' => array( 2.0, 10.0 ),
        'claude-opus-5-5' => array( 4.0, 20.0 ),
        'claude-fable-5-1' => array( 10.0, 50.0 ),
        'claude-opus-5' => array( 5.0, 25.0 ),
        'claude-sonnet-5' => array( 2.0, 10.0 ),
        'claude-fable-5' => array( 10.0, 50.0 ),
        'claude-opus-4-8' => array( 5.0, 25.0 ),
        'claude-opus-4-7' => array( 5.0, 25.0 ),
        'claude-sonnet-4-6' => array( 3.0, 15.0 ),
        'claude-opus-4-6' => array( 5.0, 25.0 ),
        'claude-opus-4-5-20251101' => array( 5.0, 25.0 ),
        'claude-haiku-4-5-20251001' => array( 1.0, 5.0 ),
    );
    if ( ! isset( $rates[ $model_id ] ) ) { return null; }
    return array(
        'input' => $rates[ $model_id ][0],
        'output' => $rates[ $model_id ][1],
        'source' => 'https://platform.claude.com/docs/en/about-claude/pricing',
        'verifiedAt' => '2026-11-24',
        'note' => 'claude-haiku-5-5' === $model_id
            ? 'Standard rates apply only up to 100K prompt tokens; over 100K: $0.50 input / $2.50 output per 1M.'
            : '',
    );
}

function syndamorph_models_with_pricing( array $catalog ): array {
    if ( 'anthropic' === ( $catalog['provider'] ?? '' ) && is_array( $catalog['models'] ?? null ) ) {
        foreach ( $catalog['models'] as &$entry ) {
            if ( is_array( $entry ) && is_string( $entry['id'] ?? null ) ) {
                $entry['pricing'] = syndamorph_anthropic_pricing( $entry['id'] );
            }
        }
        unset( $entry );
    }
    return $catalog;
}

function syndamorph_models_catalog( string $provider, bool $force = false ) {
    if ( 'anthropic' !== $provider ) {
        return new WP_Error( 'sm_model_unsupported', 'Model discovery is not implemented for this provider.', array( 'status' => 501 ) );
    }
    $key = syndamorph_ai_saved_key( $provider );
    if ( '' === $key ) {
        return new WP_Error( 'sm_model_key', 'Save and test an Anthropic credential first.', array( 'status' => 400 ) );
    }
    $cache_key = 'syndamorph_ai_models_' . $provider;
    $cached = get_transient( $cache_key );
    if ( ! $force && is_array( $cached ) && ! empty( $cached['models'] ) ) { return syndamorph_models_with_pricing( $cached ); }
    $response = wp_remote_get( 'https://api.anthropic.com/v1/models?limit=100', array(
        'timeout' => 18, 'redirection' => 0,
        'headers' => array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'Accept' => 'application/json' ),
        'limit_response_size' => 262144,
    ) );
    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        $last_good = get_option( 'syndamorph_ai_catalog_last_' . $provider, array() );
        if ( is_array( $last_good ) && ! empty( $last_good['models'] ) ) {
            $last_good['stale'] = true;
            return syndamorph_models_with_pricing( $last_good );
        }
        return new WP_Error( 'sm_model_fetch', 'Could not retrieve the model catalog from Anthropic.', array( 'status' => 502 ) );
    }
    $json = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $json ) || ! is_array( $json['data'] ?? null ) ) {
        return new WP_Error( 'sm_model_invalid', 'Provider returned an invalid model catalog.', array( 'status' => 502 ) );
    }
    $models = array();
    foreach ( $json['data'] as $model ) {
        if ( ! is_array( $model ) || ! is_string( $model['id'] ?? null ) ||
             ! preg_match( '/^[a-z0-9][a-z0-9._-]{1,99}$/iD', $model['id'] ) ||
             ! is_string( $model['display_name'] ?? null ) ) { continue; }
        $models[] = array(
            'id' => $model['id'], 'name' => sanitize_text_field( $model['display_name'] ),
            'pricing' => syndamorph_anthropic_pricing( $model['id'] ),
        );
    }
    if ( ! $models ) {
        return new WP_Error( 'sm_model_empty', 'The provider returned no usable models.', array( 'status' => 502 ) );
    }
    // Anthropic paginates; don't misrepresent an incomplete response as complete.
    $catalog = array( 'provider' => $provider, 'models' => $models,
        'stale' => false, 'has_more' => ! empty( $json['has_more'] ), 'checked_at' => time() );
    set_transient( $cache_key, $catalog, 15 * MINUTE_IN_SECONDS );
    update_option( 'syndamorph_ai_catalog_last_' . $provider, $catalog, false );
    return $catalog;
}

function syndamorph_models_settings(): array {
    $settings = get_option( 'syndamorph_ai_model_settings', array() );
    return is_array( $settings ) ? $settings : array();
}

/**
 * Stream Anthropic SSE into a bounded server-side accumulator. WordPress' REST
 * endpoint still returns one JSON result when the four-agent job completes.
 * No model-generated text is ever streamed directly to an executable page.
 */
function syndamorph_anthropic_sse_request( string $key, array $payload, ?callable $on_delta = null ) {
    if ( ! function_exists( 'curl_init' ) ) {
        return new WP_Error( 'sm_stream_unavailable', 'PHP cURL is required for the streaming baseline.', array( 'status' => 503 ) );
    }
    $handle = curl_init( 'https://api.anthropic.com/v1/messages' );
    if ( false === $handle ) {
        return new WP_Error( 'sm_generation_transport', 'Could not initialize Anthropic streaming transport.', array( 'status' => 502 ) );
    }
    $text = '';
    $pending = '';
    $stop_reason = null;
    $model = (string) ( $payload['model'] ?? '' );
    $usage = array( 'inputTokens' => 0, 'outputTokens' => 0, 'cacheReadTokens' => 0, 'cacheWriteTokens' => 0 );
    $saw_start = false;
    $saw_stop = false;
    $overflow = false;
    $provider_error = false;
    $event_count = 0;
    $process = static function ( string $frame ) use ( &$text, &$stop_reason, &$model, &$usage, &$saw_start, &$saw_stop, &$provider_error, &$event_count, $on_delta ): void {
        $lines = preg_split( '/\\r?\\n/', $frame );
        $data_lines = array();
        foreach ( $lines as $line ) {
            if ( str_starts_with( $line, 'data:' ) ) {
                $data_lines[] = ltrim( substr( $line, 5 ), ' ' );
            }
        }
        if ( ! $data_lines ) { return; }
        $event = json_decode( implode( "\n", $data_lines ), true );
        if ( ! is_array( $event ) ) { return; }
        $event_count++;
        switch ( $event['type'] ?? '' ) {
            case 'message_start':
                $saw_start = true;
                $message = $event['message'] ?? array();
                if ( is_array( $message ) ) {
                    $model = (string) ( $message['model'] ?? $model );
                    $initial = $message['usage'] ?? array();
                    if ( is_array( $initial ) ) {
                        $usage['inputTokens'] = max( 0, (int) ( $initial['input_tokens'] ?? 0 ) );
                        $usage['cacheReadTokens'] = max( 0, (int) ( $initial['cache_read_input_tokens'] ?? 0 ) );
                        $usage['cacheWriteTokens'] = max( 0, (int) ( $initial['cache_creation_input_tokens'] ?? 0 ) );
                    }
                }
                break;
            case 'content_block_delta':
                $delta = $event['delta'] ?? array();
                if ( is_array( $delta ) && 'text_delta' === ( $delta['type'] ?? '' ) && is_string( $delta['text'] ?? null ) ) {
                    $text .= $delta['text'];
                    if ( $on_delta ) { $on_delta( $delta['text'] ); }
                }
                break;
            case 'message_delta':
                $delta = $event['delta'] ?? array();
                if ( is_array( $delta ) && is_string( $delta['stop_reason'] ?? null ) ) {
                    $stop_reason = $delta['stop_reason'];
                }
                $delta_usage = $event['usage'] ?? array();
                if ( is_array( $delta_usage ) ) {
                    $usage['outputTokens'] = max( $usage['outputTokens'], (int) ( $delta_usage['output_tokens'] ?? 0 ) );
                }
                break;
            case 'message_stop':
                $saw_stop = true;
                break;
            case 'error':
                $provider_error = true;
                break;
        }
    };
    $write = static function ( $curl, string $chunk ) use ( &$pending, &$text, &$overflow, &$event_count, $process ): int {
        $length = strlen( $chunk );
        $pending .= str_replace( "\r\n", "\n", $chunk );
        if ( strlen( $pending ) > 65536 || strlen( $text ) > 120000 || $event_count > 100000 ) {
            $overflow = true;
            return 0;
        }
        while ( false !== ( $position = strpos( $pending, "\n\n" ) ) ) {
            $frame = substr( $pending, 0, $position );
            $pending = substr( $pending, $position + 2 );
            $process( $frame );
            if ( strlen( $text ) > 120000 ) {
                $overflow = true;
                return 0;
            }
        }
        return $length;
    };
    $configured = curl_setopt_array( $handle, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => wp_json_encode( $payload ),
        CURLOPT_HTTPHEADER => array(
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
            'accept: text/event-stream',
        ),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 240,
        // RETURNTRANSFER must be established before the custom writer.
        // Otherwise cURL may reset the writer and echo raw SSE into REST JSON.
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_WRITEFUNCTION => $write,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ) );
    if ( ! $configured ) {
        curl_close( $handle );
        return new WP_Error( 'sm_stream_unavailable', 'Could not configure PHP streaming transport.', array( 'status' => 503 ) );
    }
    // The provider stream is consumed only by the custom cURL writer.
    // For JSON calls, absorb accidental transport output. For SSE calls,
    // allow only explicitly emitted, sanitized progress events through.
    if ( ! $on_delta ) { ob_start(); }
    try {
        $ok = curl_exec( $handle );
    } finally {
        if ( ! $on_delta ) { ob_end_clean(); }
    }
    $http = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE );
    curl_close( $handle );
    if ( 200 !== $http ) {
        $reason = match ( $http ) {
            400 => 'Anthropic rejected the request parameters or selected model.',
            401 => 'Anthropic rejected the API credential.',
            403 => 'Anthropic denied access to the selected model.',
            404 => 'Anthropic did not recognize the requested model.',
            429 => 'Anthropic rate limit or quota was reached.',
            default => 'Anthropic returned a non-success response.',
        };
        return new WP_Error( 'sm_generation_upstream', $reason . ' (Anthropic HTTP ' . $http . ').', array( 'status' => 502 ) );
    }
    if ( $overflow ) {
        return new WP_Error( 'sm_generation_oversize', 'Streaming output exceeded the safety size bound.', array( 'status' => 502, 'outputTokens' => $usage['outputTokens'] ) );
    }
    if ( false === $ok || $provider_error || ! $saw_start || ! $saw_stop ) {
        return new WP_Error( 'sm_generation_transport', 'Anthropic streaming ended unexpectedly or timed out.', array( 'status' => 502, 'outputTokens' => $usage['outputTokens'] ) );
    }
    return array( 'model' => $model, 'text' => $text, 'stop_reason' => $stop_reason, 'usage' => $usage );
}

/** Provider-neutral generation boundary; optional server-side streaming. */
function syndamorph_models_generate( string $provider, string $model, string $system, string $input, int $max_tokens = 1200, bool $stream = false, ?callable $on_delta = null ) {
    if ( 'anthropic' !== $provider ) {
        return new WP_Error( 'sm_provider_unsupported', 'This provider has no generation adapter yet.', array( 'status' => 501 ) );
    }
    $key = syndamorph_ai_saved_key( $provider );
    if ( '' === $key ) {
        return new WP_Error( 'sm_provider_key', 'Anthropic credential is missing.', array( 'status' => 400 ) );
    }
    $payload = array(
        'model' => $model, 'max_tokens' => $max_tokens,
        'system' => $system, 'messages' => array( array( 'role' => 'user', 'content' => $input ) ),
    );
    if ( $stream ) {
        $payload['stream'] = true;
        $json = syndamorph_anthropic_sse_request( $key, $payload, $on_delta );
        if ( is_wp_error( $json ) ) { return $json; }
    } else {
        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
            'timeout' => 60, 'redirection' => 0, 'limit_response_size' => 200000,
            'headers' => array(
                'x-api-key' => $key, 'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ),
            'body' => wp_json_encode( $payload ),
        ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'sm_generation_transport', 'Anthropic connection failed or timed out. Check server connectivity and timeout logs.', array( 'status' => 502 ) );
        }
        $upstream_status = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $upstream_status ) {
            $reason = match ( $upstream_status ) {
                400 => 'Anthropic rejected the request parameters or selected model.',
                401 => 'Anthropic rejected the API credential.',
                403 => 'Anthropic denied access to the selected model.',
                404 => 'Anthropic did not recognize the requested model.',
                429 => 'Anthropic rate limit or quota was reached.',
                default => 'Anthropic returned a non-success response.',
            };
            return new WP_Error( 'sm_generation_upstream', $reason . ' (Anthropic HTTP ' . $upstream_status . ').', array( 'status' => 502 ) );
        }
        $raw = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $raw ) || ! is_array( $raw['content'] ?? null ) ) {
            return new WP_Error( 'sm_generation_invalid', 'Anthropic returned an invalid response structure.', array( 'status' => 502 ) );
        }
        $text = '';
        foreach ( $raw['content'] as $part ) {
            if ( 'text' === ( $part['type'] ?? '' ) && is_string( $part['text'] ?? null ) ) { $text .= $part['text']; }
        }
        $json = array(
            'text' => $text, 'model' => $raw['model'] ?? $model,
            'stop_reason' => $raw['stop_reason'] ?? null,
            'usage' => array(
                'inputTokens' => max( 0, (int) ( $raw['usage']['input_tokens'] ?? 0 ) ),
                'outputTokens' => max( 0, (int) ( $raw['usage']['output_tokens'] ?? 0 ) ),
                'cacheReadTokens' => max( 0, (int) ( $raw['usage']['cache_read_input_tokens'] ?? 0 ) ),
                'cacheWriteTokens' => max( 0, (int) ( $raw['usage']['cache_creation_input_tokens'] ?? 0 ) ),
            ),
        );
    }
    $stop_reason = $json['stop_reason'] ?? null;
    if ( 'end_turn' !== $stop_reason ) {
        $reasons = array(
            'max_tokens' => 'Output token limit reached before the agent finished.',
            'refusal' => 'Model declined to complete the request.',
            'tool_use' => 'Model requested tool execution, which this POC does not support.',
            'pause_turn' => 'Model requested a paused/resumed turn, unsupported by this POC.',
            'stop_sequence' => 'Model stopped at a stop sequence before completing.',
        );
        $reason = is_string( $stop_reason ) && isset( $reasons[ $stop_reason ] ) ? $stop_reason : 'unknown';
        return new WP_Error(
            'max_tokens' === $reason ? 'sm_generation_token_limit' : 'sm_generation_incomplete',
            $reasons[ $reason ] ?? 'Model stopped for an unrecognized reason.',
            array( 'status' => 502, 'stopReason' => $reason, 'outputTokens' => (int) ( $json['usage']['outputTokens'] ?? 0 ) )
        );
    }
    if ( '' === trim( (string) $json['text'] ) || strlen( $json['text'] ) > 120000 ) {
        return new WP_Error( 'sm_generation_invalid', 'Model returned invalid or oversized output.', array( 'status' => 502 ) );
    }
    return array(
        'text' => $json['text'],
        'model' => sanitize_text_field( (string) $json['model'] ),
        'usage' => $json['usage'],
    );
}

add_action( 'rest_api_init', static function (): void {
    $permission = static function (): bool { return current_user_can( 'manage_options' ); };
    register_rest_route( 'syndamorph/v1', '/ai/models', array(
        'methods' => 'GET', 'permission_callback' => $permission,
        'callback' => static function ( WP_REST_Request $request ) {
            $provider = sanitize_key( (string) $request->get_param( 'provider' ) );
            $catalog = syndamorph_models_catalog( $provider, '1' === (string) $request->get_param( 'refresh' ) );
            if ( is_wp_error( $catalog ) ) { return $catalog; }
            return rest_ensure_response( array_merge( $catalog, array( 'settings' => syndamorph_models_settings() ) ) );
        },
    ) );
    register_rest_route( 'syndamorph/v1', '/ai/models/settings', array(
        'methods' => 'POST', 'permission_callback' => $permission,
        'callback' => static function ( WP_REST_Request $request ) {
            $provider = (string) $request->get_param( 'provider' );
            $id = (string) $request->get_param( 'model' );
            $catalog = syndamorph_models_catalog( $provider );
            if ( is_wp_error( $catalog ) ) { return $catalog; }
            $ids = array_column( $catalog['models'], 'id' );
            if ( ! in_array( $id, $ids, true ) ) {
                return new WP_Error( 'sm_model_unknown', 'Select a model from the discovered catalog.', array( 'status' => 400 ) );
            }
            $budget = $request->get_param( 'maxJobUsd' );
            if ( ! is_numeric( $budget ) || (float) $budget <= 0 || (float) $budget > 100 ) {
                return new WP_Error( 'sm_model_budget', 'Enter a positive per-job budget up to USD 100.', array( 'status' => 400 ) );
            }
            // Published standard pricing is authoritative for this POC.
            // Reject missing-rate models instead of trusting browser-submitted prices.
            $rate = 'anthropic' === $provider ? syndamorph_anthropic_pricing( $id ) : null;
            if ( null === $rate ) {
                return new WP_Error( 'sm_model_price', 'Pricing is unavailable for this model; paid execution is disabled.', array( 'status' => 400 ) );
            }
            $settings = array(
                'provider' => $provider,
                'model' => $id,
                'inputPrice' => $rate['input'],
                'outputPrice' => $rate['output'],
                'maxJobUsd' => (float) $budget,
                'pricingSource' => 'anthropic-standard-reference-2026-11-24',
                'savedAt' => time(),
            );
            update_option( 'syndamorph_ai_model_settings', $settings, false );
            return rest_ensure_response( $settings );
        },
    ) );
} );
