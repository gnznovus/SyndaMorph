<?php
/** Read-only local/CI integration of real WordPress HTTP, renderer and Chromium. */
if (PHP_SAPI !== 'cli') exit;
require '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR.'/syndamorph/includes/production-collaboration.php';
require_once WP_PLUGIN_DIR.'/syndamorph/includes/production-block-plan.php';

function inspection_assert(bool $passed,string $message): void {
    if(!$passed)throw new RuntimeException($message);
}
function inspection_require_result($result) {
    if(is_wp_error($result))throw new RuntimeException($result->get_error_code().' '.
        wp_json_encode(syndamorph_inspection_diagnostic($result)));
    return $result;
}

$responses=array();
add_action('http_api_debug',static function($response,$context,$class,$args,$url)use(&$responses){
    if($context!=='response'||!str_ends_with($url,'/inspect')||is_wp_error($response))return;
    $body=wp_remote_retrieve_body($response);
    $responses[]=array('bytes'=>strlen($body),'declared'=>(int)wp_remote_retrieve_header($response,'content-length'),
        'status'=>wp_remote_retrieve_response_code($response),'build'=>wp_remote_retrieve_header($response,'x-syndamorph-renderer-build'));
},10,5);

$default=array(1440,768,375);
$ready=inspection_require_result(syndamorph_inspection_ready($default));
$ordinary='<section><h2>Inspection fixture</h2><p>Local synthetic example.</p><img src="about:blank"></section>';
foreach(array($default,array(375,820,1680),$default) as $widths) {
    $samples=inspection_require_result(syndamorph_production_tool('designer','playwright.inspect',array(
        'html'=>$ordinary,'viewports'=>$widths,
    )));
    inspection_assert(array_column($samples,'width')===$widths,'Inspections did not preserve requested viewport order');
    foreach($samples as $sample) {
        inspection_assert(in_array('image-alt',array_column($sample['a11y'],'id'),true),'Real axe evidence missing');
        inspection_assert($sample['snapshot']['overflow']===false,'Valid ordinary fixture reported overflow');
    }
}

// Deterministic high-entropy pixels exercise the screenshot and upload caps.
$texture='<style>body{margin:0}</style><svg width="100%" height="900" aria-label="Texture" role="img"><filter id="noise"><feTurbulence baseFrequency=".88" numOctaves="3" stitchTiles="stitch" seed="42"/></filter><rect width="100%" height="900" filter="url(#noise)"/></svg>';
$wide=array(1920,1840,1760,1680,1600);
$shots=array();
for($i=0;$i<2;$i++) {
    $samples=inspection_require_result(syndamorph_collab_inspect($texture,$wide));
    $lengths=array_map('strlen',array_column($samples,'screenshot'));
    inspection_assert(count($lengths)===5&&max($lengths)<=SYNDAMORPH_INSPECTION_SCREENSHOT_BYTES,'Screenshot bound violated');
    inspection_assert(2*array_sum($lengths)>2*1024*1024,'Heavy fixture did not exercise the old comparison upload cap');
    $shots[]=$lengths;
}
$comparison=inspection_require_result(syndamorph_collab_compare($samples,$samples));
inspection_assert(array_column($comparison,'width')===$wide&&
    array_sum(array_column($comparison,'pixelDifferenceRatio'))===0,'Large evidence comparison is not lossless');

inspection_assert(count($responses)===5,'Unexpected transport retry during normal sequential inspections');
foreach($responses as $response) {
    inspection_assert($response['bytes']===$response['declared']&&$response['bytes']<=SYNDAMORPH_INSPECTION_RESPONSE_BYTES&&
        $response['status']===200&&$response['build']===$ready['build'],'Evidence transport framing or renderer build changed');
}

// Exercise recovery with otherwise real Chromium results. The filter mutates
// only the first successful WordPress response in each individual test.
$receivedRequests=array();
$fault=null;
$inject=static function($response,$args,$url)use(&$fault,&$receivedRequests){
    if(!str_ends_with($url,'/inspect'))return $response;
    $input=json_decode($args['body'],true);
    $receivedRequests[]=$input['viewports'];
    if($fault===null)return $response;
    $payload=json_decode($response['body'],true);
    if($fault==='reorder')$payload['evidence']=array_reverse($payload['evidence']);
    elseif($fault==='missing')array_pop($payload['evidence']);
    elseif($fault==='unrecoverable')$payload['evidence']=array_values(array_filter($payload['evidence'],
        static fn($sample)=>$sample['width']!==375));
    elseif($fault==='raster')unset($payload['evidence'][0]['rasterProfile']);
    if($fault!=='unrecoverable')$fault=null;
    $response['body']=wp_json_encode($payload);
    $response['headers']['content-length']=(string)strlen($response['body']);
    return $response;
};
add_filter('http_response',$inject,10,3);
$fault='reorder';
$reordered=inspection_require_result(syndamorph_collab_inspect($ordinary,$default));
inspection_assert(array_column($reordered,'width')===$default && $receivedRequests===array($default),'Reordering required another browser pass');
$fault='missing';$receivedRequests=array();
$recovered=inspection_require_result(syndamorph_collab_inspect($ordinary,$default));
inspection_assert(array_column($recovered,'width')===$default&&$receivedRequests===array($default,array(375)),
    'Recovery did not inspect just the missing viewport');
$fault='unrecoverable';$receivedRequests=array();
$pending=syndamorph_collab_inspect($ordinary,$default);
inspection_assert(is_wp_error($pending)&&syndamorph_inspection_diagnostic($pending)['missingWidths']===array(375)&&
    $receivedRequests===array($default,array(375),array(375)),'Unrecoverable viewport was accepted or substituted');
$fault=null;
$profile=SYNDAMORPH_PAGE_RASTER_PROFILE;
$matched=inspection_require_result(syndamorph_collab_inspect($ordinary,$default,$rasterBuild,'page',null,$profile));
inspection_assert(array_column($matched,'rasterProfile')===array_fill(0,count($default),$profile),
    'Full-page raster retry did not prove its requested capture profile.');
$fault='raster';
$wrongRaster=syndamorph_collab_inspect($ordinary,array(375),$rasterBuild,'page-fold',null,$profile);
inspection_assert(is_wp_error($wrongRaster)&&$wrongRaster->get_error_code()==='sm_inspect_protocol_mismatch',
    'An unattested raster profile passed as equivalent browser evidence.');
remove_filter('http_response',$inject,10);

$replayUrl='https://recorded-page.invalid/';
$archive=array('version'=>1,'format'=>'singlefile-2.27.3','sourceUrl'=>$replayUrl,'runtimeSha256'=>str_repeat('a',64),'views'=>array());
$identity=array(1,$archive['format'],$replayUrl);
$pinned=array();
$folds=inspection_require_result(syndamorph_collab_inspect($ordinary,$default,$rasterBuild,'page-fold',null,$profile));
$dark=inspection_require_result(syndamorph_collab_inspect(
    '<style>html,body{background:#111;color:#fff}</style><main><h1>Different source replay</h1></main>',
    array(375),$rasterBuild,'page-fold',null,$profile))[0]['screenshot'];
foreach($matched as $i=>$sample){
    $width=$sample['width'];
    $view=array('width'=>$width,'viewportHeight'=>900,'scrollWidth'=>$width,'pageHeight'=>$sample['extent'],
        'pixelDifferenceRatio'=>0,'javascriptEnabled'=>false,'externalRequests'=>0,'verified'=>true,'copySha256'=>str_repeat('b',64));
    $entry=array($width);
    foreach(array('sourceDOMSha256','sourceScreenshotSha256','archiveSha256','replayScreenshotSha256') as $key)
        $entry[]=$view[$key]=hash('sha256',$key.$width);
    $archive['views'][]=$view;
    $identity[]=$entry;
    $pinned[$width]=array('width'=>$width,'height'=>$sample['extent'],'whole'=>$sample['screenshot'],'fold'=>$folds[$i]['screenshot']);
}
$identity[]=$archive['runtimeSha256'];
$archive['id']=hash('sha256',wp_json_encode($identity,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$drift=false;
$sourceReply=static function($pre,$args,$url)use(&$drift,$pinned,$archive,$profile,$dark){
    if(!str_ends_with($url,'/production-touch'))return $pre;
    $input=json_decode($args['body'],true);
    $width=$input['width'];
    $screens=array_intersect_key($pinned[$width],array('whole'=>true,'fold'=>true));
    $original=$screens;
    if($drift&&$width===375)$original['fold']=$dark;
    return array('response'=>array('code'=>200),'body'=>wp_json_encode(array(
        'profile'=>$profile,'archiveId'=>$archive['id'],'copySha256'=>str_repeat('b',64),'stateSha256'=>str_repeat('c',64),
        'width'=>$width,'height'=>$pinned[$width]['height'],'original'=>$original,'matched'=>$screens)));
};
add_filter('pre_http_request',$sourceReply,10,3);
try{
    $manifest=array('referenceArchive'=>$archive,'sourceUrl'=>$replayUrl);
    $comparison=syndamorph_scope_page_raster_source($manifest,array_values($pinned));
    inspection_assert($comparison['status']==='verified','A matching sealed source witness was rejected.');
    $drift=true;
    $comparison=syndamorph_scope_page_raster_source($manifest,array_values($pinned));
    inspection_assert($comparison['status']==='pending'&&$comparison['reason']==='recorded_page_replay_drift'&&
        !isset($comparison['frames']),'A matched raster frame bypassed a drifting ordinary source replay.');
}finally{remove_filter('pre_http_request',$sourceReply,10);}

$origin=array(1440=>7681.890625,768=>6643.0625,375=>5662.25);
$section='<section style="height:1050.4px;background:#fff"><h2 style="margin:0;padding:40px">Captured paint</h2></section>';
$unframed=inspection_require_result(syndamorph_collab_inspect($section,$default,$unframedBuild,'section'));
$framed=inspection_require_result(syndamorph_collab_inspect($section,$default,$frameBuild,'section',$origin));
foreach($framed as $i=>$sample){
    $width=$sample['width'];
    inspection_assert((float)$sample['sourceFrame']['top']===$origin[$width]&&
        $sample['sourceFrame']['screenshotTop']===(int)round($origin[$width])&&$sample['extent']===1050&&
        $sample['snapshot']===$unframed[$i]['snapshot'],'Source framing changed measured layout or missed its exact capture origin.');
}
$changed=inspection_require_result(syndamorph_collab_inspect(str_replace('#fff','#a0182c',$section),$default,$frameBuild,'section',$origin));
$differences=inspection_require_result(syndamorph_collab_compare($framed,$changed,true));
inspection_assert(min(array_column($differences,'pixelDifferenceRatio'))>0.8,'Source framing concealed a real paint mismatch.');
$wrongOrigin=syndamorph_collab_inspect($section,$default,$frameBuild,'section',array(1440=>500));
$unshiftable=syndamorph_collab_inspect(str_replace('margin:0','position:fixed;top:0;margin:0',$section),
    array(375),$frameBuild,'section',array(375=>5662.25));
inspection_assert(is_wp_error($wrongOrigin)&&$wrongOrigin->get_error_code()==='sm_inspect_source_frame_unavailable'&&
    is_wp_error($unshiftable)&&$unshiftable->get_error_code()==='sm_inspect_source_frame_unavailable',
    'Incomplete or unmeasurable source framing passed inspection.');
$wrongReceipt=static function($response,$args,$url){
    if(!str_ends_with($url,'/inspect'))return $response;
    $data=json_decode($response['body'],true);
    $data['evidence'][0]['sourceFrame']['top']=12;
    $response['body']=wp_json_encode($data);
    $response['headers']['content-length']=(string)strlen($response['body']);
    return $response;
};
add_filter('http_response',$wrongReceipt,10,3);
try { $unproven=syndamorph_collab_inspect($section,array(375),$frameBuild,'section',array(375=>5662.25)); }
finally { remove_filter('http_response',$wrongReceipt,10); }
inspection_assert(is_wp_error($unproven)&&$unproven->get_error_code()==='sm_inspect_source_frame_unavailable',
    'A substituted source frame passed inspection.');
$frameBatches=array();
$omitFrame=static function($response,$args,$url)use(&$frameBatches){
    if(!str_ends_with($url,'/inspect'))return $response;
    $frameBatches[]=json_decode($args['body'],true)['sourceFrames']??null;
    if(count($frameBatches)>1)return $response;
    $data=json_decode($response['body'],true);
    unset($data['evidence'][1]['sourceFrame']);
    $response['body']=wp_json_encode($data);
    $response['headers']['content-length']=(string)strlen($response['body']);
    return $response;
};
add_filter('http_response',$omitFrame,10,3);
try { $reframed=inspection_require_result(syndamorph_collab_inspect($section,$default,$frameBuild,'section',$origin)); }
finally { remove_filter('http_response',$omitFrame,10); }
inspection_assert($frameBatches===array($origin,array(768=>$origin[768]))&&array_column($reframed,'width')===$default,
    'Missing source framing retried unrelated widths or substituted evidence.');

$testedFont=null;
$presets=syndamorph_production_font_families();
if($presets){
    $slug=array_key_first($presets);
    $family=$presets[$slug]['name'];
    $plan=array('version'=>1,'blocks'=>array(array('type'=>'group','id'=>'font-root','style'=>array('fontFamily'=>$slug),
        'children'=>array(array('type'=>'heading','text'=>'Editable installed typography')))));
    $native=inspection_require_result(syndamorph_compile_block_plan(wp_json_encode($plan)));
    $parsed=parse_blocks($native['content']);
    inspection_assert($parsed[0]['attrs']['fontFamily']===$slug&&!str_contains($native['content'],'data:font/'),
        'The saved native block did not preserve a portable preset reference.');
    $reference='<style>.sample{--sample-family:"'.$family.'";font-family:var(--sample-family),sans-serif}</style>'.
        '<section class="sample"><h2>Editable installed typography</h2></section>';
    foreach(array($reference,$native['preview']) as $fontHtml){
        $fontViews=inspection_require_result(syndamorph_collab_inspect($fontHtml,$default));
        foreach($fontViews as $sample)inspection_assert(in_array($family,$sample['snapshot']['installedFonts']['loaded']??array(),true),
            'The browser silently substituted the locally installed font.');
    }
    $plan['blocks'][0]['style']['fontFamily']='unregistered-fixture-preset';
    inspection_assert(is_wp_error(syndamorph_compile_block_plan(wp_json_encode($plan)))&&
        syndamorph_production_font_path('https://fonts.invalid/unchecked.woff2')===null,
        'The compiler accepted uninstalled or remote font input.');
    $dropFontEvidence=static function($response,$args,$url){
        if(!str_ends_with($url,'/inspect'))return $response;
        $data=json_decode($response['body'],true);
        unset($data['evidence'][0]['snapshot']['installedFonts']);
        $response['body']=wp_json_encode($data);
        $response['headers']['content-length']=(string)strlen($response['body']);
        return $response;
    };
    add_filter('http_response',$dropFontEvidence,10,3);
    try { $unchecked=syndamorph_collab_inspect($reference,array(375)); }
    finally { remove_filter('http_response',$dropFontEvidence,10); }
    inspection_assert(is_wp_error($unchecked)&&$unchecked->get_error_code()==='sm_inspect_font_evidence_missing',
        'A renderer response without font evidence passed inspection.');
    $testedFont=$family;
}
$host=syndamorph_design_wordpress_page_context();
if($host!==null){
    $level=$host['pageTitleLevel']+1;
    $positive='<section style="background:#fff;color:#222"><h'.$level.'>Correct hierarchy</h'.$level.'><p>Visible text</p></section>';
    $checks=array('heading'=>'host-heading','contrast'=>'host-contrast');
    $good=syndamorph_design_host_accessibility($positive,$checks,$host,$default);
    inspection_assert($good['status']==='passed'&&array_column($good['checks']['contrast']['views'],'width')===$default,
        'The host check did not evaluate the terminal WordPress Page context at all required widths.');
    $sha=hash('sha256',$positive);
    $result=array('artifact'=>array('preview'=>$positive),'quality'=>array('viewportWidths'=>$default,
        'hostAccessibility'=>$good+array('artifactSha256'=>$sha)),
        'designApproval'=>array('productionSpecification'=>array('requirements'=>array(
            array('id'=>'heading','check'=>array('kind'=>'host-heading')),array('id'=>'contrast','check'=>array('kind'=>'host-contrast'))))));
    inspection_assert(syndamorph_design_host_acceptance($result,$sha),'Verified host evidence did not permit the exact artifact.');
    $result['quality']['hostAccessibility']['checks']['contrast']['views']=array_slice($good['checks']['contrast']['views'],0,2);
    inspection_assert(!syndamorph_design_host_acceptance($result,$sha)&&
        !syndamorph_design_host_acceptance($result,hash('sha256','changed')),
        'Missing viewport evidence or a different artifact passed host acceptance.');
    $bad=str_replace(array('h'.$level,'#222'),array('h'.$host['pageTitleLevel'],'#aaa'),$positive);
    $failed=syndamorph_design_host_accessibility($bad,$checks,$host,$default);
    inspection_assert($failed['status']==='failed'&&array_column($failed['checks'],'status')===array('failed','failed'),
        'An invalid heading hierarchy or low contrast passed the default Page evidence check.');
    $ambiguous=str_replace('background:#fff','background:linear-gradient(#fff,#000)',$positive);
    $pending=syndamorph_design_host_accessibility($ambiguous,$checks,$host,array(375));
    inspection_assert($pending['status']==='pending'&&$pending['checks']['contrast']['status']==='uncertain',
        'An unresolved composed background was assumed to have adequate contrast.');
    $blended=syndamorph_design_host_accessibility('<div style="opacity:.35">'.$positive.'</div>',$checks,$host,array(375));
    inspection_assert($blended['status']==='pending'&&$blended['checks']['contrast']['status']==='uncertain',
        'A translucent ancestor was silently treated as an opaque background.');
}
echo wp_json_encode(array('renderer'=>$ready,'responses'=>$responses,'heavyScreenshotBytes'=>$shots,
    'comparisonUploadBase64Bytes'=>2*array_sum($shots[0]),'phpPeakBytes'=>memory_get_peak_usage(true),
    'recoveryCases'=>array('reordered','missing','unrecoverable'),'verifiedInstalledFont'=>$testedFont)).PHP_EOL;
