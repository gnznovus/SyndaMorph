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
$matrix=static function(string $name,int $count):array {
    $columns=array();
    for($column=0;$column<$count;$column++){
        $tiles=array();
        for($row=0;$row<2;$row++)$tiles[]=array('type'=>'group','id'=>"$name-$row-$column",
            'style'=>array('background'=>($row+$column)%2?'#d7d3d3':'#eae7e7','minHeight'=>'100px'));
        $columns[]=array('type'=>'column','id'=>"$name-column-$column",'children'=>$tiles);
    }
    return array('type'=>'columns','id'=>$name,'style'=>array('gap'=>'8px'),'children'=>$columns);
};
$gridPlan=$make($matrix('desktop-grid',4));
$gridPlan['blocks'][0]['id']='grid-section';
$gridPlan['blocks'][0]['children'][0]['id']='grid-heading';
$gridPlan['blocks'][0]['children'][]=$matrix('compact-grid',2);
$gridPlan['blocks'][0]['children'][]=array('type'=>'paragraph','id'=>'below-grid','text'=>'After the tiles',
    'style'=>array('textDecoration'=>'underline'));
foreach(array('desktop-grid'=>4,'compact-grid'=>2) as $id=>$count){
    $original=syndamorph_collab_find($gridPlan['blocks'],$id);
    $authority=array('id'=>$id,'mode'=>'structure','nodes'=>array_column(syndamorph_surgery_context($original,121),'id'));
    $proposal=array('squareGrid'=>$id);
    $expect(is_wp_error(syndamorph_surgery_operation($gridPlan,$proposal,array_replace($authority,array('mode'=>'style')))),
        'Style authority allowed structure changes.');
    $partial=$authority;
    array_pop($partial['nodes']);
    $expect(is_wp_error(syndamorph_surgery_operation($gridPlan,$proposal,$partial)),'Grid repair moved an unauthorized tile.');
    $mixed=$proposal+array('changes'=>array(array('target'=>$id,'path'=>'style.gap','value'=>'0px')));
    $expect(is_wp_error(syndamorph_surgery_operation($gridPlan,$mixed,$authority)),'Grid repair accepted a second operation.');
    $nonDecorative=$gridPlan;
    $node=&syndamorph_collab_find($nonDecorative['blocks'],$id);
    $node['children'][0]['children'][0]['children']=array(array('type'=>'paragraph','text'=>'Required original copy'));
    unset($node);
    $expect(is_wp_error(syndamorph_surgery_operation($nonDecorative,$proposal,$authority)),'Grid repair accepted content.');
    $result=syndamorph_surgery_operation($gridPlan,$proposal,$authority);
    $expect(!is_wp_error($result),'Authorized grid repair failed: '.(is_wp_error($result)?$result->get_error_message():''));
    $gridPlan=$result['plan'];
    $node=&syndamorph_collab_find($gridPlan['blocks'],$id);
    $expectedOrder=array();
    for($row=0;$row<2;$row++)for($column=0;$column<$count;$column++)$expectedOrder[]="$id-$row-$column";
    $expect($node['squareGrid']===$count&&array_column($node['children'],'id')===$expectedOrder,
        'Grid repair changed tile identities or visual order.');
    $node['style']['visibility']=$count===4?'desktop':'compact';
    foreach($node['children'] as &$tile)$tile['style']['minHeight']='clamp(1px, 20vw, 100px)';
    unset($tile);
    unset($node);
}
$badGrid=$gridPlan;
$badGrid['blocks'][0]['children'][1]['squareGrid']='4';
$expect(is_wp_error(syndamorph_compile_block_plan(wp_json_encode($badGrid))),'Grid accepted an invalid column count type.');
$badGrid=$gridPlan;
$badGrid['blocks'][0]['children'][1]['children'][0]['style']['width']='100%';
$expect(is_wp_error(syndamorph_compile_block_plan(wp_json_encode($badGrid))),'Grid accepted unsupported tile styles.');
$grid=syndamorph_compile_block_plan(wp_json_encode($gridPlan));
$expect(!is_wp_error($grid)&&$grid['previewRendering']==='wordpress-core','Square Grid did not use the WordPress renderer.');
$expect(str_contains($grid['content'],'"textDecoration":"underline"')&&
    str_contains(do_blocks($grid['content']),'text-decoration:underline'),'Native underline was lost.');
$gridViews=syndamorph_collab_inspect($grid['preview'],array(1440,768,375));
$expect(!is_wp_error($gridViews),'Square Grid could not be inspected at the required widths.');
foreach($gridViews as $view){
    $nodes=array_column($view['snapshot']['elements'],null,'node');
    $desktop=$view['width']===1440;
    $id=$desktop?'desktop-grid':'compact-grid';
    $count=$desktop?4:2;
    $inactive=$desktop?'compact-grid':'desktop-grid';
    $expect($nodes[$id]['visible']&&!$nodes[$inactive]['visible'],'Wrong native Grid variant at '.$view['width'].' px.');
    [$x,$y,$width,$height]=$nodes[$id]['rect'];
    [,$firstY,$firstWidth,$firstHeight]=$nodes["$id-0-0"]['rect'];
    [$lastX,$lastY,$lastWidth,$lastHeight]=$nodes["$id-1-".($count-1)]['rect'];
    $expect($width>$view['width']*0.7&&abs($firstWidth-$firstHeight)<1&&
        abs($lastWidth-$lastHeight)<1&&abs($lastY-$firstY-$firstHeight-8)<1&&
        abs($lastX+$lastWidth-$x-$width)<1&&abs($lastY+$lastHeight-$y-$height)<1&&
        $nodes['below-grid']['rect'][1]>=$y+$height-0.1,
        'Square Grid failed to contain its rows and keep following content below at '.$view['width'].' px.');
}

$flowPlan=$gridPlan;
$flowPlan['blocks'][0]['style']=array('flow'=>true,'gap'=>0);
$flowPlan['blocks'][0]['children'][0]=array('type'=>'group','id'=>'grid-header',
    'style'=>array('flow'=>true,'gap'=>0),'children'=>array(array('type'=>'columns','id'=>'grid-header-row',
        'style'=>array('gap'=>'32px','margin'=>array('top'=>0,'bottom'=>0)),'children'=>array(
            array('type'=>'column','id'=>'grid-header-title','style'=>array('width'=>'720px'),'children'=>array(
                array('type'=>'heading','id'=>'grid-heading','text'=>'Dining','style'=>array('margin'=>0)),
                array('type'=>'paragraph','id'=>'grid-description','text'=>'Editable introduction','style'=>array('margin'=>0)))),
            array('type'=>'column','id'=>'grid-header-label','style'=>array('verticalAlignment'=>'bottom'),'children'=>array(
                array('type'=>'paragraph','id'=>'grid-label','text'=>'Editable label','style'=>array('margin'=>0))))))));
$flow=syndamorph_compile_block_plan(wp_json_encode($flowPlan));
$expect(!is_wp_error($flow),'Native Flow and Columns did not compile.');
$flowViews=syndamorph_collab_inspect($flow['preview'],array(1440));
$expect(!is_wp_error($flowViews),'Native Flow could not be inspected.');
$nodes=array_column($flowViews[0]['snapshot']['elements'],null,'node');
[$titleX,$titleY,$titleWidth,$titleHeight]=$nodes['grid-header-title']['rect'];
[$labelX,$labelY,,$labelHeight]=$nodes['grid-header-label']['rect'];
$expect(abs($titleWidth-720)<1&&abs($labelX-$titleX-$titleWidth-32)<1&&
    abs($labelY+$labelHeight-$titleY-$titleHeight)<1&&
    $nodes['desktop-grid']['rect'][1]>=$titleY+$titleHeight-0.1,
    'Flow did not keep the fixed lead column, bottom-aligned label and following Grid in vertical order.');
$flowPlan['blocks'][0]['style']['justifyContent']='center';
$expect(is_wp_error(syndamorph_compile_block_plan(wp_json_encode($flowPlan))),'Flow accepted incompatible flex alignment.');
$flowPlan['blocks'][0]['style']=array('flow'=>true,'gap'=>0);
$flowPlan['blocks'][0]['children'][1]['style']['flow']=true;
$expect(is_wp_error(syndamorph_compile_block_plan(wp_json_encode($flowPlan))),'Decorative Grid accepted incompatible Flow layout.');
$rowPlan=$make(array('type'=>'group','id'=>'native-labels','style'=>array('row'=>true,'gap'=>'24px','justifyContent'=>'right'),
    'children'=>array(
        array('type'=>'paragraph','id'=>'native-label-first','text'=>'Through Our Lens →','style'=>array('margin'=>0)),
        array('type'=>'paragraph','id'=>'native-label-second','text'=>'Follow on Instagram ↗','style'=>array('margin'=>0)))));
$rowArtifact=syndamorph_compile_block_plan(wp_json_encode($rowPlan));
$expect(!is_wp_error($rowArtifact),'Non-interactive labels did not compile to a native Row.');
$rowViews=syndamorph_collab_inspect($rowArtifact['preview'],array(1440,375));
$expect(!is_wp_error($rowViews),'Native Row could not be inspected.');
foreach($rowViews as $view){
    $labels=array_column($view['snapshot']['elements'],null,'node');
    $first=$labels['native-label-first'];
    $second=$labels['native-label-second'];
    $expect($first['href']===null&&$second['href']===null&&
        $first['rect'][1]===$second['rect'][1]&&abs($second['rect'][0]-$first['rect'][0]-$first['rect'][2]-24)<1,
        'Native Row moved its second label or silently attached a link.');
}
$rowPlan['blocks'][0]['children'][1]['style']['flow']=true;
$expect(is_wp_error(syndamorph_compile_block_plan(wp_json_encode($rowPlan))),'Row accepted an incompatible Flow layout.');

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
