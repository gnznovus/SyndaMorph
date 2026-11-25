<?php
/** CLI-only design approval audit. Provider requests are blocked unless explicitly enabled. */
if(PHP_SAPI!=='cli')exit(1);
$originalEngine=getenv('SM_BOSS_AUDIT_BASELINE')==='1';
if($originalEngine){
    // Load the archived plugin in this CLI process only; never swap the mounted site plugin.
    $GLOBALS['wp_filter']['option_active_plugins'][10][]=array('function'=>static fn($plugins)=>
        array_values(array_diff((array)$plugins,array('syndamorph/syndamorph.php'))),'accepted_args'=>1);
}
require '/var/www/html/wp-load.php';
require_once $originalEngine?'/tmp/sm-bossy-baseline/plugin/syndamorph.php':WP_PLUGIN_DIR.'/syndamorph/syndamorph.php';

const BOSS_AUDIT_WIDTHS=array(1440,768,375);
const BOSS_AUDIT_MODEL='claude-haiku-5-5';
const BOSS_AUDIT_CALLS=18;
const BOSS_AUDIT_DOLLARS=0.20;
const BOSS_AUDIT_MAX_OUTPUT=4096;

function boss_audit_assert($condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}
function boss_audit_ok($result) {
    if(is_wp_error($result))throw new RuntimeException($result->get_error_code().': '.$result->get_error_message());
    return $result;
}
function boss_audit_replace(string $html,string $find,string $replace): string {
    boss_audit_assert(substr_count($html,$find)===1,'Mutation anchor is absent or ambiguous.');
    return str_replace($find,$replace,$html);
}
function boss_audit_baseline(string $original,bool $link): string {
    // The modern native engine treats active balance/pretty wrapping as unsupported.
    // Keep the original archive immutable and use this calibrated derivative.
    $html=preg_replace('/<!--.*?-->|[ \t]*text-wrap: (?:balance|pretty);\n/s','',$original);
    $html=boss_audit_replace($html,'<style>','<style>body{margin:0} .audit-marker-slot{height:3px;width:24px} .audit-mark{display:block;height:3px;width:24px;background:#ffd966}');
    $html=boss_audit_replace($html,'<div class="sm-hero__content">','<div class="sm-hero__content" id="rooms">');
    $html=boss_audit_replace($html,'<div class="sm-hero__inner">','<div class="sm-hero__inner"><div class="audit-marker-slot" aria-hidden="true"><span class="audit-mark" aria-hidden="true"></span></div>');
    if(!$link)$html=boss_audit_replace($html,'<a class="sm-hero__cta" href="#rooms">View available rooms</a>','');
    return $html;
}
function boss_audit_brief(bool $link): string {
    return 'Reproduce the approved premium hotel hero at 1440px, 768px and 375px. Use centered system-ui typography, a 42rem maximum centered content column, a navy gradient from #18283a to #2b4a6b, a small gold marker above Welcome, and responsive vertical padding clamp(3rem, 2rem + 6vw, 6.5rem). The headline must read “Rest well in a space designed for you”. Keep the introduction as supplied. The headline must wrap within the narrow viewport without horizontal scroll.' .
        ($link?' Include one real keyboard-operable anchor labeled “View available rooms” with href="#rooms" and a corresponding destination; keep the control intrinsic width.':' This approved variant has no CTA. Do not add one.');
}
function boss_audit_source(string $html): array {
    $samples=boss_audit_ok(syndamorph_scope_inspect($html,'section'));
    $variants=array();
    foreach($samples as $sample){
        $literal=$sample['snapshot']['literal'];
        $variants[$sample['width']]=array('sourceRef'=>'section.sm-hero','bounds'=>array('height'=>$sample['extent']),
            'facts'=>array('copy'=>$sample['snapshot']['copy']),
            'inventory'=>array('text'=>$literal['text'],'links'=>$literal['links'],'assets'=>array(),'controls'=>array()),
            'html'=>$html,'screenshot'=>$sample['screenshot']);
    }
    $region=array('id'=>substr(hash('sha256',$html),0,24),'kind'=>'content','label'=>'Hotel hero','sourceRef'=>'section.sm-hero','variants'=>$variants);
    return syndamorph_scope_engine_source($region,'https://fixture.invalid/bossy/hotel');
}
function boss_audit_manual(string $html,array $source): array {
    unset($source['scopeRegion']);
    $samples=boss_audit_ok(syndamorph_collab_inspect($html,BOSS_AUDIT_WIDTHS));
    foreach($source['section']['evidence'] as $i=>&$sample)$sample['screenshot']=$samples[$i]['screenshot'];
    unset($sample);
    $source['section']['evidenceSha256']=hash('sha256',wp_json_encode($source['section']['evidence']));
    return $source;
}
function boss_audit_archived_bkkgo(): array {
    global $wpdb;
    list($jobs)=syndamorph_history_tables();
    $row=$wpdb->get_row("SELECT id,context FROM $jobs WHERE category='GEN' AND sequence_no=36",ARRAY_A);
    $context=json_decode($row['context']??'',true);
    $source=$context['sourceEvidence']??null;
    boss_audit_assert(is_array($source)&&str_contains($source['url']??'','BKKGOPrototype1/')&&isset($source['scopeRegion']),
        'Local BKKGO GEN-000036 source evidence is unavailable.');
    $rounded=null;$reference='';
    foreach(boss_audit_ok(syndamorph_job_resume_calls((int)$row['id'])) as $call){
        if($call['task']==='designer')$reference=trim($call['text']);
        if($call['task']!=='design_correction')continue;
        $patch=json_decode(syndamorph_native_json_text($call['text']),true);
        if(is_array($patch)&&isset($patch['edits']))$reference=boss_audit_ok(syndamorph_design_surgery($reference,$patch))['reference'];
    }
    $rounded=syndamorph_history_latest((int)$row['id'],'source_review');
    boss_audit_assert($reference!==''&&($rounded['stage']??null)==='design'&&empty($rounded['result']['passed']),
        'The archived BKKGO negative control is not verifiable.');
    return array('id'=>'bkkgo-mobile-wrap','mode'=>'scoped','expected'=>'block','html'=>$reference,'brief'=>$context['brief'],
        'source'=>$source,'archive'=>'GEN-000036','evidence'=>'Real BKKGO captured scope and final archived Designer reference.');
}

function boss_audit_snapshot(array $case,array $base): array {
    $samples=boss_audit_ok(syndamorph_collab_inspect($case['html'],BOSS_AUDIT_WIDTHS));
    $details=array();
    foreach($samples as $sample){
        $heading=array_values(array_filter($sample['snapshot']['elements'],static fn($row)=>$row['tag']==='H2'))[0]??array();
        $root=$sample['snapshot']['elements'][0];
        $details[$sample['width']]=array('overflow'=>$sample['snapshot']['overflow'],'copySha256'=>hash('sha256',$sample['snapshot']['copy']),
            'screenshotSha256'=>hash('sha256',$sample['screenshot']),'headingRect'=>$heading['rect']??null,
            'headingLines'=>count($heading['lineRects']??array()),'family'=>$heading['styles']['fontFamily']??null,
            'rootPadding'=>$root['styles']['padding']??null,'rootRect'=>$root['rect'],'a11y'=>array_column($sample['a11y'],'id'));
    }
    $comparison=$base?boss_audit_ok(syndamorph_collab_compare($base,$samples)):array();
    $link=preg_match('/<a class="sm-hero__cta" href="([^"]+)">/',$case['html'],$match)?$match[1]:null;
    return array('views'=>$details,'pixelRatios'=>array_column($comparison,'pixelDifferenceRatio','width'),
        'linkDestination'=>$link,'linkTargetPresent'=>is_string($link)&&str_contains($case['html'],'id="'.substr($link,1).'"'),
        'markerPresent'=>str_contains($case['html'],'<span class="audit-mark"'),'nativeConstraints'=>syndamorph_reference_unrealizable($samples));
}

$fixtureDirectory=getenv('SM_BOSS_FIXTURES')?:'/tmp/sm-bossy-audit';
$fixture=json_decode(file_get_contents($fixtureDirectory.'/bossy-design-cases.json'),true,32,JSON_THROW_ON_ERROR);
$original=rtrim(file_get_contents($fixtureDirectory.'/bossy-approved-gen-000002.html'),"\r\n");
boss_audit_assert(hash('sha256',str_replace("\r\n","\n",$original))==='12107e8b92e69fb65a5a605704011a54dc5fba48a7bc32c7f60e7ddff21f9db9',
    'The archived approved reference must remain immutable.');
$original=str_replace("\r\n","\n",$original);
$withoutLink=boss_audit_baseline($original,false);
$withLink=boss_audit_baseline($original,true);
$source=boss_audit_source($withoutLink);
$manual=boss_audit_manual($withoutLink,$source);
$baselines=array(false=>boss_audit_ok(syndamorph_collab_inspect($withoutLink,BOSS_AUDIT_WIDTHS)),
    true=>boss_audit_ok(syndamorph_collab_inspect($withLink,BOSS_AUDIT_WIDTHS)));

/** Run the production engine up to Developer handoff, supplying recorded audit inputs. */
function boss_audit_run(array $case,array $settings,bool $live=false,?array $decision=null,string $fault=''): array {
    $requestBody=null;$responseBody=null;$approvalCalls=0;$developerStarted=false;$gate=null;$events=array();
    $nested=false;$renderRequests=0;$renderWidths=array();$reserved=0.0;$metric=null;
    $reviewTask=static function(array $args): string {
        $request=json_decode($args['body']??'',true);
        preg_match('/Task: ([a-z_]+)\./',$request['system'][1]['text']??'',$match);
        return $match[1]??'';
    };
    $cap=static function(array $args,string $url)use($live,$reviewTask): array {
        if($live&&$url==='https://api.anthropic.com/v1/messages'&&$reviewTask($args)==='final_approval'){
            $body=json_decode($args['body'],true);
            $body['max_tokens']=min($body['max_tokens'],BOSS_AUDIT_MAX_OUTPUT);
            $args['body']=wp_json_encode($body);
        }
        return $args;
    };
    $intercept=static function($pre,array $args,string $url)use($case,$settings,$live,$decision,$fault,$reviewTask,
        &$requestBody,&$approvalCalls,&$reserved,&$nested,&$renderRequests,&$renderWidths){
        if($fault!==''&&$url==='http://renderer:3000/inspect'&&!$nested){
            $renderRequests++;
            $renderWidths[]=json_decode($args['body'],true)['viewports'];
            $nested=true;
            try{$response=wp_remote_post($url,$args);}finally{$nested=false;}
            if(is_wp_error($response))return $response;
            $body=json_decode(wp_remote_retrieve_body($response),true);
            if($fault==='missing-width')$body['evidence']=array_values(array_filter($body['evidence'],static fn($sample)=>$sample['width']!==375));
            if($fault==='empty-snapshot')foreach($body['evidence'] as &$sample){
                $sample['snapshot']['elements']=array();unset($sample['snapshot']['copy']);
            }
            $response['body']=wp_json_encode($body);
            $response['headers']['content-length']=(string)strlen($response['body']);
            return $response;
        }
        if($url==='https://api.anthropic.com/v1/models?limit=100'){
            $reply=array('data'=>array(array('id'=>$settings['model'],'display_name'=>$settings['model'])),'has_more'=>false);
        }elseif($url==='https://api.anthropic.com/v1/messages'){
            $task=$reviewTask($args);
            if($task==='final_approval'){
                $approvalCalls++;
                if($live&&$approvalCalls>1)return new WP_Error('sm_audit_boundary','First-pass review measured; follow-up paused.');
                $requestBody??=json_decode($args['body'],true);
                if($live){
                    $blocks=$requestBody['messages'][0]['content'];
                    $bytes=strlen($blocks[0]['text'])+strlen($requestBody['system'][0]['text'])+strlen($requestBody['system'][1]['text']);
                    // Conservative audit reservation doubles the production estimate and also
                    // uses the higher long-context prices for admission only.
                    $reserved=2*syndamorph_collab_allowance($bytes,count($blocks)-1,$requestBody['max_tokens'],0.50,2.50);
                    if($GLOBALS['boss_audit_actual_calls']>=BOSS_AUDIT_CALLS ||
                        $GLOBALS['boss_audit_total']+$reserved>BOSS_AUDIT_DOLLARS)
                        return new WP_Error('sm_audit_budget','Audit stopped before the approved request or cost limit.');
                    $GLOBALS['boss_audit_actual_calls']++;
                    return $pre;
                }
            }
            $text=match($task){
                'boss'=>wp_json_encode(array('title'=>'Recorded audit fixture','sections'=>array('Hotel hero'),
                    'constraints'=>array(),'acceptance'=>array('Preserve the supplied design and content.'))),
                'designer'=>$case['html'],
                'final_approval'=>wp_json_encode($decision??array('decision'=>'approved','issues'=>array())),
                // Deliberately try approval during missing-evidence escalation.
                'scope_resolution'=>'{"decision":"approved","issues":[]}',
                default=>null,
            };
            if($text===null)return new WP_Error('sm_audit_boundary','Audit stops before Designer repair or Developer generation.');
            $reply=array('model'=>$settings['model'],'stop_reason'=>'end_turn','content'=>array(array('type'=>'text','text'=>$text)),
                'usage'=>array('input_tokens'=>0,'output_tokens'=>0));
        }else return $pre;
        return array('response'=>array('code'=>200),'headers'=>array(),'body'=>wp_json_encode($reply));
    };
    $capture=static function($response,$context,$class,$args,$url)use($live,$reviewTask,&$responseBody){
        if($live&&$url==='https://api.anthropic.com/v1/messages'&&$context==='response'&&$reviewTask($args)==='final_approval'&&$responseBody===null&&!is_wp_error($response))
            $responseBody=json_decode(wp_remote_retrieve_body($response),true);
    };
    $emit=static function(string $event,array $data)use(&$developerStarted,&$gate,&$events,&$metric){
        if($event==='agent_start'&&$data['role']==='developer')$developerStarted=true;
        if($event==='execution_gate'&&$data['stage']==='design_approval')$gate=$data;
        if(in_array($event,array('agent_decision','inspection_error','source_review','agent_start','handoff'),true))$events[]=array($event,$data);
        if(in_array($event,array('agent_complete','agent_failure'),true)&&$data['task']==='final_approval'&&$metric===null)$metric=$data['metric'];
    };
    add_filter('http_request_args',$cap,10,2);
    add_filter('pre_http_request',$intercept,1,3);
    add_action('http_api_debug',$capture,10,5);
    try{$result=syndamorph_collab_run($case['brief'],false,$emit,false,$case['source']);}
    finally{
        remove_filter('http_request_args',$cap,10);
        remove_filter('pre_http_request',$intercept,1);
        remove_action('http_api_debug',$capture,10);
    }
    $rawText='';
    foreach($responseBody['content']??array() as $part)if(($part['type']??'')==='text')$rawText.=$part['text'];
    $rawDecision=$live?json_decode(syndamorph_native_json_text($rawText),true):null;
    $context=$requestBody===null?array():json_decode($requestBody['messages'][0]['content'][0]['text'],true);
    $checkpoint=is_array($result)&&!empty($result['referenceCheckpoint']);
    if($checkpoint)delete_transient('sm_inspection_'.$result['referenceCheckpoint']);
    return array('handoff'=>$developerStarted,'gate'=>$gate,'code'=>is_wp_error($result)?$result->get_error_code():null,
        'outcome'=>is_array($result)?$result['status']:null,'checkpoint'=>$checkpoint,'approvalCalls'=>$approvalCalls,
        'renderRequests'=>$renderRequests,'renderWidths'=>$renderWidths,'events'=>$events,'reservedUsd'=>$reserved,
        'metering'=>$metric,'review'=>$rawDecision,'responseDigest'=>$rawText===''?null:hash('sha256',$rawText),
        'requestSha256'=>$requestBody===null?null:hash('sha256',wp_json_encode($requestBody)),
        'reviewEvidence'=>array('keys'=>array_keys($context??array()),'imageOrder'=>$context['imageOrder']??array(),
            'sourceImageOrder'=>$context['sourceImageOrder']??array(),
            'measurementWidths'=>array_column($context['measurements']??array(),'width'),
            'sourceVerification'=>$context['sourceVerification']??null,'sourceVisualCheck'=>$context['sourceVisualCheck']??null,
            'sourceCopy'=>$context['sourceCopyCheck']['status']??null));
}

$live=getenv('SM_BOSS_AUDIT_LIVE')==='1';
$trustOnly=getenv('SM_BOSS_AUDIT_TRUST_ONLY')==='1';
$includeArchive=getenv('SM_BOSS_AUDIT_ARCHIVE')==='1';
boss_audit_assert(!$live||wp_get_environment_type()==='local','Live audit requires the local WordPress instance.');
$configured=syndamorph_models_settings();
if($live)boss_audit_assert(($configured['provider']??null)==='anthropic'&&($configured['model']??null)===BOSS_AUDIT_MODEL&&
    ($configured['inputPrice']??null)===0.1&&($configured['outputPrice']??null)===0.5,'The approved model and rates must match current settings.');
$settings=$live?$configured:array('provider'=>'anthropic','model'=>BOSS_AUDIT_MODEL,'inputPrice'=>0.1,'outputPrice'=>0.5,'maxJobUsd'=>1);
if(!$live){
    add_filter('pre_option_syndamorph_ai_key_anthropic',static fn()=>syndamorph_ai_encrypt('bossy-audit-offline'));
    add_filter('pre_option_syndamorph_ai_model_settings',static fn()=>$settings);
}
add_filter('pre_transient_syndamorph_ai_models_anthropic',static fn()=>array('models'=>array(array('id'=>$settings['model']))));
$GLOBALS['boss_audit_actual_calls']=0;$GLOBALS['boss_audit_total']=0.0;

$cases=array();
foreach($fixture['cases'] as $item){
    $link=!empty($item['requiresLink']);
    $candidate=$link?$withLink:$withoutLink;
    if(isset($item['find']))$candidate=boss_audit_replace($candidate,$item['find'],$item['replace']);
    if(isset($item['css']))$candidate=boss_audit_replace($candidate,'</style>',$item['css'].' </style>');
    $cases[$item['id']]=$item+array('html'=>$candidate,'brief'=>boss_audit_brief($link),'mode'=>$item['liveMode'],
        'source'=>match($item['liveMode']){'manual'=>$link?boss_audit_manual($withLink,boss_audit_source($withLink)):$manual,'scoped'=>$source,default=>null});
}
if($includeArchive)$cases['bkkgo-mobile-wrap']=boss_audit_archived_bkkgo();

function boss_audit_print(array $row): void { echo wp_json_encode($row,JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL; }

if($live){
    $order=array_merge(array_keys($cases),$fixture['repeat']);
    boss_audit_assert(count($order)<=BOSS_AUDIT_CALLS,'Live case sequence exceeds authorized calls.');
    $start=0;
    if(getenv('SM_BOSS_AUDIT_CONTINUE')==='1'){
        $prior=file('/tmp/sm-bossy-audit/live-progress.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        foreach($prior?:array() as $i=>$entry){
            $row=json_decode($i===0?preg_replace('/^\xEF\xBB\xBF/','',$entry):$entry,true,32,JSON_THROW_ON_ERROR);
            boss_audit_assert($row['kind']==='live'&&$row['sequence']===$i+1&&$row['case']===($order[$i]??null)&&
                $row['callsTotal']===$i+1&&$row['estimatedTotalUsd']>=$GLOBALS['boss_audit_total']&&
                $row['estimatedTotalUsd']<=BOSS_AUDIT_DOLLARS,'Audit checkpoint differs from the approved sequence.');
            $GLOBALS['boss_audit_actual_calls']=$row['callsTotal'];
            $GLOBALS['boss_audit_total']=$row['estimatedTotalUsd'];
            $start=$i+1;
        }
        boss_audit_assert($start>0&&$start<count($order),'No unrun audit cases remain.');
    }
    foreach($order as $i=>$id){
        if($i<$start)continue;
        $before=$GLOBALS['boss_audit_actual_calls'];
        $case=$cases[$id];$result=boss_audit_run($case,$settings,true);
        if($GLOBALS['boss_audit_actual_calls']>$before){
            $usage=$result['metering'];
            $known=($usage['metering']??null)==='provider_reported';
            $GLOBALS['boss_audit_total']+=$known?syndamorph_collab_cost($usage,$settings['inputPrice'],$settings['outputPrice']):$result['reservedUsd'];
        }else $known=false;
        unset($result['events']);
        boss_audit_print(array('kind'=>'live','sequence'=>$i+1,'case'=>$id,'mode'=>$case['mode'],'expected'=>$case['expected'],
            'callsTotal'=>$GLOBALS['boss_audit_actual_calls'],'metered'=>$known,'estimatedTotalUsd'=>round($GLOBALS['boss_audit_total'],8))+$result);
        if(!$known||$GLOBALS['boss_audit_total']>=BOSS_AUDIT_DOLLARS)break;
    }
    exit;
}

$failures=array();
foreach($cases as $case){
    if($trustOnly&&!in_array($case['id'],array('unchanged','equivalent-color','background','mobile-wrap','altered-copy'),true))continue;
    $measured=boss_audit_snapshot($case,isset($case['archive'])?array():$baselines[!empty($case['requiresLink'])]);
    $modes=isset($case['archive'])||!empty($case['requiresLink'])?array($case['mode']):array('brief','manual','scoped');
    foreach($modes as $mode){
        $input=$case;
        if(!isset($case['archive'])&&empty($case['requiresLink']))$input['source']=match($mode){'manual'=>$manual,'scoped'=>$source,default=>null};
        $result=boss_audit_run($input,$settings);
        unset($result['events']);
        boss_audit_print(array('kind'=>'deterministic','case'=>$case['id'],'mode'=>$mode,'expected'=>$case['expected'],
            'artifactSha256'=>hash('sha256',$case['html']),'verifiedMutation'=>$case['evidence'],'measurement'=>$measured)+$result);
        if($case['expected']==='pass'&&!$result['handoff'])$failures[]=$case['id'].' unexpectedly blocked in '.$mode;
        if(getenv('SM_BOSS_AUDIT_REQUIRE_FIXES')==='1'&&$result['handoff']&&$case['expected']==='block'&&
            ($case['id']==='mobile-wrap'||($mode!=='brief'&&$case['id']!=='missing-decoration')))
            $failures[]=$case['id'].' escaped mandatory validation in '.$mode;
    }
}
$control=$cases['unchanged'];$control['source']=null;
$rejection=array('decision'=>'changes_requested','issues'=>array(array('target'=>'headline','problem'=>'Unverified alignment concern','requiredChange'=>'Move headline')));
$contradiction=$rejection;$contradiction['decision']='approved';
$trust=array(
    'unsupported-rejection'=>boss_audit_run($control,$settings,false,$rejection),
    'contradictory-approval'=>boss_audit_run($control,$settings,false,$contradiction),
    'missing-width'=>boss_audit_run($control,$settings,false,null,'missing-width'),
    'empty-snapshot'=>boss_audit_run($control,$settings,false,null,'empty-snapshot'),
);
foreach($trust as $id=>$data){
    unset($data['events']);
    boss_audit_print(array('kind'=>'trust','case'=>$id)+$data);
}
boss_audit_assert(!$trust['missing-width']['handoff']&&$trust['missing-width']['checkpoint']&&
    $trust['missing-width']['approvalCalls']===0&&$trust['missing-width']['renderWidths']===array(BOSS_AUDIT_WIDTHS,array(375),array(375)),
    'Missing viewport evidence must stop design approval and retain a checkpoint.');
boss_audit_assert(!$trust['contradictory-approval']['handoff']&&!$trust['unsupported-rejection']['handoff'],
    'Conflicting approval or a rejection incorrectly reached Developer.');
if(getenv('SM_BOSS_AUDIT_REQUIRE_FIXES')==='1')boss_audit_assert(
    !$trust['empty-snapshot']['handoff']&&$trust['empty-snapshot']['approvalCalls']===0&&
    $trust['empty-snapshot']['checkpoint']&&$trust['empty-snapshot']['outcome']==='uncompleted',
    'An empty browser snapshot must defer design approval and retain a checkpoint.');
boss_audit_assert(!$failures,implode(' | ',$failures));
