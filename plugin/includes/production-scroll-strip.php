<?php
/** Local scroll strips built from editable core Group blocks. */
if (!defined('ABSPATH')) exit;

function syndamorph_scroll_strip_css(): string {
    return '.wp-block-group.sm-scroll-strip{display:flex;flex-flow:row nowrap;align-items:stretch;'.
        'gap:12px;padding-inline-end:20px;width:100%;min-width:0;max-width:100%;box-sizing:border-box;'.
        'overflow-x:auto;scrollbar-width:none;scroll-snap-type:x mandatory}'.
        '.wp-block-group.sm-scroll-strip>:is(.wp-block-group){flex:0 0 80%;min-width:0;max-width:80%;'.
        'margin-inline:0;scroll-snap-align:start}'.
        '.wp-block-group.sm-scroll-strip:focus-visible{outline:2px solid currentColor;outline-offset:-2px}';
}

if(function_exists('add_filter'))add_filter('render_block_core/group',static function(string $html): string {
    if(!str_contains($html,'sm-scroll-strip')||!class_exists('WP_HTML_Tag_Processor'))return $html;
    $tag=new WP_HTML_Tag_Processor($html);
    if(!$tag->next_tag()||!$tag->has_class('sm-scroll-strip'))return $html;
    $tag->set_attribute('tabindex','0');
    $tag->set_attribute('role','group');
    $tag->set_attribute('aria-label',__('Scrollable cards','syndamorph'));
    return $tag->get_updated_html();
});

if(function_exists('add_action'))add_action('enqueue_block_assets',static function():void {
    wp_register_style('syndamorph-scroll-strip',false);
    wp_enqueue_style('syndamorph-scroll-strip');
    wp_add_inline_style('syndamorph-scroll-strip',syndamorph_scroll_strip_css());
});

/** Measure the scroll strip in either the approved sketch or WordPress preview. */
function syndamorph_scroll_strip_measure(string $html,array $widths,array $expected): array {
    if(!$expected)return array();
    $results=array_fill_keys(array_keys($expected),array('status'=>'uncertain','viewports'=>array()));
    if(getenv('SYNDAMORPH_RENDERER_URL')!=='http://renderer:3000/render')return $results;
    foreach(array_chunk($widths,5) as $batch){
        $response=wp_remote_post('http://renderer:3000/check-scroll-strips',array('timeout'=>65,'redirection'=>0,
            'limit_response_size'=>64000,'headers'=>array('Content-Type'=>'application/json'),
            'body'=>wp_json_encode(array('html'=>$html,'viewports'=>$batch))));
        $data=is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200?null:
            json_decode(wp_remote_retrieve_body($response),true);
        if(!is_array($data['evidence']??null)||array_column($data['evidence'],'width')!==$batch)return $results;
        foreach($data['evidence'] as $view){
            $checks=$view['checks']??null;
            if(!is_array($checks))return $results;
            $ids=array_column($checks,'component');
            if(count($ids)!==count($checks)||array_filter($ids,static fn($id)=>!is_string($id))||
                count(array_unique($ids))!==count($ids)||array_diff($ids,array_keys($expected)))return $results;
            $checks=array_column($checks,null,'component');
            foreach($expected as $id=>$strip){
                $width=$view['width'];
                $required=$width<($strip['visibility']==='mobile'?600:1024);
                $check=$checks[$id]??null;
                $passed=$required?(($check['visible']??null)===true&&($check['cards']??null)===$strip['cards']&&
                    ($check['layout']??null)===true&&($check['wheel']??null)===true&&($check['keyboard']??null)===true&&
                    ($check['initial']??null)===true&&($check['end']??null)===true&&($check['focusVisible']??null)===true):
                    (($check['visible']??null)===false);
                $results[$id]['viewports'][]=array('width'=>$width,'required'=>$required,'passed'=>$passed,'proof'=>$check);
            }
        }
    }
    foreach($results as &$result){
        $active=array_filter($result['viewports'],static fn($view)=>$view['required']);
        if(array_column($result['viewports'],'width')!==$widths||!$active)continue;
        $result['status']=in_array(false,array_column($result['viewports'],'passed'),true)?'failed':'verified';
    }
    unset($result);
    return $results;
}

function syndamorph_scroll_strip_verify(array $artifact,array $widths,string $reference): array {
    $expected=$artifact['scrollStrips']??array();
    $native=syndamorph_scroll_strip_measure($artifact['preview'],$widths,$expected);
    if(!in_array('verified',array_column($native,'status'),true))return $native;
    $design=syndamorph_scroll_strip_measure($reference,$widths,$expected);
    foreach($native as $id=>&$result){
        if($result['status']!=='verified')continue;
        if(($design[$id]['status']??null)!=='verified'){
            $result['status']=$design[$id]['status']??'uncertain';
            $result['reason']='approved_scroll_evidence_missing_or_failed';
            continue;
        }
        foreach($result['viewports'] as $index=>&$view){
            if(!$view['required'])continue;
            $digest=$view['proof']['copySha256']??null;
            $view['referenceCopySha256']=$design[$id]['viewports'][$index]['proof']['copySha256']??null;
            $view['sameOrderedCardCopy']=is_string($digest)&&preg_match('/^[a-f0-9]{64}$/D',$digest)===1&&
                $digest===$view['referenceCopySha256'];
            if(!$view['sameOrderedCardCopy'])$result['status']='failed';
        }
        unset($view);
    }
    unset($result);
    return $native;
}
