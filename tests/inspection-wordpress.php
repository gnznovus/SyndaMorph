<?php
/** Read-only local/CI integration of real WordPress HTTP, renderer and Chromium. */
if (PHP_SAPI !== 'cli') exit;
require '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR.'/syndamorph/includes/production-collaboration.php';

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
remove_filter('http_response',$inject,10);

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
echo wp_json_encode(array('renderer'=>$ready,'responses'=>$responses,'heavyScreenshotBytes'=>$shots,
    'comparisonUploadBase64Bytes'=>2*array_sum($shots[0]),'phpPeakBytes'=>memory_get_peak_usage(true),
    'recoveryCases'=>array('reordered','missing','unrecoverable'),'verifiedInstalledFont'=>$testedFont)).PHP_EOL;
