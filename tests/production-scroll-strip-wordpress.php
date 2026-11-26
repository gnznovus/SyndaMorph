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

$target=str_repeat('a',24);
$component='scroll-'.$target;
$bound=str_replace('dining-cards',$component,$native);
$boundViews=syndamorph_scope_inspect($bound,'section');
$expect(!is_wp_error($boundViews),'Bound source proof could not be inspected.');
$copy=array('LAYR Independent card copy.','THE LOUPE Independent card copy.','TOP OF TAAFFE Independent card copy.');
$digest=hash('sha256',wp_json_encode($copy));
$source=array('id'=>str_repeat('b',24),'sourceRef'=>'section','label'=>'Dining','kind'=>'content','variants'=>array());
$archive=array('version'=>1,'format'=>'singlefile-2.27.3','sourceUrl'=>'https://source.invalid/',
    'runtimeSha256'=>hash('sha256','runtime'),'views'=>array());
$identity=array(1,$archive['format'],$archive['sourceUrl']);
foreach($boundViews as $view){
    $width=$view['width'];
    $controls=$width===1440?array():array(array('type'=>'horizontal-scroll','name'=>'','strip'=>array(
        'version'=>1,'targetId'=>$target,'cards'=>3,'copySha256'=>$digest,'cardCopy'=>$copy)));
    $source['variants'][$width]=array('sourceRef'=>'section','html'=>$bound,'screenshot'=>$view['screenshot'],
        'bounds'=>array('width'=>$width,'height'=>$view['extent']),'facts'=>array('copy'=>$view['snapshot']['copy']),
        'inventory'=>array('text'=>array($view['snapshot']['copy']),'controls'=>$controls,'links'=>array(),'assets'=>array()));
    $proof=array('width'=>$width,'viewportHeight'=>900,'scrollWidth'=>$width,'pageHeight'=>900,'pixelDifferenceRatio'=>0,
        'javascriptEnabled'=>false,'verified'=>true,'externalRequests'=>0,'copySha256'=>hash('sha256','copy-'.$width));
    $values=array($width);
    foreach(array('sourceDOMSha256','sourceScreenshotSha256','archiveSha256','replayScreenshotSha256') as $field)
        $values[]=$proof[$field]=hash('sha256',$field.$width);
    $identity[]=$values;
    $archive['views'][]=$proof;
}
$identity[]=$archive['runtimeSha256'];
$archive['id']=hash('sha256',wp_json_encode($identity,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$expect(syndamorph_source_blockers($source)!==array()&&syndamorph_source_blockers($source,$archive)===array(),
    'An uncertified scroll was admitted or an exact scoped strip remained blocked.');
$markup=syndamorph_source_verify_markup($source,$boundViews);
$expect($markup['passed'],'Exact source-bound card copy and component failed: '.wp_json_encode($markup));
$tamperedViews=$boundViews;
$tamperedViews[2]['snapshot']['literal']['scrollStrips'][0]['copySha256']=str_repeat('c',64);
$expect(!syndamorph_source_verify_markup($source,$tamperedViews)['passed'],'Wrong archived ordered card copy passed source QA.');
$tamperedViews=$boundViews;
$tamperedViews[2]['snapshot']['literal']['scrollStrips']=array();
$expect(!syndamorph_source_verify_markup($source,$tamperedViews)['passed'],'A static variant passed source QA.');
$tamperedSource=$source;
$tamperedSource['variants'][375]['inventory']['controls'][0]['strip']['targetId']=str_repeat('d',24);
$expect(syndamorph_source_blockers($tamperedSource,$archive)!==array(),'A different compact target passed source admission.');

putenv('SYNDAMORPH_BACKEND_URL=http://backend:3100/capture');
putenv('SYNDAMORPH_BACKEND_TOKEN=offline-scroll-fixture');
$missingTouch=static fn($prior,$args,$url)=>$url==='http://backend:3100/production-touch'?
    new WP_Error('fixture_no_replay','Recorded movement unavailable.'):$prior;
add_filter('pre_http_request',$missingTouch,10,3);
$events=array();
$engineSource=syndamorph_scope_engine_source($source,$archive['sourceUrl'])+array('referenceArchive'=>$archive);
try {
    $held=syndamorph_collab_run('Keep the editable dining copy and scroll behavior.',false,
        static function($event,$payload)use(&$events){$events[]=$event;},false,$engineSource);
} finally { remove_filter('pre_http_request',$missingTouch,10); }
$expect(is_wp_error($held)&&$held->get_error_code()==='sm_source_scroll_pending'&&
    syndamorph_team_failure_payload($held)['sourceScroll']['width']===768&&!in_array('agent_start',$events,true),
    'Missing archived Touch evidence advanced to paid production or lost its Pending diagnostic.');

$post=wp_insert_post(wp_slash(array('post_type'=>'page','post_status'=>'draft','post_author'=>1,
    'post_title'=>'SyndaMorph Scroll Strip QA','post_content'=>$artifact['content'])),true);
if(is_wp_error($post))throw new RuntimeException($post->get_error_message());
echo 'PASS: native scroll strips, exact viewport gestures and content preservation. Draft '.$post.PHP_EOL;
