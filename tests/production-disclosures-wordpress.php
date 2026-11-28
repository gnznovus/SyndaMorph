<?php
/** Real WordPress compilation and independent interaction QA. */
require '/var/www/html/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
$activation=activate_plugin('syndamorph/syndamorph.php');
if(is_wp_error($activation))throw new RuntimeException($activation->get_error_message());
$expect=static function(bool $test,string $message):void {
    if(!$test)throw new RuntimeException($message);
};
$plan=array('version'=>1,'blocks'=>array(array('type'=>'group','id'=>'faq-section','children'=>array(
    array('type'=>'heading','id'=>'faq-title','text'=>'FAQ'),
    array('type'=>'group','id'=>'faq-details','disclosureGroup'=>true,'disclosureAppearance'=>'bordered','children'=>array(
        array('type'=>'details','id'=>'faq-first','text'=>'What is included?', 'open'=>true,'children'=>array(
            array('type'=>'paragraph','id'=>'faq-first-copy','text'=>'The first answer.'))),
        array('type'=>'details','id'=>'faq-second','text'=>'What is included?', 'children'=>array(
            array('type'=>'paragraph','id'=>'faq-second-copy','text'=>'A different ordered answer.'))),
        array('type'=>'details','id'=>'faq-third','text'=>'Is this editable?', 'children'=>array(
            array('type'=>'paragraph','id'=>'faq-third-copy','text'=>'Yes, without a script.'))),
    )),
))));
$artifact=syndamorph_compile_block_plan(wp_json_encode($plan));
$expect(!is_wp_error($artifact),is_wp_error($artifact)?$artifact->get_error_message():'');
$expect($artifact['previewRendering']==='wordpress-core'&&$artifact['blockCount']===9&&
    substr_count($artifact['content'],'<!-- wp:details')===3&&
    substr_count($artifact['content'],'"showContent":true')===1,'Details compilation changed native structure.');
$widths=array(1440,768,375);
$proof=syndamorph_disclosures_verify($artifact,$widths);
$expect(($proof['faq-details']['status']??null)==='verified','Chromium could not prove the Details behavior: '.wp_json_encode($proof));
$reference=str_replace('data-sm-node="faq-details"','data-sm-disclosure-reference="faq-details"',$artifact['preview']);
$expect(syndamorph_disclosures_verify($artifact,$widths,$reference)['faq-details']['status']==='verified',
    'The approved design and native artifact did not pass the same independent proof.');
$interaction=array(array('id'=>'faq-details','kind'=>'disclosure-group','classification'=>'native'));
$functional=syndamorph_tabs_verify($artifact,$widths,$interaction,array(),$proof);
$expect($functional['status']==='passed'&&syndamorph_functional_acceptance(
    array('artifact'=>$artifact,'quality'=>array('functionalChecks'=>$functional)),hash('sha256',$artifact['content'])),
    'Verified native disclosure proof did not reach the implementation gate.');
$expect(syndamorph_tabs_verify($artifact,$widths,array(),array(),$proof)['status']!=='passed'&&
    !syndamorph_functional_acceptance(array('artifact'=>$artifact),hash('sha256',$artifact['content'])),
    'An unclassified or unverified disclosure bypassed independent QA.');
$wrongDesign=str_replace('A different ordered answer.','Incorrect hidden content.',$reference);
$expect(syndamorph_disclosures_verify($artifact,$widths,$wrongDesign)['faq-details']['status']==='failed',
    'A mismatched approved reference accepted different collapsed content.');
$swapped=$artifact;
[$swapped['disclosureGroups']['faq-details'][0],$swapped['disclosureGroups']['faq-details'][1]]=
    [$swapped['disclosureGroups']['faq-details'][1],$swapped['disclosureGroups']['faq-details'][0]];
$expect(syndamorph_disclosures_verify($swapped,$widths)['faq-details']['status']==='failed',
    'Identical questions hid reordered answer copy.');
$uninteractive=$artifact;
$uninteractive['preview']=preg_replace('/\bname="sm-disclosure-instance-[^"]+"/','',$artifact['preview']);
$expect(syndamorph_disclosures_verify($uninteractive,$widths)['faq-details']['status']==='failed',
    'Independent toggles passed the exclusive Details contract.');
$repeat=do_blocks($artifact['content']."\n".$artifact['content']);
preg_match_all('/<details[^>]+name="([^"]+)"/',$repeat,$names);
$expect(count($names[1])===6&&count(array_unique($names[1]))===2&&
    $names[1][0]===$names[1][2]&&$names[1][3]===$names[1][5],'Repeated Patterns share native open state.');
$wrong=$plan;
$wrong['blocks'][0]['children'][1]['children'][1]['open']=true;
$expect(is_wp_error(syndamorph_compile_block_plan(wp_json_encode($wrong))),'Multiple authored open panels compiled.');
$wrong=$plan;
unset($wrong['blocks'][0]['children'][1]['disclosureGroup']);
$expect(is_wp_error(syndamorph_compile_block_plan(wp_json_encode($wrong))),'Standalone Details bypassed the group contract.');

$samples=syndamorph_scope_inspect($artifact['preview'],'section');
$expect(!is_wp_error($samples),'Grouped Details source evidence could not be measured.');
foreach($samples as $sample){
    $font=$sample['width']===1440?'20px':'16px';
    $summaries=array_filter($sample['snapshot']['elements'],static fn($el)=>$el['tag']==='SUMMARY'&&$el['visible']);
    $sizes=array_values(array_unique(array_column(array_column($summaries,'styles'),'fontSize')));
    $expect(count($summaries)===3&&$sizes===array($font),
        'The saved bordered appearance did not render responsive disclosure typography.');
}
$source=array('id'=>str_repeat('a',24),'sourceRef'=>'[id="faq-source"]','label'=>'FAQ','variants'=>array());
$archive=array('version'=>1,'format'=>'singlefile-2.27.3','sourceUrl'=>'https://fixture.invalid/faq',
    'runtimeSha256'=>hash('sha256','recorded fixture runtime'),'views'=>array());
$identity=array(1,$archive['format'],$archive['sourceUrl']);
foreach($samples as $view){
    $width=$view['width'];$literal=$view['snapshot']['literal'];
    $source['variants'][$width]=array('sourceRef'=>$source['sourceRef'],'html'=>$reference,
        'screenshot'=>$view['screenshot'],'bounds'=>array('height'=>$view['extent']),
        'inventory'=>array('text'=>$literal['text'],'links'=>array(),'controls'=>array_fill(0,3,array('type'=>'button')),
            'assets'=>array_fill(0,3,array('type'=>'svg'))),'facts'=>array('copy'=>$view['snapshot']['copy']));
    $trace=array('width'=>$width,'viewportHeight'=>900,'scrollWidth'=>$width,'pageHeight'=>$view['extent'],
        'verified'=>true,'javascriptEnabled'=>false,'externalRequests'=>0,'pixelDifferenceRatio'=>0,
        'copySha256'=>hash('sha256',$view['snapshot']['copy']));
    $fingerprint=array($width);
    foreach(array('sourceDOMSha256','sourceScreenshotSha256','archiveSha256','replayScreenshotSha256') as $field)
        $fingerprint[]=$trace[$field]=hash('sha256',$field.$width);
    $identity[]=$fingerprint;
    $archive['views'][]=$trace;
}
$identity[]=$archive['runtimeSha256'];
$archive['id']=hash('sha256',wp_json_encode($identity,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$evidence=array('archiveId'=>$archive['id'],'sourceSha256'=>hash('sha256',wp_json_encode($source)),'views'=>array());
foreach($archive['views'] as $view){
    $width=$view['width'];$panels=$artifact['disclosureGroups']['faq-details'];
    foreach($panels as $index=>&$panel)$panel['targetId']=substr(hash('sha256',
        wp_json_encode(array($source['sourceRef'],$index,$panel['summary']),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),0,24);
    unset($panel);
    $clicks=array();
    foreach(array(1,2,0,0) as $index=>$target)$clicks[]=array('index'=>$target,'targetId'=>$panels[$target]['targetId'],
        'open'=>$index===3?array():array($target),'fromSha256'=>hash('sha256','state-'.$index),
        'toSha256'=>hash('sha256','state-'.($index+1)));
    $evidence['views'][$width]=array('archiveId'=>$archive['id'],'mode'=>'recorded','status'=>'observed','width'=>$width,
        'sourceRef'=>$source['sourceRef'],'sourceCopySha256'=>$view['copySha256'],'indicator'=>'chevron-20','initial'=>array(0),
        'blockedRequestCount'=>0,'keyboard'=>'unverified','externalEffects'=>'unverified','panels'=>$panels,'clicks'=>$clicks);
}
$expect(syndamorph_source_blockers($source,$archive)!==array(),'Generic buttons and SVG assets were admitted without replay.');
$source['disclosureEvidence']=$evidence;
$expect(syndamorph_source_blockers($source,$archive)===array(),'Exact recorded disclosure evidence did not expand source eligibility.');
$badEvidence=$source;
unset($badEvidence['disclosureEvidence']['views'][375]);
$expect(syndamorph_source_blockers($badEvidence,$archive)!==array(),'Incomplete compact replay admitted a disclosure.');
$badEvidence=$source;
$badEvidence['disclosureEvidence']['views'][768]['clicks'][3]['open']=array(0);
$expect(syndamorph_source_blockers($badEvidence,$archive)!==array(),'A changed open-state transition admitted a disclosure.');
foreach($samples as &$sample)$sample['snapshot']['literal']['disclosures'][0]['id']='disclosure-'.$source['id'];
unset($sample);
$expect(syndamorph_source_verify_markup($source,$samples)['passed'],'Pinned source gate rejected matching closed and open panel evidence.');
$samples[2]['snapshot']['literal']['disclosures'][0]['panels'][1]['answer']='Changed closed answer.';
$expect(!syndamorph_source_verify_markup($source,$samples)['passed'],'Wrong collapsed copy passed the pinned source gate.');
echo 'PASS: native Details, ordered copy, exclusive clicks, keyboard, indicator, and repeated Pattern isolation.'.PHP_EOL;
