<?php
/** Real WordPress compiler and network-isolated Chromium. */
require '/var/www/html/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
$activated=activate_plugin('syndamorph/syndamorph.php');
if(is_wp_error($activated))throw new RuntimeException($activated->get_error_message());

$expect=static function(bool $passed,string $message):void {
    if(!$passed)throw new RuntimeException($message);
};
$cards=array_map(static fn($name)=>array('type'=>'group','children'=>array(
    array('type'=>'heading','level'=>3,'text'=>$name),array('type'=>'paragraph','text'=>'Independent card copy.'))),
    array('LAYR','THE LOUPE','TOP OF TAAFFE'));
$strip=array('type'=>'group','id'=>'dining-cards','scrollStrip'=>true,
    'style'=>array('visibility'=>'compact'),'children'=>$cards);
$make=static fn($node)=>array('version'=>1,'blocks'=>array(array('type'=>'group',
    'style'=>array('justifyContent'=>'stretch'),'children'=>array(array('type'=>'heading','text'=>'Dining'),$node))));
$artifact=syndamorph_compile_block_plan(wp_json_encode($make($strip)));
$expect(!is_wp_error($artifact),'Native scroll plan did not compile.');
$expect($artifact['previewRendering']==='wordpress-core'&&$artifact['scrollStrips']['dining-cards']['cards']===3,
    'The artifact did not preserve the native scroll component and its cards.');
$expect(str_contains(do_blocks($artifact['content']),'tabindex="0"'),'WordPress did not render a keyboard target.');
foreach(array('desktop','other') as $visibility){
    $wrong=$strip;$wrong['style']['visibility']=$visibility;
    $expect(is_wp_error(syndamorph_compile_block_plan(wp_json_encode($make($wrong)))),'Unscoped variant compiled.');
}
$widths=array(1440,768,599,375);
$native=$artifact['preview'];
$reference=str_replace('data-sm-node="dining-cards"','data-sm-scroll-reference="dining-cards"',$native);
$check=syndamorph_scroll_strip_verify($artifact,$widths,$reference);
$expect(($check['dining-cards']['status']??null)==='verified','Strip failed exact-width proof: '.wp_json_encode($check));
$specification=array(array('id'=>'dining-cards','kind'=>'horizontal-scroll','classification'=>'native'));
$receipt=syndamorph_tabs_verify($artifact,$widths,$specification,array(),$check);
$expect($receipt['status']==='passed','Verified strip did not pass shared independent QA.');
$expect(syndamorph_tabs_verify($artifact,$widths,array(),array(),$check)['status']!=='passed',
    'Scroll strip without an approved interaction passed.');
$invalid=str_replace('THE LOUPE','Different card copy',$reference);
$changed=syndamorph_scroll_strip_verify($artifact,$widths,$invalid);
$expect($changed['dining-cards']['status']==='failed','Changed card copy passed functional QA.');
$static=$artifact;
$static['preview'].='<style>.wp-block-group.sm-scroll-strip{overflow-x:hidden!important}</style>';
$failed=syndamorph_scroll_strip_measure($static['preview'],$widths,$static['scrollStrips']);
$expect($failed['dining-cards']['status']==='failed','A static approximation passed wheel and keyboard QA.');
$expect(syndamorph_scroll_strip_measure($native,array(1440),$artifact['scrollStrips'])['dining-cards']['status']==='uncertain',
    'A receipt with only hidden desktop evidence passed.');
$views=syndamorph_collab_inspect($native,$widths);
$expect(!is_wp_error($views)&&syndamorph_design_responsive_findings($views)===array(),
    'Contained scroll card text caused a false horizontal clipping failure.');
$designViews=syndamorph_collab_inspect($reference,$widths);
$expect(!is_wp_error($designViews)&&in_array('unclassified-horizontal-scroll',array_column(
    syndamorph_reference_unrealizable($designViews),'capability'),true)&&
    syndamorph_reference_unrealizable($designViews,array('dining-cards'))===array(),
    'An active source sketch escaped detection or a correctly classified strip was held.');
$post=wp_insert_post(wp_slash(array('post_type'=>'page','post_status'=>'draft','post_author'=>1,
    'post_title'=>'SyndaMorph Scroll Strip QA','post_content'=>$artifact['content'])),true);
if(is_wp_error($post))throw new RuntimeException($post->get_error_message());
echo 'PASS: native scroll strips, exact viewport gestures and content preservation. Draft '.$post.PHP_EOL;
