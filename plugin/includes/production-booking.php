<?php
/** Editable booking summary with local guest and rate state. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_booking_defaults(): array {
    return array(
        'dates' => '2 Nights', 'arrival' => 'Fri, 12 Dec', 'departure' => 'Sun, 14 Dec',
        'guests' => 'Rooms & guests', 'rate' => 'Rate preference', 'availability' => 'Check Availability',
        'rooms' => 'Rooms', 'adults' => 'Adults', 'children' => 'Children',
        'rates' => array( 'Best Available', 'AAA/CAA Rate', 'Government/Military Business Rate',
            'Member Senior Discounts', 'Reward Nights' ),
        'notes' => array( 'Corporate Special Rate', 'Group Rate', 'Are you a travel agent?' ),
    );
}

function syndamorph_booking_copy( $copy ): ?array {
    $defaults = syndamorph_booking_defaults();
    if ( ! is_array( $copy ) || array_diff_key( $copy, $defaults ) ) { return null; }
    $valid = static fn( $value ): bool => is_string( $value ) && $value !== '' &&
        $value === trim( $value ) && mb_strlen( $value ) <= 100 && ! preg_match( '/[\x00-\x1f\x7f]/', $value );
    foreach ( $copy as $key => $value ) {
        if ( is_array( $defaults[ $key ] ) ) {
            if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) !== count( $defaults[ $key ] ) ||
                count( array_filter( $value, $valid ) ) !== count( $value ) ||
                count( array_unique( $value ) ) !== count( $value ) ) { return null; }
        } elseif ( ! $valid( $value ) ) { return null; }
    }
    return $copy + $defaults;
}

function syndamorph_booking_render( array $attributes ): string {
    $copy = syndamorph_booking_copy( $attributes['copy'] ?? array() );
    if ( $copy === null ) { return ''; }
    $prefix = wp_unique_id( 'sm-booking-' );
    $overlap = ! empty( $attributes['overlap'] ) ? ' sm-booking-overlap' : '';
    $component = $attributes['previewComponent'] ?? '';
    $counter_limits = array( 'rooms' => array( 1, 1, 9 ), 'adults' => array( 2, 1, 8 ), 'children' => array( 0, 0, 8 ) );
    ob_start();
    ?>
    <div class="wp-block-syndamorph-booking-summary<?php echo esc_attr( $overlap ); ?>" data-sm-booking<?php
        if ( is_string( $component ) && $component !== '' ) { echo ' data-sm-node="' . esc_attr( $component ) . '"'; }
    ?>>
        <div class="sm-booking-field">
            <span class="sm-booking-label"><?php echo esc_html( $copy['dates'] ); ?></span>
            <span class="sm-booking-dates">
                <?php echo esc_html( $copy['arrival'] ); ?>
                <span class="sm-booking-arrow" aria-hidden="true"></span>
                <?php echo esc_html( $copy['departure'] ); ?>
            </span>
            <span class="sm-booking-calendar" aria-hidden="true"></span>
        </div>
        <div class="sm-booking-field">
            <button type="button" class="sm-booking-toggle" aria-expanded="false"
                aria-controls="<?php echo esc_attr( $prefix . '-guests' ); ?>">
                <span class="sm-booking-label"><?php echo esc_html( $copy['guests'] ); ?></span>
                <span data-sm-booking-guests>1 room · 2 guests</span>
            </button>
            <div class="sm-booking-panel sm-booking-counters" id="<?php echo esc_attr( $prefix . '-guests' ); ?>" hidden>
                <?php foreach ( $counter_limits as $key => list( $initial, $minimum, $maximum ) ) : ?>
                    <div class="sm-booking-counter" data-sm-counter="<?php echo esc_attr( $key ); ?>"
                        data-min="<?php echo esc_attr( $minimum ); ?>" data-max="<?php echo esc_attr( $maximum ); ?>">
                        <span><?php echo esc_html( $copy[ $key ] ); ?></span>
                        <button type="button" data-sm-step="-1" aria-label="<?php echo esc_attr( sprintf( __( 'Decrease %s', 'syndamorph' ), $copy[ $key ] ) ); ?>">−</button>
                        <output aria-live="polite"><?php echo esc_html( $initial ); ?></output>
                        <button type="button" data-sm-step="1" aria-label="<?php echo esc_attr( sprintf( __( 'Increase %s', 'syndamorph' ), $copy[ $key ] ) ); ?>">+</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="sm-booking-field">
            <button type="button" class="sm-booking-toggle" aria-expanded="false"
                aria-controls="<?php echo esc_attr( $prefix . '-rates' ); ?>">
                <span class="sm-booking-label"><?php echo esc_html( $copy['rate'] ); ?></span>
                <span data-sm-booking-rate><?php echo esc_html( $copy['rates'][0] ); ?></span>
            </button>
            <div class="sm-booking-panel sm-booking-rates" id="<?php echo esc_attr( $prefix . '-rates' ); ?>" hidden>
                <?php foreach ( $copy['rates'] as $index => $rate ) : ?>
                    <button type="button" data-sm-rate aria-pressed="<?php echo $index === 0 ? 'true' : 'false'; ?>"><?php echo esc_html( $rate ); ?></button>
                <?php endforeach; ?>
                <?php foreach ( $copy['notes'] as $note ) : ?>
                    <span><?php echo esc_html( $note ); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <span class="sm-booking-availability"><?php echo esc_html( $copy['availability'] ); ?></span>
    </div>
    <?php
    return ob_get_clean();
}

add_action( 'init', static function (): void {
    $plugin = dirname( __DIR__ ) . '/syndamorph.php';
    $url = static fn( string $name ): string => plugins_url( 'assets/' . $name, $plugin );
    $version = static fn( string $name ): string => (string) filemtime( dirname( __DIR__ ) . '/assets/' . $name );
    wp_register_script( 'syndamorph-booking-editor', $url( 'production-booking-editor.js' ),
        array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element' ), $version( 'production-booking-editor.js' ), true );
    wp_add_inline_script( 'syndamorph-booking-editor', 'const syndamorphBookingDefaults = ' .
        wp_json_encode( syndamorph_booking_defaults(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
    wp_register_script( 'syndamorph-booking-view', $url( 'production-booking-view.js' ),
        array(), $version( 'production-booking-view.js' ), true );
    wp_register_style( 'syndamorph-booking', $url( 'production-booking.css' ), array(), $version( 'production-booking.css' ) );
    register_block_type( 'syndamorph/booking-summary', array(
        'api_version' => 3, 'title' => 'Booking Summary', 'category' => 'widgets',
        'attributes' => array( 'copy' => array( 'type' => 'object', 'default' => array() ),
            'overlap' => array( 'type' => 'boolean', 'default' => false ) ),
        'supports' => array( 'html' => false, 'customClassName' => false ),
        'style' => 'syndamorph-booking', 'view_script' => 'syndamorph-booking-view',
        'editor_script' => 'syndamorph-booking-editor', 'render_callback' => 'syndamorph_booking_render',
    ) );
} );
