<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['options'] = array();
$GLOBALS['transients'] = array();
$GLOBALS['model_fetches'] = 0;
$GLOBALS['generations'] = array();
class WP_Error {
    public function __construct(public string $code, public string $message, public array $args = array()) {}
    public function get_error_message(): string { return $this->message; }
    public function get_error_code(): string { return $this->code; }
    public function get_error_data(): array { return $this->args; }
}
function is_wp_error($v): bool { return $v instanceof WP_Error; }
function add_action(...$args): void {}
function get_option($key,$default=false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key,$value,$autoload=null) { $GLOBALS['options'][$key]=$value; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key,$value,$ttl) { $GLOBALS['transients'][$key]=$value; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\\-]/i','',(string)$v)); }
function wp_json_encode($v) { return json_encode($v); }
function wp_remote_retrieve_response_code($r) { return $r['status']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function syndamorph_ai_saved_key($provider) { return $provider === 'anthropic' ? 'sensitive-test-key' : ''; }
function wp_remote_get($url,$args) {
    $GLOBALS['model_fetches']++;
    if ( $url !== 'https://api.anthropic.com/v1/models?limit=100' ) throw new RuntimeException('Unexpected models endpoint');
    if ( $args['headers']['x-api-key'] !== 'sensitive-test-key' ) throw new RuntimeException('Missing credential');
    return array( 'status' => 200, 'body' => json_encode(array('data'=>array(
        array('id'=>'claude-dynamic-test-1','display_name'=>'Dynamic Claude Test'),
        array('id'=>'invalid model','display_name'=>'invalid')
    ),'has_more'=>false)) );
}
function wp_remote_post($url,$args) {
    if ($url === 'http://renderer:3000/crop') {
        $GLOBALS['crop_calls']=($GLOBALS['crop_calls']??0)+1;
        $items=json_decode($args['body'],true)['items'];
        return array('status'=>200,'body'=>json_encode(array('images'=>array_map(static fn($item)=>array('media_type'=>'image/jpeg','data'=>base64_encode('cropped-jpeg')),$items))));
    }
    if ($url === 'http://renderer:3000/compare') {
        return array('status'=>200,'body'=>json_encode(array('comparison'=>array_map(
            static fn($width)=>array('width'=>$width,'pixelDifferenceRatio'=>0.15,'meanChannelDifference'=>5.0,'sampledPixels'=>100),array(1440,768,390)))));
    }
    if ($url === 'http://renderer:3000/inspect') {
        $input=json_decode($args['body'],true);
        $native=str_contains($input['html'],'<!-- wp:');
        $GLOBALS['inspection_calls'][]=$native?'native':'reference';
        $sample=base64_encode('mock-jpeg-evidence');
        return array('status'=>200,'body'=>json_encode(array('evidence'=>array_map(
            static fn($width)=>array('width'=>$width,'screenshot'=>$sample,'a11y'=>array(),'snapshot'=>array(
                'overflow'=>false,'elements'=>array(array('tag'=>'H2','id'=>'','text'=>'Welcome & enjoy',
                'rect'=>array(0,0,300,70),'styles'=>array('fontSize'=>'32px','fontWeight'=>'700','color'=>'rgb(0, 0, 0)','letterSpacing'=>'normal'))))),
            array(1440,768,390)))));
    }
    if ($url !== 'https://api.anthropic.com/v1/messages') throw new RuntimeException('Unexpected generation endpoint');
    if (isset($GLOBALS['upstream_test_status'])) return array('status'=>$GLOBALS['upstream_test_status'], 'body'=>'{"sensitive":"should-not-leak"}');
    if (isset($GLOBALS['stop_reason_test'])) return array('status'=>200, 'body'=>json_encode(array(
        'stop_reason'=>$GLOBALS['stop_reason_test'],
        'content'=>array(array('type'=>'text','text'=>'partial output; do not use')),
        'usage'=>array('input_tokens'=>80,'output_tokens'=>1200),
    )));
    $req=json_decode($args['body'],true);
    $GLOBALS['generations'][]=$req;
    if ($args['headers']['x-api-key']!=='sensitive-test-key') throw new RuntimeException('Credential missing');
    $i=count($GLOBALS['generations']);
    if (!empty($GLOBALS['revision_test'])) {
        $text=match($i) {
            1 => 'Job title: Hotel hero',
            2 => 'Use elegant typography and native blocks.',
            3 => '<section><h2>Welcome &amp; enjoy</h2></section>',
            4 => $GLOBALS['native_fixture'],
            5 => '{"issues":[{"id":"heading-fidelity","component":"blocks[0].children[0]","severity":"medium","detail":"Font size needs refinement"}],"summary":"Review heading"}',
            6 => '{"designerNeeded":false,"reason":"Existing design specification sufficient"}',
            7 => json_encode(array('id'=>'blocks[0].children[0]','replacement'=>array('id'=>'blocks[0].children[0]','type'=>'heading','text'=>'Welcome & enjoy','level'=>2,'style'=>array('color'=>'#ffffff','fontSize'=>'2.5rem')))),
            8 => '{"issues":[]}',
            default => throw new RuntimeException('Unexpected extra paid revision call '.$i),
        };
    } else $text= !empty($GLOBALS['native_mode']) ? match($i) {
        1 => 'Job title: Premium hotel hero',
        2 => 'Fluid hero design with strong contrast and clear CTA.',
        3 => '<section class="hero"><style>.hero{padding:3rem}</style><h2>Welcome &amp; enjoy</h2></section>',
        4 => $GLOBALS['native_fixture'],
        5 => '{"issues":[],"summary":"Measurements checked; editor still unverified."}',
        default => throw new RuntimeException('Too many native AI calls'),
    } : match($i) {
        1 => 'Job plan: build premium hotel hero with heading and CTA. Criteria: semantic section, responsive layout.',
        2 => 'Visual design: responsive grid, strong typography, accessible contrast and clear CTA.',
        3 => isset($GLOBALS['native_fixture']) ? $GLOBALS['native_fixture'] : '<section class="hero"><style>.hero{padding:3rem}</style><h1>Welcome to our Hotel</h1><p>Stay with us.</p></section>',
        4 => 'Review: headline and section pass; test contrast and responsiveness in browser before shipping.',
        default => throw new RuntimeException('Too many AI calls'),
    };
    return array('status'=>200,'body'=>json_encode(array(
        'model'=>'claude-dynamic-test-1','stop_reason'=>'end_turn',
        'content'=>array(array('type'=>'text','text'=>$text)),
        'usage'=>array('input_tokens'=>1200,'output_tokens'=>150),
    )));
}
putenv('SYNDAMORPH_RENDERER_URL=http://renderer:3000/render');
require dirname(__DIR__) . '/plugin/includes/ai-models.php';
require dirname(__DIR__) . '/plugin/includes/ai-team-real.php';
function expect($ok,$message) { if(!$ok) throw new RuntimeException($message); }

$rate = syndamorph_anthropic_pricing( 'claude-sonnet-5-5' );
expect( $rate['input'] === 2.0 && $rate['output'] === 10.0, 'Sonnet price not mapped to published standard rates' );
$haiku = syndamorph_anthropic_pricing( 'claude-haiku-5-5' );
expect( $haiku['input'] === 0.1 && $haiku['output'] === 0.5 && str_contains( $haiku['note'], '100K' ), 'Haiku long-context tier missing' );
expect( null === syndamorph_anthropic_pricing( 'claude-unknown-future' ), 'Unknown model given fabricated pricing' );
$catalog=syndamorph_models_catalog('anthropic');
expect(!is_wp_error($catalog),'Dynamic discovery failed');
expect(count($catalog['models'])===1 && $catalog['models'][0]['id']==='claude-dynamic-test-1','Invalid catalog filtering');
syndamorph_models_catalog('anthropic');
expect($GLOBALS['model_fetches']===1,'Catalog cache bypassed unexpectedly');
syndamorph_models_catalog('anthropic',true);
expect($GLOBALS['model_fetches']===2,'Manual catalog refresh ignored');
expect(is_wp_error(syndamorph_models_catalog('google')),'Unsupported provider accepted');
$GLOBALS['options']['syndamorph_ai_model_settings']=array(
    'provider'=>'anthropic','model'=>'claude-dynamic-test-1',
    'inputPrice'=>0.1,'outputPrice'=>0.5,'maxJobUsd'=>1.0,'pricingSource'=>'administrator-entered',
);
$result=syndamorph_team_real_run('Create a premium hotel hero with clear CTA.', false);
expect(is_array($result),'Real team pipeline failed');
expect(array_keys($result['outputs'])===array('boss','designer','developer','validator'),'Role execution order wrong');
expect(count($GLOBALS['generations'])===4,'Expected four paid calls');
expect($result['usage']['inputTokens']===4800 && $result['usage']['outputTokens']===600,'Token usage not aggregated');
expect($result['status']==='generated' && $result['checks']['contains_heading'],'Independent validator failed');
expect($result['published']===false && $result['renderedInBrowser']===false,'POC falsely claimed publishing/rendering');
expect(is_float($result['estimatedCostUsd']),'Estimated spend missing');
expect(count($result['agentMetrics'])===4 && $result['agentMetrics'][1]['role']==='designer' &&
    $result['agentMetrics'][1]['outputTokens']===150 &&
    $result['agentMetrics'][1]['outputCap']===16384,
    'Streaming baseline per-agent metrics or generous output caps missing');
$GLOBALS['options']['syndamorph_ai_model_settings']['maxJobUsd']=0.0001;
expect(is_wp_error(syndamorph_team_real_run('Create a premium hotel hero with clear CTA.', false)),'Budget gate failed');
expect(count($GLOBALS['generations'])===4,'Budget failure made paid call');
unset($GLOBALS['upstream_test_status']);
$GLOBALS['upstream_test_status']=429;
$upstream=syndamorph_models_generate('anthropic','claude-dynamic-test-1','test','test');
expect(is_wp_error($upstream) && $upstream->code==='sm_generation_upstream' &&
    str_contains($upstream->get_error_message(), '429') &&
    !str_contains($upstream->get_error_message(), 'sensitive'), 'Upstream error classification or response-body protection failed');
unset($GLOBALS['upstream_test_status']);
$GLOBALS['stop_reason_test']='max_tokens';
$truncated=syndamorph_models_generate('anthropic','claude-dynamic-test-1','test','test',2400);
expect(is_wp_error($truncated) && $truncated->code==='sm_generation_token_limit' &&
    $truncated->args['stopReason']==='max_tokens' && $truncated->args['outputTokens']===1200,
    'Token exhaustion diagnostic missing or mislabeled');
$GLOBALS['stop_reason_test']='refusal';
$refused=syndamorph_models_generate('anthropic','claude-dynamic-test-1','test','test',2400);
expect(is_wp_error($refused) && $refused->code==='sm_generation_incomplete' &&
    $refused->args['stopReason']==='refusal', 'Refusal misreported as token exhaustion');
unset($GLOBALS['stop_reason_test']);

// Guard against reintroducing a second POC brief or source-required paid job.
$ui = file_get_contents(dirname(__DIR__) . '/plugin/includes/production-team.php');
$client = file_get_contents(dirname(__DIR__) . '/plugin/assets/ai-team-real.js');
$real = strpos($ui, 'id="syndamorph-real-run"');
$reset = strpos($ui, 'id="syndamorph-team-reset"');
expect($real !== false && $reset !== false && $real < $reset &&
    str_contains($ui, 'Start Production') && !str_contains($ui, 'Start simulation'),
    'Production must provide one real start action followed by Reset');
expect(!str_contains($ui, 'id="syndamorph-real-brief"') &&
    !str_contains($ui, 'id="syndamorph-purr-run"') &&
    !str_contains($ui, 'syndamorph-demo-dialogue'),
    'Legacy POC or simulator controls still exposed');
expect(str_contains($client, "byId(") === false || str_contains($client, "const brief = $('syndamorph-team-brief')"),
    'The real client must use the Production Brief');
expect(str_contains($client, 'JSON.stringify({brief:prompt,outputType:activeFormat})') &&
    !str_contains($client, 'validateSource(') &&
    str_contains($client, 'response.body.getReader()') &&
    str_contains($client, 'agent_delta') &&
    str_contains($ui, 'syndamorph/v1/ai/team-stream'),
    'Real AI must use brief-only incremental SSE events');
expect(str_contains($ui, 'id="syndamorph-real-results"') &&
    str_contains($client, "frame.setAttribute('sandbox', '')"),
    'Safe HTML results panel is missing');
$server = file_get_contents(dirname(__DIR__) . '/plugin/includes/ai-team-real.php');
$models = file_get_contents(dirname(__DIR__) . '/plugin/includes/ai-models.php');
expect(str_contains($server, "\$emit( 'job_complete'") &&
    str_contains($server, "syndamorph_team_sse_emit( 'job_error'") &&
    str_contains($server, "'agent_delta'") &&
    str_contains($models, '$on_delta( $delta[\'text\'] )'),
    'Server-side incremental event delivery missing');

$standards = syndamorph_production_standards();
expect($result['standardsVersion'] === $standards['version'], 'Standards version missing from result');
foreach ($GLOBALS['generations'] as $generation) {
    foreach (array_keys($standards['criteria']) as $id) {
        expect(str_contains($generation['system'], $id), 'Missing mandatory criterion ' . $id);
    }
    expect(str_contains($generation['system'], 'Not a full HTML document'), 'Section contract missing');
}
expect(str_contains($GLOBALS['generations'][3]['system'], 'Unverified'), 'Validator must distinguish unverified criteria');
expect(str_contains($ui, 'syndamorph-rich-content') &&
    substr_count($client, 'SyndaMorphRichContent.mount') === 2,
    'Shared renderer must be used by live feed and results');

expect(syndamorph_history_label('GEN',1)==='GEN-000001', 'GEN ID format wrong');
expect(syndamorph_history_label('REF',123)==='REF-000123', 'REF ID format wrong');
expect(str_contains($ui, 'data-panel="history"') && str_contains($client, 'SyndaMorphProductionReplay'), 'History/replay integration missing');
$archive = file_get_contents(dirname(__DIR__) . '/plugin/includes/production-history.php');
expect(str_contains($archive, 'UNIQUE KEY category_sequence') && str_contains($archive, 'ORDER BY id ASC'), 'Job identity or event ordering missing');

echo "PASS: live model catalog cache/refresh, no hardcoded models, four role handoff, usage, deterministic checks and spending gate\n";

$GLOBALS['native_fixture'] = json_encode(array('version'=>1,'blocks'=>array(
    array('type'=>'group','style'=>array('background'=>'#18283a','padding'=>'clamp(2rem,5vw,5rem)'),'children'=>array(
        array('type'=>'heading','text'=>'Welcome & enjoy','level'=>2,'style'=>array('color'=>'#ffffff')),
        array('type'=>'paragraph','text'=>'A premium hotel experience.'),
        array('type'=>'buttons','children'=>array(array('type'=>'button','text'=>'Explore','href'=>'/explore')))
    ))
)));
$GLOBALS['generations']=array();
$GLOBALS['native_mode']=true;
$GLOBALS['inspection_calls']=array();
$GLOBALS['options']['syndamorph_ai_model_settings']['maxJobUsd']=1.0;
$native=syndamorph_team_real_run('Create a native premium hotel hero.',false,null,'gutenberg');
expect(is_array($native) && $native['outputType']==='gutenberg','Native pipeline failed: '.(is_wp_error($native) ? $native->get_error_code().' · '.$native->get_error_message() : gettype($native)));
expect($native['artifact']['blockCount']===5 && $native['checks']['contains_heading'],'Native compilation failed');
expect(str_contains($native['artifact']['content'],'<!-- wp:group') && str_contains($native['artifact']['content'],'Welcome &amp; enjoy'),'Native serialization failed');
expect(count($GLOBALS['generations'])===5,'Native job must execute five initial calls');
expect($GLOBALS['inspection_calls']===array('reference','native'),'Both browser artifacts must be inspected');
expect(count($GLOBALS['generations'][4]['messages'][0]['content'])===5,'Vision validator must receive text and four screenshots');
expect($native['quality']['browserInspected']===true && $native['quality']['visionInspected']===true,'Browser and vision evidence not recorded');
expect(count($native['quality']['pixelComparison'])===3,'Real pixel-comparison metrics not recorded');
expect($native['status']==='needs_review','Unverified Gutenberg editor must not be auto-approved');
expect(str_contains(wp_json_encode($GLOBALS['generations'][3]['system']),'JSON'),'Native developer contract missing');
expect(($GLOBALS['generations'][3]['system'][0]['cache_control']['type']??null)==='ephemeral','Cache prefix absent');
expect($GLOBALS['generations'][0]['system'][0]===$GLOBALS['generations'][4]['system'][0],'Shared cache prefix not stable');
foreach(array(
    '{"version":2,"blocks":[]}',
    '{"version":1,"blocks":[{"type":"html","text":"bad"}]}',
    str_replace('/explore','javascript:alert(1)',$GLOBALS['native_fixture']),
    str_replace('#ffffff','red;position:fixed',$GLOBALS['native_fixture']),
    str_replace('"type":"heading"','"type":"column"',$GLOBALS['native_fixture'])
) as $bad) expect(is_wp_error(syndamorph_compile_block_plan($bad)),'Invalid plan accepted');
$richPlan=json_decode($GLOBALS['native_fixture'],true);
$richPlan['blocks'][0]['style']=array('gradient'=>'linear-gradient(135deg,#18283a,#203040)',
    'padding'=>array('top'=>'3rem','right'=>'2rem','bottom'=>'3rem','left'=>'2rem'),'minHeight'=>'420px','gap'=>'1rem');
$richPlan['blocks'][0]['children'][0]['style']=array('color'=>'#ffffff','fontSize'=>'clamp(2rem,5vw,4rem)',
    'fontWeight'=>'700','lineHeight'=>'1.2','letterSpacing'=>'0.05em','margin'=>array('bottom'=>'1rem'));
$richPlan['blocks'][0]['children'][2]['children'][0]['style']=array('background'=>'#ffffff','color'=>'#18283a','borderRadius'=>'8px','padding'=>'1rem');
$styled=syndamorph_compile_block_plan(wp_json_encode($richPlan));
expect(!is_wp_error($styled),'Expanded Gutenberg native style schema failed to compile');
expect(str_contains($styled['content'],'linear-gradient(135deg') && str_contains($styled['content'],'font-weight:700') &&
    str_contains($styled['content'],'border-radius:8px'),'Native styling was not serialized');
$badStyle=$richPlan;
$badStyle['blocks'][0]['children'][0]['style']['fontWeight']='700;position:fixed';
expect(is_wp_error(syndamorph_compile_block_plan(wp_json_encode($badStyle))),'Unsafe native font weight accepted');
$badStyle=$richPlan;
$badStyle['blocks'][0]['style']['gradient']='linear-gradient(135deg,#000000,red)';
expect(is_wp_error(syndamorph_compile_block_plan(wp_json_encode($badStyle))),'Unsafe native gradient accepted');
expect(str_contains($ui,'syndamorph-team-output-type'),'Output selector missing');
$export=file_get_contents(dirname(__DIR__).'/plugin/assets/production-ai-export.js');
expect(str_contains($export,'native ? artifact : await window.SyndaMorphConverter.prepareImport'),'Native export must bypass manual conversion');
$history=file_get_contents(dirname(__DIR__).'/plugin/assets/production-history.js');
expect(str_contains($history,'artifact:final.artifact'),'Archived native artifact not passed to shared export');
echo "PASS: native pipeline, compiler rejection cases, output selector and shared export integration\n";


$withIds=syndamorph_collab_ids(json_decode($GLOBALS['native_fixture'],true));
expect(isset($withIds['blocks'][0]['children'][0]['id']),'Native component ID assignment missing');
$patch=array('id'=>$withIds['blocks'][0]['children'][0]['id'],'replacement'=>$withIds['blocks'][0]['children'][0]);
$patch['replacement']['text']='Updated heading';
$patched=syndamorph_collab_patch($withIds,$patch);
expect(!is_wp_error($patched) && str_contains($patched['artifact']['content'],'Updated heading'),'Targeted native revision failed');
expect(str_contains($patched['artifact']['content'],'A premium hotel experience.'),'Approved sibling changed by patch');
$impact=syndamorph_collab_impact($withIds,$patched['plan']);
expect(in_array($withIds['blocks'][0]['children'][0]['id'],$impact['changed'],true),'Edited leaf is not invalidated');
expect(in_array($withIds['blocks'][0]['children'][1]['id'],$impact['reused'],true),'Unchanged sibling evidence was invalidated');
expect(!in_array($withIds['blocks'][0]['children'][1]['id'],$impact['affected'],true),'Unchanged sibling incorrectly impacted');
$rootChanged=$withIds;
$rootChanged['blocks'][0]['style']['background']='#203040';
$rootImpact=syndamorph_collab_impact($withIds,$rootChanged);
expect(in_array($withIds['blocks'][0]['children'][1]['id'],$rootImpact['affected'],true),'Parent style changes must invalidate descendants');
$duplicate=$withIds;
$duplicate['blocks'][0]['children'][1]['id']=$duplicate['blocks'][0]['children'][0]['id'];
expect(is_wp_error(syndamorph_collab_validate_ids($duplicate)),'Duplicate component identity accepted');
$patch['replacement']['id']='different';
expect(is_wp_error(syndamorph_collab_patch($withIds,$patch)),'Cross-component patch was allowed');
expect(str_contains($export,'if (native) { void preparePreview(); }'),'Native auto-preview missing');
echo "PASS: screenshot-grounded dual artifact QA, stable IDs and targeted revision isolation\\n";

$GLOBALS['generations']=array();$GLOBALS['inspection_calls']=array();
$GLOBALS['revision_test']=true;
$GLOBALS['options']['syndamorph_ai_model_settings']['maxJobUsd']=1.0;
$revised=syndamorph_team_real_run('Create a native premium hotel hero.',false,null,'gutenberg');
expect(!is_wp_error($revised),'Targeted collaborative revision failed: '.(is_wp_error($revised)?$revised->get_error_message():'unknown'));
expect(count($GLOBALS['generations'])===8,'Boss, Developer and scoped Validator revision should make exactly three incremental calls');
expect($revised['status']==='needs_review' && count($revised['quality']['revisions'])===1 && $revised['quality']['revisions'][0]['accepted'],
    'Successful targeted revision did not resolve the issue');
expect(str_contains($revised['artifact']['content'],'font-size:2.5rem'),'Best native artifact did not keep the repaired heading');
expect(str_contains($revised['artifact']['content'],'A premium hotel experience.'),'Revision destroyed approved sibling');
expect($revised['quality']['revisions'][0]['imageScope']==='component','Scoped review did not use crops');
expect($GLOBALS['crop_calls']===1,'Crop endpoint not exercised');
expect(!$revised['quality']['revisions'][0]['delegation']['designerNeeded'],'Boss delegated unnecessary full design work');
expect(in_array('blocks[0].children[1]',$revised['quality']['revisions'][0]['impact']['reused'],true),'Unchanged sibling evidence not reused');

$GLOBALS['generations']=array();$GLOBALS['inspection_calls']=array();
$GLOBALS['options']['syndamorph_ai_model_settings']['maxJobUsd']=0.010;
$cutoff=syndamorph_team_real_run('Create a native premium hotel hero.',false,null,'gutenberg');
expect(!is_wp_error($cutoff) && $cutoff['status']==='uncompleted','80% budget cutoff must preserve best artifact as Uncompleted');
expect(count($GLOBALS['generations'])===5 && count($cutoff['quality']['revisions'])===0,'80% cutoff initiated unauthorized paid repair');
expect($cutoff['quality']['terminationReason']==='budget_80_percent','Budget stop reason not archived');
expect(isset($cutoff['artifact']['content']),'Uncompleted job lost the usable native artifact');
unset($GLOBALS['revision_test']);
echo "PASS: delegated component repair, scoped vision review, best artifact, reuse and 80-percent budget cutoff\\n";

$memory=new SyndaMorph_Job_Memory('fixture');
$a=$withIds;$b=$withIds;
$a['blocks'][0]['children'][0]['style']['margin']='0px';
$b['blocks'][0]['children'][0]['style']['margin']='0rem';
$memory->remember($a);
expect($memory->seen($b),'Equivalent zero lengths not deduplicated');
for($i=0;$i<3;$i++)$memory->record('heading',array('accepted'=>false,'detail'=>'duplicate'));
expect(!$memory->eligible('heading')&&$memory->eligible('other'),'Stalled target did not yield to other components');
expect(count($memory->feedback('heading'))===3,'Rejected repair feedback lost');
$sample=array('width'=>390,'snapshot'=>array('overflow'=>false,'elements'=>array(array('text'=>'Heading','rect'=>array(0,0,100,20),'styles'=>array('fontSize'=>'20px','display'=>'block')),array('text'=>'Other','rect'=>array(0,20,100,20)))),'screenshot'=>'secret-image');
$compact=syndamorph_memory_measurements(array($sample),array('Heading'));
expect(count($compact[0]['elements'])===1&&!isset($compact[0]['screenshot']),'Scoped context includes irrelevant elements or image bytes');
expect(abs(syndamorph_collab_cost(array('inputTokens'=>100,'cacheReadTokens'=>100,'cacheWriteTokens'=>100,'outputTokens'=>100),1,2)-0.000435)<0.000000001,'Cache-aware cost arithmetic failed');
echo "PASS: shared memory, cache prefix, scoped evidence and screenshot crops\n";
