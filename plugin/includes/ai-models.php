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

function syndamorph_models_generate( string $provider, string $model, string $system, string $input, int $max_tokens = 1200 ) {
    if ( 'anthropic' !== $provider ) {
        return new WP_Error( 'sm_provider_unsupported', 'This provider has no generation adapter yet.', array( 'status' => 501 ) );
    }
    $key = syndamorph_ai_saved_key( $provider );
    if ( '' === $key ) {
        return new WP_Error( 'sm_provider_key', 'Anthropic credential is missing.', array( 'status' => 400 ) );
    }
    $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
        'timeout' => 60, 'redirection' => 0, 'limit_response_size' => 200000,
        'headers' => array(
            'x-api-key' => $key, 'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ),
        'body' => wp_json_encode( array(
            'model' => $model, 'max_tokens' => $max_tokens,
            'system' => $system, 'messages' => array( array( 'role' => 'user', 'content' => $input ) ),
        ) ),
    ) );
    if ( is_wp_error( $response ) ) {
        // Never echo transport errors: they can include server details or input.
        return new WP_Error( 'sm_generation_transport', 'Anthropic connection failed or timed out. Check server connectivity and timeout logs.', array( 'status' => 502 ) );
    }
    $upstream_status = (int) wp_remote_retrieve_response_code( $response );
    if ( 200 !== $upstream_status ) {
        // Expose only upstream HTTP status and a fixed explanation, never response bodies.
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
    $json = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $json ) || ! is_array( $json['content'] ?? null ) ) {
        return new WP_Error( 'sm_generation_invalid', 'Anthropic returned an invalid response structure.', array( 'status' => 502 ) );
    }
    $stop_reason = $json['stop_reason'] ?? null;
    if ( 'end_turn' !== $stop_reason ) {
        // Only known stop-reason identifiers are surfaced, never model text,
        // raw provider bodies, or untrusted explanations.
        $reasons = array(
            'max_tokens' => 'Output token limit reached before the agent finished.',
            'refusal' => 'Model declined to complete the request.',
            'tool_use' => 'Model requested tool execution, which this POC does not support.',
            'pause_turn' => 'Model requested a paused/resumed turn, unsupported by this POC.',
            'stop_sequence' => 'Model stopped at a stop sequence before completing.',
        );
        $reason = is_string( $stop_reason ) && isset( $reasons[ $stop_reason ] )
            ? $stop_reason : 'unknown';
        $usage = $json['usage'] ?? array();
        $tokens = is_array( $usage ) ? max( 0, (int) ( $usage['output_tokens'] ?? 0 ) ) : 0;
        return new WP_Error(
            'max_tokens' === $reason ? 'sm_generation_token_limit' : 'sm_generation_incomplete',
            $reasons[ $reason ] ?? 'Model stopped for an unrecognized reason.',
            array( 'status' => 502, 'stopReason' => $reason, 'outputTokens' => $tokens )
        );
    }
    $text = '';
    foreach ( $json['content'] as $part ) {
        if ( 'text' === ( $part['type'] ?? '' ) && is_string( $part['text'] ?? null ) ) { $text .= $part['text']; }
    }
    if ( '' === trim( $text ) || strlen( $text ) > 180000 ) {
        return new WP_Error( 'sm_generation_invalid', 'Model returned invalid or oversized output.', array( 'status' => 502 ) );
    }
    return array(
        'text' => $text, 'model' => sanitize_text_field( (string) ( $json['model'] ?? $model ) ),
        'usage' => array(
            'inputTokens' => max( 0, (int) ( $json['usage']['input_tokens'] ?? 0 ) ),
            'outputTokens' => max( 0, (int) ( $json['usage']['output_tokens'] ?? 0 ) ),
            'cacheReadTokens' => max( 0, (int) ( $json['usage']['cache_read_input_tokens'] ?? 0 ) ),
            'cacheWriteTokens' => max( 0, (int) ( $json['usage']['cache_creation_input_tokens'] ?? 0 ) ),
        ),
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
