<?php
/** Editable desktop offer with an in-page dismissal and no external action. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_offer_copy( $copy ): ?array {
    $limits = array( 'placeholder' => 40, 'eyebrow' => 60, 'title' => 100, 'description' => 220,
        'offerLabel' => 80, 'dismissLabel' => 80 );
    if ( ! is_array( $copy ) || array_diff_key( $copy, $limits ) || array_diff_key( $limits, $copy ) ) {
        return null;
    }
    foreach ( $limits as $key => $limit ) {
        $text = $copy[ $key ];
        if ( ! is_string( $text ) || $text === '' || $text !== trim( $text ) ||
            mb_strlen( $text ) > $limit || preg_match( '/[\x00-\x1f\x7f]/', $text ) ) {
            return null;
        }
    }
    return $copy;
}

function syndamorph_offer_render( array $attributes ): string {
    $copy = syndamorph_offer_copy( $attributes['copy'] ?? null );
    if ( $copy === null ) { return ''; }
    $title_id = wp_unique_id( 'sm-offer-title-' );
    ob_start();
    ?>
    <div class="sm-offer" data-sm-offer>
        <div class="sm-offer-shade" aria-hidden="true"></div>
        <section class="sm-offer-card" aria-labelledby="<?php echo esc_attr( $title_id ); ?>">
            <p class="sm-offer-placeholder"><?php echo esc_html( $copy['placeholder'] ); ?></p>
            <div class="sm-offer-content">
                <p class="sm-offer-eyebrow"><?php echo esc_html( $copy['eyebrow'] ); ?></p>
                <h2 id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $copy['title'] ); ?></h2>
                <p class="sm-offer-description"><?php echo esc_html( $copy['description'] ); ?></p>
                <span class="sm-offer-label"><?php echo esc_html( $copy['offerLabel'] ); ?></span>
                <button type="button" class="sm-offer-dismiss"><?php echo esc_html( $copy['dismissLabel'] ); ?></button>
            </div>
        </section>
    </div>
    <?php
    return ob_get_clean();
}

add_action( 'init', static function (): void {
    $plugin = dirname( __DIR__ ) . '/syndamorph.php';
    $asset = static fn( string $name ): string => plugins_url( 'assets/' . $name, $plugin );
    $version = static fn( string $name ): string => (string) filemtime( dirname( __DIR__ ) . '/assets/' . $name );
    wp_register_script( 'syndamorph-offer-editor', $asset( 'production-offer-editor.js' ),
        array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element' ), $version( 'production-offer-editor.js' ), true );
    wp_register_script( 'syndamorph-offer-view', $asset( 'production-offer-view.js' ), array(),
        $version( 'production-offer-view.js' ), true );
    wp_register_style( 'syndamorph-offer', $asset( 'production-offer.css' ), array(), $version( 'production-offer.css' ) );
    register_block_type( 'syndamorph/desktop-offer', array(
        'api_version' => 3, 'title' => 'Desktop Offer', 'category' => 'widgets',
        'attributes' => array( 'copy' => array( 'type' => 'object', 'default' => array() ) ),
        'supports' => array( 'html' => false, 'customClassName' => false, 'multiple' => false ),
        'editor_script' => 'syndamorph-offer-editor', 'view_script' => 'syndamorph-offer-view',
        'style' => 'syndamorph-offer', 'render_callback' => 'syndamorph_offer_render',
    ) );
} );
