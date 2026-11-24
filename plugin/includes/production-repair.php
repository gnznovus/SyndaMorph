<?php
/** Native production scope, capability and repair policy. No provider calls here. */
if (!defined('ABSPATH')) exit;
function syndamorph_agent_scope(string $role,string $task): bool {
    return in_array($task,array(
        'boss'=>array('boss','validation','revision_validation','final_approval','scope_resolution'),
        'designer'=>array('designer','design_correction'),
        'developer'=>array('reference','developer','schema_repair','repair')
    )[$role]??array(),true);
}
function syndamorph_repair_route(array $issues): string {
    // Native fidelity defects never authorize a creative redesign. Explicit scope
    // or design changes require a new reference, not a patch to native alone.
    return 'developer';
}
function syndamorph_repair_prioritize(array $issues): array {
    $severity=array('high'=>3,'medium'=>2,'low'=>1);
    usort($issues,static function(array $a,array $b)use($severity):int {
        $class=static fn(array $issue):array=>array(
            (int)($issue['critical']??false),
            $severity[$issue['severity']??'medium']??2,
            count($issue['components']??array()),
            count($issue['views']??array()),
        );
        return $class($b)<=>$class($a);
    });
    return $issues;
}

function syndamorph_repair_blocker_type(string $reason,array $issues): string {
    if(in_array($reason,array('browser_blocked','visual_compare_blocked','visual_crop_blocked','validator_blocked'),true))return 'missing_evidence';
    foreach($issues as $issue)if(($issue['blockerType']??'')==='capability')return 'capability';
    return $reason==='design_review_required'?'design_constraint':'implementation';
}
function syndamorph_issue_score(array $issues): int {
    $weights=array();
    foreach($issues as $issue) {
        $key=$issue['scoreGroup']??$issue['group']??count($weights);
        $weight=array('high'=>10,'medium'=>4,'low'=>1)[$issue['severity']??'medium']??4;
        $weights[$key]=max($weights[$key]??0,$weight);
    }
    return array_sum($weights);
}
function syndamorph_issue_key(array $issue): string {
    return $issue['group']??(($issue['component']??'').'|'.($issue['id']??''));
}
function syndamorph_issue_merge(array $issues): array {
    $out=array();
    foreach($issues as $issue) {
        if(!is_array($issue)||!is_string($issue['detail']??null))continue;
        if(isset($issue['status']) && $issue['status']!=='open')continue;
        $key=syndamorph_issue_key($issue);
        if(!isset($out[$key])) {
            $out[$key]=$issue;
            continue;
        }
        if(isset($out[$key]['measurement']) && ($issue['source']??'')==='model')continue;
        if(isset($issue['measurement']) || syndamorph_issue_score(array($issue))>syndamorph_issue_score(array($out[$key]))) {
            $out[$key]=$issue;
        }
    }
    return array_values($out);
}
function syndamorph_issue_model(array $issue,array $plan): ?array {
    if(!is_string($issue['detail']??null)||strlen($issue['detail'])>1000)return null;
    if(isset($issue['status']) && $issue['status']!=='open')return null;
    if(isset($issue['kind']) && $issue['kind']!=='implementation')return null;
    $id=$issue['component']??'';
    if(!is_string($id)||syndamorph_collab_find($plan['blocks'],$id)===null)return null;
    $key=sanitize_key((string)($issue['id']??''));
    if($key==='')return null;
    $category=$issue['category']??'';
    $root=$plan['blocks'][0]['id']??'blocks[0]';
    // Support older QA responses while current prompts name the measured categories.
    if($category==='' && preg_match('/^(?:layout-root-.*centering|spacing-root-(?:gap-collapse|min-height))$/D',$key)) {
        $category=str_contains($key,'centering')?'alignment':(str_contains($key,'min-height')?'height':'spacing');
        $issue['scope']='section';
    }
    $normalized=array('id'=>$key,'component'=>$id,
        'severity'=>in_array($issue['severity']??'',array('high','medium','low'),true)?$issue['severity']:'medium',
        'detail'=>$issue['detail'],'source'=>'model');
    if(in_array($category,array('alignment','height','spacing','typography','wrapping'),true)) {
        $owner=($issue['scope']??'')==='section'?$root:$id;
        $normalized['group']=$category.'|'.$owner;
        $normalized['category']=$category;
    }
    return $normalized;
}
/** Safe measured leaf repair: only a value stable at every inspected width,
 * unique matching text, and a supported property. Never infer responsive CSS
 * from one viewport. Compilation and browser QA remain mandatory. */
function syndamorph_repair_measured(array $node,array $reference,array $native): ?array {
    if(!in_array($node['type']??'',array('heading','paragraph','button'),true)||empty($node['text']))return null;
    $text=trim($node['text']);$maps=array();
    foreach(array($reference,$native) as $samples) {
        $set=array();
        foreach($samples as $sample) {
            $matches=array_values(array_filter($sample['snapshot']['elements']??array(),static fn($e)=>trim((string)($e['text']??''))===$text && in_array(strtolower($e['tag']??''),array('h1','h2','h3','h4','h5','h6','p','a','button'),true)));
            if(count($matches)!==1)return null;
            $set[]=$matches[0]['styles']??array();
        }
        $maps[]=$set;
    }
    if(count($maps[0])!==3||count($maps[1])!==3)return null;
    $replacement=$node;
    foreach(array('fontWeight','fontSize','letterSpacing','color') as $key) {
        $values=array_column($maps[0],$key);$current=array_column($maps[1],$key);
        if(count($values)!==3||count($current)!==3||count(array_unique($values))!==1||$values===$current)continue;
        $v=$values[0];
        if($key==='color'&&preg_match('/^rgb\(\s*(\d{1,3}),\s*(\d{1,3}),\s*(\d{1,3})\s*\)$/D',$v,$m)&&max(array_slice($m,1))<=255)$v=sprintf('#%02x%02x%02x',$m[1],$m[2],$m[3]);
        if($key==='fontWeight'&&!preg_match('/^[1-9]00$/D',$v))continue;
        if($key==='fontSize'&&!preg_match('/^\d{1,3}(?:\.\d{1,3})?px$/D',$v))continue;
        if($key==='letterSpacing'&&!preg_match('/^-?\d{1,3}(?:\.\d{1,3})?px$/D',$v))continue;
        if($key==='color'&&!preg_match('/^#[0-9a-f]{6}$/iD',$v))continue;
        $replacement['style'][$key]=$v;
    }
    return SyndaMorph_Job_Memory::fingerprint($replacement)===SyndaMorph_Job_Memory::fingerprint($node)?null:array('id'=>$node['id'],'replacement'=>$replacement);
}

/** Preflight checks for reference-only features the current native schema cannot reproduce.
 * Host-owned interaction states are intentionally not section repair targets. */
function syndamorph_reference_capability_notes(string $html): array {
    $notes=array();
    if(preg_match('/(?<![\w-])max-width\s*:\s*[^;{}]+/i',$html))$notes[]='Leaf max-width requires a constrained group layout; verify wrapping.';
    if(preg_match('/(?<![\w-])(?:grid-template-areas\s*:|position\s*:\s*(?:absolute|fixed|sticky)|transform\s*:|clip-path\s*:)/i',$html))$notes[]='Reference contains layout effects outside current native block capabilities.';
    if(preg_match('/(?<![\w-])(?:text-transform|text-wrap)\s*:\s*(?:uppercase|lowercase|capitalize|balance|pretty)\b/i',$html))
        $notes[]='Reference typography uses casing or wrapping effects without a native style mapping. Check the visual impact before approval.';
    if(preg_match('/<\\s*(?:img|video|svg|canvas|table|ul|ol)\\b/i',$html))$notes[]='Reference contains elements outside current native block schema.';
    return $notes;
}
function syndamorph_repair_content_signature(array $node): array {
    $result=array();
    if(isset($node['text']))$result[]=array('text'=>$node['text'],'href'=>$node['href']??null,'type'=>$node['type']??null);
    foreach($node['children']??array() as $child)if(is_array($child))$result=array_merge($result,syndamorph_repair_content_signature($child));
    return $result;
}

/** Container correction from unambiguous, stable alignment evidence.
 * Only adjust the assigned container; constrained width is never discarded. */
function syndamorph_repair_container(array $node,array $reference,array $native): ?array {
    if(($node['type']??'')!=='buttons')return null;
    $texts=syndamorph_memory_texts($node);
    if(count($texts)!==1)return null;
    $direction=null;
    foreach($reference as $i=>$sample) {
        $match=static function(array $s,string $text): ?array {
            $matches=array_values(array_filter($s['snapshot']['elements']??array(),static fn($e)=>trim((string)($e['text']??''))===$text&&in_array(strtolower($e['tag']??''),array('a','button'),true)));
            return count($matches)===1&&count($matches[0]['rect']??array())===4?$matches[0]['rect']:null;
        };
        $a=$match($sample,$texts[0]);$b=$match($native[$i]??array(),$texts[0]);
        if(!$a||!$b)return null;
        $width=(float)($sample['width']??0);
        if($width<=0)return null;
        // Infer only a clear viewport-relative direction; preserve ambiguity for Developer.
        $d=abs($a[0]+$a[2]/2-$width/2)<12?'center':($a[0]<$width*0.16?'left':($a[0]+$a[2]>$width*0.84?'right':null));
        if($d===null||($direction!==null&&$d!==$direction))return null;
        $direction=$d;
    }
    if($direction===null||($node['style']['justifyContent']??'left')===$direction)return null;
    $replacement=$node;$replacement['style']['justifyContent']=$direction;
    return array('id'=>$node['id'],'replacement'=>$replacement);
}
