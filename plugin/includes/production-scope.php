<?php
/** Local Section and Full Page adapters around the real AI Production Team. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/production-source.php';
require_once __DIR__.'/production-scope-source.php';
require_once __DIR__.'/production-tabs.php';
require_once __DIR__.'/production-responsive.php';

const SYNDAMORPH_SCOPE_WORKFLOW = 'source-scope-v1';

function syndamorph_scope_error(string $code,string $message,int $status=409): WP_Error {
    return new WP_Error('sm_scope_'.$code,$message,array('status'=>$status));
}

function syndamorph_scope_open(string $mode,array $capture) {
    if(!in_array($mode,array('section','page'),true)||!is_string($capture['url']??null)||
        !is_array($capture['sections']??null)||!$capture['sections']||count($capture['sections'])>180||
        array_column($capture['views']??array(),'width')!==SYNDAMORPH_SOURCE_WIDTHS)
        return syndamorph_scope_error('capture','Responsive discovery produced no usable manifest.',422);
    if((getenv('SYNDAMORPH_REFERENCE_ARCHIVE')==='1'||array_key_exists('referenceArchive',$capture)) &&
        !syndamorph_production_reference_valid($capture['referenceArchive']??null,$capture['url']))
        return syndamorph_scope_error('reference','Rendered source reference failed verification.',422);
    $regions=array();
    foreach($capture['sections'] as $source) {
        $key=$source['id']??'';
        if(!is_string($key)||!preg_match('/^[a-f0-9]{24}$/D',$key)||isset($regions[$key])||!is_string($source['sourceRef']??null))
            return syndamorph_scope_error('identity','Discovery returned an invalid or duplicate region identity.',422);
        $blockers=syndamorph_source_blockers($source,$capture['referenceArchive']??null);
        $regions[$key]=array('id'=>$key,'label'=>sanitize_text_field($source['label']??'Section'),
            'kind'=>$source['kind']??'content','sourceRef'=>$source['sourceRef'],'position'=>count($regions)+1,
            'variants'=>array_map(static fn($view)=>$view['sourceRef'],$source['variants']),
            'status'=>$blockers?'blocked':'ready','blockers'=>$blockers,'attempts'=>array(),'patternId'=>null);
    }
    $record=syndamorph_history_create('REF',($mode==='page'?'Full Page · ':'Section · ').$capture['url'],
        array('workflow'=>SYNDAMORPH_SCOPE_WORKFLOW,'mode'=>$mode,'sourceUrl'=>$capture['url']));
    if(is_wp_error($record))return $record;
    foreach($capture['sections'] as $source) {
        $id=$source['id'];
        $regions[$id]['checkpoint']=syndamorph_history_append($record['id'],'scope_source',$source);
    }
    syndamorph_history_append($record['id'],'scope_page_source',$capture['views']);
    $manifest=$record+array('mode'=>$mode,'sourceUrl'=>$capture['url'],'regions'=>$regions,
        'status'=>'needs_review','selected'=>null,'page'=>null,'spentUsd'=>0.0,'meteringComplete'=>true,
        'captures'=>array_map(static fn($view)=>array('width'=>$view['width'],'regions'=>$view['discovered']??count($view['order']??array()),
            'blockedRequests'=>$view['network']['denied']??0,'failedResponses'=>$view['network']['error']??0,
            'browserErrors'=>count($view['browserErrors']??array())),$capture['views']))+
        array_intersect_key($capture,array('referenceArchive'=>true));
    syndamorph_history_append($record['id'],'scope_manifest',$manifest);
    return $manifest;
}

function syndamorph_scope_checkpoint(int $id,int $eventId): ?array {
    global $wpdb;
    list(,$events)=syndamorph_history_tables();
    $raw=$wpdb->get_var($wpdb->prepare("SELECT payload FROM $events WHERE id=%d AND job_id=%d AND event_type='scope_source'",$eventId,$id));
    $source=is_string($raw)?json_decode($raw,true):null;
    return is_array($source)?$source:null;
}

/** Share the existing site-wide production guard. Archive mutations on this scope are serialized locally. */
function syndamorph_scope_locked(callable $operation) {
    global $wpdb;
    $key='sm_scope_'.substr(hash('sha256',DB_NAME.$wpdb->prefix),0,40);
    if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$key))!==1)
        return syndamorph_scope_error('busy','Another source conversion action is still running.',429);
    try { return $operation(); }
    finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key)); }
}

function syndamorph_scope_account(int $id,bool $includeMetrics=false): array {
    global $wpdb;
    list(,$events)=syndamorph_history_tables();
    $rows=$wpdb->get_results($wpdb->prepare("SELECT event_type,payload FROM $events WHERE job_id=%d AND event_type IN ('agent_complete','agent_failure') ORDER BY id",$id),ARRAY_A);
    $total=0.0;$reserve=0.0;$metrics=array();
    $completed=0;
    $usage=array_fill_keys(array('inputTokens','outputTokens','cacheReadTokens','cacheWriteTokens'),0);
    $bounded=$known=is_array($rows);
    foreach($rows??array() as $row) {
        if($row['event_type']==='agent_complete')$completed++;
        $metric=json_decode($row['payload'],true)['metric']??array();
        $total+=(float)($metric['estimatedCostUsd']??0);
        if($includeMetrics)$metrics[]=$metric;
        foreach($usage as $key=>$_)$usage[$key]+=(int)($metric[$key]??0);
        $reported=($metric['metering']??'')==='provider_reported';
        $known=$known&&$reported;
        $estimate=$metric['estimatedCostUsd']??null;$allowance=$metric['reservedCostUsd']??null;
        $bounded=$bounded&&is_numeric($estimate)&&$estimate>=0&&($reported||(is_numeric($allowance)&&$allowance>0));
        if(!$reported&&is_numeric($allowance)&&is_numeric($estimate))
            $reserve+=max(0,(float)($metric['unmeteredReserveUsd']??0),$allowance-$estimate);
    }
    return array('estimatedCostUsd'=>round($total,6),'meteringComplete'=>$known,'usage'=>$usage,'completedCalls'=>$completed,
        'unmeteredReserveUsd'=>$reserve,'costBounded'=>$bounded)+($includeMetrics?array('metrics'=>$metrics):array());
}

function syndamorph_scope_recovery_stage(?array $failure): ?string {
    $code=$failure['code']??null;
    if($code==='sm_generation_transport'&&($failure['role']??null)==='boss'&&($failure['task']??null)==='final_approval')
        return 'design_approval';
    if($code==='sm_generation_transport'&&($failure['role']??null)==='developer'&&($failure['task']??null)==='developer')
        return 'developer';
    if($code==='sm_generation_token_limit'&&($failure['role']??null)==='developer'&&($failure['task']??null)==='developer'&&
        ($failure['stopReason']??null)==='max_tokens')return 'developer';
    if($code==='sm_generation_token_limit'&&($failure['role']??null)==='designer'&&($failure['task']??null)==='designer'&&
        ($failure['stopReason']??null)==='max_tokens')return 'designer_css';
    if($code==='sm_team_context'&&($failure['role']??null)==='designer'&&($failure['task']??null)==='design_correction')
        return 'design_evidence';
    if($code==='sm_boss_contract'&&($failure['field']??null)==='requirement check')return 'planning';
    return null;
}

function syndamorph_scope_failed_stream(int $id,string $role): ?string {
    global $wpdb;
    list(,$events)=syndamorph_history_tables();
    $rows=$wpdb->get_results($wpdb->prepare(
        "SELECT event_type,payload FROM $events WHERE job_id=%d AND event_type IN ('agent_start','agent_delta','agent_complete','agent_failure') ORDER BY id",$id),ARRAY_A);
    $raw=null;$saved=null;
    foreach($rows??array() as $row){
        $data=json_decode($row['payload'],true);
        $original=($data['role']??null)===$role&&($data['task']??null)===$role;
        if($row['event_type']==='agent_start')$raw=$original?'':null;
        elseif($raw!==null&&$original&&$row['event_type']==='agent_delta'){
            $raw.=$data['text']??'';
            if(strlen($raw)>120000)return null;
        }elseif($raw!==null&&$original&&$row['event_type']==='agent_failure'){
            $saved=($data['metric']['metering']??null)==='provider_reported'?$raw:null;
            $raw=null;
        }elseif($row['event_type']==='agent_complete')$raw=null;
    }
    return $saved;
}

function syndamorph_scope_designer_css_checkpoint(int $id): ?array {
    $raw=syndamorph_scope_failed_stream($id,'designer');
    return $raw===null?null:syndamorph_design_css_checkpoint($raw);
}

function syndamorph_scope_planning_checkpoint(int $id): ?string {
    $calls=syndamorph_job_resume_calls($id);
    if(!is_array($calls)||count($calls)!==1)return null;
    $planning=$calls[0];
    $lastTouch=syndamorph_history_latest($id,'touch_inspection');
    $sourceScroll=syndamorph_history_latest($id,'source_scroll_verified');
    if(($planning['role']??null)!=='boss'||($planning['task']??null)!=='boss'||!is_string($planning['text']??null)||
        ($lastTouch!==null&&!in_array($lastTouch['key']??null,array_column($sourceScroll['observed']??array(),'receipt'),true))||
        is_wp_error(syndamorph_collab_requirements($planning['text'])))
        return null;
    return $planning['text'];
}

/** Re-flow one oversized disclosure requirement without omitting any model-authored sentence. */
function syndamorph_scope_staged_work_order(string $raw,string $id): ?string {
    $workOrder=json_decode(syndamorph_native_json_text($raw),true);
    if(!is_array($workOrder['requirements']??null)||!is_array($workOrder['interactions']??null))return null;
    $disclosure=array_values(array_filter($workOrder['interactions'],static fn($item)=>
        is_array($item)&&($item['kind']??null)==='disclosure-group'));
    if(count($disclosure)!==1||($disclosure[0]['id']??null)!==$id||($disclosure[0]['classification']??null)!=='native')return null;
    foreach($workOrder['requirements'] as $index=>$requirement){
        if(!is_array($requirement)||($requirement['id']??null)!==($disclosure[0]['requirementId']??null)||
            ($requirement['basis']??null)!=='source'||($requirement['dimension']??null)!=='functional')continue;
        $text=$requirement['requirement']??null;
        $acceptance=$requirement['acceptance']??null;
        if(!is_string($text)||!is_string($acceptance)||mb_strlen($text)<=240||mb_strlen($text)+mb_strlen($acceptance)>=480)return null;
        $parts=preg_split('/(?<=\.)\s+/u',trim($text));
        if(!$parts||count($parts)<2)return null;
        $first=array_shift($parts);
        $requirement['requirement']=implode(' ',$parts);
        $requirement['acceptance']=$first.' '.trim($acceptance);
        $workOrder['requirements'][$index]=$requirement;
        $formatted=wp_json_encode($workOrder);
        return is_string($formatted)&&!is_wp_error(syndamorph_collab_requirements($formatted))?$formatted:null;
    }
    return null;
}

/** A private recovery candidate must be bound to the current native result and approved reference. */
function syndamorph_scope_native_review_checkpoint(int $id,?array $final): ?array {
    if(($final['status']??null)!=='uncompleted'||!is_string($final['artifact']['content']??null)||
        !is_string($final['referenceHtml']??null))return null;
    $saved=syndamorph_history_latest($id,'native_layout_recovery');
    $content=$saved['artifact']['content']??null;
    $hash=$saved['artifactSha256']??null;
    if(($saved['hold']??null)!=='implementation_review'||!is_array($saved['plan']??null)||
        !is_string($content)||!is_string($hash)||hash('sha256',$content)!==$hash||
        ($saved['previousArtifactSha256']??null)!==hash('sha256',$final['artifact']['content'])||
        ($saved['approvedDesignerSha256']??null)!==hash('sha256',$final['referenceHtml'])||
        ($saved['sourceReview']['passed']??null)!==true)
        return null;
    $observations=$saved['implementationEvidence']??array();
    if(!is_array($observations)||strlen(wp_json_encode($observations))>12000)return null;
    return array('artifactSha256'=>$hash,'plan'=>$saved['plan'],'archivedObservations'=>$observations);
}

function syndamorph_scope_status(array $manifest): array {
    global $wpdb;
    list($jobs)=syndamorph_history_tables();
    $manifest['spentUsd']=0.0;
    $manifest['meteringComplete']=true;
    $manifest['costBounded']=true;
    $manifest['unmeteredReserveUsd']=0.0;
    $manifest['outputTokens']=0;
    foreach($manifest['regions'] as &$region) {
        foreach($region['attempts'] as &$attempt) {
            $row=$wpdb->get_row($wpdb->prepare("SELECT status,updated_at FROM $jobs WHERE id=%d",$attempt['id']),ARRAY_A);
            $status=$row['status']??'interrupted';
            if($status==='running'&&strtotime($row['updated_at'].' UTC')<time()-1500)$status='interrupted';
            $account=syndamorph_scope_account($attempt['id']);
            if(in_array($status,array('running','interrupted'),true))$account['costBounded']=$account['meteringComplete']=false;
            $attempt=array_merge($attempt,$account,array('status'=>$status));
            $manifest['spentUsd']+=$attempt['estimatedCostUsd'];
            $manifest['outputTokens']+=$attempt['usage']['outputTokens'];
            $manifest['meteringComplete']=$manifest['meteringComplete']&&$attempt['meteringComplete'];
            $manifest['costBounded']=$manifest['costBounded']&&$attempt['costBounded'];
            $manifest['unmeteredReserveUsd']+=$attempt['unmeteredReserveUsd'];
        }
        unset($attempt);
        if($region['attempts']) {
            $last=end($region['attempts']);
            $region['status']=$region['patternId']?'pattern_saved':$last['status'];
            unset($region['checkpointAvailable'],$region['designReviewRecovery'],$region['recoveryStage'],$region['designDecisionRequired'],
                $region['issue'],$region['sourceScroll']);
            if(in_array($last['status'],array('failed','uncompleted','needs_review'),true)) {
                $failure=$last['status']==='failed'?syndamorph_history_latest($last['id'],'job_error'):null;
                $final=syndamorph_history_latest($last['id'],'job_complete');
                if($final){
                    $final=syndamorph_history_responsive_result($last['id'],$final);
                    if($last['status']==='uncompleted'&&($final['status']??null)==='needs_review')$region['status']='needs_review';
                }
                $functional=$final['quality']['functionalChecks']['status']??null;
                $responsive=$final['quality']['continuousResponsive']['status']??null;
                $region['checkpointAvailable']=!empty($failure['referenceCheckpoint'])||!empty($final['referenceCheckpoint'])||
                    $responsive==='pending';
                $region['recoveryStage']=$last['status']==='failed'?syndamorph_scope_recovery_stage($failure):null;
                if($region['recoveryStage']==='planning'&&syndamorph_scope_planning_checkpoint($last['id'])===null)
                    $region['recoveryStage']=null;
                if($region['recoveryStage']==='designer_css'&&
                    (syndamorph_history_latest($last['id'],'job_resumed')!==null||
                    syndamorph_scope_designer_css_checkpoint($last['id'])===null))$region['recoveryStage']=null;
                if($region['recoveryStage']==='design_evidence'&&
                    ((syndamorph_history_latest($last['id'],'job_resumed')['stage']??null)==='design_evidence'||
                    (syndamorph_history_latest($last['id'],'design_candidate')['pinnedSourcePassed']??null)!==true))
                    $region['recoveryStage']=null;
                if($region['recoveryStage']==='developer'&&($failure['code']??null)==='sm_generation_token_limit'&&
                    ((syndamorph_history_latest($last['id'],'job_resumed')['stage']??null)==='developer'||
                    syndamorph_scope_failed_stream($last['id'],'developer')!==''))
                    $region['recoveryStage']=null;
                if($last['status']==='failed'&&($failure['code']??null)==='sm_source_disclosure_assignment'&&
                    $last['completedCalls']===1&&syndamorph_history_latest($last['id'],'staged_implementation_review')!==null&&
                    syndamorph_history_latest($last['id'],'job_resumed')===null&&
                    syndamorph_scope_planning_checkpoint($last['id'])!==null)
                    $region['recoveryStage']='staged_planning';
                if($last['status']==='failed'&&($failure['code']??null)==='sm_boss_contract'&&
                    ($failure['field']??null)==='requirements'&&$last['completedCalls']===2&&
                    (syndamorph_history_latest($last['id'],'job_resumed')['stage']??null)==='staged_planning')
                    $region['recoveryStage']='staged_format';
                if($last['status']==='failed'&&($failure['code']??null)==='sm_design_requirements'&&
                    syndamorph_history_latest($last['id'],'staged_native_surgery')!==null&&
                    (syndamorph_history_latest($last['id'],'job_resumed')['stage']??null)!=='staged_disclosure_qa')
                    $region['recoveryStage']='staged_disclosure_qa';
                if($region['status']!=='needs_review'&&syndamorph_scope_native_review_checkpoint($last['id'],$final)!==null)
                    $region['recoveryStage']='native_review';
                $region['designReviewRecovery']=$region['recoveryStage']==='design_approval';
                $region['designDecisionRequired']=$last['status']==='failed'&&($failure['code']??null)==='sm_design_review_required';
                if($last['status']==='failed'&&$region['recoveryStage']===null&&
                    syndamorph_history_latest($last['id'],'staged_implementation_review')!==null)
                    $region['designDecisionRequired']=true;
                if($last['status']==='failed'&&$region['recoveryStage']===null&&
                    (syndamorph_history_latest($last['id'],'job_resumed')['stage']??null)==='designer_css')
                    $region['designDecisionRequired']=true;
                if($last['status']==='failed'&&$region['recoveryStage']===null&&
                    ($failure['code']??null)==='sm_generation_token_limit'&&($failure['role']??null)==='developer')
                    $region['designDecisionRequired']=true;
                if(isset($failure['sourceScroll']))$region['sourceScroll']=$failure['sourceScroll'];
                $region['issue']=$failure['message']??$final['quality']['issues'][0]['detail']??
                    (in_array($functional,array('pending','failed'),true)?'Functional QA '.$functional.'. See the artifact receipt in History.':null)??
                    (in_array($responsive,array('pending','failed'),true)?'Responsive QA '.$responsive.'. See the artifact receipt in History.':null);
                if($region['recoveryStage']==='native_review')
                    $region['issue']='The recovered native artifact is ready for independent implementation review.';
                if($region['designDecisionRequired']){
                    $decision=syndamorph_history_latest($last['id'],'agent_decision');
                    if(($decision['stage']??null)==='design_approval'&&($decision['decision']??null)==='design_review_required'&&
                        !empty($decision['summary']))$region['issue']=$decision['summary'];
                }
            }
        }
    }
    unset($region);
    $manifest['spentUsd']=round($manifest['spentUsd'],6);
    return $manifest;
}

function syndamorph_scope_run(array $manifest,string $key,bool $retry,?callable $engine=null,?array $stagedReview=null) {
    $region=$manifest['regions'][$key]??null;
    if(!$region||($manifest['mode']==='section'&&$manifest['selected']!==$key))
        return syndamorph_scope_error('selection','This region does not belong to the active scope.');
    $last=end($region['attempts']);
    if($retry&&$last&&((syndamorph_history_latest($last['id'],'job_error')['code']??null)==='sm_design_review_required'||
        syndamorph_history_latest($last['id'],'staged_implementation_review')!==null||
        (syndamorph_history_latest($last['id'],'job_resumed')['stage']??null)==='designer_css'||
        !empty($region['designDecisionRequired'])))
        return syndamorph_scope_error('design_decision','The saved design requires a decision before another paid attempt.');
    $eligible=$retry?array('failed','uncompleted'):array('ready');
    if(!in_array($region['status'],$eligible,true)||!empty($region['checkpointAvailable'])||!empty($region['recoveryStage']))
        return syndamorph_scope_error('region_state','Use the existing artifact or browser checkpoint for this region.');
    if(empty($manifest['budgetUsd'])||empty($manifest['meteringComplete'])||$manifest['spentUsd']>=$manifest['budgetUsd']*0.8)
        return syndamorph_scope_error('budget','No verified remaining budget for another section.');
    if(get_transient('syndamorph_ai_real_job_lock'))return syndamorph_scope_error('busy','Another AI production job is running.',429);
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    if(!$source||$source['id']!==$key)return syndamorph_scope_error('checkpoint','Pinned source checkpoint is unavailable.');
    if($stagedReview!==null&&($retry||($stagedReview['nativeReview']['stagedSourceSha256']??null)!==hash('sha256',wp_json_encode($source))))
        return syndamorph_scope_error('candidate','The staged implementation does not match this pinned source.');
    $available=$manifest['budgetUsd']-$manifest['spentUsd'];
    $availableWork=$manifest['budgetUsd']*0.8-$manifest['spentUsd'];
    $brief=$manifest['brief'];
    $settings=syndamorph_models_settings();
    $available=min($available,(float)($manifest['sectionBudgetUsd']??$settings['maxJobUsd']??0));
    $engineSource=syndamorph_scope_engine_source($source,$manifest['sourceUrl']);
    if(isset($manifest['layoutAuthorization'])){
        $authorization=syndamorph_scope_layout_authorization($manifest,$key);
        if($authorization===null)return syndamorph_scope_error('layout_authorization','The layout authorization does not match the pinned region.');
        $engineSource['layoutAuthorization']=$authorization;
    }
    if(array_key_exists('referenceArchive',$manifest)) {
        if(!syndamorph_production_reference_valid($manifest['referenceArchive'],$manifest['sourceUrl']))
            return syndamorph_scope_error('reference','Pinned rendered reference is unavailable.');
        $engineSource['referenceArchive']=$manifest['referenceArchive'];
    }
    $job=syndamorph_history_create('GEN',$region['label'],array('workflow'=>'three-agent-v2','outputType'=>'gutenberg',
        'brief'=>$brief,'settings'=>$settings,'standards'=>syndamorph_production_standards(),
        'scope'=>array('id'=>$manifest['id'],'region'=>$key,'checkpoint'=>$region['checkpoint'],'sourceUrl'=>$manifest['sourceUrl'],
            'budgetUsd'=>$available,'workingBudgetUsd'=>$availableWork),'sourceEvidence'=>$engineSource));
    if(is_wp_error($job))return $job;
    $manifest['regions'][$key]['attempts'][]=$job;
    syndamorph_history_append($manifest['id'],'scope_manifest',$manifest);
    set_transient('syndamorph_ai_real_job_lock',1,20*MINUTE_IN_SECONDS);
    $GLOBALS['syndamorph_active_job_id']=$job['id'];
    $emit=static function(string $type,array $data)use($job):void{syndamorph_history_append($job['id'],$type,$data);};
    try {
        $emit('ready',$job+array('outputType'=>'gutenberg'));
        if($stagedReview!==null)$emit('staged_implementation_review',$stagedReview);
        $result=($engine??'syndamorph_collab_run')($brief,true,$emit,true,$engineSource,$available,$availableWork,$stagedReview);
        if(is_wp_error($result)) {
            $failure=syndamorph_team_failure_payload($result);
            $failure['completedCalls']=syndamorph_scope_account($job['id'])['completedCalls'];
            $emit('job_error',$failure);
        } else $emit('job_complete',$result);
    } catch(Throwable $error) {
        $emit('job_error',array('code'=>'sm_scope_internal','message'=>'Production stopped before the section result could be archived.'));
        $emit('agent_failure',array('metric'=>array('metering'=>'unavailable')));
    } finally {
        unset($GLOBALS['syndamorph_active_job_id']);
        delete_transient('syndamorph_ai_real_job_lock');
    }
    $manifest=syndamorph_scope_status($manifest);
    if($manifest['status']!=='completed')$manifest['status']='uncompleted';
    syndamorph_history_append($manifest['id'],'scope_manifest',$manifest);
    return $manifest;
}

/** Prepare a scope-retained native candidate for fresh planning and independent review. */
function syndamorph_scope_review_staged(array $manifest,string $key,int $originId,?callable $engine=null) {
    $region=$manifest['regions'][$key]??null;
    $origin=syndamorph_history_latest($originId,'scope_manifest');
    $saved=syndamorph_history_latest($originId,'scope_native_candidate');
    if(!$region||$region['status']!=='ready'||!empty($region['attempts'])||empty($manifest['budgetUsd'])||
        ($manifest['mode']==='section'&&$manifest['selected']!==$key)||
        ($saved['region']??null)!==$key||($saved['approved']??null)!==false||
        !is_array($saved['plan']??null)||($origin['sourceUrl']??null)!==$manifest['sourceUrl']||
        ($origin['referenceArchive']??null)!==($manifest['referenceArchive']??null)||
        !empty($origin['layoutAuthorization'])||!empty($manifest['layoutAuthorization']))
        return syndamorph_scope_error('candidate','The retained candidate and this ready scope do not share the same source evidence.');
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    $old=syndamorph_scope_checkpoint($originId,(int)($saved['checkpoint']??0));
    if(!$source||$source!==$old||syndamorph_source_blockers($source,$manifest['referenceArchive']??null))
        return syndamorph_scope_error('candidate','The candidate must use the exact original verified region capture.');
    $plan=$saved['plan'];
    $compiled=syndamorph_surgery_verify($plan,$plan);
    if(is_wp_error($compiled))return $compiled;
    $artifact=$compiled['artifact'];
    $sha=hash('sha256',$artifact['content']);
    if($sha!==($saved['artifactSha256']??null))
        return syndamorph_scope_error('candidate','The retained block plan no longer compiles to its exact artifact.');
    $reference=syndamorph_scope_staged_reference($plan,$artifact);
    if(is_wp_error($reference))return $reference;
    $native=syndamorph_source_review($source,$artifact['preview']);
    $design=syndamorph_source_review($source,$reference);
    if(!$native['passed']||!$design['passed'])
        return syndamorph_scope_error('candidate_quality','The retained artifact or reference did not pass the current pinned source comparison.');
    return syndamorph_scope_run($manifest,$key,false,$engine,array('referenceHtml'=>$reference,'nativeReview'=>array(
        'referenceSha256'=>hash('sha256',$reference),'artifactSha256'=>$sha,'plan'=>$plan,
        'stagedSourceSha256'=>hash('sha256',wp_json_encode($source)),'originScopeId'=>$originId,
        'archivedObservations'=>array('pinnedSourcePixelRatios'=>array_column($native['visual']['views'],'pixelDifferenceRatio'),
            'sourceWidths'=>SYNDAMORPH_SOURCE_WIDTHS))));
}

function syndamorph_scope_staged_reference(array $plan,array $artifact) {
    $root=$plan['blocks'][0]['id']??'';
    if(!is_string($root)||!preg_match('/^[a-z][a-z0-9_.-]{0,79}$/iD',$root))
        return syndamorph_scope_error('candidate','The retained artifact lacks a stable semantic review root.');
    $reference=preg_replace('~<div(?=[^>]*\bdata-sm-node="'.preg_quote($root,'~').'")~','<section',$artifact['preview'],1,$opened);
    $reference=preg_replace('~</div>\s*$~','</section>',trim($reference??''),1,$closed);
    if($artifact['disclosureGroups']??array())$reference.="\n".'<style data-sm-disclosure-indicator>'.syndamorph_disclosures_css().'</style>';
    if($opened!==1||$closed!==1||!is_string($reference)||strlen($reference)>120000||
        preg_match('~<\s*(?:script|iframe|object|embed|form|link|meta)\b|\bon[a-z]+\s*=|\b(?:javascript:|https?://|@import)~i',$reference))
        return syndamorph_scope_error('candidate','The native preview could not be represented as a safe semantic review reference.');
    return $reference;
}

/** Replay one existing paragraph's captured wrapping while keeping the original plan and review in History. */
function syndamorph_scope_staged_wrap(array $source,array $staged,array $artifact,string $id) {
    $native=$staged['nativeReview'];
    $plan=$native['plan'];
    $node=syndamorph_collab_find($plan['blocks'],$id);
    if(($node['type']??null)!=='paragraph'||isset($node['style']['textWrap']))
        return syndamorph_scope_error('review_surgery','Select the original Paragraph that needs wrapping repair.');
    $captured=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $view=$source['variants'][$width]??array();
        $matches=array_values(array_filter($view['facts']['typography']??array(),static fn($row)=>
            ($row['tag']??'')==='p'&&($row['text']??null)===$node['text']));
        $sourceWidth=$matches[0]['bounds'][2]??null;
        if(count($matches)!==1||!is_numeric($sourceWidth)||$sourceWidth<=0)continue;
        if(!preg_match_all('~<p\b[^>]*\bstyle="([^"]*)"[^>]*>([^<]*)</p>~iu',$view['html']??'',$paragraphs,PREG_SET_ORDER))continue;
        foreach($paragraphs as $sample){
            if(html_entity_decode(trim($sample[2]),ENT_QUOTES|ENT_HTML5,'UTF-8')!==$node['text']||
                !preg_match('/(?:^|;)\s*text-wrap\s*:\s*pretty\s*(?:;|$)/i',$sample[1]))continue;
            $captured[$width]=$sourceWidth;
        }
    }
    if(!$captured)return syndamorph_scope_error('review_surgery','The pinned source does not verify pretty wrapping for that Paragraph.');
    $patch=syndamorph_surgery_apply($plan,array('id'=>$id,'changes'=>array(array('path'=>'style.textWrap','value'=>'pretty'))),array($id));
    if(is_wp_error($patch))return $patch;
    if(!$patch['artifact'])return $patch['error'];
    $new=$patch['artifact'];
    $reference=syndamorph_scope_staged_reference($patch['plan'],$new);
    if(is_wp_error($reference))return $reference;
    $nativeQa=syndamorph_source_review($source,$new['preview']);
    $designQa=syndamorph_source_review($source,$reference);
    $nativeDelta=syndamorph_source_surgery_comparison(syndamorph_source_review($source,$artifact['preview']),$nativeQa);
    $designDelta=syndamorph_source_surgery_comparison(syndamorph_source_review($source,$staged['referenceHtml']),$designQa);
    foreach(array($nativeDelta,$designDelta) as $delta){
        if(!$nativeQa['passed']||!$designQa['passed']||$delta['regressions']||!$delta['improved'])
            return syndamorph_scope_error('review_surgery','The edited paragraph must measurably improve both source comparisons.');
        foreach($delta['views'] as $view){
            if($view['pixelAfter']>$view['pixelBefore']||$view['heightAfter']!==$view['heightBefore']||
                (!isset($captured[$view['width']])&&$view['pixelAfter']!==$view['pixelBefore']))
                return syndamorph_scope_error('review_surgery','The wrapping change regressed a view or affected an unverified width.');
        }
    }
    $receipt=array('status'=>'source_verified','target'=>$id,'operation'=>'paragraph_pretty',
        'sourceWidthPx'=>$captured,'priorSha256'=>$native['referenceSha256'],'candidateSha256'=>hash('sha256',$reference),
        'priorArtifactSha256'=>$native['artifactSha256'],'artifactSha256'=>hash('sha256',$new['content']),
        'sourceMeasurements'=>$designDelta['views'],'nativeSourceMeasurements'=>$nativeDelta['views']);
    $native['referenceSha256']=$receipt['candidateSha256'];
    $native['artifactSha256']=$receipt['artifactSha256'];
    $native['plan']=$patch['plan'];
    $native['archivedObservations']['wrapRepair']=$receipt;
    return array('staged'=>array('referenceHtml'=>$reference,'nativeReview'=>$native),'receipt'=>$receipt);
}

/** Resume an exact archived assignment or planning response on its original GEN. */
function syndamorph_scope_resume_checkpoint(array $manifest,string $key,array $edits=array(),?callable $engine=null,
    array $workOrderEdits=array(),?string $wrapId=null) {
    global $wpdb;
    $manifest=syndamorph_scope_status($manifest);
    $region=$manifest['regions'][$key]??null;
    $stage=$region['recoveryStage']??null;
    $disputeRecovery=false;
    if($wrapId!==null){
        $last=!empty($region['attempts'])?end($region['attempts']):null;
        $failure=$last?syndamorph_history_latest($last['id'],'job_error'):null;
        if($stage!==null||$edits||$workOrderEdits||($region['status']??null)!=='failed'||
            ($failure['code']??null)!=='sm_design_unapproved'||!preg_match('/^[a-z][a-z0-9-]{0,47}$/D',$wrapId)||
            syndamorph_history_latest($last['id'],'staged_implementation_review')===null||
            syndamorph_history_latest($last['id'],'staged_native_surgery')!==null)
            return syndamorph_scope_error('review_surgery','This job has no eligible staged wrapping correction.');
        $stage='design_approval';
    }
    // A reviewer can repair a rejected surgical operation on its original
    // checkpoint. Existing identity and browser regression checks still apply.
    if($stage===null&&($edits||$workOrderEdits)&&($region['status']??null)==='failed'&&!empty($region['attempts'])){
        $last=end($region['attempts']);
        $failure=syndamorph_history_latest($last['id'],'job_error')['code']??null;
        $disputeRecovery=$edits&&!$workOrderEdits&&$failure==='sm_design_dispute';
        if($disputeRecovery||($edits&&!$workOrderEdits&&$failure==='sm_design_surgery_blocked')||
            ($workOrderEdits&&!$edits&&$failure==='sm_design_requirements'))
            $stage='design_approval';
    }
    if($stage===null||($manifest['mode']==='section'&&$manifest['selected']!==$key))
        return syndamorph_scope_error('review_checkpoint','This region has no supported recovery checkpoint.');
    if(get_transient('syndamorph_ai_real_job_lock'))return syndamorph_scope_error('busy','Another AI production job is running.',429);
    $id=end($region['attempts'])['id'];
    list($jobs,$events)=syndamorph_history_tables();
    $row=$wpdb->get_row($wpdb->prepare("SELECT status,context FROM $jobs WHERE id=%d AND category='GEN'",$id),ARRAY_A);
    $context=json_decode($row['context']??'',true);
    $saved=$context['scope']??array();
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    $rowStatus=$row['status']??null;
    $final=$stage==='native_review'?syndamorph_history_latest($id,'job_complete'):null;
    $nativeReview=$stage==='native_review'?syndamorph_scope_native_review_checkpoint($id,$final):null;
    if(!in_array($rowStatus,$nativeReview===null?array('failed'):array('failed','uncompleted'),true)||
        ($stage==='native_review'&&$nativeReview===null)||($context['outputType']??null)!=='gutenberg'||
        ($context['brief']??null)!==$manifest['brief']||($saved['id']??null)!==$manifest['id']||
        ($saved['region']??null)!==$key||($saved['checkpoint']??null)!==$region['checkpoint']||
        ($saved['sourceUrl']??null)!==$manifest['sourceUrl']||($source['id']??null)!==$key)
        return syndamorph_scope_error('review_checkpoint','The archived job and pinned scope disagree.');
    $evidence=syndamorph_scope_engine_source($source,$manifest['sourceUrl']);
    if(isset($manifest['layoutAuthorization'])){
        $authorization=syndamorph_scope_layout_authorization($manifest,$key);
        if($authorization===null)return syndamorph_scope_error('layout_authorization','The arrangement authorization does not match the saved source.');
        $evidence['layoutAuthorization']=$authorization;
    }
    if(isset($manifest['referenceArchive'])){
        if(!syndamorph_production_reference_valid($manifest['referenceArchive'],$manifest['sourceUrl']))
            return syndamorph_scope_error('reference','The archived rendered reference is unavailable.');
        $evidence['referenceArchive']=$manifest['referenceArchive'];
    }
    if($evidence!==($context['sourceEvidence']??null))
        return syndamorph_scope_error('review_checkpoint','The archived source evidence changed.');
    $staged=syndamorph_history_latest($id,'staged_implementation_review');
    if($staged!==null){
        $native=$staged['nativeReview']??array();
        $stagedReference=$staged['referenceHtml']??null;
        if($edits||$stage==='native_review'||($native['stagedSourceSha256']??null)!==hash('sha256',wp_json_encode($source))||
            !is_string($stagedReference)||hash('sha256',$stagedReference)!==($native['referenceSha256']??null)||
            !is_array($native['plan']??null))
            return syndamorph_scope_error('review_checkpoint','A staged review requires the exact source, reference and retained plan.');
        $compiled=syndamorph_surgery_verify($native['plan'],$native['plan']);
        if(is_wp_error($compiled)||hash('sha256',$compiled['artifact']['content'])!==($native['artifactSha256']??null))
            return syndamorph_scope_error('review_checkpoint','The retained implementation no longer matches its staged hash.');
    }
    $candidate=syndamorph_history_latest($id,'design_candidate');
    $retainedRevision=syndamorph_history_latest($id,'work_order_revision');
    $assignment=json_decode((string)$wpdb->get_var($wpdb->prepare(
        "SELECT payload FROM $events WHERE job_id=%d AND event_type='handoff' ORDER BY id LIMIT 1",$id)),true);
    $reference=$candidate['referenceHtml']??null;
    $sha=$candidate['referenceSha256']??null;
    $widths=syndamorph_collab_viewports($manifest['brief']);
    if(is_wp_error($widths))return $widths;
    $widths=array_values(array_unique(array_merge($widths,SYNDAMORPH_SOURCE_WIDTHS)));
    rsort($widths,SORT_NUMERIC);
    $resume=$staged??array();
    $workOrderRevision=null;
    $extendWorkOrder=false;
    $savedApproval=null;
    $planningStage=in_array($stage,array('planning','staged_planning','staged_format'),true);
    $cssCheckpoint=$stage==='designer_css'?syndamorph_scope_designer_css_checkpoint($id):null;
    if($planningStage){
        if($edits||$workOrderEdits||$candidate!==null||$assignment!==null)
            return syndamorph_scope_error('review_checkpoint','The exact archived planning response still lacks a verifiable work order.');
        if($stage==='staged_format'){
            $calls=syndamorph_job_resume_calls($id);
            $lastCall=is_array($calls)?end($calls):null;
            $profile=syndamorph_source_disclosure_profile($source,$evidence['referenceArchive']??array());
            $planning=$lastCall['text']??null;
            $reformatted=$staged!==null&&is_array($calls)&&count($calls)===2&&
                ($lastCall['role']??null)==='boss'&&($lastCall['task']??null)==='boss'&&is_string($planning)&&
                $profile!==null&&$profile===syndamorph_history_latest($id,'source_disclosure_handoff')?
                syndamorph_scope_staged_work_order($planning,$profile['id']):null;
            if($reformatted===null)
                return syndamorph_scope_error('review_checkpoint','The saved disclosure requirement cannot be re-flowed without changing its content.');
            $resume['planningText']=$reformatted;
        }else{
            $planning=syndamorph_scope_planning_checkpoint($id);
            if($planning===null)
                return syndamorph_scope_error('review_checkpoint','The original Bossy classification is unavailable.');
        }
        if($stage==='planning')$resume['planningText']=$planning;
        elseif($stage==='staged_planning'){
            $profile=syndamorph_source_disclosure_profile($source,$evidence['referenceArchive']??array());
            $order=syndamorph_collab_requirements($planning)['specification'];
            $reported=array_values(array_filter($order['interactions']??array(),
                static fn($item)=>$item['kind']==='disclosure-group'));
            if($staged===null||$profile===null||$profile!==syndamorph_history_latest($id,'source_disclosure_handoff')||
                (count($reported)===1&&$reported[0]['id']===$profile['id']&&$reported[0]['classification']==='native'))
                return syndamorph_scope_error('review_checkpoint','The staged artifact and exact disclosure classification failure could not be verified.');
            $resume['planningCorrection']=array('priorPlanningSha256'=>hash('sha256',$planning),
                'expectedId'=>$profile['id'],'reported'=>$reported);
        }
    }else{
        $specification=$assignment['requirements']['specification']??null;
        $candidateValid=is_string($reference)&&is_string($sha)&&hash_equals($sha,hash('sha256',$reference))&&
            ($staged===null||$reference===$stagedReference)&&is_int($candidate['round']??null);
        if(($stage==='designer_css'?($cssCheckpoint===null||$candidate!==null||$staged!==null||$edits||$workOrderEdits):!$candidateValid)||
            ($assignment['type']??null)!=='assignment'||
            ($assignment['from']??null)!=='boss'||($assignment['to']??null)!=='designer'||!is_array($specification)||
            ($assignment['specificationReceipt']??null)!==
            syndamorph_production_specification_receipt($specification,$manifest['brief'],$widths,$evidence))
            return syndamorph_scope_error('review_checkpoint','The saved design or assignment cannot be verified for review recovery.');
        if($stage==='staged_disclosure_qa'){
            $profile=syndamorph_source_disclosure_profile($source,$evidence['referenceArchive']??array());
            $keyboard=$profile===null?array():syndamorph_disclosures_keyboard_requirements($specification,$profile);
            $decision=syndamorph_history_latest($id,'agent_decision');
            $recordedGate=syndamorph_history_latest($id,'execution_gate');
            $checked=syndamorph_history_latest($id,'design_requirements');
            if($staged===null||$edits||$workOrderEdits||!$keyboard||
                ($checked['referenceSha256']??null)!==$sha||($checked['blocking']??null)!==$keyboard||
                ($decision['owner']??null)!=='boss'||($decision['stage']??null)!=='design_approval'||
                ($decision['decision']??null)!=='approved'||($decision['payload']['issues']??null)!==array()||
                ($recordedGate['stage']??null)!=='design_approval'||($recordedGate['agentDecision']??null)!=='approved'||
                ($recordedGate['executionStatus']??null)!=='blocked')
                return syndamorph_scope_error('review_checkpoint','The exact design approval has required checks outside the native disclosure contract.');
            $savedApproval=array('referenceSha256'=>$sha,'specificationReceipt'=>$assignment['specificationReceipt'],
                'agentPayload'=>$decision['payload'],'requirementReceipt'=>array('checks'=>$checked['checks'],'blocking'=>$checked['blocking']),
                'deferredDisclosureKeyboard'=>$keyboard);
        }
        if($retainedRevision!==null){
            $operations=$retainedRevision['operations']??null;
            if(!is_array($operations)||!$operations||($retainedRevision['referenceSha256']??null)!==$sha||
                ($retainedRevision['original']??null)!==$assignment['specificationReceipt'])
                return syndamorph_scope_error('work_order_evidence','The saved work-order revision does not match this checkpoint.');
            if(!$workOrderEdits)$workOrderEdits=$operations;
            if($workOrderEdits!==$operations){
                $extension=array_slice($workOrderEdits,count($operations));
                if(!$extension||array_slice($workOrderEdits,0,count($operations))!==$operations||
                    array_column($extension,'kind')!==array_fill(0,count($extension),'host-context'))
                    return syndamorph_scope_error('work_order_evidence','A saved revision may only add independently verified host checks.');
                $extendWorkOrder=true;
            }
        }
        if($workOrderEdits){
            if($edits||empty($candidate['pinnedSourcePassed'])||empty(syndamorph_source_review($source,$reference,$authorization??null)['passed']))
                return syndamorph_scope_error('work_order_evidence','The unchanged reference must pass pinned source QA before correcting the work order.');
            $samples=syndamorph_scope_inspect($reference,'section');
            if(is_wp_error($samples))return $samples;
            $revised=syndamorph_source_work_order_amend($specification,$evidence,$samples,$workOrderEdits);
            if(is_wp_error($revised))return $revised;
            $previousReceipt=$assignment['specificationReceipt'];
            $updated=$revised['specification'];
            $hostChecks=syndamorph_design_host_checks($updated);
            if($hostChecks&&($manifest['mode']!=='section'||
                syndamorph_design_host_accessibility($reference,$hostChecks,syndamorph_design_wordpress_page_context(),$widths)['status']!=='passed'))
                return syndamorph_scope_error('host_accessibility','The unchanged sketch must pass measured WordPress Page context checks.');
            $assignment['requirements']['specification']=$updated;
            $assignment['requirements']['acceptance']=array_column($updated['requirements'],'acceptance');
            $assignment['specificationReceipt']=syndamorph_production_specification_receipt($updated,$manifest['brief'],$widths,$evidence);
            $workOrderRevision=array('original'=>$previousReceipt,'revised'=>$assignment['specificationReceipt'],
                'referenceSha256'=>$sha,'operations'=>$workOrderEdits,'changes'=>$revised['changes']);
            if($retainedRevision!==null){
                $unchanged=array_slice($workOrderRevision['changes'],0,count($retainedRevision['operations']));
                if($unchanged!==$retainedRevision['changes']||(!$extendWorkOrder&&$retainedRevision!==$workOrderRevision))
                    return syndamorph_scope_error('work_order_evidence','The saved work-order revision no longer reproduces.');
            }
        }
        if($stage==='design_evidence'&&($staged!==null||$edits||
            empty($candidate['responsive']['passed'])||empty(syndamorph_source_review($source,$reference,$authorization??null)['passed'])))
            return syndamorph_scope_error('review_checkpoint','The saved reference must still pass source and responsive QA before reviewing its evidence.');
    }
    if(in_array($stage,array('developer','native_review'),true)){
        if($edits||$extendWorkOrder)return syndamorph_scope_error('review_checkpoint','Implementation recovery requires the unchanged design and work order.');
        if($nativeReview===null){
            $approved=syndamorph_history_latest($id,'handoff');
            $checked=syndamorph_history_latest($id,'design_requirements');
        }else{
            $approved=null;
            foreach($final['handoffs']??array() as $handoff){
                if(($handoff['type']??null)==='approved_design')$approved=$handoff;
            }
            $checked=array('referenceSha256'=>$final['designApproval']['referenceSha256']??null)+
                ($final['designApproval']['requirementResults']??array());
        }
        if(($approved['type']??null)!=='approved_design'||($approved['from']??null)!=='boss'||
            ($approved['to']??null)!=='developer'||($approved['referenceSha256']??null)!==$sha||
            ($approved['specificationReceipt']??null)!==$assignment['specificationReceipt']||
            ($approved['agentPayload']['decision']??null)!=='approved'||($approved['agentPayload']['issues']??null)!==array()||
            ($checked['referenceSha256']??null)!==$sha||($checked['blocking']??null)!==array()||!is_array($checked['checks']??null))
            return syndamorph_scope_error('review_checkpoint','The approved design handoff or its independent checklist cannot be verified.');
        $savedApproval=$approved+array('requirementReceipt'=>array('checks'=>$checked['checks'],'blocking'=>$checked['blocking']));
        if($nativeReview!==null)$resume['nativeReview']=$nativeReview;
    }
    $touch=array();
    $storedScenarios=$planningStage?(syndamorph_history_latest($id,'source_scroll_verified')['observed']??array()):
        ($assignment['touchScenarios']??array());
    foreach(array_unique(array_column($storedScenarios,'receipt')) as $keySha){
        if(!is_string($keySha)||!preg_match('/^[a-f0-9]{64}$/D',$keySha))
            return syndamorph_scope_error('review_checkpoint','The saved Touch scenario identity is invalid.');
        $receipt=syndamorph_history_touch_receipt($id,$keySha);
        $input=$receipt['input']??null;
        if(!is_array($input)||!isset($evidence['referenceArchive']['id'])||($input['archiveId']??null)!==$evidence['referenceArchive']['id']||
            !hash_equals($keySha,hash('sha256',wp_json_encode($input)))||($receipt['result']['archiveId']??null)!==$input['archiveId'])
            return syndamorph_scope_error('review_checkpoint','The saved Touch scenario is unavailable or its source differs.');
        $touch[$keySha]=$receipt;
    }
    $scenarios=syndamorph_production_touch_scenarios($touch);
    foreach($storedScenarios as $index=>$scenario){
        if(!isset($scenarios[$index])||array_intersect_key($scenarios[$index],$scenario)!==$scenario)
            return syndamorph_scope_error('review_checkpoint','The saved Touch evidence changed.');
    }
    $account=syndamorph_scope_account($id,true);
    $settings=syndamorph_models_settings();
    foreach(array('provider','model','inputPrice','outputPrice') as $field){
        if(($settings[$field]??null)!==($context['settings'][$field]??null))
            return syndamorph_scope_error('review_settings','Model or token rates differ from the archived job.');
    }
    $spent=$manifest['spentUsd']+$manifest['unmeteredReserveUsd'];
    $used=$account['estimatedCostUsd']+$account['unmeteredReserveUsd'];
    $other=max(0,$spent-$used);
    $max=min((float)($saved['budgetUsd']??0),$settings['maxJobUsd'],($manifest['budgetUsd']??0)-$other);
    $work=min((float)($saved['workingBudgetUsd']??0),$max*0.8,($manifest['budgetUsd']??0)*0.8-$other);
    if(!$manifest['costBounded']||!$account['costBounded']||$work<=$used)
        return syndamorph_scope_error('budget','The original budget cannot cover the recorded charges, reservations and continuation.');
    $surgery=null;
    $stagedRepair=null;
    if($wrapId!==null){
        $decision=syndamorph_history_latest($id,'handoff');
        if(($decision['type']??null)!=='design_review'||($decision['decision']??null)!=='changes_requested'||
            ($decision['referenceSha256']??null)!==$sha)
            return syndamorph_scope_error('review_surgery','The current staged reference must have a recorded design rejection.');
        $stagedRepair=syndamorph_scope_staged_wrap($source,$staged,$compiled['artifact'],$wrapId);
        if(is_wp_error($stagedRepair))return $stagedRepair;
        $resume=$stagedRepair['staged'];
        $reference=$resume['referenceHtml'];
        $surgery=$stagedRepair['receipt'];
    }
    if($edits){
        $patch=syndamorph_design_surgery($reference,array('edits'=>$edits));
        if(is_wp_error($patch))return $patch;
        $before=syndamorph_collab_inspect($reference,$widths,$beforeBuild);
        if(is_wp_error($before))return $before;
        $after=syndamorph_collab_inspect($patch['reference'],$widths,$afterBuild);
        if(is_wp_error($after))return $after;
        if($beforeBuild!==$afterBuild)return syndamorph_scope_error('review_checkpoint','The renderer changed during bounded design recovery.');
        $beforeResponsive=syndamorph_design_responsive_measure($reference,$widths,$before,$beforeBuild);
        if(is_wp_error($beforeResponsive))return $beforeResponsive;
        $afterResponsive=syndamorph_design_responsive_measure($patch['reference'],$widths,$after,$afterBuild);
        if(is_wp_error($afterResponsive))return $afterResponsive;
        $sourceAfter=syndamorph_source_review($source,$patch['reference'],$authorization??null);
        $delta=syndamorph_source_surgery_comparison(syndamorph_source_review($source,$reference,$authorization??null),$sourceAfter);
        if($delta['regressions']||syndamorph_design_responsive_regressions($beforeResponsive,$afterResponsive)||
            array_diff(syndamorph_design_element_ids($before),syndamorph_design_element_ids($after)))
            return syndamorph_scope_error('review_regression','The recovery patch failed to preserve verified design evidence.');
        if($disputeRecovery&&(!$sourceAfter['passed']||!$afterResponsive['passed']))
            return syndamorph_scope_error('review_surgery','A disputed design requires a source-passing and responsive repair before another review.');
        $reference=$patch['reference'];
        $surgery=array('status'=>'responsive_checked','priorSha256'=>$sha,'candidateSha256'=>hash('sha256',$reference),
            'changes'=>$patch['changes'],'sourceMeasurements'=>$delta['views']);
    }
    if(get_transient('syndamorph_ai_real_job_lock'))return syndamorph_scope_error('busy','Another AI production job is running.',429);
    $started=$wpdb->query($wpdb->prepare("UPDATE $jobs SET status='running',updated_at=%s WHERE id=%d AND status=%s",
        gmdate('Y-m-d H:i:s'),$id,$rowStatus));
    if($started!==1)return syndamorph_scope_error('busy','The saved checkpoint has already been claimed.',429);
    $emit=static function(string $type,array $data)use($id):void{syndamorph_history_append($id,$type,$data);};
    set_transient('syndamorph_ai_real_job_lock',1,20*MINUTE_IN_SECONDS);
    $GLOBALS['syndamorph_active_job_id']=$id;
    try{
        $identity=$planningStage?array('planningSha256'=>hash('sha256',$planning)):array('referenceSha256'=>$sha);
        if($cssCheckpoint!==null)$identity=array('partialDesignerSha256'=>$cssCheckpoint['rawSha256'],
            'retainedHtmlSha256'=>$cssCheckpoint['htmlSha256']);
        if($staged!==null)$identity['nativeArtifactSha256']=$resume['nativeReview']['artifactSha256'];
        if($stage==='staged_format')$identity['formattedPlanningSha256']=hash('sha256',$resume['planningText']);
        if($nativeReview!==null)$identity['nativeArtifactSha256']=$nativeReview['artifactSha256'];
        $emit('job_resumed',array('stage'=>$stage,'priorCompletedCalls'=>$account['completedCalls'],
            'priorEstimatedCostUsd'=>$account['estimatedCostUsd'],'priorUnmeteredReserveUsd'=>$account['unmeteredReserveUsd'])+$identity);
        $resume['account']=$account;
        if($workOrderRevision!==null&&($retainedRevision===null||$extendWorkOrder))$emit('work_order_revision',$workOrderRevision);
        if($cssCheckpoint!==null)$resume+=array('assignment'=>$assignment,'touch'=>$touch,'designCssCheckpoint'=>$cssCheckpoint);
        elseif(!$planningStage)$resume+=array('assignment'=>$assignment,'referenceHtml'=>$reference,'archivedSha256'=>$sha,
            'touch'=>$touch,'surgery'=>$surgery,'round'=>$candidate['round']+($surgery===null?0:1));
        if($stage==='design_evidence')$resume['reviewEvidenceRecovered']=true;
        if($stage==='developer'&&(syndamorph_history_latest($id,'job_error')['code']??null)==='sm_generation_token_limit')
            $resume['emptyDeveloperOutputRecovery']=true;
        if($savedApproval!==null)$resume['savedDesignApproval']=$savedApproval;
        if($stagedRepair!==null){
            $emit('staged_implementation_review',$stagedRepair['staged']);
            $emit('staged_native_surgery',$stagedRepair['receipt']);
        }
        if($surgery!==null)$emit('design_surgery',array('round'=>$resume['round'])+$surgery);
        $result=($engine??'syndamorph_collab_run')($manifest['brief'],true,$emit,true,$evidence,$max,$work,$resume);
        if(is_wp_error($result)){
            $failure=syndamorph_team_failure_payload($result);
            $failure['completedCalls']=syndamorph_scope_account($id)['completedCalls'];
            $emit('job_error',$failure);
        }
        else $emit('job_complete',$result);
    }catch(Throwable $error){
        $emit('job_error',array('code'=>'sm_scope_internal','message'=>'Checkpoint recovery stopped before it could be archived.'));
        $emit('agent_failure',array('metric'=>array('metering'=>'unavailable')));
    }finally{
        unset($GLOBALS['syndamorph_active_job_id']);
        delete_transient('syndamorph_ai_real_job_lock');
    }
    $manifest=syndamorph_scope_status($manifest);
    if($manifest['status']!=='completed')$manifest['status']='uncompleted';
    syndamorph_history_append($manifest['id'],'scope_manifest',$manifest);
    return $manifest;
}

function syndamorph_scope_candidate(array $manifest,string $key): ?array {
    if($manifest['mode']==='section'&&$manifest['selected']!==$key)return null;
    $region=$manifest['regions'][$key]??null;
    if(empty($region['attempts']))return null;
    $attempt=end($region['attempts']);
    $final=syndamorph_history_latest($attempt['id'],'job_complete');
    if(empty($final['artifact']['content']))return null;
    $final=syndamorph_history_responsive_result($attempt['id'],$final);
    $checksum=hash('sha256',$final['artifact']['content']);
    $hostChecks=syndamorph_design_host_checks($final['designApproval']['productionSpecification']??array());
    return array('id'=>$attempt['id'],'content'=>$final['artifact']['content'],
        'checksum'=>$checksum,'sourceAcceptance'=>$final['sourceAcceptance']??null,'hostChecks'=>$hostChecks,
        'eligible'=>!empty($final['sourceAcceptance']['passed'])&&!empty($final['quality']['bossApproved'])&&
            ($final['sourceAcceptance']['id']??null)===$key&&hash_equals((string)($final['sourceAcceptance']['artifactSha256']??''),$checksum)&&
            !empty($final['quality']['visionInspected'])&&!empty($final['quality']['browserInspected'])&&
            syndamorph_design_host_acceptance($final,$checksum)&&
            empty($final['quality']['issues'])&&syndamorph_functional_acceptance($final,$checksum)&&
            syndamorph_native_responsive_acceptance($final,$checksum));
}

function syndamorph_scope_find_post(string $type,string $key): ?WP_Post {
    $posts=get_posts(array('post_type'=>$type,'post_status'=>array('draft','publish','pending','private','future'),
        'numberposts'=>1,'meta_key'=>'_syndamorph_scope_key','meta_value'=>$key));
    return $posts[0]??null;
}

function syndamorph_scope_save_pattern(array $manifest,string $key) {
    $candidate=syndamorph_scope_candidate($manifest,$key);
    $validation=$candidate?syndamorph_history_latest($candidate['id'],'editor_validation'):null;
    if(empty($candidate['eligible'])||empty($validation['passed'])||empty($validation['completionEligible'])||
        !hash_equals($candidate['checksum'],(string)($validation['contentSha256']??'')))
        return syndamorph_scope_error('editor','The region must pass all QA and Gutenberg validation before saving.');
    if($candidate['hostChecks']){
        $native=syndamorph_native_core_preview(parse_blocks($candidate['content']));
        $host=syndamorph_design_host_accessibility($native,$candidate['hostChecks'],syndamorph_design_wordpress_page_context(),
            SYNDAMORPH_SOURCE_WIDTHS);
        if($host['status']!=='passed')return syndamorph_scope_error('host_accessibility','Pattern save needs fresh WordPress Page context evidence.');
        syndamorph_history_append($candidate['id'],'pattern_host_accessibility',array('artifactSha256'=>$candidate['checksum'])+$host);
    }
    $identity=$manifest['id'].':'.$key;
    $saved=syndamorph_scope_find_post('wp_block',$identity);
    if(!$saved) {
        $created=syndamorph_insert_pattern($manifest['regions'][$key]['label'],$candidate['content'],0,$identity);
        if(is_wp_error($created))return $created;
        $saved=get_post($created['id']);
    }
    if(!$saved||$saved->post_content!==$candidate['content']||get_post_meta($saved->ID,'wp_pattern_sync_status',true)!=='unsynced')
        return syndamorph_scope_error('pattern_changed','The saved pattern differs from its verified region artifact.');
    $manifest['regions'][$key]['patternId']=$saved->ID;
    $manifest['regions'][$key]['patternEditor']=get_edit_post_link($saved->ID,'raw');
    $manifest['regions'][$key]['artifactSha256']=$candidate['checksum'];
    $manifest['regions'][$key]['status']='pattern_saved';
    unset($manifest['regions'][$key]['checkpointAvailable'],$manifest['regions'][$key]['issue']);
    if($manifest['mode']==='section')$manifest['status']='completed';
    syndamorph_history_append($manifest['id'],'scope_manifest',$manifest);
    return syndamorph_scope_status($manifest);
}

/** Attach an independently accepted Section, without transferring its attempts or spend. */
function syndamorph_scope_reuse_section(array $page,int $sectionId,string $key) {
    $section=syndamorph_history_latest($sectionId,'scope_manifest');
    $target=$page['regions'][$key]??null;
    $origin=$section['regions'][$key]??null;
    $archive=$page['referenceArchive']??null;
    if(($page['mode']??null)!=='page'||($section['mode']??null)!=='section'||
        ($section['status']??null)!=='completed'||($section['selected']??null)!==$key||
        !$target||!$origin||($origin['status']??null)!=='pattern_saved'||
        ($page['sourceUrl']??null)!==($section['sourceUrl']??null)||
        $archive!==($section['referenceArchive']??null)||
        ($archive!==null&&!syndamorph_production_reference_valid($archive,$page['sourceUrl']))||
        !empty($page['layoutAuthorization'])||!empty($section['layoutAuthorization'])||
        (isset($page['brief'])&&$page['brief']!==($section['brief']??null)))
        return syndamorph_scope_error('reuse_scope','The saved Section and Full Page work orders or source archives do not match.');
    $previousSource=syndamorph_scope_checkpoint($sectionId,$origin['checkpoint']);
    $targetSource=syndamorph_scope_checkpoint($page['id'],$target['checkpoint']);
    if($previousSource===null||$previousSource!==$targetSource||syndamorph_source_blockers($targetSource,$archive))
        return syndamorph_scope_error('reuse_source','The Full Page region does not have the exact accepted Section capture.');
    $candidate=syndamorph_scope_candidate($section,$key);
    $editor=$candidate?syndamorph_history_latest($candidate['id'],'editor_validation'):null;
    $pattern=!empty($origin['patternId'])?get_post($origin['patternId']):null;
    if(empty($candidate['eligible'])||empty($editor['passed'])||empty($editor['completionEligible'])||
        ($editor['contentSha256']??null)!==$candidate['checksum']||!$pattern||$pattern->post_type!=='wp_block'||
        !current_user_can('edit_post',$pattern->ID)||get_post_meta($pattern->ID,'wp_pattern_sync_status',true)!=='unsynced'||
        ($origin['artifactSha256']??null)!==$candidate['checksum']||$pattern->post_content!==$candidate['content'])
        return syndamorph_scope_error('reuse_artifact','The saved Pattern no longer matches its independently verified Section artifact.');
    $receipt=array('scopeId'=>$sectionId,'generationId'=>$candidate['id'],'sectionCheckpoint'=>$origin['checkpoint'],
        'pageCheckpoint'=>$target['checkpoint'],'region'=>$key,'patternId'=>$pattern->ID,'artifactSha256'=>$candidate['checksum'],
        'sourceSha256'=>hash('sha256',wp_json_encode($targetSource)));
    if(($target['patternOrigin']??null)===$receipt&&($target['patternId']??null)===$pattern->ID&&
        ($target['artifactSha256']??null)===$candidate['checksum'])return syndamorph_scope_status($page);
    if($target['status']!=='ready'||!empty($target['patternId'])||!empty($target['attempts']))
        return syndamorph_scope_error('reuse_state','Only an untouched ready Full Page region can receive a verified Section.');
    $preview='<style>'.syndamorph_native_visibility_css().'</style>'.syndamorph_native_core_preview(parse_blocks($pattern->post_content));
    $review=syndamorph_source_review($targetSource,$preview);
    if(!$review['passed']){
        syndamorph_history_append($page['id'],'scope_section_reuse_held',$receipt+array('sourceReview'=>$review));
        return syndamorph_scope_error('reuse_quality','Fresh source QA is pending or failed. See the region receipt in History.');
    }
    $page['regions'][$key]=array_replace($target,array('status'=>'pattern_saved','patternId'=>$pattern->ID,
        'artifactSha256'=>$candidate['checksum'],'patternEditor'=>get_edit_post_link($pattern->ID,'raw'),'patternOrigin'=>$receipt));
    syndamorph_history_append($page['id'],'scope_section_reused',$receipt+array('sourceReview'=>$review));
    syndamorph_history_append($page['id'],'scope_manifest',$page);
    return syndamorph_scope_status($page);
}

/** Add a replay receipt without replacing the captured browser evidence or any job. */
function syndamorph_scope_verify_disclosure(array $manifest,string $key) {
    $region=$manifest['regions'][$key]??null;
    if(!$region||!empty($region['attempts'])||!empty($region['patternId'])||
        !in_array($region['status'],array('blocked','ready'),true)||
        ($manifest['mode']==='section'&&$manifest['selected']!==null&&$manifest['selected']!==$key))
        return syndamorph_scope_error('disclosure_state','Only an untouched source region can verify a recorded disclosure.');
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    $archive=$manifest['referenceArchive']??null;
    if(!$source||!is_array($archive))return syndamorph_scope_error('disclosure_source','The referenced source archive is unavailable.');
    if(syndamorph_source_disclosure_profile($source,$archive)!==null)return syndamorph_scope_status($manifest);
    $verified=syndamorph_source_disclosure_touch($source,$archive);
    if(is_wp_error($verified)){
        syndamorph_history_append($manifest['id'],'source_disclosure_pending',array('region'=>$key,'checkpoint'=>$region['checkpoint'],
            'code'=>$verified->get_error_code(),'diagnostic'=>$verified->get_error_data()));
        return $verified;
    }
    $checkpoint=syndamorph_history_append($manifest['id'],'scope_source',$verified);
    $blockers=syndamorph_source_blockers($verified,$archive);
    $manifest['regions'][$key]=array_replace($region,array('checkpoint'=>$checkpoint,
        'status'=>$blockers?'blocked':'ready','blockers'=>$blockers));
    syndamorph_history_append($manifest['id'],'source_disclosure_verified',array('region'=>$key,
        'originalCheckpoint'=>$region['checkpoint'],'checkpoint'=>$checkpoint,
        'component'=>syndamorph_source_disclosure_profile($verified,$archive)));
    syndamorph_history_append($manifest['id'],'scope_manifest',$manifest);
    return syndamorph_scope_status($manifest);
}

function syndamorph_scope_targets(array $manifest): array {
    return $manifest['mode']==='page'?$manifest['regions']:
        array_intersect_key($manifest['regions'],array_flip(array($manifest['selected']??'')));
}

function syndamorph_scope_layout_authorization(array $manifest,string $region): ?array {
    $receipt=$manifest['layoutAuthorization']??null;
    $checkpoint=$manifest['regions'][$region]['checkpoint']??null;
    if(!is_array($receipt)||$checkpoint===null||($receipt['scopeId']??null)!==$manifest['id']||
        ($receipt['sourceUrl']??null)!==$manifest['sourceUrl']||($receipt['checkpoints'][$region]??null)!==$checkpoint)
        return null;
    unset($receipt['checkpoints']);
    return $receipt+array('region'=>$region,'checkpoint'=>$checkpoint);
}

function syndamorph_scope_configure(array $manifest,string $brief,?string $selection,?float $limit=null,$layout=null) {
    if(isset($manifest['budgetUsd']))return syndamorph_scope_error('configured','This scope already has a pinned production brief and budget.');
    if(strlen(trim($brief))<12||strlen($brief)>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$brief))
        return syndamorph_scope_error('brief','Provide a production brief of 12 to 2000 characters.',400);
    if($manifest['mode']==='section'&&!isset($manifest['regions'][$selection]))
        return syndamorph_scope_error('selection','Select one of the pinned source regions.',400);
    $sectionBudget=(float)(syndamorph_models_settings()['maxJobUsd']??0);
    if($sectionBudget<=0)return syndamorph_scope_error('budget','Configure a valid maximum AI job cost first.');
    $budget=$limit??$sectionBudget;
    if(!is_finite($budget)||$budget<=0||($manifest['mode']==='section'&&$budget>$sectionBudget))
        return syndamorph_scope_error('budget','Enter an authorized total budget within the applicable section limit.',400);
    $manifest['brief']=trim($brief);
    $manifest['selected']=$manifest['mode']==='section'?$selection:null;
    if($layout!==null){
        $widths=$layout['widths']??null;
        $reason=is_string($layout['reason']??null)?sanitize_text_field($layout['reason']):null;
        if(!is_array($layout)||($layout['authorized']??false)!==true||
            !in_array($widths,array(array(768),array(375),array(768,375)),true)||
            !is_string($reason)||mb_strlen(trim($reason))<12||mb_strlen($reason)>240||!get_current_user_id())
            return syndamorph_scope_error('layout_authorization','Select exact compact widths and give a layout reason of 12 to 240 characters.',400);
        $manifest['layoutAuthorization']=array('scopeId'=>$manifest['id'],'mode'=>$manifest['mode'],
            'sourceUrl'=>$manifest['sourceUrl'],'widths'=>$widths,'reason'=>$reason,
            'approvedBy'=>get_current_user_id(),'approvedAt'=>gmdate('c'),
            'checkpoints'=>array_map(static fn($region)=>$region['checkpoint'],syndamorph_scope_targets($manifest)));
        syndamorph_history_append($manifest['id'],'source_layout_authorized',$manifest['layoutAuthorization']);
    }
    $manifest['budgetUsd']=$budget;
    $manifest['sectionBudgetUsd']=$sectionBudget;
    if(!array_filter(syndamorph_scope_targets($manifest),static fn($region)=>$region['status']==='ready'))$manifest['status']='uncompleted';
    syndamorph_history_append($manifest['id'],'scope_manifest',$manifest);
    return $manifest;
}

function syndamorph_scope_increase_budget(array $manifest,float $amount) {
    $sectionBudget=(float)($manifest['sectionBudgetUsd']??syndamorph_models_settings()['maxJobUsd']??0);
    if(!isset($manifest['budgetUsd'])||!is_finite($amount)||$amount<=$manifest['budgetUsd'] ||
        ($manifest['mode']==='section'&&$amount>$sectionBudget))
        return syndamorph_scope_error('budget','Enter a higher authorized total budget within the applicable section limit.',400);
    $manifest['budgetUsd']=$amount;
    $manifest['sectionBudgetUsd']=$sectionBudget;
    syndamorph_history_append($manifest['id'],'scope_manifest',$manifest);
    return syndamorph_scope_status($manifest);
}

function syndamorph_scope_repin_section(array $manifest,array $capture) {
    $key=$manifest['selected']??null;
    $previous=$manifest['regions'][$key]??null;
    if(isset($manifest['layoutAuthorization']))
        return syndamorph_scope_error('layout_authorization','Start a fresh source scope to authorize a layout change against a new capture.');
    if(!empty($previous['designDecisionRequired']))
        return syndamorph_scope_error('design_decision','The saved design requires a decision before replacing the evidence.');
    if($manifest['mode']!=='section'||!$previous||$previous['status']!=='failed'||!empty($previous['patternId'])||
        ($capture['url']??null)!==$manifest['sourceUrl'])
        return syndamorph_scope_error('repin','Only a failed Section on the same source URL can replace its evidence.');
    $rows=array_column($capture['sections']??array(),null,'id');
    $source=$rows[$key]??null;
    // A retained scope may only verify an interaction against its own archive.
    $archive=$manifest['referenceArchive']??null;
    $sameArchive=($capture['referenceArchive']['id']??null)===($archive['id']??null);
    if(!$source||$source['sourceRef']!==$previous['sourceRef']||syndamorph_source_blockers($source,$sameArchive?$archive:null))
        return syndamorph_scope_error('repin_evidence','The new capture cannot verify the same Section at all required widths.');
    $manifest['regions'][$key]['checkpoint']=syndamorph_history_append($manifest['id'],'scope_source',$source);
    $manifest['regions'][$key]['variants']=array_map(static fn($view)=>$view['sourceRef'],$source['variants']);
    syndamorph_history_append($manifest['id'],'scope_manifest',$manifest);
    return $manifest;
}

function syndamorph_scope_document(string $content,string $title): string {
    $blocks=array_values(array_filter(parse_blocks($content),static fn($block)=>$block['blockName']!==null));
    $body=syndamorph_native_core_preview($blocks);
    return '<!doctype html><html lang="'.esc_attr(get_bloginfo('language')).'"><head><meta charset="utf-8">'.
        '<meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc_html($title).'</title>'.
        '<style>'.syndamorph_native_visibility_css().'</style></head>'.
        '<body><main id="syndamorph-page">'.$body.'</main></body></html>';
}

function syndamorph_scope_page_qa(array $manifest,WP_Post $page): array {
    $source=syndamorph_history_latest($manifest['id'],'scope_page_source');
    if(array_column($source??array(),'width')!==SYNDAMORPH_SOURCE_WIDTHS ||
        array_filter($source,static fn($view)=>empty($view['ready'])||empty($view['whole'])||empty($view['fold'])))
        return array('passed'=>false,'reason'=>'Pinned full-page viewport evidence is incomplete.');
    foreach($source as $view) {
        $width=$view['width'];
        $order=array_values(array_map(static fn($region)=>$region['variants'][$width]??null,$manifest['regions']));
        if(($view['order']??null)!==$order)return array('passed'=>false,'reason'=>"Reconstructed section order differs from the source at {$width}px.");
    }
    $html=syndamorph_scope_document($page->post_content,$page->post_title);
    $build=null;
    $whole=syndamorph_collab_inspect($html,SYNDAMORPH_SOURCE_WIDTHS,$build,'page');
    $fold=syndamorph_scope_inspect($html,'page-fold');
    if(is_wp_error($whole)||is_wp_error($fold))return array('passed'=>false,'reason'=>'Reconstructed WordPress browser evidence is pending.',
        'inspection'=>syndamorph_inspection_diagnostic(is_wp_error($whole)?$whole:$fold));
    $layoutWidths=$manifest['layoutAuthorization']['widths']??array();
    if($layoutWidths){
        foreach($manifest['regions'] as $key=>$region){
            $expected=syndamorph_scope_layout_authorization($manifest,$key);
            $candidate=syndamorph_scope_candidate($manifest,$key);
            if(!$expected||empty($candidate['eligible'])||($candidate['sourceAcceptance']['layoutAuthorization']??null)!==$expected||
                ($region['artifactSha256']??null)!==$candidate['checksum'])
                return array('passed'=>false,'reason'=>'A region lacks matching source layout authorization and verified QA.');
        }
    }
    $wholeQa=syndamorph_source_visual(array_map(static fn($view)=>array('width'=>$view['width'],'extent'=>$view['height'],'screenshot'=>$view['whole']),$source),$whole,true,$layoutWidths);
    $foldQa=syndamorph_source_visual(array_map(static fn($view)=>array('width'=>$view['width'],'screenshot'=>$view['fold']),$source),$fold,false,$layoutWidths);
    $issues=syndamorph_collab_evidence($whole,$whole);
    $responsive=syndamorph_native_responsive_receipt($html,SYNDAMORPH_SOURCE_WIDTHS,$whole,$build,hash('sha256',$page->post_content));
    return array('passed'=>$wholeQa['passed']&&$foldQa['passed']&&!$issues&&$responsive['status']==='passed',
        'fullExtent'=>$wholeQa,'firstFold'=>$foldQa,'browserIssues'=>$issues,'continuousResponsive'=>$responsive,
        'renderSha256'=>hash('sha256',$html))+
        ($layoutWidths?array('layoutAuthorization'=>$manifest['layoutAuthorization']):array());
}

function syndamorph_scope_assemble(array $manifest) {
    if($manifest['mode']!=='page')return syndamorph_scope_error('mode','Only Full Page mode assembles a page draft.');
    $placement=array();$content=array();
    foreach($manifest['regions'] as $id=>$region) {
        if(empty($region['patternId']))continue;
        $pattern=get_post($region['patternId']);
        if(!$pattern||$pattern->post_type!=='wp_block'||get_post_meta($pattern->ID,'wp_pattern_sync_status',true)!=='unsynced'||
            !hash_equals($region['artifactSha256'],hash('sha256',$pattern->post_content)))
            return syndamorph_scope_error('pattern_changed','A saved region pattern changed. Its original quality evidence is no longer valid.');
        $placement[]=array('id'=>$id,'sourceRef'=>$region['sourceRef'],'patternId'=>$pattern->ID,
            'sourcePosition'=>$region['position'],'pagePosition'=>count($placement)+1,'contentSha256'=>$region['artifactSha256']);
        $content[]=$pattern->post_content;
    }
    if(!$content)return syndamorph_scope_error('empty','No verified region is available to assemble.');
    $content=implode("\n\n",$content);
    if(strlen($content)>2*1024*1024)return syndamorph_scope_error('page_size','The combined native draft exceeds the WordPress output limit.');
    $identity=$manifest['id'].':page';
    $saved=syndamorph_scope_find_post('page',$identity);
    $sha=hash('sha256',$content);
    if($saved && ($saved->post_status!=='draft'||(!hash_equals($sha,hash('sha256',$saved->post_content))&&
        !hash_equals((string)($manifest['page']['contentSha256']??''),hash('sha256',$saved->post_content)))))
        return syndamorph_scope_error('draft_changed','The existing WordPress page has been edited or moved out of draft status.');
    $complete=count($placement)===count($manifest['regions']);
    $title=($complete?'SyndaMorph draft':'SyndaMorph partial draft').' · '.$manifest['jobId'];
    $input=array('post_type'=>'page','post_status'=>'draft','post_title'=>$title,'post_content'=>$content,
        'meta_input'=>array('_syndamorph_scope_key'=>$identity));
    if($saved)$input['ID']=$saved->ID;
    $pageId=wp_insert_post(wp_slash($input),true);
    if(is_wp_error($pageId))return $pageId;
    $page=get_post($pageId);
    if(!$page||$page->post_status!=='draft'||!hash_equals($sha,hash('sha256',$page->post_content)))
        return syndamorph_scope_error('draft_write','WordPress did not preserve the assembled draft.');
    $previous=$manifest['page'];
    $manifest['page']=array('id'=>$pageId,'editor'=>get_edit_post_link($pageId,'raw'),'preview'=>get_preview_post_link($pageId),
        'contentSha256'=>$sha,'placements'=>$placement,
        'allRegions'=>$complete,'editorVerified'=>($previous['contentSha256']??'')===$sha&&!empty($previous['editorVerified']),
        'qa'=>array('passed'=>false,'reason'=>$complete?'Reconstructed page visual comparison pending.':'Required regions are still missing.'));
    $manifest['status']='uncompleted';
    update_post_meta($pageId,'_syndamorph_scope_manifest',$manifest['page']);
    syndamorph_history_append($manifest['id'],'scope_manifest',$manifest);
    if($complete) {
        $manifest['page']['qa']=syndamorph_scope_page_qa($manifest,$page);
        return syndamorph_scope_finish_page($manifest);
    }
    return $manifest;
}

function syndamorph_scope_finish_page(array $manifest): array {
    $manifest['status']=!empty($manifest['page']['allRegions'])&&!empty($manifest['page']['editorVerified'])&&
        !empty($manifest['page']['qa']['passed'])?'completed':'uncompleted';
    update_post_meta($manifest['page']['id'],'_syndamorph_scope_manifest',$manifest['page']);
    syndamorph_history_append($manifest['id'],'scope_manifest',$manifest);
    return $manifest;
}

function syndamorph_scope_verify_page(array $manifest,string $checksum,int $count) {
    $page=get_post($manifest['page']['id']??0);
    // get_post(0) may resolve the global post in a WordPress request.
    if(empty($manifest['page']['id'])||!$page||$page->post_status!=='draft'||!hash_equals($manifest['page']['contentSha256'],$checksum)||
        !hash_equals($checksum,hash('sha256',$page->post_content)))return syndamorph_scope_error('draft_changed','The draft changed before editor verification.');
    $actual=syndamorph_scope_block_count(parse_blocks($page->post_content));
    if($actual!==$count||$count<1||$count>7200)return syndamorph_scope_error('editor','The draft block count differs from Gutenberg verification.');
    $manifest['page']['editorVerified']=true;
    $manifest['page']['blockCount']=$count;
    return syndamorph_scope_finish_page($manifest);
}

function syndamorph_scope_block_count(array $nodes): int {
    $total=0;
    foreach($nodes as $node) {
        if(($node['blockName']??null)===null&&trim($node['innerHTML']??'')==='')continue;
        $name=$node['blockName']??'';
        if(!str_starts_with($name,'core/')&&$name!=='syndamorph/tabs')return -1;
        if($name==='syndamorph/tabs'&&syndamorph_tabs_decode($node['attrs']['panelData']??null)===null)return -1;
        $children=syndamorph_scope_block_count($node['innerBlocks']??array());
        if($children<0)return -1;
        $total+=1+$children;
    }
    return $total;
}

add_filter('template_include',static function(string $template): string {
    if(is_singular('page')&&preg_match('/^\d+:page$/D',(string)get_post_meta(get_queried_object_id(),'_syndamorph_scope_key',true)))
        return __DIR__.'/production-scope-template.php';
    return $template;
});

add_action('wp_enqueue_scripts',static function():void {
    wp_register_style('syndamorph-native-variants',false);
    wp_enqueue_style('syndamorph-native-variants');
    wp_add_inline_style('syndamorph-native-variants',syndamorph_native_visibility_css());
});

add_action('rest_api_init',static function():void {
    $permission=static fn():bool=>current_user_can('manage_options')&&current_user_can('edit_pages');
    register_rest_route('syndamorph/v1','/production-scope',array(
        'methods'=>'POST','permission_callback'=>$permission,
        'args'=>array('url'=>array('type'=>'string','required'=>true),'scope'=>array('type'=>'string','required'=>true,'enum'=>array('section','page'))),
        'callback'=>static function(WP_REST_Request $request) {
            $capture=syndamorph_capture_page($request['url'],'browser',true);
            if(is_wp_error($capture))return $capture;
            try { return syndamorph_scope_open($request['scope'],$capture); }
            catch(Throwable $error) { return syndamorph_scope_error('archive','Source evidence could not be pinned in History.',503); }
        },
    ));
    register_rest_route('syndamorph/v1','/production-scope/(?P<id>[0-9]+)',array(
        array('methods'=>'GET','permission_callback'=>$permission,'callback'=>static function(WP_REST_Request $request) {
            $manifest=syndamorph_history_latest((int)$request['id'],'scope_manifest');
            if(!$manifest)return syndamorph_scope_error('missing','Scope checkpoint not found.',404);
            $region=(string)$request->get_param('region');
            if($region!=='') {
                $candidate=syndamorph_scope_candidate($manifest,$region);
                if(!$candidate)return syndamorph_scope_error('artifact','This region has no compiled artifact.',404);
                return $candidate;
            }
            if($request->get_param('draft')==='1') {
                $page=empty($manifest['page']['id'])?null:get_post($manifest['page']['id']);
                if(!$page||$page->post_status!=='draft')return syndamorph_scope_error('draft','The assembled draft is unavailable.',404);
                return array('content'=>$page->post_content,'checksum'=>hash('sha256',$page->post_content));
            }
            if($request->get_param('preview')==='1') {
                $selected=(string)$request->get_param('selected');
                $checkpoint=$manifest['regions'][$selected]['checkpoint']??null;
                $source=$checkpoint?syndamorph_scope_checkpoint($manifest['id'],$checkpoint):null;
                if(!$source)return syndamorph_scope_error('source','The selected source is unavailable.',404);
                $view=$source['variants'][1440]??reset($source['variants']);
                return array('html'=>$view['html']??null,'id'=>$source['id'],'label'=>$source['label']);
            }
            return syndamorph_scope_status($manifest);
        }),
        array('methods'=>'POST','permission_callback'=>$permission,'callback'=>static function(WP_REST_Request $request) {
            $action=$request->get_param('action');
            if(!in_array($action,array('configure','increase_budget','recapture','run','retry','review_staged','resume_design_review','resume_checkpoint','save_pattern','reuse_section','verify_disclosure','assemble','verify_page'),true))
                return syndamorph_scope_error('action','Invalid scope action.',400);
            return syndamorph_scope_locked(static function()use($request,$action){
                $manifest=syndamorph_history_latest((int)$request['id'],'scope_manifest');
                if(!$manifest)return syndamorph_scope_error('missing','Scope checkpoint not found.',404);
                $region=(string)$request->get_param('region');
                try {
                    switch($action) {
                        case 'configure':
                            $limit=$request->get_param('limitUsd');
                            if($limit!==null && (!is_numeric($limit)||(float)$limit<=0))
                                return syndamorph_scope_error('budget','Invalid budget cap.',400);
                            return syndamorph_scope_configure($manifest,(string)$request->get_param('brief'),$region,
                                $limit===null?null:(float)$limit,$request->get_param('sourceLayoutChange'));
                        case 'increase_budget':
                            $limit=$request->get_param('limitUsd');
                            if(!is_numeric($limit))return syndamorph_scope_error('budget','Enter a valid total budget.',400);
                            return syndamorph_scope_increase_budget($manifest,(float)$limit);
                        case 'recapture':
                            $manifest=syndamorph_scope_status($manifest);
                            if(!empty($manifest['regions'][$manifest['selected']??'']['designDecisionRequired']))
                                return syndamorph_scope_error('design_decision','The saved design requires a decision before replacing the evidence.');
                            if(isset($manifest['layoutAuthorization']))
                                return syndamorph_scope_error('layout_authorization','Start a fresh source scope to authorize a layout change against a new capture.');
                            if($manifest['mode']!=='section'||($manifest['regions'][$manifest['selected']??'']['status']??'')!=='failed'||
                                !empty($manifest['regions'][$manifest['selected']??'']['recoveryStage']))
                                return syndamorph_scope_error('repin','Only a failed Section can recapture its source.');
                            $capture=syndamorph_capture_page($manifest['sourceUrl'],'browser',true);
                            return is_wp_error($capture)?$capture:syndamorph_scope_repin_section($manifest,$capture);
                        case 'run':
                        case 'retry':
                        case 'review_staged':
                        case 'resume_design_review':
                        case 'resume_checkpoint':
                            if(true!==$request->get_param('confirmed'))return syndamorph_scope_error('approval','Explicit paid production confirmation is required.',400);
                            ignore_user_abort(true);
                            if($action==='review_staged'){
                                $originId=filter_var($request->get_param('candidateScopeId'),FILTER_VALIDATE_INT,array('options'=>array('min_range'=>1)));
                                if($originId===false)return syndamorph_scope_error('candidate','An exact candidate scope ID is required.',400);
                                return syndamorph_scope_review_staged(syndamorph_scope_status($manifest),$region,$originId);
                            }
                            if(in_array($action,array('resume_design_review','resume_checkpoint'),true)){
                                $workOrderEdits=$request->get_param('workOrderEdits')??array();
                                $wrapId=$request->get_param('stagedParagraphId');
                                if(!is_array($workOrderEdits))return syndamorph_scope_error('work_order_evidence','Invalid work-order corrections.',400);
                                if($wrapId!==null&&!is_string($wrapId))return syndamorph_scope_error('review_surgery','Invalid staged Paragraph identity.',400);
                                return syndamorph_scope_resume_checkpoint($manifest,$region,array(),null,$workOrderEdits,$wrapId);
                            }
                            return syndamorph_scope_run(syndamorph_scope_status($manifest),$region,$action==='retry');
                        case 'save_pattern': return syndamorph_scope_save_pattern($manifest,$region);
                        case 'verify_disclosure': return syndamorph_scope_verify_disclosure($manifest,$region);
                        case 'reuse_section':
                            $sectionId=filter_var($request->get_param('sectionId'),FILTER_VALIDATE_INT,array('options'=>array('min_range'=>1)));
                            if($sectionId===false)return syndamorph_scope_error('reuse_scope','An exact saved Section ID is required.',400);
                            return syndamorph_scope_reuse_section($manifest,$sectionId,$region);
                        case 'assemble': return syndamorph_scope_assemble($manifest);
                        case 'verify_page':
                            if(true!==$request->get_param('valid'))return syndamorph_scope_error('editor','A Gutenberg validation result is required.',400);
                            return syndamorph_scope_verify_page($manifest,(string)$request->get_param('checksum'),(int)$request->get_param('blockCount'));
                    }
                } catch(Throwable $error) {
                    return syndamorph_scope_error('archive','The action stopped while saving its checkpoint. Reload the scope before retrying.',503);
                }
            });
        }),
    ));
});
