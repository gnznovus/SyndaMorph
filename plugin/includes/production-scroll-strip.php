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
    $frame=syndamorph_production_font_frame($html);
    if(is_wp_error($frame))return $results;
    foreach(array_chunk($widths,5) as $batch){
        $response=wp_remote_post('http://renderer:3000/check-scroll-strips',array('timeout'=>65,'redirection'=>0,
            'limit_response_size'=>64000,'headers'=>array('Content-Type'=>'application/json'),
            'body'=>wp_json_encode(array('html'=>$frame['html'],'viewports'=>$batch),JSON_UNESCAPED_SLASHES)));
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

function syndamorph_scroll_strip_keyboard_requirements(array $specification,array $profile): array {
    $strips=array_values(array_filter($specification['interactions']??array(),static fn($item)=>
        $item['kind']==='horizontal-scroll'&&$item['classification']==='native'&&$item['id']===$profile['id']));
    if(count($strips)!==1)return array();
    $requirements=array_filter($specification['requirements'],static function(array $item)use($strips): bool {
        return ($item['target']??null)===$strips[0]['target']&&$item['dimension']==='accessibility'&&
            $item['basis']==='standard'&&!isset($item['check'])&&
            $item['requirement']==='Strip is keyboard operable with a visible focus indicator meeting WCAG 2.2 AA focus visibility.'&&
            $item['acceptance']==='Keyboard focus is visible on strip and controls; browser behavior is marked unverified until compiled artifact check.';
    });
    return array_column($requirements,'id');
}

/** Bind separate source-strip and inert-fragment requirements to their native evidence. */
function syndamorph_scroll_strip_source_checks(array $specification,array $profile,array $artifact,array $samples,
    array $source,array $sourceQa,array $native): array {
    $id=$profile['id'];
    $components=array_values(array_filter($specification['interactions']??array(),static fn($item)=>
        $item['id']===$id&&$item['kind']==='horizontal-scroll'&&$item['classification']==='native'));
    if(count($components)!==1||($native[$id]['status']??null)!=='verified')return array();
    $out=array();
    $widths=array_keys($profile['copyByWidth']);
    $inert=!empty($sourceQa['passed'])&&empty($artifact['tabWidgets'])&&
        !preg_match('~\b(?:href|src)\s*=|<\s*(?:script|form|iframe|embed|object)\b|https?://~i',$artifact['preview']);
    foreach($specification['requirements'] as $requirement){
        if($requirement['dimension']!=='functional')continue;
        $key=$requirement['id'];
        $expect='At '.implode(' and ',$widths).' implement native horizontal scroll group id '.$id.
            ' with two cards, 80% card basis, 12px gap, 20px end padding and start snapping. Visibility: '.$profile['visibility'].'.';
        if($requirement['basis']==='source'&&$requirement['target']===$components[0]['target']&&
            $requirement['requirement']===$expect&&
            $requirement['acceptance']==='Group exists with the exact ID and two direct cards; the second card is empty; the strip starts at card one.'&&
            $profile['cards']===2&&array_filter($profile['cardCopyByWidth'],static fn($copy)=>($copy[1]??null)!=='')===array()){
            $out[$key]=array('status'=>'verified','check'=>'source-scroll-layout','component'=>$id,
                'viewports'=>array_column($native[$id]['viewports'],'width'));
        }
        if($inert&&$requirement['basis']==='brief'&&
            $requirement['requirement']==="Reservation labels 'Reserve a table', 'Explore' and 'All Sip & Dine →' remain plain text with no link or booking destination."&&
            $requirement['acceptance']==='No link, button href or invented destination exists for these labels in the native plan.')
            $out[$key]=array('status'=>'verified','check'=>'inert-source-fragment');
        if($inert&&$requirement['basis']==='source'&&$requirement['target']===$components[0]['target']&&
            $requirement['requirement']==="Initial state shows the first card at the start of the strip and the indicator text '1 / 3' as captured text."&&
            $requirement['acceptance']==="Strip starts at scrollLeft 0; '1 / 3' is plain text and does not update."){
            $matched=array();
            foreach($samples as $sample){
                $width=$sample['width'];
                if(!in_array($width,SYNDAMORPH_SOURCE_WIDTHS,true))continue;
                $indicators=array_values(array_filter($sample['snapshot']['elements']??array(),static fn($element)=>
                    ($element['visible']??null)===true&&($element['normalizedText']??null)==='1 / 3'));
                $active=in_array($width,$widths,true);
                if(count($indicators)!==(int)$active||($active&&(($indicators[0]['tag']??null)!=='P'||
                    ($indicators[0]['keyboardReachable']??null)!==false||!empty($indicators[0]['role']))))break;
                $matched[]=$width;
            }
            if($matched===SYNDAMORPH_SOURCE_WIDTHS)
                $out[$key]=array('status'=>'verified','check'=>'static-source-scroll-indicator','component'=>$id,'widths'=>$widths);
        }
        if($requirement['basis']!=='brief'||
            $requirement['requirement']!=='No reservation integration, forms, iframes, external requests, made-up assets, extra cards or publishing. Booking labels stay as plain copy.'||
            $requirement['acceptance']!=='Zero href attributes, zero scripts, zero external URLs in the fragment; exactly the captured cards exist.'||
            !$inert||
            array_column($samples,'width')!==SYNDAMORPH_SOURCE_WIDTHS)continue;
        $counts=array();
        foreach($samples as $sample){
            $width=$sample['width'];
            $captured=preg_match_all('/\baspect-ratio\s*:\s*4\s*\/\s*3\b/',$source['variants'][$width]['html']??'');
            $rendered=count(array_filter($sample['snapshot']['elements']??array(),static fn($element)=>
                ($element['visible']??null)===true&&in_array('sm-aspect-4-3',$element['classes']??array(),true)));
            if(!$captured||$rendered!==$captured)break;
            $counts[$width]=$rendered;
        }
        if(array_keys($counts)===SYNDAMORPH_SOURCE_WIDTHS)
            $out[$key]=array('status'=>'verified','check'=>'inert-source-fragment','cardCounts'=>$counts,
                'publishing'=>'excluded by the private draft workflow');
    }
    return $out;
}
