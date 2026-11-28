<?php
/** Recorded, desktop-only offer admission for the existing native block. */
if (!defined('ABSPATH')) exit;

function syndamorph_scope_offer_profile(array $source): ?array {
    $variants=$source['variants']??array();
    $view=$variants[1440]??array();
    $inventory=$view['inventory']??array();
    $bounds=$view['bounds']??array();
    $height=$bounds['height']??null;
    $controls=$inventory['controls']??null;
    $control=$controls[0]??array();
    $text=$inventory['text']??null;
    if(($source['kind']??null)!=='overlay'||!is_array($variants)||count($variants)!==1||
        ($view['kind']??null)!=='overlay'||empty($view['geometryAvailable'])||
        ($inventory['position']??null)!=='absolute'||($inventory['links']??null)!==array()||
        ($inventory['assets']??null)!==array()||!is_array($controls)||count($controls)!==1||
        !is_array($text)||!array_is_list($text)||count(array_filter($text,'is_string'))!==count($text)||
        ($control['type']??null)!=='button'||!is_string($control['name']??null)||
        ($bounds['x']??null)!==380||($bounds['y']??null)!==200||($bounds['width']??null)!==680||
        (!is_int($height)&&!is_float($height))||ceil($height)!==411.0||
        !is_string($view['sourceRef']??null)||!syndamorph_source_screenshot_valid($view['screenshot']??null,1440,410))
        return null;
    return array('dismissLabel'=>$control['name'],'sourceRef'=>$view['sourceRef'],'text'=>$text,
        'pinnedSha256'=>hash('sha256',base64_decode($view['screenshot'],true)));
}

function syndamorph_scope_offer_replay(array $profile,array $archive) {
    $views=array();
    foreach($archive['views'] as $view){
        $width=$view['width'];
        $response=syndamorph_internal_request('backend','POST','/production-touch',array('timeout'=>40,
            'headers'=>array('Content-Type'=>'application/json'),'limit_response_size'=>200001,
            'body'=>wp_json_encode(array('kind'=>'desktop-offer','archiveId'=>$archive['id'],'width'=>$width,
                'sourceRef'=>$profile['sourceRef'],'pinnedSha256'=>$width===1440?$profile['pinnedSha256']:null,
                'dismissLabel'=>$profile['dismissLabel']))));
        if(is_wp_error($response))return $response;
        $raw=wp_remote_retrieve_body($response);
        $row=strlen($raw)<=200000?json_decode($raw,true):null;
        if(wp_remote_retrieve_response_code($response)!==200||($row['version']??null)!==1||
            ($row['archiveId']??null)!==$archive['id']||($row['mode']??null)!=='recorded'||
            ($row['status']??null)!=='observed'||($row['width']??null)!==$width||
            ($row['sourceRef']??null)!==$profile['sourceRef']||($row['sourceCopySha256']??null)!==$view['copySha256']||
            ($row['blockedRequestCount']??null)!==0||($row['present']??null)!==($width===1440))
            return new WP_Error('sm_offer_replay','The archive could not verify this offer.',
                array('status'=>409,'width'=>$width,'reason'=>$row['reason']??'receipt_missing'));
        $views[$width]=$row;
    }
    $desktop=$views[1440];
    $copy=syndamorph_offer_copy($desktop['copy']??null);
    if(!$copy||implode(' ',$copy)!==implode(' ',$profile['text'])||$copy['dismissLabel']!==$profile['dismissLabel']||
        ($desktop['pinnedSha256']??null)!==$profile['pinnedSha256']||($desktop['shade']??null)!=='document'||
        ($desktop['clip']??null)!==array('x'=>380,'y'=>200,'width'=>680,'height'=>411)||
        !syndamorph_source_screenshot_valid($desktop['screenshot']??null,680,411)||
        ($desktop['dismissal']??null)!=='pointer'||($desktop['labelKeepsOpen']??null)!==true||
        ($desktop['shadeKeepsOpen']??null)!==true||($desktop['escapeKeepsOpen']??null)!==true)
        return syndamorph_scope_error('offer_contract','The recorded offer does not match the native block contract.');
    return array('copy'=>$copy,'views'=>$views);
}

function syndamorph_scope_offer_receipt(array $manifest,array $region): ?array {
    $event=$region['desktopOfferSourceEvent']??null;
    if(!is_int($event))return null;
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    $archive=$manifest['referenceArchive']??null;
    $receipt=syndamorph_scope_checkpoint($manifest['id'],$event,'source_desktop_offer');
    if(!$source||!$receipt||!syndamorph_production_reference_valid($archive,$manifest['sourceUrl'])||
        ($receipt['region']??null)!==$region['id']||($receipt['checkpoint']??null)!==$region['checkpoint']||
        ($receipt['archiveId']??null)!==$archive['id']||
        ($receipt['sourceSha256']??null)!==hash('sha256',wp_json_encode($source))||
        !syndamorph_offer_copy($receipt['copy']??null))return null;
    return $receipt;
}

function syndamorph_scope_offer_fingerprint(): string {
    return hash('sha256',hash_file('sha256',__FILE__).hash_file('sha256',__DIR__.'/production-offer.php').
        hash_file('sha256',dirname(__DIR__).'/assets/production-offer.css').
        hash_file('sha256',dirname(__DIR__).'/assets/production-offer-view.js'));
}

function syndamorph_scope_offer_candidate(array $manifest,array $region): ?array {
    $receipt=syndamorph_scope_offer_receipt($manifest,$region);
    if(!$receipt)return null;
    $content=get_comment_delimited_block_content('syndamorph/desktop-offer',array('copy'=>$receipt['copy']),'');
    $checksum=hash('sha256',$content);
    $event=$region['desktopOfferQaEvent']??null;
    $record=is_int($event)?syndamorph_scope_checkpoint($manifest['id'],$event,'desktop_offer_qa'):null;
    $qa=$record['qa']??array();
    return array('verifiedLayout'=>'desktopOffer','content'=>$content,'checksum'=>$checksum,'hostChecks'=>array(),
        'eligible'=>($record['region']??null)===$region['id']&&($record['checkpoint']??null)===$region['checkpoint']&&
            ($record['sourceEvent']??null)===$region['desktopOfferSourceEvent']&&($qa['passed']??null)===true&&
            ($qa['artifactSha256']??null)===$checksum&&($qa['fingerprint']??null)===syndamorph_scope_offer_fingerprint());
}

function syndamorph_scope_offer_quality(array $receipt,string $content) {
    $html='<main id="syndamorph-page"><div class="sm-offer-entrance"><h2>Offer interaction fixture</h2></div>'.
        syndamorph_native_core_preview(parse_blocks($content)).'</main>';
    $fonts=syndamorph_production_font_frame($html);
    if(is_wp_error($fonts))return $fonts;
    $runtime=file_get_contents(dirname(__DIR__).'/assets/production-offer-view.js');
    $build=null;$results=array();$passed=true;
    foreach(array(array(1440,1350,1280,1200,1024),array(1023,900,768,600,375)) as $widths){
        $response=syndamorph_internal_request('renderer','POST','/check-offer',array('timeout'=>65,
            'headers'=>array('Content-Type'=>'application/json'),'limit_response_size'=>32000,
            'body'=>wp_json_encode(array('html'=>$fonts['html'],'viewports'=>$widths,'runtime'=>$runtime,
                'frame'=>$widths[0]===1440?$receipt['views'][1440]['screenshot']:null))));
        if(is_wp_error($response))return $response;
        $result=json_decode(wp_remote_retrieve_body($response),true);
        $batch=$result['evidence']??null;
        $received=wp_remote_retrieve_header($response,'x-syndamorph-renderer-build');
        if(wp_remote_retrieve_response_code($response)!==200||!is_array($batch)||array_column($batch,'width')!==$widths||
            (string)wp_remote_retrieve_header($response,'x-syndamorph-inspection-protocol')!==(string)SYNDAMORPH_INSPECTION_PROTOCOL||
            !is_string($received)||!preg_match('/^[a-f0-9]{64}$/D',$received)||($build!==null&&$received!==$build))
            return new WP_Error('sm_offer_renderer','Recorded offer browser evidence is pending.',
                array('status'=>409,'httpStatus'=>wp_remote_retrieve_response_code($response),
                    'widths'=>$widths,'reason'=>$result['error']??'incomplete'));
        $build=$received;
        foreach($batch as $row){
            $proof=$row['proof']??array();
            $responsive=($proof['responsive']??null)===true&&($proof['offline']??null)===true;
            $state=array('width'=>$row['width'],'responsive'=>$responsive);
            if($row['width']===1440){
                $ratio=$proof['ratio']??null;
                $visual=(is_float($ratio)||is_int($ratio))&&is_finite((float)$ratio)&&$ratio>=0&&$ratio<=0.005;
                $functional=($proof['functional']??null)===true&&($proof['copy']??null)===$receipt['copy'];
                $state+=array('visual'=>$visual,'functional'=>$functional,'difference'=>$ratio);
                $passed=$passed&&$visual&&$functional;
            }
            $passed=$passed&&$responsive;
            $results[]=$state;
        }
    }
    return array('passed'=>$passed,'artifactSha256'=>hash('sha256',$content),'fingerprint'=>syndamorph_scope_offer_fingerprint(),
        'rendererBuild'=>$build,'views'=>$results);
}

function syndamorph_scope_stage_offer(array $manifest,string $key) {
    $region=$manifest['regions'][$key]??null;
    if(!$region||!empty($region['attempts'])||($manifest['mode']==='section'&&$manifest['selected']!==$key)||
        !in_array($region['status'],array('blocked','needs_review','uncompleted','pattern_saved'),true))
        return syndamorph_scope_error('offer_state','This region cannot stage a Desktop Offer.');
    $archive=$manifest['referenceArchive']??null;
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    $profile=$source?syndamorph_scope_offer_profile($source):null;
    if(!$profile||!syndamorph_production_reference_valid($archive,$manifest['sourceUrl'])||empty($archive['runtimeSha256']))
        return syndamorph_scope_error('offer_source','The original offer archive or component profile is unavailable.');
    $previous=syndamorph_scope_offer_candidate($manifest,$region);
    $receipt=syndamorph_scope_offer_receipt($manifest,$region);
    if(!$receipt){
        if(!empty($region['patternId']))return syndamorph_scope_error('pattern_changed','The saved offer lost its recorded source.');
        $receipt=syndamorph_scope_offer_replay($profile,$archive);
        if(is_wp_error($receipt))return $receipt;
        $receipt+=array('region'=>$key,'checkpoint'=>$region['checkpoint'],'archiveId'=>$archive['id'],
            'sourceSha256'=>hash('sha256',wp_json_encode($source)));
        $event=syndamorph_history_append($manifest['id'],'source_desktop_offer',$receipt);
        if(!$event)return syndamorph_scope_error('offer_archive','Could not save the offer receipt.');
        $manifest['regions'][$key]['desktopOfferSourceEvent']=$event;
        if(!syndamorph_history_append($manifest['id'],'scope_manifest',$manifest))
            return syndamorph_scope_error('offer_archive','Could not attach the offer receipt to the scope.');
    }
    $content=get_comment_delimited_block_content('syndamorph/desktop-offer',array('copy'=>$receipt['copy']),'');
    if(!empty($region['patternId'])){
        $pattern=get_post($region['patternId']);
        if(!$pattern||$pattern->post_status!=='private'||$pattern->post_type!=='wp_block'||
            get_post_meta($pattern->ID,'wp_pattern_sync_status',true)!=='unsynced'||$pattern->post_content!==$content||
            ($region['artifactSha256']??null)!==hash('sha256',$content))
            return syndamorph_scope_error('pattern_changed','The saved offer no longer matches the verified markup.');
    }
    $qa=syndamorph_scope_offer_quality($receipt,$content);
    $result=is_wp_error($qa)?array('passed'=>false,'code'=>$qa->get_error_code()):$qa;
    $event=syndamorph_history_append($manifest['id'],'desktop_offer_qa',array('region'=>$key,'checkpoint'=>$region['checkpoint'],
        'sourceEvent'=>$manifest['regions'][$key]['desktopOfferSourceEvent'],'qa'=>$result));
    $manifest['regions'][$key]['desktopOfferQaEvent']=$event;
    $pass=$event&&!empty($result['passed']);
    $manifest['regions'][$key]['status']=$pass?(empty($region['patternId'])?'needs_review':'pattern_saved'):
        (empty($region['patternId'])?'blocked':'uncompleted');
    if(!$pass||(!empty($manifest['page']['allRegions'])&&empty($previous['eligible']))){
        $manifest['status']='uncompleted';
        if(!empty($manifest['page']['allRegions'])){
            $manifest['page']['qa']=array('passed'=>false,'reason'=>'Whole-page comparison needs current Desktop Offer evidence.');
            update_post_meta($manifest['page']['id'],'_syndamorph_scope_manifest',$manifest['page']);
        }
    }
    if(!syndamorph_history_append($manifest['id'],'scope_manifest',$manifest))
        return syndamorph_scope_error('offer_archive','Could not attach the offer QA to the scope.');
    if(!$pass)return is_wp_error($qa)?$qa:new WP_Error('sm_offer_qa','The Desktop Offer has not passed independent QA.',
        array('status'=>409,'views'=>$result['views']??array()));
    return syndamorph_scope_status($manifest);
}
