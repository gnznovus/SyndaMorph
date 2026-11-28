<?php
/** Source-bound room carousel, category disclosures and compact room strip. */
if (!defined('ABSPATH')) exit;

function syndamorph_rooms_inventory(array $source): ?array {
    $copy=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $view=$source['variants'][$width]??array();
        $inventory=$view['inventory']??array();
        $desktop=$width===1440;
        $words=$inventory['text']??null;
        if(($view['kind']??null)!=='content'||($inventory['assets']??null)!==array()||($inventory['links']??null)!==array()||
            !is_array($words)||!array_is_list($words)||count($words)!==($desktop?28:15)||
            !is_array($inventory['controls']??null)||array_column($inventory['controls'],'type')!==
                ($desktop?array_merge(array_fill(0,5,'button'),array_fill(0,4,'div')):array())||
            !preg_match('/^(?:[a-z][a-z0-9-]{0,20})?\[(?:data-dc-tpl|id)="[a-zA-Z0-9_-]{1,64}"\]$/D',$view['sourceRef']??'')||
            ($view['facts']['flow']['padding']??null)!==($desktop?'96px 60px 120px':'56px 0px 56px 20px')||
            array_filter($words,static fn($word)=>!is_string($word)||trim($word)===''||mb_strlen($word)>900)||
            syndamorph_source_text(implode(' ',$words))!==($view['facts']['copy']??null))return null;
        $copy[$width]=$words;
    }
    return $copy[768]===$copy[375]&&array_slice($copy[1440],0,3)===array_slice($copy[375],0,3)?$copy:null;
}

function syndamorph_rooms_replay(array $source,array $archive) {
    $fail=static fn(string $reason,array $data=array())=>new WP_Error('sm_source_rooms_evidence',$reason,$data+array('status'=>409));
    $copy=syndamorph_rooms_inventory($source);
    if(!$copy||!syndamorph_production_reference_valid($archive,$archive['sourceUrl']??'')||empty($archive['runtimeSha256'])||
        !syndamorph_internal_configured('backend'))
        return $fail('The Rooms showcase needs a verified recorded source.');
    $pageCopy=array_column($archive['views'],'copySha256','width');$views=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $ref=$source['variants'][$width]['sourceRef'];$desktop=$width===1440;
        $response=syndamorph_internal_request('backend','POST','/production-touch',array('timeout'=>45,'redirection'=>0,'limit_response_size'=>32000,
            'headers'=>array('Content-Type'=>'application/json'),
            'body'=>wp_json_encode(array('archiveId'=>$archive['id'],'kind'=>'room-showcase','width'=>$width,'sourceRef'=>$ref))));
        $view=is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200?null:json_decode(wp_remote_retrieve_body($response),true);
        if(($view['version']??null)!==1||($view['archiveId']??null)!==$archive['id']||($view['mode']??null)!=='recorded'||
            ($view['width']??null)!==$width||($view['sourceRef']??null)!==$ref||($view['status']??null)!=='observed'||
            ($view['sourceCopySha256']??null)!==$pageCopy[$width]||($view['blockedRequestCount']??null)!==0||
            ($view['modeAtWidth']??null)!==($desktop?'desktop-showcase':'static-strip'))
            return $fail('Recorded Rooms evidence is incomplete.',array('width'=>$width,'reason'=>$view['reason']??'receipt_mismatch'));
        if($desktop){
            $expectedSteps=array(array('initial',0,0),array('previous-wrap',2,0),array('next-wrap',0,0),array('direct-photo',2,0),
                array('category-1',0,1),array('category-2',0,2),array('category-3',0,3),array('category-0',0,0),array('category-0',0,0),
                array('label-'.$copy[1440][3],0,0),array('label-'.$copy[1440][17],0,0),array('label-'.$copy[1440][18],0,0));
            $automatic=$view['automatic']??array();
            if(($view['steps']??null)!==$expectedSteps||($view['slides']??null)!==array_slice($copy[1440],4,3)||
                ($view['slideWidth']??null)!==760||($view['externalNavigation']??null)!=='absent'||
                !is_array($view['categories']??null)||count($view['categories'])!==4||count($automatic)!==2||
                ($automatic[0][0]??null)!==1||($automatic[1][0]??null)!==2||!is_int($automatic[0][1]??null)||
                !is_int($automatic[1][1]??null)||abs($automatic[1][1]-$automatic[0][1]-4000)>350)
                return $fail('The desktop Rooms replay did not match the pinned states.',array('width'=>$width));
            foreach($view['categories'] as $index=>$category){
                $header=array_slice($copy[1440],$index===0?10:16+$index*3,3);
                $detail=$category['details']??null;
                $original=array_slice($copy[1440],13,6);
                if(($category['header']??null)!==$header||!is_array($detail)||count($detail)!==6||
                    array_filter($detail,static fn($word)=>!is_string($word)||trim($word)===''||mb_strlen($word)>900)||
                    ($index===0?$detail!==$original:array_intersect_key($detail,array_flip(array(0,2,4,5)))!==
                        array_intersect_key($original,array_flip(array(0,2,4,5)))))
                    return $fail('A room category differs from the pinned copy.',array('width'=>$width,'index'=>$index));
            }
        }else{
            $strip=$view['strip']??array();
            if(($strip['copy']??null)!==array_slice($copy[$width],3,4)||($strip['wheel']??null)!==array(0,$width===375?138:0,0)||
                ($strip['keyboard']??null)!==($width===375?40:null)||
                ($view['inertLabels']??null)!==array($copy[$width][4],$copy[$width][13],$copy[$width][14]))
                return $fail('The compact room strip differs from the recorded evidence.',array('width'=>$width));
        }
        $views[$width]=$view;
    }
    return $views;
}

function syndamorph_rooms_asset(string $filename): string {
    $path=dirname(__DIR__).'/assets/'.$filename;
    $content=is_readable($path)?file_get_contents($path):false;
    return is_string($content)?str_replace("\r\n","\n",$content):'';
}

function syndamorph_rooms_fingerprint(): string {
    return hash('sha256',syndamorph_rooms_asset('production-rooms.css')."\0".syndamorph_rooms_asset('production-rooms-view.js'));
}

add_action('enqueue_block_assets',static function():void {
    wp_enqueue_style('syndamorph-room-showcase',plugins_url('assets/production-rooms.css',dirname(__DIR__).'/syndamorph.php'),
        array(),syndamorph_rooms_fingerprint());
});
add_action('init',static function():void {
    wp_register_script('syndamorph-room-showcase',plugins_url('assets/production-rooms-view.js',dirname(__DIR__).'/syndamorph.php'),
        array(),syndamorph_rooms_fingerprint(),true);
});

add_filter('render_block_core/group',static function(string $html,array $block): string {
    $class=$block['attrs']['className']??'';
    if($class==='sm-rooms-layout'){
        wp_enqueue_script('syndamorph-room-showcase');
        return $html;
    }
    if(!in_array($class,array('sm-rooms-controls','sm-rooms-carousel','sm-rooms-category-header','sm-rooms-strip'),true)&&
        !preg_match('/^sm-rooms-(?:category|detail) sm-rooms-(?:category|detail)-[0-3]$/D',$class))return $html;
    $tags=new WP_HTML_Tag_Processor($html);
    if(!$tags->next_tag('DIV'))return $html;
    if($class==='sm-rooms-carousel')$tags->set_attribute('data-sm-room-photo','0');
    elseif($class==='sm-rooms-category-header'){
        $tags->set_attribute('data-sm-room-category','');$tags->set_attribute('role','button');$tags->set_attribute('tabindex','0');
        $tags->set_attribute('aria-expanded','false');
    }elseif($class==='sm-rooms-strip'){
        $tags->set_attribute('tabindex','0');$tags->set_attribute('role','group');
        $tags->set_attribute('aria-label',__('Room categories','syndamorph'));
    }elseif(str_starts_with($class,'sm-rooms-category '))$tags->set_attribute('data-sm-room-active',str_ends_with($class,'-0')?'true':'false');
    elseif(str_starts_with($class,'sm-rooms-detail ')&&!str_ends_with($class,'-0'))$tags->set_attribute('hidden',true);
    elseif($class==='sm-rooms-controls'){
        $dots='';
        for($i=0;$i<3;$i++)$dots.='<button type="button" data-sm-room-dot="'.$i.'" aria-current="'.($i===0?'true':'false').'" aria-label="'.
            esc_attr(sprintf(__('Go to photo %d','syndamorph'),$i+1)).'"></button>';
        $pause=esc_attr(__('Pause slideshow','syndamorph'));$resume=esc_attr(__('Resume slideshow','syndamorph'));
        $controls='<div class="sm-rooms-dots">'.$dots.'</div>'.
            '<button type="button" class="sm-rooms-arrow" data-sm-room-previous aria-label="'.esc_attr(__('Previous photo','syndamorph')).'">←</button>'.
            '<button type="button" class="sm-rooms-arrow" data-sm-room-next aria-label="'.esc_attr(__('Next photo','syndamorph')).'">→</button>'.
            '<button type="button" data-sm-room-pause data-pause-label="'.$pause.'" data-resume-label="'.$resume.'" aria-label="'.$pause.'" aria-pressed="false"></button>';
        $at=strrpos($html,'</div>');
        return $at===false?$html:substr_replace($html,$controls,$at,0);
    }
    return $tags->get_updated_html();
},10,2);

function syndamorph_rooms_content(array $source,array $receipt): ?string {
    $copy=syndamorph_rooms_inventory($source);
    $desktop=$receipt['views'][1440]??array();
    if(!$copy||count($desktop['categories']??array())!==4||($desktop['slides']??null)!==array_slice($copy[1440],4,3))return null;
    $group=static fn($class,$children='',$anchor=null)=>'<!-- wp:group '.wp_json_encode(array('className'=>$class,'layout'=>array('type'=>'default'))+
        ($anchor!==null?array('anchor'=>$anchor):array()))." -->\n".
        '<div'.($anchor!==null?' id="'.esc_attr($anchor).'"':'').' class="wp-block-group '.esc_attr($class).'">'.$children."</div>\n<!-- /wp:group -->\n";
    $p=static fn($class,$text)=>'<!-- wp:paragraph '.wp_json_encode(array('className'=>$class))." -->\n".
        '<p class="'.$class.'">'.esc_html($text)."</p>\n<!-- /wp:paragraph -->\n";
    $intro=static fn($text)=>$group('sm-rooms-intro',$p('sm-rooms-kicker',$text[0]).'<!-- wp:heading {"className":"sm-rooms-title"} -->'."\n".
        '<h2 class="wp-block-heading sm-rooms-title">'.esc_html($text[1])."</h2>\n<!-- /wp:heading -->\n".$p('sm-rooms-description',$text[2]));
    $specs=static fn($parts)=>$group('sm-rooms-specs',$p('sm-rooms-term',$parts[0]).$p('sm-rooms-value',$parts[1]).
        $p('sm-rooms-term',$parts[2]).$p('sm-rooms-value',$parts[3]));
    $actions=static fn($parts)=>$group('sm-rooms-actions',$p('sm-rooms-book',$parts[4]).$p('sm-rooms-more',$parts[5]));
    $slides='';foreach($desktop['slides'] as $label)$slides.=$group('sm-rooms-slide',$p('sm-rooms-slide-label',$label));
    $carousel=$group('sm-rooms-carousel',$group('sm-rooms-track',$slides).$group('sm-rooms-caption',
        $p('sm-rooms-photo-title',$copy[1440][7]).$group('sm-rooms-controls')));
    $categories='';
    foreach($desktop['categories'] as $index=>$category){
        list($name,$indexLabel,$summary)=$category['header'];
        $header=$group('sm-rooms-category-header',$group('sm-rooms-row',$p('sm-rooms-name',$name).
            $p('sm-rooms-index',$indexLabel)).$p('sm-rooms-summary',$summary));
        $categories.=$group('sm-rooms-category sm-rooms-category-'.$index,$header.
            $group('sm-rooms-detail sm-rooms-detail-'.$index,$specs($category['details']).$actions($category['details'])));
    }
    $large=$group('sm-rooms-desktop',$group('sm-rooms-heading',$intro($copy[1440]).$p('sm-rooms-all',$copy[1440][3])).
        $group('sm-rooms-grid',$carousel.$group('sm-rooms-categories',$categories)));
    $mobile=$copy[375];$chips='';
    foreach(array_slice($mobile,3,4) as $label)$chips.=$p('sm-rooms-chip',$label);
    $details=array_slice($mobile,9,6);
    $small=$group('sm-rooms-compact',$intro($mobile).$group('sm-rooms-strip',$chips).
        $group('sm-rooms-mobile-card',$group('sm-rooms-mobile-cover').$p('sm-rooms-name',$mobile[7]).
            $p('sm-rooms-summary',$mobile[8]).$specs($details).$actions($details)));
    $anchor=$source['variants'][1440]['inventory']['anchors'][0]??'rooms';
    return preg_match('/^[A-Za-z][\w-]{0,63}$/D',$anchor)?rtrim($group('sm-rooms-layout',$large.$small,$anchor)):null;
}

function syndamorph_rooms_quality(array $source,array $receipt,string $content) {
    $widths=SYNDAMORPH_SOURCE_WIDTHS;$native=syndamorph_native_core_preview(parse_blocks($content));
    // Capture pinned the second slide, while the recorded runtime starts on the first.
    $visualNative=preg_replace('/data-sm-room-photo="0"/', 'data-sm-room-photo="1"',$native,1);
    $frames=array();$reference=array();
    foreach($widths as $width){
        $view=$source['variants'][$width];
        $frames[$width]=$view['bounds']['y'];
        $reference[]=array('width'=>$width,'extent'=>(int)round($view['bounds']['height']),'screenshot'=>$view['screenshot']);
    }
    $build=null;
    $views=syndamorph_collab_inspect($visualNative,$widths,$build,'section',$frames);
    if(is_wp_error($views))return $views;
    $sha=hash('sha256',$content);
    $visual=syndamorph_source_visual($reference,$views);
    $responsive=syndamorph_native_responsive_receipt($visualNative,$widths,$views,$build,$sha);
    $probe=array(1440,1024,1023,768,375);
    $frame=syndamorph_production_font_frame($native);
    if(is_wp_error($frame))return $frame;
    $response=syndamorph_internal_request('renderer','POST','/check-rooms',array('timeout'=>65,'redirection'=>0,'limit_response_size'=>64000,
        'headers'=>array('Content-Type'=>'application/json'),'body'=>wp_json_encode(array('html'=>$frame['html'],
            'runtime'=>syndamorph_rooms_asset('production-rooms-view.js'),'viewports'=>$probe))));
    $status=is_wp_error($response)?null:wp_remote_retrieve_response_code($response);
    if($status!==200)return new WP_Error('sm_rooms_renderer','Rooms functional evidence is pending.',array('status'=>409,'httpStatus'=>$status));
    $checks=json_decode(wp_remote_retrieve_body($response),true)['evidence']??array();
    $ok=array_column($checks,'width')===$probe;
    $sourceSteps=$receipt['views'][1440]['steps'];$categories=$receipt['views'][1440]['categories'];
    $expected=array(array(0,0));
    foreach(array_slice($sourceSteps,1,8) as $step)
        $expected[]=array($step[1],$step[2],$categories[$step[2]]['header'][0],$categories[$step[2]]['details']);
    foreach($checks as $row){
        $width=$row['width'];$proof=$row['proof']??array();
        if($width>=1024)$ok=$ok&&($proof['steps']??null)===$expected&&($proof['autoplay']??null)===($width===1440);
        else $ok=$ok&&$proof===($receipt['views'][$width===1023?768:$width]['strip']??null);
    }
    $markup=array('passed'=>true,'views'=>array());
    foreach($views as $view){
        $width=$view['width'];$lite=$view['snapshot']['literal']??array();
        $expectedText=$source['variants'][$width]['facts']['copy'];
        $actualText=$view['snapshot']['copy']??null;
        $row=array('width'=>$width,'literalContent'=>$actualText===$expectedText,'links'=>($lite['links']??null)===array(),
            'assets'=>($lite['assets']??null)===0,'functions'=>$ok&&($lite['controls']??null)===($width===1440?10:0));
        if(!$row['literalContent'])$row['copyDifference']=syndamorph_source_copy_difference($expectedText,$actualText);
        $markup['passed']=$markup['passed']&&!in_array(false,$row,true);
        $markup['views'][]=$row;
    }
    return array('artifactSha256'=>$sha,'runtimeSha256'=>syndamorph_rooms_fingerprint(),'passed'=>$ok&&$markup['passed']&&
        $visual['passed']&&$responsive['status']==='passed','visual'=>$visual,'markup'=>$markup,'responsive'=>$responsive,
        'functional'=>array('passed'=>$ok,'views'=>$checks));
}
