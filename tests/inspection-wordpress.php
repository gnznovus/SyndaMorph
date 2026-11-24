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
echo wp_json_encode(array('renderer'=>$ready,'responses'=>$responses,'heavyScreenshotBytes'=>$shots,
    'comparisonUploadBase64Bytes'=>2*array_sum($shots[0]),'phpPeakBytes'=>memory_get_peak_usage(true))).PHP_EOL;
