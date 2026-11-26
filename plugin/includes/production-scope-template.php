<?php
/** Canvas for the reconstructed single page, including its captured landmarks. */
if (!defined('ABSPATH')) { exit; }
the_post();
$page=get_post();
$document=syndamorph_scope_document($page->post_content,$page->post_title);
if(function_exists('wp_print_font_faces')){
    // This isolated canvas bypasses wp_head, including core's font-face output.
    ob_start();
    wp_print_font_faces();
    $fontStyles=ob_get_clean();
    $document=substr_replace($document,$fontStyles,strpos($document,'</head>'),0);
}
echo $document; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Native block serialization and WordPress theme CSS.
