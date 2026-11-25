<?php
/** Bounded native-plan surgery shared by compiler recovery and browser QA. */
if (!defined('ABSPATH')) exit;

/** Short-lived administrator-private working draft, never a public endpoint. */
function syndamorph_surgery_save(array $plan, string $key=''): string {
    $encoded=wp_json_encode($plan);
    if(!is_string($encoded)||strlen($encoded)>120000)return '';
    if($key==='' )$key='sm_surgery_'.bin2hex(random_bytes(12));
    if(!preg_match('/^sm_surgery_[a-f0-9]{24}$/D',$key))return '';
    $prior=get_transient($key);
    $saved=set_transient($key,array('plan'=>$plan,'sha256'=>hash('sha256',$encoded),
        'revision'=>is_array($prior)?(int)($prior['revision']??0)+1:1,
        'compilerRevision'=>syndamorph_learning_revision()),86400);
    return $saved ? $key : '';
}
function syndamorph_surgery_load(string $key): ?array {
    if(!preg_match('/^sm_surgery_[a-f0-9]{24}$/D',$key))return null;
    $item=get_transient($key);
    if(!is_array($item)||!is_array($item['plan']??null))return null;
    return hash_equals((string)($item['sha256']??''),hash('sha256',wp_json_encode($item['plan'])))?$item:null;
}
/** A compiler field identifies the smallest relevant node and local style key. */
function syndamorph_surgery_extract(array $plan,array $diagnostic): ?array {
    $field=(string)($diagnostic['field']??'');
    if(!preg_match('/^(blocks\\[0\\](?:\\.children\\[[0-9]{1,3}\\])*)(?:\\.(style(?:\\.[a-zA-Z][a-zA-Z0-9]*){0,3}|[a-zA-Z][a-zA-Z0-9]*))?$/D',$field,$matches))return null;
    $steps=array();
    preg_match_all('/\\.children\\[([0-9]{1,3})\\]/',$matches[1],$indices);
    $node=$plan['blocks'][0]??null;
    foreach($indices[1] as $index){$node=$node['children'][(int)$index]??null;}
    if(!is_array($node)||!is_string($node['id']??null))return null;
    // The target may be the root group of a large five-section design.
    // Surgical context must never drag the complete descendants into a model call.
    $local=$node;$childCount=count($local['children']??array());unset($local['children']);
    return array('id'=>$node['id'],'field'=>$field,'property'=>$matches[2]??'',
        'code'=>(string)($diagnostic['code']??''),'message'=>mb_substr((string)($diagnostic['message']??''),0,180),
        'node'=>$local,'childCount'=>$childCount);
}
/** Only native style attributes are patchable; content, IDs and siblings are immutable. */
/**
 * Field-only transaction. QA may edit a descendant of an issue-owning group,
 * but never a node outside that group's existing subtree.
 */
function syndamorph_surgery_apply(array $plan,array $proposal,?array $authorized=null) {
    $owner=$proposal['id']??null;$changes=$proposal['changes']??null;
    if(!is_string($owner)||!is_array($changes)||!array_is_list($changes)||
        count($changes)<1||count($changes)>8)
        return new WP_Error('sm_surgery_contract','Expected {id,changes:[{path,value,target?}]} with at most eight changes.');
    $base=$plan;
    $scope=syndamorph_collab_find($plan['blocks'],$owner);
    if($scope===null)return new WP_Error('sm_surgery_target','Assigned repair component not found.');
    $allowed=array('color','background','gradient','fontSize','fontWeight','lineHeight','letterSpacing',
        'padding','margin','gap','minHeight','contentWidth','justifyContent','verticalAlignment','align','borderRadius');
    $touches=array();
    foreach($changes as $change){
        if(!is_array($change)||!is_string($change['path']??null)||
            !preg_match('/^style\\.([a-zA-Z]+)(?:\\.(top|right|bottom|left))?$/D',$change['path'],$m)||
            !in_array($m[1],$allowed,true)||
            !in_array($change['op']??'set',array('set','remove'),true)||
            (($change['op']??'set')==='set'&&!array_key_exists('value',$change))||
            (isset($m[2])&&!in_array($m[1],array('padding','margin'),true)))
            return new WP_Error('sm_surgery_scope','Surgery may only update supported native style properties.');
        $id=$change['target']??$owner;
        if(!is_string($id))return new WP_Error('sm_surgery_scope','Invalid patch target.');
        if($authorized!==null && !in_array($id,$authorized,true))
            return new WP_Error('sm_surgery_scope','Patch target was not authorized for this operation.');
        // Resolve against the assigned subtree first; global lookup alone would
        // allow unrelated siblings and approved design components to be edited.
        $within=array($scope);
        if(syndamorph_collab_find($within,$id)===null)
            return new WP_Error('sm_surgery_scope','Patch target escapes assigned component subtree.');
        $target=&syndamorph_collab_find($plan['blocks'],$id);
        if($target===null)return new WP_Error('sm_surgery_target','Patch target missing.');
        $remove=($change['op']??'set')==='remove';
        if(isset($m[2])){
            $spacing=$target['style'][$m[1]]??null;
            if(!is_array($spacing)) {
                $target['style'][$m[1]]=$spacing===null?array():array_fill_keys(array('top','right','bottom','left'),$spacing);
            }
            if($remove)unset($target['style'][$m[1]][$m[2]]);
            else $target['style'][$m[1]][$m[2]]=$change['value'];
            if(!$target['style'][$m[1]])unset($target['style'][$m[1]]);
        }elseif($remove)unset($target['style'][$m[1]]);
        else $target['style'][$m[1]]=$change['value'];
        $touches[$id]=true;
        unset($target);
    }
    if(syndamorph_repair_content_signature($base['blocks'][0])!==syndamorph_repair_content_signature($plan['blocks'][0]))
        return new WP_Error('sm_surgery_content','Surgical patch changed protected content.');
    $unique=syndamorph_collab_validate_ids($plan);
    if(is_wp_error($unique))return $unique;
    $compile=syndamorph_compile_block_plan(wp_json_encode($plan));
    return array('plan'=>$plan,'artifact'=>is_wp_error($compile)?null:$compile,
        'error'=>is_wp_error($compile)?$compile:null,
        'impact'=>syndamorph_collab_impact($base,$plan),'touched'=>array_keys($touches));
}

/** Apply one authorized style or layout operation to an isolated copy. */
function syndamorph_surgery_operation(array $plan,array $proposal,array $authority) {
    $id=$authority['id'];
    $authorized=$authority['nodes'];
    $changes=$proposal['changes']??array();
    $wraps=$proposal['wraps']??array();
    if(($proposal['id']??null)!==$id || !is_array($changes)||!array_is_list($changes) ||
        !is_array($wraps)||!array_is_list($wraps) || count($wraps)>2 ||
        count($changes)+count($wraps)<1 || count($changes)+count($wraps)>8) {
        return new WP_Error('sm_surgery_contract','Expected one to eight scoped style changes or authorized wraps.');
    }
    if($wraps && ($authority['mode']??'style')!=='structure') {
        return new WP_Error('sm_surgery_scope','Structural changes require a structural repair assignment.');
    }
    if(!$wraps)return syndamorph_surgery_apply($plan,array('id'=>$id,'changes'=>$changes),$authorized);

    $candidate=$plan;
    $structureTargets=array();
    foreach($wraps as $wrap) {
        if(!is_array($wrap) || array_diff(array_keys($wrap),array('parent','children','id','style'))) {
            return new WP_Error('sm_surgery_structure','A wrap must specify parent, children, new group id and style.');
        }
        $parentId=$wrap['parent']??null;$groupId=$wrap['id']??null;
        $children=$wrap['children']??null;$style=$wrap['style']??array();
        if(!is_string($parentId)||!in_array($parentId,$authority['wrapParents']??array(),true) ||
            !is_string($groupId)||!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$groupId) ||
            syndamorph_collab_find($candidate['blocks'],$groupId)!==null ||
            !is_array($children)||!array_is_list($children)||count($children)<2||count($children)>12 ||
            !is_array($style)||array_diff(array_keys($style),array('contentWidth','justifyContent','verticalAlignment','gap','minHeight','padding'))) {
            return new WP_Error('sm_surgery_structure','Wrap exceeds the assigned native layout scope.');
        }
        foreach($children as $child)if(!is_string($child)||!in_array($child,$authorized,true)) {
            return new WP_Error('sm_surgery_structure','Wrap would move an unauthorized block.');
        }
        $parent=&syndamorph_collab_find($candidate['blocks'],$parentId);
        if($parent===null||!in_array($parent['type'],array('group','column'),true)) {
            return new WP_Error('sm_surgery_structure','Wrap parent must be an existing group or column.');
        }
        $childIds=array_column($parent['children'],'id');
        $start=array_search($children[0],$childIds,true);
        if($start===false||array_slice($childIds,$start,count($children))!==$children) {
            return new WP_Error('sm_surgery_structure','A wrap must preserve the order of consecutive direct children.');
        }
        $group=array('id'=>$groupId,'type'=>'group','style'=>$style,
            'children'=>array_slice($parent['children'],$start,count($children)));
        array_splice($parent['children'],$start,count($children),array($group));
        unset($parent);
        array_push($structureTargets,$parentId,$groupId,...$children);
    }
    // Only the combined structure and final styles are compiled.
    $operation=$changes ? syndamorph_surgery_apply($candidate,array('id'=>$id,'changes'=>$changes),$authorized):
        syndamorph_surgery_verify($plan,$candidate);
    if(is_wp_error($operation)||is_wp_error($operation['error']??null))return $operation;
    if(syndamorph_repair_content_signature($plan['blocks'][0])!==syndamorph_repair_content_signature($operation['plan']['blocks'][0])) {
        return new WP_Error('sm_surgery_content','Structural surgery changed approved content or its order.');
    }
    $operation['error']=null;
    $operation['touched']=array_values(array_unique(array_merge($operation['touched']??array(),$structureTargets)));
    $operation['impact']=syndamorph_collab_impact($plan,$operation['plan']);
    return $operation;
}
/**
 * Convert a safe deterministic old-style replacement to field-only surgery.
 * Never accept structural or copy changes from this compatibility adapter.
 */
function syndamorph_surgery_from_replacement(array $node,array $patch) {
    if(($patch['id']??null)!==($node['id']??null)||!is_array($patch['replacement']??null))return null;
    $after=$patch['replacement'];
    if(($after['type']??null)!==($node['type']??null))return null;
    $a=$node;$c=$after;unset($a['style'],$c['style']);
    if($a!==$c)return null;
    $oldStyle=$node['style']??array();$newStyle=$after['style']??array();
    $changes=array();
    foreach(array_unique(array_merge(array_keys($oldStyle),array_keys($newStyle))) as $key){
        if(($oldStyle[$key]??null)===($newStyle[$key]??null))continue;
        if(!array_key_exists($key,$newStyle))$changes[]=array('path'=>'style.'.$key,'op'=>'remove');
        else $changes[]=array('path'=>'style.'.$key,'value'=>$newStyle[$key]);
    }
    return $changes&&count($changes)<=8?array('id'=>$node['id'],'changes'=>$changes):null;
}
/** A compact, bounded set of existing nodes; never ship entire subtrees for QA. */
function syndamorph_surgery_context(array $node,int $max=14): array {
    $out=array();
    $walk=static function(array $n,int $depth)use(&$walk,&$out,$max):void{
        if(count($out)>=$max)return;
        $entry=array('id'=>$n['id']??'', 'type'=>$n['type']??'', 'depth'=>$depth,
            'style'=>$n['style']??array());
        if(isset($n['text']))$entry['text']=mb_substr((string)$n['text'],0,120);
        if(isset($n['href']))$entry['href']=mb_substr((string)$n['href'],0,100);
        $out[]=$entry;
        foreach($n['children']??array() as $child)if(is_array($child))$walk($child,$depth+1);
    };
    $walk($node,0);
    return $out;
}
/** Deterministic corrections are permitted only when semantics are unambiguous. */
function syndamorph_surgery_normalize(array $plan,array $diagnostic): ?array {
    $focus=syndamorph_surgery_extract($plan,$diagnostic);
    if(!$focus)return null;
    $target=&syndamorph_collab_find($plan['blocks'],$focus['id']);
    if($target===null)return null;
    $before=$target;
    $key=$focus['property'];
    $nativeStyle=array('color','background','gradient','fontSize','fontWeight','lineHeight','letterSpacing',
        'padding','margin','gap','minHeight','contentWidth','justifyContent','verticalAlignment','align','borderRadius');
    // Unsupported node-level style properties can be moved without changing their values.
    if(in_array($key,$nativeStyle,true)&&array_key_exists($key,$target)&&!array_key_exists($key,$target['style']??array())){
        $target['style'][$key]=$target[$key];unset($target[$key]);
    }elseif($key==='style.typography' && is_array($target['style']['typography']??null)){
        $nested=$target['style']['typography'];$supported=array('fontSize','fontWeight','lineHeight','letterSpacing');
        if(array_diff(array_keys($nested),$supported) || array_intersect(array_keys($nested),array_keys($target['style'])))return null;
        unset($target['style']['typography']);foreach($nested as $k=>$v)$target['style'][$k]=$v;
    }elseif($key==='style.color' && is_array($target['style']['color']??null)){
        $nested=$target['style']['color'];$mapping=array('text'=>'color','background'=>'background','gradient'=>'gradient');
        if(array_diff(array_keys($nested),array_keys($mapping)))return null;
        foreach($nested as $k=>$v)if(array_key_exists($mapping[$k],$target['style']))return null;
        unset($target['style']['color']);foreach($nested as $k=>$v)$target['style'][$mapping[$k]]=$v;
    }elseif($key==='style.margin' && ($target['type']??'')==='buttons' &&
        ($diagnostic['message']??'')==='Unsupported style for block.' &&
        array_key_exists('margin',$target['style']??array())){
        // Buttons margins are not part of Gutenberg plan v1. Removing them is
        // schema-safe, but may affect spacing: the existing browser QA remains
        // mandatory before final delivery. Never migrate a margin speculatively.
        unset($target['style']['margin']);
    }elseif($key==='style.gradient' &&
        ($diagnostic['message']??'')==='Invalid two-color gradient.' &&
        in_array($target['type']??'',array('group','column','button'),true) &&
        is_string($target['style']['gradient']??null)){
        // Equivalent two-stop endpoint percentages are redundant CSS syntax.
        $gradient=trim($target['style']['gradient']);
        if(!preg_match('/^linear-gradient\\(\\s*(\\d{1,3})deg\\s*,\\s*(#[0-9a-f]{6})\\s+0%\\s*,\\s*(#[0-9a-f]{6})\\s+100%\\s*\\)$/iD',
            $gradient,$stop)|| (int)$stop[1]>360)return null;
        $target['style']['gradient']='linear-gradient('.$stop[1].'deg,'.$stop[2].','.$stop[3].')';
    }elseif(preg_match('/^style\\.(color|background)$/D',$key,$m) &&
        is_string($target['style'][$m[1]]??null)&&preg_match('/^#([a-f0-9]{3})$/iD',$target['style'][$m[1]],$hex)){
        $target['style'][$m[1]]='#'.implode('',array_map(static fn($v)=>$v.$v,str_split($hex[1])));
    }else return null;
    if($target===$before)return null;
    return array('plan'=>$plan,'id'=>$focus['id'],'property'=>$key);
}
/** Deterministic compiler-first transaction: never modify input or commit failed patches. */
function syndamorph_compiler_auto_repair(array $plan,array $diagnostic) {
    $candidate=syndamorph_surgery_normalize($plan,$diagnostic);
    if($candidate===null)return null;
    $verified=syndamorph_surgery_verify($plan,$candidate['plan']);
    $code=$diagnostic['code']??'';
    $field=$diagnostic['field']??'';
    $message=$diagnostic['message']??'';
    if(is_wp_error($verified) &&
        $verified->get_error_code()===$code &&
        ($verified->get_error_data()['field']??'')===$field &&
        $verified->get_error_message()===$message)return null;
    if($candidate['plan']===$plan)return null;
    // The caller persists the candidate only if this known compiler defect
    // advances. Other remaining schema defects are repaired in later rounds.
    return array('plan'=>$candidate['plan'],
        'compiled'=>!is_wp_error($verified),'next'=>$verified,
        'field'=>$field,'repair'=>$candidate['property']??'');
}
/** Both compiler and QA submit to an identical transactional validation boundary. */
function syndamorph_surgery_verify(array $before,array $candidate,?callable $visualGate=null) {
    $compile=syndamorph_compile_block_plan(wp_json_encode($candidate));
    if(is_wp_error($compile))return $compile;
    if(is_wp_error(syndamorph_collab_validate_ids($candidate)))return new WP_Error('sm_surgery_identity','Identity regression.');
    if($visualGate!==null && $visualGate($compile,$candidate) !== true)
        return new WP_Error('sm_surgery_visual','Candidate did not pass visual regression gates.');
    return array('plan'=>$candidate,'artifact'=>$compile,'impact'=>syndamorph_collab_impact($before,$candidate));
}
