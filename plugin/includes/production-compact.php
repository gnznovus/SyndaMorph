<?php
/** Archive-backed compact Patterns for the native production scope. */
if(!defined('ABSPATH'))exit;

function syndamorph_compact_inventory(array $source,string $kind): ?array {
    $booking=$kind==='booking';
    $variants=$source['variants']??array();
    if(($source['kind']??null)!=='content'||!is_array($variants)||count($variants)!==2||
        !isset($variants[768],$variants[375]))return null;
    $ref=$source['sourceRef']??null;
    if(!is_string($ref)||!preg_match('/^(?:[a-z][a-z0-9-]{0,20})?\[(?:data-dc-tpl|id)="[a-zA-Z0-9_-]{1,64}"\]$/D',$ref))return null;
    $copy=null;
    foreach(array(768,375) as $width){
        $view=$variants[$width];$inventory=$view['inventory']??array();
        $bounds=$view['bounds']??array();$text=$inventory['text']??null;
        if(($view['sourceRef']??null)!==$ref||empty($view['geometryAvailable'])||
            (float)($bounds['x']??-1)!==0.0||($bounds['width']??null)!==$width||
            !is_numeric($bounds['y']??null)||$bounds['y']<0||$bounds['y']>36000||
            !is_numeric($bounds['height']??null)||$bounds['height']<1||$bounds['height']>420||
            ($inventory['position']??null)!==($booking?'fixed':'static')||
            ($booking&&((float)$bounds['y']!==832.0||(float)$bounds['height']!==68.0))||
            ($inventory['links']??null)!==array()||
            ($inventory['controls']??null)!==array()||($inventory['anchors']??null)!==array()||
            ($inventory['assets']??null)!==($booking?array():array_fill(0,3,array('type'=>'svg')))||
            !is_array($text)||!array_is_list($text)||count($text)!==($booking?3:6)||
            array_filter($text,static fn($line)=>!is_string($line)||trim($line)===''||mb_strlen($line)>150)||
            ($copy!==null&&$text!==$copy)||
            !syndamorph_source_screenshot_valid($view['screenshot']??null,$width,(int)round($bounds['height'])))return null;
        $copy=$text;
    }
    return $copy;
}

function syndamorph_compact_replay(array $source,array $archive,string $kind) {
    $copy=syndamorph_compact_inventory($source,$kind);
    if($copy===null||empty($archive['runtimeSha256'])||!syndamorph_internal_configured('backend'))
        return syndamorph_scope_error('compact_source','The compact section needs a verified archived source.');
    $sourceCopies=array_column($archive['views'], 'copySha256','width');
    $views=array();$assets=array();$originals=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $variant=$source['variants'][$width]??null;
        $pinned=$variant?hash('sha256',base64_decode($variant['screenshot'],true)):null;
        $response=syndamorph_internal_request('backend','POST','/production-touch',array(
            'timeout'=>35,'limit_response_size'=>90000,'headers'=>array('Content-Type'=>'application/json'),
            'body'=>wp_json_encode(array('kind'=>'compact-'.$kind,'archiveId'=>$archive['id'],
                'width'=>$width,'sourceRef'=>$source['sourceRef'],'pinnedSha256'=>$pinned))));
        $view=is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200?null:
            json_decode(wp_remote_retrieve_body($response),true,32);
        if(($view['status']??null)!=='observed'||($view['version']??null)!==1||
            ($view['mode']??null)!=='recorded'||($view['archiveId']??null)!==$archive['id']||
            ($view['width']??null)!==$width||($view['sourceRef']??null)!==$source['sourceRef']||
            ($view['sourceCopySha256']??null)!==($sourceCopies[$width]??'')||
            ($view['blockedRequestCount']??null)!==0||($view['present']??null)!==($variant!==null))
            return syndamorph_scope_error('compact_replay','The archived compact section replay is incomplete at '.$width.' px.');
        if($variant){
            $top=(int)round($variant['bounds']['y']);$height=(int)round($variant['bounds']['height']);
            if(($view['pinnedSha256']??null)!==$pinned||($view['copy']??null)!==$copy)
                return syndamorph_scope_error('compact_replay','The compact section copy or pinned crop changed.');
            if($kind==='booking'){
                if(($view['scrollPositions']??null)!==array(0,500,1400,3000)||($view['controls']??null)!==0||
                    ($view['localActions']??null)!=='inert')
                    return syndamorph_scope_error('compact_replay','The archived booking labels or fixed positioning could not be verified.');
                $view['screenshot']=$variant['screenshot'];
                $view['height']=$height;
                $views[$width]=$view;
                continue;
            }
            if(($view['y']??null)!==$top||($view['height']??null)!==$height||
                !syndamorph_source_screenshot_valid($view['screenshot']??null,$width,$height)||
                !is_array($view['icons']??null)||!array_is_list($view['icons'])||count($view['icons'])!==3)
                return syndamorph_scope_error('compact_replay','The compact guarantee layout or pinned crop changed.');
            $badgeBytes=array();$metadata=array();
            foreach($view['icons'] as $icon){
                $bytes=is_array($icon)?syndamorph_archived_png_bytes($icon):null;
                if($bytes===null||strlen($bytes)>8192||!in_array($icon['asset']['pixels'],array(array(20,20),array(20,21)),true))
                    return syndamorph_scope_error('compact_asset','A compact guarantee badge could not be verified.');
                $badgeBytes[]=$bytes;$metadata[]=$icon['asset'];
            }
            if($assets&&$assets!==$metadata)
                return syndamorph_scope_error('compact_asset','The compact guarantee badges differ between widths.');
            $assets=$metadata;$originals=$badgeBytes;
            unset($view['icons']);
        }
        $views[$width]=$view;
    }
    return array('copy'=>$copy,'views'=>$views)+($kind==='guarantee'?array('assets'=>$assets,'originals'=>$originals):array());
}

function syndamorph_compact_content(array $receipt,string $kind): ?string {
    $copy=$receipt['copy'];
    $group=static function(array $children,string $style=''): string {
        $class=$style===''?'':' '.$style;
        return get_comment_delimited_block_content('core/group',$style===''?array():array('className'=>$style),
            '<div class="wp-block-group'.$class.'">'.implode("\n\n",$children).'</div>');
    };
    $paragraph=static fn(string $line): string => get_comment_delimited_block_content('core/paragraph',array(),'<p>'.esc_html($line).'</p>');
    if($kind==='booking'){
        return $group(array($group(array($paragraph($copy[0]),$paragraph($copy[1]))),$paragraph($copy[2])),
            'is-style-sm-compact-booking');
    }
    $rows=array();
    foreach($receipt['attachmentIds'] as $i=>$id){
        $sha=$receipt['assets'][$i]['sha256'];
        $url=wp_get_attachment_url($id);
        if(!$url||get_post_meta($id,'_syndamorph_source_strip_sha256',true)!==$sha||
            !syndamorph_attachment_has_original($id,$sha))return null;
        $paragraphs=array_map($paragraph,array_slice($copy,$i*2,2));
        $rows[]=$group(array(syndamorph_source_image_block($id,$url,'','sm-source-compact-badge'),$group($paragraphs)));
    }
    return $group($rows,'is-style-sm-compact-guarantee');
}

function syndamorph_compact_fingerprint(): ?string {
    $style=syndamorph_native_compact_css();
    $frame=syndamorph_production_font_frame('<style>'.$style.'</style>');
    return is_wp_error($frame)||empty($frame['sha256'])?null:hash('sha256',$style.$frame['sha256']);
}

function syndamorph_compact_guarantee_quality(array $receipt,string $content) {
    $fingerprint=syndamorph_compact_fingerprint();
    if($fingerprint===null)return syndamorph_scope_error('compact_fonts','Install the verified compact guarantee fonts before browser QA.');
    $preview=syndamorph_native_core_preview(parse_blocks($content));
    $widths=syndamorph_design_probe_widths($preview,SYNDAMORPH_SOURCE_WIDTHS);
    if(is_wp_error($widths))return $widths;
    $samples=array();$build=null;
    foreach(array_chunk($widths,5) as $batch){
        $frames=array();
        foreach($batch as $width)$frames[$width]=$receipt['views'][$width]['y']??0;
        $views=syndamorph_collab_inspect($preview,$batch,$currentBuild,'section',$frames);
        if(is_wp_error($views))return $views;
        if($build!==null&&$currentBuild!==$build)return syndamorph_inspection_failure('protocol_mismatch',array('expectedWidths'=>$widths));
        $build=$currentBuild;
        foreach($views as $view)$samples[$view['width']]=$view;
    }
    $copy=implode(' ',$receipt['copy']);
    $markup=array();
    foreach($samples as $width=>$view){
        $visible=$width<1024;
        $literal=$view['snapshot']['literal']??array();
        $markup[$width]=($view['snapshot']['copy']??null)===($visible?$copy:'')&&
            ($literal['assets']??null)===($visible?3:0)&&($literal['controls']??null)===0&&($literal['links']??null)===array();
    }
    $reference=array();$native=array();
    foreach(array(768,375) as $width){
        $view=$receipt['views'][$width];
        $reference[]=array('width'=>$width,'extent'=>$view['height'],'screenshot'=>$view['screenshot']);
        $native[]=$samples[$width];
    }
    $visual=syndamorph_source_visual($reference,$native);
    $findings=syndamorph_design_responsive_findings(array_values($samples));
    return array('artifactSha256'=>hash('sha256',$content),'fingerprint'=>$fingerprint,
        'passed'=>$visual['passed']&&!$findings&&!in_array(false,$markup,true),'visual'=>$visual,
        'responsive'=>array('widths'=>$widths,'findings'=>$findings),'markup'=>$markup,'rendererBuild'=>$build);
}

function syndamorph_compact_quality(array $receipt,string $content,string $kind) {
    if($kind==='guarantee')return syndamorph_compact_guarantee_quality($receipt,$content);
    $preview=syndamorph_native_core_preview(parse_blocks($content));
    $fingerprint=syndamorph_compact_fingerprint();
    $frame=syndamorph_production_font_frame($preview);
    if($fingerprint===null||is_wp_error($frame)||empty($frame['sha256']))
        return syndamorph_scope_error('compact_fonts','Install the verified compact booking fonts before browser QA.');
    $widths=syndamorph_design_probe_widths($preview,SYNDAMORPH_SOURCE_WIDTHS);
    if(is_wp_error($widths))return $widths;
    $samples=array();$build=null;
    foreach(array_chunk($widths,5) as $batch){
        $response=syndamorph_internal_request('renderer','POST','/check-compact-booking',array(
            'timeout'=>65,'limit_response_size'=>SYNDAMORPH_INSPECTION_RESPONSE_BYTES+1,
            'headers'=>array('Content-Type'=>'application/json','Accept-Encoding'=>'identity'),
            'body'=>wp_json_encode(array('html'=>$frame['html'],'viewports'=>$batch,
                'inspectionProtocol'=>SYNDAMORPH_INSPECTION_PROTOCOL))));
        $result=syndamorph_inspection_envelope($response,SYNDAMORPH_INSPECTION_RESPONSE_BYTES,$batch);
        if(is_wp_error($result))return $result;
        $error=syndamorph_inspection_check_protocol($result['details']);
        if($error)return $error;
        $current=$result['details']['rendererBuild'];
        if($build!==null&&$build!==$current)return syndamorph_inspection_failure('protocol_mismatch',$result['details']);
        $build=$current;
        $evidence=$result['data']['evidence']??null;
        if(!is_array($evidence)||!array_is_list($evidence)||array_column($evidence,'width')!==$batch)
            return syndamorph_inspection_failure('viewport_mismatch',$result['details']);
        foreach($evidence as $view)$samples[$view['width']]=$view['proof']??null;
    }
    $responsive=array();
    foreach($samples as $width=>$proof){
        $bounds=array(0,832,$width,68);
        $positions=array_map(static fn($y)=>array('y'=>$y,'bounds'=>$bounds),array(0,500,1400,3000));
        $responsive[$width]=$width>=1024?($proof['present']??null)===false:
            ($proof['present']??null)===true&&($proof['copy']??null)===$receipt['copy']&&($proof['controls']??null)===0&&
            ($proof['fixed']??null)===true&&($proof['overflow']??null)===false&&
            ($proof['bounds']??null)===$bounds&&($proof['positions']??null)===$positions&&
            syndamorph_source_screenshot_valid($proof['screenshot']??null,$width,68);
    }
    $visual=array('passed'=>false,'reason'=>'The fixed booking layout did not pass all viewport checks.');
    if(!in_array(false,$responsive,true)){
        $reference=array();$native=array();
        foreach(array(768,375) as $width){
            $reference[]=array('width'=>$width,'extent'=>68,'screenshot'=>$receipt['views'][$width]['screenshot']);
            $native[]=array('width'=>$width,'extent'=>68,'screenshot'=>$samples[$width]['screenshot'],
                'snapshot'=>array('overflow'=>$samples[$width]['overflow']));
        }
        $visual=syndamorph_source_visual($reference,$native);
    }
    return array('artifactSha256'=>hash('sha256',$content),'fingerprint'=>$fingerprint,'rendererBuild'=>$build,
        'passed'=>$visual['passed']&&!in_array(false,$responsive,true),'visual'=>$visual,'responsive'=>$responsive);
}

function syndamorph_scope_compact_kind(array $region): ?string {
    if(isset($region['compactGuaranteeQa']))return 'guarantee';
    return isset($region['compactBookingQa'])?'booking':null;
}

function syndamorph_scope_compact_receipt(array $manifest,array $region,string $kind): ?array {
    $receipt=$region['compact'.ucfirst($kind).'Evidence']??null;
    $archive=$manifest['referenceArchive']??null;
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    if(!$receipt||!$source||($receipt['checkpoint']??null)!==$region['checkpoint']||
        ($receipt['region']??null)!==$region['id']||
        ($receipt['archiveId']??null)!==($archive['id']??null)||
        ($receipt['sourceSha256']??null)!==hash('sha256',wp_json_encode($source))||
        !syndamorph_production_reference_valid($archive,$manifest['sourceUrl'])||
        !is_int($receipt['event']??null)||
        syndamorph_scope_checkpoint($manifest['id'],$receipt['event'],'source_compact_'.$kind)!==array_diff_key($receipt,array('event'=>true)))
        return null;
    return $receipt;
}

function syndamorph_scope_stage_compact(array $manifest,string $key,string $kind) {
    $field='compact'.ucfirst($kind);
    $region=$manifest['regions'][$key]??null;
    if(!$region||!empty($region['attempts'])||($manifest['mode']==='section'&&$manifest['selected']!==$key)||
        !in_array($region['status'],array('blocked','needs_review','uncompleted','pattern_saved'),true))
        return syndamorph_scope_error('compact_state','This region cannot stage the selected compact section.');
    $archive=$manifest['referenceArchive']??null;
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    if(!$source||!syndamorph_production_reference_valid($archive,$manifest['sourceUrl']))
        return syndamorph_scope_error('compact_source','The original compact section source is unavailable.');
    $receipt=syndamorph_scope_compact_receipt($manifest,$region,$kind);
    $content=$receipt?syndamorph_compact_content($receipt,$kind):null;
    if($content===null){
        $replay=syndamorph_compact_replay($source,$archive,$kind);
        if(is_wp_error($replay))return $replay;
        $created=array();$ids=array();
        foreach($replay['originals']??array() as $i=>$bytes){
            $sha=$replay['assets'][$i]['sha256'];
            $id=syndamorph_import_image_bytes($bytes,'compact-badge-'.substr($sha,0,16).'.png',$created,true);
            if(is_wp_error($id))return $id;
            update_post_meta($id,'_syndamorph_source_strip_sha256',$sha);
            $ids[]=$id;
        }
        unset($replay['originals']);
        $receipt=$replay+array('region'=>$key,'checkpoint'=>$region['checkpoint'],'archiveId'=>$archive['id'],
            'sourceSha256'=>hash('sha256',wp_json_encode($source)),'attachmentIds'=>$ids);
        $content=syndamorph_compact_content($receipt,$kind);
        if($content===null)return syndamorph_scope_error('compact_asset','The imported badge bytes are unavailable.');
        $receipt['event']=syndamorph_history_append($manifest['id'],'source_compact_'.$kind,$receipt);
        if(!$receipt['event'])return syndamorph_scope_error('compact_archive','The compact source receipt could not be saved.');
        $manifest['regions'][$key][$field.'Evidence']=$receipt;
        syndamorph_history_append($manifest['id'],'scope_manifest',$manifest);
    }
    if(!empty($region['patternId'])){
        $pattern=get_post($region['patternId']);
        if(!$pattern||$pattern->post_status!=='private'||$pattern->post_type!=='wp_block'||
            get_post_meta($pattern->ID,'wp_pattern_sync_status',true)!=='unsynced'||$pattern->post_content!==$content||
            ($region['artifactSha256']??null)!==hash('sha256',$content))
            return syndamorph_scope_error('pattern_changed','The saved compact Pattern differs from its verified artifact.');
    }
    $qa=syndamorph_compact_quality($receipt,$content,$kind);
    $result=is_wp_error($qa)?array('passed'=>false,'status'=>'pending','code'=>$qa->get_error_code()):$qa;
    syndamorph_history_append($manifest['id'],'compact_'.$kind.'_qa',array('region'=>$key,'checkpoint'=>$region['checkpoint'],
        'qa'=>$result));
    if(!$result['passed']){
        $manifest['regions'][$key][$field.'Qa']=$result;
        $manifest['regions'][$key]['status']=empty($region['patternId'])?'blocked':'uncompleted';
        $manifest['status']='uncompleted';
        if(!empty($manifest['page']['allRegions'])){
            $manifest['page']['qa']=array('passed'=>false,'reason'=>'Compact section browser QA is pending.');
            update_post_meta($manifest['page']['id'],'_syndamorph_scope_manifest',$manifest['page']);
        }
        syndamorph_history_append($manifest['id'],'scope_manifest',$manifest);
        return is_wp_error($qa)?$qa:syndamorph_scope_error('compact_qa','The compact section has not passed independent browser QA.');
    }
    if(!empty($manifest['page']['allRegions'])&&($region[$field.'Qa']['fingerprint']??null)!==$qa['fingerprint']){
        $manifest['page']['qa']=array('passed'=>false,'reason'=>'Reconstructed page visual comparison pending after compact styles changed.');
        $manifest['status']='uncompleted';
        update_post_meta($manifest['page']['id'],'_syndamorph_scope_manifest',$manifest['page']);
    }
    $manifest['regions'][$key][$field.'Qa']=$qa;
    $manifest['regions'][$key][$field.'ReplayAvailable']=true;
    $manifest['regions'][$key]['status']=empty($region['patternId'])?'needs_review':'pattern_saved';
    $manifest['regions'][$key]['blockers']=array();
    unset($manifest['regions'][$key]['issue']);
    syndamorph_history_append($manifest['id'],'scope_manifest',$manifest);
    return syndamorph_scope_status($manifest);
}
