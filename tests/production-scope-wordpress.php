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

$linkedSourceUrl='https://fixture.example/';
$linkedSource=array('variants'=>array());
foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
    $linkedSource['variants'][$width]=array('sourceRef'=>'[id="story"]','kind'=>'content','inventory'=>array(
        'text'=>array('Story','Read more'),'controls'=>array(),
        'links'=>array(array('href'=>$linkedSourceUrl.'history','text'=>'Read more')),
        'assets'=>$width===1440?array(array('type'=>'image','loaded'=>true,
            'url'=>$linkedSourceUrl.'illustration.png','alt'=>'Illustration')):array()));
}
scope_expect((syndamorph_linked_image_inventory($linkedSource,$linkedSourceUrl)['assetWidths']??null)===array(1440),
    'The linked image profile lost its exact image visibility.');
$changedLink=$linkedSource;
$changedLink['variants'][375]['inventory']['links'][0]['href']=$linkedSourceUrl.'elsewhere';
scope_expect(syndamorph_linked_image_inventory($changedLink,$linkedSourceUrl)===null,
    'An altered compact link matched the pinned profile.');
$externalLink=$linkedSource;
foreach($externalLink['variants'] as &$view)$view['inventory']['links'][0]['href']='https://external.example/history';
unset($view);
scope_expect(syndamorph_linked_image_inventory($externalLink,$linkedSourceUrl)===null,
    'An external link passed the recorded same-origin profile.');
unset($linkedSource['variants'][375]);
scope_expect(syndamorph_linked_image_inventory($linkedSource,$linkedSourceUrl)===null,
    'A missing viewport passed the recorded image and link profile.');

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
$active=null;$calls=array();$interruptReview=false;$interruptDeveloper=false;
add_filter('pre_http_request',static function($pre,$args,$url)use($model,$stubKey,&$calls,&$active,&$interruptReview,&$interruptDeveloper){
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
        if($task==='developer'&&$interruptDeveloper)return new WP_Error('http_request_failed','Fixture Developer timeout.');
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
    $guarantee=array('kind'=>'content','sourceRef'=>'div[id="compact-guarantee"]','variants'=>array());
    $guaranteeCopy=array('First','Terms one','Second','Terms two','Third','Terms three');
    foreach(array(768,375) as $width){
        $guarantee['variants'][$width]=array(
            'sourceRef'=>$guarantee['sourceRef'],'geometryAvailable'=>true,
            'bounds'=>array('x'=>0,'y'=>0,'width'=>$width,'height'=>$capture['sections'][0]['variants'][$width]['bounds']['height']),
            'screenshot'=>$capture['sections'][0]['variants'][$width]['screenshot'],
            'inventory'=>array('position'=>'static','text'=>$guaranteeCopy,'assets'=>array_fill(0,3,array('type'=>'svg')),
                'controls'=>array(),'links'=>array(),'anchors'=>array()));
    }
    scope_expect(syndamorph_compact_inventory($guarantee,'guarantee')===$guaranteeCopy,'The compact guarantee lost its literal source profile.');
    $badGuarantee=$guarantee;
    $badGuarantee['variants'][375]['inventory']['links'][]=array('href'=>'#terms');
    scope_expect(syndamorph_compact_inventory($badGuarantee,'guarantee')===null,'A linked guarantee was accepted as display only.');
    $badGuarantee=$guarantee;
    $badGuarantee['variants'][375]['inventory']['text'][1]='Changed compact text';
    scope_expect(syndamorph_compact_inventory($badGuarantee,'guarantee')===null,'Different viewport copy was accepted as one native guarantee.');
    $badGuarantee=$guarantee;
    $badGuarantee['variants'][1440]=$guarantee['variants'][768];
    scope_expect(syndamorph_compact_inventory($badGuarantee,'guarantee')===null,'A desktop variant was silently dropped.');
    $booking=syndamorph_compact_content(array('copy'=>array('Two guests','Edit','Check Availability')),'booking');
    scope_expect(syndamorph_scope_block_count(parse_blocks($booking))===5&&!str_contains($booking,'<a ')&&!str_contains($booking,'<button'),
        'Recorded booking labels must remain editable display text.');
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
    $originalFailure=syndamorph_history_latest($prior['id'],'job_error');
    syndamorph_history_append($prior['id'],'job_error',array('code'=>'sm_design_surgery_blocked','stage'=>'design_approval',
        'message'=>'Fixture rejected the last surgical operation.'));
    $withoutEdit=syndamorph_scope_resume_checkpoint($recover,$keys[0],array(),$engine);
    $invalidEdit=syndamorph_scope_resume_checkpoint($recover,$keys[0],$badEdit,$engine);
    scope_expect(is_wp_error($withoutEdit)&&$withoutEdit->get_error_code()==='sm_scope_review_checkpoint'&&
        is_wp_error($invalidEdit)&&$invalidEdit->get_error_code()==='sm_scope_review_regression'&&count($calls)===$priorCalls,
        'A rejected surgery must keep its exact GEN and require checked edits before any model call.');
    syndamorph_history_append($prior['id'],'job_error',array('code'=>'sm_design_dispute','stage'=>'design_approval',
        'message'=>'Fixture rejected an unsupported diagnosis.'));
    $disputedEdit=syndamorph_scope_resume_checkpoint($recover,$keys[0],$badEdit,$engine);
    scope_expect(is_wp_error($disputedEdit)&&$disputedEdit->get_error_code()==='sm_scope_review_regression'&&
        count($calls)===$priorCalls,'A design dispute allowed a source regression to reach paid review.');
    syndamorph_history_append($prior['id'],'job_error',$originalFailure);
    $recover=scope_ok(syndamorph_scope_resume_checkpoint($recover,$keys[0],array(),$engine));
    $attempts=$recover['regions'][$keys[0]]['attempts'];
    $savedRecovery=syndamorph_history_latest($prior['id'],'job_resumed');
    scope_expect(count($attempts)===1&&$attempts[0]['id']===$prior['id']&&$attempts[0]['status']==='needs_review'&&
        $attempts[0]['completedCalls']===5&&$attempts[0]['estimatedCostUsd']>$prior['estimatedCostUsd']&&
        $attempts[0]['unmeteredReserveUsd']===$prior['unmeteredReserveUsd']&&$savedRecovery['priorCompletedCalls']===2&&
        array_slice($calls,$priorCalls)===array('North:final_approval','North:developer','North:validation'),
        'Design review recovery repeated completed work, created a GEN, or lost the original accounting.');
    $interrupted=scope_ok(syndamorph_scope_configure(scope_ok(syndamorph_scope_open('section',$capture)),$brief,$keys[0]));
    $interruptDeveloper=true;
    $interrupted=scope_ok(syndamorph_scope_run($interrupted,$keys[0],false,$engine));
    $interruptDeveloper=false;
    $prior=$interrupted['regions'][$keys[0]]['attempts'][0];
    $priorCalls=count($calls);
    scope_expect($interrupted['regions'][$keys[0]]['recoveryStage']==='developer'&&$prior['completedCalls']===3&&
        is_wp_error(syndamorph_scope_run($interrupted,$keys[0],true,$engine)),
        'An interrupted Developer discarded the approved design or allowed generation from the beginning.');
    $continued=scope_ok(syndamorph_scope_resume_checkpoint($interrupted,$keys[0],array(),$engine));
    $latest=end($continued['regions'][$keys[0]]['attempts']);
    scope_expect($latest['id']===$prior['id']&&$latest['status']==='needs_review'&&$latest['completedCalls']===5&&
        $latest['unmeteredReserveUsd']===$prior['unmeteredReserveUsd']&&
        array_slice($calls,$priorCalls)===array('North:developer','North:validation')&&
        (syndamorph_history_latest($prior['id'],'design_approval_replay')['status']??null)==='revalidated',
        'Developer recovery failed to reuse the verified approval or repeated the reviewed stages.');
    $streamJob=scope_ok(syndamorph_history_create('GEN','Offline capped Developer stream',array()));
    $streamTasks=array('role'=>'developer','task'=>'developer');
    $streamFailure=$streamTasks+array('code'=>'sm_generation_token_limit','stopReason'=>'max_tokens',
        'metric'=>array('metering'=>'provider_reported'));
    syndamorph_history_append($streamJob['id'],'agent_start',$streamTasks);
    syndamorph_history_append($streamJob['id'],'agent_failure',$streamFailure);
    scope_expect(syndamorph_scope_recovery_stage($streamFailure)==='developer'&&
        syndamorph_scope_failed_stream($streamJob['id'],'developer')==='',
        'A billed empty Developer stream was not distinguished from missing archive evidence.');
    syndamorph_history_append($streamJob['id'],'agent_start',$streamTasks);
    syndamorph_history_append($streamJob['id'],'agent_delta',$streamTasks+array('text'=>'{"version":1'));
    syndamorph_history_append($streamJob['id'],'agent_failure',$streamFailure);
    scope_expect(syndamorph_scope_failed_stream($streamJob['id'],'developer')==='{"version":1',
        'A partial native plan was mistaken for an empty stream.');
    $leaf=array('id'=>'saved-leaf','text'=>'A closing brace } in the original copy.','type'=>'paragraph');
    $partial='{"version":1,"blocks":[{"type":"group","id":"root","children":['.wp_json_encode($leaf).',{"type":"paragraph"';
    $recovered=array('version'=>1,'blocks'=>array(array('type'=>'group','id'=>'root','children'=>array(
        $leaf+array('style'=>array('color'=>'#112233')),array('type'=>'paragraph','id'=>'completion','text'=>'Added from pinned evidence.')))));
    scope_expect(syndamorph_scope_retained_native_nodes($partial,$recovered)===array('saved-leaf'),
        'A complete node inside an unfinished container was not preserved.');
    $recovered['blocks'][0]['children'][0]['text']='Changed authored literal';
    scope_expect(syndamorph_scope_retained_native_nodes($partial,$recovered)===null,
        'Native completion changed the authored literal inside a retained node.');
    $nativeId=$prior['id'];
    $held=syndamorph_history_latest($nativeId,'job_complete');
    $held['status']='uncompleted';
    $held['quality']['bossApproved']=false;
    $held['sourceAcceptance']['passed']=false;
    syndamorph_history_append($nativeId,'job_complete',$held);
    $nativePlan=json_decode($held['outputs']['developer'],true,64,JSON_THROW_ON_ERROR);
    $nativePlan['blocks'][0]['children'][0]['style']['color']='#172025';
    $nativeArtifact=scope_ok(syndamorph_compile_block_plan(wp_json_encode($nativePlan)));
    $nativeSha=hash('sha256',$nativeArtifact['content']);
    $nativeCheckpoint=array('hold'=>'implementation_review','artifactSha256'=>$nativeSha,
        'previousArtifactSha256'=>hash('sha256',$held['artifact']['content']),
        'approvedDesignerSha256'=>hash('sha256',$held['referenceHtml']),
        'sourceReview'=>array('passed'=>true),'plan'=>$nativePlan,'artifact'=>$nativeArtifact);
    scope_expect($nativeSha!==$nativeCheckpoint['previousArtifactSha256'],'The recovery fixture did not change the artifact.');
    syndamorph_history_append($nativeId,'native_layout_recovery',array_replace($nativeCheckpoint,
        array('approvedDesignerSha256'=>str_repeat('a',64))));
    $beforeNativeReview=count($calls);
    scope_expect(is_wp_error(syndamorph_scope_resume_checkpoint($continued,$keys[0],array(),$engine))&&
        count($calls)===$beforeNativeReview,'A mismatched native checkpoint reused an unrelated design approval.');
    $invalidNative=$nativeCheckpoint;
    $invalidNative['plan']['blocks'][0]['children'][0]['text']='Changed literal text';
    syndamorph_history_append($nativeId,'native_layout_recovery',$invalidNative);
    $blockedNative=scope_ok(syndamorph_scope_resume_checkpoint($continued,$keys[0],array(),$engine));
    scope_expect($blockedNative['regions'][$keys[0]]['status']==='failed'&&count($calls)===$beforeNativeReview&&
        (syndamorph_history_latest($nativeId,'job_error')['code']??null)==='sm_native_review_identity',
        'Native review charged for a plan that no longer compiled to the archived artifact.');
    syndamorph_history_append($nativeId,'native_layout_recovery',$nativeCheckpoint);
    $nativeRecovered=scope_ok(syndamorph_scope_resume_checkpoint($blockedNative,$keys[0],array(),$engine));
    $nativeAttempt=end($nativeRecovered['regions'][$keys[0]]['attempts']);
    $nativeResult=syndamorph_history_latest($nativeId,'job_complete');
    scope_expect($nativeAttempt['id']===$nativeId&&$nativeAttempt['status']==='needs_review'&&
        $nativeResult['artifact']['contentSha256']===$nativeSha&&$nativeAttempt['completedCalls']===6&&
        $nativeAttempt['unmeteredReserveUsd']===$prior['unmeteredReserveUsd']&&
        array_slice($calls,$beforeNativeReview)===array('North:validation'),
        'Native review changed the archived artifact, repeated a production stage or lost the original accounting: '.
        wp_json_encode(array($nativeRecovered['regions'][$keys[0]]['status'],$nativeResult['quality']['issues']??array(),
            syndamorph_history_latest($nativeId,'job_error'))));
    $priorReference=$nativeResult['referenceHtml'];
    $revisedReference=preg_replace('/<section\b/','<section data-recovered-fixture',$priorReference,1);
    $priorSha=hash('sha256',$priorReference);
    $revisedSha=hash('sha256',$revisedReference);
    $revision=array('account'=>syndamorph_scope_account($nativeId,true),'assignment'=>$nativeResult['handoffs'][0],
        'referenceHtml'=>$revisedReference,'archivedSha256'=>$priorSha,'round'=>2,
        'surgery'=>array('priorSha256'=>$priorSha,'candidateSha256'=>$revisedSha,'status'=>'responsive_checked'),
        'nativeReview'=>array('referenceSha256'=>$revisedSha,'artifactSha256'=>$nativeSha,'plan'=>$nativePlan));
    $sourceRegion=syndamorph_scope_checkpoint($continued['id'],$continued['regions'][$keys[0]]['checkpoint']);
    $reviewSource=syndamorph_scope_engine_source($sourceRegion,$continued['sourceUrl']);
    $beforeRevisedReview=count($calls);
    $unbound=$revision;
    $unbound['nativeReview']['referenceSha256']=$priorSha;
    $result=$engine($brief,false,static function(){},false,$reviewSource,0.15,0.12,$unbound);
    scope_expect(is_wp_error($result)&&$result->get_error_code()==='sm_native_review_identity'&&count($calls)===$beforeRevisedReview,
        'An unbound revised reference entered paid review.');
    $result=scope_ok($engine($brief,false,static function(){},false,$reviewSource,0.15,0.12,$revision));
    scope_expect($result['status']==='needs_review'&&$result['artifact']['contentSha256']===$nativeSha&&
        $result['designApproval']['referenceSha256']===$revisedSha&&
        array_slice($calls,$beforeRevisedReview)===array('North:final_approval','North:validation'),
        'A bound reference correction did not receive fresh design and native reviews without regeneration.');
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
    $reviewPage=scope_ok(syndamorph_scope_configure(scope_ok(syndamorph_scope_open('page',$capture)),$brief,null,0.24));
    $reviewPage=scope_ok(syndamorph_scope_run($reviewPage,$keys[1],false,static function($brief,$stream,$emit){
        $emit('agent_decision',array('stage'=>'design_approval','decision'=>'design_review_required',
            'summary'=>'A compact source interaction needs an explicit design decision.'));
        return new WP_Error('sm_design_review_required','Fixture design hold.',array('stage'=>'design_approval'));
    }));
    $priorCalls=count($calls);
    $heldRetry=syndamorph_scope_run($reviewPage,$keys[1],true,$engine);
    scope_expect(is_wp_error($heldRetry)&&$heldRetry->get_error_code()==='sm_scope_design_decision'&&
        $reviewPage['regions'][$keys[1]]['designDecisionRequired']&&count($calls)===$priorCalls&&
        str_starts_with($reviewPage['regions'][$keys[1]]['issue'],'A compact source interaction'),
        'A paid retry bypassed the saved design decision or dropped its reason.');
    $reviewPage=scope_ok(syndamorph_scope_run($reviewPage,$keys[2],false,$engine));
    scope_expect($reviewPage['regions'][$keys[1]]['designDecisionRequired']&&
        $reviewPage['regions'][$keys[2]]['status']==='needs_review'&&count($calls)===$priorCalls+5,
        'One pending design decision blocked independent production work.');

    $proofHtml='<section><h2>About</h2><p style="color:#605d5d">Eyebrow</p>'.
        '<p style="color:#333333">Verified introduction</p><p>View details →</p></section>';
    $proofSamples=scope_ok(syndamorph_scope_inspect($proofHtml,'section'));
    $proofSource=array('url'=>'https://fixture.invalid/order-repair','scopeRegion'=>array('variants'=>array()));
    $archive=array('version'=>1,'format'=>'singlefile-2.27.3','sourceUrl'=>$proofSource['url'],'views'=>array());
    $identity=array(1,$archive['format'],$archive['sourceUrl']);
    foreach($proofSamples as $sample){
        $width=$sample['width'];$literal=$sample['snapshot']['literal'];
        $proofSource['scopeRegion']['variants'][$width]=array('inventory'=>array_intersect_key($literal,
            array('text'=>true,'links'=>true))+array('assets'=>array(),'controls'=>array()),
            'facts'=>array('copy'=>$sample['snapshot']['copy']));
        $view=array('width'=>$width,'viewportHeight'=>900,'scrollWidth'=>$width,'pageHeight'=>900,'pixelDifferenceRatio'=>0,
            'javascriptEnabled'=>false,'externalRequests'=>0,'verified'=>true,'copySha256'=>hash('sha256',$sample['snapshot']['copy']));
        $entry=array($width);
        foreach(array('sourceDOMSha256','sourceScreenshotSha256','archiveSha256','replayScreenshotSha256') as $field)
            $entry[]=$view[$field]=hash('sha256',$field.$width);
        $identity[]=$entry;$archive['views'][]=$view;
    }
    $archive['id']=hash('sha256',wp_json_encode($identity,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $proofSource['referenceArchive']=$archive;
    $proofSpec=scope_ok(syndamorph_production_specification(array('version'=>1,'title'=>'Work-order proof',
        'sections'=>array(array('id'=>'about','label'=>'About')),'requirements'=>array(
            array('id'=>'intro-style','target'=>'about','dimension'=>'visual','basis'=>'source','requirement'=>'Introduction color matches.',
                'acceptance'=>'The introduction uses #333333.','check'=>array('kind'=>'style','selector'=>'p','field'=>'color','value'=>'#333333')),
            array('id'=>'link-destination','target'=>'about','dimension'=>'functional','basis'=>'source','requirement'=>'Preserve the source link.',
                'acceptance'=>'Verify the destination in the captured evidence.'),
            array('id'=>'heading-structure','target'=>'about','dimension'=>'accessibility','basis'=>'standard','requirement'=>'Preserve hierarchy.',
                'acceptance'=>'The host page heading has a logical level.')))));
    $edits=array(array('id'=>'intro-style','kind'=>'style-target','literal'=>'Verified introduction'),
        array('id'=>'link-destination','kind'=>'source-label','literal'=>'View details →'));
    $amended=scope_ok(syndamorph_source_work_order_amend($proofSpec,$proofSource,$proofSamples,$edits));
    $amendedSpec=$amended['specification'];
    $result=syndamorph_design_requirements($amendedSpec,$proofSamples,array(),array(),array());
    scope_expect($result['blocking']===array('heading-structure')&&$amendedSpec['requirements'][2]===$proofSpec['requirements'][2]&&
        $amended['changes'][0]['before']===$proofSpec['requirements'][0],
        'The revision bypassed an unrelated requirement or failed to retain the original work order.');
    $incomplete=$proofSamples;
    $incomplete[2]['snapshot']['elementCount']++;
    $wrongTarget=array(array('id'=>'intro-style','kind'=>'style-target','literal'=>'Eyebrow'));
    $incorrect=$proofSource;
    $incorrect['scopeRegion']['variants'][1440]['inventory']['links']=array(array('text'=>'View details →','href'=>'https://fixture.invalid/'));
    $misleading=$proofSamples;
    $misleading[0]['snapshot']['literal']['links']=$incorrect['scopeRegion']['variants'][1440]['inventory']['links'];
    scope_expect(is_wp_error(syndamorph_source_work_order_amend($proofSpec,$proofSource,$incomplete,$edits))&&
        is_wp_error(syndamorph_source_work_order_amend($proofSpec,$proofSource,$proofSamples,$wrongTarget))&&
        is_wp_error(syndamorph_source_work_order_amend($proofSpec,$incorrect,$misleading,$edits)),
        'Incomplete evidence, a different style target, or an actual captured link permitted a revision.');
    $hostJob=scope_ok(syndamorph_history_create('GEN','Offline host QA checkpoint',array()));
    $hostArtifact=$fixtures[$keys[0]]['artifact'];
    $hostArchive=array('status'=>'completed','artifact'=>$hostArtifact,
        'quality'=>array('viewportWidths'=>SYNDAMORPH_SOURCE_WIDTHS),
        'designApproval'=>array('productionSpecification'=>array('requirements'=>array(
            array('id'=>'current-heading','check'=>array('kind'=>'host-heading')),
            array('id'=>'current-contrast','check'=>array('kind'=>'host-contrast')),
        ))));
    syndamorph_history_append($hostJob['id'],'job_complete',$hostArchive);
    $hostArchive=syndamorph_history_latest($hostJob['id'],'job_complete');
    $hostRequest=new WP_REST_Request('POST','/syndamorph/v1/history/'.$hostJob['id'].'/reinspect-reference');
    $hostRequest->set_param('check','native-host');
    $hostResponse=rest_do_request($hostRequest);
    $hostResult=$hostResponse->get_data();
    $hostSha=$hostArtifact['contentSha256'];
    $effective=syndamorph_history_responsive_result($hostJob['id'],$hostArchive);
    scope_expect($hostResponse->get_status()===200&&$hostResult['aiCalls']===0&&
        syndamorph_design_host_acceptance($effective,$hostSha)&&
        syndamorph_history_latest($hostJob['id'],'job_complete')===$hostArchive&&
        rest_do_request($hostRequest)->get_data()===$hostResult,'Host reinspection did not preserve the exact archived candidate.');
    $changedPreview=$hostArchive;
    $changedPreview['artifact']['preview'].='<p>Different preview</p>';
    scope_expect(!syndamorph_design_host_acceptance(
        syndamorph_history_responsive_result($hostJob['id'],$changedPreview),$hostSha),
        'Host reinspection evidence was attached to a changed browser preview.');
    $staged=scope_ok(syndamorph_scope_configure(scope_ok(syndamorph_scope_open('section',$capture)),$brief,$keys[0]));
    $stagedPlan=syndamorph_collab_ids($fixtures[$keys[0]]['plan']);
    $stagedPlan['blocks'][0]['id']='staged-root';
    $stagedArtifact=scope_ok(syndamorph_compile_block_plan(wp_json_encode($stagedPlan)));
    $stagedSha=hash('sha256',$stagedArtifact['content']);
    $stagedEvent=array('region'=>$keys[0],'approved'=>false,'plan'=>$stagedPlan,
        'checkpoint'=>$staged['regions'][$keys[0]]['checkpoint'],'artifactSha256'=>$stagedSha);
    $beforeStaged=count($calls);
    syndamorph_history_append($staged['id'],'scope_native_candidate',array_replace($stagedEvent,
        array('artifactSha256'=>str_repeat('a',64))));
    scope_expect(is_wp_error(syndamorph_scope_review_staged($staged,$keys[0],$staged['id'],$engine))&&count($calls)===$beforeStaged,
        'A staged plan whose exact hash changed reached a provider.');
    syndamorph_history_append($staged['id'],'scope_native_candidate',$stagedEvent);
    $interruptReview=true;
    $staged=scope_ok(syndamorph_scope_review_staged($staged,$keys[0],$staged['id'],$engine));
    $interruptReview=false;
    $stagedPrior=$staged['regions'][$keys[0]]['attempts'][0];
    scope_expect($staged['regions'][$keys[0]]['recoveryStage']==='design_approval'&&
        $stagedPrior['completedCalls']===1&&is_wp_error(syndamorph_scope_run($staged,$keys[0],true,$engine)),
        'A staged timeout did not retain the same review checkpoint.');
    $staged=scope_ok(syndamorph_scope_resume_checkpoint($staged,$keys[0],array(),$engine));
    $stagedAttempt=$staged['regions'][$keys[0]]['attempts'][0];
    $stagedFinal=syndamorph_history_latest($stagedAttempt['id'],'job_complete');
    scope_expect($stagedAttempt['id']===$stagedPrior['id']&&$stagedAttempt['status']==='needs_review'&&
        $stagedAttempt['unmeteredReserveUsd']===$stagedPrior['unmeteredReserveUsd']&&
        ($stagedFinal['artifact']['contentSha256']??null)===$stagedSha&&
        array_slice($calls,$beforeStaged)===array('North:boss','North:final_approval','North:final_approval','North:validation'),
        'Staged review recovery regenerated the artifact, lost accounting, or skipped a gate: '.
        wp_json_encode(syndamorph_history_latest($stagedAttempt['id'],'job_error')));
    $identitySentence='Use native grouped Details for the recorded disclosure interaction.';
    $scenarioSentence=str_repeat('Verify the observed disclosure state. ',5).'After the final action all panels are closed.';
    $criteria='Check the initial open panel and every recorded transition.';
    $rawOrder=array('version'=>1,'title'=>'Disclosures','sections'=>array(array('id'=>'faq','label'=>'FAQ')),
        'requirements'=>array(array('id'=>'faq-states','target'=>'faq','dimension'=>'functional','basis'=>'source',
            'requirement'=>$identitySentence.' '.$scenarioSentence,'acceptance'=>$criteria)),
        'interactions'=>array(array('id'=>'recorded-disclosures','kind'=>'disclosure-group','classification'=>'native',
            'requirementId'=>'faq-states','behavior'=>'Show the observed states.')));
    $rawOrderJson=wp_json_encode($rawOrder);
    $reformatted=syndamorph_scope_staged_work_order($rawOrderJson,'recorded-disclosures');
    $expected=$rawOrder;
    $expected['requirements'][0]['requirement']=$scenarioSentence;
    $expected['requirements'][0]['acceptance']=$identitySentence.' '.$criteria;
    scope_expect(is_string($reformatted)&&json_decode($reformatted,true)===$expected&&
        syndamorph_scope_staged_work_order($rawOrderJson,'different-disclosures')===null,
        'Staged work order formatting lost copy or changed interaction identity.');
    $keyboardOrder=scope_ok(syndamorph_production_specification(array_replace($expected,array('requirements'=>array_merge(
        $expected['requirements'],array(array('id'=>'faq-keyboard','target'=>'faq','dimension'=>'accessibility','basis'=>'standard',
            'requirement'=>'Each summary is a native disclosure control that toggles with Enter and Space and shows a visible focus indicator. Source keyboard behavior is unverified; the native block must support keyboard activation.',
            'acceptance'=>'Enter and Space toggle the focused summary and focus is visible; verified only on the compiled artifact.')))))));
    $ids=syndamorph_disclosures_keyboard_requirements($keyboardOrder,array('id'=>'recorded-disclosures',
        'sourceKeyboard'=>'unverified','nativeKeyboardRequired'=>true));
    $check=array('id'=>'faq-keyboard','dimension'=>'accessibility','owner'=>'boss','status'=>'uncertain','evidence'=>array());
    $other=array_replace($check,array('id'=>'unrelated-check','status'=>'failed'));
    $nativeOnly=syndamorph_disclosures_defer_keyboard(array('checks'=>array($check,$other),
        'blocking'=>array('faq-keyboard','unrelated-check')),$ids);
    scope_expect($ids===array('faq-keyboard')&&$nativeOnly['blocking']===array('unrelated-check')&&
        $nativeOnly['checks'][0]['owner']==='implementation_qa'&&$nativeOnly['checks'][0]['status']==='uncertain'&&
        $nativeOnly['checks'][1]===$other,'The matched keyboard criterion was not deferred independently of unrelated QA.');
    $sectionNode=$stagedPlan['blocks'][0]['children'][1]['id'];
    $wrapped=scope_ok(syndamorph_surgery_apply($stagedPlan,array('id'=>$sectionNode,
        'changes'=>array(array('path'=>'style.textWrap','value'=>'pretty'))),array($sectionNode)));
    scope_expect($wrapped['artifact']&&str_contains($wrapped['artifact']['content'],'"className":"sm-text-wrap-pretty"')&&
        str_contains($wrapped['artifact']['preview'],syndamorph_native_paragraph_wrap_css())&&$wrapped['impact']['changed']===array($sectionNode),
        'The wrapping surgery did not preserve the exact target and save the approved Paragraph class.');
    $unsupported=scope_ok(syndamorph_surgery_apply($stagedPlan,array('id'=>$sectionNode,
        'changes'=>array(array('path'=>'style.textWrap','value'=>'balance'))),array($sectionNode)));
    scope_expect(is_wp_error($unsupported['error']), 'Unsupported paragraph wrap unexpectedly compiled.');
    $reusePage=scope_ok(syndamorph_scope_open('page',$capture));
    scope_expect(is_wp_error(syndamorph_scope_reuse_section($reusePage,$section['id'],$keys[0])),
        'Full Page attached a Section before Gutenberg and Pattern acceptance.');
    $changedCapture=$capture;
    $changedCapture['sections'][0]['variants'][375]['inventory']['text'][]='Other captured copy';
    $differentPage=scope_ok(syndamorph_scope_open('page',$changedCapture));
    echo wp_json_encode(array('section'=>$section['id'],'page'=>$page['id'],'reusePage'=>$reusePage['id'],
        'differentPage'=>$differentPage['id'],'keys'=>$keys,'mockCalls'=>count($calls),
        'authorizedMobileLayout'=>true,'sameGenReviewRecovery'=>true,'sameGenPlanningRecovery'=>true,'designHoldIsolated'=>true,
        'sourceWorkOrderEvidence'=>true,'sameGenDeveloperRecovery'=>true)).PHP_EOL;
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
