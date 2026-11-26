<?php
/** Native production scope, capability and repair policy. No provider calls here. */
if (!defined('ABSPATH')) exit;
function syndamorph_agent_scope(string $role,string $task): bool {
    return in_array($task,array(
        'boss'=>array('boss','final_approval','scope_resolution'),
        'validator'=>array('validation','revision_validation'),
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

function syndamorph_repair_blocker_type(string $reason,array $issues,array $attempts=array()): string {
    if(in_array($reason,array('browser_blocked','visual_compare_blocked','visual_crop_blocked','validator_blocked'),true))return 'missing_evidence';
    foreach($issues as $issue)if(($issue['blockerType']??'')==='capability')return 'capability';
    foreach(array_reverse($attempts) as $attempt) {
        if(in_array($attempt['validation']??null,array('capability','design_constraint','missing_evidence'),true))
            return $attempt['validation'];
    }
    if($reason==='content_review_required')return 'content';
    if($reason==='repair_scope_unsupported')return 'scope';
    return $reason==='design_review_required'?'design_constraint':'implementation';
}

/** Keep the Boss findings intact, including instructions that accompany a measured finding. */
function syndamorph_repair_handoff(array $findings,array $assignment,array $plan): array {
    $instructions=array();$requested=array();
    foreach($findings as $finding) {
        if(!is_array($finding))continue;
        $issue=syndamorph_issue_model($finding,$plan);
        if(!$issue || ($issue['category']??'')==='content')continue;
        if(!in_array(syndamorph_issue_key($issue),$assignment['group'],true) &&
            !in_array($issue['component'],$assignment['nodes'],true))continue;
        $instructions[]=$finding;
        if(array_key_exists('repairStrategy',$finding)) {
            $mode=$finding['repairStrategy'];
            if(!in_array($mode,array('style','structure','hybrid'),true))
                return array('instructions'=>$instructions,'unsupported'=>true,'requested'=>array());
            $requested[$mode]=true;
        }
    }
    return array('instructions'=>$instructions,'requested'=>array_keys($requested),
        'unsupported'=>!$assignment['wrapParents'] && (isset($requested['structure'])||isset($requested['hybrid'])));
}

function syndamorph_repair_assignment(array $plan,array $issues,SyndaMorph_Job_Memory $memory,bool $useLearning=true,array $metrics=array(),?array &$deferredScopes=null): ?array {
    $editable=array_values(array_filter(syndamorph_repair_prioritize($issues),
        static fn($issue)=>($issue['category']??'')!=='content' &&
            syndamorph_collab_find($plan['blocks'],$issue['component'])!==null));
    $deferredScopes=array();
    $fingerprint=SyndaMorph_Job_Memory::fingerprint($plan);
    $fingerprints=syndamorph_collab_fingerprints($plan);
    foreach($editable as $lead) {
        $owner=$lead['component'];
        $node=syndamorph_collab_find($plan['blocks'],$owner);
        $focus=array();
        $nodes=array($owner);
        $layoutCategories=array('spacing','alignment','height','wrapping');
        $layout=in_array($lead['category']??'', $layoutCategories,true) ||
            in_array($lead['repairStrategy']??'',array('structure','hybrid'),true) ||
            preg_match('/spacing|layout|align|position|wrap/i',$lead['id']??'');
        $descendants=array_column(syndamorph_surgery_context($node,121),'id');
        foreach($editable as $issue) {
            $related=$issue['component']===$owner ||
                ($layout && in_array($issue['component'],$descendants,true) &&
                    in_array($issue['category']??'',array_merge($layoutCategories,array('typography')),true));
            if(!$related)continue;
            $focus[]=$issue;
            $nodes[]=$issue['component'];
            array_push($nodes,...array_intersect($issue['components']??array(),$descendants));
            if(count($focus)>=6)break;
        }
        $wrapParents=array();
        $nodes=array_values(array_unique($nodes));
        if($layout) {
            // Bound the edit scope while exposing eligible containers and their
            // direct children. Intermediate groups on the path to a finding
            // are valid wrap parents, even when they have just one child.
            foreach(array_reverse(syndamorph_surgery_context($node,121)) as $entry) {
                if(!in_array($entry['type'],array('group','column'),true))continue;
                $container=syndamorph_collab_find($plan['blocks'],$entry['id']);
                $children=array_column($container['children']??array(),'id');
                if($entry['id']!==$owner && !array_intersect($children,$nodes) &&
                    !in_array($entry['id'],$nodes,true))continue;
                $expanded=array_values(array_unique(array_merge($nodes,array($entry['id']),$children)));
                if(count($expanded)>24)continue;
                $nodes=$expanded;
                if(count($children)>=1 && count($children)<=12)$wrapParents[]=$entry['id'];
            }
        }
        $group=array_map('syndamorph_issue_key',$focus);
        $scopeFingerprint=SyndaMorph_Job_Memory::fingerprint(array(
            'lead'=>$group[0],'owner'=>$owner,'nodes'=>array_intersect_key($fingerprints,array_flip($nodes)),
            'measurements'=>$metrics[$group[0]]['views']??array(),
        ));
        if($memory->scopeDeferred($fingerprint,$scopeFingerprint)) {
            $deferredScopes[]=array('component'=>$owner,'issue'=>$lead['id'],'scopeSha256'=>$scopeFingerprint);
            continue;
        }
        $attempts=$memory->operationsOn($scopeFingerprint,$group);
        $used=array_column($attempts,'strategy');
        $usedModes=array_column($attempts,'operationMode');
        $strategy='style';
        $priorityNodes=array_slice($nodes,0,24);
        $wrapParents=array_values(array_filter($wrapParents,static fn($parent)=>in_array($parent,$priorityNodes,true)));
        if($useLearning && !$used && $wrapParents &&
            syndamorph_learning_match_operation(array('focus'=>$focus,'node'=>$node,'nodes'=>array_values(array_unique($nodes)),'mode'=>'structure'))) {
            $strategy='structure';
        }
        if(in_array($strategy,$usedModes,true) || (!$usedModes && in_array($strategy,$used,true))) {
            $failures=array_column($attempts,'validation');
            if((in_array('patch_contract',$failures,true)||in_array('compiler',$failures,true)) &&
                !in_array('contract_correction',$used,true)) {
                $strategy='contract_correction';
            }elseif($wrapParents && !in_array('structure',$used,true) &&
                !array_intersect(array('structure','hybrid'),$usedModes)) {
                $strategy='structure';
            }
        }
        return array('id'=>$owner,'node'=>$node,'focus'=>$focus,'group'=>$group,'strategy'=>$strategy,
            'mode'=>$wrapParents?($strategy==='structure'?'structure':'adaptive'):'style',
            'nodes'=>array_slice($nodes,0,24),
            'wrapParents'=>$wrapParents,'baselineFingerprint'=>$fingerprint,'strategyFingerprint'=>$scopeFingerprint);
    }
    return null;
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
    if(array_key_exists('repairStrategy',$issue))$normalized['repairStrategy']=$issue['repairStrategy'];
    if($category==='content' || str_starts_with($key,'copy-'))$normalized['category']='content';
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
    if(preg_match('/(?<![\w-])text-wrap\s*:\s*(?:balance|pretty)\b/i',$html))
        $notes[]='Reference uses text wrapping effects outside the native style contract.';
    if(preg_match('/(?<![\w-])(?:row-gap|column-gap|gap)\s*:\s*clamp\(/i',$html))
        $notes[]='Core WordPress discards fluid blockGap. Evaluate supported child spacing or request a design decision.';
    if(preg_match('/<\\s*(?:img|video|svg|canvas|table|ul|ol)\\b/i',$html))$notes[]='Reference contains elements outside current native block schema.';
    return $notes;
}
/**
 * Active effects that the native section contract cannot render. The renderer
 * supplies observed style and geometry at the requested widths.
 */
function syndamorph_reference_unrealizable(array $samples,array $approvedScrollIds=array()): array {
    $features=array();$controlWidths=array();
    foreach($samples as $sample) {
        $width=$sample['width'];
        if(($sample['snapshot']['overflow']??false)===true) {
            $features['horizontal-overflow']['capability']='horizontal-overflow';
            $features['horizontal-overflow']['target']='section';
            $features['horizontal-overflow']['views'][$width]='Document scroll width exceeds the viewport.';
        }
        foreach($sample['snapshot']['elements']??array() as $index=>$element) {
            $tag=$element['tag']??'';
            $target=mb_substr((string)($element['normalizedText']??$element['text']??''),0,110);
            if(($element['localHorizontalScroll']??false)===true&&
                !in_array($element['scrollReference']??null,$approvedScrollIds,true)){
                $key='unclassified-horizontal-scroll|'.$index;
                $features[$key]['capability']='unclassified-horizontal-scroll';
                $features[$key]['target']=$element['scrollReference']??'section';
                $features[$key]['views'][$width]='Horizontal scroll area needs an approved native interaction.';
            }
            $wrap=$element['styles']['textWrapStyle']??null;
            if(in_array($wrap,array('balance','pretty'),true) && ($element['wrapChangesGeometry']??false)===true) {
                $key='text-wrap|'.$index;
                $features[$key]['capability']='text-wrap';
                $features[$key]['target']=$target;
                $features[$key]['views'][$width]=$wrap;
            }
            if(in_array($tag,array('A','BUTTON'),true) && !empty($element['rect'][2]) && isset($element['widthValue'])) {
                $controlWidths[$index]['target']=$target;
                $controlWidths[$index]['views'][$width]=array('value'=>$element['widthValue'],'borderBox'=>$element['rect'][2]);
            }
        }
    }
    foreach($controlWidths as $index=>$control) {
        $percent=array_column($control['views'],'value');
        if(in_array('100%',$percent,true) && in_array('auto',$percent,true)) {
            $features['responsive-cta-width|'.$index]=array(
                'capability'=>'responsive-cta-width','target'=>$control['target'],'views'=>$control['views']);
        }
    }
    return array_values($features);
}

function syndamorph_repair_content_signature(array $node): array {
    $result=array();
    if(isset($node['text']))$result[]=array('text'=>$node['text'],'href'=>$node['href']??null,'type'=>$node['type']??null);
    if(($node['type']??null)==='tabs')$result[]=array('type'=>'tabs','id'=>$node['id']??null,'panels'=>$node['panels']??null);
    foreach($node['children']??array() as $child)if(is_array($child))$result=array_merge($result,syndamorph_repair_content_signature($child));
    return $result;
}

/** Find the nearest documented common container across distinct paired text branches. */
function syndamorph_repair_reference_frame(array $elements,array $pairs): ?array {
    $common=null;
    foreach($pairs as $pair){
        $parent=$pair['reference']['parent']??null;
        $chain=array();
        while(is_int($parent)&&isset($elements[$parent])&&!isset($chain[$parent])){
            $chain[$parent]=true;
            $parent=$elements[$parent]['parent']??null;
        }
        $common=$common===null?$chain:array_intersect_key($common,$chain);
        if(!$common)return null;
    }
    $key=$common?array_key_first($common):null;
    return $key===null?null:$elements[$key];
}

/**
 * In a vertical Stack, a sole constrained child can shrink and stay at the
 * start edge even though its own children have a valid contentSize.
 */
function syndamorph_repair_constrained_parent(array $node,array $reference,array $native): ?array {
    $inner=$node['children'][0]??array();
    if(($node['type']??'')!=='group'||
        array_key_exists('contentWidth',$node['style']??array()) ||
        !in_array($node['style']['justifyContent']??'left',array('left','center'),true) ||
        count($node['children']??array())!==1||($inner['type']??'')!=='group'||
        !isset($inner['id'],$inner['style']['contentWidth'])||!$reference||count($reference)!==count($native))return null;
    $needsStretch=false;$nativePadding=false;$referenceHasPadding=false;
    foreach($reference as $i=>$sample) {
        $ref=$sample['snapshot']['elements']??array();
        $out=$native[$i]['snapshot']['elements']??array();
        $bounded=array_values(array_filter($out,static fn($el)=>($el['node']??null)===$inner['id']));
        $pairs=syndamorph_collab_pairs($ref,$out,array('blocks'=>array($node)));
        $pairs=array_values(array_filter($pairs,static fn($pair)=>
            ($pair['reference']['visible']??true)&&($pair['native']['visible']??true)));
        $frame=count($pairs)>=2?syndamorph_repair_reference_frame($ref,$pairs):null;
        if($frame===null||!isset($out[0],$bounded[0]))return null;
        $root=$ref[0]['rect']??array();$r=$frame['rect']??array();$n=$bounded[0]['rect']??array();
        $nativeRoot=$out[0]['rect']??array();
        if(count($root)!==4||count($r)!==4||count($n)!==4||count($nativeRoot)!==4||
            ($out[0]['node']??null)!==$node['id']||($out[0]['styles']['display']??null)!=='flex')return null;
        $center=static fn(array $box):float=>$box[0]+$box[2]/2;
        if(abs($center($root)-$center($r))>2)return null;
        $needsStretch=$needsStretch||abs($center($nativeRoot)-$center($n))>12;
        $referenceHasPadding=$referenceHasPadding||($frame['styles']['padding']??null)!=='0px';
        $nativePadding=$nativePadding||(isset($bounded[0]['styles']['padding'])&&$bounded[0]['styles']['padding']!=='0px');
    }
    if(!$needsStretch)return null;
    $changes=array(array('target'=>$node['id'],'path'=>'style.justifyContent','value'=>'stretch'));
    if(!$referenceHasPadding && $nativePadding && !isset($inner['style']['padding']))
        $changes[]=array('target'=>$inner['id'],'path'=>'style.padding','value'=>0);
    return array('changes'=>$changes,
        'diagnosis'=>'The centered reference column and the native constrained Group have different parent allocation. Stretch the sole inner Group so its constraint can center its children.');
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
