<?php
/** Canvas for the reconstructed single page, including its captured landmarks. */
if (!defined('ABSPATH')) { exit; }
the_post();
$page=get_post();
$record=get_post_meta($page->ID,'_syndamorph_scope_manifest',true);
$document=syndamorph_scope_page_document($page,is_array($record)?$record:array());
if(function_exists('wp_print_font_faces')){
    // This isolated canvas bypasses wp_head, including core's font-face output.
    ob_start();
    wp_print_font_faces();
    $fontStyles=ob_get_clean();
    $document=substr_replace($document,$fontStyles,strpos($document,'</head>'),0);
}
echo $document; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Native block serialization and WordPress theme CSS.
