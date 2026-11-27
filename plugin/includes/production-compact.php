<?php
/** Archive-backed compact guarantee Patterns for the native production scope. */
if(!defined('ABSPATH'))exit;

function syndamorph_compact_guarantee_inventory(array $source): ?array {
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
            ($inventory['position']??null)!=='static'||($inventory['links']??null)!==array()||
            ($inventory['controls']??null)!==array()||($inventory['anchors']??null)!==array()||
            ($inventory['assets']??null)!==array_fill(0,3,array('type'=>'svg'))||
            !is_array($text)||!array_is_list($text)||count($text)!==6||
            array_filter($text,static fn($line)=>!is_string($line)||trim($line)===''||mb_strlen($line)>150)||
            ($copy!==null&&$text!==$copy)||
            !syndamorph_source_screenshot_valid($view['screenshot']??null,$width,(int)round($bounds['height'])))return null;
        $copy=$text;
    }
    return $copy;
}

function syndamorph_compact_guarantee_replay(array $source,array $archive) {
    $copy=syndamorph_compact_guarantee_inventory($source);
    if($copy===null||empty($archive['runtimeSha256'])||!syndamorph_internal_configured('backend'))
        return syndamorph_scope_error('compact_source','The compact guarantee needs a verified archived source.');
    $sourceCopies=array_column($archive['views'], 'copySha256','width');
    $views=array();$assets=array();$originals=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $variant=$source['variants'][$width]??null;
        $pinned=$variant?hash('sha256',base64_decode($variant['screenshot'],true)):null;
        $response=syndamorph_internal_request('backend','POST','/production-touch',array(
            'timeout'=>35,'limit_response_size'=>90000,'headers'=>array('Content-Type'=>'application/json'),
            'body'=>wp_json_encode(array('kind'=>'compact-guarantee','archiveId'=>$archive['id'],
                'width'=>$width,'sourceRef'=>$source['sourceRef'],'pinnedSha256'=>$pinned))));
        $view=is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200?null:
            json_decode(wp_remote_retrieve_body($response),true,32);
        if(($view['status']??null)!=='observed'||($view['version']??null)!==1||
            ($view['mode']??null)!=='recorded'||($view['archiveId']??null)!==$archive['id']||
            ($view['width']??null)!==$width||($view['sourceRef']??null)!==$source['sourceRef']||
            ($view['sourceCopySha256']??null)!==($sourceCopies[$width]??'')||
            ($view['blockedRequestCount']??null)!==0||($view['present']??null)!==($variant!==null))
            return syndamorph_scope_error('compact_replay','The archived compact guarantee replay is incomplete at '.$width.' px.');
        if($variant){
            $top=(int)round($variant['bounds']['y']);$height=(int)round($variant['bounds']['height']);
            if(($view['pinnedSha256']??null)!==$pinned||($view['copy']??null)!==$copy||
                ($view['y']??null)!==$top||($view['height']??null)!==$height||
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
    return array('copy'=>$copy,'views'=>$views,'assets'=>$assets,'originals'=>$originals);
}

function syndamorph_compact_guarantee_content(array $receipt): ?string {
    $copy=$receipt['copy'];$attachments=$receipt['attachmentIds'];
    $group=static function(array $children,string $style=''): string {
        $class=$style===''?'':' '.$style;
        return get_comment_delimited_block_content('core/group',$style===''?array():array('className'=>$style),
            '<div class="wp-block-group'.$class.'">'.implode("\n\n",$children).'</div>');
    };
    $rows=array();
    foreach($attachments as $i=>$id){
        $sha=$receipt['assets'][$i]['sha256'];
        $url=wp_get_attachment_url($id);
        if(!$url||get_post_meta($id,'_syndamorph_source_strip_sha256',true)!==$sha||
            !syndamorph_attachment_has_original($id,$sha))return null;
        $paragraphs=array();
        foreach(array_slice($copy,$i*2,2) as $line)
            $paragraphs[]=get_comment_delimited_block_content('core/paragraph',array(),'<p>'.esc_html($line).'</p>');
        $rows[]=$group(array(syndamorph_source_image_block($id,$url,'','sm-source-compact-badge'),$group($paragraphs)));
    }
    return $group($rows,'is-style-sm-compact-guarantee');
}

function syndamorph_compact_guarantee_fingerprint(): ?string {
    $style=syndamorph_native_compact_css();
    $frame=syndamorph_production_font_frame('<style>'.$style.'</style>');
    return is_wp_error($frame)||empty($frame['sha256'])?null:hash('sha256',$style.$frame['sha256']);
}

function syndamorph_compact_guarantee_quality(array $receipt,string $content) {
    $fingerprint=syndamorph_compact_guarantee_fingerprint();
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

function syndamorph_scope_compact_guarantee_receipt(array $manifest,array $region): ?array {
    $receipt=$region['compactGuaranteeEvidence']??null;
    $archive=$manifest['referenceArchive']??null;
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    if(!$receipt||!$source||($receipt['checkpoint']??null)!==$region['checkpoint']||
        ($receipt['region']??null)!==$region['id']||
        ($receipt['archiveId']??null)!==($archive['id']??null)||
        ($receipt['sourceSha256']??null)!==hash('sha256',wp_json_encode($source))||
        !syndamorph_production_reference_valid($archive,$manifest['sourceUrl'])||
        !is_int($receipt['event']??null)||
        syndamorph_scope_checkpoint($manifest['id'],$receipt['event'],'source_compact_guarantee')!==array_diff_key($receipt,array('event'=>true)))
        return null;
    return $receipt;
}

function syndamorph_scope_stage_compact_guarantee(array $manifest,string $key) {
    $region=$manifest['regions'][$key]??null;
    if(!$region||!empty($region['attempts'])||($manifest['mode']==='section'&&$manifest['selected']!==$key)||
        !in_array($region['status'],array('blocked','needs_review','uncompleted','pattern_saved'),true))
        return syndamorph_scope_error('compact_state','This region cannot stage a compact guarantee.');
    $archive=$manifest['referenceArchive']??null;
    $source=syndamorph_scope_checkpoint($manifest['id'],$region['checkpoint']);
    if(!$source||!syndamorph_production_reference_valid($archive,$manifest['sourceUrl']))
        return syndamorph_scope_error('compact_source','The original compact guarantee source is unavailable.');
    $receipt=syndamorph_scope_compact_guarantee_receipt($manifest,$region);
    $content=$receipt?syndamorph_compact_guarantee_content($receipt):null;
    if($content===null){
        $replay=syndamorph_compact_guarantee_replay($source,$archive);
        if(is_wp_error($replay))return $replay;
        $created=array();$ids=array();
        foreach($replay['originals'] as $i=>$bytes){
            $sha=$replay['assets'][$i]['sha256'];
            $id=syndamorph_import_image_bytes($bytes,'compact-badge-'.substr($sha,0,16).'.png',$created,true);
            if(is_wp_error($id))return $id;
            update_post_meta($id,'_syndamorph_source_strip_sha256',$sha);
            $ids[]=$id;
        }
        unset($replay['originals']);
        $receipt=$replay+array('region'=>$key,'checkpoint'=>$region['checkpoint'],'archiveId'=>$archive['id'],
            'sourceSha256'=>hash('sha256',wp_json_encode($source)),'attachmentIds'=>$ids);
        $content=syndamorph_compact_guarantee_content($receipt);
        if($content===null)return syndamorph_scope_error('compact_asset','The imported badge bytes are unavailable.');
        $receipt['event']=syndamorph_history_append($manifest['id'],'source_compact_guarantee',$receipt);
        if(!$receipt['event'])return syndamorph_scope_error('compact_archive','The compact source receipt could not be saved.');
        $manifest['regions'][$key]['compactGuaranteeEvidence']=$receipt;
        syndamorph_history_append($manifest['id'],'scope_manifest',$manifest);
    }
    if(!empty($region['patternId'])){
        $pattern=get_post($region['patternId']);
        if(!$pattern||$pattern->post_status!=='private'||$pattern->post_type!=='wp_block'||
            get_post_meta($pattern->ID,'wp_pattern_sync_status',true)!=='unsynced'||$pattern->post_content!==$content||
            ($region['artifactSha256']??null)!==hash('sha256',$content))
            return syndamorph_scope_error('pattern_changed','The saved compact Pattern differs from its verified artifact.');
    }
    $qa=syndamorph_compact_guarantee_quality($receipt,$content);
    $result=is_wp_error($qa)?array('passed'=>false,'status'=>'pending','code'=>$qa->get_error_code()):$qa;
    syndamorph_history_append($manifest['id'],'compact_guarantee_qa',array('region'=>$key,'checkpoint'=>$region['checkpoint'],
        'qa'=>$result));
    if(!$result['passed']){
        $manifest['regions'][$key]['compactGuaranteeQa']=$result;
        $manifest['regions'][$key]['status']=empty($region['patternId'])?'blocked':'uncompleted';
        $manifest['status']='uncompleted';
        if(!empty($manifest['page']['allRegions'])){
            $manifest['page']['qa']=array('passed'=>false,'reason'=>'Compact guarantee browser QA is pending.');
            update_post_meta($manifest['page']['id'],'_syndamorph_scope_manifest',$manifest['page']);
        }
        syndamorph_history_append($manifest['id'],'scope_manifest',$manifest);
        return is_wp_error($qa)?$qa:syndamorph_scope_error('compact_qa','The compact guarantee has not passed independent browser QA.');
    }
    if(!empty($manifest['page']['allRegions'])&&($region['compactGuaranteeQa']['fingerprint']??null)!==$qa['fingerprint']){
        $manifest['page']['qa']=array('passed'=>false,'reason'=>'Reconstructed page visual comparison pending after compact styles changed.');
        $manifest['status']='uncompleted';
        update_post_meta($manifest['page']['id'],'_syndamorph_scope_manifest',$manifest['page']);
    }
    $manifest['regions'][$key]['compactGuaranteeQa']=$qa;
    $manifest['regions'][$key]['compactGuaranteeReplayAvailable']=true;
    $manifest['regions'][$key]['status']=empty($region['patternId'])?'needs_review':'pattern_saved';
    $manifest['regions'][$key]['blockers']=array();
    unset($manifest['regions'][$key]['issue']);
    syndamorph_history_append($manifest['id'],'scope_manifest',$manifest);
    return syndamorph_scope_status($manifest);
}
