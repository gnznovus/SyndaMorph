<?php
/** Native, mutually exclusive WordPress Details groups. */
if(!defined('ABSPATH'))exit;

function syndamorph_disclosures_supported(): bool {
    if(!class_exists('WP_Block_Type_Registry'))return false;
    $details=WP_Block_Type_Registry::get_instance()->get_registered('core/details');
    return ($details->attributes['name']['source']??null)==='attribute'&&
        ($details->attributes['name']['attribute']??null)==='name'&&isset($details->attributes['showContent']);
}

function syndamorph_disclosures_css(): string {
    $item=':is(.sm-disclosure-group,[data-sm-disclosure-reference])>details';
    $bordered=':is(.sm-disclosure-bordered,[data-sm-disclosure-reference][data-sm-disclosure-appearance="bordered"])';
    return $item.'>summary{display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer}'.
        $item.'>summary::-webkit-details-marker{display:none}'.
        $item.'>summary::after{content:"";flex:0 0 20px;width:20px;height:20px;background:currentColor;'.
        'clip-path:polygon(22.4% 37.5%,27.6% 32.3%,50% 54.7%,72.4% 32.3%,77.6% 37.5%,50% 65.1%)}'.
        $item.'[open]>summary::after{transform:rotate(180deg)}'.
        $item.'>summary:focus-visible{outline:2px solid currentColor;outline-offset:2px}'.
        $bordered.'{border-top:1px solid #111}'.
        $bordered.'>details{border-bottom:1px solid #d7d3d3;margin:0}'.
        $bordered.'>details>summary{padding:18px 0;gap:16px;font-size:16px;font-weight:600;line-height:1.55;letter-spacing:0}'.
        $bordered.'>details>p{line-height:1.65;margin:0;padding:0 0 18px}'.
        '@media(min-width:1024px){'.$bordered.'>details>summary{padding:24px 0;gap:24px;font-size:20px}'.
        $bordered.'>details>p{line-height:1.7;padding:0 48px 24px 0}}';
}

if(function_exists('add_action'))add_action('enqueue_block_assets',static function():void{
    wp_register_style('syndamorph-disclosures',false);
    wp_enqueue_style('syndamorph-disclosures');
    wp_add_inline_style('syndamorph-disclosures',syndamorph_disclosures_css());
});

/** Scope browser exclusivity to this rendered instance, including repeated patterns. */
if(function_exists('add_filter'))add_filter('render_block_core/group',static function(string $html):string {
    if(!str_contains($html,'sm-disclosure-group')||!class_exists('WP_HTML_Tag_Processor'))return $html;
    $tag=new WP_HTML_Tag_Processor($html);
    if(!$tag->next_tag()||!$tag->has_class('sm-disclosure-group'))return $html;
    $name=wp_unique_id('sm-disclosure-instance-');
    while($tag->next_tag(array('tag_name'=>'DETAILS','class_name'=>'wp-block-details'))){
        $original=$tag->get_attribute('name');
        if(is_string($original)&&preg_match('/^sm-disclosure-[a-z][a-z0-9-]{0,47}$/D',$original)&&
            !str_starts_with($original,'sm-disclosure-instance-'))$tag->set_attribute('name',$name);
    }
    return $tag->get_updated_html();
});

/** Exact ordered copy and interaction proof from isolated Chromium. */
function syndamorph_disclosures_measure(string $html,array $widths,array $expected): array {
    $result=array_fill_keys(array_keys($expected),array('status'=>'uncertain','viewports'=>array()));
    if(!$expected||getenv('SYNDAMORPH_RENDERER_URL')!=='http://renderer:3000/render')return $result;
    $frame=syndamorph_production_font_frame($html);
    if(is_wp_error($frame))return $result;
    foreach(array_chunk($widths,5) as $batch){
        $response=wp_remote_post('http://renderer:3000/check-disclosures',array('timeout'=>65,'redirection'=>0,
            'limit_response_size'=>128000,'headers'=>array('Content-Type'=>'application/json'),
            'body'=>wp_json_encode(array('html'=>$frame['html'],'viewports'=>$batch))));
        $data=is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200?null:
            json_decode(wp_remote_retrieve_body($response),true);
        if(!is_array($data['evidence']??null)||array_column($data['evidence'],'width')!==$batch)return $result;
        foreach($data['evidence'] as $view){
            $checks=$view['checks']??null;
            if(!is_array($checks)||array_column($checks,'component')!==array_keys($expected))return $result;
            foreach($checks as $check){
                $id=$check['component'];
                $passed=($check['copy']??null)===$expected[$id]&&($check['structure']??null)===true&&
                    ($check['initial']??null)===true&&($check['clicks']??null)===true&&
                    ($check['keyboard']??null)===true&&($check['indicator']??null)===true&&($check['isolation']??null)===true;
                $result[$id]['viewports'][]=array('width'=>$view['width'],'passed'=>$passed,'proof'=>$check);
            }
        }
    }
    foreach($result as &$receipt){
        if($widths&&array_column($receipt['viewports'],'width')===$widths)
            $receipt['status']=in_array(false,array_column($receipt['viewports'],'passed'),true)?'failed':'verified';
    }
    unset($receipt);
    return $result;
}

function syndamorph_disclosures_verify(array $artifact,array $widths,?string $reference=null): array {
    $expected=$artifact['disclosureGroups']??array();
    $native=syndamorph_disclosures_measure($artifact['preview'],$widths,$expected);
    if($reference===null||!in_array('verified',array_column($native,'status'),true))return $native;
    $design=syndamorph_disclosures_measure($reference,$widths,$expected);
    foreach($native as $id=>&$receipt){
        if($receipt['status']!=='verified')continue;
        $receipt['reference']=$design[$id]??null;
        if(($design[$id]['status']??null)!=='verified')$receipt['status']=$design[$id]['status']??'uncertain';
    }
    unset($receipt);
    return $native;
}
