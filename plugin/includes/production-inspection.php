<?php
/** Versioned transport for the isolated renderer. */
if (!defined('ABSPATH')) exit;

const SYNDAMORPH_INSPECTION_PROTOCOL = 2;
const SYNDAMORPH_INSPECTION_SCREENSHOT_BYTES = 400000;
const SYNDAMORPH_INSPECTION_RESPONSE_BYTES = 3602048;
const SYNDAMORPH_INSPECTION_ATTEMPTS = 3;

function syndamorph_inspection_endpoint() {
    $endpoint=defined('SYNDAMORPH_RENDERER_INSPECT_URL')?SYNDAMORPH_RENDERER_INSPECT_URL:'http://renderer:3000/inspect';
    if(!is_string($endpoint)||!preg_match('~^http://[a-zA-Z0-9.-]+:[0-9]{2,5}/inspect$~D',$endpoint))
        return new WP_Error('sm_inspect_config','Invalid isolated renderer endpoint.');
    return $endpoint;
}

function syndamorph_inspection_failure(string $category,array $details=array()): WP_Error {
    $messages=array(
        'unavailable'=>'The isolated renderer is unavailable.',
        'busy'=>'The isolated renderer is busy.',
        'transport_timeout'=>'The request to the isolated renderer timed out.',
        'browser_timeout'=>'Chromium inspection exceeded its time limit.',
        'browser_crash'=>'Chromium or its isolated worker exited during inspection.',
        'worker_failure'=>'The isolated inspection worker failed.',
        'response_limit'=>'Inspection evidence exceeded the transport limit.',
        'truncated'=>'The renderer response ended before all evidence arrived.',
        'invalid_json'=>'The renderer returned invalid JSON.',
        'missing_evidence'=>'The renderer did not return a complete evidence list.',
        'viewport_mismatch'=>'The renderer returned different viewport widths or order.',
        'screenshot_incomplete'=>'Inspection screenshot evidence is missing, invalid, or incorrectly sized.',
        'a11y_incomplete'=>'Inspection accessibility evidence is missing or invalid.',
        'snapshot_incomplete'=>'Inspection browser measurements are missing or invalid.',
        'protocol_mismatch'=>'WordPress and the renderer use different inspection protocols. Check the renderer build.',
        'rejected'=>'The isolated renderer rejected the inspection request.',
        'request_invalid'=>'Inspection requires one to five distinct integer widths from 320 to 1920 CSS px and bounded HTML.',
    );
    return new WP_Error('sm_inspect_'.$category,$messages[$category],$details);
}

function syndamorph_inspection_http_number($value): ?int {
    if(is_int($value)&&$value>=0)return $value;
    return is_string($value)&&preg_match('/^\d{1,9}$/D',$value)?(int)$value:null;
}

function syndamorph_inspection_build($value): ?string {
    return is_string($value)&&preg_match('/^[a-f0-9]{64}$/D',$value)?$value:null;
}

/** The error envelope is a whitelist. Never copy response bodies or exception messages. */
function syndamorph_inspection_envelope($response,int $limit,array $widths) {
    $details=array('expectedWidths'=>$widths,'expectedProtocol'=>SYNDAMORPH_INSPECTION_PROTOCOL);
    if(is_wp_error($response)) {
        $message=$response->get_error_message();
        $category=preg_match('/curl error 18\b|partial file|bytes remaining|premature eof/i',$message)?'truncated':
            (preg_match('/curl error 28\b|timed out|timeout/i',$message)?'transport_timeout':'unavailable');
        return syndamorph_inspection_failure($category,$details);
    }
    $status=(int)wp_remote_retrieve_response_code($response);
    $body=wp_remote_retrieve_body($response);
    $length=syndamorph_inspection_http_number(wp_remote_retrieve_header($response,'content-length'));
    $protocol=syndamorph_inspection_http_number(wp_remote_retrieve_header($response,'x-syndamorph-inspection-protocol'));
    $build=syndamorph_inspection_build(wp_remote_retrieve_header($response,'x-syndamorph-renderer-build'));
    $details+=array('httpStatus'=>$status,'responseBytes'=>strlen($body),'declaredBytes'=>$length,
        'rendererProtocol'=>$protocol,'rendererBuild'=>$build);
    if(strlen($body)>$limit || ($length!==null&&$length>$limit))
        return syndamorph_inspection_failure('response_limit',$details);
    if($length!==null && strlen($body)<$length)
        return syndamorph_inspection_failure('truncated',$details);
    $data=json_decode($body,true,64);
    $jsonError=json_last_error();
    if($status!==200) {
        $codes=array('renderer_busy'=>'busy','browser_timeout'=>'browser_timeout','browser_crash'=>'browser_crash',
            'worker_crash'=>'browser_crash','worker_failure'=>'worker_failure','worker_invalid_json'=>'invalid_json',
            'screenshot_limit'=>'response_limit','evidence_limit'=>'response_limit','protocol_mismatch'=>'protocol_mismatch',
            'missing_evidence'=>'missing_evidence','viewport_mismatch'=>'viewport_mismatch',
            'request_limit'=>'rejected','invalid_viewports'=>'rejected','invalid_request_json'=>'rejected',
            'invalid_html'=>'rejected','not_found'=>'protocol_mismatch','invalid_renderer_input'=>'rejected');
        $rendererCode=$data['error']??null;
        if(is_string($rendererCode)&&isset($codes[$rendererCode]))$details['rendererCode']=$rendererCode;
        $category=$codes[$details['rendererCode']??'']??
            ($status===429?'busy':($status===404?'protocol_mismatch':
                ($status===504?'transport_timeout':(in_array($status,array(502,503),true)?'unavailable':'rejected'))));
        return syndamorph_inspection_failure($category,$details);
    }
    if($jsonError!==JSON_ERROR_NONE || !is_array($data)){
        $details['jsonError']=$jsonError;
        return syndamorph_inspection_failure('invalid_json',$details);
    }
    return array('data'=>$data,'details'=>$details);
}

function syndamorph_inspection_check_protocol(array $details): ?WP_Error {
    return ($details['rendererProtocol']??null)!==SYNDAMORPH_INSPECTION_PROTOCOL || empty($details['rendererBuild']) ?
        syndamorph_inspection_failure('protocol_mismatch',$details):null;
}

function syndamorph_inspection_validate($response,array $viewports,bool $recover=false) {
    $result=syndamorph_inspection_envelope($response,SYNDAMORPH_INSPECTION_RESPONSE_BYTES,$viewports);
    if(is_wp_error($result))return $result;
    $details=$result['details'];
    $evidence=$result['data']['evidence']??null;
    if(!is_array($evidence)||!array_is_list($evidence))
        return syndamorph_inspection_failure('missing_evidence',$details);
    $returned=array_map(static function($sample){
        $width=is_array($sample)?($sample['width']??null):null;
        // Retain a numeric-string width to make type errors diagnosable.
        return syndamorph_inspection_http_number($width)!==null?$width:null;
    },array_slice($evidence,0,6));
    $details['returnedWidths']=$returned;
    $details['evidenceCount']=count($evidence);
    if(!$evidence || (!$recover&&count($evidence)<count($viewports)))
        return syndamorph_inspection_failure('missing_evidence',$details);
    if(!$recover&&$returned!==$viewports)return syndamorph_inspection_failure('viewport_mismatch',$details);
    if($recover && (count($evidence)>count($viewports)||count(array_unique($returned,SORT_REGULAR))!==count($returned)||
        array_filter($returned,static fn($width)=>!in_array($width,$viewports,true))))
        return syndamorph_inspection_failure('viewport_mismatch',$details);
    $protocolError=syndamorph_inspection_check_protocol($details);
    if($protocolError)return $protocolError;
    foreach($evidence as $sample) {
        $details['viewport']=$sample['width'];
        $a11y=$sample['a11y']??null;
        if(!is_array($a11y)||!array_is_list($a11y))
            return syndamorph_inspection_failure('a11y_incomplete',$details);
        foreach($a11y as $violation)if(!is_array($violation)||!is_string($violation['id']??null)||
            $violation['id']===''||!is_array($violation['nodes']??null))
            return syndamorph_inspection_failure('a11y_incomplete',$details);
        $snapshot=$sample['snapshot']??null;
        if(!is_array($snapshot)||!is_bool($snapshot['overflow']??null)||
            (array_key_exists('copy',$snapshot) && !is_string($snapshot['copy']))||
            !is_array($snapshot['elements']??null)||!array_is_list($snapshot['elements']))
            return syndamorph_inspection_failure('snapshot_incomplete',$details);
        foreach($snapshot['elements'] as $element)if(!is_array($element)||!is_string($element['tag']??null)||
            !is_array($element['rect']??null)||count($element['rect'])!==4||!is_array($element['styles']??null))
            return syndamorph_inspection_failure('snapshot_incomplete',$details);
        $screenshot=$sample['screenshot']??null;
        $image=is_string($screenshot)&&strlen($screenshot)<=SYNDAMORPH_INSPECTION_SCREENSHOT_BYTES ?
            base64_decode($screenshot,true):false;
        $size=$image!==false && str_ends_with($image,"\xFF\xD9")?@getimagesizefromstring($image):false;
        if(!$size||$size[0]!==$sample['width']||$size[1]!==900||($size['mime']??'')!=='image/jpeg')
            return syndamorph_inspection_failure('screenshot_incomplete',$details);
    }
    return $recover?array('evidence'=>$evidence,'details'=>$details):$evidence;
}

function syndamorph_inspection_transient(WP_Error $error): bool {
    return in_array($error->get_error_code(),array('sm_inspect_busy','sm_inspect_unavailable','sm_inspect_transport_timeout',
        'sm_inspect_browser_timeout','sm_inspect_browser_crash','sm_inspect_truncated'),true);
}

function syndamorph_inspection_backoff(WP_Error $error,int $attempt): void {
    usleep(($error->get_error_code()==='sm_inspect_busy'?1000000:250000)*$attempt);
}

function syndamorph_inspection_retry(callable $request) {
    for($attempt=1;$attempt<=SYNDAMORPH_INSPECTION_ATTEMPTS;$attempt++) {
        $result=$request();
        if(!is_wp_error($result))return $result;
        if($attempt===SYNDAMORPH_INSPECTION_ATTEMPTS||!syndamorph_inspection_transient($result)) {
            return new WP_Error($result->get_error_code(),$result->get_error_message(),
                array_merge((array)$result->get_error_data(),array('attempts'=>$attempt)));
        }
        syndamorph_inspection_backoff($result,$attempt);
    }
}

function syndamorph_inspection_ready(array $viewports) {
    $endpoint=syndamorph_inspection_endpoint();
    if(is_wp_error($endpoint))return $endpoint;
    return syndamorph_inspection_retry(static function()use($endpoint,$viewports) {
        $response=wp_remote_get(substr($endpoint,0,-strlen('/inspect')).'/healthz',
            array('timeout'=>5,'redirection'=>0,'limit_response_size'=>2049));
        $result=syndamorph_inspection_envelope($response,2048,$viewports);
        if(is_wp_error($result))return $result;
        $data=$result['data'];$details=$result['details'];
        $error=syndamorph_inspection_check_protocol($details);
        if($error)return $error;
        if(($data['service']??'')!=='syndamorph-renderer'||
            ($data['inspectionProtocol']??null)!==SYNDAMORPH_INSPECTION_PROTOCOL||
            ($data['maxResponseBytes']??null)!==SYNDAMORPH_INSPECTION_RESPONSE_BYTES||
            ($data['maxScreenshotBytes']??null)!==SYNDAMORPH_INSPECTION_SCREENSHOT_BYTES||
            ($data['build']??null)!==$details['rendererBuild'])
            return syndamorph_inspection_failure('protocol_mismatch',$details);
        return array('protocol'=>$details['rendererProtocol'],'build'=>$details['rendererBuild']);
    });
}

function syndamorph_collab_inspect(string $html,array $viewports=array(1440,768,375)) {
    $endpoint=syndamorph_inspection_endpoint();
    if(is_wp_error($endpoint))return $endpoint;
    if(trim($html)===''||strlen($html)>120000||!array_is_list($viewports)||count($viewports)<1||count($viewports)>5||
        count(array_filter($viewports,static fn($width)=>is_int($width)&&$width>=320&&$width<=1920))!==count($viewports)||
        count(array_unique($viewports))!==count($viewports))
        return syndamorph_inspection_failure('request_invalid');
    $collected=array();$requests=array();$returned=array();$batches=array();$missing=$viewports;$build=null;
    for($attempt=1;$attempt<=SYNDAMORPH_INSPECTION_ATTEMPTS;$attempt++) {
        $requests[]=$missing;
        $payload=wp_json_encode(array('html'=>$html,'viewports'=>$missing,'inspectionProtocol'=>SYNDAMORPH_INSPECTION_PROTOCOL));
        if(!is_string($payload))return syndamorph_inspection_failure('request_invalid');
        $response=wp_remote_post($endpoint,array('timeout'=>75,'redirection'=>0,
            'headers'=>array('Content-Type'=>'application/json','Accept-Encoding'=>'identity'),
            'body'=>$payload,'limit_response_size'=>SYNDAMORPH_INSPECTION_RESPONSE_BYTES+1));
        $result=syndamorph_inspection_validate($response,$missing,true);
        if(is_wp_error($result)) {
            $error=$result;
            $details=(array)$result->get_error_data();
            $batches[]=array('requested'=>$missing,'returned'=>$details['returnedWidths']??array(),
                'code'=>$result->get_error_code())+array_intersect_key($details,array_flip(array('httpStatus','responseBytes')));
            if(isset($details['returnedWidths']))$returned[]=$details['returnedWidths'];
            if($attempt<SYNDAMORPH_INSPECTION_ATTEMPTS&&(syndamorph_inspection_transient($result)||
                $result->get_error_code()==='sm_inspect_missing_evidence')) {
                syndamorph_inspection_backoff($result,$attempt);
                continue;
            }
            break;
        }
        $details=$result['details'];
        $returned[]=$details['returnedWidths'];
        $batches[]=array('requested'=>$missing,'returned'=>$details['returnedWidths'],
            'httpStatus'=>$details['httpStatus'],'responseBytes'=>$details['responseBytes']);
        if($build!==null&&$build!==$details['rendererBuild']) {
            $error=syndamorph_inspection_failure('protocol_mismatch',$details+array('previousRendererBuild'=>$build));
            break;
        }
        $build=$details['rendererBuild'];
        foreach($result['evidence'] as $sample)$collected[$sample['width']]=$sample;
        $missing=array_values(array_diff($viewports,array_keys($collected)));
        if(!$missing)return array_map(static fn($width)=>$collected[$width],$viewports);
        $error=syndamorph_inspection_failure('missing_evidence',$details);
    }
    return new WP_Error($error->get_error_code(),$error->get_error_message(),array_merge((array)$error->get_error_data(),
        array('expectedWidths'=>$viewports,'retainedWidths'=>array_values(array_intersect($viewports,array_keys($collected))),
            'missingWidths'=>$missing,'requestedBatches'=>$requests,'returnedBatches'=>$returned,'batches'=>$batches,
            'attempts'=>$attempt>SYNDAMORPH_INSPECTION_ATTEMPTS?
                SYNDAMORPH_INSPECTION_ATTEMPTS:$attempt)));
}

function syndamorph_inspection_with_stage(WP_Error $error,string $stage,string $role,int $calls): WP_Error {
    return new WP_Error($error->get_error_code(),$error->get_error_message(),
        array_merge((array)$error->get_error_data(),array('stage'=>$stage,'role'=>$role,'task'=>'playwright.inspect','completedCalls'=>$calls)));
}

function syndamorph_inspection_diagnostic(WP_Error $error): array {
    return array('code'=>$error->get_error_code())+array_intersect_key((array)$error->get_error_data(),array_flip(array(
        'stage','expectedWidths','returnedWidths','expectedProtocol','rendererProtocol','rendererBuild',
        'rendererCode','httpStatus','responseBytes','declaredBytes','evidenceCount','viewport','jsonError','attempts',
        'retainedWidths','missingWidths','requestedBatches','returnedBatches','batches','previousRendererBuild',
    )));
}

function syndamorph_inspection_checkpoint(WP_Error $error,string $reference,array $viewports): WP_Error {
    $handle=bin2hex(random_bytes(16));
    // History records the handle only. The private draft expires in a day.
    if(!set_transient('sm_inspection_'.$handle,array(
        'html'=>$reference,'sha256'=>hash('sha256',$reference),'viewports'=>$viewports,
    ),86400))return $error;
    return new WP_Error($error->get_error_code(),$error->get_error_message(),
        array_merge((array)$error->get_error_data(),array('referenceCheckpoint'=>$handle)));
}

function syndamorph_inspection_recheck(string $handle) {
    $draft=preg_match('/^[a-f0-9]{32}$/D',$handle)?get_transient('sm_inspection_'.$handle):false;
    if(!is_array($draft)||!is_string($draft['html']??null)||!is_array($draft['viewports']??null)||
        !is_string($draft['sha256']??null)||!hash_equals($draft['sha256'],hash('sha256',$draft['html']))) {
        return new WP_Error('sm_inspect_checkpoint_expired','The saved reference is unavailable or expired.',array('status'=>410));
    }
    $ready=syndamorph_inspection_ready($draft['viewports']);
    $evidence=is_wp_error($ready)?$ready:syndamorph_collab_inspect($draft['html'],$draft['viewports']);
    if(is_wp_error($evidence))return new WP_Error($evidence->get_error_code(),$evidence->get_error_message(),
        array('status'=>503,'inspection'=>syndamorph_inspection_diagnostic($evidence)));
    return array('inspected'=>true,'widths'=>array_column($evidence,'width'),'rendererBuild'=>$ready['build'],'aiCalls'=>0);
}
