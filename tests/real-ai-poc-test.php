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
function get_current_user_id() { return $GLOBALS['source_fixture_user']??11; }
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
        'content-length'=>strlen($body),'x-syndamorph-inspection-protocol'=>'2',
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
        return inspection_reply(array('service'=>'syndamorph-renderer','inspectionProtocol'=>2,'build'=>str_repeat('a',64),
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
    if($url==='http://backend:3100/production-touch'){
        $input=json_decode($args['body'],true);
        $GLOBALS['touch_inputs'][]=$input;
        $target=array('id'=>str_repeat('f',24),'tag'=>'button','name'=>'Open menu','permitsClick'=>true);
        return array('status'=>200,'body'=>wp_json_encode(array('version'=>1,'archiveId'=>$input['archiveId'],'status'=>'observed',
            'initial'=>array('width'=>$input['width'],'targets'=>array($target)),
            'scenarios'=>!$input['actions']?array():array(array('action'=>$input['actions'][0],'target'=>$target,
                'status'=>'observed','remoteEffects'=>'unverified','blockedRequests'=>array(),
                'before'=>array('sha256'=>str_repeat('a',64),'image'=>'private-before'),
                'after'=>array('sha256'=>str_repeat('b',64),'image'=>'private-after'))))));
    }
    if ($url === 'http://renderer:3000/crop') {
        $GLOBALS['crop_calls']=($GLOBALS['crop_calls']??0)+1;
        $items=json_decode($args['body'],true)['items'];
        return array('status'=>200,'body'=>json_encode(array('images'=>array_map(static fn($item)=>array('media_type'=>'image/jpeg','data'=>base64_encode('cropped-jpeg')),$items))));
    }
    if ($url === 'http://renderer:3000/compare') {
        $widths=array_column(json_decode($args['body'],true)['reference'],'width');
        $index=$GLOBALS['progressive_comparison']??0;
        $ratios=array(0.20,0.16,0.19,0.13);
        $ratio=isset($GLOBALS['progressive_test'])?($ratios[$index]??throw new RuntimeException('Duplicate was rendered')):0.15;
        if(isset($GLOBALS['source_fixture_ratio']))$ratio=$GLOBALS['source_fixture_ratio'];
        if(!empty($GLOBALS['source_fixture_comparisons']))$ratio=array_shift($GLOBALS['source_fixture_comparisons']);
        if(isset($GLOBALS['progressive_test']))$GLOBALS['progressive_comparison']=$index+1;
        return array('status'=>200,'body'=>json_encode(array('comparison'=>array_map(
            static fn($width)=>array('width'=>$width,'pixelDifferenceRatio'=>$ratio,'meanChannelDifference'=>5.0,'sampledPixels'=>100),$widths))));
    }
    if ($url === 'http://renderer:3000/inspect') {
        $input=json_decode($args['body'],true);
        $native=str_contains($input['html'],'<!-- wp:');
        $designProbe=in_array('syndamorph_design_responsive_measure',
            array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,6),'function'),true);
        if($native&&$designProbe&&($GLOBALS['native_responsive_test']??null)==='missing')
            $input['viewports']=array_values(array_filter($input['viewports'],static fn($width)=>$width!==900));
        if($designProbe)$GLOBALS['responsive_probe_widths'][]=$input['viewports'];
        else {
            $GLOBALS['inspection_calls'][]=$native?'native':'reference';
            $GLOBALS['inspection_sent_hashes'][]=hash('sha256',$input['html']);
            $GLOBALS['inspection_sent_widths'][]=$input['viewports'];
            if(!empty($GLOBALS['inspection_failures']))return array_shift($GLOBALS['inspection_failures']);
        }
        return inspection_reply(array('evidence'=>array_map(
            static fn($width)=>array('width'=>$width,'screenshot'=>inspection_fixture_jpeg($width),'a11y'=>array(),'snapshot'=>(isset($GLOBALS['source_fixture_copy'])?array('copy'=>$GLOBALS['source_fixture_copy']):array())+array(
                'overflow'=>$designProbe&&$width===900&&(str_contains($input['html'],'--test-overflow:1')||
                    ($native&&($GLOBALS['native_responsive_test']??null)==='overflow')),
                'elements'=>array(array('tag'=>'H2','id'=>'','text'=>'Welcome & enjoy',
                'rect'=>array(0,0,300,70),'wrapChangesGeometry'=>str_contains($input['html'],'text-wrap:balance'),
                'styles'=>array('textWrapStyle'=>'balance','fontSize'=>'32px','fontWeight'=>'700','color'=>'rgb(0, 0, 0)','letterSpacing'=>'normal'))))),
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
    if(!empty($GLOBALS['design_requirement_test'])){
        $expected=$GLOBALS['design_requirement_test']==='pass'?'Welcome & enjoy':'Unrendered required copy';
        $text=match($i){
            1=>wp_json_encode(array('version'=>1,'title'=>'Browser requirement fixture',
                'sections'=>array(array('id'=>'hero','label'=>'Hero','parent'=>null)),
                'requirements'=>array(array('id'=>'exact-pinned-headline','target'=>'hero','dimension'=>'content','basis'=>'brief',
                    'requirement'=>'Preserve the required copy','acceptance'=>'Copy is visible at the required viewports',
                    'check'=>array('kind'=>'text','value'=>$expected))))),
            2=>'<section class="hero"><h2>Welcome &amp; enjoy</h2></section>',
            3=>'{"decision":"approved","issues":[],"requirementChecks":[{"id":"exact-pinned-headline","status":"verified","evidence":["view-375"]}]}',
            4=>$GLOBALS['native_fixture'],
            5=>'{"decision":"approved","issues":[]}',
            default=>throw new RuntimeException('Unexpected design requirement call '.$i),
        };
    }elseif(!empty($GLOBALS['responsive_design_test'])){
        $rollback=$GLOBALS['responsive_design_test']==='rollback';
        $text=match($i){
            1=>'{"title":"Responsive fixture","sections":["Hero"],"constraints":[],"acceptance":["Preserve the visible heading"]}',
            2=>'<section class="hero"><style>.hero{--test-overflow:'.($rollback?'0':'1').';color:#111}</style><h2>Welcome &amp; enjoy</h2></section>',
            3=>$rollback?'{"decision":"changes_requested","issues":[{"target":"Hero","problem":"Try a different accent","requiredChange":"Change title accent"}]}':
                '{"decision":"approved","issues":[]}',
            4=>'{"edits":[{"find":"--test-overflow:0","replace":"--test-overflow:1"}]}',
            5=>'{"decision":"approved","issues":[]}',
            6=>$GLOBALS['native_fixture'],
            7=>'{"decision":"approved","issues":[]}',
            default=>throw new RuntimeException('Unexpected responsive fixture call '.$i),
        };
    }elseif(!empty($GLOBALS['team_touch_fixture'])){
        $text=match($i){
            1=>'{"action":"tool_request","toolRequest":{"name":"playwright.touch","target":"source","reason":"Discover the compact menu","width":375,"actions":[]}}',
            2=>wp_json_encode(array('action'=>'tool_request','toolRequest'=>array('name'=>'playwright.touch','target'=>'source',
                'reason'=>'Verify the compact menu opens','width'=>375,'actions'=>array(array('type'=>'click','target'=>str_repeat('f',24)))))),
            3=>'{"title":"Welcome with local navigation","sections":["Hero"],"constraints":[],"acceptance":["Keep headline"]}',
            4=>'<section class="hero"><h2>Welcome &amp; enjoy</h2></section>',
            5=>'{"decision":"approved","issues":[]}',
            6=>$GLOBALS['native_fixture'],
            7=>'{"decision":"approved","issues":[]}',
            default=>throw new RuntimeException('Unexpected Touch fixture call '.$i),
        };
    }elseif(isset($GLOBALS['progressive_test'])){
        $heading='blocks[0].children[0]';$cta='blocks[0].children[2].children[0]';
        $finding=array('id'=>'cta-typography','component'=>$cta,'severity'=>'medium','category'=>'typography',
            'detail'=>'Retained CTA needs a different line height.','repairStrategy'=>'style');
        $rejectedFinding=array_replace($finding,array('detail'=>'Instruction from a rejected candidate',
            'repairStrategy'=>'structure'));
        $review=static fn(array $issues):string=>wp_json_encode(array('decision'=>'changes_requested','issues'=>$issues));
        $patch=static fn($id,$key,$value,$diagnosis=''):string=>wp_json_encode(array('diagnosis'=>$diagnosis,
            'changes'=>array(array('target'=>$id,'path'=>'style.'.$key,'value'=>$value))));
        $repeat=$GLOBALS['progressive_test']==='duplicate';
        $text=match($i){
            1=>'{"title":"GEN-025 progression fixture","sections":["Hero"],"constraints":["Native blocks"],"acceptance":["Valid native output"]}',
            2=>'<section><h2>Welcome &amp; enjoy</h2></section>',
            3=>'{"decision":"approved","issues":[]}',
            4=>$GLOBALS['native_fixture'],
            5=>$review(array(array('id'=>'heading-typography','component'=>$heading,'severity'=>'high','detail'=>'Heading refinement needed'),$finding)),
            6=>$patch($heading,'fontSize','2.5rem'),
            7=>$review(array($finding)),
            8=>$patch($cta,'padding.top','0.75rem','First CTA operation'),
            9=>$review(array($rejectedFinding)),
            10=>$repeat?$patch($cta,'padding.top','0.75rem','Different wording, same operation'):$patch($cta,'lineHeight',1.3),
            11=>$repeat?'{"decision":"engineering_blocked","summary":"Repeated proposal","nextAction":"Review a different concrete operation"}':
                '{"decision":"approved","issues":[]}',
            default=>throw new RuntimeException('Unexpected progression fixture call '.$i),
        };
    }elseif(!empty($GLOBALS['design_feasibility_test'])){
        $text=match($i){
            1=>'{"title":"Design capability gate","sections":["Hero"],"constraints":["Native blocks"],"acceptance":["Editable native layout"]}',
            2=>'<section><style>section h2{text-wrap:balance}</style><h2>Welcome &amp; enjoy</h2></section>',
            3,6,8=>'{"decision":"approved","issues":[]}',
            4=>'{"decision":"changes_requested","issues":[{"target":"Heading","problem":"Measured balancing changes wrapping","requiredChange":"Use natural wrapping"}]}',
            5=>'{"edits":[{"find":"text-wrap:balance","replace":"text-wrap:wrap"}]}',
            7=>$GLOBALS['native_fixture'],
            default=>throw new RuntimeException('Unexpected native capability gate call '.$i),
        };
    }elseif(!empty($GLOBALS['scoped_receipt_test'])){
        $text=match($i){
            1=>'{"title":"Repair receipts","sections":["Hero"],"constraints":["Native blocks"],"acceptance":["Valid native output"]}',
            2=>'<section><h2>Welcome &amp; enjoy</h2></section>',
            3,9=>'{"decision":"approved","issues":[]}',
            4=>$GLOBALS['native_fixture'],
            5=>'{"decision":"changes_requested","issues":[{"id":"heading-type","component":"blocks[0].children[0]","severity":"medium","detail":"Use a larger heading font"}]}',
            6=>'{"id":"developer","changes":[{"target":"blocks[0].children[0]","path":"style.fontSize","value":"2.5rem"}]}',
            7=>'{"action":"tool_request","toolRequest":{"name":"playwright.inspect","target":"native","reason":"Verify which native heading was rendered"}}',
            8=>'{"changes":[{"target":"blocks[0].children[0]","path":"style.fontSize","value":"2.5rem"}]}',
            default=>throw new RuntimeException('Unexpected scoped receipt call '.$i),
        };
    }elseif(!empty($GLOBALS['capability_blocker_test'])){
        $text=match($i){
            1=>'{"title":"Native capability blocker","sections":["Hero"],"constraints":["Native blocks"],"acceptance":["Valid output"]}',
            2=>'<section><h2>Welcome &amp; enjoy</h2></section>',
            3=>'{"decision":"approved","issues":[],"summary":"Design accepted"}',
            4=>$GLOBALS['native_fixture'],
            5=>'{"decision":"changes_requested","issues":[{"id":"mobile-cta-stretch","component":"blocks[0].children[2]","category":"alignment","scope":"component","severity":"high","detail":"Reference mobile CTA stretches, native buttons lack width support"}]}',
            6=>'{"id":"blocks[0].children[2]","blocked":"capability","diagnosis":"Native buttons expose no responsive width or stretch property."}',
            7=>'{"decision":"design_review_required","summary":"Native CTA stretch cannot be reproduced with permitted styles.","nextAction":"Design owner must accept or revise the reference."}',
            default=>throw new RuntimeException('Developer retried a known native capability blocker, paid call '.$i),
        };
    }elseif(!empty($GLOBALS['authority_qa_conflict_test'])){
        $text=match($i){
            1=>'{"title":"QA authority conflict","sections":["Hero"],"constraints":["Native Gutenberg"],"acceptance":["Heading visible"]}',
            2=>'<section><h2>Welcome &amp; enjoy</h2></section>',
            3=>'{"decision":"approved","issues":[],"summary":"Visual reference approved"}',
            4=>$GLOBALS['native_fixture'],
            5=>'{"decision":"approved","issues":[{"id":"qa-conflict","component":"blocks[0]","severity":"high","detail":"Unresolved defect"}],"summary":"Contradictory QA approval"}',
            default=>throw new RuntimeException('QA contradiction accidentally authorized an additional model call '.$i),
        };
    }elseif(!empty($GLOBALS['boss_approved_deviation_test'])){
        $text=match($i){
            1=>'{"title":"GEN-000018 approval regression","sections":["Hero"],"constraints":["Native Gutenberg"],"acceptance":["Heading and CTA visible"]}',
            2=>'<section class="ph-hero"><style>.ph-hero__inner{max-width:44rem}.ph-hero__eyebrow{text-transform:uppercase}</style><div class="ph-hero__inner"><p class="ph-hero__eyebrow">Hotel</p><h2>Welcome &amp; enjoy</h2></div></section>',
            3=>'{"decision":"approved","issues":[],"acceptedCapabilityDeviations":["Uppercase label accepted as a minor visual-only limitation; validate the native mapping during QA."],"summary":"Approved browser reference"}',
            4=>$GLOBALS['native_fixture'],
            5=>'{"decision":"approved","issues":[],"summary":"Native QA approved"}',
            default=>throw new RuntimeException('Approved Boss was overridden or Designer was called for needless surgery: '.$i),
        };
    }elseif(!empty($GLOBALS['tool_designer_test'])){
        $text=match($i){
            1=>'{"title":"Designer Tool Fixture","sections":["Hero"],"constraints":["Native Gutenberg"],"acceptance":["Correct heading weight"]}',
            2=>'<section><h2>Welcome &amp; enjoy</h2></section>',
            3=>'{"decision":"changes_requested","issues":[{"target":"heading","problem":"Screenshot appears thinner than computed weight","requiredChange":"Verify browser font weight"}]}',
            4=>'{"action":"verification_required","toolRequest":{"name":"playwright.inspect","target":"reference","reason":"Verify computed browser headline font weight before modifying CSS"}}',
            5=>'{"decision":"approved","issues":[],"summary":"Computed typography evidence resolves uncertainty"}',
            6=>$GLOBALS['native_fixture'],
            7=>'{"decision":"approved","issues":[],"summary":"Native QA passed"}',
            default=>throw new RuntimeException('Unexpected Designer tool request model call '.$i),
        };
    }elseif(!empty($GLOBALS['tool_generic_test'])){
        $text=match($i){
            1=>'{"title":"Global Developer Tools","sections":["Hero"],"constraints":["Native Gutenberg"],"acceptance":["Valid heading"]}',
            2=>'<section><h2>Welcome &amp; enjoy</h2></section>',
            3=>'{"decision":"approved","issues":[],"summary":"Approved reference"}',
            4=>'{"action":"tool_request","toolRequest":{"name":"playwright.inspect","target":"reference","reason":"Confirm reference heading browser typography before implementing native blocks"}}',
            5=>$GLOBALS['native_fixture'],
            6=>'{"decision":"approved","issues":[],"summary":"Native QA approved"}',
            default=>throw new RuntimeException('Unexpected generic tool call '.$i),
        };
    }elseif(!empty($GLOBALS['tool_verification_test'])){
        $text=match($i){
            1=>'{"title":"Global tools fixture","sections":["Hero"],"constraints":["Native Gutenberg"],"acceptance":["Heading visible"]}',
            2=>'<section><h2>Welcome &amp; enjoy</h2></section>',
            3=>'{"decision":"verification_required","toolRequest":{"name":"playwright.inspect","target":"reference","reason":"Check actual browser-computed headline font weight"},"summary":"Verify typography"}',
            4=>'{"decision":"approved","issues":[],"summary":"Computed styles verified"}',
            5=>$GLOBALS['native_fixture'],
            6=>'{"decision":"approved","issues":[],"summary":"Native reference verified"}',
            default=>throw new RuntimeException('Unexpected verification model call '.$i),
        };
    }elseif(!empty($GLOBALS['tool_dispute_test'])){
        $text=match($i){
            1=>'{"title":"Design dispute fixture","sections":["Hero"],"constraints":["Native Gutenberg"],"acceptance":["Heading visible"]}',
            2=>'<section><h2>Welcome &amp; enjoy</h2></section>',
            3=>'{"decision":"changes_requested","issues":[{"target":"heading","problem":"Weight looks wrong despite computed weight 700","requiredChange":"Confirm browser font weight"}]}',
            4=>'{"action":"no_change","reason":"Computed browser styles support declared weight; no source edit is justified"}',
            default=>throw new RuntimeException('Unexpected disputed regeneration model call '.$i),
        };
    }elseif(!empty($GLOBALS['schema_stall_test']) || !empty($GLOBALS['schema_ack_test'])) {
        $repairContext=json_decode($req['messages'][0]['content'][0]['text']??'{}',true);
        $id=$repairContext['instructionId']??'';
        $field=$repairContext['diagnostic']['field']??'';
        if(($GLOBALS['schema_ack_test']??'')==='valid' && $i===6){
            $text='{"decision":"approved","issues":[],"summary":"Schema-guided correction passed"}';
        } elseif($i>=5) {
            $ack=array('instructionId'=>$id,'field'=>$field);
            if(($GLOBALS['schema_ack_test']??'')==='missing')$ack=null;
            if(($GLOBALS['schema_ack_test']??'')==='mismatch')$ack['instructionId']='invalid-diagnostic-id';
            $edit=array('path'=>'style.fontSize','value'=>!empty($GLOBALS['schema_stall_test'])?'1.1234567rem':'2rem');
            if(!empty($GLOBALS['schema_stall_test']) && $i===6)$edit+=array('op'=>'set','target'=>'blocks[0].children[0]');
            $text=wp_json_encode(array('acknowledged'=>$ack,'patch'=>array(
                'id'=>'blocks[0].children[0]','changes'=>array($edit))));
        } else $text=match($i) {
            1 => '{"title":"Compiler-guided repair fixture","sections":["Hero"],"constraints":["Native Gutenberg"],"acceptance":["Valid editable blocks"]}',
            2 => '<section><h2>Welcome &amp; enjoy</h2></section>',
            3 => '{"decision":"approved","issues":[]}',
            4 => $GLOBALS['native_fixture'],
            default => throw new RuntimeException('Unexpected compiler-guided model call '.$i),
        };
        if($i>6 && !empty($GLOBALS['schema_stall_test']))
            throw new RuntimeException('Same-error schema guard consumed an extra paid AI call '.$i);
    } elseif (!empty($GLOBALS['surgery_test'])) {
        $text=match($i) {
            1 => '{"title":"Surgical recovery fixture","sections":["Hero"],"constraints":["Native Gutenberg"],"acceptance":["Valid editable blocks"]}',
            2 => '<section><h2>Welcome &amp; enjoy</h2></section>',
            3 => '{"decision":"approved","issues":[]}',
            4 => $GLOBALS['native_fixture'],
            5 => '{"decision":"approved","issues":[],"summary":"No measured defects"}',
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
        $strategy=$GLOBALS['adaptive_specific_strategy']??'';
        $specified=$strategy==='hybrid';
        if($strategy==='unsupported' && str_contains($req['system'][1]['text']??'','Task: scope_resolution.'))
            return array('status'=>200,'body'=>json_encode(array(
                'model'=>'claude-dynamic-test-1','stop_reason'=>'end_turn',
                'content'=>array(array('type'=>'text','text'=>'{"decision":"engineering_blocked","summary":"Requires clarification","nextAction":"Use a supported native operation"}')),
                'usage'=>array('input_tokens'=>1200,'output_tokens'=>150),
            )));
        $bossLayout=array('id'=>'layout-spacing','component'=>'blocks[0]','category'=>'spacing','scope'=>'section',
            'severity'=>'high','detail'=>'Keep the approved copy and move the text leaves into one native group.');
        if($specified)$bossLayout['repairStrategy']='hybrid';
        if($strategy==='unsupported')$bossLayout['repairStrategy']='custom_css';
        $startedWithStructure=$i===7 && ($specified || str_contains($GLOBALS['generations'][5]['messages'][0]['content'][0]['text'],'"strategy":"structure"'));
        $text=match($i) {
            1 => '{"title":"Adaptive fixture","sections":["Hero"],"constraints":["Native layout"],"acceptance":["Suitable layout"]}',
            2 => '<section><h2>Welcome &amp; enjoy</h2></section>',
            3 => '{"decision":"approved","issues":[]}',
            4 => $GLOBALS['native_fixture'],
            5,7 => $startedWithStructure?'{"decision":"approved","issues":[]}':
                wp_json_encode(array('decision'=>'changes_requested','issues'=>array($bossLayout))),
            6 => $learnedStructure||$specified?$structure:'{"id":"blocks[0]","diagnosis":"Try explicit style first","changes":[{"path":"style.gap","value":"2rem"}]}',
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
    if($i===1 && isset($GLOBALS['team_specification_fixture']))$text=wp_json_encode($GLOBALS['team_specification_fixture']);
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
expect(syndamorph_collab_output_cap()===128000,'Native AI team uses provider output ceiling, not fixed per-role/task limits');
expect(array_keys($result['outputs'])===array('boss','designer','developer','validator'),'Role execution order wrong');
expect(count($GLOBALS['generations'])===4,'Expected four paid calls');
expect($result['usage']['inputTokens']===4800 && $result['usage']['outputTokens']===600,'Token usage not aggregated');
expect($result['status']==='generated' && $result['checks']['contains_heading'],'Independent validator failed');
expect($result['published']===false && $result['renderedInBrowser']===false,'POC falsely claimed publishing/rendering');
expect(is_float($result['estimatedCostUsd']),'Estimated spend missing');
expect(count($result['agentMetrics'])===4 && $result['agentMetrics'][1]['role']==='designer' &&
    $result['agentMetrics'][1]['outputTokens']===150 &&
    $result['agentMetrics'][1]['outputCap']>16384,
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
$markup='<section class="offers"><h2>Offers</h2><p>Keep this copy.</p></section>';
$prefix='.offers h2{font-size:2rem}';
$raw=str_repeat(chr(96),3)."html\n".$markup."\n<style>\n".$prefix."\n.off";
$cssCheckpoint=syndamorph_design_css_checkpoint($raw);
$suffix='@media(max-width:600px){.offers h2{font-size:1.5rem}}';
$finished=syndamorph_design_css_finish($cssCheckpoint,wp_json_encode(array('suffixCss'=>$suffix)));
expect(($cssCheckpoint['rawSha256']??null)===hash('sha256',$raw)&&$cssCheckpoint['html']===$markup."\n"&&
    $cssCheckpoint['css']===$prefix&&$cssCheckpoint['discardedSelectorTail']==='.off'&&
    $finished===$markup."\n<style>\n".$prefix."\n".$suffix."\n</style>",
    'Designer CSS recovery did not preserve the exact markup and fully received CSS rules.');
expect(syndamorph_design_css_checkpoint(str_replace('</section>','',$raw))===null&&
    syndamorph_design_css_checkpoint($raw.'ers{color:red')===null&&
    is_wp_error(syndamorph_design_css_finish($cssCheckpoint,'{"suffixCss":"</style><script></script>"}'))&&
    is_wp_error(syndamorph_design_css_finish($cssCheckpoint,wp_json_encode(array('suffixCss'=>'.offers{color:red')))),
    'Designer CSS recovery accepted incomplete markup, a cut CSS declaration, or unsafe/unfinished continuation.');
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
expect(str_contains($client, 'JSON.stringify({brief:prompt,outputType:activeFormat,sourceCapture})') &&
    !str_contains($client, 'validateSource(') &&
    str_contains($client, 'response.body.getReader()') &&
    str_contains($client, 'agent_delta') &&
    str_contains($ui, 'syndamorph/v1/ai/team-stream'),
    'Real AI must use one incremental SSE route with an optional server-side capture handle');
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
expect(!str_contains($ui,'<div class="sm-stats" aria-label="Production overview">')
    && str_contains($ui,'class="sm-production-control"')
    && str_contains($ui,'id="syndamorph-team-live-cost"')
    && str_contains($ui,'id="syndamorph-team-pause"')
    && str_contains($ui,'id="syndamorph-team-resume"')
    && str_contains($ui,'id="syndamorph-team-stop"')
    && str_contains($ui,'id="syndamorph-team-abort"'),
    'Production controls must replace summary cards inside the existing feed');
expect(str_contains($client,"/history/'+encodeURIComponent(currentIdentity.id)+'/resume")
    && str_contains($client,"/history/'+encodeURIComponent(currentIdentity.id)+'/control"),
    'Client must use authenticated production-control and continuation endpoints');

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
$GLOBALS['team_specification_fixture']=array('version'=>1,'title'=>'Premium hotel hero',
    'sections'=>array(array('id'=>'hero','label'=>'Hero','parent'=>null),
        array('id'=>'heading','label'=>'Hero heading','parent'=>'hero')),
    'requirements'=>array(array('id'=>'editable-heading','target'=>'heading','dimension'=>'implementation',
        'basis'=>'brief','requirement'=>'An editable native heading','acceptance'=>'Heading block survives editor round-trip',
        'status'=>'verified')));
$native=syndamorph_team_real_run('Create a native premium hotel hero.',false,null,'gutenberg');
unset($GLOBALS['team_specification_fixture']);
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
$assignment=$native['handoffs'][0];
$specification=$assignment['requirements']['specification'];
$specificationReceipt=$assignment['specificationReceipt'];
expect($specification['format']==='structured' && $specification['sections'][1]['parent']==='hero' &&
    $specification['requirements'][0]['id']==='editable-heading' && !isset($specification['requirements'][0]['status']),
    'Planning must preserve hierarchy and requirement IDs without self-certifying verification');
expect($specificationReceipt['specificationSha256']===hash('sha256',wp_json_encode($specification)) &&
    $specificationReceipt['sourceEvidenceSha256']===null && $specificationReceipt['viewportWidths']===array(1440,768,375) &&
    $native['designApproval']['specificationReceipt']===$specificationReceipt,
    'Application-owned specification receipt must bind the brief and exact viewport widths');
$requestContext=static fn($index)=>json_decode($GLOBALS['generations'][$index]['messages'][0]['content'][0]['text'],true);
expect($requestContext(1)['requirements']['specification']===$specification &&
    $requestContext(2)['productionSpecification']===$specification &&
    $requestContext(3)['design']['productionSpecification']===$specification &&
    $requestContext(4)['design']['productionSpecification']===$specification,
    'Designer, Boss review, Developer and implementation QA did not receive the same specification');
$legacyspec=syndamorph_collab_requirements('{"title":"Archive","sections":["Hero"],"constraints":[],"acceptance":["Keep headline"]}');
expect($legacyspec['specification']['format']==='legacy' && $legacyspec['specification']['requirements'][0]['basis']===null,
    'Archived planning has no attributed source or acceptance verdict');
$broken=$specification;
$broken['sections'][1]['parent']='heading';
expect(is_wp_error(syndamorph_production_specification($broken)),'Cyclic specification hierarchy was accepted');
$broken=$specification;
$broken['requirements'][0]['target']='missing-section';
expect(is_wp_error(syndamorph_production_specification($broken)),'Requirement with an unknown target was accepted');
expect(is_wp_error(syndamorph_collab_requirements('{"title":"Archive","sections":[],"constraints":[],"acceptance":[1," "]}')),
    'Empty legacy acceptance criteria were accepted');
$finishFixture=$GLOBALS['native_fixture'];
$finishCropCalls=$GLOBALS['crop_calls']??0;
$GLOBALS['design_feasibility_test']=true;
$GLOBALS['generations']=array();$gateEvents=array();
$reviewed=syndamorph_collab_run('Create a section with an editable heading.',false,
    static function($type,$data)use(&$gateEvents):void {
        if($type==='execution_gate'&&$data['stage']==='design_approval')$gateEvents[]=$data;
    },false);
unset($GLOBALS['design_feasibility_test']);
expect(!is_wp_error($reviewed)&&$reviewed['status']==='needs_review'&&
    array_column($gateEvents,'agentDecision')===array('approved','changes_requested','approved')&&
    array_column($gateEvents,'executionStatus')===array('blocked','blocked','ready')&&
    str_contains($reviewed['referenceHtml'],'text-wrap:wrap'),
    'A measured non-native effect must return to design and clear before Developer starts');
$GLOBALS['scoped_receipt_test']=true;
$GLOBALS['generations']=array();$probeReceipts=array();
$repaired=syndamorph_collab_run('Repair the scoped heading.',false,
    static function($type,$data)use(&$probeReceipts):void {
        if($type==='tool_invocation')$probeReceipts[]=$data;
    },false);
unset($GLOBALS['scoped_receipt_test']);
expect(!is_wp_error($repaired)&&$repaired['status']==='needs_review'&&
    count($repaired['quality']['revisions'])===2&&
    $repaired['quality']['revisions'][0]['contractDiagnostic']['expected']==='blocks[0].children[0]'&&
    $repaired['quality']['revisions'][0]['contractDiagnostic']['received']==='developer'&&
    $repaired['quality']['revisions'][0]['contractDiagnostic']['editsNotEvaluated']&&
    $repaired['quality']['revisions'][1]['accepted']&&
    $probeReceipts[0]['target']==='native'&&$probeReceipts[0]['cache']==='hit'&&
    !str_contains($GLOBALS['generations'][7]['messages'][0]['content'][0]['text'],'<!-- wp:'),
    'Ownership errors must not discredit unapplied edits, and repair must receive only server-bound inspection evidence');
$GLOBALS['native_fixture']=$finishFixture;
$GLOBALS['crop_calls']=$finishCropCalls;
$GLOBALS['generations']=array();$GLOBALS['inspection_calls']=array();

// GEN-000020: once Developer reports an unsupported native capability,
 // stop paid style/structure retries and escalate exactly once to Boss.
$GLOBALS['capability_blocker_test']=true;
$priorBlockerGenerations=$GLOBALS['generations'];
$priorBlockerInspections=$GLOBALS['inspection_calls'];
$GLOBALS['generations']=array();$GLOBALS['inspection_calls']=array();
$blockedEvents=array();
$blockedJob=syndamorph_team_real_run('Verify unsupported mobile CTA capability escalation.',
    false,static function(string $type,array $data)use(&$blockedEvents):void{
        if(in_array($type,array('repair_capability_blocked','repair_escalation'),true))
            $blockedEvents[]=array('type'=>$type,'data'=>$data);
    },'gutenberg');
expect(is_array($blockedJob)&&$blockedJob['status']==='uncompleted'&&
    count($GLOBALS['generations'])===7&&
    count($blockedJob['quality']['revisions'])===1&&
    ($blockedJob['quality']['revisions'][0]['validation']??'')==='capability'&&
    count(array_filter($blockedEvents,static fn($e)=>$e['type']==='repair_capability_blocked'))===1&&
    ($blockedJob['quality']['escalation']['decision']??'')==='design_review_required',
    'GEN-000020 must escalate a native capability blocker without repeat paid Developer surgery');
unset($GLOBALS['capability_blocker_test']);
$GLOBALS['generations']=$priorBlockerGenerations;
$GLOBALS['inspection_calls']=$priorBlockerInspections;

$beforeReplayGenerations=$GLOBALS['generations'];
$beforeReplayInspections=$GLOBALS['inspection_calls'];
// Live pipeline regression for GEN-000019: automatic compiler surgery must
// precede AI repair, and confirmed negative constraints must affect later jobs.
$beforeAutoFixture=$GLOBALS['native_fixture'];
$beforeAutoLearning=$GLOBALS['options'][SYNDAMORPH_LEARNING_OPTION]??array();
$GLOBALS['options'][SYNDAMORPH_LEARNING_OPTION]=array();
$autoFixture=json_decode($beforeAutoFixture,true);
$autoFixture['blocks'][0]['children'][2]['style']=array('justifyContent'=>'left','margin'=>'0.5rem 0 0 0');
$GLOBALS['native_fixture']=wp_json_encode($autoFixture);
$GLOBALS['generations']=array();$GLOBALS['inspection_calls']=array();
$autoEvents=array();
$autoJob=syndamorph_team_real_run('Auto-repair invalid buttons margin with no additional AI calls.',false,
    static function(string $type,array $data)use(&$autoEvents):void{
        if($type==='compiler_auto_repair')$autoEvents[]=$data;
    },'gutenberg');
expect(is_array($autoJob) && count($GLOBALS['generations'])===5 &&
    $autoJob['surgery']['deterministic']===1 && $autoJob['surgery']['paidPatches']===0 &&
    count($autoEvents)===1 && $autoEvents[0]['requiresVisualQa']===true &&
    $GLOBALS['inspection_calls']===array('reference','native') &&
    (json_decode($autoJob['outputs']['developer']??'{}',true)['blocks'][0]['children'][2]['style']['margin']??null)===array('top'=>'0.5rem','bottom'=>'0') &&
    syndamorph_surgery_load($autoJob['surgery']['originalPlanId'])['plan']['blocks'][0]['children'][2]['style']['margin']==='0.5rem 0 0 0' &&
    array_key_exists('margin',$autoFixture['blocks'][0]['children'][2]['style']),
    'GEN-000019 should finish native compilation with one auto-repair and zero paid repair calls');
$verifiedRules=syndamorph_learning_retrieve('developer');
expect(count(array_filter($verifiedRules,static fn($rule)=>str_contains($rule,'buttons.style.margin')))>0,
    'A compiler-confirmed buttons.margin rejection was not learned across runs');
$GLOBALS['native_fixture']=$beforeAutoFixture;
$GLOBALS['generations']=array();$GLOBALS['inspection_calls']=array();
$followupJob=syndamorph_team_real_run('Avoid repeating known invalid native schema properties.',false,null,'gutenberg');
expect(is_array($followupJob)&&count($GLOBALS['generations'])===5&&
    str_contains(wp_json_encode($GLOBALS['generations'][3]),'Use buttons.style.margin'),
    'Next Developer generation did not receive verified cross-job compiler learning');
$rollbackPlan=$autoFixture;
$rollbackPlan['blocks'][0]['style']['gradient']='linear-gradient(135deg,#18283a 0%,#203040 100%)';
$rollbackPlan['blocks'][0]['children'][2]['style']['margin']='0.5rem 1rem 0 0';
$GLOBALS['native_fixture']=wp_json_encode($rollbackPlan);
$GLOBALS['generations']=array();
$rollbacks=array();
$rollbackJob=syndamorph_collab_run('Retain an unsupported horizontal margin as a design blocker.',false,
    static function(string $type,array $data)use(&$rollbacks):void {
        if($type==='compiler_auto_repair')$rollbacks[]=$data;
    },false);
$rollbackData=$rollbackJob->get_error_data();
$original=syndamorph_surgery_load($rollbackData['workingPlanId']);
$staged=syndamorph_surgery_load($rollbackData['surgery']['schemaCandidateId']);
expect($rollbackJob->get_error_code()==='sm_native_capability' && count($GLOBALS['generations'])===4 &&
    array_column($rollbacks,'status')===array('rolled_back') &&
    $original['plan']===syndamorph_collab_ids($rollbackPlan) &&
    $staged['plan']['blocks'][0]['style']['gradient']==='linear-gradient(135deg,#18283a,#203040)' &&
    $staged['plan']['blocks'][0]['children'][2]['style']['margin']==='0.5rem 1rem 0 0',
    'Compiler must roll back the transaction and preserve an unsupported design request without paying for deletion');
$GLOBALS['options'][SYNDAMORPH_LEARNING_OPTION]=$beforeAutoLearning;
$GLOBALS['native_fixture']=$beforeAutoFixture;
$GLOBALS['generations']=$beforeReplayGenerations;
$GLOBALS['inspection_calls']=$beforeReplayInspections;
$GLOBALS['generations']=array(array('cached'=>true),array('cached'=>true),array('cached'=>true));
$GLOBALS['syndamorph_resume_calls']=array(
    array('role'=>'boss','task'=>'boss','text'=>'{"title":"Premium hotel hero","sections":["Hero"],"constraints":["Native Gutenberg"],"acceptance":["Valid editable blocks"]}',
        'metric'=>array('inputTokens'=>356,'outputTokens'=>100)),
    array('role'=>'designer','task'=>'designer','text'=>'<section class="hero"><style>.hero{padding:3rem}</style><h2>Welcome &amp; enjoy</h2></section>',
        'metric'=>array('inputTokens'=>400,'outputTokens'=>110)),
    array('role'=>'boss','task'=>'final_approval','text'=>'{"decision":"approved","issues":[],"summary":"Approved design"}',
        'metric'=>array('inputTokens'=>500,'outputTokens'=>120)),
);
$GLOBALS['syndamorph_resume_index']=0;
$GLOBALS['syndamorph_resume_suppress']=true;
$resumed=syndamorph_team_real_run('Create a native premium hotel hero.',false,null,'gutenberg');
expect(is_array($resumed)&&$resumed['status']==='needs_review'
    &&count($GLOBALS['generations'])===5&&$GLOBALS['syndamorph_resume_index']===3,
    'Paused native production did not reuse three completed paid calls and execute only remaining two');
unset($GLOBALS['syndamorph_resume_calls'],$GLOBALS['syndamorph_resume_index'],$GLOBALS['syndamorph_resume_suppress']);
$GLOBALS['generations']=$beforeReplayGenerations;
$GLOBALS['inspection_calls']=$beforeReplayInspections;

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
// GEN-000019: compiler preserves the authored vertical margin as native sides.
// Compiler-success is not final visual approval; browser QA still runs later.
$badButtonsPlan=syndamorph_collab_ids($richPlan);
$badButtonsPlan['blocks'][0]['children'][2]['style']=array(
    'justifyContent'=>'left','margin'=>'0.5rem 0 0 0');
$badButtonsError=syndamorph_compile_block_plan(wp_json_encode($badButtonsPlan));
expect(is_wp_error($badButtonsError)&&str_ends_with($badButtonsError->get_error_message(),'This block supports only top and bottom margins.')&&
    str_ends_with($badButtonsError->get_error_data()['field']??'','.style.margin'),
    'GEN-000019 buttons.margin fixture no longer reproduces native compiler rejection');
$buttonsDiagnostic=array('code'=>$badButtonsError->get_error_code(),
    'message'=>$badButtonsError->get_error_message(),
    'field'=>$badButtonsError->get_error_data()['field']);
$autoButtons=syndamorph_compiler_auto_repair($badButtonsPlan,$buttonsDiagnostic);
expect(is_array($autoButtons)&&$autoButtons['compiled']===true &&
    $autoButtons['plan']['blocks'][0]['children'][2]['style']['margin']===array('top'=>'0.5rem','bottom'=>'0') &&
    ($autoButtons['plan']['blocks'][0]['children'][2]['style']['justifyContent']??null)==='left' &&
    $autoButtons['plan']['blocks'][0]['children'][0]===$badButtonsPlan['blocks'][0]['children'][0] &&
    array_key_exists('margin',$badButtonsPlan['blocks'][0]['children'][2]['style']),
    'Compiler auto-repair did not preserve GEN-000019 vertical margin while correcting its format');
$buttonId=$badButtonsPlan['blocks'][0]['children'][2]['id'];
$explicitDelete=syndamorph_surgery_apply($badButtonsPlan,array('id'=>$buttonId,
    'changes'=>array(array('path'=>'style.margin','op'=>'remove'))));
$nullAssignment=syndamorph_surgery_apply($badButtonsPlan,array('id'=>$buttonId,
    'changes'=>array(array('path'=>'style.margin','value'=>null))));
expect(is_array($explicitDelete)&&!is_wp_error($explicitDelete['error']) &&
    !array_key_exists('margin',$explicitDelete['plan']['blocks'][0]['children'][2]['style']) &&
    is_array($nullAssignment)&&is_wp_error($nullAssignment['error']) &&
    array_key_exists('margin',$nullAssignment['plan']['blocks'][0]['children'][2]['style']),
    'Developer fallback must distinguish explicit remove operations from null assignment');
$badGradientPlan=syndamorph_collab_ids($richPlan);
$badGradientPlan['blocks'][0]['style']['gradient']='linear-gradient(135deg,#18283a 0%,#203040 100%)';
$badGradientError=syndamorph_compile_block_plan(wp_json_encode($badGradientPlan));
$gradientDiagnostic=array('code'=>$badGradientError->get_error_code(),
    'message'=>$badGradientError->get_error_message(),
    'field'=>$badGradientError->get_error_data()['field']);
$autoGradient=syndamorph_compiler_auto_repair($badGradientPlan,$gradientDiagnostic);
expect(is_array($autoGradient)&&$autoGradient['compiled']===true&&
    $autoGradient['plan']['blocks'][0]['style']['gradient']==='linear-gradient(135deg,#18283a,#203040)',
    'Equivalent endpoint gradient stops were not normalized without Developer');
$unsafeGradient=$badGradientPlan;
$unsafeGradient['blocks'][0]['style']['gradient']='linear-gradient(135deg,#18283a 20%,#203040 80%)';
$unsafeError=syndamorph_compile_block_plan(wp_json_encode($unsafeGradient));
expect(syndamorph_compiler_auto_repair($unsafeGradient,array(
    'code'=>$unsafeError->get_error_code(),'message'=>$unsafeError->get_error_message(),
    'field'=>$unsafeError->get_error_data()['field']))===null,
    'Unsafe gradient normalization changed explicit color-stop semantics');

$compilerRequest=array('name'=>'compiler.validate','target'=>'plan',
    'reason'=>'Validate the native Gutenberg structure before continuing development');
$GLOBALS['syndamorph_production_tool_cache']=array();
$GLOBALS['syndamorph_production_tool_count']=0;
$nativeCheck=syndamorph_production_tool_request('developer',$compilerRequest,
    array('plan'=>$richPlan));
$nativeCache=syndamorph_production_tool_request('developer',$compilerRequest,
    array('plan'=>$richPlan));
expect(is_array($nativeCheck)&&($nativeCheck['evidence']['valid']??false)===true&&
    ($nativeCheck['receipt']['cache']??null)==='miss'&&($nativeCache['receipt']['cache']??null)==='hit',
    'Global compiler validation tool did not return a deterministic cached result');
expect(is_wp_error(syndamorph_production_tool_request('boss',$compilerRequest,
    array('plan'=>$richPlan))),
    'Boss received unauthorized native compiler validation access');
// Regression: GEN-000017 Boss diagnostic was 473 characters, which
// previously failed at the arbitrary 260-byte reason contract.
$longDiagnostic='At 375, 768 and 1440 CSS px, report the browser-computed max-width of every leaf heading, paragraph and button, and the computed text-transform and letter-spacing of the headline and eyebrow, to confirm that the leaf max-width and casing/wrapping effects flagged in the previous review are removed. The surgery changed one 54-byte segment to 24 bytes, but changedMeasurements is empty and verificationEvidence is null, so the fix cannot be confirmed from the supplied data.';
$longToolRequest=array('name'=>'compiler.validate','target'=>'plan','reason'=>$longDiagnostic);
$longResult=syndamorph_production_tool_request('developer',$longToolRequest,array('plan'=>$richPlan));
expect(is_array($longResult)&&($longResult['evidence']['valid']??null)===true &&
    strlen($longResult['receipt']['reason'])<=260 &&
    strlen($longResult['evidence']['reason']??'')===0,
    'GEN-000017 diagnostic length must not reject a valid tool request or bloat audit receipts');
$overlongRequest=$longToolRequest;
$overlongRequest['reason']=str_repeat('x',4097);
expect(is_wp_error(syndamorph_production_tool_request('developer',$overlongRequest,array('plan'=>$richPlan))),
    'Tool requests must retain a hard maximum input budget');


// Global authority invariant: model decisions remain attributed and immutable,
// while orchestrator gates report their own, separately authorized status.
$authorityEvents=array();
$emitAuthority=static function(string $type,array $data)use(&$authorityEvents):void{
    $authorityEvents[]=array('type'=>$type,'data'=>$data);
};
$agentRecord=syndamorph_authority_decision('boss','design_approval',
    array('decision'=>'approved','issues'=>array(),'summary'=>'Approved by Boss'),
    array('approved','changes_requested'),$emitAuthority);
$gateRecord=syndamorph_authority_gate($agentRecord,'blocked',
    'Deterministic safety validation failed.',$emitAuthority);
expect($agentRecord['decision']==='approved'&&$gateRecord['agentDecision']==='approved'&&
    $gateRecord['executionStatus']==='blocked'&&$gateRecord['owner']==='orchestrator'&&
    count($authorityEvents)===2&&$authorityEvents[0]['type']==='agent_decision'&&
    $authorityEvents[1]['type']==='execution_gate'&&
    $authorityEvents[0]['data']['decision']==='approved',
    'Orchestrator must never change an agent approval when blocking execution');
expect(is_wp_error(syndamorph_authority_decision('boss','design_approval',
    array('decision'=>'surprise_override'),array('approved','changes_requested'),$emitAuthority)),
    'Unknown agent decisions must not be silently converted into repair instructions');
expect(is_wp_error(syndamorph_authority_decision('designer','design_approval',
    null,array('approved'),$emitAuthority)),
    'Invalid responses cannot be impersonated as another agent decision');
expect(count($authorityEvents)===2,'Invalid decisions must not emit fabricated agent decision events');

// Conflicting QA may block execution, never reinterpret Boss's answer
// as an authorized changes_requested assignment to Developer.
$GLOBALS['authority_qa_conflict_test']=true;
$GLOBALS['generations']=array();
$qaAuthorityEvents=array();
$qaConflict=syndamorph_team_real_run('Catch an inconsistent Boss implementation QA decision.',
    false,static function(string $type,array $data)use(&$qaAuthorityEvents):void{
        if(in_array($type,array('agent_decision','execution_gate','handoff'),true))
            $qaAuthorityEvents[]=array('type'=>$type,'data'=>$data);
    },'gutenberg');
$qaOriginal=array_values(array_filter($qaAuthorityEvents,static fn($event)=>
    $event['type']==='agent_decision'&&($event['data']['stage']??'')==='implementation_qa'));
$qaGate=array_values(array_filter($qaAuthorityEvents,static fn($event)=>
    $event['type']==='execution_gate'&&($event['data']['stage']??'')==='implementation_qa'));
expect(is_wp_error($qaConflict)&&$qaConflict->get_error_code()==='sm_boss_qa_contract'&&
    count($GLOBALS['generations'])===5&&count($qaOriginal)===1&&count($qaGate)===1&&
    $qaOriginal[0]['data']['decision']==='approved'&&
    $qaGate[0]['data']['agentDecision']==='approved'&&
    $qaGate[0]['data']['executionStatus']==='blocked',
    'QA contradiction must block without forging an agent change request or starting Developer surgery');
unset($GLOBALS['authority_qa_conflict_test']);

// GEN-000018: an explicit issue-free Boss approval is final for the design
// stage even when accepted capability deviations are narrative strings.
$GLOBALS['boss_approved_deviation_test']=true;
$GLOBALS['generations']=array();
$approvalEvents=array();
$accepted=syndamorph_team_real_run('Approve the hero with a nonblocking typography mapping caveat.',
    false,static function(string $type,array $data)use(&$approvalEvents):void{
        if($type==='handoff')$approvalEvents[]=$data;
    },'gutenberg');
expect(is_array($accepted)&&count($GLOBALS['generations'])===5 &&
    ($accepted['designApproval']['reviewRounds']??null)===1 &&
    count(array_filter($approvalEvents,static fn($event)=>
        ($event['type']??'')==='design_review'&&($event['decision']??'')==='approved'))===1,
    'GEN-000018: orchestrator overrode Boss approval or sent Designer into repair');
unset($GLOBALS['boss_approved_deviation_test']);

// Agents share the same read-only Playwright capability, without exposing
// executable HTML, native mutation, arbitrary endpoints, or duplicate renders.
$tools=syndamorph_production_tool_catalog('designer');
expect(isset($tools['playwright.inspect'])&&!isset($tools['compiler.patch']),
    'Designer must discover globally registered Playwright without native mutation grants');
$originalInspections=$GLOBALS['inspection_calls'];
$GLOBALS['tool_verification_test']=true;
$GLOBALS['generations']=array();
$GLOBALS['inspection_calls']=array();
$toolReceipts=array();
$verified=syndamorph_team_real_run('Inspect the hotel hero with the globally shared Playwright tool.',false,
    static function(string $type,array $data)use(&$toolReceipts):void{
        if($type==='tool_invocation')$toolReceipts[]=$data;
    },'gutenberg');
expect(is_array($verified)&&$verified['designApproval']['reviewRounds']===2 &&
    count($GLOBALS['generations'])===6&&count($toolReceipts)===1 &&
    $toolReceipts[0]['cache']==='hit'&&$toolReceipts[0]['role']==='boss' &&
    $GLOBALS['inspection_calls']===array('reference','native'),
    'On-demand Boss inspection must reuse the existing browser evidence without an extra renderer call');
$bossToolContext=json_decode($GLOBALS['generations'][3]['messages'][0]['content'][0]['text'],true);
expect(($bossToolContext['verificationEvidence']['evidence']['source']??'')==='playwright.computed',
    'Boss continuation did not receive structured computed-style evidence');
unset($GLOBALS['tool_verification_test']);
$GLOBALS['tool_generic_test']=true;
$GLOBALS['generations']=array();
$GLOBALS['inspection_calls']=array();
$genericReceipts=array();
$generic=syndamorph_team_real_run('Allow Developer to inspect the reference on demand.',false,
    static function(string $type,array $data)use(&$genericReceipts):void{
        if($type==='tool_invocation')$genericReceipts[]=$data;
    },'gutenberg');
expect(is_array($generic)&&count($GLOBALS['generations'])===6 &&
    count($genericReceipts)===1&&$genericReceipts[0]['role']==='developer'&&
    $genericReceipts[0]['cache']==='hit'&&$GLOBALS['inspection_calls']===array('reference','native'),
    'Developer could not request the globally cached Playwright tool on demand');
$devToolContext=json_decode($GLOBALS['generations'][4]['messages'][0]['content'][0]['text'],true);
expect(($devToolContext['toolEvidence']['evidence']['source']??null)==='playwright.computed',
    'Developer did not receive requested deterministic tool evidence');
unset($GLOBALS['tool_generic_test']);
$GLOBALS['tool_designer_test']=true;
$GLOBALS['generations']=array();
$GLOBALS['inspection_calls']=array();
$designerReceipts=array();
$designerVerified=syndamorph_team_real_run('Designer may request global browser evidence rather than guessing.',false,
    static function(string $type,array $data)use(&$designerReceipts):void{
        if($type==='tool_invocation')$designerReceipts[]=$data;
    },'gutenberg');
expect(is_array($designerVerified)&&$designerVerified['designApproval']['reviewRounds']===2 &&
    count($GLOBALS['generations'])===7&&count($designerReceipts)===1 &&
    $designerReceipts[0]['role']==='designer'&&$designerReceipts[0]['cache']==='hit'&&
    $GLOBALS['inspection_calls']===array('reference','native'),
    'Designer browser verification did not resolve the Boss dispute without regeneration');
unset($GLOBALS['tool_designer_test']);

expect(is_wp_error(syndamorph_production_tool_request('designer',
    array('name'=>'compiler.patch','target'=>'plan','reason'=>'Edit Gutenberg plan outside Designer scope'),
    array('plan'=>array()))),
    'Global tools allowed Designer to mutate native compiler artifacts');

$GLOBALS['tool_dispute_test']=true;
$GLOBALS['generations']=array();
$disputed=syndamorph_team_real_run('Reject unsupported design complaints without a full regeneration.',false,null,'gutenberg');
expect(is_wp_error($disputed)&&$disputed->get_error_code()==='sm_design_dispute'&&
    count($GLOBALS['generations'])===4,
    'Evidence-based Designer dispute caused another full design regeneration');
unset($GLOBALS['tool_dispute_test']);
$GLOBALS['inspection_calls']=$originalInspections;
$GLOBALS['generations']=$beforeReplayGenerations;

$GLOBALS['syndamorph_resume_calls']=array(array('role'=>'developer','task'=>'repair','text'=>'cached-model-patch','metric'=>array('outputTokens'=>17)));
$GLOBALS['syndamorph_resume_index']=0;
$GLOBALS['syndamorph_resume_suppress']=true;
$wrong=syndamorph_job_resume_take('boss','repair');
expect(is_wp_error($wrong)&&$wrong->get_error_code()==='sm_resume_diverged'&&$GLOBALS['syndamorph_resume_index']===0,
    'Replay divergence must halt without consuming or duplicating a paid call');
$replayed=syndamorph_job_resume_take('developer','repair');
expect(is_array($replayed)&&$replayed['text']==='cached-model-patch'&&$GLOBALS['syndamorph_resume_index']===1,
    'Resume failed to reuse the exact recorded developer response');
expect(syndamorph_job_resume_take('developer','repair')===null&&!isset($GLOBALS['syndamorph_resume_suppress']),
    'Replay did not release suppression at the first new operation');
unset($GLOBALS['syndamorph_resume_calls'],$GLOBALS['syndamorph_resume_index']);
$designReference='<section class="sm-hero"><style>.sm-hero{color:#ffffff}</style><h2>Welcome</h2><p>Introduction</p></section>';
$designEdit=syndamorph_design_surgery($designReference,array('edits'=>array(
    array('find'=>'.sm-hero{color:#ffffff}','replace'=>'.sm-hero{color:#f0f0f0}'))));
expect(is_array($designEdit)&&str_contains($designEdit['reference'],'color:#f0f0f0')
    &&str_contains($designEdit['reference'],'<p>Introduction</p>'),
    'Designer surgery failed to preserve unrelated reference content');
expect(is_wp_error(syndamorph_design_surgery($designReference,array('edits'=>array(
    array('find'=>'unmatched declaration','replace'=>'padding:2rem'))))),
    'Designer surgery accepted an unmatched edit');
expect(is_wp_error(syndamorph_design_surgery($designReference,array('edits'=>array(
    array('find'=>'<h2>Welcome</h2>','replace'=>'<script>alert(1)</script>'))))),
    'Designer surgery accepted active markup');

$guidancePlan=$richPlan;
$guidancePlan['blocks'][0]['children'][0]['style']['fontSize']='1.1234567rem';
$guided=syndamorph_compile_block_plan(wp_json_encode($guidancePlan));
expect(is_wp_error($guided)&&($guided->get_error_data()['expected']['format']??'')!==''
    &&str_contains($guided->get_error_data()['expected']['constraint']??'','6 decimal digits')
    &&($guided->get_error_data()['field']??'')==='blocks[0].children[0].style.fontSize',
    'Compiler missing precise field-specific remediation instructions');
$badHref=$richPlan;
$badHref['blocks'][0]['children'][2]['children'][0]['href']='https://example.com';
$hrefDiagnostic=syndamorph_compile_block_plan(wp_json_encode($badHref));
expect(is_wp_error($hrefDiagnostic)&&str_contains($hrefDiagnostic->get_error_data()['expected']['format']??'','Local #fragment'),
    'Compiler failed to explain allowed local CTA URLs');

// F11 regression: CSS-valid four-decimal length must not trigger paid schema surgery.
$precisionPlan=$richPlan;
$precisionPlan['blocks'][0]['children'][0]['style']['fontSize']='clamp(1.0625rem,1rem + 0.5vw,1.25rem)';
expect(!is_wp_error(syndamorph_compile_block_plan(wp_json_encode($precisionPlan))),
    'Four-decimal CSS lengths incorrectly rejected (GEN-000004 regression)');
// GEN-000026: native decoration preserves the approved empty panel and its copy.
$decoration=array('type'=>'group','id'=>'decorative-panel','style'=>array(
    'minHeight'=>'clamp(12rem,30vw,26rem)','gradient'=>'linear-gradient(200deg,#2f4f70,#14263a)'));
$decorativePlan=$richPlan;
$decorativePlan['blocks'][0]['children'][]=$decoration;
$decorativeArtifact=syndamorph_compile_block_plan(wp_json_encode($decorativePlan));
$withEmptyList=$decorativePlan;
$withEmptyList['blocks'][0]['children'][3]['children']=array();
expect(!is_wp_error($decorativeArtifact) && $decorativeArtifact['schemaFacts']===array('decorative_group') &&
    $decorativeArtifact['content']===syndamorph_compile_block_plan(wp_json_encode($withEmptyList))['content'] &&
    !str_contains($decorativeArtifact['content'],'<!-- wp:paragraph --><p></p>'),
    'Decorative Group did not compile without adding content');
$invalidContainer=$decorativePlan;
unset($invalidContainer['blocks'][0]['children'][3]['style']['minHeight']);
$priorDecorativeFixture=$GLOBALS['native_fixture'];
$priorDecorativeLearning=syndamorph_learning_rows();
update_option(SYNDAMORPH_LEARNING_OPTION,array(),false);
$GLOBALS['native_fixture']=wp_json_encode($invalidContainer);
$GLOBALS['generations']=array();
$blockedStructure=syndamorph_team_real_run('Reject an incomplete content Group before requesting edits.',false,null,'gutenberg');
expect(is_wp_error($blockedStructure) && $blockedStructure->get_error_code()==='sm_schema_unrepairable' &&
    count($GLOBALS['generations'])===4 &&
    str_contains($blockedStructure->get_error_data()['expected']['format'],'nested decorative Group'),
    'Structural diagnostics must not spend the style repair budget');
$GLOBALS['native_fixture']=wp_json_encode($decorativePlan);
$GLOBALS['generations']=array();
$decorativeReceipts=array();
$replay=syndamorph_team_real_run('Compile a native decorative panel without adding spacer copy.',false,
    static function($type,$data)use(&$decorativeReceipts):void{
        if($type==='schema_repair' && ($data['status']??'')==='verified')$decorativeReceipts[]=$data;
    },'gutenberg');
$decorationRule=syndamorph_block_plan_decorative_group_rule();
expect(is_array($replay) && count($GLOBALS['generations'])===5 && $replay['surgery']['paidPatches']===0 &&
    ($decorativeReceipts[0]['fact']??'')==='decorative_group' &&
    syndamorph_learning_retrieve('developer','group')===array($decorationRule) &&
    syndamorph_learning_retrieve('developer','buttons')===array(),
    'The validated compiler fact must persist and stay scoped to Group');
$GLOBALS['native_fixture']=$priorDecorativeFixture;
$GLOBALS['generations']=array();
$recall=syndamorph_team_real_run('Retrieve verified compiler lessons from a prior job.',false,null,'gutenberg');
$developerContext=json_decode($GLOBALS['generations'][3]['messages'][0]['content'][0]['text'],true);
expect(is_array($recall) && in_array($decorationRule,$developerContext['learnedRules']??array(),true),
    'Subsequent jobs must retrieve verified native schema facts');
update_option(SYNDAMORPH_LEARNING_OPTION,$priorDecorativeLearning,false);
// The remaining invalid seven-decimal value must not cause unlimited paid identical patches.
$priorFixture=$GLOBALS['native_fixture'];
$stallPlan=json_decode($priorFixture,true);
$stallPlan['blocks'][0]['children'][0]['style']['fontSize']='1.1234567rem';
$GLOBALS['native_fixture']=wp_json_encode($stallPlan);
$GLOBALS['generations']=array();
$GLOBALS['schema_stall_test']=true;
$stallResult=syndamorph_team_real_run('Recover repeated invalid native font size.',false,null,'gutenberg');
expect(is_wp_error($stallResult)&&$stallResult->get_error_code()==='sm_schema_stagnation',
    'Repeated identical invalid schema patches did not halt with actionable stagnation: '.(is_wp_error($stallResult)?$stallResult->get_error_code().' '.$stallResult->get_error_message():gettype($stallResult)).'; model calls='.count($GLOBALS['generations']));
expect(count($GLOBALS['generations'])===6,
    'Same-error guard allowed more than two identical paid Developer repair proposals');
expect(!empty($stallResult->get_error_data()['workingPlanId']),
    'Same-error schema escalation lost the persistent working plan checkpoint');
unset($GLOBALS['schema_stall_test']);
foreach(array('missing','mismatch') as $ackMode){
    $GLOBALS['schema_ack_test']=$ackMode;
    $GLOBALS['generations']=array();
    $rejected=syndamorph_team_real_run('Verify compiler acknowledgement enforcement.',false,null,'gutenberg');
    expect(is_wp_error($rejected)&&$rejected->get_error_code()==='sm_schema_stagnation'
        &&count($GLOBALS['generations'])===7,
        'Unacknowledged compiler instructions were applied or retry bound was bypassed: '.$ackMode);
}
unset($GLOBALS['schema_ack_test']);
$GLOBALS['schema_ack_test']='valid';
$GLOBALS['generations']=array();
$accepted=syndamorph_team_real_run('Verify guided schema repair.',false,null,'gutenberg');
expect(is_array($accepted)&&($accepted['artifact']['blockCount']??0)===5
    &&count($GLOBALS['generations'])===6,
    'Properly acknowledged compiler guidance did not yield a valid repair: '.(is_wp_error($accepted)?$accepted->get_error_code().' '.$accepted->get_error_message():gettype($accepted)).'; calls='.count($GLOBALS['generations']));
unset($GLOBALS['schema_ack_test']);
$fontDiagnostic=json_decode($GLOBALS['generations'][4]['messages'][0]['content'][0]['text'],true);
expect($fontDiagnostic['allowedPaths']===array('style.fontSize') &&
    is_wp_error(syndamorph_schema_apply_patch(syndamorph_collab_ids($stallPlan),
        array('id'=>$fontDiagnostic['target']['id'],'changes'=>array(array('path'=>'style.color','value'=>'#ffffff'))),
        $fontDiagnostic['target'],$fontDiagnostic['allowedPaths'])),
    'Schema instructions and edit enforcement must share the actual failing path');
$GLOBALS['native_fixture']=$priorFixture;
$GLOBALS['generations']=array();
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
expect(count($GLOBALS['generations'])===5 &&
    $case['surgery']['deterministic']===1 && $case['surgery']['paidPatches']===0 &&
    $case['surgery']['fullRegenerations']===0,
    'A supported gradient in the background field must normalize without a schema repair call');
expect($case['surgery']['repairs'][0]['accepted']&&$case['surgery']['repairs'][0]['compiled'],
    'Surgical correction did not compile');
expect(str_contains($case['artifact']['content'],'linear-gradient(135deg,#18283a,#203040)')&&
    str_contains($case['artifact']['content'],'Welcome &amp; enjoy'),
    'Compiled surgical plan lost the gradient or original content');
$persisted=syndamorph_surgery_load($case['surgery']['workingPlanId']);
expect(is_array($persisted)&&$persisted['plan']!==syndamorph_surgery_load($case['surgery']['originalPlanId'])['plan'],
    'Resolved surgical plan must have a checkpoint distinct from the original.');
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
expect($GLOBALS['generations'][5]['max_tokens']>4096 && $GLOBALS['generations'][6]['max_tokens']>4096,
    'Developer and Boss repair calls must use budget-aware output allowances');
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
$GLOBALS['cutoff_fixture']=94000;
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
$visibleReference=array('tag'=>'P','text'=>'Example label','visible'=>true,'styles'=>array('fontSize'=>'16px'));
$hiddenReference=array_replace($visibleReference,array('visible'=>false,'styles'=>array('fontSize'=>'24px')));
$visibleNative=$visibleReference+array('node'=>'compact-label');
$hiddenNative=array_replace($visibleNative,array('node'=>'desktop-label','visible'=>false,'styles'=>array('fontSize'=>'28px')));
$visibleView=static fn(array $elements):array=>array(array('width'=>375,'snapshot'=>array('elements'=>$elements)));
expect(syndamorph_collab_style_findings($visibleView(array($hiddenReference,$visibleReference)),
    $visibleView(array($visibleNative,$hiddenNative)),$gen015['plan'])===array(),
    'Hidden breakpoint variants polluted visible typography matching');
expect(syndamorph_collab_style_findings($visibleView(array($visibleReference)),
    $visibleView(array($hiddenNative)),$gen015['plan'])===array(),
    'A hidden native component produced an unsupported typography finding');
$visibleNative['styles']['fontSize']='18px';
expect(array_column(syndamorph_collab_style_findings($visibleView(array($hiddenReference,$visibleReference)),
    $visibleView(array($visibleNative,$hiddenNative)),$gen015['plan']),'component')===array('compact-label'),
    'Hidden duplicates suppressed a real visible typography finding');
$paintFixture=static fn(string $node,array $rect)=>array('node'=>$node,'rect'=>$rect,'visible'=>true,
    'normalizedText'=>'','styles'=>array('backgroundColor'=>'rgb(234, 231, 231)'));
$paintEvidence=syndamorph_collab_painted_leaves($visibleView(array(
    $paintFixture('second',array(180,10,100,100)), $paintFixture('first',array(50,10,100,100)),
    array_replace($paintFixture('hidden',array(0,10,50,50)),array('visible'=>false)))));
expect(array_column($paintEvidence[375],'component')===array('first','second'),
    'QA paint evidence used DOM order or a hidden component rather than browser positions');
$archivedDefects=array_map(static fn($i,$severity)=>array('id'=>'defect-'.$i,'component'=>'root','severity'=>$severity),
    range(1,4),array('high','medium','medium','medium'));
$archivedDefects[]=array('id'=>'defect-5','component'=>'root','severity'=>'medium');
$withDiscovery=array_merge($archivedDefects,array(array('id'=>'historical-gap','component'=>'inner','severity'=>'low')));
$oldRatios=array_map(static fn($width)=>array('width'=>$width,'pixelDifferenceRatio'=>0.2),array(375,768,1440));
$newRatios=array_map(static fn($index,$delta)=>array('width'=>$oldRatios[$index]['width'],'pixelDifferenceRatio'=>0.2+$delta),
    array(0,1,2),array(-0.04583,-0.00789,0.00239));
$historical=syndamorph_visual_decision($archivedDefects,$withDiscovery,array(),array(),$oldRatios,$newRatios,$archivedDefects,false);
expect(!$historical['accepted'] && $historical['outcome']==='unchanged' && $historical['beforeScore']===26 && $historical['afterScore']===27 &&
    $historical['comparableAfterScore']===26 && $historical['states']['inner|historical-gap']==='unverified_discovery',
    'A historical discovery remains separate from regression, but equal comparable score cannot promote a candidate');
$intro=str_repeat('Full text needed beyond the geometry excerpt. ',8).'Plan your visit and let us prepare the rest.';
$referenceCopy=array_map(static fn($width)=>array('width'=>$width,'snapshot'=>array('copy'=>$intro)),array(375,768,1440));
$oldCopy=syndamorph_visual_copy($referenceCopy,$referenceCopy);
$shortCopy=$referenceCopy;
$shortCopy[0]['snapshot']['copy']=mb_substr($intro,0,250);
expect($oldCopy['status']==='equal' &&
    syndamorph_visual_copy($referenceCopy,$shortCopy)['widthsWithDifferences']===array(375),
    'Copy QA ignored text beyond the geometry excerpt');
$copyIssues=syndamorph_collab_evidence($referenceCopy,array_map(static function($sample){
    $sample['snapshot']['overflow']=false;
    return $sample;
},$shortCopy));
$copyVerdict=syndamorph_visual_decision(array(),$copyIssues,array(),array(),
    array(array('width'=>375,'pixelDifferenceRatio'=>0.2)),array(array('width'=>375,'pixelDifferenceRatio'=>0.1)),array(),true);
expect(!$copyVerdict['accepted'] && $copyVerdict['introducedIssues']===array('copy-reference-mismatch'),
    'A genuine copy regression must override screenshot improvement');
$goodMetrics=array('wrapping|leaf'=>array('views'=>array(375=>array('error'=>0,'tolerance'=>0.5))));
$badMetrics=array('wrapping|leaf'=>array('views'=>array(375=>array('error'=>1,'tolerance'=>0.5))));
$newWrap=array('id'=>'fresh-wrap','component'=>'leaf','group'=>'wrapping|leaf',
    'severity'=>'medium','measurement'=>true,'source'=>'browser');
$lowerButNew=syndamorph_visual_decision($archivedDefects,array($newWrap),$goodMetrics,$badMetrics,$oldRatios,$newRatios,$archivedDefects,true);
expect(!$lowerButNew['accepted']&&$lowerButNew['comparableAfterScore']<$lowerButNew['beforeScore']&&
    $lowerButNew['introducedIssues']===array('fresh-wrap'),'New measured defects must veto a lower aggregate score');
$oldStyle=array('typography|leaf'=>array(375=>array('fontSize')));
$newStyle=array('typography|leaf'=>array(375=>array('letterSpacing')));
$newTypography=array_replace($newWrap,array('id'=>'fresh-tracking','group'=>'typography|leaf'));
$mixed=syndamorph_visual_decision($archivedDefects,array($newTypography),array(),array(),$oldRatios,$newRatios,
    $archivedDefects,true,$oldStyle,$newStyle);
expect(!$mixed['accepted']&&$mixed['introducedIssues']===array('fresh-tracking'),
    'Resolving one typography property must not mask a new measured typography defect');
expect(syndamorph_anthropic_reported_usage(array('input_tokens'=>18,'output_tokens'=>31),
    array('inputTokens'=>12,'outputTokens'=>20,'cacheReadTokens'=>5))===
        array('inputTokens'=>18,'outputTokens'=>31,'cacheReadTokens'=>5),
    'Cumulative provider usage was double-counted or lost');
expect(syndamorph_anthropic_reported_usage(array())===array(),'Missing usage was mislabeled as provider-reported zeros');
echo "PASS: GEN-015 partial repair retention, stable defect identity, complete copy and genuine regression veto\n";
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
expect($notes===array(),'Native text-transform must not be reported as unsupported');
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
    syndamorph_surgery_load($adaptive['surgery']['workingPlanId'])['plan']===$adaptivePlan,
    'Combined structural and four style changes did not commit atomically with sibling preservation');
$groupSummary=$adaptive['quality']['repairSummary']['groups'][0];
expect($groupSummary['attempts']===2 && $groupSummary['usage']['calls']===4 &&
    $groupSummary['retainedImprovement']===1.0 && $adaptive['quality']['revisions'][0]['retainedImprovement']===0.0,
    'Coordinated operation did not include failed work in its cost');
$adaptiveRequest=json_decode($GLOBALS['generations'][7]['messages'][0]['content'][0]['text'],true);
expect(($adaptiveRequest['previousAttempts'][0]['reason']??'')==='Comparable defect score did not improve',
    'Structural diagnosis did not receive failed style validation');
$GLOBALS['adaptive_specific_strategy']='hybrid';
$GLOBALS['generations']=array();
$handoffJob=syndamorph_collab_run('Exercise explicit Boss hybrid instructions with a targeted Developer patch.',false,null,false);
unset($GLOBALS['adaptive_specific_strategy']);
$firstRepair=json_decode($GLOBALS['generations'][5]['messages'][0]['content'][0]['text'],true);
$qaHandoffs=array_column($handoffJob['handoffs'],null,'type');
$qaInput=json_decode($GLOBALS['generations'][6]['messages'][0]['content'][0]['text'],true);
expect($qaInput['candidateHandoff']===$qaHandoffs['revision_candidate'] &&
    $qaInput['candidateHandoff']['from']==='developer' &&
    $qaInput['candidateHandoff']['operation']['wraps'][0]['id']==='text-stack',
    'Scoped QA must receive the exact Developer operation and artifact identity');
expect($firstRepair['repairScope']['bossInstructions']===$qaHandoffs['qa_review']['issues'] &&
    $firstRepair['repairScope']['requestedStrategies']===array('hybrid') &&
    $firstRepair['repairScope']['allowedOperations']===array('style','structure','hybrid') &&
    $handoffJob['quality']['revisions'][0]['operationMode']==='hybrid' &&
    $handoffJob['quality']['revisions'][0]['accepted'] && count($GLOBALS['generations'])===7,
    'Boss instructions were rewritten or hybrid surgery was withheld before a failed style attempt');
expect($handoffJob['agentMetrics'][5]['metering']==='provider_reported' &&
    $handoffJob['agentMetrics'][5]['responseBytes']>0 && $handoffJob['agentMetrics'][5]['outputCap']>4096,
    'Repair metering must distinguish provider totals from serialized response bytes');
$GLOBALS['adaptive_specific_strategy']='unsupported';
$GLOBALS['generations']=array();$blockedHandoffEvents=array();
$blockedHandoff=syndamorph_collab_run('Exercise an unsupported Boss request.',false,
    static function(string $type,array $data)use(&$blockedHandoffEvents):void {
        if($type==='execution_gate')$blockedHandoffEvents[]=$data;
    },false);
unset($GLOBALS['adaptive_specific_strategy']);
expect(is_array($blockedHandoff)&&$blockedHandoff['quality']['terminationReason']==='repair_scope_unsupported' &&
    array_column($blockedHandoff['agentMetrics'],'task')===array('boss','designer','final_approval','developer','validation','scope_resolution') &&
    $blockedHandoffEvents[count($blockedHandoffEvents)-2]['owner']==='orchestrator',
    'Unsupported Boss instruction must block routing to Developer and request clarification');
$unauthorized=array('id'=>'sm-hero-root','wraps'=>array(array('parent'=>'sm-hero-root',
    'children'=>array('sm-hero-title','sm-hero-eyebrow'),'id'=>'reordered')));
expect(is_wp_error(syndamorph_surgery_operation($gen015['plan'],$unauthorized,array('id'=>'sm-hero-root','mode'=>'structure',
    'nodes'=>array('sm-hero-root','sm-hero-eyebrow','sm-hero-title'),'wrapParents'=>array('sm-hero-root')))),
    'Structural surgery accepted reordered protected children');
$fluidPatch=array('id'=>'blocks[0]','changes'=>array(array('path'=>'style.gap','value'=>'clamp(1rem,2vw,2rem)')));
$handoffPlan=syndamorph_collab_ids(json_decode($GLOBALS['native_fixture'],true));
$fluid=syndamorph_surgery_operation($handoffPlan,$fluidPatch,
    array('id'=>'blocks[0]','nodes'=>array('blocks[0]'),'mode'=>'adaptive'));
expect(is_wp_error($fluid)&&$fluid->get_error_code()==='sm_surgery_capability',
    'A newly proposed fluid blockGap must stop before browser or model QA');
$leafFinding=array('id'=>'leaf-hybrid','component'=>'blocks[0].children[0]','severity'=>'high','detail'=>'Try wrapping','repairStrategy'=>'hybrid');
$leafAssignment=syndamorph_repair_assignment($handoffPlan,array($leafFinding),new SyndaMorph_Job_Memory('leaf'));
expect(syndamorph_repair_handoff(array($leafFinding),$leafAssignment,$handoffPlan)['unsupported'],
    'An unsupported Boss instruction must request clarification rather than substitute a style edit');
$pureWrap=array('id'=>'blocks[0]','wraps'=>array(array('parent'=>'blocks[0]',
    'children'=>array('blocks[0].children[0]'),'id'=>'one-heading-group','style'=>array('contentWidth'=>'40rem'))));
$wrapAuthority=array('id'=>'blocks[0]','nodes'=>array('blocks[0]','blocks[0].children[0]'),
    'mode'=>'adaptive','wrapParents'=>array('blocks[0]'));
$geometryCandidate=syndamorph_surgery_operation($handoffPlan,$pureWrap,$wrapAuthority);
expect(!is_wp_error($geometryCandidate) && !is_wp_error($geometryCandidate['error']) &&
    $geometryCandidate['plan']['blocks'][0]['children'][0]['children'][0]['id']==='blocks[0].children[0]',
    'Pure structure must wrap one authorized direct child without changing its existing ID');
$duplicateWrap=$geometryCandidate['plan'];
$duplicateWrap['blocks'][0]['children'][0]['id']='same-layout-fresh-id';
$wrapMemory=new SyndaMorph_Job_Memory('duplicate wrapper');
$wrapMemory->remember($geometryCandidate['plan']);
expect($wrapMemory->seen($duplicateWrap),'A fresh wrapper ID must not bypass candidate deduplication');
$savedProgressionGlobals=array_intersect_key($GLOBALS,array_flip(array('generations','inspection_calls','crop_calls','adaptive_test')));
unset($GLOBALS['adaptive_test']);
foreach(array('progress','duplicate') as $scenario){
    $GLOBALS['progressive_test']=$scenario;
    $GLOBALS['generations']=array();$GLOBALS['progressive_comparison']=0;
    $history=array();
    $progress=syndamorph_collab_run('Replay progressive improvements at 375, 768 and 1440 CSS pixels.',false,
        static function($type,$data)use(&$history):void {
            if($type==='candidate_baseline')$history[]=$data;
        },false);
    expect(is_array($progress),'Progression fixture failed to produce QA evidence');
    [$first,$regression,$last]=$progress['quality']['revisions'];
    $rollForward=json_decode($progress['outputs']['developer'],true);
    $cta=$rollForward['blocks'][0]['children'][2]['children'][0];
    $repairContext=json_decode($GLOBALS['generations'][9]['messages'][0]['content'][0]['text'],true);
    expect($first['outcome']==='progress' && $regression['outcome']==='visual_regression' &&
        $first['baselineAfter']===$regression['baselineBefore'] && $regression['baselineBefore']===$regression['baselineAfter'] &&
        $repairContext['repairScope']['baseline']===$first['baselineAfter'] &&
        $repairContext['repairScope']['bossInstructions'][0]['detail']==='Retained CTA needs a different line height.' &&
        $rollForward['blocks'][0]['children'][0]['style']['fontSize']==='2.5rem' &&
        (($cta['style']['padding']['top']??null)!=='0.75rem') &&
        array_column($history[1]['candidate']['evidence'],'width')===$progress['quality']['viewportWidths'] &&
        $history[0]['referenceHtml']===$history[1]['referenceHtml'],
        'Rejected QA mutated the retained plan, findings, or verified evidence');
    if($scenario==='progress'){
        expect($last['outcome']==='progress' && $last['operationMode']===$regression['operationMode'] &&
            $last['operationSignature']!==$regression['operationSignature'] &&
            $last['baselineBefore']===$first['baselineAfter'] && count($history)===3 &&
            $last['baselineAfter']['version']===3 && $cta['style']['lineHeight']===1.3 &&
            $progress['status']==='needs_review',
            'A distinct style operation did not advance the retained candidate');
    }else{
        expect($last['outcome']==='duplicate' && $last['operationSignature']===$regression['operationSignature'] &&
            $GLOBALS['progressive_comparison']===3 && count($history)===2 &&
            $progress['quality']['terminationReason']==='repair_proposals_repeated' &&
            $progress['quality']['escalation']['baseline']===$first['baselineAfter'] &&
            !empty($progress['quality']['escalation']['deferredScopes']),
            'Equivalent operations must finish without another render or invented strategy exhaustion');
    }
}
unset($GLOBALS['progressive_test'],$GLOBALS['progressive_comparison']);
// Pause/stop must restore the durable checkpoint even if the transient draft is absent.
if(!defined('ARRAY_A'))define('ARRAY_A','ARRAY_A');
$oldDb=$GLOBALS['wpdb']??null;
$GLOBALS['wpdb']=new class(wp_json_encode($history[1])) {
    public string $prefix='sm_test_';
    public function __construct(public string $checkpoint) {}
    public function prepare(string $query,...$args): string { return $query; }
    public function get_results(string $query,$format): array { return array(); }
    public function get_var(string $query): ?string {
        if(str_contains($query,"event_type='candidate_baseline'"))return $this->checkpoint;
        throw new RuntimeException('A durable best candidate must take precedence over working plan fallback');
    }
};
$stopped=syndamorph_job_control_payload(25,'gutenberg','stopped','Operator stop');
expect($stopped['quality']['bestCandidate']===$history[1]['candidate']['identity'] &&
    json_decode($stopped['outputs']['developer'],true)===$history[1]['candidate']['plan'] &&
    $stopped['referenceHtml']===$history[0]['referenceHtml'],
    'Operator stop fell back to the generated plan');
$corrupt=$history[1];
$corrupt['candidate']['artifact']['preview'].='<p>Modified checkpoint</p>';
$GLOBALS['wpdb']->checkpoint=wp_json_encode($corrupt);
expect(syndamorph_history_best_candidate(25)->get_error_code()==='sm_resume_baseline',
    'A modified best candidate must fail its History integrity check');
if($oldDb===null)unset($GLOBALS['wpdb']);else $GLOBALS['wpdb']=$oldDb;
$GLOBALS['syndamorph_resume_calls']=array();
$GLOBALS['syndamorph_resume_baseline']=$history[1]['candidate']['identity'];
$GLOBALS['generations']=array();
$diverged=syndamorph_collab_run('Replay progressive improvements at 375, 768 and 1440 CSS pixels.',false,null,false);
expect(is_wp_error($diverged) && $diverged->get_error_code()==='sm_resume_baseline' && !$GLOBALS['generations'],
    'Resume must verify the retained candidate before requesting any new provider output');
unset($GLOBALS['syndamorph_resume_calls'],$GLOBALS['syndamorph_resume_baseline']);

$GLOBALS['generations']=array();$GLOBALS['inspection_sent_widths']=array();
$customViews=syndamorph_collab_run('Test at 360/820/1600 CSS px.',false,null,false);
expect(is_array($customViews)&&$customViews['quality']['viewportWidths']===array(1600,1440,820,768,375,360) &&
    array_column($customViews['quality']['pixelComparison'],'width')===$customViews['quality']['viewportWidths'] &&
    array_slice($GLOBALS['inspection_sent_widths'],0,2)===array(array(1600,1440,820,768,375),array(360)),
    'Required production widths and additional requested widths must all be rendered and compared');

foreach($savedProgressionGlobals as $name=>$value)$GLOBALS[$name]=$value;
$memoryForStrategy=new SyndaMorph_Job_Memory('scope evidence progression');
$reuseFinding=array('id'=>'leaf-style','component'=>'blocks[0].children[0]','severity'=>'high','detail'=>'Measure style','category'=>'typography');
$existingEvidence=array('blocks[0].children[0]|leaf-style'=>array('views'=>array(375=>array('error'=>13,'tolerance'=>10))));
$nextEvidence=$existingEvidence;
$nextEvidence['blocks[0].children[0]|leaf-style']['views'][375]['error']=24;
$existingAssignment=syndamorph_repair_assignment($handoffPlan,array($reuseFinding),$memoryForStrategy,false,$existingEvidence);
$memoryForStrategy->record($existingAssignment['id'],array('strategyFingerprint'=>$existingAssignment['strategyFingerprint'],
    'issueGroup'=>$existingAssignment['group'],'strategy'=>'style','operationMode'=>'style','validation'=>'no_improvement'));
$unrelatedPlan=$handoffPlan;
$unrelatedPlan['blocks'][0]['children'][1]['style']['fontSize']='3rem';
expect(syndamorph_repair_assignment($handoffPlan,array($reuseFinding),$memoryForStrategy,false,$existingEvidence)['strategy']==='style' &&
    syndamorph_repair_assignment($unrelatedPlan,array($reuseFinding),$memoryForStrategy,false,$nextEvidence)['strategy']==='style',
    'An earlier style outcome cannot disqualify a new operation under that strategy');
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
expect(syndamorph_surgery_load($parentCase['surgery']['workingPlanId'])['plan']===json_decode($parentCase['outputs']['developer'],true),
    'QA surgery did not persist an accepted revision');
expect(count($GLOBALS['generations'])===7,'Parent-owned issue unexpectedly retried or regenerated a full component');
expect(!str_contains(wp_json_encode($GLOBALS['generations'][5]['messages'][0]['content']),
    '"replacement"'),'QA model was prompted with full replacement rather than compact patch');
unset($GLOBALS['parent_issue_test']);
$GLOBALS['native_fixture']=$fixtureBeforeParent;
// The scheduler must not terminate QA merely because a component failed three times.
// Repeated attempts are bounded by the configured cost admission, not a fixed count.
expect(str_contains(file_get_contents(dirname(__DIR__).'/plugin/includes/production-collaboration.php'),
    "for(\$round=1;\$best['issues'];\$round++)"),'Budget-driven QA loop missing');
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
$alignReceipt=array('attempt'=>5,'status'=>'verified','field'=>'blocks[0].children[0].align',
    'message'=>'Alignment belongs inside style.align.','block'=>'heading','route'=>'auto_repair',
    'compilerRevision'=>syndamorph_learning_revision(),'artifactSha256'=>hash('sha256',$styled['content']));
$historyEvents=array(
    array('type'=>'schema_repair','data'=>array('attempt'=>1,'status'=>'required','field'=>'blocks[0].style.fontSize','message'=>'Invalid length.')),
    array('type'=>'schema_repair','data'=>array('attempt'=>1,'status'=>'resolved','field'=>'blocks[0].style.fontSize')),
    array('type'=>'schema_repair','data'=>$alignReceipt)
);
expect(syndamorph_learning_bootstrap($historyEvents,'94')===1,'Only fully verified current receipts must be indexed');
expect(syndamorph_learning_bootstrap($historyEvents,'94')===1,'Idempotent bootstrap should recognize same evidence');
$alignDiagnostic=array('field'=>'blocks[0].children[3].align','message'=>'Native block plan rejected: Alignment belongs inside style.align.');
expect(syndamorph_learning_retrieve('schema_repair','heading',3,$alignDiagnostic)===array('Place text alignment inside style.align.') &&
    syndamorph_learning_retrieve('schema_repair','button',3,$alignDiagnostic)===array() &&
    syndamorph_learning_retrieve('schema_repair','heading',3,array('field'=>'blocks[0].children[3].style.fontSize','message'=>'Invalid length.'))===array(),
    'Schema repair retrieval leaked a lesson from an unrelated block or diagnostic');
$rules=syndamorph_learning_retrieve('developer');
expect(count($rules)===2,'Historical verified lessons were not indexed');
syndamorph_learning_capability(array('Reference contains elements outside current native block schema.'),'test:capability');
$snapshot=syndamorph_learning_snapshot();
expect($snapshot['counts']['capability']===1&&$snapshot['counts']['observed']>=1,'Capability gap classification missing');
expect(!in_array('Images, lists, tables and vector elements need an explicit compiler capability before native export.',syndamorph_learning_retrieve('developer'),true),'Capability observation injected into agent prompt');
$staleRule=syndamorph_learning_rows()[$lessonId];
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
$groupAlignment=$alignReceipt;
$groupAlignment['attempt']=6;
$groupAlignment['field']='blocks[0].children[2].contentAlignment';
$groupAlignment['message']='Native block plan rejected: Unsupported node field.';
$groupAlignment['block']='group';
expect(syndamorph_learning_compiler_receipt($groupAlignment,'job:95:schema:6')!==null &&
    syndamorph_learning_retrieve('schema_repair','group',3,$groupAlignment)===
        array('Place Group contentAlignment inside style alongside contentWidth.') &&
    syndamorph_learning_retrieve('schema_repair','heading',3,$groupAlignment)===array(),
    'Verified Group contentAlignment correction failed scoped retrieval');
$layoutAssignment=array('focus'=>array(array('category'=>'alignment')),
    'group'=>array('alignment|section'),'node'=>array('type'=>'group'),'nodes'=>array('section','title'),'mode'=>'adaptive');
$hybridPatch=array('changes'=>array(array('path'=>'style.contentAlignment','value'=>'left')),
    'wraps'=>array(array('parent'=>'section','children'=>array('title'))));
$measuredVerdict=array('accepted'=>true,'geometryDeltas'=>array(),'states'=>array(),
    'focusBefore'=>8,'focusAfter'=>4,'pixelDeltas'=>array(-0.01));
$ordinaryId=syndamorph_learning_operation($layoutAssignment,$hybridPatch,$measuredVerdict,'test:container');
$constrained=$layoutAssignment;
$constrained['node']['style']['contentWidth']='40rem';
$constrainedId=syndamorph_learning_operation($constrained,$hybridPatch,$measuredVerdict,'test:constrained');
expect($ordinaryId!==null && $constrainedId!==null && $ordinaryId!==$constrainedId &&
    array_column(syndamorph_learning_match_operation($layoutAssignment),'id')===array($ordinaryId) &&
    array_column(syndamorph_learning_match_operation($constrained),'id')===array($constrainedId),
    'Verified hybrid evidence must stay available for its original layout across jobs');
$layoutAssignment['mode']='style';
expect(syndamorph_learning_match_operation($layoutAssignment)===array(),
    'Hybrid learning leaked into an assignment without structural authority');
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
    !$designPending['quality']['bossApproved']&&$designPending['quality']['escalation']['decision']==='pending'&&
    $designPending['quality']['escalation']['owner']==='orchestrator'&&
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
$visibleEvidence=array_map(static function($sample){
    $sample['snapshot']['elements']=array(array('tag'=>'SECTION','rect'=>array(0,0,$sample['width'],100),'styles'=>array()));
    return $sample;
},$fixtureEvidence);
$GLOBALS['inspection_failures']=array(inspection_reply(array('evidence'=>$visibleEvidence)),inspection_reply(array('evidence'=>$duplicate)));
$nativePending=syndamorph_collab_run('Keep the compiled native draft if inspection is pending.',false,null);
expect($nativePending['stageStatus']['design']==='approved'&&$nativePending['stageStatus']['compilation']==='passed'&&
    $nativePending['stageStatus']['browserQa']==='pending'&&$nativePending['checks']['valid_plan']===true&&
    isset($nativePending['artifact']['contentSha256'],$nativePending['surgery']['workingPlanId'],$nativePending['referenceCheckpoint'])&&
    $nativePending['quality']['escalation']['decision']==='continue_independent'&&count($GLOBALS['generations'])===5,
    'Pending native inspection did not retain independent valid output and usage');
unset($GLOBALS['inspection_escalation_test']);
unset($GLOBALS['revision_test']);
echo "PASS: inspection diagnostics, stale runtime gate, bounded retry and saved reference recovery\n";


$negativeClamp=syndamorph_collab_ids(array('version'=>1,'blocks'=>array(array('type'=>'group',
    'style'=>array('padding'=>array('left'=>'clamp(20px,-25.71px + 5.952vw,60px)','right'=>'clamp(20px,-25.71px + 5.952vw,60px)')),
    'children'=>array(array('type'=>'heading','text'=>'Preserved heading'))))));
$badClamp=syndamorph_surgery_verify($negativeClamp,$negativeClamp);
$clampCorrection=syndamorph_compiler_auto_repair($negativeClamp,array('code'=>$badClamp->get_error_code(),
    'field'=>$badClamp->get_error_data()['field'],'message'=>$badClamp->get_error_message()));
expect(!empty($clampCorrection['compiled']) &&
    $clampCorrection['plan']['blocks'][0]['style']['padding']['left']==='clamp(20px,5.952vw - 25.71px,60px)' &&
    $clampCorrection['plan']['blocks'][0]['children']===$negativeClamp['blocks'][0]['children'],
    'A CSS-equivalent signed addition required a paid schema repair or changed protected content');

$sourceFixture=array('url'=>'https://example.org/welcome','manifestSha256'=>str_repeat('b',64),'section'=>array(
    'id'=>str_repeat('c',24),'label'=>'Welcome','sourceRef'=>'#welcome','kind'=>'content','evidenceSha256'=>str_repeat('d',64),
    'evidence'=>array_map(static fn($width)=>array('width'=>$width,'screenshot'=>inspection_fixture_jpeg($width),
        'copy'=>'Welcome & enjoy','bounds'=>array(0,100,$width,150),'links'=>array(),'images'=>array(),
        'controls'=>array(),'backgrounds'=>array()),array(1440,768,375))));
expect(syndamorph_production_source_valid($sourceFixture),'Responsive source fixture was not validated');
$sourceHandle=str_repeat('e',32);
set_transient('sm_source_'.$sourceHandle,array('user'=>11,'source'=>$sourceFixture),60);
expect(syndamorph_production_source_load($sourceHandle,'gutenberg')===$sourceFixture &&
    is_wp_error(syndamorph_production_source_load($sourceHandle,'html')),'Capture is not scoped to native production');
$GLOBALS['source_fixture_user']=12;
expect(is_wp_error(syndamorph_production_source_load($sourceHandle,'gutenberg')),'Capture handle crossed administrator identity');
unset($GLOBALS['source_fixture_user']);

$truncated=$sourceFixture;
array_pop($truncated['section']['evidence']);
expect(!syndamorph_production_source_valid($truncated),'A missing viewport passed source admission');
$archive=array('version'=>1,'format'=>'singlefile-2.27.3','sourceUrl'=>$sourceFixture['url'],'views'=>array());
$archiveIdentity=array(1,$archive['format'],$archive['sourceUrl']);
foreach(array(1440,768,375) as $width){
    $digests=array_map(static fn($part)=>hash('sha256',$width.'-'.$part),array('dom','baseline','html','replay'));
    $archive['views'][]=array('width'=>$width,'viewportHeight'=>900,'scrollWidth'=>$width,'pageHeight'=>920,
        'verified'=>true,'javascriptEnabled'=>false,'externalRequests'=>0,'pixelDifferenceRatio'=>0.001,
        'copySha256'=>hash('sha256','Welcome & enjoy'))+
        array_combine(array('sourceDOMSha256','sourceScreenshotSha256','archiveSha256','replayScreenshotSha256'),$digests);
    $archiveIdentity[]=array_merge(array($width),$digests);
}
$archive['id']=hash('sha256',json_encode($archiveIdentity,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
expect(syndamorph_production_source_valid($sourceFixture+array('referenceArchive'=>$archive)),
    'Complete archive receipt did not pass pinned source admission');
$badArchive=$archive;
$badArchive['views'][2]['externalRequests']=1;
expect(!syndamorph_production_source_valid($sourceFixture+array('referenceArchive'=>$badArchive)),
    'An archive which requested an external asset passed source admission');
$badArchive=$archive;
$badArchive['views'][1]['archiveSha256']=hash('sha256','different HTML');
expect(!syndamorph_production_source_valid($sourceFixture+array('referenceArchive'=>$badArchive)),
    'A replaced archive did not invalidate the receipt');
expect(!syndamorph_production_reference_valid($archive,'https://other.example/site'),
    'Archive receipt could be reused for a different source');
$GLOBALS['source_fixture_copy']='Welcome & enjoy';
foreach(array(0.0,0.02) as $ratio){
    // Source/Designer and Designer/native agree. Only the final source/native
    // comparison varies here so it still exercises independent native QA.
    $GLOBALS['source_fixture_comparisons']=array(0.0,0.0,$ratio);
    $GLOBALS['generations']=array();
    $sourceJob=syndamorph_collab_run('Convert the selected welcome source without adding content.',false,null,false,$sourceFixture);
    expect(is_array($sourceJob) && $sourceJob['sourceQuality']['literalCopy']['status']==='equal' &&
        $sourceJob['sourceQuality']['artifactSha256']===$sourceJob['artifact']['contentSha256'] &&
        ($sourceJob['sourceQuality']['issues']===array())===($ratio===0.0) &&
        ($sourceJob['status']==='uncompleted')===($ratio!==0.0),
        'Independent source gate failed: '.wp_json_encode(is_wp_error($sourceJob)?
            array($sourceJob->get_error_code(),$sourceJob->get_error_message(),$sourceJob->get_error_data()):
            array($sourceJob['status'],$sourceJob['sourceQuality']??null,$sourceJob['quality']['terminationReason']??null)));
    foreach(array(0,1,2,3) as $callIndex){
        $content=$GLOBALS['generations'][$callIndex]['messages'][0]['content'];
        $context=json_decode($content[0]['text'],true);
        expect(($context['capturedSource']['identity']['id']??null)===str_repeat('c',24) &&
            count($content)>=4,'A production role did not receive the pinned source evidence');
    }
}
$GLOBALS['source_fixture_ratio']=0.02;
$GLOBALS['generations']=array();
$visualBlocked=syndamorph_collab_run('Reject designs that visibly differ from the selected source.',false,null,false,$sourceFixture);
expect(is_wp_error($visualBlocked)&&$visualBlocked->get_error_code()==='sm_source_fidelity'&&count($GLOBALS['generations'])===3,
    'A known manual source visual defect reached Developer generation');
$GLOBALS['source_fixture_copy']='Changed source copy';
$GLOBALS['generations']=array();
$copyBlocked=syndamorph_collab_run('Reject even an approved design that changes captured copy.',false,null,false,$sourceFixture);
expect(is_wp_error($copyBlocked) && $copyBlocked->get_error_code()==='sm_source_copy' &&
    count($GLOBALS['generations'])===3,'A source-copy mismatch reached Developer generation');
unset($GLOBALS['source_fixture_copy'],$GLOBALS['source_fixture_ratio'],$GLOBALS['source_fixture_comparisons']);
echo "PASS: pinned three-viewport URL evidence, source-copy protection and independent visual gate\n";

putenv('SYNDAMORPH_BACKEND_URL=http://backend:3100/capture');
putenv('SYNDAMORPH_BACKEND_TOKEN=touch-fixture-token');
$archive['runtimeSha256']=hash('sha256','fixture runtime');
$archive['id']=hash('sha256',json_encode(array_merge($archiveIdentity,array($archive['runtimeSha256'])),
    JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$GLOBALS['source_fixture_copy']='Welcome & enjoy';
$GLOBALS['source_fixture_ratio']=0.0;
$GLOBALS['team_touch_fixture']=true;
$GLOBALS['touch_inputs']=array();
$GLOBALS['generations']=array();
$touchEvents=array();
$touched=syndamorph_collab_run('Preserve a sourced section and inspect the local menu.',false,
    static function($event,$data)use(&$touchEvents){if($event==='touch_inspection')$touchEvents[]=$data;},
    false,$sourceFixture+array('referenceArchive'=>$archive));
expect(is_array($touched) && count($GLOBALS['touch_inputs'])===2 && count($touchEvents)===2,
    'Boss planning did not call the real shared Touch adapter contract');
expect($GLOBALS['touch_inputs'][1]===array('archiveId'=>$archive['id'],'width'=>375,
    'actions'=>array(array('type'=>'click','target'=>str_repeat('f',24)))),
    'Touch request used an unpinned identifier or unsupported browser input');
expect(($touched['designApproval']['touchScenarios'][0]['status']??null)==='observed' &&
    ($touched['designApproval']['touchScenarios'][0]['archiveId']??null)===$archive['id'] &&
    ($touched['designApproval']['touchScenarios'][0]['action']['target']??null)===str_repeat('f',24) &&
    ($touched['designApproval']['touchScenarios'][0]['remoteEffects']??null)==='unverified' &&
    str_contains(wp_json_encode($touchEvents[1]),'private-before') &&
    !str_contains(wp_json_encode($GLOBALS['generations'][2]),'private-before'),
    'Archived screenshots or observed-state limitations leaked into the wrong output');
expect(!isset(syndamorph_production_tool_catalog('designer',array('sourceArchive'=>$archive))['playwright.touch']) &&
    !isset(syndamorph_production_tool_catalog('boss')['playwright.touch']),
    'Touch was exposed without Boss ownership and a pinned source');
$gesture=array('type'=>'scroll-x','target'=>str_repeat('f',24),'dx'=>640);
$horizontal=syndamorph_production_touch(array('width'=>375,'actions'=>array($gesture)),$archive);
expect(!is_wp_error($horizontal)&&end($GLOBALS['touch_inputs'])['actions']===array($gesture),
    'The WordPress adapter changed the authorized horizontal target or delta.');
$gesture['dx']=901;
expect(is_wp_error(syndamorph_production_touch(array('width'=>375,'actions'=>array($gesture)),$archive))&&
    count($GLOBALS['touch_inputs'])===3,'Unbounded local scroll reached the browser.');
unset($GLOBALS['source_fixture_copy'],$GLOBALS['source_fixture_ratio'],$GLOBALS['team_touch_fixture'],$GLOBALS['touch_inputs']);
echo "PASS: pinned Boss Touch receipts, safe registry grants and downstream scenario handoff\n";

foreach(array('escape','rollback') as $case){
    $GLOBALS['responsive_design_test']=$case;
    $GLOBALS['generations']=array();
    $GLOBALS['responsive_probe_widths']=array();
    $surgeryEvents=array();
    $result=syndamorph_collab_run('Build the responsive welcome section.',false,
        static function($event,$data)use(&$surgeryEvents){if($event==='design_surgery')$surgeryEvents[]=$data;},false);
    expect(in_array(900,array_merge(...$GLOBALS['responsive_probe_widths']),true),'An intermediate width was never inspected');
    if($case==='escape'){
        expect(is_wp_error($result)&&$result->get_error_code()==='sm_design_responsive'&&
            $result->get_error_data()['findings'][0]['width']===900&&count($GLOBALS['generations'])===3,
            'A false approval bypassed the intermediate responsive gate');
    }else{
        expect(is_array($result)&&in_array('rolled_back',array_column($surgeryEvents,'status'),true)&&
            str_contains($result['referenceHtml'],'--test-overflow:0')&&
            $result['designApproval']['continuousResponsive']['passed'],
            'A regressing surgical candidate displaced the verified reference');
    }
}
unset($GLOBALS['responsive_design_test'],$GLOBALS['responsive_probe_widths']);
echo "PASS: intermediate responsive gate and Designer surgical rollback\n";

foreach(array('pass','false-approval') as $case){
    $GLOBALS['design_requirement_test']=$case;
    $GLOBALS['source_fixture_copy']='Welcome & enjoy';
    $GLOBALS['generations']=array();
    $result=syndamorph_collab_run('Preserve the required headline.',false,null,false);
    if($case==='pass'){
        expect(is_array($result)&&$result['designApproval']['requirementResults']['checks'][0]['owner']==='browser'&&
            $result['designApproval']['requirementResults']['checks'][0]['status']==='verified',
            'A matching measured work-order check did not reach the implementation handoff');
        $reviewInputs=json_decode($GLOBALS['generations'][2]['messages'][0]['content'][0]['text'],true);
        expect(($reviewInputs['applicationChecks'][0]['status']??null)==='verified',
            'The reviewer did not receive the browser-owned requirement verdict.');
    }else{
        expect(is_wp_error($result)&&$result->get_error_code()==='sm_design_requirements'&&
            $result->get_error_data()['requirements']['blocking']===array('exact-pinned-headline')&&
            count($GLOBALS['generations'])===3,'A model self-approval bypassed a measured work-order failure');
    }
}
unset($GLOBALS['design_requirement_test'],$GLOBALS['source_fixture_copy']);
echo "PASS: browser-owned requirement verdicts across real pipeline handoffs\n";

foreach(array('overflow'=>'failed','missing'=>'pending') as $injection=>$expected){
    $GLOBALS['native_responsive_test']=$injection;
    $GLOBALS['generations']=array();
    $result=syndamorph_collab_run('Build a native section and verify intermediate widths.',false,null,false);
    expect(is_array($result)&&$result['status']==='uncompleted'&&$result['stageStatus']['responsive']===$expected&&
        $result['quality']['continuousResponsive']['artifactSha256']===$result['artifact']['contentSha256']&&
        !syndamorph_native_responsive_acceptance($result,$result['artifact']['contentSha256'])&&
        count($GLOBALS['generations'])===5,
        'Incomplete native responsive QA lost the draft or advanced the affected quality gate.');
    if($expected==='failed'){
        expect($result['quality']['continuousResponsive']['findings'][0]['width']===900,
            'Native responsiveness ignored an intermediate width.');
    }else{
        unset($GLOBALS['native_responsive_test']);
        $rechecked=syndamorph_native_responsive_recheck($result);
        expect(is_array($rechecked)&&$rechecked['status']==='passed'&&count($GLOBALS['generations'])===5,
            'Responsive recheck failed to recover the saved artifact without provider calls.');
    }
}
unset($GLOBALS['native_responsive_test']);
echo "PASS: independent native responsive acceptance and deterministic recovery\n";

$sample=static fn(array $rect,string $padding):array=>array('width'=>1440,'snapshot'=>array(
    'elements'=>array(array('visible'=>true,'classes'=>array('column'),'rect'=>$rect,'styles'=>array('padding'=>$padding)))));
$placement=array('kind'=>'center','selector'=>'.column');
$padding=array('kind'=>'style','selector'=>'.column','field'=>'paddingBottom','value'=>'40px');
$golden=$sample(array(384,50,672,95),'12px 18px 40px 20px');
$shifted=$sample(array(48,50,672,95),'16px');
expect(syndamorph_design_run_check($placement,array($golden))['status']==='verified'&&
    syndamorph_design_run_check($placement,array($shifted))['status']==='failed'&&
    syndamorph_design_run_check($padding,array($golden))['status']==='verified'&&
    syndamorph_design_run_check($padding,array($shifted))['status']==='failed',
    'Direct geometry checks did not distinguish centered placement or vertical padding.');
echo "PASS: direct centered-placement and vertical-padding requirement checks\n";

$tabRequirements=array(
    array('id'=>'initial-tab','dimension'=>'functional'),
    array('id'=>'keyboard-tab','dimension'=>'functional'),
    array('id'=>'styled-tab','dimension'=>'visual'),
    array('id'=>'accessible-tab','dimension'=>'accessibility'),
    array('id'=>'visual-identity','dimension'=>'visual'),
);
$tabOrder=array('version'=>1,'title'=>'Tab proof fixture','sections'=>array(array('id'=>'hero','label'=>'Hero')),
    'requirements'=>array_map(static fn($requirement)=>$requirement+array('target'=>'hero','basis'=>'brief',
        'requirement'=>'Required behavior','acceptance'=>'Observable condition','check'=>null),$tabRequirements),
    'interactions'=>array(array('id'=>'hotel-tabs','kind'=>'tabs','classification'=>'reusable',
        'requirementId'=>'initial-tab','behavior'=>'Two panels and an active tab.',
        'proof'=>array(array('requirementId'=>'initial-tab','check'=>'tab-initial'),
            array('requirementId'=>'keyboard-tab','check'=>'tab-keyboard'),array('requirementId'=>'styled-tab','check'=>'tab-style'),
            array('requirementId'=>'accessible-tab','check'=>'tab-accessibility')))));
$normalized=syndamorph_production_specification($tabOrder);
expect(!is_wp_error($normalized),'Tabbed qualification was not accepted.');
$tabGate=syndamorph_design_requirements($normalized,array(),array(),array(),array());
expect($tabGate['blocking']===array('visual-identity')&&$tabGate['checks'][2]['owner']==='implementation_qa',
    'The design gate bypassed an ordinary visual requirement or demanded compiled tab proof too early.');
unset($tabOrder['interactions'][0]['requirementId']);
$normalized=syndamorph_production_specification($tabOrder);
expect(!is_wp_error($normalized)&&$normalized['interactions'][0]['requirementId']==='initial-tab',
    'The explicit functional proof did not normalize a missing legacy primary requirement field.');
$tabOrder['interactions'][0]['proof'][2]['check']='tab-keyboard';
expect(is_wp_error(syndamorph_production_specification($tabOrder)), 'A tab proof could satisfy an unrelated quality dimension.');
echo "PASS: separate design and native proof ownership for multiple tab requirements\n";

$complete=wp_json_encode(array('version'=>1,'blocks'=>array(array('type'=>'group','children'=>array(
    array('type'=>'heading','text'=>'A brace } inside the heading.'))))));
$truncated=substr($complete,0,-2);
$expected=syndamorph_decode_developer_plan($complete,$normal);
$recovered=syndamorph_decode_developer_plan($truncated,$boundaryRepair);
expect($normal===null&&$expected===$recovered&&$boundaryRepair['appended']===']}'&&
    $boundaryRepair['beforeSha256']===hash('sha256',$truncated)&&$boundaryRepair['afterSha256']===hash('sha256',$complete),
    'Outer-plan recovery changed content or failed to prove the exact delimiter repair.');
expect(syndamorph_decode_developer_plan(substr($complete,0,-3),$invalidRepair)===null&&$invalidRepair===null,
    'A partial root block was guessed.');
echo "PASS: bounded outer Developer JSON recovery preserves every authored field\n";

require_once dirname(__DIR__).'/plugin/includes/production-scope-source.php';
$sourceTrial=static function(array $ratios,bool $mobileLinks):array{
    $visual=array();$markup=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $index=>$width){
        $visual[]=array('width'=>$width,'pixelDifferenceRatio'=>$ratios[$index],'heightPassed'=>true,
            'passed'=>$ratios[$index]<=0.015,'nativeHeight'=>400);
        $markup[]=array('width'=>$width,'literalContent'=>true,'links'=>$width!==375||$mobileLinks,
            'assets'=>true,'functions'=>true);
    }
    return array('sourceSha256'=>str_repeat('a',64),'passed'=>false,'visual'=>array('views'=>$visual),
        'markup'=>array('views'=>$markup));
};
$before=$sourceTrial(array(0.009,0.026,0.052),false);
$improved=$sourceTrial(array(0.009,0.021,0.044),true);
$comparison=syndamorph_source_surgery_comparison($before,$improved);
expect($comparison['improved']&&empty($comparison['regressions'])&&!$improved['passed'],
    'A localized improvement must be retained without treating it as approved source fidelity.');
$moved=$sourceTrial(array(0.009,0.018,0.053),false);
$comparison=syndamorph_source_surgery_comparison($before,$moved);
expect($comparison['improved']&&$comparison['regressions']===array(array(
    'code'=>'source-pixel-difference-increased','width'=>375,'target'=>'section')),
    'Aggregate improvement masked a worsened viewport that was already held.');
$regressed=$sourceTrial(array(0.016,0.013,0.031),false);
$regressed['visual']['views'][2]['heightPassed']=false;
$comparison=syndamorph_source_surgery_comparison($improved,$regressed);
expect(array_column($comparison['regressions'],'code')===array('source-visual-gate-regressed',
    'source-height-gate-regressed','source-markup-gate-regressed'),
    'A lower aggregate difference must preserve any verified source width, height and links.');
$missing=$improved;
array_pop($missing['visual']['views']);
expect(in_array('source-width-evidence-lost',array_column(
    syndamorph_source_surgery_comparison($before,$missing)['regressions'],'code'),true),
    'Missing source evidence must roll surgery back.');
expect(array_column(syndamorph_source_surgery_comparison($improved,$improved)['regressions'],'code')===
    array('source-surgery-no-improvement'),'Unchanged source evidence must not consume another repair round.');
$copy=syndamorph_source_copy_difference('Exclusive Offers Full intro and footer Stay Offer','EXCLUSIVE OFFERS Full intro and footer STAY OFFER');
expect($copy['kind']==='case_only'&&array_column($copy['caseDifferences'],'wordIndex')===array(0,1,6,7)&&
    array_column($copy['caseDifferences'],'expected')===array('Exclusive','Offers','Stay','Offer'),
    'Exact copy evidence confused changed capitalization with missing introduction or footer text.');
$copy=syndamorph_source_copy_difference('Start with the full introduction and footer','Start with and footer');
expect($copy['kind']==='literal_mismatch'&&$copy['expected']==='the full introduction'&&$copy['observed']===''&&
    syndamorph_source_copy_difference('Known source',null)===array('kind'=>'missing_evidence'),
    'Copy diagnostics did not distinguish missing words from missing browser evidence.');
echo "PASS: source surgery preserves per-width evidence and ranks incomplete improvements\n";

$root=array('node'=>'root','rect'=>array(0,0,900,300));
$column=array('parent'=>0,'rect'=>array(115,20,670,250),'styles'=>array('padding'=>'0px'));
$referenceElements=array($root,$column,array('parent'=>1),array('parent'=>2));
expect(syndamorph_repair_reference_frame($referenceElements,array(array('reference'=>array('parent'=>3)),
    array('reference'=>array('parent'=>1))))===$column,'Nested interactive text must use its nearest shared layout frame.');
expect(syndamorph_repair_reference_frame($referenceElements,array(array('reference'=>array('parent'=>3)),
    array('reference'=>array('parent'=>4))))===null,'Incomplete ancestry must not imply a shared frame.');
$tabs=array('type'=>'tabs','id'=>'hotel-tabs','panels'=>array(array('id'=>'stay','body'=>'Visible'),
    array('id'=>'arrival','body'=>'Hidden until selected')));
$changed=$tabs;$changed['panels'][1]['body']='Changed hidden copy';
expect(syndamorph_repair_content_signature($tabs)!==syndamorph_repair_content_signature($changed),
    'Surgery must preserve inactive tab content and panel identity.');
echo "PASS: nested interactive layout evidence and content signatures\n";
