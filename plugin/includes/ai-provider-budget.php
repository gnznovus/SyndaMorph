<?php
/**
 * Optional provider billing snapshot. Never conflate a production API key,
 * per-job cap, organization spend limit, and prepaid credit balance.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_provider_budget_unavailable( string $provider, string $reason ): array {
    return array(
        'provider' => $provider,
        'available' => false,
        'limitUsd' => null,
        'spentUsd' => null,
        'remainingUsd' => null,
        'currency' => 'USD',
        'period' => null,
        'remainingType' => null,
        'message' => $reason,
    );
}

function syndamorph_provider_budget_request( string $url, string $key, string $beta ) {
    $response = wp_remote_get( $url, array(
        'timeout' => 14,
        'redirection' => 0,
        'limit_response_size' => 262144,
        'headers' => array(
            'x-api-key' => $key,
            'anthropic-version' => '2026-10-21',
            'anthropic-beta' => $beta,
            'Accept' => 'application/json',
        ),
    ) );
    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) { return null; }
    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    return is_array( $data ) ? $data : null;
}

function syndamorph_provider_budget_snapshot( string $provider ): array {
    if ( 'anthropic' !== $provider ) {
        return syndamorph_provider_budget_unavailable( $provider, 'Account budget lookup is not available for this provider.' );
    }
    // An optional scoped billing credential may differ from the model key.
    // Both remain exclusively server-side. A normal production key can be tried
    // but cannot be assumed to have organization analytics permissions.
    $key = defined( 'SYNDAMORPH_ANTHROPIC_BILLING_KEY' ) &&
        is_string( SYNDAMORPH_ANTHROPIC_BILLING_KEY ) &&
        '' !== SYNDAMORPH_ANTHROPIC_BILLING_KEY
        ? SYNDAMORPH_ANTHROPIC_BILLING_KEY : syndamorph_ai_saved_key( 'anthropic' );
    if ( '' === $key ) {
        return syndamorph_provider_budget_unavailable( $provider, 'No Claude credential is configured.' );
    }
    $limits = syndamorph_provider_budget_request(
        'https://api.anthropic.com/v1/organizations/spend_limits?limit=100',
        $key,
        'spend-limit-reads-2026-09-26'
    );
    if ( ! is_array( $limits['data'] ?? null ) ) {
        return syndamorph_provider_budget_unavailable( $provider,
            'Claude spend limits are unavailable to this API credential. Billing access may require a scoped key.' );
    }
    $matching = null;
    // A configured organization limit takes precedence over the usage-tier cap.
    // Never sum different scopes or mix monthly and workspace limits.
    foreach ( array( 'organization', 'usage_tier' ) as $scope ) {
        foreach ( $limits['data'] as $limit ) {
            if ( ! is_array( $limit ) || ( $limit['scope']['type'] ?? '' ) !== $scope ||
                ( $limit['period'] ?? '' ) !== 'monthly' || ! is_numeric( $limit['amount'] ?? null ) ||
                ( $limit['currency'] ?? '' ) !== 'USD' ) { continue; }
            $matching = $limit;
            break 2;
        }
    }
    if ( null === $matching ) {
        return syndamorph_provider_budget_unavailable( $provider,
            'Claude did not return a readable monthly organization spending limit.' );
    }
    $limitUsd = round( (float) $matching['amount'] / 100, 6 );
    $snapshot = array(
        'provider' => $provider,
        'available' => true,
        'limitUsd' => $limitUsd,
        'spentUsd' => null,
        'remainingUsd' => null,
        'currency' => 'USD',
        'period' => 'monthly',
        'remainingType' => null,
        'scope' => (string) $matching['scope']['type'],
        'message' => 'Monthly Claude spending limit. Remaining allowance requires an authorized cost report.',
    );
    $start = gmdate( 'Y-m-01\\T00:00:00\\Z' );
    $end = gmdate( 'Y-m-d\\TH:i:s\\Z' );
    $cost = syndamorph_provider_budget_request(
        add_query_arg( array( 'starting_at' => $start, 'ending_at' => $end, 'limit' => 1000 ),
            'https://api.anthropic.com/v1/organizations/analytics/cost' ),
        $key,
        'analytics-usage-cost-2026-08-24'
    );
    if ( ! is_array( $cost['data'] ?? null ) || ! empty( $cost['next_page'] ) ) { return $snapshot; }
    $spentMinor = 0.0;
    foreach ( $cost['data'] as $row ) {
        $amount = $row['cost']['amount'] ?? null;
        if ( ! is_array( $row ) || ! is_numeric( $amount ) || ( $row['cost']['currency'] ?? '' ) !== 'USD' ) {
            return $snapshot;
        }
        $spentMinor += (float) $amount;
    }
    // Provider analytics reports decimal amounts in minor currency units.
    $snapshot['spentUsd'] = round( $spentMinor / 100, 6 );
    $snapshot['remainingUsd'] = max( 0, round( $limitUsd - $snapshot['spentUsd'], 6 ) );
    $snapshot['remainingType'] = 'calculated_allowance';
    $snapshot['message'] = 'Calculated monthly spending allowance, not prepaid credit balance.';
    return $snapshot;
}

add_action( 'rest_api_init', static function (): void {
    register_rest_route( 'syndamorph/v1', '/ai/provider-budget', array(
        'methods' => 'GET',
        'permission_callback' => static function (): bool { return current_user_can( 'manage_options' ); },
        'callback' => static function ( WP_REST_Request $request ) {
            $provider = sanitize_key( (string) $request->get_param( 'provider' ) );
            if ( ! array_key_exists( $provider, syndamorph_ai_providers() ) ) {
                return new WP_Error( 'sm_budget_provider', 'Unknown provider.', array( 'status' => 400 ) );
            }
            // Cache only verified read-only snapshots; no credentials enter the cache.
            $fingerprint = 'anthropic' === $provider
                ? ( defined( 'SYNDAMORPH_ANTHROPIC_BILLING_KEY' ) && is_string( SYNDAMORPH_ANTHROPIC_BILLING_KEY )
                    ? SYNDAMORPH_ANTHROPIC_BILLING_KEY : syndamorph_ai_saved_key( 'anthropic' ) )
                : '';
            $cache = 'sm_provider_budget_' . md5( $provider . '|' . $fingerprint );
            $saved = get_transient( $cache );
            if ( is_array( $saved ) ) { return rest_ensure_response( $saved ); }
            $snapshot = syndamorph_provider_budget_snapshot( $provider );
            set_transient( $cache, $snapshot, $snapshot['available'] ? 300 : 60 );
            return rest_ensure_response( $snapshot );
        },
    ) );
} );
