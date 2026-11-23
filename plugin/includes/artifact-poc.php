<?php
/**
 * Opt-in artifact independence POC.
 *
 * Publishes Core-only unsynced wp_block posts and writes immutable content
 * snapshots outside the SyndaMorph plugin directory. No AI/API dependency.
 * This is deliberately separate from the existing pattern-save endpoint.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_poc_error( string $code, string $message, int $status = 400 ): WP_Error {
    return new WP_Error( $code, $message, array( 'status' => $status ) );
}

/** The archive is site-owned, not inside plugins/syndamorph. */
function syndamorph_poc_root(): string {
    $uploads = wp_upload_dir( null, false );
    return trailingslashit( $uploads['basedir'] ) . 'syndamorph-artifacts-poc';
}

function syndamorph_poc_directory( int $id ): string {
    return trailingslashit( syndamorph_poc_root() ) . 'pattern-' . $id;
}

/** Only self-contained Core blocks are in scope for this POC. */
function syndamorph_poc_validate_content( string $content ) {
    if ( '' === trim( $content ) || strlen( $content ) > 2097152 || ! has_blocks( $content ) ) {
        return syndamorph_poc_error( 'poc_invalid_markup', 'Provide Gutenberg block content up to 2 MB.' );
    }
    // No runtime assets, image URLs, shortcode resolution or executable embeds.
    $allowed = array_fill_keys( array(
        'core/group', 'core/columns', 'core/column', 'core/paragraph', 'core/heading',
        'core/buttons', 'core/button', 'core/list', 'core/list-item', 'core/quote',
        'core/separator', 'core/spacer', 'core/pullquote', 'core/verse',
    ), true );
    $visit = static function ( array $blocks ) use ( &$visit, $allowed ): bool {
        foreach ( $blocks as $block ) {
            $name = $block['blockName'] ?? null;
            if ( ! $name ) {
                // Reject freeform/raw HTML outside named Gutenberg blocks.
                if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) { return false; }
                continue;
            }
            if ( ! isset( $allowed[ $name ] ) ) { return false; }
            if ( ! $visit( $block['innerBlocks'] ?? array() ) ) { return false; }
        }
        return true;
    };
    if ( ! $visit( parse_blocks( $content ) ) ) {
        return syndamorph_poc_error( 'poc_unsupported_blocks',
            'POC supports only approved WordPress Core text/layout blocks (no image, HTML, shortcode or custom blocks).' );
    }
    if ( preg_match( '~<\s*(?:script|iframe|object|embed|link|style|form)\b|\bon\w+\s*=|\b(?:javascript|data):|/syndamorph/v1/|/plugins/syndamorph/~i', $content ) ) {
        return syndamorph_poc_error( 'poc_runtime_dependency', 'Unsafe or plugin-dependent content is not allowed in this POC.' );
    }
    return true;
}

/** Write immutable version files; never overwrite an existing version. */
function syndamorph_poc_archive( int $id, string $content, string $title ) {
    $dir = syndamorph_poc_directory( $id );
    $versions = $dir . '/versions';
    if ( ! wp_mkdir_p( $versions ) ) {
        return syndamorph_poc_error( 'poc_storage', 'Cannot create local version archive.', 500 );
    }
    // Files are snapshots, not a runtime dependency. Prevent casual HTTP access
    // on Apache/IIS; nginx deployments must deny the directory separately.
    $root = syndamorph_poc_root();
    foreach ( array( $root, $dir, $versions ) as $folder ) {
        $guards = array(
            'index.php' => '<?php // Local archive. No direct execution.',
            '.htaccess' => "Require all denied\nDeny from all\n",
            'web.config' => '<?xml version="1.0"?><configuration><system.webServer><security><requestFiltering><hiddenSegments><add segment="syndamorph-artifacts-poc"/></hiddenSegments></requestFiltering></security></system.webServer></configuration>',
        );
        foreach ( $guards as $name => $body ) {
            $target = $folder . '/' . $name;
            if ( ! is_file( $target ) && false === @file_put_contents( $target, $body, LOCK_EX ) ) {
                return syndamorph_poc_error( 'poc_storage', 'Cannot protect local archive directory.', 500 );
            }
        }
    }
    $lock = @fopen( $dir . '/.archive.lock', 'c' );
    if ( ! $lock || ! flock( $lock, LOCK_EX ) ) {
        if ( $lock ) { fclose( $lock ); }
        return syndamorph_poc_error( 'poc_storage', 'Cannot lock the local archive.', 500 );
    }
    try {
        $entries = glob( $versions . '/v[0-9][0-9][0-9][0-9][0-9][0-9]' ) ?: array();
        $next = 1;
        foreach ( $entries as $entry ) {
            $next = max( $next, (int) substr( basename( $entry ), 1 ) + 1 );
        }
        $version = sprintf( 'v%06d', $next );
        $destination = $versions . '/' . $version;
        if ( file_exists( $destination ) || ! mkdir( $destination, 0750 ) ) {
            return syndamorph_poc_error( 'poc_storage', 'Cannot reserve immutable version directory.', 500 );
        }
        $manifest = array(
            'schema' => 1,
            'pattern_id' => $id,
            'version' => $version,
            'title' => $title,
            'created_utc' => gmdate( 'c' ),
            'content_sha256' => hash( 'sha256', $content ),
            'runtime' => 'wordpress-core',
            'plugin_required' => false,
            'assets' => array(),
        );
        $body_file = $destination . '/pattern.blocks.txt';
        $manifest_file = $destination . '/manifest.json';
        $bytes = @file_put_contents( $body_file, $content, LOCK_EX );
        $json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        $written = $json ? @file_put_contents( $manifest_file, $json, LOCK_EX ) : false;
        if ( $bytes !== strlen( $content ) || $written !== strlen( $json ) ) {
            @unlink( $body_file ); @unlink( $manifest_file ); @rmdir( $destination );
            return syndamorph_poc_error( 'poc_storage', 'Failed to write complete version files.', 500 );
        }
        return $manifest;
    } finally {
        flock( $lock, LOCK_UN );
        fclose( $lock );
    }
}

function syndamorph_poc_versions( int $id ): array {
    $result = array();
    foreach ( glob( syndamorph_poc_directory( $id ) . '/versions/v[0-9][0-9][0-9][0-9][0-9][0-9]/manifest.json' ) ?: array() as $file ) {
        $manifest = json_decode( (string) @file_get_contents( $file ), true );
        if ( is_array( $manifest ) && (int) ( $manifest['pattern_id'] ?? 0 ) === $id ) {
            $result[] = $manifest;
        }
    }
    usort( $result, static function ( $a, $b ): int {
        return strcmp( $a['version'], $b['version'] );
    } );
    return $result;
}

function syndamorph_poc_publish( string $title, string $content, int $id = 0 ) {
    $title = sanitize_text_field( $title );
    $valid = syndamorph_poc_validate_content( $content );
    if ( is_wp_error( $valid ) ) { return $valid; }
    if ( '' === $title ) { return syndamorph_poc_error( 'poc_title', 'Pattern title is required.' ); }
    $existing = $id ? get_post( $id ) : null;
    if ( $id && ( ! $existing || 'wp_block' !== $existing->post_type || ! get_post_meta( $id, '_syndamorph_poc_owned', true ) ) ) {
        return syndamorph_poc_error( 'poc_not_owned', 'Only POC-owned patterns can be versioned.', 404 );
    }
    if ( $id && ! current_user_can( 'edit_post', $id ) ) {
        return syndamorph_poc_error( 'poc_forbidden', 'Cannot edit this pattern.', 403 );
    }
    $post = array(
        'post_type' => 'wp_block', 'post_status' => 'publish',
        'post_title' => $title, 'post_content' => $content,
    );
    if ( $id ) { $post['ID'] = $id; }
    $saved = wp_insert_post( wp_slash( $post ), true );
    if ( is_wp_error( $saved ) ) { return $saved; }
    if ( ! $saved ) { return syndamorph_poc_error( 'poc_save', 'WordPress could not save the pattern.', 500 ); }
    update_post_meta( $saved, 'wp_pattern_sync_status', 'unsynced' );
    update_post_meta( $saved, '_syndamorph_poc_owned', 1 );
    $manifest = syndamorph_poc_archive( $saved, $content, $title );
    if ( is_wp_error( $manifest ) ) {
        if ( $existing ) {
            wp_update_post( wp_slash( array(
                'ID' => $saved, 'post_title' => $existing->post_title,
                'post_content' => $existing->post_content,
            ) ) );
        } else {
            wp_delete_post( $saved, true );
        }
        return $manifest;
    }
    return array(
        'id' => $saved, 'title' => $title, 'version' => $manifest['version'],
        'sha256' => $manifest['content_sha256'], 'archive' => syndamorph_poc_directory( $saved ),
        'wordpress_owned' => true, 'plugin_required' => false,
    );
}

add_action( 'rest_api_init', static function (): void {
    $permission = static function (): bool { return current_user_can( 'edit_pages' ); };
    register_rest_route( 'syndamorph/v1', '/artifact-poc/publish', array(
        'methods' => 'POST',
        'permission_callback' => $permission,
        'callback' => static function ( WP_REST_Request $request ) {
            $id = absint( $request->get_param( 'pattern_id' ) );
            $result = syndamorph_poc_publish(
                (string) $request->get_param( 'title' ),
                (string) $request->get_param( 'content' ),
                $id
            );
            return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 201 );
        },
    ) );
    register_rest_route( 'syndamorph/v1', '/artifact-poc/versions/(?P<id>\d+)', array(
        'methods' => 'GET', 'permission_callback' => $permission,
        'callback' => static function ( WP_REST_Request $request ) {
            $id = absint( $request['id'] );
            if ( ! current_user_can( 'edit_post', $id ) || ! get_post_meta( $id, '_syndamorph_poc_owned', true ) ) {
                return syndamorph_poc_error( 'poc_forbidden', 'Not a permitted POC pattern.', 403 );
            }
            return new WP_REST_Response( array( 'versions' => syndamorph_poc_versions( $id ) ), 200 );
        },
    ) );
    register_rest_route( 'syndamorph/v1', '/artifact-poc/restore/(?P<id>\d+)/(?P<version>v\d{6})', array(
        'methods' => 'POST', 'permission_callback' => $permission,
        'callback' => static function ( WP_REST_Request $request ) {
            $id = absint( $request['id'] );
            if ( ! current_user_can( 'edit_post', $id ) || ! get_post_meta( $id, '_syndamorph_poc_owned', true ) ) {
                return syndamorph_poc_error( 'poc_forbidden', 'Not a permitted POC pattern.', 403 );
            }
            $version = (string) $request['version'];
            $dir = syndamorph_poc_directory( $id ) . '/versions/' . $version;
            $manifest = json_decode( (string) @file_get_contents( $dir . '/manifest.json' ), true );
            $content = @file_get_contents( $dir . '/pattern.blocks.txt' );
            if ( ! is_array( $manifest ) || (int) ( $manifest['pattern_id'] ?? 0 ) !== $id ||
                ( $manifest['version'] ?? '' ) !== $version || ! is_string( $content ) ||
                ! hash_equals( (string) ( $manifest['content_sha256'] ?? '' ), hash( 'sha256', $content ) ) ) {
                return syndamorph_poc_error( 'poc_bad_archive', 'Version missing or SHA-256 integrity failed.', 409 );
            }
            $result = syndamorph_poc_publish( (string) $manifest['title'], $content, $id );
            return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
        },
    ) );
} );
