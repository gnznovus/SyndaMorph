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
function set_transient($key,$value,$ttl) { $GLOBALS['transients'][$key]=$value; return true; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\\-]/i','',(string)$v)); }
function wp_json_encode($v) { return json_encode($v); }
function wp_remote_retrieve_response_code($r) { return $r['status']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function wp_remote_retrieve_header($r,$name) { return $r['headers'][$name]??''; }
function inspection_reply(array $data,int $status=200): array {
    $body=json_encode($data);
    return array('status'=>$status,'body'=>$body,'headers'=>array(
        'content-length'=>strlen($body),'x-syndamorph-inspection-protocol'=>'1',
        'x-syndamorph-renderer-build'=>str_repeat('a',64),
    ));
}
function inspection_fixture_jpeg(int $width): string {
    // Minimal synthetic JPEG header for mocked transport; real integration decodes Chromium output.
    return base64_encode(hex2bin('ffd8ffc0000b08').pack('nn',900,$width).hex2bin('01011100ffd9'));
}
function syndamorph_ai_saved_key($provider) { return $provider === 'anthropic' ? 'sensitive-test-key' : ''; }
function wp_remote_get($url,$args) {
    if($url==='http://renderer:3000/healthz') {
        if(isset($GLOBALS['inspection_health']))return $GLOBALS['inspection_health'];
        return inspection_reply(array('service'=>'syndamorph-renderer','inspectionProtocol'=>1,'build'=>str_repeat('a',64),
            'maxResponseBytes'=>3602048,'maxScreenshotBytes'=>400000));
    }
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
        $widths=array_column(json_decode($args['body'],true)['reference'],'width');
        return array('status'=>200,'body'=>json_encode(array('comparison'=>array_map(
            static fn($width)=>array('width'=>$width,'pixelDifferenceRatio'=>0.15,'meanChannelDifference'=>5.0,'sampledPixels'=>100),$widths))));
    }
    if ($url === 'http://renderer:3000/inspect') {
        $input=json_decode($args['body'],true);
        $native=str_contains($input['html'],'<!-- wp:');
        $GLOBALS['inspection_calls'][]=$native?'native':'reference';
        $GLOBALS['inspection_sent_hashes'][]=hash('sha256',$input['html']);
        $GLOBALS['inspection_sent_widths'][]=$input['viewports'];
        if(!empty($GLOBALS['inspection_failures']))return array_shift($GLOBALS['inspection_failures']);
        return inspection_reply(array('evidence'=>array_map(
            static fn($width)=>array('width'=>$width,'screenshot'=>inspection_fixture_jpeg($width),'a11y'=>array(),'snapshot'=>array(
                'overflow'=>false,'elements'=>array(array('tag'=>'H2','id'=>'','text'=>'Welcome & enjoy',
                'rect'=>array(0,0,300,70),'styles'=>array('fontSize'=>'32px','fontWeight'=>'700','color'=>'rgb(0, 0, 0)','letterSpacing'=>'normal'))))),
            $input['viewports'])));
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
    if (!empty($GLOBALS['surgery_test'])) {
        $text=match($i) {
            1 => '{"title":"Surgical recovery fixture","sections":["Hero"],"constraints":["Native Gutenberg"],"acceptance":["Valid editable blocks"]}',
            2 => '<section><h2>Welcome &amp; enjoy</h2></section>',
            3 => '{"decision":"approved","issues":[]}',
            4 => $GLOBALS['native_fixture'],
            5 => '{"id":"blocks[0]","changes":[{"path":"style.background","op":"remove"},{"path":"style.gradient","value":"linear-gradient(135deg,#18283a,#203040)"}]}',
            6 => '{"decision":"approved","issues":[],"summary":"No measured defects"}',
            default => throw new RuntimeException('Unexpected surgery paid model call '.$i),
        };
    } elseif (!empty($GLOBALS['stall_test'])) {
        $text=match($i){
            1 => '{"title":"Stalled QA fixture","sections":["Hero"],"constraints":["Native Gutenberg"],"acceptance":["Valid blocks"]}',
            2 => '<section><h2>Welcome &amp; enjoy</h2></section>',
            3 => '{"decision":"approved","issues":[]}',
            4 => $GLOBALS['native_fixture'],
            5 => '{"decision":"changes_requested","issues":[{"id":"heading-fidelity","component":"blocks[0].children[0]","severity":"medium","detail":"Typography mismatch"}]}',
            6,7,8 => '{"id":"blocks[0].children[0]","changes":[{"path":"style.notAllowed","value":"1px"}]}',
            9 => '{"decision":"engineering_blocked","summary":"Repeated surgical changes were outside schema","nextAction":"Review repair scope and evidence"}',
            default => throw new RuntimeException('Unexpected paid stalled QA call '.$i),
        };
    } elseif (!empty($GLOBALS['parent_issue_test'])) {
        $text=match($i){
            1 => '{"title":"Parent issue fixture","sections":["Hero"],"constraints":["Native Gutenberg"],"acceptance":["Valid blocks"]}',
            2 => '<section><h2>Welcome &amp; enjoy</h2></section>',
            3 => '{"decision":"approved","issues":[]}',
            4 => $GLOBALS['native_fixture'],
            5 => '{"decision":"changes_requested","issues":[{"id":"cta-spacing","component":"blocks[0]","severity":"medium","detail":"Parent layout spacing differs from reference"}]}',
            6 => '{"id":"blocks[0]","changes":[{"target":"blocks[0].children[0]","path":"style.margin.bottom","value":"1rem"}]}',
            7 => '{"decision":"approved","issues":[]}',
            default => throw new RuntimeException('Unexpected paid parent repair call '.$i),
        };
    } elseif (!empty($GLOBALS['adaptive_test'])) {
        $structure='{"id":"blocks[0]","diagnosis":"An inner flex group can preserve content and apply consistent gap","wraps":[{"parent":"blocks[0]","children":["blocks[0].children[0]","blocks[0].children[1]"],"id":"text-stack","style":{"justifyContent":"center","gap":"1rem"}}],"changes":[{"path":"style.contentWidth","value":"36rem"},{"target":"blocks[0].children[0]","path":"style.margin.bottom","value":"1rem"},{"target":"blocks[0].children[0]","path":"style.align","value":"center"},{"target":"blocks[0].children[1]","path":"style.align","value":"center"}]}';
        $input=json_decode($req['messages'][0]['content'][0]['text'],true);
        $learnedStructure=$i===6 && !empty($input['learnedCorrections']);
        $startedWithStructure=$i===7 && str_contains($GLOBALS['generations'][5]['messages'][0]['content'][0]['text'],'"strategy":"structure"');
        $text=match($i) {
            1 => '{"title":"Adaptive fixture","sections":["Hero"],"constraints":["Native layout"],"acceptance":["Suitable layout"]}',
            2 => '<section><h2>Welcome &amp; enjoy</h2></section>',
            3 => '{"decision":"approved","issues":[]}',
            4 => $GLOBALS['native_fixture'],
            5,7 => $startedWithStructure?'{"decision":"approved","issues":[]}':
                '{"decision":"changes_requested","issues":[{"id":"layout-spacing","component":"blocks[0]","category":"spacing","scope":"section","severity":"high","detail":"Text leaves need a shared vertical layout."}]}',
            6 => $learnedStructure?$structure:'{"id":"blocks[0]","diagnosis":"Try explicit style first","changes":[{"path":"style.gap","value":"2rem"}]}',
            8 => $structure,
            9 => '{"decision":"approved","issues":[]}',
            default => throw new RuntimeException('Unexpected adaptive repair call '.$i),
        };
    } elseif (!empty($GLOBALS['revision_test'])) {
        if (str_contains($req['system'][1]['text'] ?? '', 'Task: scope_resolution.')) {
            $resolution = array('decision'=>$GLOBALS['inspection_escalation_test']??'manual_review_required',
                'summary'=>'Repair reserve reached','nextAction'=>'Review the retained artifact');
            return array('status'=>200,'body'=>json_encode(array(
                'model'=>'claude-dynamic-test-1','stop_reason'=>'end_turn',
                'content'=>array(array('type'=>'text','text'=>json_encode($resolution))),
                'usage'=>array('input_tokens'=>1200,'output_tokens'=>150),
            )));
        }
        $text=match($i) {
            1 => '{"title":"Hotel hero","sections":["Hero"],"constraints":["Native Gutenberg"],"acceptance":["Valid editable blocks"]}',
            2 => '<section><h2>Welcome &amp; enjoy</h2></section>',
            3 => '{"decision":"approved","issues":[],"summary":"Approved design"}',
            4 => $GLOBALS['native_fixture'],
            5 => '{"decision":"changes_requested","issues":[{"id":"heading-fidelity","component":"blocks[0].children[0]","severity":"medium","detail":"Font size needs refinement"}],"summary":"Review heading"}',
            6 => '{"id":"blocks[0].children[0]","changes":[{"path":"style.fontSize","value":"2.5rem"}]}',
            7 => '{"decision":"approved","issues":[]}',
            default => throw new RuntimeException('Unexpected extra paid revision call '.$i),
        };
    } else $text= !empty($GLOBALS['native_mode']) ? match($i) {
        1 => '{"title":"Premium hotel hero","sections":["Hero"],"constraints":["Native Gutenberg"],"acceptance":["Valid editable blocks"]}',
        2 => '<section class="hero"><style>.hero{padding:3rem}</style><h2>Welcome &amp; enjoy</h2></section>',
        3 => '{"decision":"approved","issues":[],"summary":"Approved design"}',
        4 => $GLOBALS['native_fixture'],
        5 => '{"decision":"approved","issues":[],"summary":"Measurements checked; editor still unverified."}',
        default => throw new RuntimeException('Too many native AI calls'),
    } : match($i) {
        1 => 'Job plan: build premium hotel hero with heading and CTA. Criteria: semantic section, responsive layout.',
        2 => 'Visual design: responsive grid, strong typography, accessible contrast and clear CTA.',
        3 => isset($GLOBALS['native_fixture']) ? $GLOBALS['native_fixture'] : '<section class="hero"><style>.hero{padding:3rem}</style><h1>Welcome to our Hotel</h1><p>Stay with us.</p></section>',
        4 => 'Review: headline and section pass; test contrast and responsiveness in browser before shipping.',
        default => throw new RuntimeException('Too many AI calls'),
    };
    $mockInput=!empty($GLOBALS['cutoff_fixture']) && str_contains($req['system'][1]['text']??'','Task: validation.')?
        $GLOBALS['cutoff_fixture']:1200;
    return array('status'=>200,'body'=>json_encode(array(
        'model'=>'claude-dynamic-test-1','stop_reason'=>'end_turn',
        'content'=>array(array('type'=>'text','text'=>$text)),
        'usage'=>array('input_tokens'=>$mockInput,'output_tokens'=>150),
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
expect(syndamorph_collab_output_cap()===16384,'Native AI team output cap must be 16384 for every role and task');
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
expect(count($GLOBALS['generations'][3]['messages'][0]['content'])===3,'Initial native Developer lacks reference screenshots');
expect(count($GLOBALS['generations'][4]['messages'][0]['content'])===5,'Vision validator must receive text and four screenshots');
expect($native['quality']['browserInspected']===true && $native['quality']['visionInspected']===true,'Browser and vision evidence not recorded');
expect(count($native['quality']['pixelComparison'])===3,'Real pixel-comparison metrics not recorded');
expect($native['status']==='needs_review','Unverified Gutenberg editor must not be auto-approved');
expect($native['quality']['bossApproved']===true,'Boss must explicitly approve native QA before handoff to editor review');
expect($native['designApproval']['decision']==='approved'&&$native['designApproval']['reviewRounds']===1&&$native['handoffs'][0]['type']==='assignment'&&$native['handoffs'][1]['decision']==='approved','Designer reference crossed handoff without Boss approval');
expect(count($GLOBALS['generations'][2]['messages'][0]['content'])===3,'Boss did not receive desktop/mobile visual evidence before Developer');
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
$nestedPlan=$richPlan;
$nestedPlan['blocks'][0]['children'][0]['style']=array('typography'=>array('fontSize'=>'2rem'));
$nestedPlan=syndamorph_collab_ids($nestedPlan);
$nestedError=syndamorph_compile_block_plan(wp_json_encode($nestedPlan));
expect(is_wp_error($nestedError),'Nested typography must fail before surgery');
$diagnostic=array('code'=>$nestedError->get_error_code(),'message'=>$nestedError->get_error_message(),'field'=>$nestedError->get_error_data()['field']);
$localFocus=syndamorph_surgery_extract($nestedPlan,$diagnostic);
expect(is_array($localFocus)&&!isset($localFocus['node']['children']),'Surgical extract leaked complete descendants');
$normalized=syndamorph_surgery_normalize($nestedPlan,$diagnostic);
expect(is_array($normalized),'Nested typography normalization not detected');
$resolved=syndamorph_surgery_verify($nestedPlan,$normalized['plan']);
expect(!is_wp_error($resolved),'Deterministic surgical normalization failed');
$key=syndamorph_surgery_save($resolved['plan']);
expect($key!==''&&syndamorph_surgery_load($key)['revision']===1,'Working draft not persisted');
expect(syndamorph_surgery_save($resolved['plan'],$key)===$key&&syndamorph_surgery_load($key)['revision']===2,'Working draft revision not persisted');
$target=$resolved['plan']['blocks'][0]['children'][0];
$patch=syndamorph_surgery_apply($resolved['plan'],array('id'=>$target['id'],'changes'=>array(array('path'=>'style.fontSize','value'=>'2.5rem'))));
expect(is_array($patch)&&!is_wp_error($patch['artifact']),'Surgical property correction failed');
expect($patch['plan']['blocks'][0]['children'][1]===$resolved['plan']['blocks'][0]['children'][1],'Surgical repair modified unrelated sibling');
expect(is_wp_error(syndamorph_surgery_apply($resolved['plan'],array('id'=>$target['id'],'changes'=>array(array('path'=>'text','value'=>'tampered'))))),'Surgery escaped style-only scope');
$invalid=syndamorph_surgery_apply($resolved['plan'],array('id'=>$target['id'],'changes'=>array(array('path'=>'style.fontSize','value'=>'url(javascript:evil)'))));
expect(is_array($invalid)&&is_wp_error($invalid['error'])&&$invalid['artifact']===null,'Invalid surgery candidate passed compilation');

expect(str_contains($styled['content'],'linear-gradient(135deg') && str_contains($styled['content'],'font-weight:700') &&
    str_contains($styled['content'],'border-radius:8px'),'Native styling was not serialized');
$badStyle=$richPlan;
$badStyle['blocks'][0]['children'][0]['style']['fontWeight']='700;position:fixed';
expect(is_wp_error(syndamorph_compile_block_plan(wp_json_encode($badStyle))),'Unsafe native font weight accepted');
$badStyle=$richPlan;
$badStyle['blocks'][0]['style']['gradient']='linear-gradient(135deg,#000000,red)';
expect(is_wp_error(syndamorph_compile_block_plan(wp_json_encode($badStyle))),'Unsafe native gradient accepted');

expect(in_array('playwright.inspect',syndamorph_production_tool_grants('boss'),true)
    && in_array('playwright.inspect',syndamorph_production_tool_grants('designer'),true)
    && in_array('playwright.inspect',syndamorph_production_tool_grants('developer'),true),
    'Playwright not shared across authorized roles');
expect(is_wp_error(syndamorph_production_tool('anonymous','playwright.inspect',array('html'=>'<section></section>'))),
    'Untrusted actor received shared tool access');
expect(is_wp_error(syndamorph_production_tool('designer','compiler.patch',array())),
    'Designer received Developer-only native patch authority');
$validBefore=$GLOBALS['native_fixture'];
$GLOBALS['native_fixture']=str_replace('"background":"#18283a"',
    '"background":"linear-gradient(135deg,#18283a,#203040)"',$validBefore);
$GLOBALS['surgery_test']=true;
$GLOBALS['generations']=array();$GLOBALS['inspection_calls']=array();
$case=syndamorph_team_real_run('Recover one invalid background without regenerating the entire plan.',false,null,'gutenberg');
expect(!is_wp_error($case),'GEN-012-style compiler surgery failed: '.(is_wp_error($case)?$case->get_error_message():'unknown'));
expect(count($GLOBALS['generations'])===6,'Surgery should require one paid field patch, not full-plan retries');
expect(!str_contains(wp_json_encode($GLOBALS['generations'][4]['messages'][0]['content']), 'Welcome & enjoy'),
    'Paid schema repair received unrelated descendant content');
expect($case['surgery']['paidPatches']===1&&$case['surgery']['fullRegenerations']===0,
    'Surgery metrics do not distinguish patch calls from full generations');
expect($case['surgery']['repairs'][0]['accepted']&&$case['surgery']['repairs'][0]['compiled'],
    'Surgical correction did not compile');
expect(str_contains($case['artifact']['content'],'linear-gradient(135deg')&&
    str_contains($case['artifact']['content'],'Welcome &amp; enjoy'),
    'Compiled surgical plan lost the gradient or original content');
$persisted=syndamorph_surgery_load($case['surgery']['workingPlanId']);
expect(is_array($persisted)&&$persisted['revision']===2,
    'Resolved surgical plan was not persisted as a second draft revision');
expect(count(syndamorph_learning_retrieve('developer'))>0,'Accepted GEN-012 compiler fix was not retained as verified learning');
// Keep unrelated legacy editor-learning fixtures isolated from this recovery benchmark.
$GLOBALS['options'][SYNDAMORPH_LEARNING_OPTION]=array();
unset($GLOBALS['surgery_test']);
$GLOBALS['native_fixture']=$validBefore;

expect(str_contains($ui,'syndamorph-team-output-type'),'Output selector missing');
$export=file_get_contents(dirname(__DIR__).'/plugin/assets/production-ai-export.js');
expect(str_contains($export,'native ? activeArtifact : await window.SyndaMorphConverter.prepareImport'),'Native export must bypass manual conversion');
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
expect(count($GLOBALS['generations'])===7,'Direct Developer and scoped QA should make exactly two incremental calls');
expect($revised['status']==='needs_review' && count($revised['quality']['revisions'])===1 && $revised['quality']['revisions'][0]['accepted'],
    'Successful targeted revision did not resolve the issue');
expect(str_contains($revised['artifact']['content'],'font-size:2.5rem'),'Best native artifact did not keep the repaired heading');
expect(str_contains($revised['artifact']['content'],'A premium hotel experience.'),'Revision destroyed approved sibling');
expect($revised['quality']['revisions'][0]['imageScope']==='component','Scoped review did not use crops');
expect($GLOBALS['crop_calls']===2,'Developer and QA crop endpoints not exercised');
expect(count($GLOBALS['generations'][5]['messages'][0]['content'])===3,'Developer repair lacks selected reference/native image pair');
expect($GLOBALS['generations'][5]['max_tokens']===2048 && $GLOBALS['generations'][6]['max_tokens']===4096,
    'Developer and Boss repair calls ignored task output limits');
expect($revised['quality']['revisions'][0]['usage']['stages']===array('repair','revision_validation') &&
    $revised['quality']['revisions'][0]['usage']['inputTokens']===2400 &&
    $revised['quality']['revisions'][0]['retainedImprovement']===1.0,'Repair usage or retained improvement was not attributed');
expect($revised['quality']['revisions'][0]['route']==='developer' && !$revised['quality']['revisions'][0]['delegation']['designerNeeded'],'Routine repair invoked unnecessary design work');
expect(count($revised['quality']['revisions'][0]['changedFields']??array())===1 &&
    !isset($revised['quality']['revisions'][0]['changedFields'][0]['replacement']),
    'QA repair still sends full-component replacements');
expect(in_array('blocks[0].children[1]',$revised['quality']['revisions'][0]['impact']['reused'],true),'Unchanged sibling evidence not reused');

$GLOBALS['generations']=array();$GLOBALS['inspection_calls']=array();
// Admit initial screenshot QA with the cached contract overhead, but not the full revision reserve.
$GLOBALS['cutoff_fixture']=74000;
$cropsBeforeCutoff=$GLOBALS['crop_calls'];
$GLOBALS['options']['syndamorph_ai_model_settings']['maxJobUsd']=0.015;
$cutoff=syndamorph_team_real_run('Create a native premium hotel hero.',false,null,'gutenberg');
unset($GLOBALS['cutoff_fixture']);
expect(!is_wp_error($cutoff) && $cutoff['status']==='uncompleted','80% working-budget cutoff must preserve best artifact as Uncompleted: '.(is_wp_error($cutoff)?$cutoff->get_error_code().' '.$cutoff->get_error_message():($cutoff['status']??'unknown')));
$cutoffTasks=array_map(static function(array $request): string {
    preg_match('/^Task: ([a-z_]+)\./',$request['system'][1]['text']??'', $match);
    return $match[1]??'unknown';
},$GLOBALS['generations']);
expect($cutoffTasks===array('boss','designer','final_approval','developer','validation','scope_resolution') &&
    count($cutoff['quality']['revisions'])===0,'80% budget admission must reserve only the final Boss summary');
expect($GLOBALS['crop_calls']===$cropsBeforeCutoff+1,'Budget fixture must exercise admission of the Developer and QA pair');
expect($cutoff['quality']['escalation']['decision']==='manual_review_required','Reserved Boss summary was not recorded');
$escalationInput=json_decode($GLOBALS['generations'][5]['messages'][0]['content'][0]['text'],true);
expect($escalationInput['blockerType']==='implementation' &&
    $escalationInput['openIssues'][0]['id']==='heading-fidelity','Boss did not receive the actual remaining blocker');
expect($cutoff['quality']['terminationReason']==='budget_80_percent','80% reserve stop reason not archived');
expect(isset($cutoff['artifact']['content']),'Uncompleted job lost the usable native artifact');
unset($GLOBALS['revision_test']);

$gen015=json_decode(file_get_contents(__DIR__.'/fixtures/gen-015-repair.json'),true,512,JSON_THROW_ON_ERROR);
expect($gen015['archive']['job']==='GEN-000002' && $gen015['baseline']['score']===38 &&
    $gen015['baseline']['retained']===0,'Archived GEN-015 baseline changed');
expect(array_column(array_column($gen015['rounds'],'result'),'accepted')===array(false,false,false,false),
    'Archived GEN-015 outcomes changed');
$gen015Round3=$gen015['rounds'][2];
expect($gen015Round3['result']['scoreAfter']===34 &&
    $gen015Round3['result']['introducedIssues']===array('layout-root-desktop-centering'),
    'Archived GEN-015 acceptance counterexample changed');
expect(array_column($gen015Round3['pixels'],'pixelDifferenceRatio')===array(0.06784,0.06804,0.07448),
    'GEN-015 Chromium replay comparison changed');
expect(syndamorph_collab_viewports('Responsive section.')===array(1440,768,375),
    'Production did not default to the required 375px mobile width');
expect(syndamorph_collab_viewports('Test at 360/820/1600 CSS px.')===array(1600,820,360) &&
    syndamorph_collab_viewports('Viewports: 360/820/1600.')===array(1600,820,360) &&
    syndamorph_collab_viewports('Apply padding at 24px and border radius at 32px.')===array(1440,768,375) &&
    is_wp_error(syndamorph_collab_viewports('Test at 240/820/1600 CSS px.')),
    'Explicit viewport requests must be honored or rejected visibly');
$gen015Preview=syndamorph_compile_block_plan(wp_json_encode($gen015['plan']));
expect(str_contains($gen015Preview['preview'],'margin-left:auto!important;margin-right:auto!important') &&
    str_contains($gen015Preview['preview'],'data-sm-node="sm-hero-title"') &&
    !str_contains($gen015Preview['content'],'data-sm-node'),
    'Constrained preview changed Gutenberg markup or ignored core centering');
$matched=syndamorph_collab_pairs($gen015['evidence']['reference'][0]['snapshot']['elements'],
    $gen015['evidence']['baseline'][0]['snapshot']['elements'],$gen015['plan']);
expect(in_array('sm-hero-intro',array_column($matched,'owner'),true),'Multiline reference intro was not matched');
$referenceEvidence=$gen015['evidence']['reference'];
$beforeMetrics=syndamorph_visual_metrics($referenceEvidence,$gen015['evidence']['baseline'],$gen015['plan']);
$afterMetrics=syndamorph_visual_metrics($referenceEvidence,$gen015['evidence']['round3'],$gen015['plan']);
$centering='alignment|sm-hero-root';
expect($beforeMetrics[$centering]['views'][1440]['error']===$afterMetrics[$centering]['views'][1440]['error'],
    'Archived centering defect predates the candidate');
$beforeIssues=array_values(array_filter(syndamorph_visual_geometry_issues($beforeMetrics),
    static fn($issue)=>$issue['group']!==$centering)); // Original QA had not reported centering.
$reportedCentering=syndamorph_issue_model($gen015Round3['review']['issues'][0],$gen015['plan']);
expect($reportedCentering['group']===$centering,'Model ownership drift split a section-level issue');
$afterIssues=syndamorph_issue_merge(array_merge(syndamorph_visual_geometry_issues($afterMetrics),array($reportedCentering)));
$assessment=syndamorph_visual_decision($beforeIssues,$afterIssues,$beforeMetrics,$afterMetrics,
    $gen015['baseline']['pixels'],$gen015Round3['pixels'],$beforeIssues,false);
expect($assessment['accepted'] && $assessment['states'][$centering]==='pre_existing' &&
    !$assessment['introducedIssues'],'GEN-015 improvement still rejected due to discovery of unchanged centering');
$worseEvidence=$gen015['evidence']['round3'];
$worseEvidence[2]['snapshot']['overflow']=true;
$regressive=syndamorph_visual_decision($beforeIssues,
    array_merge($afterIssues,syndamorph_collab_evidence($referenceEvidence,$worseEvidence)),
    $beforeMetrics,$afterMetrics,$gen015['baseline']['pixels'],$gen015Round3['pixels'],$beforeIssues,false);
expect(!$regressive['accepted'] && in_array('overflow-390',$regressive['introducedIssues'],true),
    'Global improvement hid a genuine mobile overflow regression');
$styles=syndamorph_collab_style_findings($referenceEvidence,$gen015['evidence']['baseline'],$gen015['plan']);
$cta=array_values(array_filter($styles,static fn($issue)=>$issue['component']==='sm-hero-cta'));
expect(count($cta)===1 && count($cta[0]['views'])===3 && syndamorph_issue_score($cta)===4,
    'A single CTA typography defect was counted separately by viewport');
echo "PASS: GEN-015 partial repair retention, stable defect identity and genuine regression veto\n";
$historyMemory=new SyndaMorph_Job_Memory('archive replay');
$historyMemory->record('sm-hero-root',array('round'=>2,'id'=>'sm-hero-root','accepted'=>false,
    'changes'=>$gen015['rounds'][1]['patch']['changes'],'touched'=>array('sm-hero-eyebrow'),
    'reason'=>'Existing alignment was assigned a new issue ID','remainingIssues'=>$afterIssues));
$descendantFeedback=$historyMemory->feedback('sm-hero-eyebrow');
expect(count($descendantFeedback)===1 && $descendantFeedback[0]['attemptedChanges']===$gen015['rounds'][1]['patch']['changes'] &&
    isset($descendantFeedback[0]['reason'],$descendantFeedback[0]['remainingIssues'][0]['detail']),
    'A child repair did not receive the ancestor correction and actionable rejection evidence');
expect(syndamorph_repair_prioritize(array(array('id'=>'first','severity'=>'medium'),array('id'=>'root','severity'=>'high')))[0]['id']==='root',
    'Boss escalation did not prioritize a high-impact blocker');
$notes=syndamorph_reference_capability_notes('<section><style>.k{text-transform:uppercase}</style></section>');
expect(count($notes)===1 && str_contains($notes[0],'typography'),'Text transform was misclassified as an advanced layout effect');
$GLOBALS['adaptive_test']=true;
$GLOBALS['generations']=array();
$GLOBALS['options']['syndamorph_ai_model_settings']['maxJobUsd']=1.0;
$adaptive=syndamorph_team_real_run('Repair the native layout with a scoped coordinated operation.',false,null,'gutenberg');
expect(!is_wp_error($adaptive) && array_column($adaptive['quality']['revisions'],'accepted')===array(false,true) &&
    array_column($adaptive['quality']['revisions'],'mode')===array('style','structure'),
    'Scheduler did not switch strategy after a failed style repair');
$adaptivePlan=json_decode($adaptive['outputs']['developer'],true);
expect($adaptivePlan['blocks'][0]['children'][0]['id']==='text-stack' &&
    $adaptivePlan['blocks'][0]['children'][1]['id']==='blocks[0].children[2]' &&
    count($adaptive['quality']['revisions'][1]['changedFields'])===4 &&
    syndamorph_surgery_load($adaptive['surgery']['workingPlanId'])['revision']===2,
    'Combined structural and four style changes did not commit atomically with sibling preservation');
$groupSummary=$adaptive['quality']['repairSummary']['groups'][0];
expect($groupSummary['attempts']===2 && $groupSummary['usage']['calls']===4 &&
    $groupSummary['retainedImprovement']===1.0 && $adaptive['quality']['revisions'][0]['retainedImprovement']===0.0,
    'Coordinated operation did not include failed work in its cost');
$adaptiveRequest=json_decode($GLOBALS['generations'][7]['messages'][0]['content'][0]['text'],true);
expect(($adaptiveRequest['previousAttempts'][0]['reason']??'')==='No verified improvement in the affected region',
    'Structural diagnosis did not receive failed style validation');
$unauthorized=array('id'=>'sm-hero-root','wraps'=>array(array('parent'=>'sm-hero-root',
    'children'=>array('sm-hero-title','sm-hero-eyebrow'),'id'=>'reordered')));
expect(is_wp_error(syndamorph_surgery_operation($gen015['plan'],$unauthorized,array('id'=>'sm-hero-root','mode'=>'structure',
    'nodes'=>array('sm-hero-root','sm-hero-eyebrow','sm-hero-title'),'wrapParents'=>array('sm-hero-root')))),
    'Structural surgery accepted reordered protected children');
$learningRows=$GLOBALS['options'][SYNDAMORPH_LEARNING_OPTION]??array();
$scriptedAssignment=array('focus'=>array(array('category'=>'spacing')),'group'=>array('spacing|fixture'),
    'node'=>array('type'=>'group'),'nodes'=>array('fixture','fixture-heading'),'mode'=>'structure');
$scriptedPatch=array('wraps'=>array(array('id'=>'synthetic-group')),'changes'=>array(array('path'=>'style.contentWidth')));
$scriptedEvidence=array('accepted'=>true,'focusBefore'=>10,'focusAfter'=>0,'pixelDeltas'=>array(375=>-0.04),
    'states'=>array('spacing|fixture'=>'resolved'),
    'geometryDeltas'=>array('spacing|fixture'=>array(375=>array('before'=>28,'after'=>2))));
$lesson=syndamorph_learning_operation($scriptedAssignment,$scriptedPatch,$scriptedEvidence,'test:scripted-benchmark');
expect(is_string($lesson),'Verified structural lesson did not persist');
$coldCalls=0;$warmCalls=0;
for($trial=0;$trial<2;$trial++) {
    $GLOBALS['generations']=array();
    $cold=syndamorph_collab_run('Coordinated layout benchmark',false,null,false);
    $coldCalls+=count($GLOBALS['generations']);
    $GLOBALS['generations']=array();
    $warm=syndamorph_collab_run('Coordinated layout benchmark',false,null,true);
    $warmCalls+=count($GLOBALS['generations']);
    expect(!is_wp_error($cold) && !is_wp_error($warm) &&
        count($cold['quality']['revisions'])===2 && count($warm['quality']['revisions'])===1 &&
        $warm['quality']['score']===$cold['quality']['score'],'Learning benchmark did not preserve its quality baseline');
    $trace=$warm['quality']['revisions'][0]['learning'];
    expect(in_array($lesson,$trace['matched'],true) && $trace['injected']===array($lesson) &&
        $trace['followed']===array($lesson) && $trace['validation']==='passed',
        'Lesson matching, injection, adoption and validation were not observable');
}
expect($coldCalls===18 && $warmCalls===14,'Scripted learning comparison did not bypass the failed style strategy');
$GLOBALS['options'][SYNDAMORPH_LEARNING_OPTION]=$learningRows;
unset($GLOBALS['adaptive_test']);
echo "PASS: adaptive repair switches to scoped structural surgery and persists a combined operation atomically\n";
echo "PASS: scripted learning benchmark, 18 calls disabled vs 14 enabled across two matched trials\n";
// GEN-013 reproduction: issue is owned by an ancestor but repair changes a leaf.
$fixtureBeforeParent=$GLOBALS['native_fixture'];
$parentPlan=json_decode($GLOBALS['native_fixture'],true);
$parentPlan['blocks'][0]['children'][0]['style']['margin']=array('bottom'=>'0px');
$GLOBALS['native_fixture']=wp_json_encode($parentPlan);
$GLOBALS['generations']=array();$GLOBALS['inspection_calls']=array();
$GLOBALS['options']['syndamorph_ai_model_settings']['maxJobUsd']=1.0;
$GLOBALS['parent_issue_test']=true;
$parentCase=syndamorph_team_real_run('Correct a layout gap without regenerating a group.',false,null,'gutenberg');
expect(!is_wp_error($parentCase),'Ancestor issue surgical test failed: '.(is_wp_error($parentCase)?$parentCase->get_error_message():'unknown'));
expect($parentCase['status']==='needs_review'&&count($parentCase['quality']['revisions'])===1 &&
    $parentCase['quality']['revisions'][0]['accepted']&&$parentCase['quality']['score']===0,
    'Boss-approved leaf surgery failed to clear ancestor-owned visual issue');
expect(in_array('blocks[0]',$parentCase['quality']['revisions'][0]['reviewScope'],true),
    'Parent layout issue not included in child repair review scope');
expect(in_array('blocks[0].children[1]',$parentCase['quality']['revisions'][0]['impact']['reused'],true),
    'Untouched sibling lost component identity reuse');
expect(str_contains($parentCase['artifact']['content'],'margin-bottom:1rem'),
    'Accepted child margin surgery was not applied');
expect(syndamorph_surgery_load($parentCase['surgery']['workingPlanId'])['revision']===2,
    'QA surgery did not persist an accepted revision');
expect(count($GLOBALS['generations'])===7,'Parent-owned issue unexpectedly retried or regenerated a full component');
expect(!str_contains(wp_json_encode($GLOBALS['generations'][5]['messages'][0]['content']),
    '"replacement"'),'QA model was prompted with full replacement rather than compact patch');
unset($GLOBALS['parent_issue_test']);
$GLOBALS['native_fixture']=$fixtureBeforeParent;
// The scheduler must not terminate QA merely because a component failed three times.
// Repeated attempts are bounded by the configured cost admission, not a fixed count.
expect(str_contains(file_get_contents(dirname(__DIR__).'/plugin/includes/production-collaboration.php'),
    "for(\$round=1;count(\$issues)>0;\$round++)"),'Budget-driven QA loop missing');
// New helper-level assertions for protected sibling edits and review propagation.
$planForSurgery=syndamorph_collab_ids(json_decode($GLOBALS['native_fixture'],true));
$cross=syndamorph_surgery_apply($planForSurgery,
    array('id'=>$planForSurgery['blocks'][0]['children'][0]['id'],'changes'=>array(
        array('target'=>$planForSurgery['blocks'][0]['children'][1]['id'],
              'path'=>'style.color','value'=>'#123456'))));
expect(is_wp_error($cross),'Surgery escaped its assigned subtree into a sibling');
echo "PASS: GEN-013 ancestor issue reconciliation, descendant surgery and approved persistent QA revision\\n";
echo "PASS: delegated component repair, scoped vision review, best artifact and configured budget cutoff\\n";

$memory=new SyndaMorph_Job_Memory('fixture');
$a=$withIds;$b=$withIds;
$a['blocks'][0]['children'][0]['style']['margin']='0px';
$b['blocks'][0]['children'][0]['style']['margin']='0rem';
$memory->remember($a);
expect($memory->seen($b),'Equivalent zero lengths not deduplicated');
for($i=0;$i<3;$i++)$memory->record('heading',array('accepted'=>false,'detail'=>'duplicate'));
expect($memory->eligible('heading')&&$memory->eligible('other'),'Budget-driven QA incorrectly excluded a previously failed component');
expect(count($memory->feedback('heading'))===3,'Rejected repair feedback lost');
$sample=array('width'=>390,'snapshot'=>array('overflow'=>false,'elements'=>array(array('text'=>'Heading','rect'=>array(0,0,100,20),'styles'=>array('fontSize'=>'20px','display'=>'block')),array('text'=>'Other','rect'=>array(0,20,100,20)))),'screenshot'=>'secret-image');
$compact=syndamorph_memory_measurements(array($sample),array('Heading'));
expect(count($compact[0]['elements'])===1&&!isset($compact[0]['screenshot']),'Scoped context includes irrelevant elements or image bytes');
expect(abs(syndamorph_collab_cost(array('inputTokens'=>100,'cacheReadTokens'=>100,'cacheWriteTokens'=>100,'outputTokens'=>100),1,2)-0.000435)<0.000000001,'Cache-aware cost arithmetic failed');
echo "PASS: shared memory, cache prefix, scoped evidence and screenshot crops\n";

expect(syndamorph_agent_scope('developer','repair')&&!syndamorph_agent_scope('designer','repair')&&!syndamorph_agent_scope('boss','delegation'),'Agent scope violation');
expect(syndamorph_issue_score(array(array('severity'=>'high'))) > syndamorph_issue_score(array_fill(0,5,array('severity'=>'low'))),'Severity weighting failed');
expect(count(syndamorph_issue_merge(array(array('id'=>'same','component'=>'heading','detail'=>'defect'),array('id'=>'same','component'=>'heading','detail'=>'duplicate'),array('id'=>'pass','component'=>'heading','detail'=>'pass','status'=>'resolved'))))===1,'Duplicate or resolved issue counted');
$richPlan['blocks'][0]['children'][0]['style']['fontSize']='clamp(2rem, 1.2rem + 3.5vw, 4rem)';
$richPlan['blocks'][0]['children'][0]['style']['letterSpacing']='-0.01em';
expect(!is_wp_error(syndamorph_compile_block_plan(wp_json_encode($richPlan))),'GEN8 fluid typography rejected');
$memory->record('delta',array('replacement'=>$withIds['blocks'][0],'remainingIssues'=>array(array('id'=>'example'))));
expect(!isset($memory->feedback('delta')[0]['replacement'])&&$memory->feedback('delta')[0]['remainingIssueIds']===array('example'),'Repair memory leaks full historical node');
echo "PASS: scope, issue registry, GEN8 compiler and compact memory regressions\n";

$layoutPlan=$richPlan;
$layoutPlan['blocks'][0]['style']=array('minHeight'=>'clamp(28rem,70vh,40rem)','justifyContent'=>'left','verticalAlignment'=>'center','gradient'=>'linear-gradient(180deg, #18283a, #0f1a27)');
$layoutPlan['blocks'][0]['children'][2]['style']=array('justifyContent'=>'left');
$layoutResult=syndamorph_compile_block_plan("```json\n".wp_json_encode($layoutPlan)."\n```");
expect(!is_wp_error($layoutResult),'GEN9 layout and viewport height compilation failed');
expect(str_contains($layoutResult['content'],'"verticalAlignment":"center"')&&str_contains($layoutResult['content'],'70vh')&&str_contains($layoutResult['content'],'linear-gradient(180deg,#18283a,#0f1a27)'),'Native layout serialization or gradient normalization failed');
expect(str_contains($layoutResult['preview'],'align-items:flex-start')&&str_contains($layoutResult['preview'],'justify-content:center'),'Preview layout alignment absent');
$layoutPlan['blocks'][0]['style']['contentWidth']='36rem';
expect(is_wp_error(syndamorph_compile_block_plan(wp_json_encode($layoutPlan))),'Conflicting layout types accepted');
$memory->reopen(array('heading'));
expect($memory->eligible('heading'),'Stalled descendant not reopened after ancestor change');
echo "PASS: GEN9 native layout, viewport height, normalization and visual context regressions\n";

require_once dirname(__DIR__).'/plugin/includes/production-editor-learning.php';
function get_bloginfo($key){return 'test-wordpress';}
syndamorph_editor_learning_observe(10,str_repeat('a',64),'serialization','core/group');
syndamorph_editor_learning_observe(10,str_repeat('a',64),'serialization','core/group');
$learning=array_values(get_option('syndamorph_editor_learning_v1'))[0];
expect($learning['occurrences']===1&&$learning['verifiedCorrections']===0,'Editor learning did not deduplicate or falsely verified a failure');
syndamorph_editor_learning_confirm(10,str_repeat('a',64),str_repeat('b',64));
syndamorph_editor_learning_confirm(10,str_repeat('a',64),str_repeat('b',64));
$learning=array_values(get_option('syndamorph_editor_learning_v1'))[0];
expect($learning['verifiedCorrections']===1&&$learning['category']==='engineering','Verified engineering correction not tracked exactly once');
$sharedEngineering=syndamorph_learning_snapshot();
$engineeringRows=array_values(array_filter($sharedEngineering['lessons'],static fn($lesson)=>($lesson['category']??'')==='engineering'));
expect(count($engineeringRows)===1&&$engineeringRows[0]['status']==='verified'&&$engineeringRows[0]['verifiedCount']===1,'Confirmed editor recovery not promoted to unified verified engineering memory');
expect(syndamorph_learning_retrieve('developer')===array(),'Compiler engineering lesson leaked into agent prompt');
expect(!str_contains($styled['content'],'style="gap:')&&str_contains($styled['preview'],'gap:1rem'),'Native/preview gap separation failed');
echo "PASS: persistent editor engineering observations and preview-only layout CSS\n";

$ctaPlan=$richPlan;
$ctaPlan['blocks'][0]['children'][2]['children'][0]['style']['fontSize']='1rem';
$ctaArtifact=syndamorph_compile_block_plan(wp_json_encode($ctaPlan));
expect(!is_wp_error($ctaArtifact)&&str_contains($ctaArtifact['content'],'has-custom-font-size wp-element-button'),'GEN9 custom button typography support class missing');

require_once dirname(__DIR__).'/plugin/includes/production-learning.php';
$GLOBALS['options'][SYNDAMORPH_LEARNING_OPTION]=array();
expect(syndamorph_learning_pattern('blocks[0].children[0].contentWidth')[0]==='contentWidth','Schema path normalization failed');
syndamorph_learning_schema('blocks[0].children[0].contentWidth','Unsupported node field.','test:rejected',false);
expect(syndamorph_learning_retrieve('developer')===array(),'Unverified schema error leaked into developer context');
syndamorph_learning_schema('blocks[0].children[0].contentWidth','Unsupported node field.','test:rejected',true);
syndamorph_learning_schema('blocks[0].children[0].contentWidth','Unsupported node field.','test:rejected',true);
$rules=syndamorph_learning_retrieve('developer');
expect(count($rules)===1&&str_contains($rules[0],'contentWidth'),'Verified schema lesson not retrieved');
$snapshot=syndamorph_learning_snapshot();
expect($snapshot['counts']['verified']===1&&$snapshot['lessons'][0]['occurrences']===1,'Repeated observations not deduplicated');
$lessonId=$snapshot['lessons'][0]['id'];
expect(syndamorph_learning_manage($lessonId,'disable')&&syndamorph_learning_retrieve('developer')===array(),'Disabled learning remains active');
expect(syndamorph_learning_manage($lessonId,'enable')&&count(syndamorph_learning_retrieve('developer'))===1,'Enabling verified learning failed');
expect(syndamorph_learning_retrieve('repair','button')===array(),'Schema lesson leaked into unrelated task');
$before=array('type'=>'button','style'=>array('fontSize'=>'1rem'),'text'=>'Explore','href'=>'#rooms');
$after=$before;$after['style']['fontSize']='1.25rem';
syndamorph_learning_repair($before,$after,10,4,'test:repair');
expect(count(syndamorph_learning_retrieve('repair','button'))===1,'QA-accepted scoped repair did not produce lesson');
syndamorph_learning_repair($before,$after,10,10,'test:rejected');
expect(count(syndamorph_learning_retrieve('repair','button'))===1,'Rejected repair changed learning');
$historyEvents=array(
    array('type'=>'schema_repair','data'=>array('attempt'=>1,'status'=>'required','field'=>'blocks[0].align','message'=>'Alignment belongs inside style.align.')),
    array('type'=>'schema_repair','data'=>array('attempt'=>1,'status'=>'resolved','field'=>'blocks[0].align'))
);
expect(syndamorph_learning_bootstrap($historyEvents,'GEN0009')===1,'Verified history bootstrap failed');
expect(syndamorph_learning_bootstrap($historyEvents,'GEN0009')===1,'Idempotent bootstrap should recognize same evidence');
$rules=syndamorph_learning_retrieve('developer');
expect(count($rules)===2,'Historical verified lessons were not indexed');
syndamorph_learning_capability(array('Reference contains elements outside current native block schema.'),'test:capability');
$snapshot=syndamorph_learning_snapshot();
expect($snapshot['counts']['capability']===1&&$snapshot['counts']['observed']>=1,'Capability gap classification missing');
expect(!in_array('Images, lists, tables and vector elements need an explicit compiler capability before native export.',syndamorph_learning_retrieve('developer'),true),'Capability observation injected into agent prompt');
$staleRule=syndamorph_learning_snapshot()['lessons'][0];
$staleId=$staleRule['id'];
$staleRows=syndamorph_learning_rows();
$staleRows[$staleId]['compilerRevision']='obsolete-compiler-hash';
update_option(SYNDAMORPH_LEARNING_OPTION,$staleRows,false);
expect(syndamorph_learning_snapshot()['counts']['stale']>=1,'Compiler revision invalidation failed');
expect(!in_array($staleRule['rule'],syndamorph_learning_retrieve('developer'),true),'Stale lesson injected into prompt');
syndamorph_learning_schema('blocks[0].children[0].contentWidth','Unsupported node field.','test:revalidate',true);
expect(in_array($staleRule['rule'],syndamorph_learning_retrieve('developer'),true),'Reverification did not restore stale lesson');
expect(syndamorph_learning_manage($lessonId,'delete'),'Learning deletion failed');
expect(count(syndamorph_learning_retrieve('developer'))===1,'Deleted rule remained in retrieval');
echo "PASS: persistent verified lessons, scope, deduplication, archive bootstrap, capability classification and management\n";

$widths=array(1440,768,375);
$fixtureEvidence=array_map(static fn($width)=>array('width'=>$width,'screenshot'=>inspection_fixture_jpeg($width),
    'snapshot'=>array('overflow'=>false,'elements'=>array()),'a11y'=>array()),$widths);
$valid=inspection_reply(array('evidence'=>$fixtureEvidence));
expect(syndamorph_inspection_validate($valid,$widths)===$fixtureEvidence,'Valid renderer evidence was rejected');
$checkInspection=static function($response,string $code,array $expected=array())use($widths) {
    $error=syndamorph_inspection_validate($response,$widths);
    expect(is_wp_error($error)&&$error->get_error_code()==='sm_inspect_'.$code,'Wrong inspection classification for '.$code);
    $diagnostic=syndamorph_inspection_diagnostic($error);
    foreach($expected as $key=>$value)expect(($diagnostic[$key]??null)===$value,'Inspection detail missing: '.$key);
    expect(!str_contains(wp_json_encode($diagnostic),'DO-NOT-LOG'),'Inspection diagnostic leaked response content');
};
$truncated=$valid;
$truncated['body']=substr($truncated['body'],0,-7);
$checkInspection($truncated,'truncated',array('httpStatus'=>200,'responseBytes'=>strlen($truncated['body'])));
$broken=$valid;
$broken['body']='{"evidence":"DO-NOT-LOG"';
$broken['headers']['content-length']=strlen($broken['body']);
$checkInspection($broken,'invalid_json');
$oversized=$valid;
$oversized['headers']['content-length']=SYNDAMORPH_INSPECTION_RESPONSE_BYTES+1;
$checkInspection($oversized,'response_limit');
$checkInspection(inspection_reply(array('evidence'=>array_slice($fixtureEvidence,0,2))),'missing_evidence');
$checkInspection(inspection_reply(array('evidence'=>array_reverse($fixtureEvidence))),'viewport_mismatch',array('returnedWidths'=>array(375,768,1440)));
$duplicate=$fixtureEvidence;$duplicate[2]['width']=768;
$checkInspection(inspection_reply(array('evidence'=>$duplicate)),'viewport_mismatch');
$typed=$fixtureEvidence;$typed[2]['width']='375';
$checkInspection(inspection_reply(array('evidence'=>$typed)),'viewport_mismatch',array('returnedWidths'=>array(1440,768,'375')));
$partial=$fixtureEvidence;unset($partial[0]['a11y']);
$checkInspection(inspection_reply(array('evidence'=>$partial)),'a11y_incomplete');
$partial=$fixtureEvidence;unset($partial[1]['snapshot']);
$checkInspection(inspection_reply(array('evidence'=>$partial)),'snapshot_incomplete');
$partial=$fixtureEvidence;$partial[2]['screenshot']=inspection_fixture_jpeg(390);
$checkInspection(inspection_reply(array('evidence'=>$partial)),'screenshot_incomplete');
$checkInspection(inspection_reply(array('error'=>'browser_timeout'),504),'browser_timeout');
$checkInspection(inspection_reply(array('error'=>'worker_crash'),503),'browser_crash');
$checkInspection(inspection_reply(array('error'=>'DO-NOT-LOG'),429),'busy');
$checkInspection(new WP_Error('http_request_failed','cURL error 18: partial file DO-NOT-LOG'),'truncated');
$checkInspection(new WP_Error('http_request_failed','cURL error 28: timed out DO-NOT-LOG'),'transport_timeout');
$checkInspection(new WP_Error('http_request_failed','DO-NOT-LOG'),'unavailable');

$GLOBALS['generations']=array();
$GLOBALS['inspection_health']=array('status'=>404,'body'=>'{"error":"Not found."}');
$stale=syndamorph_collab_run('Stale renderer regression fixture.',false,null);
expect(is_wp_error($stale)&&$stale->get_error_code()==='sm_inspect_protocol_mismatch'&&count($GLOBALS['generations'])===0,
    'Stale renderer did not fail readiness before model generation');
unset($GLOBALS['inspection_health']);
$GLOBALS['inspection_calls']=array();
$GLOBALS['inspection_sent_hashes']=array();
$GLOBALS['inspection_failures']=array(inspection_reply(array('error'=>'renderer_busy'),429),
    inspection_reply(array('error'=>'browser_crash'),503));
$recovered=syndamorph_collab_inspect('<section>Saved reference</section>',$widths);
expect(!is_wp_error($recovered)&&count($GLOBALS['inspection_calls'])===3&&count(array_unique($GLOBALS['inspection_sent_hashes']))===1,
    'Transient renderer failures did not retry the same reference within the bound');
$GLOBALS['inspection_failures']=array_fill(0,4,inspection_reply(array('error'=>'worker_crash'),503));
$exhausted=syndamorph_collab_inspect('<section>Saved reference</section>',$widths);
expect(is_wp_error($exhausted)&&$exhausted->get_error_data()['attempts']===3&&count($GLOBALS['inspection_failures'])===1,
    'Renderer retries exceeded the configured bound');
$GLOBALS['inspection_failures']=array();
$GLOBALS['inspection_sent_widths']=array();
$GLOBALS['inspection_failures']=array(inspection_reply(array('evidence'=>array_reverse($fixtureEvidence))));
$ordered=syndamorph_collab_inspect('<section>Reorder fixture</section>',$widths);
expect(!is_wp_error($ordered)&&array_column($ordered,'width')===$widths&&$GLOBALS['inspection_sent_widths']===array($widths),
    'Complete evidence was not reordered without another request');
$GLOBALS['inspection_sent_widths']=array();
$GLOBALS['inspection_failures']=array(inspection_reply(array('evidence'=>array($fixtureEvidence[1],$fixtureEvidence[0]))));
$filled=syndamorph_collab_inspect('<section>Partial fixture</section>',$widths);
expect(!is_wp_error($filled)&&array_column($filled,'width')===$widths&&
    $GLOBALS['inspection_sent_widths']===array($widths,array(375)),
    'Renderer did not recover only the missing required width');
$GLOBALS['inspection_sent_widths']=array();
$badWidth=syndamorph_collab_inspect('<section>Unsupported fixture</section>',array(1440,3000));
expect(is_wp_error($badWidth)&&$badWidth->get_error_code()==='sm_inspect_request_invalid'&&
    $GLOBALS['inspection_sent_widths']===array(),'Unsupported width was changed or sent to Chromium');
$GLOBALS['inspection_failures']=array(inspection_reply(array('evidence'=>array_slice($fixtureEvidence,0,2))),
    inspection_reply(array('evidence'=>array())),inspection_reply(array('evidence'=>array())));
$pending=syndamorph_collab_inspect('<section>Required width still missing</section>',$widths);
expect(is_wp_error($pending)&&$pending->get_error_code()==='sm_inspect_missing_evidence'&&
    $pending->get_error_data()['retainedWidths']===array(1440,768)&&$pending->get_error_data()['missingWidths']===array(375)&&
    $GLOBALS['inspection_sent_widths']===array($widths,array(375),array(375)),
    'Unrecoverable viewport evidence did not preserve useful diagnostic information');
$saved=syndamorph_inspection_checkpoint($exhausted,'<section>Saved reference</section>',$widths);
$wire=syndamorph_team_failure_payload($saved);
expect(isset($wire['referenceCheckpoint'])&&!str_contains(wp_json_encode($wire),'Saved reference'),'Checkpoint exposed private HTML');
$receipt=syndamorph_inspection_recheck($wire['referenceCheckpoint']);
expect(!is_wp_error($receipt)&&$receipt['widths']===$widths&&$receipt['aiCalls']===0&&count($GLOBALS['generations'])===0,
    'Saved reference could not be inspected without a new generation');
$GLOBALS['revision_test']=true;
$GLOBALS['options']['syndamorph_ai_model_settings']['maxJobUsd']=1.0;
$GLOBALS['inspection_escalation_test']='approved';
$GLOBALS['inspection_failures']=array(inspection_reply(array('evidence'=>$duplicate)));
$designPending=syndamorph_collab_run('Archive the approved-for-inspection reference.',false,static function(){});
expect($designPending['status']==='uncompleted'&&$designPending['stageStatus']['browserQa']==='pending'&&
    !$designPending['quality']['bossApproved']&&$designPending['quality']['escalation']['decision']==='await_renderer'&&
    $designPending['quality']['inspection']['stage']==='design_inspection'&&count($GLOBALS['generations'])===3&&
    $designPending['usage']['inputTokens']===3600&&isset($designPending['referenceCheckpoint']),
    'Pending Designer inspection lost the artifact, hid usage, or accepted the Boss approval claim');
$bossInput=json_decode($GLOBALS['generations'][2]['messages'][0]['content'][0]['text'],true);
expect($bossInput['inspection']['returnedBatches']===array(array(1440,768,768))&&
    $bossInput['inspection']['expectedWidths']===$widths,'Boss did not receive the exact viewport diagnostics');
$designReceipt=syndamorph_inspection_recheck($designPending['referenceCheckpoint']);
expect(!is_wp_error($designReceipt)&&$designReceipt['aiCalls']===0&&count($GLOBALS['generations'])===3,
    'Rechecking the failed Designer artifact called a paid agent');
$GLOBALS['generations']=array();
$GLOBALS['inspection_escalation_test']='continue_independent';
$GLOBALS['inspection_failures']=array($valid,inspection_reply(array('evidence'=>$duplicate)));
$nativePending=syndamorph_collab_run('Keep the compiled native draft if inspection is pending.',false,null);
expect($nativePending['stageStatus']['design']==='approved'&&$nativePending['stageStatus']['compilation']==='passed'&&
    $nativePending['stageStatus']['browserQa']==='pending'&&$nativePending['checks']['valid_plan']===true&&
    isset($nativePending['artifact']['contentSha256'],$nativePending['surgery']['workingPlanId'],$nativePending['referenceCheckpoint'])&&
    $nativePending['quality']['escalation']['decision']==='continue_independent'&&count($GLOBALS['generations'])===5,
    'Pending native inspection did not retain independent valid output and usage');
unset($GLOBALS['inspection_escalation_test']);
unset($GLOBALS['revision_test']);
echo "PASS: inspection diagnostics, stale runtime gate, bounded retry and saved reference recovery\n";
