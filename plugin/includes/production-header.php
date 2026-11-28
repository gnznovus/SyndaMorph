<?php
/** Editable source-backed header. URLs are rendered as links and never fetched by this block. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_header_decode_text( $value ) {
    // WordPress may encode entities when an editor without unfiltered_html saves a block.
    return is_string( $value ) ? html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $value;
}

function syndamorph_header_text( $text ): bool {
    $text = syndamorph_header_decode_text( $text );
    return is_string( $text ) && $text !== '' && $text === trim( $text ) &&
        mb_strlen( $text ) <= 120 && ! preg_match( '/[\x00-\x1f\x7f]/', $text );
}

function syndamorph_header_url( $value ): bool {
    $value = syndamorph_header_decode_text( $value );
    if ( ! is_string( $value ) || strlen( $value ) > 2048 || preg_match( '~[\x00-\x20\x7f\\\\]~', $value ) ) {
        return false;
    }
    $url = wp_parse_url( $value );
    return is_array( $url ) && ( $url['scheme'] ?? null ) === 'https' && ! empty( $url['host'] ) &&
        ! array_key_exists( 'user', $url ) && ! array_key_exists( 'pass', $url );
}

function syndamorph_header_list( $items, bool $linked, bool $nested = true ): bool {
    if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) > 16 ) { return false; }
    foreach ( $items as $item ) {
        if ( ! is_array( $item ) || array_diff_key( $item, array_fill_keys( array( 'label', 'href', 'children', 'emphasis', 'panelWidth', 'separatorBefore' ), true ) ) ||
            ! syndamorph_header_text( $item['label'] ?? null ) ||
            ( ( $linked || isset( $item['href'] ) ) && ! syndamorph_header_url( $item['href'] ?? null ) ) ||
            ( isset( $item['emphasis'] ) && ( $linked || ! is_bool( $item['emphasis'] ) ) ) ||
            ( isset( $item['separatorBefore'] ) && ( ! $linked || $nested || ! is_bool( $item['separatorBefore'] ) ) ) ||
            ( isset( $item['panelWidth'] ) && ( ! $linked || ! $nested || empty( $item['children'] ) ||
                ! is_int( $item['panelWidth'] ) || $item['panelWidth'] < 180 || $item['panelWidth'] > 380 ) ) ||
            ( isset( $item['children'] ) && ( ! $nested || ! syndamorph_header_list( $item['children'], $linked, false ) ) ) ) {
            return false;
        }
    }
    return true;
}

function syndamorph_header_content( $data ): ?array {
    if ( ! is_array( $data ) ) { return null; }
    $fields = array( 'logoId', 'logoAlt', 'homeUrl', 'rateUrl', 'rateLabel', 'bookLabel', 'languageLabel',
        'languages', 'utility', 'primary', 'compactPrimary', 'compactUtility' );
    if ( count( $data ) !== count( $fields ) || array_diff( $fields, array_keys( $data ) ) ) { return null; }
    if ( ! is_int( $data['logoId'] ) || $data['logoId'] < 1 ||
        ! syndamorph_header_url( $data['homeUrl'] ) || ! syndamorph_header_url( $data['rateUrl'] ) ||
        count( array_filter( array_intersect_key( $data, array_flip( array( 'logoAlt', 'rateLabel', 'bookLabel', 'languageLabel' ) ) ),
            'syndamorph_header_text' ) ) !== 4 ) { return null; }
    $languages = $data['languages'];
    if ( ! is_array( $languages ) || ! array_is_list( $languages ) || count( $languages ) < 2 || count( $languages ) > 8 ||
        count( array_filter( $languages, 'syndamorph_header_text' ) ) !== count( $languages ) ) { return null; }
    foreach ( array( 'utility', 'primary', 'compactPrimary', 'compactUtility' ) as $name ) {
        if ( ! $data[ $name ] || ! syndamorph_header_list( $data[ $name ], in_array( $name, array( 'utility', 'primary' ), true ) ) ) {
            return null;
        }
    }
    return map_deep( $data, 'syndamorph_header_decode_text' );
}

function syndamorph_header_item_label( array $item ): string {
    $label = esc_html( $item['label'] );
    return isset( $item['href'] ) ? '<a href="' . esc_url( $item['href'] ) . '">' . $label . '</a>' : '<span>' . $label . '</span>';
}

function syndamorph_header_desktop_list( array $items ): void {
    foreach ( $items as $item ) {
        if ( empty( $item['children'] ) ) {
            echo syndamorph_header_item_label( $item );
            continue;
        }
        ?>
        <div class="sm-header-dropdown">
            <a class="sm-header-chevron" href="<?php echo esc_url( $item['href'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
            <div class="sm-header-flyout<?php echo count( $item['children'] ) > 6 ? ' sm-header-flyout-tall' : ''; ?>"<?php if ( isset( $item['panelWidth'] ) ) {
                echo ' style="--sm-header-panel-width:' . esc_attr( $item['panelWidth'] ) . 'px"';
            } ?>><?php foreach ( $item['children'] as $child ) {
                if ( ! empty( $child['separatorBefore'] ) ) { echo '<span class="sm-header-separator" aria-hidden="true"></span>'; }
                echo syndamorph_header_item_label( $child );
            } ?></div>
        </div>
        <?php
    }
}

function syndamorph_header_compact_list( array $items, bool $primary ): void {
    foreach ( $items as $item ) {
        $children = $item['children'] ?? array();
        $emphasis = ! empty( $item['emphasis'] ) ? ' sm-header-emphasis' : '';
        ?>
        <div class="sm-header-drawer-row<?php echo esc_attr( $emphasis ); ?>">
            <?php echo syndamorph_header_item_label( $item ); ?>
            <?php if ( $primary && $children ) : ?><span aria-hidden="true">+</span><?php endif; ?>
        </div>
        <?php if ( $children ) : ?>
            <div class="sm-header-drawer-children"><?php foreach ( $children as $child ) { echo syndamorph_header_item_label( $child ); } ?></div>
        <?php endif;
    }
}

function syndamorph_recorded_header_render( array $attributes ): string {
    $data = syndamorph_header_content( $attributes['navigation'] ?? null );
    if ( $data === null || ! wp_attachment_is_image( $data['logoId'] ) ) { return ''; }
    $source = wp_get_attachment_url( $data['logoId'] );
    if ( ! $source ) { return ''; }
    $id = wp_unique_id( 'sm-header-' );
    $hash = $attributes['logoSha256'] ?? null;
    $marker = is_string( $hash ) && preg_match( '/^[a-f0-9]{64}$/D', $hash )
        ? ' data-sm-header-logo="' . esc_attr( $data['logoId'] . ':' . $hash ) . '"' : '';
    $logo = '<a class="sm-header-logo" href="' . esc_url( $data['homeUrl'] ) . '">' .
        '<img src="' . esc_url( $source ) . '" alt="' . esc_attr( $data['logoAlt'] ) . '" decoding="sync"' . $marker . '></a>';
    $check_rate = '<a class="sm-header-rate" href="' . esc_url( $data['rateUrl'] ) . '" target="_blank" rel="noopener noreferrer">' .
        esc_html( $data['rateLabel'] ) . '</a>';

    ob_start();
    ?>
    <header class="sm-recorded-header" data-sm-header>
        <div class="sm-header-desktop">
            <div class="sm-header-utility">
                <nav aria-label="Utility"><?php syndamorph_header_desktop_list( $data['utility'] ); ?></nav>
                <div class="sm-header-dropdown sm-header-language">
                    <button type="button" class="sm-header-chevron" aria-expanded="false"
                        aria-controls="<?php echo esc_attr( $id . '-languages' ); ?>"><?php echo esc_html( $data['languageLabel'] ); ?></button>
                    <div class="sm-header-flyout" id="<?php echo esc_attr( $id . '-languages' ); ?>" hidden>
                        <?php foreach ( $data['languages'] as $language ) : ?><span><?php echo esc_html( $language ); ?></span><?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="sm-header-main">
                <?php echo $logo; ?>
                <nav aria-label="Primary"><?php syndamorph_header_desktop_list( $data['primary'] ); ?></nav>
                <?php echo $check_rate; ?>
            </div>
        </div>
        <div class="sm-header-compact">
            <?php echo $logo; ?>
            <div class="sm-header-compact-actions">
                <span class="sm-header-rate"><?php echo esc_html( $data['bookLabel'] ); ?></span>
                <button type="button" class="sm-header-open" aria-label="Open menu" aria-expanded="false"
                    aria-controls="<?php echo esc_attr( $id . '-drawer' ); ?>"><span aria-hidden="true"></span></button>
            </div>
            <dialog class="sm-header-drawer" id="<?php echo esc_attr( $id . '-drawer' ); ?>" aria-label="Navigation">
                <div class="sm-header-drawer-top"><?php echo $logo; ?>
                    <button type="button" class="sm-header-close" aria-label="Close menu">✕</button>
                </div>
                <nav class="sm-header-drawer-nav" aria-label="Primary">
                    <?php syndamorph_header_compact_list( $data['compactPrimary'], true ); ?>
                    <div class="sm-header-drawer-utility"><?php syndamorph_header_compact_list( $data['compactUtility'], false ); ?></div>
                </nav>
                <div class="sm-header-drawer-actions">
                    <div><strong><?php echo esc_html( $data['languageLabel'] ); ?></strong>
                        <?php foreach ( array_slice( $data['languages'], 1 ) as $language ) : ?><span><?php echo esc_html( $language ); ?></span><?php endforeach; ?>
                    </div>
                    <?php echo $check_rate; ?>
                </div>
            </dialog>
        </div>
    </header>
    <?php
    return ob_get_clean();
}

add_action( 'init', static function (): void {
    $plugin = dirname( __DIR__ ) . '/syndamorph.php';
    $url = static fn( string $file ): string => plugins_url( 'assets/' . $file, $plugin );
    $version = static fn( string $file ): string => (string) filemtime( dirname( __DIR__ ) . '/assets/' . $file );
    wp_register_script( 'syndamorph-header-editor', $url( 'production-header-editor.js' ),
        array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-html-entities' ), $version( 'production-header-editor.js' ), true );
    wp_register_script( 'syndamorph-header-view', $url( 'production-header-view.js' ), array(),
        $version( 'production-header-view.js' ), true );
    wp_register_style( 'syndamorph-header', $url( 'production-header.css' ), array(), $version( 'production-header.css' ) );
    register_block_type( 'syndamorph/recorded-header', array(
        'api_version' => 3, 'title' => 'Recorded Header', 'category' => 'design',
        'attributes' => array( 'navigation' => array( 'type' => 'object', 'default' => array() ),
            'logoSha256' => array( 'type' => 'string' ) ),
        'supports' => array( 'html' => false, 'multiple' => false, 'customClassName' => false ),
        'editor_script' => 'syndamorph-header-editor', 'view_script' => 'syndamorph-header-view',
        'style' => 'syndamorph-header', 'render_callback' => 'syndamorph_recorded_header_render',
    ) );
    if ( function_exists( 'register_block_template' ) && wp_is_block_theme() ) {
        register_block_template( 'syndamorph//production-canvas', array(
            'title' => __( 'SyndaMorph Production Canvas', 'syndamorph' ),
            'description' => __( 'Page content supplies its own header and footer.', 'syndamorph' ),
            'content' => '<!-- wp:post-content {"tagName":"main","className":"sm-production-canvas","layout":{"type":"default"}} /-->',
            'post_types' => array( 'page' ),
        ) );
    }
} );
