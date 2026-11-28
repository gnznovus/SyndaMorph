<?php
/**
 * Shared Operations navigation for the four SyndaMorph admin workspaces.
 * The active module is a presentation decision, not a separate page hook.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_operations_navigation( string $active ): void {
    $modules = array(
        'overview' => array( 'Overview', 'dashicons-dashboard', 'admin.php?page=syndamorph' ),
        'production' => array( 'AI Production Team', 'dashicons-groups', 'admin.php?page=syndamorph&tab=production' ),
        'ai' => array( 'AI Integration', 'dashicons-admin-network', 'admin.php?page=syndamorph&tab=ai' ),
        'gateway' => array( 'Client Access Manager', 'dashicons-shield', 'admin.php?page=syndamorph&tab=gateway' ),
    );
    echo '<nav class="sm-nav" aria-label="SyndaMorph modules">';
    foreach ( $modules as $key => $module ) {
        $selected = $key === $active;
        $class = 'sm-nav-item' . ( $selected ? ' is-active' : '' );
        $current = $selected ? ' aria-current="page"' : '';
        echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( admin_url( $module[2] ) ) . '"' . $current . '>';
        echo '<span class="dashicons ' . esc_attr( $module[1] ) . '" aria-hidden="true"></span> ' . esc_html( $module[0] );
        echo '</a>';
    }
    echo '</nav>';
}
