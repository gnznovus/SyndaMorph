<?php
/** Recorded and native verification for a desktop hover grid with a static compact variant. */
if (!defined('ABSPATH')) exit;

function syndamorph_reveal_layout_inventory(array $source): ?array {
    $copyByWidth=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $view=$source['variants'][$width]??array();
        $inventory=$view['inventory']??array();
        $flow=$view['facts']['flow']??array();
        $copy=$inventory['text']??null;$controls=$inventory['controls']??null;
        $desktop=$width===1440;
        if(($view['kind']??null)!=='content'||($inventory['assets']??null)!==array()||($inventory['links']??null)!==array()||
            !preg_match('/^(?:[a-z][a-z0-9-]{0,20})?\[(?:data-dc-tpl|id)="[a-zA-Z0-9_-]{1,64}"\]$/D',$view['sourceRef']??'')||
            !is_array($copy)||!array_is_list($copy)||count($copy)!==($desktop?30:7)||!is_array($controls)||
            array_column($controls,'type')!==($desktop?array_fill(0,4,'div'):array())||
            ($flow['display']??null)!==($desktop?'grid':'flex')||($flow['padding']??null)!==($desktop?'96px 60px 120px':'56px 20px')||
            ($flow['gap']??null)!==($desktop?'24px':'16px')||
            array_filter($copy,static fn($text)=>!is_string($text)||trim($text)===''||mb_strlen($text)>900)||
            syndamorph_source_text(implode(' ',$copy))!==($view['facts']['copy']??null))return null;
        $copyByWidth[$width]=$copy;
    }
    return $copyByWidth[768]===$copyByWidth[375]&&array_slice($copyByWidth[1440],0,3)===array_slice($copyByWidth[375],0,3)?
        $copyByWidth:null;
}

function syndamorph_reveal_layout_replay(array $source,array $archive) {
    $fail=static fn(string $message,array $data=array())=>new WP_Error('sm_source_reveal_evidence',$message,$data+array('status'=>409));
    $copy=syndamorph_reveal_layout_inventory($source);
    if(!$copy||!syndamorph_production_reference_valid($archive,$archive['sourceUrl']??'')||empty($archive['runtimeSha256'])||
        !syndamorph_internal_configured('backend'))
        return $fail('The reveal layout needs a verified recorded source.');
    $pageCopy=array_column($archive['views'],'copySha256','width');$views=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $ref=$source['variants'][$width]['sourceRef'];$desktop=$width===1440;
        $response=syndamorph_internal_request('backend','POST','/production-touch',array('timeout'=>35,'redirection'=>0,'limit_response_size'=>32000,
            'headers'=>array('Content-Type'=>'application/json'),
            'body'=>wp_json_encode(array('archiveId'=>$archive['id'],'kind'=>'reveal-cards','width'=>$width,'sourceRef'=>$ref))));
        $view=is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200?null:json_decode(wp_remote_retrieve_body($response),true);
        if(($view['version']??null)!==1||($view['archiveId']??null)!==$archive['id']||($view['mode']??null)!=='recorded'||
            ($view['width']??null)!==$width||($view['sourceRef']??null)!==$ref||($view['status']??null)!=='observed'||
            ($view['sourceCopySha256']??null)!==$pageCopy[$width]||($view['blockedRequestCount']??null)!==0||
            ($view['modeAtWidth']??null)!==($desktop?'hover-reveal':'static')||!is_array($view['cards']??null)||
            !array_is_list($view['cards'])||count($view['cards'])!==($desktop?4:0)){
            $reason=$view['reason']??null;
            return $fail('Recorded reveal evidence is incomplete.',array('width'=>$width,
                'reason'=>is_string($reason)&&preg_match('/^[a-z_]{1,64}$/D',$reason)?$reason:'receipt_mismatch'));
        }
        foreach($view['cards'] as $index=>$card){
            $parts=array_slice($copy[$width],6+6*$index,6);
            $text=array_merge(array($parts[0].' '.$parts[1]),array_slice($parts,2));
            $digest=hash('sha256',wp_json_encode($text,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $id=substr(hash('sha256',wp_json_encode(array($ref,$index,$digest),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),0,24);
            if(($card['id']??null)!==$id||($card['index']??null)!==$index||($card['copy']??null)!==$text||
                ($card['copySha256']??null)!==$digest||($card['opacity']??null)!==array(0,1,1,0)||
                ($card['actions']??null)!==array('hover','click-label','leave')||($card['keyboard']??null)!=='unavailable-in-source'||
                ($card['destination']??null)!=='absent'||($card['paint']??null)!=='none'||($card['color']??null)!=='rgba(17, 17, 17, 0.82)'||
                ($card['padding']??null)!=='28px'||($card['gap']??null)!=='14px'||($card['extent']??null)!==array(368,368)||
                !preg_match('/^[a-f0-9]{64}$/D',$card['initialSha256']??'')||!preg_match('/^[a-f0-9]{64}$/D',$card['revealedSha256']??'')||
                $card['initialSha256']===$card['revealedSha256'])return $fail('The observed reveal does not match the pinned card.',
                    array('width'=>$width,'card'=>$index));
        }
        $views[$width]=$view;
    }
    return $views;
}

function syndamorph_reveal_layout_css(): string {
    $file=dirname(__DIR__).'/assets/production-reveal-layout.css';
    $css=is_readable($file)?file_get_contents($file):false;
    return is_string($css)?str_replace("\r\n","\n",$css):'';
}

add_action('enqueue_block_assets',static function():void {
    wp_register_style('syndamorph-reveal-layout',false,array(),null);
    wp_enqueue_style('syndamorph-reveal-layout');
    wp_add_inline_style('syndamorph-reveal-layout',syndamorph_reveal_layout_css());
});

add_filter('render_block_core/group',static function(string $html,array $block): string {
    if(!preg_match('/^sm-reveal-card sm-reveal-id-([a-f0-9]{24})$/D',$block['attrs']['className']??'',$match))return $html;
    $caption=$block['innerBlocks'][0]['innerBlocks'][0]['innerHTML']??'';
    $label=trim(wp_strip_all_tags(html_entity_decode($caption,ENT_QUOTES,'UTF-8')));
    if($label===''||mb_strlen($label)>180)return $html;
    $tags=new WP_HTML_Tag_Processor($html);
    if(!$tags->next_tag('DIV'))return $html;
    $tags->set_attribute('data-sm-reveal-card',$match[1]);
    $tags->set_attribute('tabindex','0');
    $tags->set_attribute('role','group');
    $tags->set_attribute('aria-label',$label);
    return $tags->get_updated_html();
},10,2);

function syndamorph_reveal_layout_content(array $source,array $receipt): ?string {
    $copy=syndamorph_reveal_layout_inventory($source);
    $cards=$receipt['views'][1440]['cards']??array();
    if(!$copy||count($cards)!==4)return null;
    $group=static fn($class,$children='')=>'<!-- wp:group '.wp_json_encode(array('className'=>$class,'layout'=>array('type'=>'default')))." -->\n".
        '<div class="wp-block-group '.esc_attr($class).'">'.$children."</div>\n<!-- /wp:group -->\n";
    $p=static fn($class,$text)=>'<!-- wp:paragraph '.wp_json_encode(array('className'=>$class))." -->\n".
        '<p class="'.$class.'">'.esc_html($text)."</p>\n<!-- /wp:paragraph -->\n";
    $intro=static fn($text)=>$p('sm-reveal-kicker',$text[0]).'<!-- wp:heading {"className":"sm-reveal-title"} -->'."\n".
        '<h2 class="wp-block-heading sm-reveal-title">'.esc_html($text[1])."</h2>\n<!-- /wp:heading -->\n".
        $p('sm-reveal-description',$text[2]);
    $desktop=$copy[1440];$compact=$copy[375];$grid='';
    foreach($cards as $card){
        list($caption,$description,$label,$title,$detail)=$card['copy'];
        $panel=$group('sm-reveal-panel',$p('sm-reveal-panel-copy',$description).$p('sm-reveal-label',$label));
        $cover=$group('sm-reveal-cover',$p('sm-reveal-cover-caption',$caption).$panel);
        $grid.=$group('sm-reveal-card sm-reveal-id-'.$card['id'],$cover.$p('sm-reveal-card-title',$title).$p('sm-reveal-card-detail',$detail));
    }
    $map=$group('sm-reveal-map',$p('sm-reveal-map-caption',$desktop[3]).$p('sm-reveal-map-detail',$desktop[4]));
    $large=$group('sm-reveal-desktop',$group('sm-reveal-intro',$intro($desktop).$map.$p('sm-reveal-explore',$desktop[5])).
        $group('sm-reveal-grid',$grid));
    $smallCards='';
    foreach(array_slice($compact,3,2) as $title)
        $smallCards.=$group('sm-reveal-compact-card',$group('sm-reveal-cover').$p('sm-reveal-card-title',$title));
    $small=$group('sm-reveal-compact',$intro($compact).$group('sm-reveal-grid',$smallCards).
        $group('sm-reveal-map',$p('sm-reveal-map-caption',$compact[5])).$p('sm-reveal-explore',$compact[6]));
    return rtrim($group('sm-reveal-layout',$large.$small));
}

function syndamorph_reveal_layout_quality(array $source,array $receipt,string $content) {
    $widths=SYNDAMORPH_SOURCE_WIDTHS;$native=syndamorph_native_core_preview(parse_blocks($content));
    $frames=array();$reference=array();
    foreach($widths as $width){
        $view=$source['variants'][$width];$frames[$width]=$view['bounds']['y'];
        $reference[]=array('width'=>$width,'extent'=>(int)round($view['bounds']['height']),'screenshot'=>$view['screenshot']);
    }
    $build=null;
    $views=syndamorph_collab_inspect($native,$widths,$build,'section',$frames);
    if(is_wp_error($views))return $views;
    $visual=syndamorph_source_visual($reference,$views);
    $sha=hash('sha256',$content);
    $responsive=syndamorph_native_responsive_receipt($native,$widths,$views,$build,$sha);
    $probe=array(1440,1024,1023,768,375);
    $response=syndamorph_internal_request('renderer','POST','/check-reveal-cards',array('timeout'=>60,'redirection'=>0,'limit_response_size'=>64000,
        'headers'=>array('Content-Type'=>'application/json'),'body'=>wp_json_encode(array('html'=>$native,'viewports'=>$probe))));
    $data=is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200?null:json_decode(wp_remote_retrieve_body($response),true);
    $checks=$data['evidence']??array();
    $ok=array_column($checks,'width')===$probe;
    $expected=$receipt['views'][1440]['cards'];
    foreach($checks as $check){
        $width=$check['width'];$sample=$check['proof']??array();$active=$width>=1024;
        $cards=$active?$expected:array();
        $ok=$ok&&($sample['insidePage']??null)===true&&($sample['keyboard']??null)===array_column($cards,'id')&&
            (!$active||($sample['closed']??null)===true)&&count($sample['cards']??array())===count($cards);
        foreach($cards as $index=>$card){
            $actual=$sample['cards'][$index]??array();
            foreach(array('id','copy','opacity','paint','color','padding','gap') as $field)
                $ok=$ok&&($actual[$field]??null)===$card[$field];
            $ok=$ok&&($actual['focusable']??null)===true&&($actual['labelInert']??null)===true&&
                ($width!==1440||($actual['extent']??null)===$card['extent']);
        }
    }
    $markup=syndamorph_source_verify_markup($source,$views,null,array(1440=>$ok));
    return array('artifactSha256'=>$sha,'stylesheetSha256'=>hash('sha256',syndamorph_reveal_layout_css()),
        'passed'=>$ok&&$markup['passed']&&$visual['passed']&&$responsive['status']==='passed',
        'markup'=>$markup,'visual'=>$visual,'responsive'=>$responsive,'functional'=>array('passed'=>$ok,'views'=>$checks));
}
