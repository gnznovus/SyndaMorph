<?php
/** Editable core blocks for the verified responsive footer profile. */
if(!defined('ABSPATH'))exit;

function syndamorph_footer_css(): string {
    return (string)file_get_contents(dirname(__DIR__).'/assets/production-footer.css');
}

add_action('enqueue_block_assets',static function():void {
    $file='assets/production-footer.css';
    $plugin=dirname(__DIR__).'/syndamorph.php';
    wp_enqueue_style('syndamorph-recorded-footer',plugins_url($file,$plugin),array(),
        (string)filemtime(dirname($plugin).'/'.$file));
});

function syndamorph_footer_profile(array $source): ?array {
    $variants=$source['variants']??array();
    if(count($variants)!==3||!isset($variants[1440],$variants[768],$variants[375]))return null;
    $desktop=$variants[1440]['inventory']??array();
    $copy=$desktop['text']??null;
    $links=$desktop['links']??null;
    $assets=$desktop['assets']??null;
    if(!is_array($copy)||!array_is_list($copy)||count($copy)!==23||
        !is_array($links)||!array_is_list($links)||count($links)!==14||
        !is_array($assets)||!array_is_list($assets)||count($assets)!==1||
        ($assets[0]['type']??null)!=='image'||!syndamorph_header_text($assets[0]['alt']??null))return null;
    $expected=array_merge(array_slice($copy,5,5),array_slice($copy,11,5),array_slice($copy,18,4));
    if(array_column($links,'text')!==$expected)return null;
    foreach($links as $i=>$link){
        if(in_array($i,array(0,10,11,12,13),true)?($link['href']??null)!=='#':!syndamorph_header_url($link['href']??null))
            return null;
    }
    $compact=$variants[768]['inventory']['text']??null;
    if(!is_array($compact)||!array_is_list($compact)||count($compact)!==12||
        $compact!==($variants[375]['inventory']['text']??null)||$copy[0]!==$compact[0]||
        $copy[22]!==$compact[11]||array_intersect_key($compact,array_flip(array(4,6,8,10)))!==array(4=>'+',6=>'+',8=>'+',10=>'+'))return null;
    foreach(array_merge($copy,$compact) as $line)if(!syndamorph_header_text($line))return null;
    foreach(array(1440,768,375) as $width){
        $view=$variants[$width];$inventory=$view['inventory'];$bounds=$view['bounds']??array();
        if(($inventory['assets']??null)!==$assets||($bounds['x']??null)!==0||($bounds['width']??null)!==$width||
            !is_numeric($bounds['y']??null)||!is_numeric($bounds['height']??null)||
            !syndamorph_source_screenshot_valid($view['screenshot']??null,$width,(int)round($bounds['height'])))return null;
        if($width!==1440&&(($inventory['links']??null)!==array()||($inventory['controls']??null)!==array()))return null;
    }
    return array('desktop'=>$copy,'compact'=>$compact,'links'=>$links,'alt'=>$assets[0]['alt']);
}

function syndamorph_footer_content(array $profile,int $logoId): ?string {
    $url=wp_get_attachment_url($logoId);
    if(!$url)return null;
    $group=static function(string $class,array $children,string $tag='div'):string {
        $attrs=array('className'=>$class);
        if($tag==='footer')$attrs+=array('tagName'=>$tag,'align'=>'full');
        $classes=($tag==='footer'?'alignfull ':'').$class;
        return get_comment_delimited_block_content('core/group',$attrs,
            '<'.$tag.' class="wp-block-group '.esc_attr($classes).'">'.implode("\n\n",$children).'</'.$tag.'>');
    };
    $line=static function(string $label,string $class='',?string $href=null):string {
        $text=implode('<br>',array_map('esc_html',explode("\n",$label)));
        if($href!==null)$text='<a href="'.esc_url($href).'">'.$text.'</a>';
        return get_comment_delimited_block_content('core/paragraph',$class===''?array():array('className'=>$class),
            ($class===''?'<p>':'<p class="'.esc_attr($class).'">').$text.'</p>');
    };
    $copy=$profile['desktop'];$compact=$profile['compact'];
    $image=syndamorph_source_image_block($logoId,$url,$profile['alt'],'sm-source-footer-logo');
    $items=array_map(static fn($link)=>$line($link['text'],'',$link['href']),$profile['links']);
    $brand=$group('sm-footer-brand',array($image,$line(implode("\n",array_slice($copy,0,3))),$line($copy[3],'sm-footer-muted')));
    $explore=$group('sm-footer-links sm-footer-explore',array_merge(array($line($copy[4],'sm-footer-heading')),array_slice($items,0,5)));
    $discover=$group('sm-footer-links sm-footer-discover',array_merge(array($line($copy[10],'sm-footer-heading')),array_slice($items,5,5)));
    $boxes=$group('sm-footer-social-boxes',array_fill(0,4,$group('sm-footer-social-box',array())));
    $follow=$group('sm-footer-links sm-footer-follow',array($line($copy[16],'sm-footer-heading'),$boxes,$line($copy[17],'sm-footer-muted')));
    $desktop=$group('sm-view-desktop sm-footer-desktop',array(
        $group('sm-footer-top',array($brand,$explore,$discover,$follow)),
        $group('sm-footer-bottom',array($group('sm-footer-legal',array_slice($items,10,4)),$line($copy[22])))));
    $rows=array($image,$line(implode("\n",array_slice($compact,0,3)),'sm-footer-address'));
    foreach(array(3,5,7,9) as $i)$rows[]=$group('sm-footer-compact-row',array($line($compact[$i]),$line($compact[$i+1])));
    $rows[]=$line($compact[11],'sm-footer-copyright');
    return $group('sm-recorded-footer',array($desktop,$group('sm-view-compact sm-footer-compact',$rows)),'footer');
}

function syndamorph_footer_replay(array $source,array $archive,array $profile) {
    $allowed=array_column($archive['views'],'copySha256','width');
    $asset=null;$original=null;$views=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $variant=$source['variants'][$width];
        $hash=hash('sha256',base64_decode($variant['screenshot'],true));
        $response=syndamorph_internal_request('backend','POST','/production-touch',array('timeout'=>40,
            'limit_response_size'=>170000,'headers'=>array('Content-Type'=>'application/json'),
            'body'=>wp_json_encode(array('kind'=>'recorded-footer','archiveId'=>$archive['id'],'width'=>$width,
                'sourceRef'=>$variant['sourceRef'],'pinnedSha256'=>$hash))));
        if(is_wp_error($response))return $response;
        $row=json_decode(wp_remote_retrieve_body($response),true);
        $desktop=$width===1440;
        $pseudo=$desktop?array_column(array_filter($profile['links'],static fn($link)=>$link['href']==='#'),'text'):array_fill(0,4,'+');
        $expected=array_map(static fn($label)=>array('label'=>$label,'effect'=>$desktop?'scroll_to_document_start':'inert'),$pseudo);
        if(wp_remote_retrieve_response_code($response)!==200||($row['status']??null)!=='observed'||
            ($row['version']??null)!==1||($row['mode']??null)!=='recorded'||($row['archiveId']??null)!==$archive['id']||
            ($row['width']??null)!==$width||($row['sourceRef']??null)!==$variant['sourceRef']||
            ($row['sourceCopySha256']??null)!==($allowed[$width]??null)||($row['pinnedSha256']??null)!==$hash||
            ($row['blockedRequestCount']??null)!==0||($row['local']??null)!==$expected||
            ($row['copy']??null)!==$profile[$desktop?'desktop':'compact']||($row['links']??null)!==($desktop?$profile['links']:array()))
            return new WP_Error('sm_footer_replay','The recorded footer is pending source replay.',
                array('status'=>409,'width'=>$width,'reason'=>$row['reason']??'receipt_mismatch'));
        $logo=$row['logo']??null;
        $bytes=is_array($logo)?syndamorph_archived_png_bytes($logo):null;
        if(!$bytes||($asset!==null&&$asset!==$logo['asset']))
            return syndamorph_scope_error('footer_logo','The original footer logo could not be verified at all widths.');
        $asset=$logo['asset'];$original=$bytes;
        unset($row['logo']);
        $views[]=$row;
    }
    return array('asset'=>$asset,'bytes'=>$original,'views'=>$views);
}

function syndamorph_footer_fingerprint(): ?string {
    $fonts=syndamorph_compact_fingerprint();
    return $fonts?hash('sha256',hash_file('sha256',__FILE__).syndamorph_footer_css().$fonts):null;
}

function syndamorph_footer_receipt(array $manifest,array $region): ?array {
    $event=$region['footerSourceEvent']??null;
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    $profile=$source?syndamorph_footer_profile($source):null;
    $archive=$manifest['referenceArchive']??null;
    $receipt=is_int($event)?syndamorph_scope_checkpoint($manifest['id'],$event,'source_recorded_footer'):null;
    $id=$receipt['logoId']??null;$hash=$receipt['asset']['sha256']??null;
    if(!$profile||!$receipt||!syndamorph_production_reference_valid($archive,$manifest['sourceUrl'])||
        ($receipt['region']??null)!==$region['id']||($receipt['checkpoint']??null)!==$region['checkpoint']||
        ($receipt['archiveId']??null)!==$archive['id']||($receipt['sourceSha256']??null)!==hash('sha256',wp_json_encode($source))||
        !is_int($id)||!is_string($hash)||get_post_meta($id,'_syndamorph_source_strip_sha256',true)!==$hash||
        !syndamorph_attachment_has_original($id,$hash))return null;
    return array('profile'=>$profile,'source'=>$source,'logoId'=>$id,'logoSha256'=>$hash);
}

function syndamorph_footer_quality(array $receipt,string $content) {
    $html=syndamorph_scope_document($content,'Recorded footer',false);
    $font=syndamorph_production_font_frame($html);
    if(is_wp_error($font))return $font;
    $html=syndamorph_image_strip_frame($font['html']);
    if(is_wp_error($html))return $html;
    $fingerprint=syndamorph_footer_fingerprint();
    if(!$fingerprint)return syndamorph_scope_error('footer_fonts','The verified footer fonts are unavailable.');
    $frames=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $view=$receipt['source']['variants'][$width];
        $frames[$width]=array('top'=>$view['bounds']['y'],'extent'=>(int)round($view['bounds']['height']),'image'=>$view['screenshot']);
    }
    $build=null;$passed=true;$proof=array();
    foreach(array(array(1440,1280,1200,1024,1023),array(900,768,600,450,375)) as $widths){
        $response=syndamorph_internal_request('renderer','POST','/check-footer',array('timeout'=>65,
            'limit_response_size'=>60000,'headers'=>array('Content-Type'=>'application/json'),
            'body'=>wp_json_encode(array('html'=>$html,'viewports'=>$widths,
                'frames'=>array_intersect_key($frames,array_flip($widths))))));
        if(is_wp_error($response))return $response;
        $row=json_decode(wp_remote_retrieve_body($response),true);$batch=$row['evidence']??null;
        $current=wp_remote_retrieve_header($response,'x-syndamorph-renderer-build');
        if(wp_remote_retrieve_response_code($response)!==200||!is_array($batch)||array_column($batch,'width')!==$widths||
            (string)wp_remote_retrieve_header($response,'x-syndamorph-inspection-protocol')!==(string)SYNDAMORPH_INSPECTION_PROTOCOL||
            !is_string($current)||!preg_match('/^[a-f0-9]{64}$/D',$current)||($build!==null&&$current!==$build))
            return new WP_Error('sm_footer_renderer','Independent footer browser evidence is pending.',
                array('status'=>409,'widths'=>$widths,'httpStatus'=>wp_remote_retrieve_response_code($response),
                    'reason'=>$row['error']??'incomplete'));
        $build=$current;
        foreach($batch as $sample){
            $width=$sample['width'];$view=$sample['proof']??array();
            $desktop=$width>=1024;$profile=$receipt['profile'];$ratio=$view['ratio']??null;
            $responsive=($view['responsive']??null)===true&&($view['offline']??null)===true;
            $functional=($view['functional']??null)===true&&($view['copy']??null)===implode(' ',$profile[$desktop?'desktop':'compact'])&&
                ($view['links']??null)===($desktop?$profile['links']:array())&&($view['alt']??null)===$profile['alt']&&
                ($view['logoSha256']??null)===$receipt['logoSha256'];
            $visual=isset($frames[$width])?((is_float($ratio)||is_int($ratio))&&is_finite((float)$ratio)&&$ratio>=0&&$ratio<=0.005):null;
            $passed=$passed&&$responsive&&$functional&&$visual!==false;
            $proof[]=compact('width','responsive','functional','visual','ratio');
        }
    }
    return array('passed'=>$passed,'artifactSha256'=>hash('sha256',$content),'fingerprint'=>$fingerprint,
        'rendererBuild'=>$build,'views'=>$proof);
}

function syndamorph_footer_candidate(array $manifest,array $region): ?array {
    $receipt=syndamorph_footer_receipt($manifest,$region);
    $content=$receipt?syndamorph_footer_content($receipt['profile'],$receipt['logoId']):null;
    if($content===null)return null;
    $checksum=hash('sha256',$content);
    $event=$region['footerQaEvent']??null;
    $record=is_int($event)?syndamorph_scope_checkpoint($manifest['id'],$event,'recorded_footer_qa'):null;
    $qa=$record['qa']??array();
    return array('verifiedLayout'=>'recordedFooter','content'=>$content,'checksum'=>$checksum,'hostChecks'=>array(),
        'eligible'=>($record['region']??null)===$region['id']&&($record['checkpoint']??null)===$region['checkpoint']&&
            ($record['sourceEvent']??null)===$region['footerSourceEvent']&&($qa['passed']??null)===true&&
            ($qa['artifactSha256']??null)===$checksum&&($qa['fingerprint']??null)===syndamorph_footer_fingerprint());
}

function syndamorph_stage_footer(array $manifest,string $key) {
    $region=$manifest['regions'][$key]??null;
    $archive=$manifest['referenceArchive']??null;
    if(!$region||!empty($region['attempts'])||($manifest['mode']==='section'&&$manifest['selected']!==$key)||
        !in_array($region['status'],array('blocked','needs_review','uncompleted','pattern_saved'),true))
        return syndamorph_scope_error('footer_state','This region cannot stage the recorded footer.');
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    $profile=$source?syndamorph_footer_profile($source):null;
    if(!$profile||!syndamorph_production_reference_valid($archive,$manifest['sourceUrl'])||empty($archive['runtimeSha256']))
        return syndamorph_scope_error('footer_source','The original recorded footer is unavailable.');
    $receipt=syndamorph_footer_receipt($manifest,$region);
    if(!$receipt){
        if(!empty($region['patternId']))return syndamorph_scope_error('pattern_changed','The saved footer lost its archived evidence.');
        $replay=syndamorph_footer_replay($source,$archive,$profile);
        if(is_wp_error($replay))return $replay;
        $hash=$replay['asset']['sha256'];
        $matches=get_posts(array('post_type'=>'attachment','post_status'=>'inherit','numberposts'=>10,
            'meta_key'=>'_syndamorph_source_strip_sha256','meta_value'=>$hash));
        $id=0;$created=array();
        foreach($matches as $post){
            if(syndamorph_attachment_has_original($post->ID,$hash)){$id=$post->ID;break;}
        }
        if(!$id){
            $id=syndamorph_import_image_bytes($replay['bytes'],'source-footer-'.substr($hash,0,16).'.png',$created,true);
            if(is_wp_error($id))return $id;
            update_post_meta($id,'_syndamorph_source_strip_sha256',$hash);
        }
        unset($replay['bytes']);
        $event=syndamorph_history_append($manifest['id'],'source_recorded_footer',$replay+
            array('region'=>$key,'checkpoint'=>$region['checkpoint'],'archiveId'=>$archive['id'],'logoId'=>$id,
                'sourceSha256'=>hash('sha256',wp_json_encode($source))));
        if(!$event){
            syndamorph_package_abort(array(),$created);
            return syndamorph_scope_error('footer_archive','Could not retain the footer source receipt.');
        }
        $manifest['regions'][$key]['footerSourceEvent']=$event;
        if(!syndamorph_history_append($manifest['id'],'scope_manifest',$manifest))
            return syndamorph_scope_error('footer_archive','Could not bind the footer replay to the scope.');
        $receipt=syndamorph_footer_receipt($manifest,$manifest['regions'][$key]);
    }
    $content=$receipt?syndamorph_footer_content($profile,$receipt['logoId']):null;
    if($content===null)return syndamorph_scope_error('footer_logo','The archived logo is unavailable.');
    if(!empty($region['patternId'])){
        $saved=get_post($region['patternId']);
        if(!$saved||$saved->post_type!=='wp_block'||$saved->post_status!=='private'||
            get_post_meta($saved->ID,'wp_pattern_sync_status',true)!=='unsynced'||$saved->post_content!==$content)
            return syndamorph_scope_error('pattern_changed','The saved footer differs from the recorded candidate.');
    }
    $qa=syndamorph_footer_quality($receipt,$content);
    if(is_wp_error($qa))return $qa;
    $event=syndamorph_history_append($manifest['id'],'recorded_footer_qa',
        array('region'=>$key,'checkpoint'=>$region['checkpoint'],'sourceEvent'=>$manifest['regions'][$key]['footerSourceEvent'],'qa'=>$qa));
    if(!$event)return syndamorph_scope_error('footer_archive','Could not retain the footer QA receipt.');
    $manifest['regions'][$key]['footerQaEvent']=$event;
    $manifest['regions'][$key]['status']=$qa['passed']?(empty($region['patternId'])?'needs_review':'pattern_saved'):
        (empty($region['patternId'])?'blocked':'uncompleted');
    if(!$qa['passed']){
        $manifest['status']='uncompleted';
        if(!empty($manifest['page']['allRegions']))$manifest['page']['qa']=array('passed'=>false,'reason'=>'The recorded footer needs current QA.');
    }
    if(!syndamorph_history_append($manifest['id'],'scope_manifest',$manifest))
        return syndamorph_scope_error('footer_archive','Could not bind the footer QA to the scope.');
    return $qa['passed']?syndamorph_scope_status($manifest):new WP_Error('sm_footer_qa','The recorded footer is still pending independent QA.',
        array('status'=>409,'views'=>$qa['views']));
}
