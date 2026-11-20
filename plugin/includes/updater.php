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

function syndamorph_release_metadata() {
    $cache_key = 'syndamorph_github_release_v1';
    $cached = get_transient( $cache_key );
    if ( false !== $cached ) { return $cached; }

    $response = wp_remote_get( SYNDAMORPH_RELEASE_API, array(
        'timeout' => 6,
        'redirection' => 2,
        'headers' => array(
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'SyndaMorph-WordPress-Updater',
            'X-GitHub-Api-Version' => '2022-11-28',
        ),
    ) );
    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        set_transient( $cache_key, array(), 15 * MINUTE_IN_SECONDS );
        return array();
    }
    $release = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $release ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
        set_transient( $cache_key, array(), 15 * MINUTE_IN_SECONDS );
        return array();
    }
    $tag = $release['tag_name'] ?? '';
    if ( ! is_string( $tag ) || ! preg_match( '/^v?(\\d+\\.\\d+\\.\\d+)$/', $tag, $match ) ) {
        set_transient( $cache_key, array(), 15 * MINUTE_IN_SECONDS );
        return array();
    }
    $package = '';
    foreach ( $release['assets'] ?? array() as $asset ) {
        if ( ! is_array( $asset ) || ( $asset['name'] ?? '' ) !== SYNDAMORPH_RELEASE_ASSET ) { continue; }
        $url = $asset['browser_download_url'] ?? '';
        if ( is_string( $url ) && 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) &&
            'github.com' === wp_parse_url( $url, PHP_URL_HOST ) &&
            str_starts_with( wp_parse_url( $url, PHP_URL_PATH ) ?: '', '/gnznovus/SyndaMorph/releases/download/' ) ) {
            $package = $url;
        }
        break;
    }
    if ( '' === $package ) {
        // A release may briefly exist before its workflow attaches the ZIP.
        set_transient( $cache_key, array(), 15 * MINUTE_IN_SECONDS );
        return array();
    }
    $metadata = array(
        'version' => $match[1],
        'package' => $package,
        'url' => 'https://github.com/gnznovus/SyndaMorph/releases/tag/' . rawurlencode( $tag ),
        'notes' => is_string( $release['body'] ?? null ) ? $release['body'] : '',
    );
    set_transient( $cache_key, $metadata, 6 * HOUR_IN_SECONDS );
    return $metadata;
}

add_filter( 'update_plugins_github.com', static function ( $update, $plugin_data, $plugin_file ) {
    if ( 'syndamorph/syndamorph.php' !== $plugin_file ||
        SYNDAMORPH_UPDATE_URI !== ( $plugin_data['UpdateURI'] ?? '' ) ) {
        return $update;
    }
    $release = syndamorph_release_metadata();
    if ( ! $release || ! version_compare( $release['version'], $plugin_data['Version'], '>' ) ) {
        return false;
    }
    return array(
        'id' => SYNDAMORPH_UPDATE_URI,
        'slug' => 'syndamorph',
        'version' => $release['version'],
        'url' => $release['url'],
        'package' => $release['package'],
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
        'requires_php' => '8.1',
        'sections' => array(
            'description' => '<p>Convert static HTML into editable native Gutenberg blocks.</p>',
            'changelog' => '<pre style="white-space:pre-wrap">' . esc_html( $release['notes'] ?: 'See the CHANGELOG.md inside the release package.' ) . '</pre>',
        ),
    );
}, 10, 3 );
