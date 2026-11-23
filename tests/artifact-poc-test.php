<?php
/** Minimal PHP integration smoke test for the opt-in independence POC.
 * Runs without WordPress; real WP/plugin removal must still be tested locally.
 */
declare(strict_types=1);
define( 'ABSPATH', '/test/' );
$GLOBALS['poc_posts'] = array();
$GLOBALS['poc_meta'] = array();
$GLOBALS['poc_next_id'] = 10;
$GLOBALS['poc_root'] = sys_get_temp_dir() . '/syndamorph-poc-' . bin2hex( random_bytes( 6 ) );
class WP_Error {
    public function __construct( public string $code, public string $message, public array $data = array() ) {}
    public function get_error_message(): string { return $this->message; }
}
class WP_REST_Request {}
class WP_REST_Response {}
function add_action( ...$unused ): void {}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function trailingslashit( $text ): string { return rtrim( $text, '/' ) . '/'; }
function wp_upload_dir( ...$unused ): array { return array( 'basedir' => $GLOBALS['poc_root'] ); }
function wp_mkdir_p( $path ): bool { return is_dir( $path ) || mkdir( $path, 0750, true ); }
function has_blocks( $content ): bool { return str_contains( $content, '<!-- wp:' ); }
function parse_blocks( $content ): array {
    // Only the supported one-block fixture is used in this isolation test.
    if ( preg_match( '~^<!-- wp:([a-z-]+) -->(.*?)<!-- /wp:\\1 -->$~s', $content, $matches ) ) {
        return array( array( 'blockName' => 'core/' . $matches[1], 'innerBlocks' => array(), 'innerHTML' => $matches[2] ) );
    }
    return array( array( 'blockName' => null, 'innerBlocks' => array(), 'innerHTML' => $content ) );
}
function wp_json_encode( $value, $options = 0 ): string { return json_encode( $value, $options, JSON_THROW_ON_ERROR ); }
function sanitize_text_field( $value ): string { return trim( strip_tags( $value ) ); }
function wp_slash( $value ) { return $value; }
function current_user_can( ...$unused ): bool { return true; }
function get_post( $id ) { return $GLOBALS['poc_posts'][$id] ?? null; }
function get_post_meta( $id, $name, $single = false ) { return $GLOBALS['poc_meta'][$id][$name] ?? null; }
function update_post_meta( $id, $name, $value ): void { $GLOBALS['poc_meta'][$id][$name] = $value; }
function wp_insert_post( $data, $throw = false ) {
    $id = $data['ID'] ?? $GLOBALS['poc_next_id']++;
    $GLOBALS['poc_posts'][$id] = (object) array(
        'ID' => $id, 'post_type' => $data['post_type'], 'post_status' => $data['post_status'],
        'post_title' => $data['post_title'], 'post_content' => $data['post_content'],
    );
    return $id;
}
function wp_update_post( $data ) {
    $post = $GLOBALS['poc_posts'][$data['ID']];
    foreach ( $data as $key => $value ) { $post->{$key} = $value; }
    return $data['ID'];
}
function wp_delete_post( $id, $force = false ): void { unset( $GLOBALS['poc_posts'][$id] ); }

require dirname( __DIR__ ) . '/plugin/includes/artifact-poc.php';
function check( $condition, string $message ): void {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function clean( string $dir ): void {
    if ( ! is_dir( $dir ) ) { return; }
    foreach ( new FilesystemIterator( $dir ) as $entry ) {
        if ( $entry->isDir() ) { clean( $entry->getPathname() ); }
        else { unlink( $entry->getPathname() ); }
    }
    rmdir( $dir );
}
try {
    $one = '<!-- wp:paragraph --><p>Version one</p><!-- /wp:paragraph -->';
    $two = '<!-- wp:paragraph --><p>Version two</p><!-- /wp:paragraph -->';
    check( is_wp_error( syndamorph_poc_validate_content( '<!-- wp:html --><div>bad</div><!-- /wp:html -->' ) ), 'Custom/raw HTML must fail.' );
    check( is_wp_error( syndamorph_poc_validate_content( '<!-- wp:paragraph --><p onclick="bad()">bad</p><!-- /wp:paragraph -->' ) ), 'Event handlers must fail.' );
    $a = syndamorph_poc_publish( 'POC', $one );
    check( ! is_wp_error( $a ) && $a['version'] === 'v000001', 'Initial version missing.' );
    $id = $a['id'];
    $b = syndamorph_poc_publish( 'POC', $two, $id );
    check( ! is_wp_error( $b ) && $b['version'] === 'v000002', 'Second version missing.' );
    $versions = syndamorph_poc_versions( $id );
    check( count( $versions ) === 2, 'Version listing incorrect.' );
    $old = syndamorph_poc_directory( $id ) . '/versions/v000001/pattern.blocks.txt';
    check( file_get_contents( $old ) === $one, 'Version 1 was overwritten.' );
    check( hash_equals( $versions[0]['content_sha256'], hash_file( 'sha256', $old ) ), 'Checksum mismatch.' );
    $c = syndamorph_poc_publish( 'POC', file_get_contents( $old ), $id );
    check( ! is_wp_error( $c ) && $c['version'] === 'v000003', 'Rollback must create new version.' );
    check( get_post( $id )->post_content === $one, 'WordPress copy not restored.' );
    check( count( syndamorph_poc_versions( $id ) ) === 3, 'Version history lost.' );
    // Deleting PHP plugin files would not touch this site-owned uploads path.
    check( str_starts_with( $old, $GLOBALS['poc_root'] ), 'Archive should be outside plugin.' );
    echo "PASS: create, update, immutable versions, integrity, restore, no plugin-directory dependency\n";
} finally {
    clean( $GLOBALS['poc_root'] );
}
