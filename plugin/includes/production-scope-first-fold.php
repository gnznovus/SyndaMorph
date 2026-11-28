<?php
/** Recover the recorded desktop hero and its responsive title relocation together. */
if (!defined('ABSPATH')) exit;

function syndamorph_scope_first_fold_source(array $manifest,string $owner): ?array {
    $archive=$manifest['referenceArchive']??null;
    $summary=$manifest['regions'][$owner]['settledPair']??null;
    if(($manifest['mode']??null)!=='page'||!is_int($summary['event']??null)||
        !syndamorph_production_reference_valid($archive,$manifest['sourceUrl'])||empty($archive['runtimeSha256']))return null;
    $settled=syndamorph_scope_checkpoint($manifest['id'],$summary['event'],'source_settled_pair_verified');
    $regions=$settled['regions']??array();
    if(($settled['archiveId']??null)!==$archive['id']||($settled['mode']??null)!=='recorded'||
        ($settled['status']??null)!=='observed'||($settled['blockedRequestCount']??null)!==0||
        ($regions['owner']['region']??null)!==$owner||array_keys($regions)!==array('owner','adjacent','overlay'))return null;
    $checkpoints=array();
    foreach($regions as $role=>$binding){
        $key=$binding['region']??null;
        $id=$binding['checkpoint']??null;
        if(!is_string($key)||!is_int($id)||($manifest['regions'][$key]['checkpoint']??null)!==$id)return null;
        $checkpoints[$role]=syndamorph_scope_checkpoint($manifest['id'],$id);
    }
    $intro=$regions['adjacent']['region'];
    $offer=$regions['overlay']['region'];
    $move=$manifest['regions'][$intro]['sourceCopyPlacement']??null;
    $title=$move['copy']??null;
    if(($move['archiveId']??null)!==$archive['id']||($move['region']??null)!==$intro||
        ($move['destinationRegion']??null)!==$owner||($move['checkpoint']??null)!==$regions['adjacent']['checkpoint']||
        ($move['destinationCheckpoint']??null)!==$regions['owner']['checkpoint']||
        !is_string($title)||hash('sha256',$title)!==($move['copySha256']??null)||
        array_column($move['views']??array(),'width')!==SYNDAMORPH_SOURCE_WIDTHS||
        ($move['ownedCrop']['overlap']??null)!=44||($move['ownedCrop']['status']??null)!=='verified')return null;
    $original=array_column(syndamorph_history_latest($manifest['id'],'scope_page_source')??array(),null,'width');
    $digests=array_column($archive['views'],'copySha256','width');
    $hero=$checkpoints['owner']['variants']??array();
    $story=$checkpoints['adjacent']['variants'][1440]['inventory']??array();
    $anchor=$hero[1440]['inventory']['links'][0]['href']??null;
    if(!is_string($anchor)||!preg_match('/^#[a-z][a-z0-9-]{0,63}$/D',$anchor)||
        count($hero[1440]['inventory']['links']??array())!==1||
        ($story['links']??null)!==array()||($story['controls']??null)!==array()||
        ($settled['sourceCopySha256']??null)!==($digests[1440]??null))return null;
    $frames=array();$copy=array();
    foreach($move['views'] as $observed){
        $width=$observed['width'];
        $desktop=$width===1440;$view=$hero[$width]??array();
        $extent=$desktop?760:560;$top=$desktop?141:72;
        if(($observed['status']??null)!=='observed'||($observed['mode']??null)!=='recorded'||
            ($observed['archiveId']??null)!==$archive['id']||($observed['copySha256']??null)!==$move['copySha256']||
            ($observed['sourceCopySha256']??null)!==($digests[$width]??null)||($observed['blockedRequestCount']??null)!==0||
            ($observed['destinationRef']??null)!==($view['sourceRef']??null)||
            ($observed['placement']??null)!==($desktop?'source':'destination')||
            ($observed['originVisible']??null)!==$desktop||($view['bounds']['y']??null)!==$top||
            ($view['bounds']['height']??null)!==$extent||empty($original[$width]['ready'])||
            !syndamorph_source_screenshot_valid($original[$width]['fold']??null,$width,900)||
            !syndamorph_source_screenshot_valid($view['screenshot']??null,$width,$extent))return null;
        $frames[$width]=array('fold'=>array('image'=>$original[$width]['fold'],'top'=>0,'extent'=>900),
            'hero'=>array('image'=>$view['screenshot'],'top'=>$top,'extent'=>$extent));
        $text=syndamorph_source_text(implode(' ',$view['inventory']['text']??array()));
        if(!$desktop&&(($view['inventory']['links']??null)!==array()||($view['inventory']['controls']??null)!==array()||
            (isset($copy['compact'])&&$copy['compact']!==$text)))return null;
        $copy[$desktop?'desktop':'compact']=$text;
    }
    $copy['intro']=syndamorph_source_text(implode(' ',$story['text']??array()));
    if(($settled['pinnedHeroSha256']??null)!==hash('sha256',base64_decode($frames[1440]['hero']['image'],true)))return null;
    foreach(array('hero'=>760,'pair'=>1352) as $frame=>$extent){
        $image=$settled[$frame]['image']??null;
        if(($settled[$frame]['extent']??null)!==$extent||!syndamorph_source_screenshot_valid($image,1440,$extent)||
            hash('sha256',base64_decode($image,true))!==($settled[$frame]['sha256']??null))return null;
        $frames[1440][$frame]=array('image'=>$image,'top'=>141,'extent'=>$extent);
    }
    return compact('owner','intro','offer','frames','copy','title','anchor')+
        array('sourceEvent'=>$summary['event'],'archiveId'=>$archive['id']);
}

function syndamorph_scope_first_fold_parts(string $content): ?array {
    $nodes=array_values(array_filter(parse_blocks($content),static fn($node)=>$node['blockName']!==null));
    $types=array_column(array_slice($nodes,0,5),'blockName');
    if($types!==array('syndamorph/recorded-header','core/group','core/group','syndamorph/desktop-offer','core/group'))return null;
    $class=static fn(array $node,string $name):bool=>in_array($name,preg_split('/\s+/',(string)($node['attrs']['className']??'')),true);
    if(!$class($nodes[1],'bkkgo-desktop-hero-lead')||!$class($nodes[1],'sm-view-desktop')||
        !$class($nodes[1],'sm-offer-entrance')||!$class($nodes[2],'bkkgo-compact-hero')||
        !$class($nodes[2],'sm-view-compact')||!$class($nodes[4],'sm-view-desktop')||
        array_column($nodes[1]['innerBlocks'],'blockName')!==array('core/paragraph','core/heading','core/paragraph','syndamorph/booking-summary')||
        array_column($nodes[4]['innerBlocks'],'blockName')!==array('core/heading','core/paragraph','core/paragraph','core/paragraph'))return null;
    // The archived desktop tree keeps the header, hero and intro at a minimum of 1280 px.
    // Fixed source font sizes need equivalent clamps to bypass WordPress theme fluid sizing.
    foreach(array(1,4) as $index){
        if($class($nodes[$index],'sm-recorded-desktop-width'))continue;
        $markup=new WP_HTML_Tag_Processor($nodes[$index]['innerContent'][0]??'');
        if(!$markup->next_tag('DIV')||!$markup->has_class('wp-block-group'))return null;
        $markup->add_class('sm-recorded-desktop-width');
        $nodes[$index]['attrs']['className'].=' sm-recorded-desktop-width';
        $nodes[$index]['innerContent'][0]=$markup->get_updated_html();
    }
    foreach($nodes[4]['innerBlocks'] as $i=>&$leaf){
        $size=$i===0?'56px':'18px';
        $fixed="clamp($size,$size,$size)";
        $actual=$leaf['attrs']['style']['typography']['fontSize']??null;
        if($actual===$fixed)continue;
        $markup=new WP_HTML_Tag_Processor($leaf['innerContent'][0]??'');
        if($actual!==$size||!$markup->next_tag($i===0?'H2':'P'))return null;
        $style=$markup->get_attribute('style');
        if(!is_string($style))return null;
        $updated=preg_replace('/(^|;)font-size:'.preg_quote($size,'/').'(?=;|$)/','${1}font-size:'.$fixed,$style,1,$changed);
        if($changed!==1)return null;
        $markup->set_attribute('style',$updated);
        $leaf['attrs']['style']['typography']['fontSize']=$fixed;
        $leaf['innerContent'][0]=$markup->get_updated_html();
    }
    unset($leaf);
    $owner=serialize_blocks(array_slice($nodes,1,2));$intro=serialize_block($nodes[4]);
    if(syndamorph_scope_block_count(parse_blocks($owner))!==20||syndamorph_scope_block_count(parse_blocks($intro))!==5)return null;
    return compact('owner','intro');
}

function syndamorph_scope_first_fold_document(array $manifest,array $source,array $parts) {
    $canvas=array();$bound=array();
    foreach($manifest['regions'] as $key=>$region){
        if($key===$source['owner']||$key===$source['intro']){
            $canvas[]=$parts[$key===$source['owner']?'owner':'intro'];
            continue;
        }
        $need=in_array($region['kind']??null,array('header'),true)||$key===$source['offer'];
        $compact=syndamorph_scope_compact_kind($region);
        $rooms=isset($region['roomsQa']);
        if(!$need&&$compact===null&&!$rooms)continue;
        $candidate=syndamorph_scope_candidate($manifest,$key);
        $post=get_post((int)($region['patternId']??0));
        if(empty($candidate['eligible'])||!$post||$post->post_status!=='private'||$post->post_type!=='wp_block'||
            get_post_meta($post->ID,'wp_pattern_sync_status',true)!=='unsynced'||
            $post->post_content!==$candidate['content']||($region['artifactSha256']??null)!==$candidate['checksum'])return null;
        $bound[$region['kind']==='header'?'header':($key===$source['offer']?'offer':($rooms?'rooms':$compact))]=$candidate['checksum'];
        $canvas[]=$post->post_content;
    }
    if(count($bound)!==5)return null;
    return array('content'=>implode("\n\n",$canvas),'dependencies'=>$bound);
}

function syndamorph_scope_first_fold_quality(array $manifest,array $source,array $parts) {
    $document=syndamorph_scope_first_fold_document($manifest,$source,$parts);
    if(!$document)return syndamorph_scope_error('first_fold_dependencies','The first fold needs the original verified header, offer, compact Patterns and rooms anchor.');
    $html=syndamorph_scope_document($document['content'],'Recorded first fold',false);
    $frame=syndamorph_production_font_frame($html);
    if(is_wp_error($frame))return $frame;
    $html=syndamorph_image_strip_frame($frame['html']);
    if(is_wp_error($html))return $html;
    $runtime=implode("\n",array_map(static fn($name)=>file_get_contents(dirname(__DIR__).'/assets/production-'.$name.'-view.js'),
        array('header','offer','booking')));
    $build=null;$proof=array();$passed=true;
    foreach(array(array(1440,1280,1200,1024,1023),array(900,768,600,450,375)) as $widths){
        $response=syndamorph_internal_request('renderer','POST','/check-first-fold',array('timeout'=>65,
            'limit_response_size'=>20000,'headers'=>array('Content-Type'=>'application/json'),
            'body'=>wp_json_encode(array('html'=>$html,'viewports'=>$widths,'runtime'=>$runtime,
                'frames'=>array_intersect_key($source['frames'],array_flip($widths)),
                'copy'=>$source['copy'],'title'=>$source['title'],'anchor'=>$source['anchor']))));
        if(is_wp_error($response))return $response;
        $row=json_decode(wp_remote_retrieve_body($response),true);
        $batch=$row['evidence']??null;
        $current=wp_remote_retrieve_header($response,'x-syndamorph-renderer-build');
        if(wp_remote_retrieve_response_code($response)!==200||!is_array($batch)||array_column($batch,'width')!==$widths||
            (string)wp_remote_retrieve_header($response,'x-syndamorph-inspection-protocol')!==(string)SYNDAMORPH_INSPECTION_PROTOCOL||
            !is_string($current)||!preg_match('/^[a-f0-9]{64}$/D',$current)||($build!==null&&$current!==$build))
            return new WP_Error('sm_first_fold_renderer','Independent first fold evidence is pending.',
                array('status'=>409,'widths'=>$widths,'httpStatus'=>wp_remote_retrieve_response_code($response),
                    'reason'=>$row['error']??'incomplete'));
        $build=$current;
        foreach($batch as $sample){
            $width=$sample['width'];$view=$sample['proof']??array();
            $expected=array_keys($source['frames'][$width]??array());$ratios=$view['ratios']??null;
            $passed=$passed&&($view['responsive']??null)===true&&($view['functional']??null)===true&&
                ($view['copy']??null)===true&&($view['offline']??null)===true&&is_array($ratios)&&
                array_keys($ratios)===$expected&&!array_filter($ratios,static fn($ratio)=>
                    (!is_float($ratio)&&!is_int($ratio))||!is_finite((float)$ratio)||$ratio<0||$ratio>0.005);
            $proof[]=$sample;
        }
    }
    return array('passed'=>$passed,'rendererBuild'=>$build,'views'=>$proof,'dependencies'=>$document['dependencies'],
        'fingerprint'=>syndamorph_scope_first_fold_fingerprint());
}

function syndamorph_scope_first_fold_fingerprint(): ?string {
    $fonts=syndamorph_compact_fingerprint();
    if(!$fonts)return null;
    $files=array(__FILE__,__DIR__.'/production-booking.php',__DIR__.'/production-block-plan.php');
    foreach(array('booking','offer','header') as $name){
        $files[]=dirname(__DIR__).'/assets/production-'.$name.'.css';
        $files[]=dirname(__DIR__).'/assets/production-'.$name.'-view.js';
    }
    return hash('sha256',$fonts.implode('',array_map(static fn($file)=>hash_file('sha256',$file),$files)));
}

function syndamorph_scope_first_fold_record(array $manifest,array $region): ?array {
    $event=$region['firstFoldEvent']??null;
    $record=is_int($event)?syndamorph_scope_checkpoint($manifest['id'],$event,'source_first_fold_candidate'):null;
    $keys=$record['regions']??array();
    $parts=$record['parts']??array();
    if(count($keys)!==2||count($parts)!==2||!in_array($region['id'],$keys,true)||
        ($record['qa']['passed']??null)!==true||
        ($record['qa']['fingerprint']??null)!==syndamorph_scope_first_fold_fingerprint())return null;
    foreach($keys as $role=>$key){
        if(($manifest['regions'][$key]['firstFoldEvent']??null)!==$event||
            ($manifest['regions'][$key]['checkpoint']??null)!==($record['checkpoints'][$role]??null)||
            !is_string($parts[$role]??null)||hash('sha256',$parts[$role])!==($record['hashes'][$role]??null))return null;
    }
    $source=syndamorph_scope_first_fold_source($manifest,$keys['owner']);
    if(!$source||$source['intro']!==$keys['intro']||$source['sourceEvent']!==$record['sourceEvent']||
        $source['archiveId']!==$record['archiveId'])return null;
    $document=syndamorph_scope_first_fold_document($manifest,$source,$parts);
    if(!$document||$document['dependencies']!==$record['qa']['dependencies'])return null;
    return $record;
}

function syndamorph_scope_first_fold_candidate(array $manifest,array $region): ?array {
    $record=syndamorph_scope_first_fold_record($manifest,$region);
    if(!$record)return null;
    $role=array_search($region['id'],$record['regions'],true);
    return array('verifiedLayout'=>'firstFold','content'=>$record['parts'][$role],'checksum'=>$record['hashes'][$role],
        'eligible'=>true,'hostChecks'=>array());
}

function syndamorph_scope_stage_first_fold(array $manifest,string $owner,int $draftId) {
    $source=syndamorph_scope_first_fold_source($manifest,$owner);
    $draft=get_post($draftId);
    if(!$source||!$draft||$draft->post_type!=='page'||$draft->post_status!=='draft'||!current_user_can('edit_post',$draftId)||
        strlen($draft->post_content)>2*1024*1024)
        return syndamorph_scope_error('first_fold_source','Select an editable unpublished draft with the recorded hero and intro.');
    $parts=syndamorph_scope_first_fold_parts($draft->post_content);
    if(!$parts)return syndamorph_scope_error('first_fold_markup','The draft does not have the retained editable hero and intro structure.');
    $regions=array('owner'=>$owner,'intro'=>$source['intro']);
    $checkpoints=array();$hashes=array_map(static fn($content)=>hash('sha256',$content),$parts);
    foreach($regions as $role=>$key){
        $region=$manifest['regions'][$key];
        if(!empty($region['attempts'])||!in_array($region['status'],array('blocked','needs_review','uncompleted','pattern_saved'),true)||
            (!empty($region['patternId'])&&($region['artifactSha256']??null)!==$hashes[$role]))
            return syndamorph_scope_error('first_fold_state','The retained pair would replace an existing production artifact.');
        $checkpoints[$role]=$region['checkpoint'];
    }
    $qa=syndamorph_scope_first_fold_quality($manifest,$source,$parts);
    if(is_wp_error($qa))return $qa;
    if(!$qa['passed'])return new WP_Error('sm_first_fold_qa','The retained first fold is still pending independent QA.',
        array('status'=>409,'views'=>$qa['views']));
    $event=syndamorph_history_append($manifest['id'],'source_first_fold_candidate',compact('regions','checkpoints','parts','hashes','qa')+
        array('sourceEvent'=>$source['sourceEvent'],'archiveId'=>$source['archiveId'],'draftId'=>$draftId,
            'draftSha256'=>hash('sha256',$draft->post_content)));
    if(!$event)return syndamorph_scope_error('first_fold_archive','Could not preserve the first fold QA receipt.');
    foreach($regions as $key){
        $manifest['regions'][$key]['firstFoldEvent']=$event;
        $manifest['regions'][$key]['status']=empty($manifest['regions'][$key]['patternId'])?'needs_review':'pattern_saved';
    }
    if(!syndamorph_history_append($manifest['id'],'scope_manifest',$manifest))
        return syndamorph_scope_error('first_fold_archive','Could not bind the first fold receipt to the scope.');
    return syndamorph_scope_status($manifest);
}

function syndamorph_scope_first_fold_save_quality(array $manifest,array $region) {
    $record=syndamorph_scope_first_fold_record($manifest,$region);
    $source=$record?syndamorph_scope_first_fold_source($manifest,$record['regions']['owner']):null;
    return $source?syndamorph_scope_first_fold_quality($manifest,$source,$record['parts']):
        syndamorph_scope_error('first_fold_source','The original first fold receipt no longer matches this scope.');
}
