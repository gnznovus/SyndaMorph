<?php
/**
 * P3 ZIP package importer: scan -> ephemeral preview -> approved batch commit.
 * The ZIP stays in a private temporary folder, never under the web root.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

const SYNDAMORPH_PACKAGE_MAX = 33554432; // 32 MiB compressed.
const SYNDAMORPH_PACKAGE_UNPACKED_MAX = 134217728;
const SYNDAMORPH_PACKAGE_TTL = 3600;

function syndamorph_package_error( string $message, int $status = 400 ): WP_Error {
    return new WP_Error( 'syndamorph_package', $message, array( 'status' => $status ) );
}
function syndamorph_package_root(): string {
    return trailingslashit( get_temp_dir() ) . 'syndamorph-p3-' . md5( ABSPATH );
}
function syndamorph_package_path( string $token ): string {
    return trailingslashit( syndamorph_package_root() ) . $token . '.zip';
}
function syndamorph_package_meta_path( string $token ): string {
    return trailingslashit( syndamorph_package_root() ) . $token . '.json';
}
function syndamorph_package_cleanup(): void {
    $dir = syndamorph_package_root();
    foreach ( glob( $dir . '/*.json' ) ?: array() as $file ) {
        $data = json_decode( (string) @file_get_contents( $file ), true );
        if ( ! is_array( $data ) || ( $data['expires'] ?? 0 ) < time() ) {
            $token = basename( $file, '.json' );
            @unlink( syndamorph_package_path( $token ) );
            @unlink( $file );
        }
    }
}
function syndamorph_package_valid_entry( string $path ): bool {
    if ( '' === $path || strlen( $path ) > 300 || str_contains( $path, "\0" ) ||
        str_contains( $path, '\\' ) || str_starts_with( $path, '/' ) ||
        preg_match( '~(^|/)\.\.?(/|$)|//|^[A-Za-z]:~', $path ) ) { return false; }
    return true;
}
function syndamorph_package_allowed_file( string $path ): bool {
    return (bool) preg_match( '/\.(?:html?|css|js|png|jpe?g|webp|gif|woff2?|eot|ttf|otf)$/i', $path );
}
/** Canonical relative ZIP path. Reject traversal and absolute/network sources. */
function syndamorph_package_resolve( string $base, string $reference ): string {
    $reference = trim( html_entity_decode( $reference, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    if ( '' === $reference || preg_match( '~^(?:[a-z][a-z0-9+.-]*:|//|/)~i', $reference ) ) { return ''; }
    $raw = explode( '/', explode( '#', explode( '?', $reference, 2 )[0], 2 )[0] );
    $segments = explode( '/', dirname( $base ) );
    if ( '.' === $segments[0] ) { $segments = array(); }
    foreach ( $raw as $segment ) {
        $segment = rawurldecode( $segment );
        if ( '' === $segment || '.' === $segment ) { continue; }
        if ( '..' === $segment ) {
            if ( ! $segments ) { return ''; }
            array_pop( $segments );
        } elseif ( str_contains( $segment, '/' ) || str_contains( $segment, '\\' ) || str_contains( $segment, "\0" ) ) {
            return '';
        } else {
            $segments[] = $segment;
        }
    }
    $resolved = implode( '/', $segments );
    return syndamorph_package_valid_entry( $resolved ) ? $resolved : '';
}
function syndamorph_package_open( string $token, bool $require_owner = true ) {
    if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) { return syndamorph_package_error( 'Invalid package token.' ); }
    $meta = json_decode( (string) @file_get_contents( syndamorph_package_meta_path( $token ) ), true );
    if ( ! is_array( $meta ) || ( $require_owner && (int) ( $meta['user'] ?? 0 ) !== get_current_user_id() ) ||
        (int) ( $meta['expires'] ?? 0 ) < time() ) {
        return syndamorph_package_error( 'Package expired or unavailable. Upload the ZIP again.', 404 );
    }
    $zip = new ZipArchive();
    if ( true !== $zip->open( syndamorph_package_path( $token ), ZipArchive::RDONLY ) ) {
        return syndamorph_package_error( 'ZIP package is no longer available.', 404 );
    }
    return array( 'meta' => $meta, 'zip' => $zip );
}
function syndamorph_package_asset_signature( string $token, string $path ): string {
    return hash_hmac( 'sha256', $token . ':' . $path, wp_salt( 'auth' ) );
}
function syndamorph_package_asset_url( string $token, string $path ): string {
    $encoded = rtrim( strtr( base64_encode( $path ), '+/', '-_' ), '=' );
    return add_query_arg( 'sig', syndamorph_package_asset_signature( $token, $path ),
        rest_url( 'syndamorph/v1/package-asset/' . $token . '/' . $encoded ) );
}
function syndamorph_package_mime( string $path ): string {
    $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
    return array(
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp', 'gif' => 'image/gif',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'eot' => 'application/vnd.ms-fontobject',
        'ttf' => 'font/ttf', 'otf' => 'font/otf',
    )[ $ext ] ?? 'application/octet-stream';
}
/**
 * CSS urls are relative to their stylesheet, not the HTML page.
 * Rewrite only known image assets; preserve non-image CSS declarations.
 */
function syndamorph_package_css_urls(
    string $css, string $css_path, array $meta, string $token, array &$missing
): string {
    return preg_replace_callback(
        '~url\(\s*(["\x27]?)(.*?)\1\s*\)~i',
        static function ( array $matches ) use ( $css_path, $meta, $token, &$missing ): string {
            $reference = trim( $matches[2] );
            if ( '' === $reference || preg_match( '~^(?:data:|https?://|//|#)~i', $reference ) ) {
                return $matches[0];
            }
            $resolved = syndamorph_package_resolve( $css_path, $reference );
            if ( $resolved && in_array( $resolved, $meta['images'], true ) ) {
                return 'url("' . syndamorph_package_asset_url( $token, $resolved ) . '")';
            }
            // Fonts are recognized, but loading font faces is not yet supported.
            if ( $resolved && preg_match( '/\.(woff2?|ttf|otf|eot)$/i', $resolved ) ) {
                return $matches[0];
            }
            $missing[] = 'CSS asset: ' . $reference;
            return 'none';
        },
        $css
    );
}
/** Prepare a single page with package CSS embedded and package image URLs remapped. */
function syndamorph_package_prepare_html( ZipArchive $zip, array $meta, string $token, string $path, bool $with_js = false ): array {
    $html = $zip->getFromName( $path );
    if ( false === $html || strlen( $html ) > 2097152 ) { return array( 'error' => 'Page missing or larger than 2 MB.' ); }
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors( true );
    $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
    libxml_clear_errors();
    libxml_use_internal_errors( $previous );
    $xpath = new DOMXPath( $doc );
    $missing = array();
    $css_found = 0;
    foreach ( iterator_to_array( $xpath->query( '//link[contains(concat(" ", normalize-space(@rel), " "), " stylesheet ")]' ) ) as $link ) {
        $ref = $link->getAttribute( 'href' );
        $css_path = syndamorph_package_resolve( $path, $ref );
        if ( $css_path && in_array( $css_path, $meta['files'], true ) && str_ends_with( strtolower( $css_path ), '.css' ) ) {
            $css = $zip->getFromName( $css_path );
            if ( false !== $css && strlen( $css ) <= 524288 ) {
                $css = syndamorph_package_css_urls( $css, $css_path, $meta, $token, $missing );
                $style = $doc->createElement( 'style' );
                $style->appendChild( $doc->createTextNode( $css ) );
                $link->parentNode->replaceChild( $style, $link );
                $css_found++;
                continue;
            }
        }
        $missing[] = 'CSS: ' . $ref;
        $link->parentNode->removeChild( $link );
    }
    // Embedded <style> URLs are relative to their containing HTML page.
    // Already-rewritten absolute ZIP asset URLs are left untouched.
    foreach ( iterator_to_array( $xpath->query( '//style' ) ) as $embedded ) {
        $embedded->nodeValue = syndamorph_package_css_urls(
            $embedded->textContent, $path, $meta, $token, $missing
        );
    }
    // Inline CSS url() declarations resolve relative to the HTML file.
    foreach ( iterator_to_array( $xpath->query( '//*[@style]' ) ) as $styled ) {
        $styled->setAttribute( 'style', syndamorph_package_css_urls(
            $styled->getAttribute( 'style' ), $path, $meta, $token, $missing
        ) );
    }
    foreach ( iterator_to_array( $xpath->query( '//img[@src]' ) ) as $img ) {
        $ref = $img->getAttribute( 'src' );
        $asset = syndamorph_package_resolve( $path, $ref );
        if ( $asset && in_array( $asset, $meta['images'], true ) ) {
            $img->setAttribute( 'src', syndamorph_package_asset_url( $token, $asset ) );
        } elseif ( ! preg_match( '~^https?://~i', $ref ) ) {
            $missing[] = 'Image: ' . $ref;
            $img->setAttribute( 'src', '' );
        }
        $img->removeAttribute( 'srcset' );
        $img->removeAttribute( 'sizes' );
    }
    // Package-local JS can be inlined only when the user explicitly selects
    // the existing experimental isolated-JS mode. Never execute in WP admin.
    foreach ( iterator_to_array( $xpath->query( '//script[@src]' ) ) as $script ) {
        $ref = $script->getAttribute( 'src' );
        $js_path = syndamorph_package_resolve( $path, $ref );
        if ( $with_js && $js_path && in_array( $js_path, $meta['files'], true ) &&
            str_ends_with( strtolower( $js_path ), '.js' ) ) {
            $js = $zip->getFromName( $js_path );
            if ( false !== $js && strlen( $js ) <= 524288 ) {
                $script->removeAttribute( 'src' );
                $script->appendChild( $doc->createTextNode( $js ) );
                continue;
            }
        }
        $script->parentNode->removeChild( $script );
        $missing[] = 'JS skipped: ' . $ref;
    }
    return array( 'html' => $doc->saveHTML(), 'css' => $css_found, 'missing' => $missing );
}
add_action( 'rest_api_init', static function (): void {
    $permission = static function (): bool { return current_user_can( 'edit_pages' ); };
    register_rest_route( 'syndamorph/v1', '/package-scan', array(
        'methods' => 'POST', 'permission_callback' => $permission,
        'callback' => static function ( WP_REST_Request $request ) {
            if ( ! class_exists( 'ZipArchive' ) || ! class_exists( 'DOMDocument' ) ) {
                return syndamorph_package_error( 'PHP ZipArchive and DOM extensions are required.', 503 );
            }
            $upload = $request->get_file_params()['package'] ?? null;
            if ( ! is_array( $upload ) || UPLOAD_ERR_OK !== ( $upload['error'] ?? -1 ) ||
                ! is_uploaded_file( $upload['tmp_name'] ?? '' ) ||
                (int) $upload['size'] > SYNDAMORPH_PACKAGE_MAX ||
                ! preg_match( '/\.zip$/i', $upload['name'] ?? '' ) ) {
                return syndamorph_package_error( 'Upload a ZIP file no larger than 32 MB.' );
            }
            $zip = new ZipArchive();
            if ( true !== $zip->open( $upload['tmp_name'], ZipArchive::RDONLY ) ) {
                return syndamorph_package_error( 'Invalid ZIP archive.' );
            }
            if ( $zip->numFiles > 600 ) { $zip->close(); return syndamorph_package_error( 'ZIP contains too many entries (600 max).' ); }
            $files = array(); $pages = array(); $images = array(); $total = 0; $seen = array();
            for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                $entry = $zip->statIndex( $i );
                $name = $entry['name'];
                if ( ! syndamorph_package_valid_entry( rtrim( $name, '/' ) ) ) {
                    $zip->close(); return syndamorph_package_error( 'Unsafe ZIP entry path.' );
                }
                if ( str_ends_with( $name, '/' ) ) { continue; }
                if ( isset( $seen[ $name ] ) ) {
                    $zip->close(); return syndamorph_package_error( 'ZIP contains duplicate file paths.' );
                }
                $seen[ $name ] = true;
                $attributes = 0; $opsys = 0;
                if ( $zip->getExternalAttributesIndex( $i, $opsys, $attributes ) &&
                    ( ( ( $attributes >> 16 ) & 0170000 ) === 0120000 ) ) {
                    $zip->close(); return syndamorph_package_error( 'ZIP symlinks are not supported.' );
                }
                $total += (int) $entry['size'];
                if ( $total > SYNDAMORPH_PACKAGE_UNPACKED_MAX || (int) $entry['size'] > 12582912 ) {
                    $zip->close(); return syndamorph_package_error( 'ZIP exceeds safe expanded size limits.' );
                }
                if ( ! syndamorph_package_allowed_file( $name ) ) { continue; }
                $files[] = $name;
                if ( preg_match( '/\.html?$/i', $name ) ) { $pages[] = $name; }
                if ( preg_match( '/\.(png|jpe?g|webp|gif)$/i', $name ) ) { $images[] = $name; }
            }
            $zip->close();
            if ( ! $pages || count( $pages ) > 30 ) {
                return syndamorph_package_error( 'ZIP must contain between 1 and 30 HTML pages.' );
            }
            syndamorph_package_cleanup();
            $dir = syndamorph_package_root();
            if ( ! wp_mkdir_p( $dir ) ) { return syndamorph_package_error( 'Cannot create private temporary storage.', 500 ); }
            @chmod( $dir, 0700 );
            $token = bin2hex( random_bytes( 16 ) );
            $meta = array( 'user' => get_current_user_id(), 'expires' => time() + SYNDAMORPH_PACKAGE_TTL,
                'files' => $files, 'pages' => $pages, 'images' => $images );
            if ( ! move_uploaded_file( $upload['tmp_name'], syndamorph_package_path( $token ) ) ||
                false === file_put_contents( syndamorph_package_meta_path( $token ), wp_json_encode( $meta ) ) ) {
                return syndamorph_package_error( 'Could not stage ZIP in private storage.', 500 );
            }
            @chmod( syndamorph_package_path( $token ), 0600 );
            @chmod( syndamorph_package_meta_path( $token ), 0600 );
            return rest_ensure_response( array( 'token' => $token, 'pages' => $pages,
                'images' => count( $images ), 'assets' => count( $files ) - count( $pages ),
                'expires_in' => SYNDAMORPH_PACKAGE_TTL ) );
        },
    ) );
    register_rest_route( 'syndamorph/v1', '/package-page', array(
        'methods' => 'POST', 'permission_callback' => $permission,
        'callback' => static function ( WP_REST_Request $request ) {
            $token = (string) $request->get_param( 'token' );
            $page = (string) $request->get_param( 'page' );
            $package = syndamorph_package_open( $token );
            if ( is_wp_error( $package ) ) { return $package; }
            if ( ! in_array( $page, $package['meta']['pages'], true ) ) {
                $package['zip']->close(); return syndamorph_package_error( 'Invalid HTML page.' );
            }
            $result = syndamorph_package_prepare_html( $package['zip'], $package['meta'], $token, $page,
                (bool) $request->get_param( 'js_mode' ) );
            $package['zip']->close();
            return isset( $result['error'] ) ? syndamorph_package_error( $result['error'] ) : rest_ensure_response( $result );
        },
    ) );
    register_rest_route( 'syndamorph/v1', '/package-asset/(?P<token>[a-f0-9]{32})/(?P<encoded>[A-Za-z0-9_-]+)', array(
        'methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => static function ( WP_REST_Request $request ) {
            // Signed expiring URL: iframe img requests cannot attach REST nonce
            // headers. Nothing else in the package is exposed publicly.
            $path = base64_decode( strtr( (string) $request['encoded'], '-_', '+/' ), true );
            if ( ! $path || ! hash_equals(
                syndamorph_package_asset_signature( (string) $request['token'], $path ),
                (string) $request->get_param( 'sig' )
            ) ) { return syndamorph_package_error( 'Invalid image signature.', 403 ); }
            $package = syndamorph_package_open( (string) $request['token'], false );
            if ( is_wp_error( $package ) ) { return $package; }
            if ( ! in_array( $path, $package['meta']['images'], true ) ) {
                $package['zip']->close(); return syndamorph_package_error( 'Unknown image.', 404 );
            }
            $data = $package['zip']->getFromName( $path );
            $package['zip']->close();
            if ( false === $data ) { return syndamorph_package_error( 'Image unavailable.', 404 ); }
            // Attach binary to the response; rest_pre_serve_request delivers it,
            // without running it through WordPress JSON encoding.
            $response = new WP_REST_Response( array(), 200 );
            $response->header( 'X-SyndaMorph-Package-Asset', '1' );
            $response->header( 'Content-Type', syndamorph_package_mime( $path ) );
            $response->header( 'Cache-Control', 'private, no-store' );
            $response->set_data( array( 'binary' => base64_encode( $data ) ) );
            return $response;
        },
    ) );
} );
add_filter( 'rest_pre_serve_request', static function ( $served, $result, $request ) {
    $headers = $result instanceof WP_REST_Response ? $result->get_headers() : array();
    if ( $served || ! $result instanceof WP_REST_Response ||
        '1' !== ( $headers['X-SyndaMorph-Package-Asset'] ?? '' ) ) {
        return $served;
    }
    $data = $result->get_data();
    if ( ! isset( $data['binary'] ) ) { return $served; }
    header( 'Content-Type: ' . $result->get_headers()['Content-Type'] );
    header( 'Cache-Control: private, no-store' );
    header( 'X-Content-Type-Options: nosniff' );
    echo base64_decode( $data['binary'] );
    return true;
}, 10, 3 );


/** On approval upload only images actually referenced by the selected block markup. */
function syndamorph_package_media( ZipArchive $zip, string $path, array &$created ): int|WP_Error {
    $raw = $zip->getFromName( $path );
    if ( false === $raw ) {
        return syndamorph_package_error( 'Invalid package image or MIME mismatch: ' . $path );
    }
    return syndamorph_import_image_bytes( $raw, $path, $created );
}

/** Store verified image bytes using the same Media Library path as package imports. */
function syndamorph_import_image_bytes( string $raw, string $path, array &$created, bool $preserve_original = false ): int|WP_Error {
    $info = strlen( $raw ) <= 12582912 ? @getimagesizefromstring( $raw ) : false;
    if ( ! is_array( $info ) || ( $info['mime'] ?? '' ) !== syndamorph_package_mime( $path ) ) {
        return syndamorph_package_error( 'Invalid package image or MIME mismatch: ' . $path );
    }
    $hash = hash( 'sha256', $raw );
    $existing = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit',
        'fields' => 'ids', 'numberposts' => $preserve_original ? 20 : 1, 'meta_key' => '_syndamorph_sha256',
        'meta_value' => $hash ) );
    foreach ( $existing as $id ) {
        if ( ! $preserve_original || syndamorph_attachment_has_original( (int) $id, $hash ) ) {
            return (int) $id;
        }
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $mime = syndamorph_package_mime( $path );
    $name = sanitize_file_name( basename( $path ) );
    $checked = wp_check_filetype( $name );
    if ( $checked['type'] !== $mime ) {
        return syndamorph_package_error( 'Unsupported image type: ' . $path );
    }
    $saved = wp_upload_bits( $name, null, $raw );
    if ( ! empty( $saved['error'] ) ) { return syndamorph_package_error( 'Media upload failed: ' . $saved['error'], 500 ); }
    $attachment = wp_insert_attachment( array(
        'post_mime_type' => $mime,
        'post_title' => sanitize_text_field( pathinfo( $name, PATHINFO_FILENAME ) ),
        'post_status' => 'inherit',
    ), $saved['file'], 0, true );
    if ( is_wp_error( $attachment ) ) {
        @unlink( $saved['file'] );
        return $attachment;
    }
    if ( ! $preserve_original ) { $created[] = $attachment; }
    update_post_meta( $attachment, '_syndamorph_sha256', $hash );
    if ( $preserve_original ) { add_filter( 'big_image_size_threshold', '__return_false', PHP_INT_MAX ); }
    try {
        $metadata = wp_generate_attachment_metadata( $attachment, $saved['file'] );
    } finally {
        if ( $preserve_original ) { remove_filter( 'big_image_size_threshold', '__return_false', PHP_INT_MAX ); }
    }
    if ( is_array( $metadata ) ) { wp_update_attachment_metadata( $attachment, $metadata ); }
    if ( $preserve_original && ! syndamorph_attachment_has_original( $attachment, $hash ) ) {
        wp_delete_attachment( $attachment, true );
        return syndamorph_package_error( 'Media Library did not preserve the original image bytes.', 500 );
    }
    if ( $preserve_original ) { $created[] = $attachment; }
    return $attachment;
}

function syndamorph_attachment_has_original( int $id, string $hash ): bool {
    $file = get_attached_file( $id, true );
    $uploads = wp_get_upload_dir();
    $root = realpath( $uploads['basedir'] ?? '' );
    $real = is_string( $file ) ? realpath( $file ) : false;
    return $root !== false && $real !== false && str_starts_with( $real, $root . DIRECTORY_SEPARATOR ) &&
        is_file( $real ) && is_readable( $real ) && filesize( $real ) <= 12582912 && hash_file( 'sha256', $real ) === $hash;
}
function syndamorph_package_abort( array $posts, array $attachments ): void {
    foreach ( $posts as $id ) { wp_delete_post( $id, true ); }
    foreach ( $attachments as $id ) { wp_delete_attachment( $id, true ); }
}
add_action( 'rest_api_init', static function (): void {
    register_rest_route( 'syndamorph/v1', '/package-commit', array(
        'methods' => 'POST',
        'permission_callback' => static function (): bool { return current_user_can( 'edit_pages' ); },
        'callback' => static function ( WP_REST_Request $request ) {
            $token = (string) $request->get_param( 'token' );
            $items = $request->get_param( 'pages' );
            $mode = (string) $request->get_param( 'mode' );
            if ( ! in_array( $mode, array( 'draft', 'pattern' ), true ) ||
                ! is_array( $items ) || ! $items || count( $items ) > 30 ) {
                return syndamorph_package_error( 'Select pages and choose drafts or patterns.' );
            }
            $package = syndamorph_package_open( $token );
            if ( is_wp_error( $package ) ) { return $package; }
            $zip = $package['zip'];
            $meta = $package['meta'];
            $seen_pages = array(); $contents = array();
            foreach ( $items as $item ) {
                if ( ! is_array( $item ) ) { $zip->close(); return syndamorph_package_error( 'Invalid page selection.' ); }
                $path = (string) ( $item['path'] ?? '' );
                $content = (string) ( $item['content'] ?? '' );
                $title = sanitize_text_field( (string) ( $item['title'] ?? basename( $path, '.html' ) ) );
                if ( ! in_array( $path, $meta['pages'], true ) || isset( $seen_pages[$path] ) ||
                    ! has_blocks( $content ) || '' === $title || strlen( $content ) > 2097152 ) {
                    $zip->close(); return syndamorph_package_error( 'Invalid or duplicated page content.' );
                }
                $seen_pages[$path] = true;
                $contents[] = array( 'path' => $path, 'content' => $content, 'title' => $title );
            }
            // Find package image references in converted markup; no upload for
            // unused assets or unsupported font files.
            $references = array();
            foreach ( $meta['images'] as $path ) {
                $url = syndamorph_package_asset_url( $token, $path );
                foreach ( $contents as $item ) {
                    if ( str_contains( $item['content'], $url ) ||
                        str_contains( $item['content'], esc_url( $url ) ) ||
                        str_contains( $item['content'], htmlspecialchars( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) {
                        $references[$path] = true;
                        break;
                    }
                }
            }
            $created_media = array(); $created_posts = array(); $image_urls = array();
            // Content hashes dedupe assets both across package paths and from
            // previously imported SyndaMorph media.
            foreach ( array_keys( $references ) as $path ) {
                $attachment = syndamorph_package_media( $zip, $path, $created_media );
                if ( is_wp_error( $attachment ) ) {
                    syndamorph_package_abort( array(), $created_media );
                    $zip->close(); return $attachment;
                }
                $image_urls[$path] = wp_get_attachment_url( $attachment );
            }
            if ( 'pattern' === $mode ) {
                $term = term_exists( 'syndamorph', 'wp_pattern_category' );
                if ( ! $term ) { $term = wp_insert_term( 'SyndaMorph', 'wp_pattern_category', array( 'slug' => 'syndamorph' ) ); }
                if ( is_wp_error( $term ) ) {
                    syndamorph_package_abort( array(), $created_media );
                    $zip->close(); return $term;
                }
                $term_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );
            }
            $created = array();
            foreach ( $contents as $item ) {
                $content = $item['content'];
                foreach ( $image_urls as $path => $url ) {
                    $source = syndamorph_package_asset_url( $token, $path );
                    $content = str_replace(
                        array( $source, htmlspecialchars( $source, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), esc_url( $source ) ),
                        esc_url( $url ), $content
                    );
                }
                $id = wp_insert_post( wp_slash( array(
                    'post_type' => 'pattern' === $mode ? 'wp_block' : 'page',
                    'post_status' => 'pattern' === $mode ? 'publish' : 'draft',
                    'post_title' => $item['title'],
                    'post_content' => $content,
                ) ), true );
                if ( is_wp_error( $id ) || ! $id ) {
                    syndamorph_package_abort( $created_posts, $created_media );
                    $zip->close();
                    return is_wp_error( $id ) ? $id : syndamorph_package_error( 'Could not create the page.', 500 );
                }
                $created_posts[] = $id;
                if ( 'pattern' === $mode ) {
                    update_post_meta( $id, 'wp_pattern_sync_status', 'unsynced' );
                    $assigned = wp_set_object_terms( $id, array( $term_id ), 'wp_pattern_category', false );
                    if ( is_wp_error( $assigned ) ) {
                        syndamorph_package_abort( $created_posts, $created_media );
                        $zip->close(); return $assigned;
                    }
                }
                $created[] = array( 'id' => $id, 'title' => $item['title'], 'source' => $item['path'] );
            }
            $zip->close();
            @unlink( syndamorph_package_path( $token ) );
            @unlink( syndamorph_package_meta_path( $token ) );
            return rest_ensure_response( array( 'created' => $created,
                'uploaded_images' => count( $created_media ), 'reused_images' => count( $image_urls ) - count( $created_media ),
                'mode' => $mode ) );
        },
    ) );
} );
