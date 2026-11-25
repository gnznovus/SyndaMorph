<?php
/** Canvas for the reconstructed single page, including its captured landmarks. */
if (!defined('ABSPATH')) { exit; }
the_post();
$page=get_post();
echo syndamorph_scope_document($page->post_content,$page->post_title); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Native block serialization and WordPress theme CSS.
