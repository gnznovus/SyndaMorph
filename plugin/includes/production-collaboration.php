<?php
/** Budgeted dual-artifact Gutenberg production. HTML-only jobs use the legacy pipeline. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/production-job-memory.php';
require_once __DIR__.'/production-learning.php';
require_once __DIR__.'/production-repair.php';

function syndamorph_collab_cost(array $usage, float $input, float $output): float {
    return ((($usage['inputTokens'] ?? 0)+($usage['cacheReadTokens']??0)*0.1+($usage['cacheWriteTokens']??0)*1.25) * $input + ($usage['outputTokens'] ?? 0) * $output) / 1000000;
}
function &syndamorph_collab_find(array &$nodes, string $id, string $path = 'blocks') {
    foreach ($nodes as $index => &$node) {
        if (!is_array($node)) { continue; }
        $current = $path . '[' . $index . ']';
        if (($node['id'] ?? $current) === $id) { return $node; }
        if (isset($node['children']) && is_array($node['children'])) {
            $found = &syndamorph_collab_find($node['children'], $id, $current . '.children');
            if ($found !== null) { return $found; }
        }
    }
    $missing=null; return $missing;
}
function syndamorph_collab_ids(array $plan): array {
    $walk = static function (array &$nodes, string $path) use (&$walk): void {
        foreach ($nodes as $i => &$node) {
            $here = $path . '[' . $i . ']';
            if (!isset($node['id'])) { $node['id'] = $here; }
            if (isset($node['children']) && is_array($node['children'])) { $walk($node['children'], $here . '.children'); }
        }
    };
    $walk($plan['blocks'], 'blocks');
    return $plan;
}
function syndamorph_collab_validate_ids(array $plan) {
    $ids=array();$duplicate=false;
    $walk=static function(array $nodes)use(&$walk,&$ids,&$duplicate):void {
        foreach($nodes as $node) {
            if(!is_array($node)||!is_string($node['id']??null)||isset($ids[$node['id']])){$duplicate=true;return;}
            $ids[$node['id']]=true;
            if(is_array($node['children']??null))$walk($node['children']);
        }
    };
    $walk($plan['blocks']??array());
    return $duplicate?new WP_Error('sm_component_identity','Duplicate or invalid component IDs in native plan.'):true;
}
/** Direct node fingerprints exclude children; parent style changes invalidate descendants. */
function syndamorph_collab_fingerprints(array $plan): array {
    $result=array();
    $walk=static function(array $nodes,?string $parent)use(&$walk,&$result):void {
        foreach($nodes as $node) {
            if(!is_array($node) || !is_string($node['id']??null)) continue;
            $own=$node;unset($own['children']);
            $result[$node['id']]=array('hash'=>hash('sha256',wp_json_encode($own)),'parent'=>$parent);
            if(is_array($node['children']??null)) $walk($node['children'],$node['id']);
        }
    };
    $walk($plan['blocks'],null);
    return $result;
}
function syndamorph_collab_impact(array $before,array $after): array {
    $old=syndamorph_collab_fingerprints($before);
    $new=syndamorph_collab_fingerprints($after);
    $changed=array();
    foreach(array_unique(array_merge(array_keys($old),array_keys($new))) as $id) {
        if(!isset($old[$id],$new[$id]) || $old[$id]['hash']!==$new[$id]['hash'] || $old[$id]['parent']!==$new[$id]['parent'])$changed[$id]=true;
    }
    $affected=$changed;
    // Only descendants of actually modified nodes are affected; sibling evidence is reusable.
    foreach($new as $id=>$item) {
        $parent=$item['parent'];
        while($parent!==null) {
            if(isset($changed[$parent])){$affected[$id]=true;break;}
            $parent=$new[$parent]['parent']??null;
        }
    }
    $reusable=array_values(array_diff(array_keys($new),array_keys($affected)));
    return array('changed'=>array_keys($changed),'affected'=>array_keys($affected),'reused'=>$reusable);
}
/** Only existing component IDs can be replaced; siblings and approved subtrees are retained. */
function syndamorph_collab_patch(array $plan, array $patch) {
    if (count($patch)!==2 || !array_key_exists('id',$patch) || !array_key_exists('replacement',$patch) || !is_string($patch['id']) || !is_array($patch['replacement'])) {
        return new WP_Error('sm_revision_patch','Expected {id,replacement}.');
    }
    $target = &syndamorph_collab_find($plan['blocks'], $patch['id']);
    if ($target === null) { return new WP_Error('sm_revision_target','Revision component ID does not exist.'); }
    if (($target['type'] ?? '') !== ($patch['replacement']['type'] ?? '') || ($patch['replacement']['id'] ?? '') !== $patch['id']) {
        return new WP_Error('sm_revision_identity','Revision must preserve component ID and block type.');
    }
    $before = $target;
    $target = $patch['replacement'];
    $unique=syndamorph_collab_validate_ids($plan);
    if(is_wp_error($unique)){$target=$before;return $unique;}
    $compiled = syndamorph_compile_block_plan(wp_json_encode($plan));
    if (is_wp_error($compiled)) { $target = $before; return $compiled; }
    return array('plan'=>$plan,'artifact'=>$compiled);
}
function syndamorph_collab_inspect(string $html) {
    $endpoint = defined('SYNDAMORPH_RENDERER_INSPECT_URL') ? SYNDAMORPH_RENDERER_INSPECT_URL : 'http://renderer:3000/inspect';
    // Network target is configured by deployment, never read from model output.
    if (!is_string($endpoint) || !preg_match('~^http://[a-zA-Z0-9.-]+:[0-9]{2,5}/inspect$~D', $endpoint)) {
        return new WP_Error('sm_inspect_config','Invalid isolated renderer endpoint.');
    }
    $response = wp_remote_post($endpoint,array('timeout'=>75,'redirection'=>0,'headers'=>array('Content-Type'=>'application/json'),
        'body'=>wp_json_encode(array('html'=>$html)),'limit_response_size'=>9000000));
    if (is_wp_error($response)) { return new WP_Error('sm_inspect_unavailable','Browser inspection service unavailable.'); }
    if (wp_remote_retrieve_response_code($response)!==200) { return new WP_Error('sm_inspect_failed','Browser inspection service rejected the request.'); }
    $data=json_decode(wp_remote_retrieve_body($response),true);
    if (!is_array($data['evidence']??null) || count($data['evidence'])!==3) { return new WP_Error('sm_inspect_incomplete','Browser inspection evidence incomplete.'); }
    foreach($data['evidence'] as $sample) {
        if(!is_array($sample['a11y']??null)) return new WP_Error('sm_a11y_incomplete','Automated accessibility scan did not return evidence.');
    }
    return $data['evidence'];
}
/** Deterministic visual comparison reuses already captured screenshots. */
function syndamorph_collab_compare(array $reference,array $native) {
    $url=getenv('SYNDAMORPH_RENDERER_URL');
    if ($url !== 'http://renderer:3000/render') return new WP_Error('sm_compare_config','Isolated visual comparison service not configured.');
    $pick=static function(array $samples):array {return array_map(static fn($s)=>array('width'=>$s['width'],'screenshot'=>$s['screenshot']),$samples);};
    $response=wp_remote_post('http://renderer:3000/compare',array('timeout'=>20,'redirection'=>0,
        'headers'=>array('Content-Type'=>'application/json'),'body'=>wp_json_encode(array('reference'=>$pick($reference),'native'=>$pick($native))),
        'limit_response_size'=>50000));
    if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)return new WP_Error('sm_compare_unavailable','Pixel comparison is unavailable.');
    $data=json_decode(wp_remote_retrieve_body($response),true);
    if(!is_array($data['comparison']??null)||count($data['comparison'])!==3)return new WP_Error('sm_compare_invalid','Visual comparison evidence incomplete.');
    return $data['comparison'];
}
function syndamorph_collab_evidence(array $reference, array $native): array {
    $findings=array();
    foreach ([0,1,2] as $i) {
        $ref=$reference[$i]['snapshot']??array(); $out=$native[$i]['snapshot']??array();$width=(int)($native[$i]['width']??0);
        if (($out['overflow']??true)) $findings[] = array('id'=>'overflow-'.$width,'component'=>'blocks[0]','severity'=>'high','detail'=>'Native output has horizontal overflow at '.$width.'px.');
        foreach (($native[$i]['a11y']??array()) as $violation) {
            $rule=sanitize_key((string)($violation['id']??'unknown'));
            if(in_array($rule,array('document-title','html-has-lang','html-lang-valid','landmark-one-main','region','page-has-heading-one'),true))continue; // host page responsibilities
            $findings[]=array('id'=>'axe-'.$rule.'-'.$width,'component'=>'blocks[0]','severity'=>'high',
                'detail'=>'axe-core '.($violation['impact']??'issue').': '.mb_substr((string)($violation['description']??$rule),0,200));
        }
        foreach (($out['elements']??array()) as $index=>$el) {
            if(!empty($el['clipped'])) {
                $findings[]=array('id'=>'clip-'.$width.'-'.$index,'component'=>$el['id']?:'blocks[0]','severity'=>'high','detail'=>'Content is clipped at '.$width.'px.');
            }
            if (!empty($el['missingAlt']) || !empty($el['unnamedControl'])) {
                $findings[] = array('id'=>'a11y-'.$width.'-'.$index,'component'=>$el['id']?:'blocks[0]','severity'=>'high','detail'=>'Element has missing accessible name or image alt.');
            }
        }
        $a=$ref['elements']??array();$b=$out['elements']??array();
        if ($a && $b) {
            $ra=$a[0]['rect']??array();$rb=$b[0]['rect']??array();
            if (count($ra)===4 && count($rb)===4 && abs($ra[3]-$rb[3])>max(80,$ra[3]*0.3)) {
                $findings[] = array('id'=>'height-'.$width,'component'=>'blocks[0]','severity'=>'medium','detail'=>'Root section height differs substantially at '.$width.'px.');
            }
        }
    }
    return $findings;
}
/** Compiler-required checks are blocking QA findings, not informational badges. */
function syndamorph_collab_block_issues(array $artifact): array {
    $issues=array();
    foreach($artifact['checks']??array() as $key=>$passed) {
        if($passed!==true) $issues[]=array('id'=>'native-'.sanitize_key((string)$key),'component'=>'blocks[0]',
            'severity'=>'high','detail'=>'Mandatory native block check failed: '.$key);
    }
    return $issues;
}
/** Map a rendered leaf to its native-plan owner, without injecting custom block markup. */
function syndamorph_collab_owner(array $nodes, string $text, string $fallback='blocks[0]'): string {
    foreach($nodes as $node) {
        if (!is_array($node)) continue;
        if (isset($node['text']) && trim((string)$node['text'])===$text) return (string)($node['id']??$fallback);
        if (isset($node['children']) && is_array($node['children'])) {
            $found=syndamorph_collab_owner($node['children'],$text,'');
            if ($found!=='') return $found;
        }
    }
    return $fallback;
}
/** Reusable measured evidence. Only matching leaf text is compared; mismatches are explicit. */
function syndamorph_collab_style_findings(array $reference,array $native,array $plan): array {
    $issues=array();
    foreach([0,1,2] as $i) {
        $width=(int)($native[$i]['width']??0);
        $ref=array();
        foreach(($reference[$i]['snapshot']['elements']??array()) as $el) {
            $text=trim((string)($el['text']??''));
            if ($text!=='') $ref[$text]=$el;
        }
        foreach(($native[$i]['snapshot']['elements']??array()) as $el) {
            $text=trim((string)($el['text']??''));
            if ($text==='' || !isset($ref[$text])) continue;
            $source=$ref[$text];$changes=array();
            foreach(array('fontSize','fontWeight','color','letterSpacing') as $key) {
                if (($source['styles'][$key]??null)!==($el['styles'][$key]??null)) $changes[]=$key;
            }
            if (count($source['rect']??array())===4 && count($el['rect']??array())===4) {
                if (abs($source['rect'][2]-$el['rect'][2])>max(30,$source['rect'][2]*0.35)) $changes[]='width';
            }
            if($changes) {
                $id=syndamorph_collab_owner($plan['blocks'],$text);
                $issues[]=array('id'=>'style-'.substr(hash('sha256',$width.$text),0,12),'component'=>$id,
                    'severity'=>'medium','detail'=>'Measured '.implode(', ',$changes).' differs from HTML reference at '.$width.'px for '.mb_substr($text,0,70));
            }
        }
    }
    return array_slice($issues,0,50);
}
/** Use matching visible text to locate affected regions in both cached images. Fall back to full evidence if matching is ambiguous. */
function syndamorph_collab_scoped_images(array $reference,array $native,array $texts): array {
    $items=array();$full=array();
    foreach(array(0,2) as $viewport) foreach(array($reference,$native) as $samples) {
        $sample=$samples[$viewport];$full[]=array('media_type'=>'image/jpeg','data'=>$sample['screenshot']);
        $rects=array();
        foreach($sample['snapshot']['elements']??array() as $el) {
            if(in_array(trim((string)($el['text']??'')),$texts,true)&&count($el['rect']??array())===4)$rects[]=$el['rect'];
        }
        if(!$rects)continue;
        $left=min(array_column($rects,0));$top=min(array_column($rects,1));
        $right=max(array_map(static fn($r)=>$r[0]+$r[2],$rects));$bottom=max(array_map(static fn($r)=>$r[1]+$r[3],$rects));
        $items[]=array('screenshot'=>$sample['screenshot'],'rect'=>array($left,$top,$right-$left,$bottom-$top));
    }
    if(count($items)!==4)return array('images'=>$full,'mode'=>'full');
    $response=wp_remote_post('http://renderer:3000/crop',array('timeout'=>20,'redirection'=>0,'headers'=>array('Content-Type'=>'application/json'),'body'=>wp_json_encode(array('items'=>$items)),'limit_response_size'=>2000000));
    if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)return array('images'=>$full,'mode'=>'full');
    $data=json_decode(wp_remote_retrieve_body($response),true);
    if(count($data['images']??array())!==4)return array('images'=>$full,'mode'=>'full');
    return array('images'=>$data['images'],'mode'=>'component');
}
function syndamorph_collab_run(string $brief,bool $stream,?callable $on_event) {
    $settings=syndamorph_models_settings();
    $provider=$settings['provider']??'';$model=$settings['model']??'';
    $catalog=syndamorph_models_catalog($provider);
    if (is_wp_error($catalog)) return $catalog;
    if (!in_array($model,array_column($catalog['models'],'id'),true)) return new WP_Error('sm_team_model','Select a supported AI model.');
    $in=(float)($settings['inputPrice']??0);$out=(float)($settings['outputPrice']??0);$max=(float)($settings['maxJobUsd']??0);
    if ($in<=0 || $out<=0 || $max<=0) return new WP_Error('sm_team_budget','Configure verified token rates and maximum job cost.');
    $usage=array('inputTokens'=>0,'outputTokens'=>0,'cacheReadTokens'=>0,'cacheWriteTokens'=>0);
    $metrics=array();$outputs=array();$events=array();$issues=array();$revisions=array();$evidence=array();$best=null;
    $learningSource='job:'.bin2hex(random_bytes(8));
    $started=microtime(true);$calls=0;$cutoff=$max*0.8;$memory=new SyndaMorph_Job_Memory($brief);
    if (function_exists('set_time_limit')) @set_time_limit(1200);
    $call=static function(string $role,string $instruction,array $context,int $cap,array $images=array(),string $stage='') use (&$usage,&$metrics,&$events,&$calls,$provider,$model,$in,$out,$max,$stream,$on_event) {
        $stage=$stage ?: $role;
        if(!syndamorph_agent_scope($role,$stage))return new WP_Error('sm_agent_scope','Task outside agent scope.');
        $cap=in_array($stage,array('boss','designer','repair','revision_validation','validation'),true)?min(16384,max(2048,$cap)):16384;
        $encoded=wp_json_encode($context);
        if (!is_string($encoded) || strlen($encoded)>160000) return new WP_Error('sm_team_context','Agent context exceeds safe limit.',array('stage'=>'context','role'=>$role,'task'=>$stage,'completedCalls'=>$calls));
        // Estimate actual context bytes conservatively and reserve the full output cap before a paid call.
        $prefix=syndamorph_production_cached_contract();
        $system=syndamorph_production_task_context($role,$stage)."\n".$instruction;
        $allowance=((ceil((strlen($encoded)+strlen($prefix)+strlen($system))/3)*1.25+count($images)*2000)*$in+$cap*$out)/1000000;
        if (syndamorph_collab_cost($usage,$in,$out)+$allowance>(in_array($stage,array('schema_repair','revision_validation','delegation','repair','design_correction'),true)?$max*0.8:$max)) return new WP_Error('sm_team_budget','No safe budget for another model call.',array('stage'=>'budget','role'=>$role,'task'=>$stage,'completedCalls'=>$calls));
        if ($on_event) $on_event('agent_start',array('role'=>$role,'task'=>$stage));
        $start=microtime(true);
        $result=syndamorph_models_generate($provider,$model,
            $system,
            $encoded,$cap,$stream,$on_event?static function(string $delta)use($on_event,$role,$stage){$on_event('agent_delta',array('role'=>$role,'task'=>$stage,'text'=>$delta));}:null,$images,$prefix);
        if (is_wp_error($result)) return new WP_Error($result->get_error_code(),$result->get_error_message(),array_merge((array)$result->get_error_data(),array('stage'=>'generation','role'=>$role,'task'=>$stage,'completedCalls'=>$calls)));
        foreach($usage as $key=>$_) $usage[$key]+=(int)($result['usage'][$key]??0);
        $calls++;
        $metric=array('role'=>$role,'task'=>$stage,'inputTokens'=>(int)$result['usage']['inputTokens'],'outputTokens'=>(int)$result['usage']['outputTokens'],
            'elapsedMs'=>(int)((microtime(true)-$start)*1000),'estimatedCostUsd'=>round(syndamorph_collab_cost($result['usage'],$in,$out),6),'outputCap'=>$cap,'cacheReadTokens'=>(int)($result['usage']['cacheReadTokens']??0),'cacheWriteTokens'=>(int)($result['usage']['cacheWriteTokens']??0),'contextBytes'=>strlen($encoded),'imageBytes'=>array_sum(array_map(static fn($img)=>strlen($img['data']??''),$images)));
        $metrics[]=$metric;$events[]=array('role'=>$role,'task'=>$stage,'status'=>'completed','model'=>$result['model']);
        if ($on_event) $on_event('agent_complete',array('role'=>$role,'task'=>$stage,'metric'=>$metric));
        return $result['text'];
    };
    $boss=$call('boss','First line must be Job title: <short title>. Then a compact requirements and component responsibility memo, at most 350 words. When copy is unspecified, authorize original generic marketing copy and a descriptive provisional local CTA; never require placeholder text or invent hotel facts. Do not generate HTML, CSS, native JSON, design tokens or validation reports; those belong to subsequent tasks.',array('brief'=>$brief),2500);
    if (is_wp_error($boss)) return $boss; $outputs['boss']=$boss;
    if ($on_event && preg_match('/Job title:\s*([^\r\n]+)/i',$boss,$match)) $on_event('job_title',array('title'=>trim($match[1])));
    $designer=$call('designer','Respect native capabilities in the shared contract; design only implementable native layouts. Return a compact design specification (at most 450 words): hierarchy, layout, palette, responsive tokens, typography and implementation limitations. Allow original generic copy without factual claims. Do not require placeholder labels. Do not generate code or a full criteria review.',array('brief'=>$brief,'boss'=>$boss),3500);
    if (is_wp_error($designer)) return $designer;$outputs['designer']=$designer;
    $reference=$call('developer','Produce one complete self-contained HTML section and scoped CSS using only design capabilities representable in the native contract. Use system-ui, matching native layout behavior and supported length expressions. Write finished generic headline, introduction and CTA copy where unspecified; no bracket placeholders. No Markdown fences. No JavaScript, external URLs, scripts or external assets. Return only HTML.',array('brief'=>$brief,'boss'=>$boss,'design'=>$designer),16384,array(),'reference');
    if (is_wp_error($reference)) return $reference;
    $reference=trim($reference);
    if(preg_match('/^```(?:html)?\s*([\s\S]*?)\s*```$/iD',$reference,$fenced))$reference=trim($fenced[1]);
    if (!preg_match('~<section\b~i',$reference) || preg_match('~<\s*(?:script|iframe|object|embed|form|link|meta)\b|\bon[a-z]+\s*=|\b(?:javascript:|https?://|@import)~i',$reference)) {
        return new WP_Error('sm_reference_unsafe','HTML design reference failed safety or structure checks.');
    }
    $outputs['reference']=$reference;
    $capabilityNotes=syndamorph_reference_capability_notes($reference);
    syndamorph_learning_capability($capabilityNotes,$learningSource.':capability');
    if($on_event&&$capabilityNotes)$on_event('capability_preflight',array('notes'=>$capabilityNotes));
    $inspectReference=syndamorph_collab_inspect($reference);
    if(is_wp_error($inspectReference))return $inspectReference;
    $referenceImages=array_map(static fn($s)=>array('media_type'=>'image/jpeg','data'=>$s['screenshot']),array($inspectReference[0],$inspectReference[2]));
    $developer=$call('developer','Translate the HTML reference into native Gutenberg JSON, preserving content and visual intent. Return JSON only. Use stable unique ids on each node, and do not invent unsupported styles. '.syndamorph_block_plan_contract(),
        array('brief'=>$brief,'design'=>$designer,'reference'=>$reference,'capabilityNotes'=>$capabilityNotes,'imageOrder'=>array('desktop-reference','mobile-reference'),'learnedRules'=>syndamorph_learning_retrieve('developer')),9000,$referenceImages);
    if (is_wp_error($developer)) return $developer;
    // Recover model-generated schema mistakes without restarting production.
    // Never bypass the compiler or send rejected markup to the renderer.
    $compilePlan=static function(string $raw) {
        $plan=json_decode(syndamorph_native_json_text($raw),true,32);
        if(!is_array($plan)||!is_array($plan['blocks']??null)) return new WP_Error('sm_block_plan_invalid','Expected valid native plan JSON.',array('field'=>'plan'));
        $plan=syndamorph_collab_ids($plan);
        $unique=syndamorph_collab_validate_ids($plan);
        if(is_wp_error($unique))return $unique;
        $artifact=syndamorph_compile_block_plan(wp_json_encode($plan));
        return is_wp_error($artifact)?$artifact:array('plan'=>$plan,'artifact'=>$artifact);
    };
    $compiled=$compilePlan($developer);
    for($attempt=1;is_wp_error($compiled)&&$attempt<=3;$attempt++) {
        $error=$compiled;
        $details=(array)$error->get_error_data();
        $diagnostic=array('code'=>$error->get_error_code(),'message'=>$error->get_error_message(),'field'=>$details['field']??'plan');
        if(!in_array($diagnostic['code'],array('sm_block_plan_invalid','sm_component_identity'),true))break;
        if($on_event)$on_event('schema_repair',array_merge($diagnostic,array('attempt'=>$attempt,'status'=>'required','role'=>'developer')));
        $repairContext=array('rejectedPlan'=>$developer,'diagnostic'=>$diagnostic,'learnedRules'=>syndamorph_learning_retrieve('schema_repair'));
        // Preserve the 20% reserve and use the same conservative admission check as generation.
        $estimate=((ceil(strlen(wp_json_encode($repairContext))/3)+4096)*$in+16384*$out)/1000000;
        if(syndamorph_collab_cost($usage,$in,$out)+$estimate>$cutoff) {
            $compiled=new WP_Error('sm_team_budget','Schema correction would exceed the 80% repair budget.',array('field'=>$diagnostic['field'],'stage'=>'budget','role'=>'developer','task'=>'schema_repair','completedCalls'=>$calls));
            break;
        }
        $corrected=$call('developer','Acknowledge the compiler diagnostic by correcting the rejected plan. Return the entire corrected JSON plan only, preserving all valid content, component IDs and design. Fix all schema violations, not just the first reported field. '.syndamorph_block_plan_contract(),$repairContext,16384,array(),'schema_repair');
        if(is_wp_error($corrected)){$compiled=$corrected;break;}
        $developer=$corrected;
        $compiled=$compilePlan($developer);
        syndamorph_learning_schema($diagnostic['field'],$diagnostic['message'],$learningSource.':schema:'.$attempt,!is_wp_error($compiled));
        if($on_event)$on_event('schema_repair',array('attempt'=>$attempt,'status'=>is_wp_error($compiled)?'rejected':'resolved','role'=>'developer','field'=>$diagnostic['field']));
    }
    if(is_wp_error($compiled)) {
        $details=(array)$compiled->get_error_data();
        return new WP_Error($compiled->get_error_code(),$compiled->get_error_message(),array_merge(array('stage'=>'compilation','role'=>'developer','task'=>'developer','completedCalls'=>$calls,'generationCompleted'=>true),$details));
    }
    $plan=$compiled['plan'];$artifact=$compiled['artifact'];
    $outputs['developer']=wp_json_encode($plan);
    $memory->remember($plan);
    
    $inspectNative=syndamorph_collab_inspect($artifact['preview']);
    if (is_wp_error($inspectReference) || is_wp_error($inspectNative)) return new WP_Error('sm_quality_blocked','Real browser inspection could not complete; job cannot be approved.');
    $comparison=syndamorph_collab_compare($inspectReference,$inspectNative);
    if(is_wp_error($comparison)) return $comparison;
    $evidence=array('reference'=>$inspectReference,'native'=>$inspectNative);
    $issues=array_merge(syndamorph_collab_block_issues($artifact),syndamorph_collab_evidence($inspectReference,$inspectNative),syndamorph_collab_style_findings($inspectReference,$inspectNative,$plan));
    $best=array('plan'=>$plan,'artifact'=>$artifact,'issues'=>$issues,'score'=>count($issues),'evidence'=>$inspectNative,'comparison'=>$comparison);
    $evidenceSummary=static function(array $samples):array {
        return array_map(static function($sample){return array('width'=>$sample['width'],'snapshot'=>$sample['snapshot'],
                'screenshotSha256'=>hash('sha256',base64_decode($sample['screenshot'],true)?:''),
                'screenshot'=>(in_array((int)$sample['width'],array(1440,390),true) && strlen($sample['screenshot'])<=180000) ? $sample['screenshot'] : null);},$samples);
    };
    // The model receives compact measurements; image bytes travel only in
    // Anthropic image blocks. History retains bounded screenshot data separately.
    $modelEvidence=static fn(array $samples):array=>syndamorph_memory_measurements($samples);
    $screenshots=array();
    foreach(array(0,2) as $viewport) {
        foreach(array($inspectReference,$inspectNative) as $samples) {
            $encoded=$samples[$viewport]['screenshot']??'';
            if (!is_string($encoded) || strlen($encoded)>2000000) return new WP_Error('sm_vision_evidence','Screenshot evidence is incomplete or exceeds image limit.');
            $screenshots[]=array('media_type'=>'image/jpeg','data'=>$encoded);
        }
    }
    $review=$call('boss','Assess visual fidelity, accessible names, content, responsiveness and native Gutenberg limits based only on the provided real browser measurements. Provide JSON: {"issues":[{"id":"...","component":"existing component id","severity":"high|medium|low","detail":"..."}],"summary":"..."}. Compiler checks are authoritative for accepted schema. Do not report speculative invalid gradients or unsupported attributes if compilation passed; report measured appearance differences instead. Return ONLY actionable implementation defects in issues. Include kind=implementation and status=open. Passes, host responsibilities, unsupported capabilities and pending editor verification belong in separate notes, never issues. Design itself is frozen. Use existing issue IDs for known findings. Evidence limits must be explicit.',
        array('brief'=>$brief,'design'=>$designer,'reference'=>$modelEvidence($inspectReference),'native'=>$modelEvidence($inspectNative),'deterministicIssues'=>$issues,'pixelComparison'=>$comparison,'componentPlan'=>$plan,'compilerChecks'=>$artifact['checks'],'imageOrder'=>array('desktop-reference','desktop-gutenberg','mobile-reference','mobile-gutenberg')),16384,$screenshots,'validation');
    if (is_wp_error($review)) return $review;$outputs['validator']=$review;
    $parsed=json_decode(syndamorph_native_json_text($review),true);
    if (!is_array($parsed) || !is_array($parsed['issues']??null)) {
        $issues[]=array('id'=>'validator-format','component'=>'blocks[0]','severity'=>'medium','detail'=>'Validator did not return structured findings.');
    } else {
        foreach(array_slice($parsed['issues'],0,20) as $issue) {
            if(!is_array($issue))continue;
            $normalized=syndamorph_issue_model($issue,$plan);
            if($normalized)$issues[]=$normalized;
        }
    }
    $issues=syndamorph_issue_merge($issues);
    $registry=array();foreach($issues as $issue)$registry[$issue['component'].'|'.$issue['id']]=array_merge($issue,array('status'=>'open'));
    $best['issues']=$issues;$best['score']=syndamorph_issue_score($issues);
    $stagnation=0;
    $terminationReason='quality_pending';
    // Budget is the primary limit; retain a hard runtime safety guard and stop stalled targets.
    for($round=1;count($issues)>0;$round++) {
        if($stagnation>=4) { $terminationReason='global_no_progress';break; }
        $stagnation++;
        if(microtime(true)-$started>1000) { $terminationReason='runtime_limit';break; }
        // 20% remains reserved for finalization. Never start a repair if projected spending crosses cutoff.
        // Reserve worst-case Developer and scoped visual QA calls.
        $projected=(25000*$in+32768*$out)/1000000;
        if (syndamorph_collab_cost($usage,$in,$out)+$projected>$cutoff) { $terminationReason='budget_80_percent';break; }
        $pending=array_values(array_filter($issues,static fn($issue)=>$memory->eligible($issue['component']) && syndamorph_collab_find($plan['blocks'],$issue['component'])!==null));
        if (!$pending) { $terminationReason='no_progress_or_editable_targets';break; }
        usort($pending,static fn($a,$b)=>array_search($a['severity'],array('high','medium','low'),true)<=>array_search($b['severity'],array('high','medium','low'),true));
        $id=$pending[0]['component'];
        $focus=array_slice(array_values(array_filter($pending,static fn($issue)=>$issue['component']===$id)),0,3);
        $node=syndamorph_collab_find($plan['blocks'],$id);
        $route=syndamorph_repair_route($focus);
        $delegated=array('designerNeeded'=>false,'reason'=>'Frozen reference: implementation repair only.');
        $patch=syndamorph_repair_container($node,$inspectReference,$inspectNative)??syndamorph_repair_measured($node,$inspectReference,$inspectNative);
        if($patch!==null) {
            $probe=syndamorph_collab_patch($plan,$patch);
            if(is_wp_error($probe)||$memory->seen($probe['plan']))$patch=null;
        }
        if($patch!==null) {
            $route='engine';
        } else {
            $repairScope=syndamorph_collab_scoped_images($inspectReference,$inspectNative,syndamorph_memory_texts($node));
            $repair=$call('developer','Repair only the specified native component against the frozen HTML reference. Inspect the reference/current screenshot pairs and measurements, including ancestor constraints. Return exactly JSON {"id":"existing id","replacement":{...full valid node with same id and type...}}. Preserve text, links and approved siblings. Do not redesign. Fix schema errors using the supported contract. Never repeat rejected attempts.',
                array('issueFocus'=>$focus,'component'=>$node,'id'=>$id,'referenceMeasurements'=>syndamorph_memory_measurements($inspectReference,syndamorph_memory_texts($node)),'nativeMeasurements'=>syndamorph_memory_measurements($inspectNative,syndamorph_memory_texts($node)),'previousAttempts'=>$memory->feedback($id),'learnedRules'=>syndamorph_learning_retrieve('repair',(string)($node['type']??'')),'imageScope'=>$repairScope['mode'],'imageOrder'=>array('desktop-reference','desktop-current','mobile-reference','mobile-current')),4000,$repairScope['images'],'repair');
            if(is_wp_error($repair)) { $terminationReason=$repair->get_error_code()==='sm_team_budget'?'budget_80_percent':'developer_blocked';break; }
            $patch=json_decode(syndamorph_native_json_text($repair),true);
        }
        if($on_event)$on_event('repair_route',array('round'=>$round,'id'=>$id,'route'=>$route));
        if (!is_array($patch)) { $entry=array('round'=>$round,'id'=>$id,'status'=>'rejected','detail'=>'Invalid JSON patch');$memory->record($id,$entry);$revisions[]=$entry;if($on_event)$on_event('revision',$entry);continue; }
        if(($patch['id']??null)!==$id) { $entry=array('round'=>$round,'id'=>$id,'status'=>'rejected','detail'=>'Patch escaped assigned component');$memory->record($id,$entry);$revisions[]=$entry;continue; }
        if(is_array($patch['replacement']??null)&&syndamorph_repair_content_signature($node)!==syndamorph_repair_content_signature($patch['replacement'])) { $entry=array('round'=>$round,'id'=>$id,'status'=>'rejected','detail'=>'Repair altered frozen content or links');$memory->record($id,$entry);$revisions[]=$entry;if($on_event)$on_event('revision',$entry);continue; }
        $candidate=syndamorph_collab_patch($plan,$patch);
        if (is_wp_error($candidate)) { $entry=array('round'=>$round,'id'=>$id,'status'=>'rejected','detail'=>$candidate->get_error_message());$memory->record($id,$entry);$revisions[]=$entry;if($on_event)$on_event('revision',$entry);continue; }
        if($memory->seen($candidate['plan'])) { $entry=array('round'=>$round,'id'=>$id,'status'=>'duplicate','accepted'=>false,'detail'=>'Equivalent candidate already attempted; skipped browser and AI QA.','before'=>count($issues),'after'=>count($issues));$memory->record($id,$entry);$revisions[]=$entry;if($on_event)$on_event('revision',$entry);continue; }
        $memory->remember($candidate['plan']);
        $impact=syndamorph_collab_impact($plan,$candidate['plan']);
        $newEvidence=syndamorph_collab_inspect($candidate['artifact']['preview']);
        if (is_wp_error($newEvidence)) { $revisions[]=array('round'=>$round,'status'=>'blocked','detail'=>'Browser revalidation unavailable');break; }
        // Root/ancestor changes affect all descendants. Reinspect all widths, but avoid additional full-model review.
        $newComparison=syndamorph_collab_compare($inspectReference,$newEvidence);
        if(is_wp_error($newComparison)) { $terminationReason='visual_compare_blocked'; $revisions[]=array('round'=>$round,'status'=>'blocked','detail'=>'Pixel comparison unavailable');break; }
        $newIssues=array_merge(syndamorph_collab_block_issues($candidate['artifact']),syndamorph_collab_evidence($inspectReference,$newEvidence),syndamorph_collab_style_findings($inspectReference,$newEvidence,$candidate['plan']));
        $affected=array_fill_keys($impact['affected'],true);
        $otherIssues=array_values(array_filter($issues,static fn($issue)=>!isset($affected[$issue['component']??''])));
        $scope=syndamorph_collab_scoped_images($inspectReference,$newEvidence,syndamorph_memory_texts(syndamorph_collab_find($candidate['plan']['blocks'],$id)));
        $scopedScreenshots=$scope['images'];
        // Each repair receives a fresh vision review of its changed component,
        // not the entire design report. Unaffected model findings are retained.
        $targetReview=$call('boss',
            'Inspect ONLY the changed component and affected descendants using the four browser screenshots. Compare against reference. Return JSON {"issues":[{"id":"...","component":"existing id","severity":"high|medium|low","detail":"..."}]}. Do not re-review approved siblings. Compiler validity is authoritative; never report speculative schema failures for compiled markup. Return only open, actionable implementation defects. Never put passes, resolved issues, host responsibilities or unsupported capabilities in issues. Reuse previous IDs. Include kind=implementation and status=open.',
            array('id'=>$id,'changedComponent'=>syndamorph_collab_find($candidate['plan']['blocks'],$id),
                'previousIssues'=>array_values(array_filter($issues,static fn($issue)=>isset($affected[$issue['component']??'']))),
                'impact'=>$impact,'compilerChecks'=>$candidate['artifact']['checks'],'imageScope'=>$scope['mode'],
                'measurements'=>syndamorph_memory_measurements($newEvidence,syndamorph_memory_texts(syndamorph_collab_find($candidate['plan']['blocks'],$id))),'previousAttempts'=>$memory->feedback($id),'pixelComparison'=>$newComparison,'imageOrder'=>array('desktop-reference','desktop-updated','mobile-reference','mobile-updated')),16384,$scopedScreenshots,'revision_validation');
        if (is_wp_error($targetReview)) { $terminationReason='validator_blocked'; $revisions[]=array('round'=>$round,'status'=>'blocked','detail'=>'Scoped visual validation unavailable');break; }
        $parsedTarget=json_decode(syndamorph_native_json_text($targetReview),true);
        if (!is_array($parsedTarget) || !is_array($parsedTarget['issues']??null)) {
            $entry=array('round'=>$round,'id'=>$id,'status'=>'rejected','detail'=>'Scoped Validator response invalid');$memory->record($id,$entry);$revisions[]=$entry;continue;
        }
        foreach(array_slice($parsedTarget['issues'],0,10) as $issue) {
            if(!is_array($issue))continue;
            $normalized=syndamorph_issue_model($issue,$candidate['plan']);
            if($normalized && isset($affected[$normalized['component']]))$newIssues[]=$normalized;
        }
        // Recompute deterministic findings globally; retain only unaffected model findings.
        foreach($otherIssues as $issue) if(($issue['source']??'')==='model')$newIssues[]=$issue;
        $newIssues=syndamorph_issue_merge($newIssues);
        $oldScore=syndamorph_issue_score($issues);$newScore=syndamorph_issue_score($newIssues);
        $oldPixel=array_sum(array_column($comparison,'pixelDifferenceRatio'))/3;
        $newPixel=array_sum(array_column($newComparison,'pixelDifferenceRatio'))/3;
        $accepted=($newScore<$oldScore || ($newScore===$oldScore && $newPixel<$oldPixel)) && $newPixel<=$oldPixel+0.08;
        $revisions[]=array('round'=>$round,'id'=>$id,'delegation'=>array('designerNeeded'=>$delegated['designerNeeded'],'reason'=>mb_substr((string)($delegated['reason']??''),0,160)),'route'=>$route,'impact'=>$impact,'imageScope'=>$scope['mode'],'accepted'=>$accepted,'before'=>count($issues),'after'=>count($newIssues),'scoreBefore'=>$oldScore,'scoreAfter'=>$newScore,'evidenceHash'=>hash('sha256',wp_json_encode($evidenceSummary($newEvidence))));
        $memory->record($id,array_merge(end($revisions),array('replacement'=>$patch['replacement'],'remainingIssues'=>array_slice($newIssues,0,8))));
        if($accepted && $route==='developer')syndamorph_learning_repair($node,$patch['replacement'],$oldScore,$newScore,$learningSource.':repair:'.$round);
        if($accepted) {
            $stagnation=0;
            $memory->reopen(array_values(array_diff($impact['affected'],$impact['changed'])));
            $plan=$candidate['plan'];$artifact=$candidate['artifact'];$inspectNative=$newEvidence;$comparison=$newComparison;
            foreach($registry as &$record)$record['status']='resolved';unset($record);
            foreach($newIssues as $issue)$registry[$issue['component'].'|'.$issue['id']]=array_merge($issue,array('status'=>'open'));
            $issues=$newIssues;
            $bestPixel=array_sum(array_column($best['comparison'],'pixelDifferenceRatio'))/3;
            if($newScore<$best['score'] || ($newScore===$best['score'] && $newPixel<$bestPixel)) $best=array('plan'=>$plan,'artifact'=>$artifact,'issues'=>$issues,'score'=>$newScore,'evidence'=>$inspectNative,'comparison'=>$comparison);
        }
        if($on_event) $on_event('revision',end($revisions));
        if($accepted && $newScore===0) { $terminationReason='quality_resolved';break; }
    }
    if($best['score']===0) $terminationReason='quality_resolved';
    elseif($terminationReason==='quality_pending') $terminationReason='revision_limit_or_unresolved';
    $artifact=$best['artifact'];
    $outputs['developer']=wp_json_encode($best['plan']);
    $artifact['contentSha256']=hash('sha256',$artifact['content']);
    $cost=round(syndamorph_collab_cost($usage,$in,$out),6);
    // A zero-count heuristic is not proof of visual or Gutenberg editor validity.
    $status=$best['score']===0 ? 'needs_review' : 'uncompleted';
    return array('provider'=>$provider,'model'=>$model,'standardsVersion'=>syndamorph_production_standards()['version'],
        'outputType'=>'gutenberg','artifact'=>$artifact,'referenceHtml'=>$reference,'status'=>$status,
        'events'=>$events,'outputs'=>$outputs,'checks'=>$artifact['checks'],
        'usage'=>$usage,'agentMetrics'=>$metrics,'estimatedCostUsd'=>$cost,'pricingSource'=>$settings['pricingSource']??'unknown',
        'elapsedMs'=>(int)((microtime(true)-$started)*1000),'budgetUsd'=>$max,'budgetIsEstimate'=>true,
        'jobMemory'=>$memory->snapshot(),
        'quality'=>array('capabilityNotes'=>$capabilityNotes,'issueRegistry'=>array_values($registry),'score'=>$best['score'],'issues'=>$best['issues'],'revisions'=>$revisions,'terminationReason'=>$terminationReason,'evidence'=>array(
            'reference'=>$evidenceSummary($inspectReference),'native'=>$evidenceSummary($best['evidence'])),
            'pixelComparison'=>$best['comparison'],
            'browserInspected'=>true,'visionInspected'=>true,'nativeEditorVerified'=>false),
        'published'=>false,'renderedInBrowser'=>true);
}
