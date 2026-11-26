<?php
/** CLI only. Exercises private scope checkpoints and mocks every provider response. */
require '/var/www/html/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once WP_PLUGIN_DIR.'/syndamorph/syndamorph.php';
if(PHP_SAPI!=='cli')exit(1);

function scope_expect($value,string $message): void {
    if(!$value)throw new RuntimeException($message);
}
function scope_ok($value) {
    if(is_wp_error($value))throw new RuntimeException($value->get_error_code().': '.$value->get_error_message());
    return $value;
}

$username=getenv('SM_SCOPE_USER')?:'ciadmin';
$user=get_user_by('login',$username);
if(!$user&&getenv('SM_SCOPE_PASSWORD')) {
    $id=scope_ok(wp_create_user($username,getenv('SM_SCOPE_PASSWORD'),$username.'@example.invalid'));
    $user=get_user_by('id',$id);
    $user->set_role('administrator');
}
scope_expect($user,'No fixture administrator found.');
wp_set_current_user($user->ID);
if(!is_plugin_active('syndamorph/syndamorph.php'))scope_ok(activate_plugin('syndamorph/syndamorph.php'));

$stubKey='offline-scope-fixture-key';
$model='claude-scope-fixture';
$stubSettings=array('provider'=>'anthropic','model'=>$model,'inputPrice'=>0.1,'outputPrice'=>0.5,'maxJobUsd'=>0.15);
add_filter('pre_option_syndamorph_ai_key_anthropic',static fn()=>syndamorph_ai_encrypt($stubKey));
add_filter('pre_option_syndamorph_ai_model_settings',static fn()=>$stubSettings);
add_filter('pre_transient_syndamorph_ai_models_anthropic',static fn()=>array('models'=>array(array('id'=>$model,'display_name'=>$model))));
$fixtures=array();
foreach(array('North','Centre','South') as $i=>$label) {
    $id=substr(hash('sha256',$label),0,24);
    $heading=static fn($text)=>array('type'=>'heading','text'=>$text,'style'=>array('fontSize'=>'1.75rem','lineHeight'=>1.2));
    $children=$i===1?array(
        array('type'=>'group','style'=>array('visibility'=>'desktop'),'children'=>array($heading($label.' desktop'))),
        array('type'=>'group','style'=>array('visibility'=>'compact'),'children'=>array($heading($label.' compact'))),
    ):array($heading($label));
    $children[]=array('type'=>'paragraph','text'=>'Literal source content for '.$label.'.');
    $plan=array('version'=>1,'blocks'=>array(array('type'=>'group','children'=>$children,'style'=>array('justifyContent'=>'stretch',
        'minHeight'=>'22rem','padding'=>'1.5rem','background'=>array('#ddf0ed','#e9e4f5','#f4ead7')[$i],'color'=>'#172025'))));
    $artifact=scope_ok(syndamorph_compile_block_plan(wp_json_encode($plan)));
    $designer=preg_replace('/<!--[^>]*-->/','',$artifact['preview']);
    $designer=preg_replace('/<div(?= [^>]*data-sm-node="blocks\[0\]")/','<section',$designer,1);
    $designer=preg_replace('/<\/div>\s*$/','</section>',$designer,1);
    scope_expect(str_contains($designer,'<section '),'Designer reference root substitution failed.');
    $fixtures[$id]=array('label'=>$label,'plan'=>$plan,'artifact'=>$artifact,'designer'=>$designer);
}
$active=null;$calls=array();$interruptReview=false;
add_filter('pre_http_request',static function($pre,$args,$url)use($model,$stubKey,&$calls,&$active,&$interruptReview){
    if(!str_starts_with($url,'https://api.anthropic.com/'))return $pre;
    scope_expect(($args['headers']['x-api-key']??'')===$stubKey,'Offline provider harness received an unexpected credential.');
    if(str_contains($url,'/models?'))$response=array('data'=>array(array('id'=>$model,'display_name'=>$model)),'has_more'=>false);
    else {
        scope_expect($active!==null,'Unplanned model request.');
        $request=json_decode($args['body'],true);
        $system=$request['system'][1]['text']??'';
        preg_match('/Task: ([a-z_]+)\./',$system,$match);
        $task=$match[1]??'';
        $calls[]=$active['label'].':'.$task;
        if($task==='final_approval'&&$interruptReview)return new WP_Error('http_request_failed','Fixture review timeout.');
        $input=json_decode($request['messages'][0]['content'][0]['text'],true);
        if($task==='designer'||$task==='developer') {
            scope_expect(count($input['capturedSource']['views']??array())===3,'AI engine lost responsive source variants.');
            if($task==='designer')scope_expect(count($request['messages'][0]['content'])===4,'Designer did not receive pinned source screenshots.');
        }
        $text=match($task) {
            'boss'=>wp_json_encode(array('title'=>$active['label'],'sections'=>array($active['label']),'constraints'=>array(),'acceptance'=>array('Preserve capture.'))),
            'designer'=>$active['designer'],
            'developer'=>wp_json_encode($active['plan']),
            'final_approval','validation'=>'{"decision":"approved","issues":[]}',
            default=>throw new RuntimeException('Unexpected offline provider task: '.$task),
        };
        $response=array('model'=>$model,'stop_reason'=>'end_turn','content'=>array(array('type'=>'text','text'=>$text)),
            'usage'=>array('input_tokens'=>100,'output_tokens'=>250));
    }
    return array('headers'=>array(),'response'=>array('code'=>200),'body'=>wp_json_encode($response));
},10,3);

$engine=static function($brief,$stream,$emit,$learning,$source,$remaining,$remainingWork,$resume=null)use(&$active,$fixtures){
    $active=$fixtures[$source['section']['id']];
    return syndamorph_collab_run($brief,false,$emit,false,$source,$remaining,$remainingWork,$resume);
};
$keys=array_keys($fixtures);
$phase=getenv('SM_SCOPE_TEST_PHASE')?:'setup';
if($phase==='setup') {
    $capture=array('url'=>'https://fixture.invalid/offline-scope','sections'=>array(),'views'=>array());
    foreach($fixtures as $key=>$fixture) {
        $evidence=scope_ok(syndamorph_scope_inspect($fixture['designer'],'section'));
        $variants=array();
        foreach($evidence as $i=>$sample) {
            $width=$sample['width'];
            $literal=$sample['snapshot']['literal'];
            $variants[$width]=array('sourceRef'=>'[data-fixture='.$key.'-'.$width.']','bounds'=>array('height'=>$sample['extent']),
                'inventory'=>array('text'=>$literal['text'],'links'=>$literal['links'],'assets'=>array(),'controls'=>array()),
                'facts'=>array('layoutIdentity'=>$literal['layoutIdentity']),
                'html'=>$fixture['designer'],'screenshot'=>$sample['screenshot']);
        }
        $capture['sections'][]=array('id'=>$key,'label'=>$fixture['label'],'kind'=>'content','variants'=>$variants,'sourceRef'=>$variants[1440]['sourceRef']);
    }
    $pageDocument=syndamorph_scope_document(implode("\n\n",array_map(static fn($f)=>$f['artifact']['content'],$fixtures)),'Offline reference');
    $whole=scope_ok(syndamorph_scope_inspect($pageDocument,'page'));
    $fold=scope_ok(syndamorph_collab_inspect($pageDocument,SYNDAMORPH_SOURCE_WIDTHS));
    foreach($whole as $i=>$sample)$capture['views'][]=array('width'=>$sample['width'],'height'=>$sample['extent'],
        'whole'=>$sample['screenshot'],'fold'=>$fold[$i]['screenshot'],'ready'=>true,
        'order'=>array_map(static fn($r)=>$r['variants'][$sample['width']]['sourceRef'],$capture['sections']));
    $brief='Convert the offline fixture and preserve the literal section content.';
    $section=scope_ok(syndamorph_scope_configure(scope_ok(syndamorph_scope_open('section',$capture)),$brief,$keys[0]));
    scope_expect(is_wp_error(syndamorph_scope_run($section,$keys[1],false,$engine)),'Single section accepted unrelated identity.');
    $section=scope_ok(syndamorph_scope_run($section,$keys[0],false,$engine));
    scope_expect($section['regions'][$keys[0]]['status']==='needs_review',wp_json_encode($section['regions'][$keys[0]]));
    scope_expect(is_wp_error(syndamorph_scope_save_pattern($section,$keys[0])),'Pattern was saved before editor validation.');
    $page=scope_ok(syndamorph_scope_configure(scope_ok(syndamorph_scope_open('page',$capture)),$brief,null,0.24));
    scope_expect($page['budgetUsd']===0.24&&$page['sectionBudgetUsd']===0.15,'Full Page did not retain a separate aggregate budget.');
    $page=scope_ok(syndamorph_scope_run($page,$keys[0],false,$engine));
    $page=scope_ok(syndamorph_scope_run($page,$keys[1],false,static fn()=>new WP_Error('sm_fixture_failure','Injected isolated section failure.')));
    $page=scope_ok(syndamorph_scope_run($page,$keys[2],false,$engine));
    scope_expect(array_column($page['regions'],'status')===array('needs_review','failed','needs_review'),wp_json_encode(array_column($page['regions'],'status')));
    scope_expect(count($calls)===15,'Expected five shared agent tasks in each of three successful offline section jobs.');
    $tiny=scope_ok(syndamorph_scope_configure(scope_ok(syndamorph_scope_open('section',$capture)),$brief,$keys[0],0.000001));
    $tiny=scope_ok(syndamorph_scope_run($tiny,$keys[0],false,$engine));
    scope_expect($tiny['regions'][$keys[0]]['status']==='failed'&&count($calls)===15&&$tiny['spentUsd']===0.0,'Budget was not enforced before provider invocation.');
    $mismatch=$capture;
    $mismatch['sections'][0]['variants'][375]['inventory']['text'][]='A captured sentence omitted by the Designer.';
    $rejected=scope_ok(syndamorph_scope_configure(scope_ok(syndamorph_scope_open('section',$mismatch)),$brief,$keys[0]));
    $rejected=scope_ok(syndamorph_scope_run($rejected,$keys[0],false,$engine));
    $attempt=$rejected['regions'][$keys[0]]['attempts'][0];
    $failure=syndamorph_history_latest($attempt['id'],'job_error');
    $decision=syndamorph_history_latest($attempt['id'],'agent_decision');
    $gate=syndamorph_history_latest($attempt['id'],'execution_gate');
    scope_expect($failure['code']==='sm_source_fidelity'&&$failure['completedCalls']===3&&count($calls)===18&&
        $decision['decision']==='approved'&&$gate['executionStatus']==='blocked','Source fidelity failure must block advancement without rewriting Boss approval or losing cost accounting.');
    $layoutSource=$capture['sections'][0];
    $authorization=array('authorized'=>true,'widths'=>array(375),'reason'=>'Reduce whitespace in the compact layout.');
    $unconfigured=scope_ok(syndamorph_scope_open('section',$capture));
    $wrongWidth=array_merge($authorization,array('widths'=>array(1440,375)));
    scope_expect(is_wp_error(syndamorph_scope_configure($unconfigured,$brief,$keys[0],null,$wrongWidth)),
        'Desktop source layout authorization was accepted.');
    $layoutScope=scope_ok(syndamorph_scope_configure($unconfigured,$brief,$keys[0],null,$authorization));
    $pinned=syndamorph_scope_layout_authorization($layoutScope,$keys[0]);
    scope_expect($pinned['widths']===array(375)&&$pinned['approvedBy']===$user->ID&&
        syndamorph_scope_layout_authorization($layoutScope,$keys[1])===null,'Layout authorization was not pinned to the selected region and account.');
    $tabletOnly=array_merge($pinned,array('widths'=>array(768)));
    $north=$fixtures[$keys[0]]['designer'];
    $geometry='<style>@media(max-width:400px){[data-sm-node="blocks[0]"]{min-height:14rem !important}}</style>'.$north;
    $strict=syndamorph_source_review($layoutSource,$geometry);
    $allowed=syndamorph_source_review($layoutSource,$geometry,$pinned);
    scope_expect(!$strict['passed']&&$allowed['passed']&&$allowed['visual']['views'][2]['layoutDeviationAuthorized']&&
        !$allowed['visual']['views'][2]['sourcePixelAndHeightPassed'],'The approved layout test did not retain and authorize the original mobile mismatch.');
    scope_expect(!syndamorph_source_review($layoutSource,$geometry,$tabletOnly)['passed'],'Tablet approval silently authorized a mobile change.');
    $wrongPaint='<style>@media(max-width:400px){[data-sm-node="blocks[0]"]{background:red !important}}</style>'.$geometry;
    scope_expect(!syndamorph_source_review($layoutSource,$wrongPaint,$pinned)['passed'],'Layout approval waived protected paint.');
    scope_expect(!syndamorph_source_review($layoutSource,str_replace('Literal source content','Changed source content',$geometry),$pinned)['passed'],
        'Layout approval waived required copy.');
    $inventedLink=str_replace('</section>','<a href="#">Preview link</a></section>',$north);
    $linkReview=syndamorph_source_review($layoutSource,$inventedLink);
    scope_expect(!$linkReview['passed']&&$linkReview['markup']['views'][0]['linkDifference']['expectedCount']===0&&
        $linkReview['markup']['views'][0]['linkDifference']['observed'][0]['href']==='#',
        'The scoped source gate did not provide exact evidence for an invented link.');
    $shortCapture=$capture;
    $shortSample=scope_ok(syndamorph_scope_inspect($geometry,'section'))[2];
    $shortCapture['sections'][0]['variants'][375]['screenshot']=$shortSample['screenshot'];
    $shortCapture['sections'][0]['variants'][375]['bounds']['height']=$shortSample['extent'];
    $authorizedJob=scope_ok(syndamorph_scope_configure(scope_ok(syndamorph_scope_open('section',$shortCapture)),
        $brief,$keys[0],null,array_merge($authorization,array('reason'=>'Increase compact layout room for the text.'))));
    $authorizedJob=scope_ok(syndamorph_scope_run($authorizedJob,$keys[0],false,$engine));
    $candidate=syndamorph_scope_candidate($authorizedJob,$keys[0]);
    scope_expect(!empty($candidate['eligible'])&&$candidate['sourceAcceptance']['layoutAuthorization']===
        syndamorph_scope_layout_authorization($authorizedJob,$keys[0]),
        'The authenticated source layout authorization did not survive production and native QA.');
    $archived=syndamorph_history_latest($candidate['id'],'job_complete');
    $archived['status']='uncompleted';
    $archived['quality']['terminationReason']='native_responsive_pending';
    $archived['quality']['continuousResponsive']['status']='pending';
    syndamorph_history_append($candidate['id'],'job_complete',$archived);
    scope_expect(empty(syndamorph_scope_candidate($authorizedJob,$keys[0])['eligible']),
        'Pending native responsive QA could promote an artifact.');
    $request=new WP_REST_Request('POST','/syndamorph/v1/history/'.$candidate['id'].'/reinspect-reference');
    $response=rest_do_request($request);
    $rechecked=$response->get_data();
    scope_expect($response->get_status()===200&&($rechecked['responsive']['status']??null)==='passed'&&
        $rechecked['aiCalls']===0&&$rechecked['status']==='needs_review'&&
        syndamorph_history_latest($candidate['id'],'job_complete')===$archived&&
        !empty(syndamorph_scope_candidate($authorizedJob,$keys[0])['eligible']),
        'Independent DOM reinspection failed to recover the exact archived artifact: '.wp_json_encode($rechecked));
    $recover=scope_ok(syndamorph_scope_configure(scope_ok(syndamorph_scope_open('section',$capture)),$brief,$keys[0]));
    $interruptReview=true;
    $recover=scope_ok(syndamorph_scope_run($recover,$keys[0],false,$engine));
    $interruptReview=false;
    $prior=$recover['regions'][$keys[0]]['attempts'][0];
    $priorCalls=count($calls);
    scope_expect($recover['regions'][$keys[0]]['designReviewRecovery']&&$recover['costBounded']&&!$recover['meteringComplete']&&
        $prior['completedCalls']===2&&$prior['unmeteredReserveUsd']>0&&
        is_wp_error(syndamorph_scope_run($recover,$keys[0],true,$engine)),
        'Timed-out review did not retain the current GEN and its conservative reservation.');
    $badEdit=array(array('find'=>'</section>','replace'=>'<a href="#">An invented link</a></section>'));
    $blocked=syndamorph_scope_resume_checkpoint($recover,$keys[0],$badEdit,$engine);
    scope_expect(is_wp_error($blocked)&&$blocked->get_error_code()==='sm_scope_review_regression'&&count($calls)===$priorCalls,
        'A recovery patch that changes source copy or links must hold before paid review.');
    $recover=scope_ok(syndamorph_scope_resume_checkpoint($recover,$keys[0],array(),$engine));
    $attempts=$recover['regions'][$keys[0]]['attempts'];
    $savedRecovery=syndamorph_history_latest($prior['id'],'job_resumed');
    scope_expect(count($attempts)===1&&$attempts[0]['id']===$prior['id']&&$attempts[0]['status']==='needs_review'&&
        $attempts[0]['completedCalls']===5&&$attempts[0]['estimatedCostUsd']>$prior['estimatedCostUsd']&&
        $attempts[0]['unmeteredReserveUsd']===$prior['unmeteredReserveUsd']&&$savedRecovery['priorCompletedCalls']===2&&
        array_slice($calls,$priorCalls)===array('North:final_approval','North:developer','North:validation'),
        'Design review recovery repeated completed work, created a GEN, or lost the original accounting.');
    $planning=scope_ok(syndamorph_scope_configure(scope_ok(syndamorph_scope_open('section',$capture)),$brief,$keys[0]));
    $order=wp_json_encode(array('version'=>1,'title'=>'North','sections'=>array(array('id'=>'north','label'=>'North')),
        'requirements'=>array(array('id'=>'north-paragraph','target'=>'north','dimension'=>'content','basis'=>'source',
            'requirement'=>'Preserve the North paragraph.','acceptance'=>'Literal paragraph is present.',
            'check'=>array('kind'=>'text','selector'=>'p','value'=>'Literal source content for North.')))));
    $planning=scope_ok(syndamorph_scope_run($planning,$keys[0],false,static function($brief,$stream,$emit)use($order){
        $stage=array('role'=>'boss','task'=>'boss');
        $emit('agent_start',$stage);
        $emit('agent_delta',$stage+array('text'=>$order));
        $emit('agent_complete',$stage+array('metric'=>array('metering'=>'provider_reported','inputTokens'=>100,
            'outputTokens'=>250,'estimatedCostUsd'=>0.000135)));
        return new WP_Error('sm_boss_contract','Fixture older work-order parser rejected the check.',array('field'=>'requirement check'));
    }));
    $planningId=$planning['regions'][$keys[0]]['attempts'][0]['id'];
    $priorCalls=count($calls);
    scope_expect($planning['regions'][$keys[0]]['recoveryStage']==='planning'&&
        is_wp_error(syndamorph_scope_run($planning,$keys[0],true,$engine)),'The prior work order needs a checkpoint, not a new GEN.');
    $planning=scope_ok(syndamorph_scope_resume_checkpoint($planning,$keys[0],array(),$engine));
    $attempts=$planning['regions'][$keys[0]]['attempts'];
    $record=syndamorph_history_latest($planningId,'job_resumed');
    scope_expect(count($attempts)===1&&$attempts[0]['id']===$planningId&&$attempts[0]['status']==='needs_review'&&
        $attempts[0]['completedCalls']===5&&$record['planningSha256']===hash('sha256',$order)&&
        array_slice($calls,$priorCalls)===array('North:designer','North:final_approval','North:developer','North:validation'),
        'Planning recovery repeated Boss classification, changed the archived response or created a new job.');
    echo wp_json_encode(array('section'=>$section['id'],'page'=>$page['id'],'keys'=>$keys,'mockCalls'=>count($calls),
        'authorizedMobileLayout'=>true,'sameGenReviewRecovery'=>true,'sameGenPlanningRecovery'=>true)).PHP_EOL;
} elseif($phase==='retry') {
    $page=syndamorph_scope_status(syndamorph_history_latest((int)getenv('SM_SCOPE_JOB'),'scope_manifest'));
    scope_expect($page['page']['editorVerified']&&!$page['page']['allRegions'],'Expected an independently verified partial WordPress draft.');
    scope_expect(is_wp_error(syndamorph_scope_run($page,$keys[0],true,$engine)),'Retry attempted to regenerate an accepted region.');
    $prior=$page['spentUsd'];
    $page=scope_ok(syndamorph_scope_increase_budget($page,0.30));
    scope_expect($page['budgetUsd']===0.30&&$page['spentUsd']===$prior,'Raising the cap changed recorded spend.');
    $page=scope_ok(syndamorph_scope_run($page,$keys[1],true,$engine));
    scope_expect($page['regions'][$keys[1]]['status']==='needs_review'&&count($page['regions'][$keys[1]]['attempts'])===2&&count($calls)===5,
        wp_json_encode($page['regions'][$keys[1]]));
    echo wp_json_encode(array('page'=>$page['id'],'mockCalls'=>count($calls))).PHP_EOL;
} else { throw new RuntimeException('Unknown fixture phase.'); }
