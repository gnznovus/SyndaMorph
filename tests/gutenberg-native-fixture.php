<?php
// CI-only fixture. Never deploy this file with the plugin.
require '/var/www/html/wp-load.php';
require_once '/var/www/html/wp-content/plugins/syndamorph/includes/production-block-plan.php';
$plan=array('version'=>1,'blocks'=>array(array('type'=>'group','style'=>array('gradient'=>'linear-gradient(135deg,#1b1a17,#2a2722)','minHeight'=>'clamp(28rem,70svh,46rem)','padding'=>'2rem','justifyContent'=>'left','verticalAlignment'=>'center'),'children'=>array(
    array('type'=>'group','style'=>array('contentWidth'=>'34rem'),'children'=>array(
        array('type'=>'group','style'=>array('gap'=>'clamp(1rem,0.8rem + 1vw,1.75rem)','justifyContent'=>'left','verticalAlignment'=>'top'),'children'=>array(
            array('type'=>'heading','text'=>'Welcome','level'=>2,'style'=>array('color'=>'#f5f1ea','fontSize'=>'clamp(2rem,1.2rem + 3.5vw,4.25rem)','fontWeight'=>600,'lineHeight'=>1.1,'letterSpacing'=>'-0.01em','align'=>'left')),
            array('type'=>'paragraph','text'=>'A comfortable stay.','style'=>array('color'=>'#d6cfc4','fontSize'=>'1rem','lineHeight'=>1.6,'fontWeight'=>400,'align'=>'left')),
            array('type'=>'buttons','children'=>array(array('type'=>'button','text'=>'Explore','href'=>'#rooms','style'=>array('background'=>'#c9a86a','color'=>'#1b1a17','fontWeight'=>600,'lineHeight'=>1.25,'borderRadius'=>'2px','padding'=>array('top'=>'0.9rem','right'=>'1.75rem','bottom'=>'0.9rem','left'=>'1.75rem')))))
        ))
    ))
))));
$artifact=syndamorph_compile_block_plan(wp_json_encode($plan));
if(is_wp_error($artifact)){http_response_code(500);exit($artifact->get_error_message());}
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html><head><meta charset="utf-8"><?php
wp_enqueue_script('wp-block-library');
wp_print_scripts(array('wp-block-library'));
?></head><body><script src="/wp-content/plugins/syndamorph/assets/production-ai-export.js"></script><script>window.nativeFixture=<?php echo wp_json_encode($artifact,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;</script></body></html>
