<?php
/** WordPress handoff for bounded, network-isolated source interactions. */
if (!defined('ABSPATH')) exit;

function syndamorph_production_touch(array $request,array $archive,?callable $on_event=null) {
    $width=$request['width']??null;
    $actions=$request['actions']??null;
    if(!in_array($width,array(1440,768,375),true) || !is_array($actions) || !array_is_list($actions) || count($actions)>5)
        return new WP_Error('sm_touch_input','Touch requires a pinned width and up to five bounded actions.');
    $validType=array('click','hover','scroll','drag','resize');
    $normalized=array();
    foreach($actions as $action){
        $type=$action['type']??null;
        if(!is_array($action)||!in_array($type,$validType,true))
            return new WP_Error('sm_touch_input','Touch received an unsupported action.');
        $row=array('type'=>$type);
        if(in_array($type,array('click','hover','drag'),true)){
            if(!is_string($action['target']??null)||!preg_match('/^[a-f0-9]{24}$/D',$action['target']))
                return new WP_Error('sm_touch_input','Touch requires a discovered target identity.');
            $row['target']=$action['target'];
        }
        foreach(array('resize'=>'width','scroll'=>'dy','drag'=>'dx') as $actionType=>$key){
            if($type!==$actionType)continue;
            $value=$action[$key]??null;
            if(!is_int($value) || ($key==='width' && ($value<375||$value>1440)) ||
                ($key==='dy' && ($value===0||abs($value)>900)) || ($key==='dx' && ($value===0||abs($value)>300)))
                return new WP_Error('sm_touch_input','Touch requires integral gesture bounds.');
            $row[$key]=$value;
        }
        $normalized[]=$row;
    }
    if(!is_string($archive['sourceUrl']??null)||
        !syndamorph_production_reference_valid($archive,$archive['sourceUrl']))
        return new WP_Error('sm_touch_reference','Touch requires a verified source receipt.');
    $token=getenv('SYNDAMORPH_BACKEND_TOKEN');
    if(getenv('SYNDAMORPH_BACKEND_URL')!=='http://backend:3100/capture'||!is_string($token)||$token===''){
        return new WP_Error('sm_touch_config','The capture service is unavailable.');
    }
    $input=array('archiveId'=>$archive['id'],'width'=>$width,'actions'=>$normalized);
    $key=hash('sha256',wp_json_encode($input));
    $job=(int)($GLOBALS['syndamorph_active_job_id']??0);
    $cached=$GLOBALS['syndamorph_touch_receipts'][$key]??null;
    if($cached===null&&$job>0)$cached=syndamorph_history_touch_receipt($job,$key);
    $cacheStatus=$cached===null?'miss':'hit';
    if($cached===null){
        $count=(int)($GLOBALS['syndamorph_production_tool_count']??0);
        if($count>=12)return new WP_Error('sm_tool_budget','Job diagnostic tool-call ceiling reached.');
        $GLOBALS['syndamorph_production_tool_count']=$count+1;
        $response=wp_remote_post('http://backend:3100/production-touch',array('timeout'=>70,'redirection'=>0,
            'limit_response_size'=>2600000,'body'=>wp_json_encode($input),
            'headers'=>array('Content-Type'=>'application/json','X-SyndaMorph-Token'=>$token)));
        $data=is_wp_error($response)?null:json_decode(wp_remote_retrieve_body($response),true);
        if(!is_array($data)||wp_remote_retrieve_response_code($response)!==200||
            ($data['version']??null)!==1||($data['archiveId']??null)!==$archive['id']||
            ($data['initial']['width']??null)!==$width||!in_array($data['status']??null,array('observed','unknown'),true)||
            !is_array($data['scenarios']??null)||!is_array($data['initial']['targets']??null))
            return new WP_Error('sm_touch_unavailable','Touch browser evidence is unavailable.');
        foreach($data['scenarios'] as $scenario){
            if(!is_array($scenario)||!is_array($scenario['action']??null)||
                !in_array($scenario['status']??null,array('observed','inferred','unknown'),true))
                return new WP_Error('sm_touch_unavailable','Touch returned an invalid scenario receipt.');
        }
        $cached=array('key'=>$key,'input'=>$input,'result'=>$data);
        if($on_event)$on_event('touch_inspection',$cached);
    }
    $GLOBALS['syndamorph_touch_receipts'][$key]=$cached;
    $data=$cached['result'];
    $target=static fn($target): array => array_intersect_key((array)$target,array_flip(
        array('id','tag','role','name','attributes','destination','permitsClick','permitsDrag','bounds')));
    $compact=array('status'=>$data['status'],'reason'=>$data['reason']??null,'width'=>$width,
        'targets'=>array_map($target,array_slice($data['initial']['targets'],0,32)),'scenarios'=>array());
    foreach($data['scenarios'] as $scenario){
        $compact['scenarios'][]=array_intersect_key($scenario,array_flip(array('action','status','reason','blockedRequests','remoteEffects')))+
            array('target'=>$target($scenario['target']??null),'beforeSha256'=>$scenario['before']['sha256']??null,
                'afterSha256'=>$scenario['after']['sha256']??null,'from'=>$target($scenario['from']['target']??null),
                'to'=>$target($scenario['to']['target']??null));
    }
    $receipt=array('role'=>'boss','tool'=>'playwright.touch','target'=>'source','artifactSha256'=>$archive['id'],
        'scenarioSha256'=>$key,'cache'=>$cacheStatus,'viewports'=>array($width));
    if($on_event)$on_event('tool_invocation',$receipt);
    return array('receipt'=>$receipt,'evidence'=>$compact);
}
