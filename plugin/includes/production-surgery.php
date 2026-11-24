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
    set_transient($key,array('plan'=>$plan,'sha256'=>hash('sha256',$encoded),
        'revision'=>is_array($prior)?(int)($prior['revision']??0)+1:1,
        'compilerRevision'=>syndamorph_learning_revision()),86400);
    return $key;
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
    return array('id'=>$node['id'],'field'=>$field,'property'=>$matches[2]??'',
        'code'=>(string)($diagnostic['code']??''),'message'=>mb_substr((string)($diagnostic['message']??''),0,180),
        'node'=>$node);
}
/** Only native style attributes are patchable; content, IDs and siblings are immutable. */
function syndamorph_surgery_apply(array $plan,array $proposal) {
    if(!is_string($proposal['id']??null)||!is_array($proposal['changes']??null)||
        count($proposal['changes'])<1||count($proposal['changes'])>8)return new WP_Error('sm_surgery_contract','Expected {id,changes:[{path,value}]} with at most eight changes.');
    $target=&syndamorph_collab_find($plan['blocks'],$proposal['id']);
    if($target===null)return new WP_Error('sm_surgery_target','Component not found.');
    $before=$target;
    $allowed=array('color','background','gradient','fontSize','fontWeight','lineHeight','letterSpacing',
        'padding','margin','gap','minHeight','contentWidth','justifyContent','verticalAlignment','align','borderRadius');
    foreach($proposal['changes'] as $change){
        if(!is_array($change)||!is_string($change['path']??null)||
            !preg_match('/^style\\.([a-zA-Z]+)(?:\\.(top|right|bottom|left))?$/D',$change['path'],$m)||
            !in_array($m[1],$allowed,true)||
            !in_array($change['op']??'set',array('set','remove'),true)||
            (($change['op']??'set')==='set'&&!array_key_exists('value',$change))||
            (isset($m[2])&&!in_array($m[1],array('padding','margin'),true)))
            return new WP_Error('sm_surgery_scope','Surgery may only update supported style properties.');
        $remove=($change['op']??'set')==='remove';
        if(isset($m[2])){
            if(!is_array($target['style'][$m[1]]??null))return new WP_Error('sm_surgery_scope','Side edits require existing spacing object.');
            if($remove)unset($target['style'][$m[1]][$m[2]]);
            else $target['style'][$m[1]][$m[2]]=$change['value'];
        }elseif($remove)unset($target['style'][$m[1]]);
        else $target['style'][$m[1]]=$change['value'];
    }
    if(syndamorph_repair_content_signature($before)!==syndamorph_repair_content_signature($target))
        return new WP_Error('sm_surgery_content','Surgical patch changed protected content.');
    $unique=syndamorph_collab_validate_ids($plan);
    if(is_wp_error($unique))return $unique;
    $compile=syndamorph_compile_block_plan(wp_json_encode($plan));
    return array('plan'=>$plan,'artifact'=>is_wp_error($compile)?null:$compile,
        'error'=>is_wp_error($compile)?$compile:null,'impact'=>syndamorph_collab_impact(
            array('blocks'=>array($before)),array('blocks'=>array($target))));
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
    }elseif(preg_match('/^style\\.(color|background)$/D',$key,$m) &&
        is_string($target['style'][$m[1]]??null)&&preg_match('/^#([a-f0-9]{3})$/iD',$target['style'][$m[1]],$hex)){
        $target['style'][$m[1]]='#'.implode('',array_map(static fn($v)=>$v.$v,str_split($hex[1])));
    }else return null;
    if($target===$before)return null;
    return array('plan'=>$plan,'id'=>$focus['id'],'property'=>$key);
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
