<?php
/**
 * SyndaMorph's first-party GitHub Releases updater.
 *
 * Stable, published releases with a syndamorph.zip asset are the only
 * eligible update sources. No executable code is fetched from the API.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const SYNDAMORPH_RELEASE_API = 'https://api.github.com/repos/gnznovus/SyndaMorph/releases/latest';
const SYNDAMORPH_UPDATE_URI = 'https://github.com/gnznovus/SyndaMorph';
const SYNDAMORPH_RELEASE_ASSET = 'syndamorph.zip';
const SYNDAMORPH_RELEASE_CACHE = 'syndamorph_github_release_v2';

/** Parse untrusted API data without warnings or falling back to source archives. */
function syndamorph_parse_release( $release ): array {
    if ( ! is_array( $release ) || false !== ( $release['draft'] ?? null ) ||
        false !== ( $release['prerelease'] ?? null ) ||
        ! is_string( $release['published_at'] ?? null ) || '' === $release['published_at'] ||
        ! is_array( $release['assets'] ?? null ) ) {
        return array();
    }
    $tag = $release['tag_name'] ?? null;
    if ( ! is_string( $tag ) || ! preg_match( '/^v?((?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*))$/D', $tag, $match ) ) {
        return array();
    }
    $package = SYNDAMORPH_UPDATE_URI . '/releases/download/' . rawurlencode( $tag ) . '/' . SYNDAMORPH_RELEASE_ASSET;
    foreach ( $release['assets'] as $asset ) {
        if ( ! is_array( $asset ) || ( $asset['name'] ?? null ) !== SYNDAMORPH_RELEASE_ASSET ||
            ( $asset['state'] ?? null ) !== 'uploaded' || ! is_int( $asset['size'] ?? null ) || $asset['size'] <= 0 ||
            ( $asset['browser_download_url'] ?? null ) !== $package ) {
            continue;
        }
        return array(
            'version' => $match[1],
            'package' => $package,
            'url' => SYNDAMORPH_UPDATE_URI . '/releases/tag/' . rawurlencode( $tag ),
            'notes' => is_string( $release['body'] ?? null ) ? $release['body'] : '',
        );
    }
    return array();
}

function syndamorph_release_metadata(): array {
    $cached = get_transient( SYNDAMORPH_RELEASE_CACHE );
    if ( is_array( $cached ) ) { return $cached; }

    $response = wp_remote_get( SYNDAMORPH_RELEASE_API, array(
        'timeout' => 6,
        'redirection' => 2,
        'limit_response_size' => 1024 * 1024,
        'headers' => array(
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'SyndaMorph-WordPress-Updater',
            'X-GitHub-Api-Version' => '2022-11-28',
        ),
    ) );
    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        set_transient( SYNDAMORPH_RELEASE_CACHE, array(), 15 * MINUTE_IN_SECONDS );
        return array();
    }
    $metadata = syndamorph_parse_release( json_decode( wp_remote_retrieve_body( $response ), true ) );
    // A release may briefly exist before its workflow attaches the ZIP.
    set_transient( SYNDAMORPH_RELEASE_CACHE, $metadata, $metadata ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );
    return $metadata;
}

add_filter( 'update_plugins_github.com', static function ( $update, $plugin_data, $plugin_file ) {
    if ( false !== $update || 'syndamorph/syndamorph.php' !== $plugin_file ||
        SYNDAMORPH_UPDATE_URI !== ( $plugin_data['UpdateURI'] ?? '' ) ) {
        return $update;
    }
    $release = syndamorph_release_metadata();
    if ( ! $release ) { return $update; }
    // Core compares version and populates response/no_update (including the
    // native auto-update UI). Never offer an equal/older ZIP for installation.
    $newer = version_compare( $release['version'], $plugin_data['Version'], '>' );
    return array(
        'id' => SYNDAMORPH_UPDATE_URI,
        'slug' => 'syndamorph',
        'version' => $release['version'],
        'url' => $release['url'],
        'package' => $newer ? $release['package'] : '',
        'requires' => '6.5',
        'requires_php' => '8.1',
    );
}, 10, 3 );

// The native plugin information dialog displays the release's change notes.
add_filter( 'plugins_api', static function ( $result, $action, $args ) {
    if ( 'plugin_information' !== $action || ! is_object( $args ) ||
        'syndamorph' !== ( $args->slug ?? '' ) ) {
        return $result;
    }
    $release = syndamorph_release_metadata();
    if ( ! $release ) { return $result; }
    return (object) array(
        'name' => 'SyndaMorph',
        'slug' => 'syndamorph',
        'version' => $release['version'],
        'author' => 'Syndacast',
        'homepage' => 'https://github.com/gnznovus/SyndaMorph',
        'download_link' => $release['package'],
        'requires' => '6.5',
        'requires_php' => '8.1',
        'sections' => array(
            'description' => '<p>Convert static HTML into editable native Gutenberg blocks.</p>',
            'changelog' => '<pre style="white-space:pre-wrap">' . esc_html( $release['notes'] ?: 'See the CHANGELOG.md inside the release package.' ) . '</pre>',
        ),
    );
}, 10, 3 );
