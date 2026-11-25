<?php
/** Local Section and Full Page adapters around the real AI Production Team. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/production-source.php';
require_once __DIR__.'/production-scope-source.php';

const SYNDAMORPH_SCOPE_WORKFLOW = 'source-scope-v1';

function syndamorph_scope_error(string $code,string $message,int $status=409): WP_Error {
    return new WP_Error('sm_scope_'.$code,$message,array('status'=>$status));
}

function syndamorph_scope_open(string $mode,array $capture) {
    if(!in_array($mode,array('section','page'),true)||!is_string($capture['url']??null)||
        !is_array($capture['sections']??null)||!$capture['sections']||count($capture['sections'])>180||
        array_column($capture['views']??array(),'width')!==SYNDAMORPH_SOURCE_WIDTHS)
        return syndamorph_scope_error('capture','Responsive discovery produced no usable manifest.',422);
    $regions=array();
    foreach($capture['sections'] as $source) {
        $key=$source['id']??'';
        if(!is_string($key)||!preg_match('/^[a-f0-9]{24}$/D',$key)||isset($regions[$key])||!is_string($source['sourceRef']??null))
            return syndamorph_scope_error('identity','Discovery returned an invalid or duplicate region identity.',422);
        $blockers=syndamorph_source_blockers($source);
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
            'browserErrors'=>count($view['browserErrors']??array())),$capture['views']));
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

function syndamorph_scope_account(int $id): array {
    global $wpdb;
    list(,$events)=syndamorph_history_tables();
    $rows=$wpdb->get_results($wpdb->prepare("SELECT event_type,payload FROM $events WHERE job_id=%d AND event_type IN ('agent_complete','agent_failure') ORDER BY id",$id),ARRAY_A);
    $total=0.0;
    $completed=0;
    $usage=array_fill_keys(array('inputTokens','outputTokens','cacheReadTokens','cacheWriteTokens'),0);
    $known=is_array($rows);
    foreach($rows??array() as $row) {
        if($row['event_type']==='agent_complete')$completed++;
        $metric=json_decode($row['payload'],true)['metric']??array();
        $total+=(float)($metric['estimatedCostUsd']??0);
        foreach($usage as $key=>$_)$usage[$key]+=(int)($metric[$key]??0);
        $known=$known&&($metric['metering']??'')==='provider_reported';
    }
    return array('estimatedCostUsd'=>round($total,6),'meteringComplete'=>$known,'usage'=>$usage,'completedCalls'=>$completed);
}

function syndamorph_scope_status(array $manifest): array {
    global $wpdb;
    list($jobs)=syndamorph_history_tables();
    $manifest['spentUsd']=0.0;
    $manifest['meteringComplete']=true;
    $manifest['outputTokens']=0;
    foreach($manifest['regions'] as &$region) {
        foreach($region['attempts'] as &$attempt) {
            $row=$wpdb->get_row($wpdb->prepare("SELECT status,updated_at FROM $jobs WHERE id=%d",$attempt['id']),ARRAY_A);
            $status=$row['status']??'interrupted';
            if($status==='running'&&strtotime($row['updated_at'].' UTC')<time()-1500)$status='interrupted';
            $account=syndamorph_scope_account($attempt['id']);
            if(in_array($status,array('running','interrupted'),true))$account['meteringComplete']=false;
            $attempt=array_merge($attempt,$account,array('status'=>$status));
            $manifest['spentUsd']+=$attempt['estimatedCostUsd'];
            $manifest['outputTokens']+=$attempt['usage']['outputTokens'];
            $manifest['meteringComplete']=$manifest['meteringComplete']&&$attempt['meteringComplete'];
        }
        unset($attempt);
        if($region['attempts']) {
            $last=end($region['attempts']);
            $region['status']=$region['patternId']?'pattern_saved':$last['status'];
            unset($region['checkpointAvailable'],$region['issue']);
            if(in_array($last['status'],array('failed','uncompleted','needs_review'),true)) {
                $failure=syndamorph_history_latest($last['id'],'job_error');
                $final=syndamorph_history_latest($last['id'],'job_complete');
                $region['checkpointAvailable']=!empty($failure['referenceCheckpoint'])||!empty($final['referenceCheckpoint']);
                $region['issue']=$failure['message']??$final['quality']['issues'][0]['detail']??null;
            }
        }
    }
    unset($region);
    $manifest['spentUsd']=round($manifest['spentUsd'],6);
    return $manifest;
}

function syndamorph_scope_run(array $manifest,string $key,bool $retry,?callable $engine=null) {
    $region=$manifest['regions'][$key]??null;
    if(!$region||($manifest['mode']==='section'&&$manifest['selected']!==$key))
        return syndamorph_scope_error('selection','This region does not belong to the active scope.');
    $eligible=$retry?array('failed','uncompleted'):array('ready');
    if(!in_array($region['status'],$eligible,true)||!empty($region['checkpointAvailable']))
        return syndamorph_scope_error('region_state','Use the existing artifact or browser checkpoint for this region.');
    if(empty($manifest['budgetUsd'])||empty($manifest['meteringComplete'])||$manifest['spentUsd']>=$manifest['budgetUsd']*0.8)
        return syndamorph_scope_error('budget','No verified remaining budget for another section.');
    if(get_transient('syndamorph_ai_real_job_lock'))return syndamorph_scope_error('busy','Another AI production job is running.',429);
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    if(!$source||$source['id']!==$key)return syndamorph_scope_error('checkpoint','Pinned source checkpoint is unavailable.');
    $available=$manifest['budgetUsd']-$manifest['spentUsd'];
    $availableWork=$manifest['budgetUsd']*0.8-$manifest['spentUsd'];
    $brief=$manifest['brief'];
    $settings=syndamorph_models_settings();
    $available=min($available,(float)($manifest['sectionBudgetUsd']??$settings['maxJobUsd']??0));
    $engineSource=syndamorph_scope_engine_source($source,$manifest['sourceUrl']);
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
        $result=($engine??'syndamorph_collab_run')($brief,true,$emit,true,$engineSource,$available,$availableWork);
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

function syndamorph_scope_candidate(array $manifest,string $key): ?array {
    if($manifest['mode']==='section'&&$manifest['selected']!==$key)return null;
    $region=$manifest['regions'][$key]??null;
    if(empty($region['attempts']))return null;
    $attempt=end($region['attempts']);
    $final=syndamorph_history_latest($attempt['id'],'job_complete');
    if(empty($final['artifact']['content']))return null;
    $checksum=hash('sha256',$final['artifact']['content']);
    return array('id'=>$attempt['id'],'content'=>$final['artifact']['content'],
        'checksum'=>$checksum,'sourceAcceptance'=>$final['sourceAcceptance']??null,
        'eligible'=>!empty($final['sourceAcceptance']['passed'])&&!empty($final['quality']['bossApproved'])&&
            ($final['sourceAcceptance']['id']??null)===$key&&hash_equals((string)($final['sourceAcceptance']['artifactSha256']??''),$checksum)&&
            !empty($final['quality']['visionInspected'])&&!empty($final['quality']['browserInspected'])&&empty($final['quality']['issues']));
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

function syndamorph_scope_targets(array $manifest): array {
    return $manifest['mode']==='page'?$manifest['regions']:
        array_intersect_key($manifest['regions'],array_flip(array($manifest['selected']??'')));
}

function syndamorph_scope_configure(array $manifest,string $brief,?string $selection,?float $limit=null) {
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
    if($manifest['mode']!=='section'||!$previous||$previous['status']!=='failed'||!empty($previous['patternId'])||
        ($capture['url']??null)!==$manifest['sourceUrl'])
        return syndamorph_scope_error('repin','Only a failed Section on the same source URL can replace its evidence.');
    $rows=array_column($capture['sections']??array(),null,'id');
    $source=$rows[$key]??null;
    if(!$source||$source['sourceRef']!==$previous['sourceRef']||syndamorph_source_blockers($source))
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
    $whole=syndamorph_scope_inspect($html,'page');
    $fold=syndamorph_scope_inspect($html,'page-fold');
    if(is_wp_error($whole)||is_wp_error($fold))return array('passed'=>false,'reason'=>'Reconstructed WordPress browser evidence is pending.',
        'inspection'=>syndamorph_inspection_diagnostic(is_wp_error($whole)?$whole:$fold));
    $wholeQa=syndamorph_source_visual(array_map(static fn($view)=>array('width'=>$view['width'],'extent'=>$view['height'],'screenshot'=>$view['whole']),$source),$whole);
    $foldQa=syndamorph_source_visual(array_map(static fn($view)=>array('width'=>$view['width'],'screenshot'=>$view['fold']),$source),$fold,false);
    $issues=syndamorph_collab_evidence($whole,$whole);
    return array('passed'=>$wholeQa['passed']&&$foldQa['passed']&&!$issues,'fullExtent'=>$wholeQa,'firstFold'=>$foldQa,'browserIssues'=>$issues,
        'renderSha256'=>hash('sha256',$html));
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
        if(!str_starts_with($node['blockName']??'','core/'))return -1;
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
            if(!in_array($action,array('configure','increase_budget','recapture','run','retry','save_pattern','assemble','verify_page'),true))
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
                            return syndamorph_scope_configure($manifest,(string)$request->get_param('brief'),$region,$limit===null?null:(float)$limit);
                        case 'increase_budget':
                            $limit=$request->get_param('limitUsd');
                            if(!is_numeric($limit))return syndamorph_scope_error('budget','Enter a valid total budget.',400);
                            return syndamorph_scope_increase_budget($manifest,(float)$limit);
                        case 'recapture':
                            $manifest=syndamorph_scope_status($manifest);
                            if($manifest['mode']!=='section'||($manifest['regions'][$manifest['selected']??'']['status']??'')!=='failed')
                                return syndamorph_scope_error('repin','Only a failed Section can recapture its source.');
                            $capture=syndamorph_capture_page($manifest['sourceUrl'],'browser',true);
                            return is_wp_error($capture)?$capture:syndamorph_scope_repin_section($manifest,$capture);
                        case 'run':
                        case 'retry':
                            if(true!==$request->get_param('confirmed'))return syndamorph_scope_error('approval','Explicit paid production confirmation is required.',400);
                            ignore_user_abort(true);
                            return syndamorph_scope_run(syndamorph_scope_status($manifest),$region,$action==='retry');
                        case 'save_pattern': return syndamorph_scope_save_pattern($manifest,$region);
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
