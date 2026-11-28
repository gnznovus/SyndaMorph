<?php
/** Recorded Header admission and independent browser QA for production scopes. */
if (!defined('ABSPATH')) exit;

function syndamorph_scope_header_profile(array $source): bool {
    $variants=$source['variants']??array();
    if(($source['kind']??null)!=='header'||count($variants)!==3)return false;
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $view=$variants[$width]??array();
        if(empty($view['geometryAvailable'])||($view['inventory']['position']??null)!=='sticky'||
            ($view['bounds']['y']??null)!==0||($view['bounds']['height']??null)!==($width===1440?141:72)||
            ($width===1440?($view['kind']??null)!=='header':empty($view['inventory']['compactNavigation']))||
            !syndamorph_scope_header_jpeg($view['screenshot']??null))return false;
    }
    return true;
}

function syndamorph_scope_header_jpeg($value): bool {
    return is_string($value)&&strlen($value)<=524288&&strlen($value)%4===0&&str_starts_with($value,'/9j/')&&
        preg_match('~^[A-Za-z0-9+/]+={0,2}$~D',$value);
}

function syndamorph_scope_header_replay(array $source,array $archive) {
    if(!syndamorph_scope_header_profile($source)||empty($archive['runtimeSha256']))
        return syndamorph_scope_error('header_source','The recorded header profile is unverified.');
    $views=array();$frames=array();
    foreach($archive['views'] as $view){
        $width=$view['width'];$pinned=$source['variants'][$width];$ref=$pinned['sourceRef'];
        $hash=hash('sha256',base64_decode($pinned['screenshot'],true));
        $response=syndamorph_internal_request('backend','POST','/production-touch',array('timeout'=>65,
            'headers'=>array('Content-Type'=>'application/json'),'limit_response_size'=>2500001,
            'body'=>wp_json_encode(array('kind'=>'recorded-header','archiveId'=>$archive['id'],
                'width'=>$width,'sourceRef'=>$ref,'pinnedSha256'=>$hash))));
        if(is_wp_error($response))return $response;
        $raw=wp_remote_retrieve_body($response);
        $v=strlen($raw)<=2500000?json_decode($raw,true):null;
        if(wp_remote_retrieve_response_code($response)!==200||($v['version']??null)!==1||
            ($v['archiveId']??null)!==$archive['id']||($v['mode']??null)!=='recorded'||($v['status']??null)!=='observed'||
            ($v['width']??null)!==$width||($v['sourceRef']??null)!==$ref||($v['pinnedSha256']??null)!==$hash||
            ($v['sourceCopySha256']??null)!==$view['copySha256']||($v['blockedRequestCount']??null)!==0||
            ($v['scrollPositions']??null)!==array(0,500,1400,3000)||!is_array($v['navigation']??null))
            return new WP_Error('sm_header_replay','The archive did not verify the recorded header.',
                array('status'=>409,'width'=>$width,'httpStatus'=>wp_remote_retrieve_response_code($response),
                    'reason'=>sanitize_key((string)($v['reason']??'unknown'))));
        $frames[$width]=array('closed'=>$pinned['screenshot']);
        if($width===1440){
            if(!is_array($v['panels']??null)||!array_is_list($v['panels'])||count($v['panels'])<2||count($v['panels'])>12)
                return syndamorph_scope_error('header_panels','Recorded desktop menu states are incomplete.');
            $frames[$width]['panels']=$v['panels'];
        }else{
            if(!syndamorph_scope_header_jpeg($v['open']??null)||($v['pointerCloses']??null)!==true||
                ($v['escapeCloses']??null)!==false||!in_array($v['warmupImageRequests']??null,array(0,1),true))
                return syndamorph_scope_error('header_drawer','Recorded compact drawer states are incomplete.');
            $frames[$width]['open']=$v['open'];
        }
        $views[$width]=array_diff_key($v,array_flip(array('open','panels','logoBase64')));
        if($width===1440)$bytes=is_string($v['logoBase64']??null)?base64_decode($v['logoBase64'],true):false;
    }
    $desktop=$views[1440]['navigation'];$compact=$views[768]['navigation'];$logo=$views[1440]['logo']??null;
    $home=$views[1440]['homeUrl']??null;
    $digest=$logo['sha256']??null;
    if(!is_array($logo)||($logo['mime']??null)!=='image/png'||!is_string($digest)||!is_string($bytes??null)||
        strlen($bytes)<33||strlen($bytes)>524288||($logo['bytes']??null)!==strlen($bytes)||
        !str_starts_with($bytes,"\x89PNG\r\n\x1a\n")||hash('sha256',$bytes)!==$digest||
        ($views[768]['logo']??null)!==$logo||($views[375]['logo']??null)!==$logo||
        ($views[768]['homeUrl']??null)!==$home||($views[375]['homeUrl']??null)!==$home||
        $views[375]['navigation']!==$compact||($desktop['rateUrl']??null)!==($compact['rateUrl']??null)||
        ($desktop['rateLabel']??null)!==($compact['rateLabel']??null)||
        ($desktop['languageLabel']??null)!==($compact['languageLabel']??null)||
        array_slice($desktop['languages']??array(),1)!==($compact['languages']??null))
        return syndamorph_scope_error('header_identity','Recorded navigation, locale or logo differs across viewports.');
    $navigation=array('homeUrl'=>$home,'logoAlt'=>$logo['alt']??null)+$desktop+$compact;
    if(!syndamorph_header_content(array('logoId'=>1)+$navigation))
        return syndamorph_scope_error('header_contract','Recorded content is outside the native header contract.');
    return array('views'=>$views,'navigation'=>$navigation,'frames'=>$frames,'logoSha256'=>$digest,'bytes'=>$bytes);
}

function syndamorph_scope_header_content(array $receipt): ?string {
    $navigation=syndamorph_header_content($receipt['navigation']??null);
    $sha=$receipt['logoSha256']??null;
    if(!$navigation||!is_string($sha)||get_post_mime_type($navigation['logoId'])!=='image/png'||
        !syndamorph_attachment_has_original($navigation['logoId'],$sha))return null;
    return get_comment_delimited_block_content('syndamorph/recorded-header',
        array('navigation'=>$navigation,'logoSha256'=>$sha),'');
}

function syndamorph_scope_header_receipt(array $manifest,array $region): ?array {
    $id=$region['recordedHeaderSourceEvent']??null;
    if(!is_int($id))return null;
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    $archive=$manifest['referenceArchive']??null;
    $row=syndamorph_scope_checkpoint($manifest['id'],$id,'source_recorded_header');
    if(!$source||!$row||!syndamorph_production_reference_valid($archive,$manifest['sourceUrl'])||
        ($row['checkpoint']??null)!==$region['checkpoint']||($row['region']??null)!==$region['id']||
        ($row['archiveId']??null)!==$archive['id']||($row['sourceSha256']??null)!==hash('sha256',wp_json_encode($source)))
        return null;
    return $row;
}

function syndamorph_scope_header_fingerprint(): string {
    return hash('sha256',hash_file('sha256',__FILE__).hash_file('sha256',__DIR__.'/production-header.php').
        hash_file('sha256',dirname(__DIR__).'/assets/production-header.css').
        hash_file('sha256',dirname(__DIR__).'/assets/production-header-view.js'));
}

function syndamorph_scope_header_candidate(array $manifest,array $region): ?array {
    $receipt=syndamorph_scope_header_receipt($manifest,$region);
    $content=$receipt?syndamorph_scope_header_content($receipt):null;
    if($content===null)return null;
    $event=$region['recordedHeaderQaEvent']??null;
    $record=is_int($event)?syndamorph_scope_checkpoint($manifest['id'],$event,'recorded_header_qa'):null;
    $qa=$record['qa']??array();
    $checksum=hash('sha256',$content);
    return array('verifiedLayout'=>'recordedHeader','content'=>$content,'checksum'=>$checksum,'hostChecks'=>array(),
        'eligible'=>($record['region']??null)===$region['id']&&($record['checkpoint']??null)===$region['checkpoint']&&
            ($record['sourceEvent']??null)===$region['recordedHeaderSourceEvent']&&($qa['passed']??null)===true&&
            ($qa['artifactSha256']??null)===$checksum&&($qa['fingerprint']??null)===syndamorph_scope_header_fingerprint());
}

/** Compare record keys independently of JSON serialization order. List order remains significant. */
function syndamorph_scope_header_normalize($value) {
    if(!is_array($value))return $value;
    if(!array_is_list($value))ksort($value);
    return array_map('syndamorph_scope_header_normalize',$value);
}

function syndamorph_scope_header_quality(array $receipt,string $content) {
    $preview=syndamorph_native_core_preview(parse_blocks($content));
    $fonts=syndamorph_production_font_frame($preview);
    if(is_wp_error($fonts))return $fonts;
    $html=syndamorph_image_strip_frame($fonts['html']);
    if(is_wp_error($html))return $html;
    $runtime=file_get_contents(dirname(__DIR__).'/assets/production-header-view.js');
    $build=null;$rows=array();
    foreach(array(SYNDAMORPH_SOURCE_WIDTHS,array(1350,1280,1200,1024,1023)) as $widths){
        $frames=array_intersect_key($receipt['frames'],array_flip($widths));
        $response=syndamorph_internal_request('renderer','POST','/check-header',array('timeout'=>65,
            'headers'=>array('Content-Type'=>'application/json'),'limit_response_size'=>64000,
            'body'=>wp_json_encode(array('html'=>$html,'viewports'=>$widths,'frames'=>(object)$frames,'runtime'=>$runtime))));
        if(is_wp_error($response))return $response;
        $result=json_decode(wp_remote_retrieve_body($response),true);
        $batch=$result['evidence']??array();
        $received=wp_remote_retrieve_header($response,'x-syndamorph-renderer-build');
        if(wp_remote_retrieve_response_code($response)!==200||!is_array($batch)||array_column($batch,'width')!==$widths||
            (string)wp_remote_retrieve_header($response,'x-syndamorph-inspection-protocol')!==(string)SYNDAMORPH_INSPECTION_PROTOCOL||
            !is_string($received)||!preg_match('/^[a-f0-9]{64}$/D',$received)||($build!==null&&$received!==$build))
            return new WP_Error('sm_header_renderer','Recorded Header browser evidence is pending.',
                array('status'=>409,'httpStatus'=>wp_remote_retrieve_response_code($response),'widths'=>$widths,
                    'reason'=>sanitize_key((string)($result['error']??'incomplete'))));
        $build=$received;
        array_push($rows,...$batch);
    }
    $ok=true;$reports=array();
    $ratio=static fn($n)=>is_numeric($n)&&is_finite((float)$n)&&$n>=0&&$n<=0.005;
    foreach($rows as $row){
        $w=$row['width'];$p=is_array($row['proof']??null)?$row['proof']:array();$source=$receipt['views'][$w]??null;
        $geometry=($p['geometry']??null)===true&&($p['assetLoaded']??null)===true&&($p['offline']??null)===true;
        $report=array('width'=>$w,'responsive'=>$geometry);
        if($source){
            $expected=array('homeUrl'=>$source['homeUrl'])+$source['navigation'];
            $visual=$p['visual']??array();
            $measured=$ratio($visual['closed']??null);
            if($w===1440){
                $panels=$visual['panels']??array();$states=$receipt['frames'][$w]['panels'];
                $measured=$measured&&array_column($panels,'label')===array_column($states,'label')&&
                    count(array_filter(array_column($panels,'ratio'),$ratio))===count($states);
            }else{
                $measured=$measured&&$ratio($visual['open']??null);
            }
            $functional=($p['actions']??null)===true&&syndamorph_scope_header_normalize($p['navigation']??null)===
                syndamorph_scope_header_normalize($expected);
            $report+=array('functional'=>$functional,'visual'=>$measured,'ratios'=>$visual);
            $ok=$ok&&$functional&&$measured;
        }
        $ok=$ok&&$geometry;
        $reports[]=$report;
    }
    return array('passed'=>$ok,'artifactSha256'=>hash('sha256',$content),'fingerprint'=>syndamorph_scope_header_fingerprint(),
        'rendererBuild'=>$build,'views'=>$reports);
}

function syndamorph_scope_stage_header(array $manifest,string $key) {
    $region=$manifest['regions'][$key]??null;
    if(!$region||!empty($region['attempts'])||($manifest['mode']==='section'&&$manifest['selected']!==$key)||
        !in_array($region['status'],array('blocked','needs_review','uncompleted','pattern_saved'),true))
        return syndamorph_scope_error('header_state','This region cannot stage a Recorded Header.');
    $archive=$manifest['referenceArchive']??null;
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    if(!$source||!syndamorph_production_reference_valid($archive,$manifest['sourceUrl']))
        return syndamorph_scope_error('header_source','The original header archive is unavailable.');
    $previous=syndamorph_scope_header_candidate($manifest,$region);
    $receipt=syndamorph_scope_header_receipt($manifest,$region);
    $content=$receipt?syndamorph_scope_header_content($receipt):null;
    if($content===null){
        $replay=syndamorph_scope_header_replay($source,$archive);
        if(is_wp_error($replay))return $replay;
        $created=array();
        $id=syndamorph_import_image_bytes($replay['bytes'],'recorded-header-'.substr($replay['logoSha256'],0,16).'.png',
            $created,true);
        if(is_wp_error($id))return $id;
        unset($replay['bytes']);
        $receipt=$replay+array('region'=>$key,'checkpoint'=>$region['checkpoint'],'archiveId'=>$archive['id'],
            'sourceSha256'=>hash('sha256',wp_json_encode($source)));
        $receipt['navigation']=array('logoId'=>$id)+$receipt['navigation'];
        $content=syndamorph_scope_header_content($receipt);
        if($content===null){syndamorph_package_abort(array(),$created);return syndamorph_scope_error('header_asset','Recorded logo import failed.');}
        if(!empty($region['patternId'])){
            syndamorph_package_abort(array(),$created);
            return syndamorph_scope_error('pattern_changed','The saved header lost its recorded source or asset.');
        }
        $event=syndamorph_history_append($manifest['id'],'source_recorded_header',$receipt);
        if(!$event){syndamorph_package_abort(array(),$created);return syndamorph_scope_error('header_archive','Could not save the header receipt.');}
        $manifest['regions'][$key]['recordedHeaderSourceEvent']=$event;
        if(!syndamorph_history_append($manifest['id'],'scope_manifest',$manifest))
            return syndamorph_scope_error('header_archive','Could not attach the header receipt to the scope.');
    }
    if(!empty($region['patternId'])){
        $pattern=get_post($region['patternId']);
        if(!$pattern||$pattern->post_status!=='private'||$pattern->post_type!=='wp_block'||
            get_post_meta($pattern->ID,'wp_pattern_sync_status',true)!=='unsynced'||$pattern->post_content!==$content||
            ($region['artifactSha256']??null)!==hash('sha256',$content))
            return syndamorph_scope_error('pattern_changed','The saved header no longer matches the verified markup.');
    }
    $qa=syndamorph_scope_header_quality($receipt,$content);
    $result=is_wp_error($qa)?array('passed'=>false,'code'=>$qa->get_error_code()):$qa;
    $event=syndamorph_history_append($manifest['id'],'recorded_header_qa',array('region'=>$key,'checkpoint'=>$region['checkpoint'],
        'sourceEvent'=>$manifest['regions'][$key]['recordedHeaderSourceEvent'],'qa'=>$result));
    $manifest['regions'][$key]['recordedHeaderQaEvent']=$event;
    $manifest['regions'][$key]['status']=$event&&!empty($result['passed'])?
        (empty($region['patternId'])?'needs_review':'pattern_saved'):(empty($region['patternId'])?'blocked':'uncompleted');
    if(!$event||empty($result['passed'])||(!empty($manifest['page']['allRegions'])&&empty($previous['eligible']))){
        $manifest['status']='uncompleted';
        if(!empty($manifest['page']['allRegions'])){
            $manifest['page']['qa']=array('passed'=>false,'reason'=>'Whole-page comparison needs current Recorded Header evidence.');
            update_post_meta($manifest['page']['id'],'_syndamorph_scope_manifest',$manifest['page']);
        }
    }
    if(!syndamorph_history_append($manifest['id'],'scope_manifest',$manifest))
        return syndamorph_scope_error('header_archive','Could not attach the header QA to the scope.');
    if(!$event||empty($result['passed']))return is_wp_error($qa)?$qa:
        new WP_Error('sm_header_qa','The Recorded Header has not passed independent QA.',array('status'=>409,'views'=>$result['views']??array()));
    return syndamorph_scope_status($manifest);
}
