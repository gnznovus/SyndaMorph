<?php
/** Budgeted dual-artifact Gutenberg production. HTML-only jobs use the legacy pipeline. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/production-job-memory.php';
require_once __DIR__.'/production-learning.php';
require_once __DIR__.'/production-repair.php';
require_once __DIR__.'/production-visual-qa.php';
require_once __DIR__.'/production-inspection.php';
require_once __DIR__.'/production-surgery.php';
require_once __DIR__.'/production-tools.php';

/**
 * Agent decisions are immutable facts. Execution gates belong to the
 * orchestrator and must never be emitted as a different agent decision.
 */
function syndamorph_authority_decision(string $owner,string $stage,$payload,array $allowed,
    ?callable $on_event=null) {
    if(!is_array($payload)||!is_string($payload['decision']??null)||
        !in_array($payload['decision'],$allowed,true))
        return new WP_Error('sm_agent_decision_contract',
            'Agent decision is missing or outside its stage contract.',
            array('stage'=>$stage,'owner'=>$owner));
    $decision=array('owner'=>$owner,'stage'=>$stage,'decision'=>$payload['decision'],
        'issues'=>is_array($payload['issues']??null)?$payload['issues']:array(),
        'summary'=>mb_substr((string)($payload['summary']??''),0,400));
    if($on_event)$on_event('agent_decision',$decision);
    return $decision;
}
/**
 * A gate may block advancement but cannot mutate the decision it received.
 * Explicit model approval plus contradictory issues is a contract error, not
 * permission for the orchestrator to invent a changes_requested decision.
 */
function syndamorph_authority_gate(array $decision,string $status,string $reason='',
    ?callable $on_event=null): array {
    if(!in_array($status,array('ready','blocked','pending_validation'),true))
        throw new InvalidArgumentException('Unknown execution gate status.');
    $gate=array('owner'=>'orchestrator','stage'=>$decision['stage'],
        'agentDecision'=>$decision['decision'],'executionStatus'=>$status,
        'reason'=>mb_substr($reason,0,300));
    if($on_event)$on_event('execution_gate',$gate);
    return $gate;
}
/** Overall hard limit; each task requests a smaller cap when suitable. */
function syndamorph_collab_output_cap(): int { return 16384; }

/** Strict, compact inter-agent assignment; the original user brief remains authoritative. */
function syndamorph_collab_requirements(string $raw) {
    $data=json_decode(syndamorph_native_json_text($raw),true);
    if(!is_array($data)||!is_string($data['title']??null)||trim($data['title'])==='')
        return new WP_Error('sm_boss_contract','Boss must return structured JSON requirements.');
    $result=array('title'=>mb_substr(trim($data['title']),0,100));
    foreach(array('sections','constraints','acceptance') as $field){
        if(!is_array($data[$field]??null)||!array_is_list($data[$field])||
            ($field==='acceptance'&&count($data[$field])===0))
            return new WP_Error('sm_boss_contract','Boss requirements are missing '.$field.'.');
        $result[$field]=array_map(static fn($v)=>mb_substr(trim($v),0,180),
            array_slice(array_values(array_filter($data[$field],'is_string')),0,12));
    }
    return $result;
}
function syndamorph_collab_cost(array $usage, float $input, float $output): float {
    return ((($usage['inputTokens'] ?? 0)+($usage['cacheReadTokens']??0)*0.1+($usage['cacheWriteTokens']??0)*1.25) * $input + ($usage['outputTokens'] ?? 0) * $output) / 1000000;
}
function syndamorph_collab_allowance(int $bytes,int $images,int $cap,float $input,float $output): float {
    $inputTokens=ceil($bytes/3)*1.25+$images*2000;
    return ($inputTokens*$input+$cap*$output)/1000000;
}

function syndamorph_operation_usage(array $metrics,float $input,float $output): array {
    $usage=array();
    foreach(array('inputTokens','outputTokens','cacheReadTokens','cacheWriteTokens','contextBytes','imageBytes','elapsedMs') as $key) {
        $usage[$key]=array_sum(array_column($metrics,$key));
    }
    return array_merge($usage,array('calls'=>count($metrics),'stages'=>array_column($metrics,'task'),
        'unmeteredCalls'=>count(array_filter($metrics,static fn($metric)=>($metric['metering']??'')==='unavailable')),
        'estimatedCostUsd'=>round(syndamorph_collab_cost($usage,$input,$output),6)));
}
function syndamorph_repair_summary(array $revisions,array $metrics,float $input,float $output): array {
    $repair=array_values(array_filter($metrics,static fn($metric)=>in_array(($metric['task']??''),
        array('repair','revision_validation','scope_resolution'),true)));
    $groups=array();
    foreach($revisions as $revision) {
        $key=($revision['baselineFingerprint']??'').'|'.($revision['issueGroup'][0]??'');
        if(!isset($groups[$key]))$groups[$key]=array('issueGroup'=>$revision['issueGroup']??array(),
            'attempts'=>0,'accepted'=>false,'retainedImprovement'=>0.0,'usage'=>array());
        $groups[$key]['attempts']++;
        $groups[$key]['accepted']=$groups[$key]['accepted']||$revision['accepted'];
        foreach($revision['usage']??array() as $name=>$value) {
            if(!is_array($value))$groups[$key]['usage'][$name]=($groups[$key]['usage'][$name]??0)+$value;
        }
        if($revision['accepted'])$groups[$key]['retainedImprovement']=$revision['retainedImprovement'];
    }
    return array('groups'=>array_values($groups),'usageIncludingEscalation'=>syndamorph_operation_usage($repair,$input,$output));
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
/** Visual effects propagate upward to layout ancestors without treating their
 * source code as changed. Preserve unaffected sibling code/reuse fingerprints. */
function syndamorph_collab_review_scope(array $plan,array $impact): array {
    $nodes=syndamorph_collab_fingerprints($plan);
    $scope=array_fill_keys($impact['affected'],true);
    foreach($impact['changed'] as $id){
        $parent=$nodes[$id]['parent']??null;
        while($parent!==null){
            $scope[$parent]=true;
            $parent=$nodes[$parent]['parent']??null;
        }
    }
    return array_keys($scope);
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
    // Shared transactional verifier is also used by compiler/schema surgery.
    $verified=syndamorph_surgery_verify(array('blocks'=>array($before)),$plan);
    if(is_wp_error($verified)){$target=$before;return $verified;}
    return array('plan'=>$verified['plan'],'artifact'=>$verified['artifact']);
}
function syndamorph_collab_viewports(string $brief) {
    $widths = array();
    $numberList='\d{2,5}(?:\s*(?:,\s*(?:and\s+)?|\/|\band\b)\s*\d{2,5})*';
    preg_match_all('/\b('.$numberList.')\s*(?:CSS\s*)?px\b/i',$brief,$matches,PREG_OFFSET_CAPTURE);
    foreach($matches[1] as [$value,$offset]) {
        $before=substr($brief,max(0,$offset-64),min(64,$offset));
        $after=substr($brief,$offset+strlen($value),36);
        $intent='/\b(?:viewports?|screen(?:s| widths?)?|breakpoints?|desktop|tablet|mobile|test|render|verify|check|measure)\b[^.;]*$/i';
        if(!preg_match($intent,$before) && !preg_match('/^(?:\s*CSS)?\s*px\s+(?:wide|viewports?|screen|desktop|tablet|mobile)\b/i',$after))continue;
        preg_match_all('/\d+/', $value, $numbers);
        foreach ($numbers[0] as $number) $widths[] = (int)$number;
    }
    preg_match_all('/\b(?:viewports?|screen widths?|test widths?)\s*[:=]?\s*('.$numberList.')\b/i',$brief,$unitless);
    foreach($unitless[1] as $value) {
        preg_match_all('/\d+/',$value,$numbers);
        foreach($numbers[0] as $number)$widths[]=(int)$number;
    }
    $widths = array_values(array_unique($widths));
    if (!$widths) return array(1440,768,375);
    if (count($widths) > 5 || min($widths) < 320 || max($widths) > 1920) {
        return new WP_Error('sm_inspect_viewports','Requested viewport widths must include one to five distinct values from 320 to 1920 CSS px.');
    }
    rsort($widths, SORT_NUMERIC);
    return $widths;
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
    if(!is_array($data['comparison']??null)||array_column($data['comparison'],'width')!==array_column($reference,'width'))return new WP_Error('sm_compare_invalid','Visual comparison evidence incomplete.');
    return $data['comparison'];
}
function syndamorph_collab_evidence(array $reference, array $native): array {
    $findings=array();
    foreach (array_keys($native) as $i) {
        $out=$native[$i]['snapshot']??array();$width=(int)($native[$i]['width']??0);
        $root=$out['elements'][0]['node']??'blocks[0]';
        if (($out['overflow']??true)) $findings[] = array('id'=>'overflow-'.$width,'component'=>$root,'severity'=>'high',
            'critical'=>true,'scoreGroup'=>'overflow|'.$root,'detail'=>'Native output has horizontal overflow at '.$width.'px.');
        foreach (($native[$i]['a11y']??array()) as $violation) {
            $rule=sanitize_key((string)($violation['id']??'unknown'));
            if(in_array($rule,array('document-title','html-has-lang','html-lang-valid','landmark-one-main','region','page-has-heading-one'),true))continue; // host page responsibilities
            $findings[]=array('id'=>'axe-'.$rule.'-'.$width,'component'=>$root,'severity'=>'high',
                'critical'=>true,'scoreGroup'=>'axe|'.$rule.'|'.$root,
                'detail'=>'axe-core '.($violation['impact']??'issue').': '.mb_substr((string)($violation['description']??$rule),0,200));
        }
        foreach (($out['elements']??array()) as $index=>$el) {
            $owner=$el['node']??(($el['id']??'')?:$root);
            $locator=$el['node']??(($el['id']??'')?:$index);
            if(!empty($el['clipped'])) {
                $findings[]=array('id'=>'clip-'.$width.'-'.$locator,'component'=>$owner,'severity'=>'high',
                    'critical'=>true,'scoreGroup'=>'clip|'.$locator,'detail'=>'Content is clipped at '.$width.'px.');
            }
            if (!empty($el['missingAlt']) || !empty($el['unnamedControl'])) {
                $findings[] = array('id'=>'a11y-'.$width.'-'.$locator,'component'=>$owner,'severity'=>'high',
                    'critical'=>true,'scoreGroup'=>'a11y|'.$locator,'detail'=>'Element has missing accessible name or image alt.');
            }
        }
    }
    return $findings;
}
/** Compiler-required checks are blocking QA findings, not informational badges. */
function syndamorph_collab_block_issues(array $artifact): array {
    $issues=array();
    foreach($artifact['checks']??array() as $key=>$passed) {
        if($passed!==true) $issues[]=array('id'=>'native-'.sanitize_key((string)$key),'component'=>'blocks[0]','critical'=>true,
            'severity'=>'high','detail'=>'Mandatory native block check failed: '.$key);
    }
    return $issues;
}
/** Map a rendered leaf to its native-plan owner, without injecting custom block markup. */
function syndamorph_collab_owner(array $nodes, string $text, string $fallback='blocks[0]'): string {
    foreach($nodes as $node) {
        if (!is_array($node)) continue;
        if (isset($node['text']) && syndamorph_evidence_text((string)$node['text'])===$text) return (string)($node['id']??$fallback);
        if (isset($node['children']) && is_array($node['children'])) {
            $found=syndamorph_collab_owner($node['children'],$text,'');
            if ($found!=='') return $found;
        }
    }
    return $fallback;
}
/** Match by explicit HTML identity first, then by unambiguous semantic text. */
function syndamorph_collab_pairs(array $reference,array $native,array $plan): array {
    $index=static function(array $elements):array {
        $out=array();
        foreach($elements as $element) {
            $text=$element['normalizedText']??syndamorph_evidence_text((string)($element['text']??''));
            if($text==='')continue;
            $tag=strtoupper($element['tag']??'');
            if(in_array($tag,array('A','BUTTON'),true))$tag='CTA';
            $key=$tag.'|'.$text;
            $out[$key][]=$element;
        }
        return $out;
    };
    $referenceIndex=$index($reference);
    $nativeIndex=$index($native);
    $byId=array();
    foreach($reference as $element)if(!empty($element['id']))$byId[$element['id']][]=$element;
    $pairs=array();
    foreach($nativeIndex as $key=>$elements)foreach($elements as $element) {
        $id=$element['node']??$element['id']??'';
        $matches=$byId[$id]??array();
        if(count($matches)!==1 || ($matches[0]['tag']??'')!==($element['tag']??'')) {
            $matches=count($elements)===1 ? ($referenceIndex[$key]??array()) : array();
        }
        if(count($matches)!==1)continue;
        $text=$element['normalizedText']??syndamorph_evidence_text((string)$element['text']);
        if($id==='' || !isset($element['node']))$id=syndamorph_collab_owner($plan['blocks'],$text);
        $pairs[]=array('owner'=>$id,'reference'=>$matches[0],'native'=>$element);
    }
    return $pairs;
}
function syndamorph_collab_style_findings(array $reference,array $native,array $plan): array {
    $issues=array();
    foreach(array_keys($native) as $i) {
        $width=(int)($native[$i]['width']??0);
        $pairs=syndamorph_collab_pairs($reference[$i]['snapshot']['elements']??array(),
            $native[$i]['snapshot']['elements']??array(),$plan);
        foreach($pairs as $pair) {
            $source=$pair['reference'];$el=$pair['native'];$changes=array();
            $text=syndamorph_evidence_text($el['text']??'');
            foreach(array('fontSize','fontWeight','color','letterSpacing','textTransform') as $key) {
                if (($source['styles'][$key]??null)!==($el['styles'][$key]??null)) $changes[]=$key;
            }
            if (count($source['textRect']??array())===4 && count($el['textRect']??array())===4) {
                if (abs($source['textRect'][2]-$el['textRect'][2])>max(24,$source['textRect'][2]*0.20)) $changes[]='textWidth';
            }
            if($changes) {
                $id=$pair['owner'];
                if(!isset($issues[$id]))$issues[$id]=array('id'=>'style-'.substr(hash('sha256',$id),0,12),'component'=>$id,
                    'category'=>'typography','group'=>'typography|'.$id,'source'=>'browser','measurement'=>true,
                    'severity'=>'medium','detail'=>'Measured text appearance differs from the HTML reference for '.mb_substr($text,0,70),
                    'views'=>array());
                $issues[$id]['views'][$width]=$changes;
            }
        }
    }
    return array_slice(array_values($issues),0,50);
}
/** Use matching visible text to locate affected regions in both cached images. Fall back to full evidence if matching is ambiguous. */
function syndamorph_collab_scoped_images(array $reference,array $native,array $texts,?int $primary=null): array {
    $items=array();$full=array();
    $indices=$primary===null?array_unique(array(0,count($reference)-1)):array($primary);
    foreach($indices as $viewport) foreach(array($reference,$native) as $samples) {
        $sample=$samples[$viewport];$full[]=array('media_type'=>'image/jpeg','data'=>$sample['screenshot']);
        $rects=array();
        $elements=$sample['snapshot']['elements']??array();
        foreach($elements as $el) {
            if(!in_array(syndamorph_evidence_text((string)($el['text']??'')),$texts,true)||count($el['rect']??array())!==4)continue;
            $rects[]=$el['rect'];
            $parent=$elements[$el['parent']??-1]['rect']??null;
            if($parent && count($texts)>1)$rects[]=$parent;
        }
        if(!$rects)continue;
        $left=min(array_column($rects,0));$top=min(array_column($rects,1));
        $right=max(array_map(static fn($r)=>$r[0]+$r[2],$rects));$bottom=max(array_map(static fn($r)=>$r[1]+$r[3],$rects));
        $items[]=array('screenshot'=>$sample['screenshot'],'rect'=>array($left,$top,$right-$left,$bottom-$top));
    }
    if(count($items)!==count($full))return array('images'=>$full,'mode'=>'full');
    $response=wp_remote_post('http://renderer:3000/crop',array('timeout'=>20,'redirection'=>0,'headers'=>array('Content-Type'=>'application/json'),'body'=>wp_json_encode(array('items'=>$items)),'limit_response_size'=>2000000));
    if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)return array('images'=>$full,'mode'=>'full');
    $data=json_decode(wp_remote_retrieve_body($response),true);
    if(count($data['images']??array())!==count($full))return array('images'=>$full,'mode'=>'full');
    return array('images'=>$data['images'],'mode'=>'component');
}
function syndamorph_repair_image_index(array $focus,array $viewports): int {
    $priority=array_fill_keys($viewports,0.0);
    foreach($focus as $issue)foreach($issue['views']??array() as $width=>$view) {
        if(!array_key_exists($width,$priority))continue;
        $priority[$width]+=isset($view['error'],$view['tolerance'])?
            $view['error']/max(0.1,$view['tolerance']):count($view);
    }
    arsort($priority,SORT_NUMERIC);
    return (int)array_search(array_key_first($priority),$viewports,true);
}
/**
 * Designer surgery: exact unique, bounded replacements against a persisted HTML
 * reference. A mismatch is a rejected transaction, never a partial mutation.
 */
function syndamorph_design_surgery(string $reference,array $response) {
    $edits=$response['edits']??null;
    if(!is_array($edits)||!array_is_list($edits)||count($edits)<1||count($edits)>5)
        return new WP_Error('sm_design_surgery_contract','Expected one to five scoped {find,replace} edits.');
    $candidate=$reference;$changed=array();
    foreach($edits as $edit){
        $find=$edit['find']??null;$replace=$edit['replace']??null;
        if(!is_string($find)||!is_string($replace)||$find===''||strlen($find)>2400||strlen($replace)>3600||
            substr_count($candidate,$find)!==1||$find===$replace)
            return new WP_Error('sm_design_surgery_scope','Edit must replace exactly one existing bounded fragment.');
        $candidate=str_replace($find,$replace,$candidate);
        $changed[]=array('beforeHash'=>hash('sha256',$find),'afterHash'=>hash('sha256',$replace),
            'beforeBytes'=>strlen($find),'afterBytes'=>strlen($replace));
    }
    if(strlen($candidate)>120000||!preg_match('~<section\\b~i',$candidate)||
        preg_match('~<\\s*(?:script|iframe|object|embed|form|link|meta)\\b|\\bon[a-z]+\\s*=|\\b(?:javascript:|https?://|@import)~i',$candidate))
        return new WP_Error('sm_reference_unsafe','Surgical design candidate failed HTML safety checks.');
    return array('reference'=>$candidate,'changes'=>$changed);
}

function syndamorph_collab_run(string $brief,bool $stream,?callable $on_event,bool $useLearning=true) {
    $GLOBALS['syndamorph_production_tool_cache']=array();
    $GLOBALS['syndamorph_production_tool_count']=0;
    unset($GLOBALS['syndamorph_current_reference']);
    $viewports=syndamorph_collab_viewports($brief);
    if(is_wp_error($viewports))return $viewports;
    $renderer=syndamorph_inspection_ready($viewports);
    if(is_wp_error($renderer))return syndamorph_inspection_with_stage($renderer,'preflight','',0);
    if($on_event)$on_event('renderer_ready',$renderer+array('expectedWidths'=>$viewports));
    $visionViewports=array_values(array_unique(array(0,count($viewports)-1)));
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
    $prefix=syndamorph_production_cached_contract();
    $call=static function(string $role,string $instruction,array $context,int $cap,array $images=array(),string $stage='',
        float $nextAllowance=0.0) use (&$call,&$usage,&$metrics,&$events,&$calls,$provider,$model,$in,$out,$max,$cutoff,$stream,$on_event,$prefix,$viewports) {
        $stage=$stage ?: $role;
        $control=syndamorph_job_control_gate();
        if(is_wp_error($control))return $control;
        $cached=syndamorph_job_resume_take($role,$stage);
        if(is_wp_error($cached))return $cached;
        if(is_array($cached)){
            $priorMetric=(array)($cached['metric']??array());
            foreach($usage as $key=>$_)$usage[$key]+=(int)($priorMetric[$key]??0);
            $metrics[]=$priorMetric;
            $calls++;
            $events[]=array('role'=>$role,'task'=>$stage,'status'=>'completed',
                'model'=>$model,'replayed'=>true);
            $followup=syndamorph_production_tool_followup($cached['text'],$role,$stage,
                $instruction,$context,$cap,$images,$nextAllowance,$viewports,$on_event,$call);
            return $followup===null?$cached['text']:$followup;
        }
        if(!syndamorph_agent_scope($role,$stage))return new WP_Error('sm_agent_scope','Task outside agent scope.');
        $cap=min($cap,syndamorph_collab_output_cap());
        $context['availableTools']=syndamorph_production_tool_catalog($role);
        $encoded=wp_json_encode($context);
        $contextLimit=in_array($stage,array('repair','revision_validation'),true)?24000:160000;
        if (!is_string($encoded) || strlen($encoded)>$contextLimit) return new WP_Error('sm_team_context','Agent context exceeds safe limit.',array('stage'=>'context','role'=>$role,'task'=>$stage,'completedCalls'=>$calls));
        $system=syndamorph_production_task_context($role,$stage)."\n".$instruction.
            "\nIf a concrete uncertainty requires one of the provided read-only availableTools, you may return ONLY JSON ".
            '{"action":"tool_request","toolRequest":{"name":"registered tool name","target":"authorized artifact","reason":"specific measurable question"}}. '.
            'The orchestrator will return measured or cached evidence; then complete the original assignment. Never request a tool merely to defer work and never request write permissions.';
        $allowance=syndamorph_collab_allowance(strlen($encoded)+strlen($prefix)+strlen($system),count($images),$cap,$in,$out);
        if (syndamorph_collab_cost($usage,$in,$out)+$allowance+$nextAllowance>($stage==='scope_resolution'?$max:$cutoff))
            return new WP_Error('sm_team_budget','No safe budget for another model call.',array('stage'=>'budget','role'=>$role,'task'=>$stage,'completedCalls'=>$calls));
        if ($on_event) $on_event('agent_start',array('role'=>$role,'task'=>$stage));
        $start=microtime(true);
        $result=syndamorph_models_generate($provider,$model,
            $system,
            $encoded,$cap,$stream,$on_event?static function(string $delta)use($on_event,$role,$stage){$on_event('agent_delta',array('role'=>$role,'task'=>$stage,'text'=>$delta));}:null,$images,$prefix);
        $error=is_wp_error($result);
        $errorData=$error?(array)$result->get_error_data():array();
        $reported=$error?($errorData['usage']??null):$result['usage'];
        $charged=array();
        foreach($usage as $key=>$_) {
            $charged[$key]=(int)($reported[$key]??($key==='outputTokens'?($errorData['outputTokens']??0):0));
            $usage[$key]+=$charged[$key];
        }
        $metric=array_merge($charged,array('role'=>$role,'task'=>$stage,'status'=>$error?'failed':'completed',
            'metering'=>is_array($reported)?'provider_reported':'unavailable',
            'elapsedMs'=>(int)((microtime(true)-$start)*1000),'estimatedCostUsd'=>round(syndamorph_collab_cost($charged,$in,$out),6),
            'reservedCostUsd'=>round($allowance,6),'outputCap'=>$cap,'contextBytes'=>strlen($encoded),
            'imageBytes'=>array_sum(array_map(static fn($img)=>strlen($img['data']??''),$images))));
        $metrics[]=$metric;
        if($error) {
            if($on_event)$on_event('agent_failure',array('role'=>$role,'task'=>$stage,'metric'=>$metric));
            return new WP_Error($result->get_error_code(),$result->get_error_message(),
                array_merge($errorData,array('stage'=>'generation','role'=>$role,'task'=>$stage,'completedCalls'=>$calls)));
        }
        $calls++;
        $events[]=array('role'=>$role,'task'=>$stage,'status'=>'completed','model'=>$result['model']);
        if ($on_event) $on_event('agent_complete',array('role'=>$role,'task'=>$stage,'metric'=>$metric));
        $followup=syndamorph_production_tool_followup($result['text'],$role,$stage,
            $instruction,$context,$cap,$images,$nextAllowance,$viewports,$on_event,$call);
        return $followup===null?$result['text']:$followup;
    };
    $deferInspection=static function(WP_Error $error,string $html,string $stage,string $role,array $capabilityNotes,
        ?array $artifact=null,array $surgery=array(),array $designMemo=array())use(
            &$usage,&$metrics,&$events,&$outputs,&$handoffs,&$calls,$provider,$model,$settings,$in,$out,$max,
            $started,$memory,$viewports,$call,$on_event
        ):array {
        $marked=syndamorph_inspection_with_stage($error,$stage,$role,$calls);
        $diagnostic=syndamorph_inspection_diagnostic($marked);
        $saved=syndamorph_inspection_checkpoint($marked,$html,$viewports);
        $checkpoint=$saved->get_error_data()['referenceCheckpoint']??null;
        // Continue independent checks and accounting while the visual gate stays closed.
        $checks=$artifact['checks']??array();
        $issues=$artifact?syndamorph_collab_block_issues($artifact):array();
        if($artifact)$artifact['contentSha256']=hash('sha256',$artifact['content']);
        $escalation=array('state'=>'pending','reason'=>'inspection_pending','blockerType'=>'missing_evidence',
            'owner'=>'orchestrator','decision'=>'pending','nextAction'=>'Retry the missing visual QA on the saved artifact.');
        if($on_event)$on_event('inspection_error',$diagnostic);
        $reply=$call('boss','The required visual gate is Pending. Choose one bounded next action. Return only JSON {"decision":"await_renderer|manual_review_required|continue_independent","nextAction":"specific safe action"}. Continue only independent static work. Do not approve, invent evidence, replace requested widths, or regenerate the saved artifact.',
            array('inspection'=>$diagnostic,'completedIndependentChecks'=>$checks,'capabilityNotes'=>$capabilityNotes,
                'compiledDraftAvailable'=>$artifact!==null,'referenceCheckpointAvailable'=>$checkpoint!==null),
            2048,array(),'scope_resolution');
        if(!is_wp_error($reply)) {
            $decision=json_decode(syndamorph_native_json_text($reply),true);
            $resolution=syndamorph_authority_decision('boss','inspection_escalation',$decision,
                array('await_renderer','manual_review_required','continue_independent'),$on_event);
            if(!is_wp_error($resolution)){
                syndamorph_authority_gate($resolution,'pending_validation',
                    'Renderer evidence is still missing; no model decision bypasses the visual gate.',$on_event);
                $escalation['decision']=$resolution['decision'];
                // Use application-owned actions, never model prose as a workflow instruction.
                $escalation['nextAction']=array(
                    'await_renderer'=>'Retry the exact required widths on the saved artifact.',
                    'manual_review_required'=>'Inspect the diagnostic and restore the renderer before visual approval.',
                    'continue_independent'=>'Review the available static checks and draft while visual QA remains pending.',
                )[$decision['decision']];
            }
        }
        if($on_event)$on_event('repair_escalation',$escalation);
        return array('provider'=>$provider,'model'=>$model,'standardsVersion'=>syndamorph_production_standards()['version'],
            'outputType'=>'gutenberg','artifact'=>$artifact,'status'=>'uncompleted','referenceCheckpoint'=>$checkpoint,
            'events'=>$events,'outputs'=>$outputs,'checks'=>$checks,'handoffs'=>$handoffs,'surgery'=>$surgery,
            'designApproval'=>$designMemo,'usage'=>$usage,'agentMetrics'=>$metrics,
            'estimatedCostUsd'=>round(syndamorph_collab_cost($usage,$in,$out),6),
            'pricingSource'=>$settings['pricingSource']??'unknown','elapsedMs'=>(int)((microtime(true)-$started)*1000),
            'budgetUsd'=>$max,'budgetIsEstimate'=>true,'jobMemory'=>$memory->snapshot(),
            'stageStatus'=>array('design'=>$designMemo?'approved':'pending','compilation'=>$artifact?'passed':'pending',
                'browserQa'=>'pending','wordpressEditor'=>'pending','blockerIds'=>array($error->get_error_code())),
            'quality'=>array('escalation'=>$escalation,'inspection'=>$diagnostic,'capabilityNotes'=>$capabilityNotes,
                'score'=>null,'issues'=>$issues,'viewportWidths'=>$viewports,'revisions'=>array(),
                'terminationReason'=>'inspection_pending','browserInspected'=>false,'visionInspected'=>false,
                'bossApproved'=>false,'nativeEditorVerified'=>false),
            'published'=>false,'renderedInBrowser'=>false);
    };
    $boss=$call('boss','Classify the job into a concise work order. Return ONLY JSON {"title":"short job title","sections":["..."],"constraints":["..."],"acceptance":["..."]}. Use the supplied acceptance viewport widths exactly. Include concrete requested sections, implementability constraints and testable acceptance criteria. Maximum 12 short strings per list, no speculative objections, markdown or HTML. If unspecified, permit generic marketing copy and provisional local CTAs without invented real-world facts.',
        array('brief'=>$brief,'viewportWidths'=>$viewports),2048);
    if(is_wp_error($boss))return $boss;
    $bossBrief=syndamorph_collab_requirements($boss);
    if(is_wp_error($bossBrief))return $bossBrief;
    $outputs['boss']=wp_json_encode($bossBrief);
    if($on_event)$on_event('job_title',array('title'=>$bossBrief['title']));
    $assignment=array('from'=>'boss','to'=>'designer','type'=>'assignment',
        'requirements'=>$bossBrief,'briefSha256'=>hash('sha256',$brief));
    if($on_event)$on_event('handoff',$assignment);

    // Designer owns the visual deliverable; Boss reviews real browser evidence
    // before the Developer receives the approved reference.
    $reference=$call('designer','Build ONE self-contained semantic HTML section with scoped CSS as the visual benchmark. Implement only native-compatible design features. Include the requested complete sections with finished content, no external assets, scripts, JS or markdown. Respect the native layout contract. Return only the HTML artifact.',
        array('brief'=>$brief,'requirements'=>$bossBrief),16384);
    if(is_wp_error($reference))return $reference;
    $approval=array('decision'=>'rejected','issues'=>array());
    $approvedReference=false;$designReviews=0;$handoffs=array($assignment);
    $priorDesignMeasurements=null;$priorDesignReview=null;$priorDesignDiff=null;
    $priorDesignImageHashes=null;$designSeenStates=array();
    $pendingToolEvidence=null;$toolRequestKeys=array();$toolRequestCount=0;
    for($designRound=1;;$designRound++){
        $control=syndamorph_job_control_gate();
        if(is_wp_error($control))return $control;
        $reference=trim($reference);
        if(preg_match('/^`{3}(?:html)?\\s*([\\s\\S]*?)\\s*`{3}$/iD',$reference,$fenced))$reference=trim($fenced[1]);
        if(!preg_match('~<section\\b~i',$reference) ||
            preg_match('~<\\s*(?:script|iframe|object|embed|form|link|meta)\\b|\\bon[a-z]+\\s*=|\\b(?:javascript:|https?://|@import)~i',$reference))
            return new WP_Error('sm_reference_unsafe','Designer reference failed HTML safety checks.',
                array('stage'=>'design','designRound'=>$designRound));
        $GLOBALS['syndamorph_current_reference']=$reference;
        $capabilityNotes=syndamorph_reference_capability_notes($reference);
        $inspectReference=syndamorph_production_tool_cached_inspection($reference,$viewports);
        if($inspectReference===null)
            $inspectReference=syndamorph_production_tool('designer','playwright.inspect',array('html'=>$reference,'viewports'=>$viewports));
        if(is_wp_error($inspectReference)) {
            $outputs['designer']='A native compatibility check was completed. The saved design is pending visual QA.';
            return $deferInspection($inspectReference,$reference,'design_inspection','designer',$capabilityNotes);
        }
        syndamorph_production_tool_seed($reference,$viewports,$inspectReference);
        $referenceImages=array();$imageOrder=array();$currentImageHashes=array();
        foreach($visionViewports as $index){
            $sample=$inspectReference[$index]??null;
            if(!is_array($sample))continue;
            $imageHash=hash('sha256',(string)($sample['screenshot']??''));
            $currentImageHashes[$index]=$imageHash;
            if($priorDesignImageHashes===null||($priorDesignImageHashes[$index]??null)!==$imageHash){
                $referenceImages[]=array('media_type'=>'image/jpeg','data'=>$sample['screenshot']);
                $imageOrder[]=$viewports[$index];
            }
        }
        $priorDesignImageHashes=$currentImageHashes;
        $measurements=syndamorph_memory_measurements($inspectReference);
        $reviewContext=array('requirements'=>$brief,'referenceSha256'=>hash('sha256',$reference),
            'availableTools'=>syndamorph_production_tool_catalog('boss'),
            'verificationEvidence'=>$pendingToolEvidence,
            'capabilityNotes'=>$capabilityNotes,
            'imageOrder'=>$imageOrder,'unchangedScreenshotCount'=>count($visionViewports)-count($imageOrder));
        if($priorDesignMeasurements===null){
            $reviewContext['measurements']=$measurements;
            $reviewContext['reviewMode']='baseline';
        }else{
            $beforeMap=array();
            foreach($priorDesignMeasurements as $item){
                $beforeMap[hash('sha256',wp_json_encode($item))]=true;
            }
            $changedMeasurements=array_values(array_filter($measurements,
                static fn($item)=>!isset($beforeMap[hash('sha256',wp_json_encode($item))])));
            $reviewContext['reviewMode']='diff';
            $reviewContext['previousDecision']=$priorDesignReview;
            $reviewContext['appliedSurgery']=$priorDesignDiff;
            $reviewContext['changedMeasurements']=array_slice($changedMeasurements,0,24);
            $reviewContext['measurementSummary']=array('baseline'=>count($priorDesignMeasurements),
                'current'=>count($measurements),'changed'=>count($changedMeasurements));
        }
        $review=$call('boss','Review the Designer reference against the brief, native compiler contract and browser evidence. In diff mode, focus on changed evidence and unresolved defects. Browser-computed styles are authoritative for CSS values; do not reject a declared weight solely because a screenshot appears light. If evidence is insufficient, return JSON {"decision":"verification_required","toolRequest":{"name":"playwright.inspect","target":"reference","reason":"specific browser diagnostic question"},"summary":"brief"} instead of requesting HTML changes. Otherwise return JSON {"decision":"approved|changes_requested","issues":[{"target":"section","problem":"measurable defect","requiredChange":"specific correction"}],"acceptedCapabilityDeviations":[],"summary":"brief"}. Request no more than three concrete issues. Do not demand browser verification from Designer.',
            $reviewContext,4096,$referenceImages,'final_approval');
        $priorDesignMeasurements=$measurements;
        if(is_wp_error($review))return $review;
        $parsed=json_decode(syndamorph_native_json_text($review),true);
        $record=syndamorph_authority_decision('boss','design_approval',$parsed,
            array('approved','changes_requested','verification_required'),$on_event);
        if(is_wp_error($record))return $record;
        $approval=$parsed;
        $designReviews++;
        if(($approval['decision']??'')==='verification_required'){
            $request=(array)($approval['toolRequest']??array());
            $requestKey=hash('sha256',hash('sha256',$reference).wp_json_encode($request));
            if(isset($toolRequestKeys[$requestKey])||$toolRequestCount>=3)
                return new WP_Error('sm_tool_stagnation','Repeated verification request against unchanged design evidence.',
                    array('stage'=>'design_approval','toolRequest'=>$request));
            $toolRequestKeys[$requestKey]=true;$toolRequestCount++;
            $toolResult=syndamorph_production_tool_request('boss',$request,
                array('reference'=>$reference,'viewports'=>$viewports),$on_event);
            if(is_wp_error($toolResult))return $toolResult;
            $pendingToolEvidence=$toolResult;
            syndamorph_authority_gate($record,'pending_validation',
                'Boss requested additional browser evidence before a design decision.',$on_event);
            continue;
        }
        if(!is_array($approval['issues']??null))
            return new WP_Error('sm_boss_review_contract','Boss must return a structured issues list.',
                array('stage'=>'design_approval'));
        $decision=$record['decision'];
        $deviations=array();
        foreach((array)($approval['acceptedCapabilityDeviations']??array()) as $deviation) {
            if(!is_array($deviation)||!in_array($deviation['note']??null,$capabilityNotes,true)||
                !is_string($deviation['reason']??null)||trim($deviation['reason'])==='')continue;
            $deviations[$deviation['note']]=mb_substr($deviation['reason'],0,240);
        }
        $unresolvedNotes=array_values(array_diff($capabilityNotes,array_keys($deviations)));
        // Capability notes are advisory until the native compiler/QA can prove a
        // blocker. Do not silently override an explicit, issue-free Boss approval:
        // GEN-000018 accepted the uppercase label but produced a narrative string
        // instead of the structured deviation object, triggering needless surgery.
        if($decision==='approved' && $unresolvedNotes && $on_event)
            $on_event('capability_preflight',array('notes'=>$unresolvedNotes,
                'status'=>'advisory','decision'=>'approved',
                'detail'=>'Carry native mapping limitations into Developer and QA validation.'));
        if($decision==='approved' && !empty($approval['issues'])){
            syndamorph_authority_gate($record,'blocked','Boss approval contains unresolved issues.',$on_event);
            return new WP_Error('sm_boss_review_contract',
                'Boss approval contains unresolved issues; request an explicit consistent decision.',
                array('stage'=>'design_approval','issues'=>$approval['issues']));
        }
        syndamorph_authority_gate($record,
            $decision==='approved'?'ready':'blocked',
            $decision==='approved'?'Boss explicitly approved visual reference.':'Boss requested specific design changes.',
            $on_event);
        $handoff=array('from'=>'boss','to'=>'designer','type'=>'design_review','decision'=>$decision,
            'referenceSha256'=>hash('sha256',$reference),'acceptedCapabilityDeviations'=>$deviations,
            'issues'=>array_slice(array_map(static function($item){
                return array('target'=>mb_substr((string)($item['target']??'reference'),0,60),
                    'problem'=>mb_substr((string)($item['problem']??''),0,160),
                    'requiredChange'=>mb_substr((string)($item['requiredChange']??''),0,160));
            },array_filter((array)($approval['issues']??array()),'is_array')),0,3));
        $handoffs[]=$handoff;
        $priorDesignReview=array('decision'=>$decision,
            'summary'=>mb_substr((string)($approval['summary']??''),0,400),
            'issues'=>$handoff['issues']);
        if($on_event)$on_event('handoff',$handoff);
        if($decision==='approved'){$approvedReference=true;break;}
        // Track the underlying defect family rather than model wording or
        // artifact hashes, which change across harmless full-reference rewrites.
        $issueFamilies=array_map(static function($issue){
            $target=strtolower(trim((string)($issue['target']??'')));
            $target=trim((string)preg_replace('/\\s*\\([^)]*\\)/','',$target));
            $problem=strtolower((string)($issue['problem']??'').' '.($issue['requiredChange']??''));
            $family=preg_match('/font|weight|bold|typograph/',$problem)?'typography':
                (preg_match('/width|wrap|line count|horizontal/',$problem)?'width':
                (preg_match('/contrast|color/',$problem)?'contrast':
                (preg_match('/spacing|padding|gap|margin/',$problem)?'spacing':'other')));
            return $target.'|'.$family;
        },$handoff['issues']);
        sort($issueFamilies);
        $issueKey=wp_json_encode($issueFamilies);
        $stateKey=hash('sha256',$issueKey);
        if(($designSeenStates[$stateKey]??0)>=2)
            return new WP_Error('sm_design_stagnation',
                'Designer review repeated the same unresolved issues against an unchanged reference.',
                array('stage'=>'design_approval','reviewRounds'=>$designReviews,'issues'=>$handoff['issues']));
        $designSeenStates[$stateKey]=($designSeenStates[$stateKey]??0)+1;
        $beforeReference=$reference;
        $patchReply=$call('designer','Apply only actual design defects using JSON {"edits":[{"find":"exact unique fragment","replace":"replacement"}]}. Maximum five edits. If a browser diagnostic is required, return JSON {"action":"verification_required","toolRequest":{"name":"playwright.inspect","target":"reference","reason":"specific question"}}. If Boss feedback contradicts recorded evidence, return JSON {"action":"no_change","reason":"specific evidence-based dispute"}; never fabricate an edit or return empty edits. Do not return the whole reference.',
            array('reference'=>$reference,'feedback'=>$handoff['issues'],'requirements'=>$brief,
                'availableTools'=>syndamorph_production_tool_catalog('designer'),
                'verificationEvidence'=>$pendingToolEvidence),syndamorph_collab_output_cap(),array(),'design_correction');
        if(is_wp_error($patchReply))return $patchReply;
        $parsedPatch=json_decode(syndamorph_native_json_text($patchReply),true);
        if(is_array($parsedPatch)&&($parsedPatch['action']??'')==='verification_required'){
            $request=(array)($parsedPatch['toolRequest']??array());
            $requestKey=hash('sha256',hash('sha256',$reference).wp_json_encode($request));
            if(isset($toolRequestKeys[$requestKey])||$toolRequestCount>=3)
                return new WP_Error('sm_tool_stagnation','Repeated Designer verification request on unchanged design.',
                    array('stage'=>'design_approval','toolRequest'=>$request));
            $toolRequestKeys[$requestKey]=true;$toolRequestCount++;
            $toolResult=syndamorph_production_tool_request('designer',$request,
                array('reference'=>$reference,'viewports'=>$viewports),$on_event);
            if(is_wp_error($toolResult))return $toolResult;
            $pendingToolEvidence=$toolResult;
            continue;
        }
        if(is_array($parsedPatch) &&
            (($parsedPatch['action']??'')==='no_change' || ($parsedPatch['edits']??null)===array())){
            if($on_event)$on_event('design_dispute',array('round'=>$designRound,
                'referenceSha256'=>hash('sha256',$reference),
                'reason'=>mb_substr((string)($parsedPatch['reason']??'No valid design change was proposed.'),0,260)));
            return new WP_Error('sm_design_dispute',
                'Designer disputed an unverified Boss requirement; reference preserved for evidence-based review.',
                array('stage'=>'design_approval','reviewRounds'=>$designReviews,
                    'reason'=>$parsedPatch['reason']??'No actionable patch','issues'=>$handoff['issues']));
        }
        $surgical=is_array($parsedPatch)?syndamorph_design_surgery($reference,$parsedPatch):
            new WP_Error('sm_design_surgery_contract','Designer returned no structured patch.');
        if(is_wp_error($surgical)){
            // Invalid or stale surgery is not proof that the whole design must be rewritten.
            if($on_event)$on_event('design_surgery',array('status'=>'rejected','reason'=>$surgical->get_error_code(),'round'=>$designRound));
            return new WP_Error('sm_design_surgery_blocked',
                'Designer surgery could not be applied atomically; retain existing reference for manual review.',
                array('stage'=>'design_approval','cause'=>$surgical->get_error_code(),'issues'=>$handoff['issues']));
        }else{
            $reference=$surgical['reference'];
            $pendingToolEvidence=null;
            $priorDesignDiff=array('beforeHash'=>hash('sha256',$beforeReference),
                'afterHash'=>hash('sha256',$reference),'changes'=>$surgical['changes']);
            if($on_event)$on_event('design_surgery',array('status'=>'applied','round'=>$designRound,
                'beforeHash'=>hash('sha256',$beforeReference),'afterHash'=>hash('sha256',$reference),
                'changes'=>$surgical['changes']));
        }
    }
    if(!$approvedReference)return new WP_Error('sm_design_unapproved','Boss did not approve the visual reference.',
        array('stage'=>'design_approval','reviewRounds'=>$designReviews,'issues'=>end($handoffs)['issues']??array()));
    $outputs['designer']='Visual reference approved by Boss; see referenceHtml.';
    $outputs['reference']=$reference;
    syndamorph_learning_capability($capabilityNotes,$learningSource.':capability');
    if($on_event&&$capabilityNotes)$on_event('capability_preflight',array('notes'=>$capabilityNotes));
    $designMemo=array('decision'=>'approved','referenceSha256'=>hash('sha256',$reference),
        'reviewRounds'=>$designReviews,'capabilityNotes'=>$capabilityNotes,'acceptedCapabilityDeviations'=>$deviations);
    $handoffs[]=array('from'=>'boss','to'=>'developer','type'=>'approved_design',
        'referenceSha256'=>$designMemo['referenceSha256'],'reviewRounds'=>$designReviews);
    if($on_event)$on_event('handoff',end($handoffs));
    $developer=$call('developer','Translate the HTML reference into native Gutenberg JSON using the cached compiler contract. Preserve content and visual intent. Return JSON only, with stable unique IDs and supported native styles.',
        array('brief'=>$brief,'design'=>$designMemo,'reference'=>$reference,'capabilityNotes'=>$capabilityNotes,
            'imageOrder'=>array_intersect_key($viewports,array_flip($visionViewports)),
            'learnedRules'=>$useLearning?syndamorph_learning_retrieve('developer'):array()),16384,$referenceImages);
    if (is_wp_error($developer)) return $developer;

    // Native schema recovery operates on one persistent full-generation plan.
    $plan=json_decode(syndamorph_native_json_text($developer),true,32);
    if(!is_array($plan)||!is_array($plan['blocks']??null))
        return new WP_Error('sm_block_plan_invalid','Developer did not return a native JSON plan.');
    $plan=syndamorph_collab_ids($plan);
    $workingId=syndamorph_surgery_save($plan);
    if($workingId==='')return new WP_Error('sm_surgery_persist','Working plan exceeds safe size.');
    $surgery=array('workingPlanId'=>$workingId,'repairs'=>array(),'deterministic'=>0,'paidPatches'=>0,'fullRegenerations'=>0);
    if($on_event)$on_event('working_plan',array('id'=>$workingId,'revision'=>1));
    $compiled=syndamorph_surgery_verify($plan,$plan);
    $lessons=array();
    // F11: bound repeated failures of the exact compiler diagnostic and unchanged plan,
    // never the total number of distinct repairs needed for a valid document.
    $schemaAttempts=array();$schemaRejected=array();$schemaRetryLimit=3;
    for($attempt=1;is_wp_error($compiled);$attempt++){
        $error=$compiled;
        $data=(array)$error->get_error_data();
        $diag=array('code'=>$error->get_error_code(),'message'=>$error->get_error_message(),
            'field'=>$data['field']??'plan','expected'=>$data['expected']??array());
        if($diag['code']!=='sm_block_plan_invalid')break;
        $focus=syndamorph_surgery_extract($plan,$diag);
        if(!$focus)break;
        $errorKey=hash('sha256',wp_json_encode(array($diag['code'],$diag['field'],$diag['message'],$focus['id'])));
        if(($schemaAttempts[$errorKey]??0)>=$schemaRetryLimit){
            $compiled=new WP_Error('sm_schema_stagnation','Schema repair exhausted distinct attempts for an unchanged compiler error.',
                array('field'=>$diag['field'],'diagnostic'=>$diag['message'],'attempts'=>$schemaAttempts[$errorKey]));
            if($on_event)$on_event('schema_repair',array('field'=>$diag['field'],'status'=>'escalated',
                'reason'=>'same_error_retry_limit','attempts'=>$schemaAttempts[$errorKey]));
            break;
        }
        // Record failures as observations immediately; only compiled recovery
        // upgrades the same evidence to a verified, reusable lesson.
        syndamorph_learning_schema($diag['field'],$diag['message'],$learningSource.':schema:'.$attempt,false);
        if($on_event)$on_event('schema_repair',array_merge($diag,array('attempt'=>$attempt,'status'=>'required','role'=>'developer')));
        $route='deterministic';
        $normal=syndamorph_surgery_normalize($plan,$diag);
        $candidate=$normal===null?null:array('plan'=>$normal['plan']);
        if($candidate!==null){
            $test=syndamorph_surgery_verify($plan,$candidate['plan']);
            if(is_wp_error($test) && ($test->get_error_data()['field']??'plan')===$diag['field'])$candidate=null;
        }
        if($candidate===null){
            $route='developer';
            $schemaAttempts[$errorKey]=($schemaAttempts[$errorKey]??0)+1;
            // Bind acknowledgement to this exact compiler diagnostic, including expectations.
            $instructionId=hash('sha256',wp_json_encode(array($diag['code'],$diag['field'],
                $diag['message'],$diag['expected'])));
            $context=array('diagnostic'=>$diag,'instructionId'=>$instructionId,'target'=>$focus,
                'rejectedPatches'=>array_values($schemaRejected[$errorKey]??array()),
                'attemptsRemaining'=>$schemaRetryLimit-$schemaAttempts[$errorKey],
                'learnedRules'=>$useLearning?syndamorph_learning_retrieve('schema_repair'):array());
            $answer=$call('developer','Compiler rejected the current plan. Follow diagnostic.expected exactly. Return JSON {"acknowledged":{"instructionId":"copy instructionId from context","field":"copy diagnostic.field from context"},"patch":{"id":"existing id","changes":[{"path":"style.fontSize","value":"2rem"}]}}. Change only the failing node style. Preserve content and identity.',
                $context,2048,array(),'schema_repair');
            if(is_wp_error($answer)){$compiled=$answer;break;}
            $surgery['paidPatches']++;
            $response=json_decode(syndamorph_native_json_text($answer),true);
            $ack=$response['acknowledged']??null;
            if(!is_array($ack)||($ack['instructionId']??null)!==$instructionId||
                ($ack['field']??null)!==$diag['field']||!is_array($response['patch']??null)){
                $surgery['repairs'][]=array('attempt'=>$attempt,'route'=>$route,'field'=>$diag['field'],
                    'accepted'=>false,'reason'=>'compiler_instruction_not_acknowledged');
                if($on_event)$on_event('schema_repair',array('field'=>$diag['field'],'status'=>'rejected',
                    'reason'=>'compiler_instruction_not_acknowledged','attempt'=>$attempt));
                continue;
            }
            $proposal=$response['patch'];
            $patchHash=hash('sha256',wp_json_encode($proposal));
            if(isset($schemaRejected[$errorKey][$patchHash])){
                $compiled=new WP_Error('sm_schema_stagnation','Developer repeated an ineffective schema repair proposal.',
                    array('field'=>$diag['field'],'diagnostic'=>$diag['message'],
                        'attempts'=>$schemaAttempts[$errorKey],'reason'=>'duplicate_patch'));
                if($on_event)$on_event('schema_repair',array('field'=>$diag['field'],'status'=>'escalated',
                    'reason'=>'duplicate_patch','attempts'=>$schemaAttempts[$errorKey]));
                break;
            }
            $schemaRejected[$errorKey][$patchHash]=array('proposal'=>$proposal,'reason'=>$diag['message']);
            $candidate=is_array($proposal)?syndamorph_surgery_apply($plan,$proposal):new WP_Error('sm_surgery_contract','Invalid JSON patch.');
            if(is_wp_error($candidate)){
                $surgery['repairs'][]=array('attempt'=>$attempt,'route'=>$route,'field'=>$diag['field'],'accepted'=>false);
                continue;
            }
            if(is_wp_error($candidate['error']) && ($candidate['error']->get_error_data()['field']??'plan')===$diag['field']){
                $surgery['repairs'][]=array('attempt'=>$attempt,'route'=>$route,'field'=>$diag['field'],'accepted'=>false);
                continue;
            }
        }else $surgery['deterministic']++;
        $before=$plan;$plan=$candidate['plan'];
        $compiled=syndamorph_surgery_verify($before,$plan);
        $lessons[]=array('field'=>$diag['field'],'message'=>$diag['message'],'attempt'=>$attempt);
        if(syndamorph_surgery_save($plan,$workingId)==='')return new WP_Error('sm_surgery_persist','Schema recovery draft could not be saved.');
        $surgery['repairs'][]=array('attempt'=>$attempt,'route'=>$route,'field'=>$diag['field'],'accepted'=>true,'compiled'=>!is_wp_error($compiled));
        if($on_event)$on_event('schema_repair',array('attempt'=>$attempt,'status'=>is_wp_error($compiled)?'progress':'resolved','role'=>'developer','field'=>$diag['field'],'route'=>$route));
    }
    if(is_wp_error($compiled))return new WP_Error($compiled->get_error_code(),$compiled->get_error_message(),
        array_merge(array('stage'=>'compilation','role'=>'developer','task'=>'developer','completedCalls'=>$calls,
            'generationCompleted'=>true,'workingPlanId'=>$workingId,'surgery'=>$surgery),(array)$compiled->get_error_data()));
    foreach($lessons as $lesson)syndamorph_learning_schema($lesson['field'],$lesson['message'],$learningSource.':schema:'.$lesson['attempt'],true);
    $plan=$compiled['plan'];$artifact=$compiled['artifact'];
    $outputs['developer']=wp_json_encode($plan);
    $memory->remember($plan);
    
    $inspectNative=syndamorph_production_tool('developer','playwright.inspect',array('html'=>$artifact['preview'],'viewports'=>$viewports));
    if(is_wp_error($inspectNative))
        return $deferInspection($inspectNative,$artifact['preview'],'implementation_inspection','developer',$capabilityNotes,
            $artifact,$surgery,$designMemo);
    $comparison=syndamorph_production_tool('boss','playwright.compare',array('reference'=>$inspectReference,'native'=>$inspectNative));
    if(is_wp_error($comparison)) return $comparison;
    $evidence=array('reference'=>$inspectReference,'native'=>$inspectNative);
    $visualMetrics=syndamorph_visual_metrics($inspectReference,$inspectNative,$plan);
    $issues=array_merge(syndamorph_collab_block_issues($artifact),syndamorph_collab_evidence($inspectReference,$inspectNative),
        syndamorph_collab_style_findings($inspectReference,$inspectNative,$plan),syndamorph_visual_geometry_issues($visualMetrics));
    $best=array('plan'=>$plan,'artifact'=>$artifact,'issues'=>$issues,'score'=>count($issues),'evidence'=>$inspectNative,'comparison'=>$comparison);
    $evidenceSummary=static function(array $samples)use($viewports,$visionViewports):array {
        $imageWidths=array_intersect_key($viewports,array_flip($visionViewports));
        return array_map(static function($sample)use($imageWidths){return array('width'=>$sample['width'],'snapshot'=>$sample['snapshot'],
                'screenshotSha256'=>hash('sha256',base64_decode($sample['screenshot'],true)?:''),
                'screenshot'=>(in_array($sample['width'],$imageWidths,true) && strlen($sample['screenshot'])<=180000) ? $sample['screenshot'] : null);},$samples);
    };
    // The model receives compact measurements; image bytes travel only in
    // Anthropic image blocks. History retains bounded screenshot data separately.
    $modelEvidence=static fn(array $samples):array=>syndamorph_memory_measurements($samples);
    $screenshots=array();
    foreach($visionViewports as $viewport) {
        foreach(array($inspectReference,$inspectNative) as $samples) {
            $encoded=$samples[$viewport]['screenshot']??'';
            if (!is_string($encoded) || strlen($encoded)>2000000) return new WP_Error('sm_vision_evidence','Screenshot evidence is incomplete or exceeds image limit.');
            $screenshots[]=array('media_type'=>'image/jpeg','data'=>$encoded);
        }
    }
    $review=$call('boss','Assess the frozen design and browser evidence for the implementation. Return JSON {"decision":"approved|changes_requested","issues":[{"id":"stable id","component":"existing id","category":"alignment|spacing|height|typography|wrapping|other","scope":"section|component","severity":"high|medium|low","detail":"measurable defect and suggested correction","kind":"implementation","status":"open"}],"summary":"..."}. Reuse deterministic issue IDs where applicable. Compiler checks are authoritative. Put host responsibilities, unsupported capabilities and pending editor verification in notes. Approve only with no actionable defects.',
        array('brief'=>$brief,'design'=>$designMemo,'reference'=>$modelEvidence($inspectReference),'native'=>$modelEvidence($inspectNative),
            'deterministicIssues'=>$issues,'pixelComparison'=>$comparison,'componentPlan'=>$plan,'compilerChecks'=>$artifact['checks'],
            'imageOrder'=>array_merge(...array_map(static fn($index)=>array($viewports[$index].'-reference',$viewports[$index].'-native'),$visionViewports))),
        6144,$screenshots,'validation');
    if (is_wp_error($review)) return $review;$outputs['validator']=$review;
    $parsed=json_decode(syndamorph_native_json_text($review),true);
    $qaRecord=syndamorph_authority_decision('boss','implementation_qa',$parsed,
        array('approved','changes_requested'),$on_event);
    if(is_wp_error($qaRecord))return $qaRecord;
    if(!is_array($parsed['issues']??null))
        return new WP_Error('sm_boss_qa_contract','Boss QA must return a structured issues list.',
            array('stage'=>'implementation_qa'));
    $bossQaApproved=$qaRecord['decision']==='approved';
    if($bossQaApproved&&!empty($parsed['issues'])){
        syndamorph_authority_gate($qaRecord,'blocked','Boss approved while reporting open QA issues.',$on_event);
        return new WP_Error('sm_boss_qa_contract',
            'Boss QA approval contradicts its issue list; no repair was authorized.',
            array('stage'=>'implementation_qa','issues'=>$parsed['issues']));
    }
    syndamorph_authority_gate($qaRecord,
        $bossQaApproved&&empty($issues)?'ready':'blocked',
        !$bossQaApproved?'Boss requested implementation changes.':
            (!empty($issues)?'Deterministic implementation checks remain open.':'Boss and deterministic QA checks passed.'),
        $on_event);
    if($on_event)$on_event('handoff',array('from'=>'boss','to'=>'developer','type'=>'qa_review',
        'decision'=>$qaRecord['decision'],'executionStatus'=>$bossQaApproved&&empty($issues)?'ready':'blocked',
        'issueIds'=>array_slice(array_values(array_filter(array_map(
            static fn($i)=>is_array($i)?sanitize_key((string)($i['id']??'')):'',
            $parsed['issues']))),0,12)));
    {
        foreach(array_slice($parsed['issues'],0,20) as $issue) {
            if(!is_array($issue))continue;
            $normalized=syndamorph_issue_model($issue,$plan);
            if($normalized)$issues[]=$normalized;
        }
    }
    // A valid finding-free model report is still not permission to advance
    // unless Boss explicitly approved the implementation.
    if(!$bossQaApproved && is_array($parsed) && empty($parsed['issues']) && !$issues)
        $issues[]=array('id'=>'boss-approval-pending','component'=>'boss','severity'=>'high',
            'detail'=>'Boss has not approved the implementation.','source'=>'process');
    $issues=syndamorph_issue_merge($issues);
    $registry=array();foreach($issues as $issue)$registry[syndamorph_issue_key($issue)]=array_merge($issue,array('status'=>'open'));
    $best['issues']=$issues;$best['score']=syndamorph_issue_score($issues);
    $terminationReason='quality_pending';
    $inspectionPending=null;
    $inspectionCheckpoint=null;
    $reviewCap=4096;
    // Reserve the maximum size of the future QA request, including its one
    // image pair and cached/task instructions, before paying for Developer.
    $reviewReserve=syndamorph_collab_allowance(24000+strlen($prefix)+2000,2,$reviewCap,$in,$out);
    // Keep 20% for final issue reporting. No fixed QA attempt or stagnation cutoff.
    for($round=1;count($issues)>0;$round++) {
        $control=syndamorph_job_control_gate();
        if(is_wp_error($control))return $control;
        if(microtime(true)-$started>1000) { $terminationReason='runtime_limit';break; }
        if(syndamorph_collab_cost($usage,$in,$out)+$reviewReserve>$cutoff) {
            $terminationReason='budget_80_percent';break;
        }
        $assignment=syndamorph_repair_assignment($plan,$issues,$memory,$useLearning);
        if(!$assignment) { $terminationReason='no_new_repair_strategy';break; }
        $id=$assignment['id'];$focus=$assignment['focus'];$node=$assignment['node'];
        $primary=syndamorph_repair_image_index($focus,$viewports);
        $imageOrder=array($viewports[$primary].'-reference',$viewports[$primary].'-native');
        $operationStarted=microtime(true);
        $firstMetric=count($metrics);
        $meter=static function()use(&$metrics,$firstMetric,$operationStarted,$in,$out):array {
            return array_merge(syndamorph_operation_usage(array_slice($metrics,$firstMetric),$in,$out),
                array('operationElapsedMs'=>(int)((microtime(true)-$operationStarted)*1000)));
        };
        $repair=null;
        $matchedLessons=syndamorph_learning_match_operation($assignment);
        $injectedLessons=array();
        $related=syndamorph_collab_review_scope($plan,array('changed'=>array($id),
            'affected'=>$assignment['nodes']));
        $editableNodes=array_values(array_filter(syndamorph_surgery_context($node,121),
            static fn($item)=>in_array($item['id'],$assignment['nodes'],true)));
        $route=syndamorph_repair_route($focus);
        $delegated=array('designerNeeded'=>false,'reason'=>'Frozen reference: implementation repair only.');
        $deterministic=$assignment['strategy']==='style' && count($assignment['group'])===1 ?
            (syndamorph_repair_container($node,$inspectReference,$inspectNative)??
                syndamorph_repair_measured($node,$inspectReference,$inspectNative)) : null;
        $patch=$deterministic===null?null:syndamorph_surgery_from_replacement($node,$deterministic);
        $candidate=null;
        if($patch!==null){
            $candidate=syndamorph_surgery_operation($plan,$patch,$assignment);
            if(is_wp_error($candidate)||is_wp_error($candidate['error'])||$memory->seen($candidate['plan'])) {
                $candidate=null;$patch=null;
            }
        }
        if($patch!==null){
            $route='engine';
        }else{
            $repairScope=syndamorph_production_tool('developer','playwright.crop',
                array('reference'=>$inspectReference,'native'=>$inspectNative,
                    'texts'=>syndamorph_memory_texts($node),'primary'=>$primary));
            if(is_wp_error($repairScope)){$terminationReason='visual_crop_blocked';break;}
            $injectedLessons=$useLearning?array_slice($matchedLessons,0,3):array();
            $repairContext=array('issueFocus'=>$focus,'id'=>$id,
                'strategy'=>$assignment['strategy'],'authorizedNodes'=>$assignment['nodes'],
                'wrapParents'=>$assignment['mode']==='structure'?$assignment['wrapParents']:array(),
                'editableNodes'=>$editableNodes,
                'referenceMeasurements'=>syndamorph_memory_measurements($inspectReference,syndamorph_memory_texts($node),8),
                'nativeMeasurements'=>syndamorph_memory_measurements($inspectNative,syndamorph_memory_texts($node),8),
                'previousAttempts'=>$memory->feedback($id,$related,2),
                'nativeLayoutNotes'=>array_slice($artifact['layoutNotes']??array(),0,6),
                'learnedCorrections'=>array_map(static fn($lesson)=>array_intersect_key($lesson,
                    array_flip(array('id','rule','pattern'))),$injectedLessons),
                'imageScope'=>$repairScope['mode'],
                'imageOrder'=>$imageOrder);
            $instruction='Diagnose the related visual defects using the aligned reference, prior attempts and current geometry. Return only JSON {"id":"assigned owner","diagnosis":"cause with evidence","changes":[{"target":"authorized id","path":"style.margin.bottom","value":"1rem"}]}. Apply up to eight combined edits only to the explicit authorized nodes. Omit unused changes. Preserve text, links, block types, existing IDs and unrelated siblings. Never return a full node or plan. If a supported repair is impossible, use {"id":"assigned owner","blocked":"capability|design_constraint|missing_evidence","diagnosis":"why"}.';
            if($assignment['strategy']==='contract_correction')$instruction.=' Correct the specific contract failure in previousAttempts before trying new style values.';
            if($assignment['mode']==='structure')$instruction.=' Use a different strategy: include wraps:[{"parent":"authorized parent","children":["consecutive child id","next child id"],"id":"new-group-id","style":{"justifyContent":"center","gap":"1rem"}}]. Preserve the same child order. Wrap at most twice and combine with style edits in one operation.';
            $repair=$call('developer',$instruction,$repairContext,syndamorph_collab_output_cap(),
                $repairScope['images'],'repair',$reviewReserve);
            if(is_wp_error($repair)) {
                $terminationReason=$repair->get_error_code()==='sm_team_budget'?'budget_80_percent':'developer_blocked';
                $patch=null;
            }else $patch=json_decode(syndamorph_native_json_text($repair),true);
        }
        if($on_event)$on_event('repair_route',array('round'=>$round,'id'=>$id,'route'=>$route,'mode'=>$assignment['mode']));
        $proposedChanges=is_array($patch['changes']??null)?array_slice($patch['changes'],0,8):array();
        $wraps=is_array($patch['wraps']??null)?array_slice($patch['wraps'],0,2):array();
        $attempt=array('round'=>$round,'id'=>$id,'route'=>$route,'issueGroup'=>$assignment['group'],'affectedComponents'=>array($id),
            'strategy'=>$assignment['strategy'],'mode'=>$assignment['mode'],'baselineFingerprint'=>$assignment['baselineFingerprint'],
            'diagnosis'=>mb_substr(is_string($patch['diagnosis']??null)?$patch['diagnosis']:(string)($focus[0]['detail']??''),0,260),
            'scoreBefore'=>syndamorph_issue_score($issues),'wraps'=>strlen(wp_json_encode($wraps))<=5000?$wraps:array(),
            'changes'=>strlen(wp_json_encode($proposedChanges))<=5000?$proposedChanges:array(),
            'touched'=>array_values(array_filter(array_column($proposedChanges,'target'),static fn($target)=>is_string($target)&&strlen($target)<=100)),
            'learning'=>array('enabled'=>$useLearning,'matched'=>array_column($matchedLessons,'id'),
                'injected'=>array_column($injectedLessons,'id'),
                'followed'=>is_array($patch)?syndamorph_learning_followed($injectedLessons,$patch):array()),
            'remainingIssues'=>array_slice(syndamorph_repair_prioritize($focus),0,5));
        $reject=static function(string $detail,string $status='rejected',string $validation='patch_contract')
            use(&$memory,&$revisions,$on_event,$attempt,$meter):void {
            $entry=array_merge($attempt,array('status'=>$status,'accepted'=>false,'detail'=>$detail,'reason'=>$detail,
                'validation'=>$validation,'scoreAfter'=>$attempt['scoreBefore'],
                'nextDirection'=>'Address the validation reason before proposing a materially different correction.',
                'usage'=>$meter(),'retainedImprovement'=>0.0));
            $entry['learning']['validation']='failed';
            $id=$entry['id'];
            $memory->record($id,$entry);$revisions[]=$entry;
            if($on_event)$on_event('revision',$entry);
        };
        if(is_wp_error($repair??null)) {
            if($terminationReason!=='budget_80_percent')$reject($repair->get_error_message(),'blocked','developer_response');
            break;
        }
        if(!is_array($patch)||($patch['id']??null)!==$id){
            $reject('Invalid or out-of-scope surgical JSON patch');continue;
        }
        if(in_array($patch['blocked']??null,array('capability','design_constraint','missing_evidence'),true)) {
            $reject($attempt['diagnosis'],'blocked',$patch['blocked']);continue;
        }
        if($assignment['mode']==='structure' && !$wraps) {
            $reject('Structural repair requires an authorized wrap or a blocker reason.');continue;
        }
        $candidate=$candidate??syndamorph_surgery_operation($plan,$patch,$assignment);
        if(is_wp_error($candidate)){$reject($candidate->get_error_message());continue;}
        if(is_wp_error($candidate['error'])){$reject($candidate['error']->get_error_message(),'rejected','compiler');continue;}
        if($memory->seen($candidate['plan'])) {
            $reject('Equivalent candidate already attempted; skipped browser and AI QA.','duplicate','candidate_identity');
            continue;
        }
        $memory->remember($candidate['plan']);
        $impact=syndamorph_collab_impact($plan,$candidate['plan']);
        $newEvidence=syndamorph_production_tool('developer','playwright.inspect',array('html'=>$candidate['artifact']['preview'],'viewports'=>$viewports));
        if (is_wp_error($newEvidence)) {
            $terminationReason='browser_blocked';
            $error=syndamorph_inspection_with_stage($newEvidence,'revision_inspection','developer',$calls);
            $inspectionPending=syndamorph_inspection_diagnostic($error);
            $saved=syndamorph_inspection_checkpoint($error,$candidate['artifact']['preview'],$viewports);
            $inspectionCheckpoint=$saved->get_error_data()['referenceCheckpoint']??null;
            if($on_event)$on_event('inspection_error',$inspectionPending);
            $reject($error->get_error_message(),'blocked','browser');break;
        }
        // Root/ancestor changes affect all descendants. Reinspect all widths, but avoid additional full-model review.
        $newComparison=syndamorph_production_tool('boss','playwright.compare',array('reference'=>$inspectReference,'native'=>$newEvidence));
        if(is_wp_error($newComparison)) {
            $terminationReason='visual_compare_blocked';$reject('Pixel comparison unavailable','blocked','browser');break;
        }
        $newMetrics=syndamorph_visual_metrics($inspectReference,$newEvidence,$candidate['plan']);
        $newIssues=array_merge(syndamorph_collab_block_issues($candidate['artifact']),syndamorph_collab_evidence($inspectReference,$newEvidence),
            syndamorph_collab_style_findings($inspectReference,$newEvidence,$candidate['plan']),syndamorph_visual_geometry_issues($newMetrics));
        // A child spacing change affects ancestor layout metrics, even when the
        // ancestor's own attributes and fingerprint are unchanged.
        $reviewIds=syndamorph_collab_review_scope($candidate['plan'],$impact);
        $affected=array_fill_keys($reviewIds,true);
        $otherIssues=array_values(array_filter($issues,static fn($issue)=>!isset($affected[$issue['component']??''])));
        $scope=syndamorph_production_tool('boss','playwright.crop',array('reference'=>$inspectReference,'native'=>$newEvidence,
            'texts'=>syndamorph_memory_texts(syndamorph_collab_find($candidate['plan']['blocks'],$id)),'primary'=>$primary));
        if(is_wp_error($scope)) {
            $terminationReason='visual_crop_blocked';$reject('Scoped evidence unavailable','blocked','browser');break;
        }
        $scopedScreenshots=$scope['images'];
        // Each repair receives a fresh vision review of its changed component,
        // not the entire design report. Unaffected model findings are retained.
        $targetReview=$call('boss',
            'Inspect changed components and affected layout with the browser evidence. Return JSON {"decision":"approved|changes_requested","issues":[{"id":"stable id","component":"existing id","category":"alignment|spacing|height|typography|wrapping|other","scope":"section|component","severity":"high|medium|low","detail":"reference, previous and candidate evidence"}]}. Reuse issue IDs. A newly noticed defect does not imply a regression. Report only open actionable issues; compiler checks are authoritative.',
            array('id'=>$id,'changedNodes'=>syndamorph_surgery_context(
                    syndamorph_collab_find($candidate['plan']['blocks'],$id)),
                'previousIssues'=>array_values(array_filter($issues,static fn($issue)=>isset($affected[$issue['component']??'']))),
                'impact'=>$impact,'reviewScope'=>$reviewIds,'compilerChecks'=>$candidate['artifact']['checks'],'imageScope'=>$scope['mode'],
                'previousGeometry'=>array_intersect_key($visualMetrics,array_flip($assignment['group'])),
                'candidateGeometry'=>array_intersect_key($newMetrics,array_flip($assignment['group'])),
                'referenceMeasurements'=>syndamorph_memory_measurements($inspectReference,syndamorph_memory_texts(syndamorph_collab_find($candidate['plan']['blocks'],$id)),8),
                'measurements'=>syndamorph_memory_measurements($newEvidence,syndamorph_memory_texts(syndamorph_collab_find($candidate['plan']['blocks'],$id)),8),
                'pixelComparison'=>$newComparison,'imageOrder'=>$imageOrder),$reviewCap,$scopedScreenshots,'revision_validation');
        if (is_wp_error($targetReview)) {
            $terminationReason=$targetReview->get_error_code()==='sm_team_budget'?'budget_80_percent':'validator_blocked';
            $reject('Scoped visual validation unavailable','blocked','vision');break;
        }
        $parsedTarget=json_decode(syndamorph_native_json_text($targetReview),true);
        $targetRecord=syndamorph_authority_decision('boss','revision_validation',$parsedTarget,
            array('approved','changes_requested'),$on_event);
        if(is_wp_error($targetRecord)||!is_array($parsedTarget['issues']??null)||
            (($parsedTarget['decision']??'')==='approved'&&!empty($parsedTarget['issues']))) {
            if(!is_wp_error($targetRecord))
                syndamorph_authority_gate($targetRecord,'blocked','Contradictory or malformed scoped QA issues.',$on_event);
            $reject('Scoped Validator response invalid','rejected','vision_contract');continue;
        }
        syndamorph_authority_gate($targetRecord,
            $targetRecord['decision']==='approved'?'pending_validation':'blocked',
            'Deterministic comparison and repair acceptance remain independently enforced.',$on_event);
        foreach(array_slice($parsedTarget['issues'],0,10) as $issue) {
            if(!is_array($issue))continue;
            $normalized=syndamorph_issue_model($issue,$candidate['plan']);
            // Include newly discovered regression findings even when the model
            // assigns them to an adjacent sibling of a layout-affected parent.
            if($normalized)$newIssues[]=$normalized;
        }
        // Recompute deterministic findings globally; retain only unaffected model findings.
        foreach($otherIssues as $issue) if(($issue['source']??'')==='model')$newIssues[]=$issue;
        if(($parsedTarget['decision']??'')!=='approved' && !$newIssues)
            $newIssues[]=array('id'=>'boss-approval-pending','component'=>'boss','severity'=>'high',
                'detail'=>'Boss did not approve this revised component.','source'=>'process');
        $newIssues=syndamorph_issue_merge($newIssues);
        $verdict=syndamorph_visual_decision($issues,$newIssues,$visualMetrics,$newMetrics,
            $comparison,$newComparison,$focus,$parsedTarget['decision']==='approved');
        $oldScore=$verdict['beforeScore'];$newScore=$verdict['afterScore'];$accepted=$verdict['accepted'];
        if($accepted && syndamorph_surgery_save($candidate['plan'],$workingId)==='') {
            $terminationReason='artifact_persist_failed';$reject('Candidate could not be persisted','blocked','persistence');break;
        }
        $attempt['learning']['validation']=$accepted?'passed':'failed';
        if($useLearning) {
            $attempt['learning']['recorded']=syndamorph_learning_operation($assignment,$patch,$verdict,$learningSource.':operation:'.$round);
        }
        $revisions[]=array_merge($attempt,array('status'=>$accepted?'accepted':'rejected','delegation'=>array('designerNeeded'=>$delegated['designerNeeded'],'reason'=>mb_substr((string)($delegated['reason']??''),0,160)),'impact'=>$impact,'reviewScope'=>$reviewIds,
            'changedFields'=>$patch['changes']??array(),'imageScope'=>$scope['mode'],'accepted'=>$accepted,'before'=>count($issues),'after'=>count($newIssues),'scoreBefore'=>$oldScore,'scoreAfter'=>$newScore,
            'introducedIssues'=>$verdict['introducedIssues'],'discoveredIssues'=>$verdict['discoveredIssues'],
            'reason'=>$verdict['reason'],'defectStates'=>$verdict['states'],'focusScoreBefore'=>$verdict['focusBefore'],
            'focusScoreAfter'=>$verdict['focusAfter'],'pixelDeltas'=>$verdict['pixelDeltas'],
            'usage'=>$meter(),'retainedImprovement'=>$accepted && $oldScore>0 ? round(($oldScore-$newScore)/$oldScore,6) : 0.0,
            'geometryDeltas'=>$verdict['geometryDeltas'],'affectedComponents'=>$reviewIds,
            'validation'=>array('compiler'=>'passed','browser'=>'passed','vision'=>$parsedTarget['decision']),
            'nextDirection'=>$accepted?'Start from the retained improvement and address the highest remaining defect.':
                'Use the geometry deltas and remaining defect details to change the repair strategy.',
            'touched'=>$candidate['touched']??array(),'remainingIssues'=>array_slice(syndamorph_repair_prioritize($newIssues),0,8),
            'evidenceHash'=>hash('sha256',wp_json_encode($evidenceSummary($newEvidence)))));
        $memory->record($id,end($revisions));
        if($accepted) {
            if($newScore===0 && ($parsedTarget['decision']??'')==='approved')$bossQaApproved=true;
            $memory->reopen(array_values(array_diff($reviewIds,$impact['changed'])));
            $plan=$candidate['plan'];$artifact=$candidate['artifact'];$inspectNative=$newEvidence;$comparison=$newComparison;
            $visualMetrics=$newMetrics;
            $surgery['qaAccepted']=($surgery['qaAccepted']??0)+1;
            foreach($registry as &$record)$record['status']='resolved';unset($record);
            foreach($newIssues as $issue)$registry[syndamorph_issue_key($issue)]=array_merge($issue,array('status'=>'open',
                'change'=>$verdict['states'][syndamorph_issue_key($issue)]??'unresolved'));
            $issues=$newIssues;
            $best=array('plan'=>$plan,'artifact'=>$artifact,'issues'=>$issues,'score'=>$newScore,'evidence'=>$inspectNative,'comparison'=>$comparison);
        }
        if($on_event) $on_event('revision',end($revisions));
        if($accepted && $newScore===0) { $terminationReason='quality_resolved';break; }
    }
    // Stalled visual repairs are a coordination problem. Escalate once to Boss
    // with compact evidence, never silently broaden Developer's edit authority.
    $escalation=null;
    if($best['score']>0 && $terminationReason!=='quality_resolved'){
        $prioritized=syndamorph_repair_prioritize($best['issues']);
        $blocked=array_slice(array_map(static fn($issue)=>array(
            'id'=>(string)($issue['id']??''),'component'=>(string)($issue['component']??''),
            'severity'=>(string)($issue['severity']??'medium'),
            'detail'=>mb_substr((string)($issue['detail']??''),0,260),
            'category'=>$issue['category']??'other','views'=>$issue['views']??array()),$prioritized),0,5);
        $escalation=array('state'=>'blocked','reason'=>$terminationReason,
            'issueIds'=>array_column($blocked,'id'),'attemptCount'=>count($revisions),
            'blockerType'=>syndamorph_repair_blocker_type($terminationReason,$prioritized,$revisions),
            'owner'=>'orchestrator','decision'=>'pending','nextAction'=>'scope_review');
        if($on_event)$on_event('repair_escalation',$escalation);
        $blockerOwners=array_values(array_unique(array_column($blocked,'component')));
        if(!$blockerOwners)$blockerOwners=array('boss');
        $briefing=array('designApproval'=>$designMemo,'openIssues'=>$blocked,
            'blockerType'=>$escalation['blockerType'],'nativeLayoutNotes'=>$best['artifact']['layoutNotes']??array(),
            'attempts'=>$memory->feedback($blockerOwners[0],$blockerOwners,4),
            'compilerValid'=>true,'editorVerified'=>false);
        if($inspectionPending!==null)$briefing['inspection']=$inspectionPending;
        $response=$call('boss','Resolve a stalled QA handoff without changing the approved design. Return JSON {"decision":"engineering_blocked|design_review_required|manual_review_required","summary":"precise unresolved blocker","nextAction":"concrete action"}. Never approve a defect, rewrite a design or ask Developer to retry the same patch without new evidence.',
            $briefing,2048,array(),'scope_resolution');
        if(is_wp_error($response)) {
            $escalation['nextAction']=$response->get_error_code()==='sm_team_budget'?'budget_review':'manual_review';
        }else {
            $decision=json_decode(syndamorph_native_json_text($response),true);
            $resolution=syndamorph_authority_decision('boss','qa_escalation',$decision,
                array('engineering_blocked','design_review_required','manual_review_required'),$on_event);
            if(!is_wp_error($resolution)){
                syndamorph_authority_gate($resolution,'blocked',
                    'QA escalation cannot revoke design approval or bypass quality gates.',$on_event);
                $escalation['decision']=$resolution['decision'];
                $escalation['summary']=mb_substr((string)($decision['summary']??''),0,240);
                $escalation['nextAction']=mb_substr((string)($decision['nextAction']??''),0,200);
            }
        }
        if($on_event)$on_event('repair_escalation',$escalation);
    }
    if($best['score']===0 && $bossQaApproved) $terminationReason='quality_resolved';
    elseif($terminationReason==='quality_pending') $terminationReason='revision_limit_or_unresolved';
    $artifact=$best['artifact'];
    $outputs['developer']=wp_json_encode($best['plan']);
    $artifact['contentSha256']=hash('sha256',$artifact['content']);
    $cost=round(syndamorph_collab_cost($usage,$in,$out),6);
    // A zero-count heuristic is not proof of visual or Gutenberg editor validity.
    $status=($best['score']===0 && $bossQaApproved) ? 'needs_review' : 'uncompleted';
    if($best['score']===0 && !$bossQaApproved)$terminationReason='boss_approval_pending';
    return array('provider'=>$provider,'model'=>$model,'standardsVersion'=>syndamorph_production_standards()['version'],
        'outputType'=>'gutenberg','artifact'=>$artifact,'referenceHtml'=>$reference,'status'=>$status,
        'referenceCheckpoint'=>$inspectionCheckpoint,
        'events'=>$events,'outputs'=>$outputs,'checks'=>$artifact['checks'],
        'usage'=>$usage,'agentMetrics'=>$metrics,'estimatedCostUsd'=>$cost,'pricingSource'=>$settings['pricingSource']??'unknown',
        'elapsedMs'=>(int)((microtime(true)-$started)*1000),'budgetUsd'=>$max,'budgetIsEstimate'=>true,
        'jobMemory'=>$memory->snapshot(),'surgery'=>$surgery,'designApproval'=>$designMemo,'handoffs'=>$handoffs,
        'stageStatus'=>array('design'=>'approved','compilation'=>'passed',
            'browserQa'=>$inspectionPending!==null?'pending':(($best['score']===0&&$bossQaApproved)?'passed':'blocked'),
            'wordpressEditor'=>'pending',
            'blockerIds'=>array_values(array_unique(array_column($best['issues'],'id')))),
        'quality'=>array('escalation'=>$escalation,'inspection'=>$inspectionPending,'capabilityNotes'=>$capabilityNotes,'issueRegistry'=>array_values($registry),'score'=>$best['score'],
            'scoringVersion'=>2,'viewportWidths'=>$viewports,'issues'=>$best['issues'],'revisions'=>$revisions,
            'repairSummary'=>syndamorph_repair_summary($revisions,$metrics,$in,$out),'terminationReason'=>$terminationReason,'evidence'=>array(
            'reference'=>$evidenceSummary($inspectReference),'native'=>$evidenceSummary($best['evidence'])),
            'pixelComparison'=>$best['comparison'],
            'browserInspected'=>true,'visionInspected'=>true,'bossApproved'=>$bossQaApproved,'nativeEditorVerified'=>false),
        'published'=>false,'renderedInBrowser'=>true);
}
