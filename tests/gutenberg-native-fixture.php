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
$fixtures=array();
$add=static function(string $name,array $children,array $rootStyle=array())use(&$fixtures){
    $compiled=syndamorph_compile_block_plan(wp_json_encode(array('version'=>1,'blocks'=>array(array('type'=>'group','style'=>$rootStyle,'children'=>$children)))));
    if(is_wp_error($compiled)){http_response_code(500);exit($name.': '.$compiled->get_error_message());}
    $fixtures[$name]=$compiled;
};
$heading=array('type'=>'heading','text'=>'Fixture heading');
$paragraph=array('type'=>'paragraph','text'=>'Plain & editable.');
$button=array('type'=>'button','text'=>'CTA label','href'=>'#reservations');
$wrap=static fn(array $child,array $style=array())=>array('type'=>'buttons','style'=>$style,'children'=>array($child));
$add('GEN9 custom CTA font',array($heading,$wrap(array_merge($button,array('style'=>array('color'=>'#18283a','background'=>'#c9a86a','fontSize'=>'1rem','fontWeight'=>600,'lineHeight'=>1.25,'padding'=>array('top'=>'0.875rem','right'=>'1.5rem','bottom'=>'0.875rem','left'=>'1.5rem'),'borderRadius'=>'0.25rem'))))));
$add('plain all types',array($heading,$paragraph,$wrap($button),array('type'=>'columns','children'=>array(array('type'=>'column','children'=>array($paragraph)),array('type'=>'column','children'=>array($heading))))));
$add('captured viewport variants',array(
    array('type'=>'group','style'=>array('visibility'=>'desktop','background'=>'#aaccbb'),'children'=>array($heading)),
    array('type'=>'group','style'=>array('visibility'=>'compact'),'children'=>array($paragraph)),
));
$typography=array('color'=>'#123456','background'=>'#abcdef','fontSize'=>'1.25rem','fontWeight'=>700,'lineHeight'=>1.4,'letterSpacing'=>'-0.02em');
foreach(array('heading','paragraph') as $type){
    $leaf=$type==='heading'?$heading:$paragraph;
    foreach(array('left','center','right') as $align)$add($type.' '.$align,array($heading,array_merge($leaf,array('style'=>$typography+array('align'=>$align,'margin'=>array('top'=>'0','bottom'=>'1rem'))))));
}
foreach(range(1,6) as $level)$add('heading level '.$level,array(array_merge($heading,array('level'=>$level))));
foreach(array('left','center','right') as $align)$add('buttons '.$align,array($heading,$wrap(array_merge($button,array('style'=>$typography+array('padding'=>'1rem','borderRadius'=>'4px'))),array('justifyContent'=>$align,'gap'=>'1rem'))));
$add('button gradient',array($heading,$wrap(array_merge($button,array('style'=>array('gradient'=>'linear-gradient(90deg,#123456,#abcdef)','color'=>'#ffffff','fontSize'=>'clamp(1rem,2vw,2rem)'))))));
$add('columns gap and column styles',array($heading,array('type'=>'columns','style'=>array('gap'=>'clamp(1rem,2vw,2rem)'),'children'=>array(array('type'=>'column','style'=>array('color'=>'#123456','background'=>'#abcdef','padding'=>'1rem'),'children'=>array($paragraph)),array('type'=>'column','style'=>array('gradient'=>'linear-gradient(90deg,#123456,#abcdef)','padding'=>array('left'=>'2rem')),'children'=>array($heading))))));
foreach(array('left','center','right','stretch') as $align)$add('group '.$align,array($heading,$paragraph),array('color'=>'#123456','background'=>'#abcdef','padding'=>'1rem','gap'=>'1rem','minHeight'=>'30vh','justifyContent'=>$align,'verticalAlignment'=>'bottom'));
$add('constrained group',array($heading,$paragraph),array('contentWidth'=>'36rem','gap'=>'1rem'));
$add('constrained layout geometry',array(
    array_merge($heading,array('style'=>array('margin'=>0,'align'=>'center'))),
    array_merge($paragraph,array('style'=>array('margin'=>array('bottom'=>'1rem'),'align'=>'center'))),
    $wrap($button,array('justifyContent'=>'center')),
),array('padding'=>'1rem','contentWidth'=>'36rem','gap'=>'1.5rem'));
$add('scoped reconstruction',array(array('type'=>'group',
    'style'=>array('justifyContent'=>'stretch','gap'=>'1.5rem'),
    'children'=>array(
        $heading,
        $paragraph,
        $wrap(array_merge($button,array('style'=>array('fontSize'=>'1rem','fontWeight'=>600,'lineHeight'=>1.2))),
            array('justifyContent'=>'center')),
    ),
)),array('contentWidth'=>'42rem','minHeight'=>'clamp(26.5rem, 20rem + 20vw, 38rem)'));
$add('vertically centered constrained content',array(array(
    'type'=>'group','style'=>array('contentWidth'=>'36rem','gap'=>'1rem','padding'=>0),
    'children'=>array($heading,array_merge($paragraph,array('style'=>array(
        'textTransform'=>'uppercase','padding'=>array('left'=>'clamp(0rem,6vw - 0.5rem,2rem)'),
        'margin'=>array('bottom'=>'clamp(0.5rem,2vw,1.5rem)')))),
        $wrap(array_merge($button,array('style'=>array('textTransform'=>'capitalize'))),
            array('margin'=>array('top'=>'0.5rem','bottom'=>'0')))),
)),array('justifyContent'=>'stretch','verticalAlignment'=>'center','minHeight'=>'24rem','padding'=>'1rem'));
if(syndamorph_native_full_button_width()){
    $add('fixed full width CTA',array($heading,$wrap(array_merge($button,array(
        'style'=>array('width'=>'100%','textTransform'=>'lowercase'))))),array('justifyContent'=>'stretch'));
}
$stackChild=array('type'=>'group','id'=>'stack-content','style'=>array('contentWidth'=>'44rem','gap'=>'1.5rem'),
    'children'=>array(array_merge($heading,array('id'=>'stack-heading')),array_merge($paragraph,array('id'=>'stack-copy')),$wrap($button)));
$add('constrained child in default stack',array($stackChild),array('padding'=>'4rem'));
$stackChild['style']['padding']=0;
$add('constrained child in stretched stack',array($stackChild),array('padding'=>'4rem','justifyContent'=>'stretch'));
$add('left aligned constrained child',array($heading),array('contentWidth'=>'25rem','contentAlignment'=>'left','padding'=>0));
$add('empty decorated Group',array(
    array('type'=>'heading','id'=>'decorative-heading','text'=>'Welcome'),
    array('type'=>'group','id'=>'decorative-panel','style'=>array(
        'minHeight'=>'clamp(12rem,30vw,26rem)','gradient'=>'linear-gradient(200deg,#2f4f70,#14263a)'))));
$parity=$fixtures['constrained layout geometry'];
$nativeHtml=do_blocks($parity['content']);
$baseCss='*{box-sizing:border-box}body{margin:0;font:16px/1.5 system-ui,sans-serif}.wp-block-button__link{display:inline-block}.has-text-align-center{text-align:center}';
$parity['frontend']='<style>'.wp_get_global_stylesheet().
    wp_style_engine_get_stylesheet_from_context('block-supports').$baseCss.'</style>'.$nativeHtml;
$parity['flex']=array('preview'=>$fixtures['group center']['preview']);
$nativeFlex=do_blocks($fixtures['group center']['content']);
$parity['flex']['frontend']='<style>'.wp_get_global_stylesheet().
    wp_style_engine_get_stylesheet_from_context('block-supports').$baseCss.'</style>'.$nativeFlex;
$parity['columns']=array('preview'=>$fixtures['columns gap and column styles']['preview']);
$nativeColumns=do_blocks($fixtures['columns gap and column styles']['content']);
$columnCss=file_get_contents(ABSPATH.WPINC.'/blocks/columns/style.min.css');
$parity['columns']['frontend']='<style>'.wp_get_global_stylesheet().$columnCss.
    wp_style_engine_get_stylesheet_from_context('block-supports').$baseCss.'</style>'.$nativeColumns;

$coreButtonCss=file_get_contents(ABSPATH.WPINC.'/blocks/button/style.min.css');
foreach(array('vertically centered constrained content'=>'hybrid','fixed full width CTA'=>'fullWidth') as $fixture=>$key) {
    if(!isset($fixtures[$fixture]))continue;
    $case=$fixtures[$fixture];
    $frontend=do_blocks($case['content']);
    $parity[$key]=array('preview'=>$case['preview'],'frontend'=>'<style>'.wp_get_global_stylesheet().
        wp_style_engine_get_stylesheet_from_context('block-supports').$coreButtonCss.$baseCss.'</style>'.$frontend);
}

if(method_exists('WP_Theme_JSON','get_viewport_media_queries') &&
    wp_is_block_theme() && syndamorph_native_full_button_width()) {
    // Probe core's responsive dimensions directly. The rule targets the
    // wrapper, so it cannot replace an intrinsic anchor with a full-width CTA.
    $responsive='<!-- wp:buttons {"layout":{"type":"flex"}} --><div class="wp-block-buttons">'.
        '<!-- wp:button {"style":{"@mobile":{"dimensions":{"width":"100%"}}}} --><div class="wp-block-button">'.
        '<a class="wp-block-button__link wp-element-button" href="#rooms">See rooms</a></div><!-- /wp:button -->'.
        '</div><!-- /wp:buttons -->';
    $frontend=do_blocks($responsive);
    $parity['responsiveCoreWrapper']='<style>'.wp_get_global_stylesheet().
        wp_style_engine_get_stylesheet_from_context('block-supports').$coreButtonCss.$baseCss.'</style>'.$frontend;
}

if(is_wp_error($artifact)){http_response_code(500);exit($artifact->get_error_message());}
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html><head><meta charset="utf-8"><?php
wp_enqueue_script('wp-block-library');
wp_print_scripts(array('wp-block-library'));
?></head><body><script src="/wp-content/plugins/syndamorph/assets/production-ai-export.js"></script><script>window.nativeFixture=<?php echo wp_json_encode($artifact,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;window.nativeFixtures=<?php echo wp_json_encode($fixtures,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;window.nativeParity=<?php echo wp_json_encode($parity,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;</script></body></html>
