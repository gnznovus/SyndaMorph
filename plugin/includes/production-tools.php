<?php
/** Explicit shared tool surface for the AI Production Team. Orchestrator owns calls. */
if(!defined('ABSPATH'))exit;
/** Runtime tool grants are per-role, not per-artifact. */
function syndamorph_production_tool_grants(string $role): array {
    return array(
        'boss'=>array('playwright.inspect','playwright.compare','playwright.crop'),
        'designer'=>array('playwright.inspect','playwright.compare','playwright.crop'),
        'developer'=>array('playwright.inspect','playwright.compare','playwright.crop','compiler.validate','compiler.patch'),
        'validator'=>array('playwright.inspect','playwright.compare','playwright.crop')
    )[$role]??array();
}
function syndamorph_production_tool(string $role,string $name,array $args) {
    if(!in_array($name,syndamorph_production_tool_grants($role),true))
        return new WP_Error('sm_tool_denied','Requested tool is not granted to this role.');
    switch($name){
        case 'playwright.inspect':
            $html=$args['html']??null;
            $viewports=$args['viewports']??array(1440,768,375);
            if(!is_string($html)||!is_array($viewports))return syndamorph_inspection_failure('request_invalid');
            return syndamorph_collab_inspect($html,$viewports);
        case 'playwright.compare':
            if(!is_array($args['reference']??null)||!is_array($args['native']??null))
                return new WP_Error('sm_tool_input','Comparison requires both evidence sets.');
            return syndamorph_collab_compare($args['reference'],$args['native']);
        case 'playwright.crop':
            if(!is_array($args['reference']??null)||!is_array($args['native']??null)||!is_array($args['texts']??null))
                return new WP_Error('sm_tool_input','Crop requires evidence and a text selector.');
            $primary=$args['primary']??null;
            if($primary!==null && (!is_int($primary) || !isset($args['reference'][$primary],$args['native'][$primary])))
                return new WP_Error('sm_tool_input','Crop viewport is not present in the evidence.');
            return syndamorph_collab_scoped_images($args['reference'],$args['native'],
                array_slice(array_filter($args['texts'],'is_string'),0,12),$primary);
        case 'compiler.validate':
            if(!is_array($args['plan']??null))return new WP_Error('sm_tool_input','Native plan missing.');
            return syndamorph_compile_block_plan(wp_json_encode($args['plan']));
        case 'compiler.patch':
            if(!is_array($args['plan']??null)||!is_array($args['patch']??null))
                return new WP_Error('sm_tool_input','Native plan and surgical patch required.');
            return syndamorph_surgery_apply($args['plan'],$args['patch']);
    }
    return new WP_Error('sm_tool_unknown','Unknown tool.');
}

/**
 * Global on-demand, read-only tool access. The orchestrator supplies artifacts;
 * agents cannot substitute URLs, markup, filesystem paths or write operations.
 */
function syndamorph_production_tool_catalog(string $role): array {
    $catalog=array(
        'playwright.inspect'=>array('purpose'=>'Inspect rendered reference at required viewports; return browser-computed styles, layout and accessibility evidence.',
            'readOnly'=>true,'targets'=>array('reference')),
        'playwright.compare'=>array('purpose'=>'Compare reference and native screenshots and metrics.','readOnly'=>true,'targets'=>array('reference','native')),
        'playwright.crop'=>array('purpose'=>'Extract targeted screenshot evidence.','readOnly'=>true,'targets'=>array('reference','native')),
        'compiler.validate'=>array('purpose'=>'Validate native Gutenberg plan.','readOnly'=>true,'targets'=>array('plan')),
        'compiler.patch'=>array('purpose'=>'Apply a validated native-block patch.','readOnly'=>false,'targets'=>array('plan')),
    );
    return array_intersect_key($catalog,array_flip(syndamorph_production_tool_grants($role)));
}
function syndamorph_production_tool_evidence(array $samples,string $reason=''): array {
    $result=array('reason'=>$reason,'source'=>'playwright.computed','viewports'=>array());
    foreach($samples as $sample) {
        if(!is_array($sample))continue;
        $elements=array();
        foreach(array_slice((array)($sample['snapshot']['elements']??array()),0,40) as $element){
            $styles=(array)($element['styles']??array());
            $elements[]=array('tag'=>$element['tag']??'','id'=>$element['id']??'',
                'text'=>mb_substr((string)($element['text']??''),0,110),
                'fontWeight'=>$styles['fontWeight']??null,
                'fontFamily'=>$styles['fontFamily']??null,
                'fontSize'=>$styles['fontSize']??null,
                'color'=>$styles['color']??null,
                'rect'=>$element['rect']??array());
        }
        $result['viewports'][]=array('width'=>$sample['width']??null,
            'overflow'=>$sample['snapshot']['overflow']??null,
            'elements'=>$elements,'accessibilityIssueCount'=>count((array)($sample['a11y']??array())));
    }
    return $result;
}
/**
 * Per-job cache-first invocation. Shared across roles by artifact+tool identity,
 * with bounded calls and audit receipts. No model can supply executable HTML.
 */
function syndamorph_production_tool_request(string $role,array $request,array $artifacts,
    ?callable $on_event=null) {
    $name=$request['name']??null;
    $reason=$request['reason']??null;
    $target=$request['target']??null;
    if(!is_string($name)||!is_string($reason)||strlen(trim($reason))<8||
        strlen($reason)>260||!is_string($target))
        return new WP_Error('sm_tool_request_contract','Tool request requires name, target and a bounded reason.');
    $catalog=syndamorph_production_tool_catalog($role);
    if(!isset($catalog[$name])||!($catalog[$name]['readOnly']??false)||
        !in_array($target,$catalog[$name]['targets'],true))
        return new WP_Error('sm_tool_denied','Requested tool or target is unavailable to this agent.');
    // First integration enables only supported single-artifact Playwright inspection.
    // Other capabilities remain discoverable but are not implicitly executable.
    if($name!=='playwright.inspect'||$target!=='reference')
        return new WP_Error('sm_tool_unavailable','This tool needs a dedicated bounded artifact adapter.');
    $html=$artifacts['reference']??null;
    $viewports=$artifacts['viewports']??null;
    if(!is_string($html)||!is_array($viewports)||!$viewports)
        return new WP_Error('sm_tool_input','Reference HTML and viewports are required.');
    $key=hash('sha256',$name."\0".hash('sha256',$html)."\0".wp_json_encode($viewports));
    if(!isset($GLOBALS['syndamorph_production_tool_cache']))
        $GLOBALS['syndamorph_production_tool_cache']=array();
    $cache=&$GLOBALS['syndamorph_production_tool_cache'];
    $hit=isset($cache[$key]);
    if(!$hit) {
        $count=(int)($GLOBALS['syndamorph_production_tool_count']??0);
        if($count>=12)return new WP_Error('sm_tool_budget','Job diagnostic tool-call ceiling reached.');
        $GLOBALS['syndamorph_production_tool_count']=$count+1;
        $samples=syndamorph_production_tool($role,$name,array('html'=>$html,'viewports'=>$viewports));
        if(is_wp_error($samples))return $samples;
        $cache[$key]=$samples;
    }
    $receipt=array('role'=>$role,'tool'=>$name,'target'=>$target,'artifactSha256'=>hash('sha256',$html),
        'cache'=>$hit?'hit':'miss','reason'=>$reason,'viewports'=>$viewports);
    if($on_event)$on_event('tool_invocation',$receipt);
    return array('receipt'=>$receipt,'evidence'=>syndamorph_production_tool_evidence($cache[$key],$reason));
}

/** Seed the global diagnostic cache from an inspection already required by QA. */
function syndamorph_production_tool_seed(string $html,array $viewports,array $samples): void {
    $key=hash('sha256','playwright.inspect'."\0".hash('sha256',$html)."\0".wp_json_encode($viewports));
    if(!isset($GLOBALS['syndamorph_production_tool_cache']))
        $GLOBALS['syndamorph_production_tool_cache']=array();
    $GLOBALS['syndamorph_production_tool_cache'][$key]=$samples;
}

/** Return the original, validated renderer evidence for an identical artifact. */
function syndamorph_production_tool_cached_inspection(string $html,array $viewports): ?array {
    $key=hash('sha256','playwright.inspect'."\0".hash('sha256',$html)."\0".wp_json_encode($viewports));
    return $GLOBALS['syndamorph_production_tool_cache'][$key]??null;
}

/**
 * Resolve a model-requested diagnostic without handing the model raw tool
 * arguments. One fresh follow-up response per tool request, depth-limited.
 * Saved tool-request responses can be replayed deterministically on Resume.
 */
function syndamorph_production_tool_followup(string $reply,string $role,string $stage,
    string $instruction,array $context,int $cap,array $images,float $reserve,
    array $viewports,?callable $on_event,callable $call) {
    $parsed=json_decode(syndamorph_native_json_text($reply),true);
    if(!is_array($parsed)||($parsed['action']??'')!=='tool_request')return null;
    $depth=(int)($context['_toolDepth']??0);
    if($depth>=2)return new WP_Error('sm_tool_loop',
        'Agent repeated tool requests instead of completing its assigned work.');
    $request=(array)($parsed['toolRequest']??array());
    $reference=$context['reference']??($GLOBALS['syndamorph_current_reference']??null);
    $toolResult=syndamorph_production_tool_request($role,$request,
        array('reference'=>$reference,'viewports'=>$viewports),$on_event);
    if(is_wp_error($toolResult))return $toolResult;
    $context['_toolDepth']=$depth+1;
    $context['toolEvidence']=$toolResult;
    $context['toolFollowup']='Use the attached measured evidence to complete the original task. Do not request the same tool repeatedly.';
    return $call($role,$instruction,$context,$cap,$images,$stage,$reserve);
}
