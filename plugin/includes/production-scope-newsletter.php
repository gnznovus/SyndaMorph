<?php
/** Source-bound newsletter replay and private Pattern admission. */
if(!defined('ABSPATH'))exit;

function syndamorph_newsletter_source(array $source): ?array {
    $views=$source['variants']??array();
    if(count($views)!==3)return null;
    $profiles=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $view=$views[$width]??array();
        $before=$view['inventory']??array();$bounds=$view['bounds']??array();
        $desktop=$width===1440;
        $copy=$before['text']??null;
        if(!is_array($copy)||!array_is_list($copy)||count($copy)!==7||
            ($before['links']??null)!==array()||($before['assets']??null)!==array(array('type'=>'svg'))||
            ($before['controls']??null)!==($desktop?array(array('type'=>'button','name'=>$copy[3])):array())||
            ($bounds['x']??null)!==0||($bounds['width']??null)!==$width||
            !is_numeric($bounds['height']??null)||!is_numeric($bounds['y']??null)||
            (int)round($bounds['height'])!==($desktop?451:411)||
            !syndamorph_source_screenshot_valid($view['screenshot']??null,$width,(int)round($bounds['height'])))return null;
        foreach($copy as $line)if(!syndamorph_header_text($line))return null;
        $key=$desktop?'desktop':'compact';
        if(isset($profiles[$key])&&$profiles[$key]!==$copy)return null;
        $profiles[$key]=$copy;
    }
    return $profiles;
}

function syndamorph_newsletter_replay(array $source,array $archive,array $profile) {
    $digests=array_column($archive['views'],'copySha256','width');
    $views=array();$open=null;
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $view=$source['variants'][$width];$desktop=$width===1440;
        $copy=$profile[$desktop?'desktop':'compact'];
        $inert=array_values(array_filter($copy,static fn($i)=>$i!==0&&(!$desktop||$i!==3),ARRAY_FILTER_USE_KEY));
        $hash=hash('sha256',base64_decode($view['screenshot'],true));
        $response=syndamorph_internal_request('backend','POST','/production-touch',array('timeout'=>40,
            'limit_response_size'=>190000,'headers'=>array('Content-Type'=>'application/json'),
            'body'=>wp_json_encode(array('kind'=>'recorded-newsletter','archiveId'=>$archive['id'],'width'=>$width,
                'sourceRef'=>$view['sourceRef'],'pinnedSha256'=>$hash))));
        if(is_wp_error($response))return $response;
        $row=json_decode(wp_remote_retrieve_body($response),true);
        if(wp_remote_retrieve_response_code($response)!==200||($row['version']??null)!==1||($row['status']??null)!=='observed'||
            ($row['mode']??null)!=='recorded'||($row['archiveId']??null)!==$archive['id']||
            ($row['width']??null)!==$width||($row['sourceRef']??null)!==$view['sourceRef']||
            ($row['sourceCopySha256']??null)!==($digests[$width]??null)||($row['pinnedSha256']??null)!==$hash||
            ($row['blockedRequestCount']??null)!==0||($row['copy']??null)!==$copy||($row['inert']??null)!==$inert||
            ($row['arrow']??null)!==array('viewBox'=>'0 0 24 24','path'=>'M6 9l6 6 6-6','width'=>'16','height'=>'16',
                'strokeWidth'=>'2','strokeLinecap'=>'square','paths'=>1)||!is_array($row['choices']??null)||
            count($row['choices'])!==($desktop?10:0))
            return new WP_Error('sm_newsletter_replay','The recorded newsletter replay is incomplete.',
                array('status'=>409,'width'=>$width,'reason'=>$row['reason']??'receipt_mismatch'));
        if($desktop){
            $profile['choices']=$row['choices'];
            $open=$row['open']??null;
            if(!syndamorph_newsletter_open_frame($open,(float)$view['bounds']['y']))
                return syndamorph_scope_error('newsletter_menu','The original country-menu crop is unavailable.');
        }elseif(($row['open']??null)!==null){
            return syndamorph_scope_error('newsletter_menu','The compact archive unexpectedly opened a country menu.');
        }
        unset($row['open']);
        $views[]=$row;
    }
    return syndamorph_newsletter_profile($profile)?compact('profile','views','open'):
        syndamorph_scope_error('newsletter_profile','The recorded country labels do not match the native block contract.');
}

function syndamorph_newsletter_open_frame($frame,float $top): bool {
    return is_array($frame)&&($frame['x']??null)===620&&(float)($frame['y']??-1)===$top-104&&
        ($frame['width']??null)===280&&($frame['height']??null)===320&&
        syndamorph_source_screenshot_valid($frame['image']??null,280,320)&&
        hash('sha256',base64_decode($frame['image'],true))===($frame['sha256']??null);
}

function syndamorph_newsletter_fingerprint(): ?string {
    $fonts=syndamorph_compact_fingerprint();
    $view=dirname(__DIR__).'/assets/production-newsletter-view.js';
    return $fonts?hash('sha256',hash_file('sha256',__FILE__).hash_file('sha256',__DIR__.'/production-newsletter.php').
        hash_file('sha256',$view).syndamorph_newsletter_css().$fonts):null;
}

function syndamorph_newsletter_receipt(array $manifest,array $region): ?array {
    $event=$region['newsletterSourceEvent']??null;
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    $archive=$manifest['referenceArchive']??null;
    $receipt=is_int($event)?syndamorph_scope_checkpoint($manifest['id'],$event,'source_recorded_newsletter'):null;
    $profiles=$source?syndamorph_newsletter_source($source):null;
    if(!$profiles||!$receipt||!syndamorph_production_reference_valid($archive,$manifest['sourceUrl'])||
        ($receipt['region']??null)!==$region['id']||($receipt['checkpoint']??null)!==$region['checkpoint']||
        ($receipt['archiveId']??null)!==$archive['id']||($receipt['sourceSha256']??null)!==hash('sha256',wp_json_encode($source))||
        !syndamorph_newsletter_profile($receipt['profile']??null)||
        array_intersect_key($receipt['profile'],$profiles)!==$profiles||
        !syndamorph_newsletter_open_frame($receipt['open']??null,(float)$source['variants'][1440]['bounds']['y']))return null;
    return $receipt+array('source'=>$source);
}

function syndamorph_newsletter_content(array $profile): string {
    return get_comment_delimited_block_content('syndamorph/recorded-newsletter',array('align'=>'full','profile'=>$profile),'');
}

function syndamorph_newsletter_quality(array $receipt,string $content) {
    $font=syndamorph_production_font_frame(syndamorph_scope_document($content,'Recorded newsletter',false));
    if(is_wp_error($font))return $font;
    $fingerprint=syndamorph_newsletter_fingerprint();
    if(!$fingerprint)return syndamorph_scope_error('newsletter_fonts','The verified newsletter fonts are unavailable.');
    $runtime=file_get_contents(dirname(__DIR__).'/assets/production-newsletter-view.js');
    $frames=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $view=$receipt['source']['variants'][$width];
        $frames[$width]=array('top'=>$view['bounds']['y'],'extent'=>(int)round($view['bounds']['height']),'image'=>$view['screenshot']);
    }
    $proof=array();$build=null;$passed=true;
    foreach(array(array(1440,1280,1200,1024,1023),array(900,768,600,450,375)) as $widths){
        $response=syndamorph_internal_request('renderer','POST','/check-newsletter',array('timeout'=>65,
            'headers'=>array('Content-Type'=>'application/json'),'limit_response_size'=>60000,
            'body'=>wp_json_encode(array('html'=>$font['html'],'viewports'=>$widths,'runtime'=>$runtime,
                'frames'=>array_intersect_key($frames,array_flip($widths)),
                'open'=>$widths[0]===1440?$receipt['open']:null))));
        if(is_wp_error($response))return $response;
        $row=json_decode(wp_remote_retrieve_body($response),true);$batch=$row['evidence']??null;
        $current=wp_remote_retrieve_header($response,'x-syndamorph-renderer-build');
        if(wp_remote_retrieve_response_code($response)!==200||!is_array($batch)||array_column($batch,'width')!==$widths||
            (string)wp_remote_retrieve_header($response,'x-syndamorph-inspection-protocol')!==(string)SYNDAMORPH_INSPECTION_PROTOCOL||
            !is_string($current)||!preg_match('/^[a-f0-9]{64}$/D',$current)||($build!==null&&$current!==$build))
            return new WP_Error('sm_newsletter_renderer','Independent newsletter browser evidence is pending.',
                array('status'=>409,'widths'=>$widths,'httpStatus'=>wp_remote_retrieve_response_code($response),'reason'=>$row['error']??'incomplete'));
        $build=$current;
        foreach($batch as $item){
            $w=$item['width'];$p=$item['proof']??array();$desktop=$w>=1024;
            $values=$receipt['profile'];$closed=$p['closedRatio']??null;$open=$p['openRatio']??null;
            $valid=static fn($ratio)=>(is_float($ratio)||is_int($ratio))&&is_finite((float)$ratio)&&$ratio>=0&&$ratio<=0.005;
            $responsive=($p['responsive']??null)===true&&($p['offline']??null)===true;
            $functional=($p['functional']??null)===true&&is_string($p['copy']??null)&&
                syndamorph_source_text($p['copy'])===implode(' ',$values[$desktop?'desktop':'compact'])&&
                ($p['choices']??null)===($desktop?$values['choices']:array());
            $visual=isset($frames[$w])?($valid($closed)&&($w!==1440||$valid($open))):null;
            $passed=$passed&&$responsive&&$functional&&$visual!==false;
            $proof[]=array('width'=>$w)+compact('responsive','functional','visual','closed','open');
        }
    }
    return array('passed'=>$passed,'artifactSha256'=>hash('sha256',$content),'fingerprint'=>$fingerprint,
        'rendererBuild'=>$build,'views'=>$proof);
}

function syndamorph_newsletter_candidate(array $manifest,array $region): ?array {
    $receipt=syndamorph_newsletter_receipt($manifest,$region);
    if(!$receipt)return null;
    $content=syndamorph_newsletter_content($receipt['profile']);
    $checksum=hash('sha256',$content);
    $event=$region['newsletterQaEvent']??null;
    $record=is_int($event)?syndamorph_scope_checkpoint($manifest['id'],$event,'recorded_newsletter_qa'):null;
    $qa=$record['qa']??array();
    return array('verifiedLayout'=>'recordedNewsletter','content'=>$content,'checksum'=>$checksum,'hostChecks'=>array(),
        'eligible'=>($record['region']??null)===$region['id']&&($record['checkpoint']??null)===$region['checkpoint']&&
            ($record['sourceEvent']??null)===$region['newsletterSourceEvent']&&($qa['passed']??null)===true&&
            ($qa['artifactSha256']??null)===$checksum&&($qa['fingerprint']??null)===syndamorph_newsletter_fingerprint());
}

function syndamorph_stage_newsletter(array $manifest,string $key) {
    $region=$manifest['regions'][$key]??null;
    if(!$region||!empty($region['attempts'])||($manifest['mode']==='section'&&$manifest['selected']!==$key)||
        !in_array($region['status'],array('blocked','needs_review','uncompleted','pattern_saved'),true))
        return syndamorph_scope_error('newsletter_state','This region cannot stage the recorded newsletter.');
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    $profile=$source?syndamorph_newsletter_source($source):null;
    $archive=$manifest['referenceArchive']??null;
    if(!$profile||!syndamorph_production_reference_valid($archive,$manifest['sourceUrl'])||empty($archive['runtimeSha256']))
        return syndamorph_scope_error('newsletter_source','The original newsletter is unavailable.');
    $receipt=syndamorph_newsletter_receipt($manifest,$region);
    if(!$receipt){
        if(!empty($region['patternId']))return syndamorph_scope_error('pattern_changed','The saved newsletter lost its archived evidence.');
        $replay=syndamorph_newsletter_replay($source,$archive,$profile);
        if(is_wp_error($replay))return $replay;
        $event=syndamorph_history_append($manifest['id'],'source_recorded_newsletter',$replay+
            array('region'=>$key,'checkpoint'=>$region['checkpoint'],'archiveId'=>$archive['id'],
                'sourceSha256'=>hash('sha256',wp_json_encode($source))));
        if(!$event)return syndamorph_scope_error('newsletter_archive','Could not retain the newsletter source receipt.');
        $manifest['regions'][$key]['newsletterSourceEvent']=$event;
        if(!syndamorph_history_append($manifest['id'],'scope_manifest',$manifest))
            return syndamorph_scope_error('newsletter_archive','Could not bind the newsletter replay to the scope.');
        $receipt=$replay+array('source'=>$source);
    }
    $content=syndamorph_newsletter_content($receipt['profile']);
    if(!empty($region['patternId'])){
        $saved=get_post($region['patternId']);
        if(!$saved||$saved->post_type!=='wp_block'||$saved->post_status!=='private'||
            get_post_meta($saved->ID,'wp_pattern_sync_status',true)!=='unsynced'||$saved->post_content!==$content)
            return syndamorph_scope_error('pattern_changed','The saved newsletter differs from the recorded candidate.');
    }
    $qa=syndamorph_newsletter_quality($receipt,$content);
    if(is_wp_error($qa))return $qa;
    $event=syndamorph_history_append($manifest['id'],'recorded_newsletter_qa',
        array('region'=>$key,'checkpoint'=>$region['checkpoint'],'sourceEvent'=>$manifest['regions'][$key]['newsletterSourceEvent'],'qa'=>$qa));
    if(!$event)return syndamorph_scope_error('newsletter_archive','Could not retain the newsletter QA receipt.');
    $manifest['regions'][$key]['newsletterQaEvent']=$event;
    $manifest['regions'][$key]['status']=$qa['passed']?(empty($region['patternId'])?'needs_review':'pattern_saved'):
        (empty($region['patternId'])?'blocked':'uncompleted');
    if(!$qa['passed']){
        $manifest['status']='uncompleted';
        if(!empty($manifest['page']['allRegions']))$manifest['page']['qa']=array('passed'=>false,'reason'=>'The recorded newsletter needs current QA.');
    }
    if(!syndamorph_history_append($manifest['id'],'scope_manifest',$manifest))
        return syndamorph_scope_error('newsletter_archive','Could not bind the newsletter QA to the scope.');
    return $qa['passed']?syndamorph_scope_status($manifest):new WP_Error('sm_newsletter_qa','The recorded newsletter is still pending independent QA.',
        array('status'=>409,'views'=>$qa['views']));
}
