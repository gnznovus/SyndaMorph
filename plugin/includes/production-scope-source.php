<?php
/** Pinned captured-source evidence for the shared native production engine. */
if (!defined('ABSPATH')) { exit; }

const SYNDAMORPH_SOURCE_WIDTHS = array(1440,768,375);

function syndamorph_source_screenshot_valid($data,int $width,int $height): bool {
    if(!is_string($data)||strlen($data)>SYNDAMORPH_INSPECTION_SCREENSHOT_BYTES||$height<1||$height>36000)return false;
    $bytes=base64_decode($data,true);
    $image=$bytes!==false&&str_ends_with($bytes,"\xFF\xD9")?@getimagesizefromstring($bytes):false;
    return $image&&($image['mime']??'')==='image/jpeg'&&
        abs($image[0]-$width*min(1,900/$height))<=2&&abs($image[1]-min(900,$height))<=2;
}

/** One measured strip with a stable target across its supported responsive variants. */
function syndamorph_source_scroll_profile(array $region): ?array {
    $active=array();$cardCopy=array();$target=null;$cards=null;
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $controls=$region['variants'][$width]['inventory']['controls']??null;
        if(!is_array($controls)||!array_is_list($controls))return null;
        if(!$controls)continue;
        $strip=$controls[0]['strip']??null;
        if(count($controls)!==1||($controls[0]['type']??null)!=='horizontal-scroll'||($strip['version']??null)!==1||
            !is_string($strip['targetId']??null)||!preg_match('/^[a-f0-9]{24}$/D',$strip['targetId'])||
            !is_int($strip['cards']??null)||$strip['cards']<2||$strip['cards']>6||
            !is_string($strip['copySha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$strip['copySha256'])||
            ($target!==null&&$target!==$strip['targetId'])||($cards!==null&&$cards!==$strip['cards']))return null;
        $target=$strip['targetId'];$cards=$strip['cards'];
        $copy=$strip['cardCopy']??null;
        if(!is_array($copy)||!array_is_list($copy)||count($copy)!==$cards||
            array_filter($copy,static fn($text)=>!is_string($text)||mb_strlen($text)>4000)||
            hash('sha256',wp_json_encode($copy,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))!==$strip['copySha256'])return null;
        $active[$width]=$strip['copySha256'];
        $cardCopy[$width]=$copy;
    }
    $widths=array_keys($active);
    if($widths!==array(768,375)&&$widths!==array(375))return null;
    return array('id'=>'scroll-'.$target,'targetId'=>$target,'cards'=>$cards,
        'visibility'=>$widths===array(375)?'mobile':'compact','copyByWidth'=>$active,'cardCopyByWidth'=>$cardCopy);
}

/** Verify one source-bound Details group, including every recorded transition. */
function syndamorph_source_disclosure_profile(array $source,?array $archive=null): ?array {
    $receipt=$source['disclosureEvidence']??null;
    $id=$source['id']??null;
    if(!is_string($id)||!preg_match('/^[a-f0-9]{24}$/D',$id)||!is_array($receipt)||
        !is_array($receipt['views']??null)||array_keys($receipt['views'])!==SYNDAMORPH_SOURCE_WIDTHS||
        !is_string($receipt['archiveId']??null)||!preg_match('/^[a-f0-9]{64}$/D',$receipt['archiveId']))return null;
    if($archive!==null&&(!syndamorph_production_reference_valid($archive,$archive['sourceUrl']??'')||
        empty($archive['runtimeSha256'])||$archive['id']!==$receipt['archiveId']))return null;
    $pinned=$source;
    unset($pinned['disclosureEvidence']);
    if(($receipt['sourceSha256']??null)!==hash('sha256',wp_json_encode($pinned)))return null;
    $ordered=null;$targets=array();$observed=array();
    $archiveCopies=array_column($archive['views']??array(),'copySha256','width');
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $view=$receipt['views'][$width];$variant=$source['variants'][$width]??array();
        $panels=$view['panels']??null;$clicks=$view['clicks']??null;
        $inventory=$variant['inventory']??array();
        $ref=$variant['sourceRef']??'';
        if(($view['archiveId']??null)!==$receipt['archiveId']||($view['mode']??null)!=='recorded'||
            ($view['width']??null)!==$width||($view['sourceRef']??null)!==$ref||
            ($view['status']??null)!=='observed'||($view['blockedRequestCount']??null)!==0||
            ($view['initial']??null)!==array(0)||($view['indicator']??null)!=='chevron-20'||
            ($view['keyboard']??null)!=='unverified'||($view['externalEffects']??null)!=='unverified'||
            !preg_match('/^[a-f0-9]{64}$/D',$view['sourceCopySha256']??'')||
            ($archive!==null&&($archiveCopies[$width]??null)!==$view['sourceCopySha256'])||
            !is_array($panels)||!array_is_list($panels)||count($panels)<2||count($panels)>8||
            !is_array($clicks)||!array_is_list($clicks)||count($clicks)!==count($panels)+1||
            array_column($inventory['controls']??array(),'type')!==array_fill(0,count($panels),'button')||
            array_column($inventory['assets']??array(),'type')!==array_fill(0,count($panels),'svg')||
            !empty($inventory['links']))return null;
        $copy=array();
        foreach($panels as $index=>$panel){
            $summary=$panel['summary']??null;$answer=$panel['answer']??null;
            if(!is_string($summary)||$summary===''||mb_strlen($summary)>240||
                !is_string($answer)||$answer===''||mb_strlen($answer)>1600||
                substr(hash('sha256',wp_json_encode(array($ref,$index,$summary),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),0,24)!==($panel['targetId']??null))
                return null;
            $copy[]=array('summary'=>$summary,'answer'=>$answer);
        }
        $sequence=array_merge(range(1,count($panels)-1),array(0,0));$after=null;
        foreach($sequence as $position=>$index){
            $click=$clicks[$position];$from=$click['fromSha256']??'';$to=$click['toSha256']??'';
            if(($click['index']??null)!==$index||($click['targetId']??null)!==$panels[$index]['targetId']||
                ($click['open']??null)!==($position===count($sequence)-1?array():array($index))||
                !preg_match('/^[a-f0-9]{64}$/D',$from)||!preg_match('/^[a-f0-9]{64}$/D',$to)||
                $from===$to||($after!==null&&$after!==$from))return null;
            $after=$to;
        }
        if($ordered!==null&&$ordered!==$copy)return null;
        $ordered=$copy;
        $targets[$width]=array_column($panels,'targetId');
        $observed[$width]=array_column($clicks,'open');
    }
    return array('id'=>'disclosure-'.$id,'panels'=>$ordered,'targetIds'=>$targets,'observedOpenStates'=>$observed,
        'initial'=>array(0),'indicator'=>'chevron-20','classification'=>'native','externalDependencies'=>array(),
        'sourceKeyboard'=>'unverified','nativeKeyboardRequired'=>true,'archiveId'=>$receipt['archiveId']);
}

function syndamorph_source_disclosure_touch(array $source,array $archive) {
    if(!syndamorph_disclosures_supported()||!syndamorph_production_reference_valid($archive,$archive['sourceUrl']??'')||
        empty($archive['runtimeSha256']))
        return new WP_Error('sm_disclosure_source','Details on this host requires the verified recorded source runtime.');
    if(!syndamorph_internal_configured('backend'))
        return new WP_Error('sm_disclosure_source','Recorded source replay is unavailable.');
    $receipt=array('archiveId'=>$archive['id'],'sourceSha256'=>hash('sha256',wp_json_encode($source)),'views'=>array());
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $ref=$source['variants'][$width]['sourceRef']??'';
        if(!preg_match('/^(?:[a-z][a-z0-9-]{0,20})?\[(?:data-dc-tpl|id)="[a-zA-Z0-9_-]{1,64}"\]$/D',$ref))
            return new WP_Error('sm_disclosure_source','The exact disclosure region identity is unsupported.',array('width'=>$width));
        $response=syndamorph_internal_request('backend','POST','/production-touch',array('timeout'=>45,'redirection'=>0,
            'limit_response_size'=>64000,'headers'=>array('Content-Type'=>'application/json'),
            'body'=>wp_json_encode(array('archiveId'=>$archive['id'],'width'=>$width,'sourceRef'=>$ref,'kind'=>'disclosure-group'))));
        $view=is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200?null:
            json_decode(wp_remote_retrieve_body($response),true);
        if(($view['status']??null)!=='observed'){
            $reason=$view['reason']??null;
            return new WP_Error('sm_disclosure_source','Recorded disclosure proof is incomplete.',
                array('width'=>$width,'reason'=>is_string($reason)&&preg_match('/^[a-z_]{1,60}$/D',$reason)?$reason:'replay_unavailable'));
        }
        $receipt['views'][$width]=$view;
    }
    $proved=$source+array('disclosureEvidence'=>$receipt);
    if(syndamorph_source_disclosure_profile($proved,$archive)===null)
        return new WP_Error('sm_disclosure_source','Recorded copy or action sequences do not bind to the pinned region.');
    return $proved;
}

function syndamorph_source_blockers(array $region,?array $archive=null): array {
    $blockers=array();
    $strip=!empty($archive['runtimeSha256'])&&is_string($archive['sourceUrl']??null)&&
        syndamorph_production_reference_valid($archive,$archive['sourceUrl'])?syndamorph_source_scroll_profile($region):null;
    $disclosure=$archive!==null&&syndamorph_disclosures_supported()?syndamorph_source_disclosure_profile($region,$archive):null;
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width) {
        $view=$region['variants'][$width]??null;
        if(!is_array($view) || empty($view['html']) || !is_array($view['inventory']??null) ||
            !syndamorph_source_screenshot_valid($view['screenshot']??null,$width,(int)round($view['bounds']['height']??0))) {
            $blockers[]="No proven source variant and browser evidence at {$width}px.";
            continue;
        }
        $inventory=$view['inventory'];
        if(!empty($inventory['controls'])&&!isset($strip['copyByWidth'][$width])&&$disclosure===null) {
            $types=array_values(array_unique(array_column($inventory['controls'],'type')));
            $blockers[]="{$width}px source actions need a native implementation and functional tests (".implode(', ',$types).').';
        }
        if(!empty($inventory['assets'])&&$disclosure===null) {
            $types=array_values(array_unique(array_column($inventory['assets'],'type')));
            $blockers[]="{$width}px visual assets are outside the current AI block contract (".implode(', ',$types).').';
        }
        foreach($inventory['links']??array() as $link) {
            $local=preg_match('/^#[a-z][a-z0-9_-]*$/iD',(string)($link['href']??''));
            $blockers[]=$local?"{$width}px source anchor destinations cannot be reconstructed by the current block contract.":
                "{$width}px link targets require support outside the current local CTA contract.";
            break;
        }
        if(empty($inventory['text']))$blockers[]="No editable literal text captured at {$width}px.";
        if(($view['kind']??'')==='overlay'||in_array($inventory['position']??'',array('fixed','sticky'),true))
            $blockers[]="{$width}px overlay or sticky placement requires native behavior support.";
    }
    return $blockers;
}

/** Preserve the original work order and revise only claims contradicted by source evidence. */
function syndamorph_source_work_order_amend(array $specification,array $source,array $samples,array $changes) {
    $error=static fn(string $reason): WP_Error=>new WP_Error('sm_scope_work_order_evidence',$reason);
    $region=$source['scopeRegion']??null;
    $archive=$source['referenceArchive']??null;
    if(($specification['format']??null)!=='structured'||!is_array($region)||!is_string($source['url']??null)||
        !syndamorph_production_reference_valid($archive,$source['url'])||
        !array_is_list($changes)||!$changes||count($changes)>7||array_column($samples,'width')!==SYNDAMORPH_SOURCE_WIDTHS||
        !syndamorph_source_verify_markup($region,$samples)['passed'])
        return $error('A work-order correction requires a structured assignment and exact pinned source markup evidence.');
    $requirements=array_column($specification['requirements'],null,'id');
    $sourceText=array();$static=true;
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $view=$region['variants'][$width];
        $inventory=$view['inventory'];
        $static=$static&&($inventory['links']??null)===array()&&($inventory['controls']??null)===array();
        $sourceText[$width]=$view['facts']['copy']??syndamorph_source_text(implode(' ',$inventory['text']));
    }
    $previous=array();
    $hostKinds=array();$sourceChanges=0;
    foreach($changes as $change){
        $id=$change['id']??null;$kind=$change['kind']??null;$literal=$change['literal']??null;
        if(!is_array($change)||!is_string($id)||!isset($requirements[$id])||isset($previous[$id])||
            !is_string($literal)||trim($literal)!==$literal||$literal===''||mb_strlen($literal)>240||
            array_diff(array_keys($change),array('id','kind','literal')))
            return $error('A work-order correction must identify one original requirement and literal source text.');
        $original=$requirements[$id];
        $revised=$original;
        $check=$original['check']??null;
        if($kind!=='host-context'&&++$sourceChanges>5)return $error('The source work-order correction limit was exceeded.');
        if($kind==='style-target'&&($check['kind']??null)==='style'&&!isset($check['literal'])&&
            preg_match('/^[a-z][a-z0-9]*$/D',$check['selector'])&&
            syndamorph_design_run_check($check,$samples)['status']==='failed'){
            $revised['check']['literal']=$literal;
            $widths=$check['widths']??SYNDAMORPH_SOURCE_WIDTHS;
            foreach($widths as $width)if(!str_contains($sourceText[$width]??'',$literal))
                return $error('The style target is not present in the required source widths.');
            if(syndamorph_design_run_check($revised['check'],$samples)['status']!=='verified')
                return $error('The exact text target does not uniquely satisfy the original computed style at every required width.');
        }elseif($kind==='source-label'&&$static&&empty($specification['interactions'])&&$check===null&&
            (($original['dimension']==='functional'&&$original['basis']==='source')||
                ($original['dimension']==='accessibility'&&$original['basis']==='standard'))&&
            preg_match('/\b(?:links?|destination|external)\b/i',$original['requirement'].' '.$original['acceptance'])&&
            array_filter($sourceText,static fn($copy)=>str_contains($copy,$literal))){
            $revised['dimension']=$original['dimension']==='functional'?'content':'accessibility';
            $revised['requirement']='Preserve the observed source label as noninteractive text: '.$literal;
            $revised['acceptance']='Preserve the exact source text and viewport visibility. The captured region has no anchor, interactive target or destination.';
            $revised['check']=array('kind'=>'element-absent','selector'=>'a','widths'=>SYNDAMORPH_SOURCE_WIDTHS);
            if(syndamorph_design_run_check($revised['check'],$samples)['status']!=='verified')
                return $error('The saved reference does not prove the absence of anchors at every required width.');
        }elseif($kind==='host-context'&&$check===null&&$original['dimension']==='accessibility'&&$original['basis']==='standard'&&
            in_array($literal,array('heading','contrast'),true)&&!isset($hostKinds[$literal])&&
            preg_match('/\b'.$literal.'\b/i',$original['requirement'].' '.$original['acceptance'])){
            $revised['check']=array('kind'=>'host-'.$literal);
            $hostKinds[$literal]=true;
        }else return $error('The requested correction is outside the verified source or work-order contract.');
        $previous[$id]=$original;
        $requirements[$id]=$revised;
    }
    $candidate=$specification;
    $candidate['requirements']=array_values($requirements);
    $canonical=syndamorph_production_specification($candidate);
    if(is_wp_error($canonical))return $canonical;
    return array('specification'=>$canonical,'changes'=>array_map(static fn($row)=>array('before'=>$row,
        'after'=>$requirements[$row['id']]),array_values($previous)));
}

/** Require an exact archived local gesture before any provider generation. */
function syndamorph_source_scroll_touch(array $profile,array $archive,?callable $on_event=null) {
    $proof=array();
    $copies=array_column($archive['views'],'copySha256','width');
    foreach($profile['copyByWidth'] as $width=>$_){
        $action=array('type'=>'scroll-x','target'=>$profile['targetId'],'dx'=>$width===768?650:360);
        $key=hash('sha256',wp_json_encode(array('archiveId'=>$archive['id'],'width'=>$width,'actions'=>array($action))));
        $receipts=$GLOBALS['syndamorph_touch_receipts']??array();
        if(!isset($receipts[$key])){
            $touch=syndamorph_production_touch(array('width'=>$width,'actions'=>array($action)),$archive,$on_event);
            if(is_wp_error($touch))return new WP_Error('sm_source_scroll_pending',
                "Archived scroll replay is pending at {$width}px.",array('width'=>$width,'targetId'=>$profile['targetId'],
                    'archiveId'=>$archive['id'],'reason'=>$touch->get_error_code()));
            $receipts=$GLOBALS['syndamorph_touch_receipts']??array();
        }
        $receipt=$receipts[$key]??array();
        $rows=syndamorph_production_touch_scenarios(array($receipt));
        $scenario=$rows[0]??array();
        $raw=$receipt['result']['scenarios'][0]??array();
        $settled=$scenario['after']['targetState']['scrollLeft']??null;
        if(($receipt['result']['mode']??null)!=='recorded'||($receipt['result']['status']??null)!=='observed'||
            ($receipt['result']['initial']['width']??null)!==$width||
            ($receipt['result']['initial']['copySha256']??null)!==$copies[$width]||
            count($rows)!==1||($scenario['width']??null)!==$width||($scenario['archiveId']??null)!==$archive['id']||
            ($scenario['action']??null)!==$action||($scenario['targetId']??null)!==$profile['targetId']||
            ($raw['from']['target']['id']??null)!==$profile['targetId']||($raw['to']['target']['id']??null)!==$profile['targetId']||
            ($scenario['status']??null)!=='observed'||($scenario['changes']??null)!==array('horizontal-scroll')||
            ($scenario['blockedRequestCount']??null)!==0||($scenario['before']['width']??null)!==$width||
            ($scenario['after']['width']??null)!==$width||($scenario['before']['copySha256']??null)!==$copies[$width]||
            ($scenario['after']['copySha256']??null)!==$copies[$width]||
            ($scenario['before']['targetState']['scrollLeft']??null)!==0||!is_int($settled)||$settled<=0||
            !preg_match('/^[a-f0-9]{64}$/D',(string)($scenario['beforeSha256']??''))||
            !preg_match('/^[a-f0-9]{64}$/D',(string)($scenario['afterSha256']??''))||
            $scenario['beforeSha256']===$scenario['afterSha256'])
            return new WP_Error('sm_source_scroll_pending',"Archived scroll movement is unverified at {$width}px.",
                array('width'=>$width,'targetId'=>$profile['targetId'],'archiveId'=>$archive['id'],
                    'reason'=>$scenario['reason']??'invalid_scroll_evidence'));
        $proof[]=$scenario;
    }
    return $proof;
}

function syndamorph_source_text(string $text): string {
    return trim(preg_replace('/\s+/u',' ',$text)??'');
}

/** An absent region needs a separate receipt when its leading copy moves to a captured sibling. */
function syndamorph_source_copy_placement(array $source,array $destination,array $archive) {
    $fail=static fn(string $reason,?int $width=null)=>new WP_Error('sm_source_copy_placement',
        'Responsive copy placement has no verified recorded proof.',array('status'=>409,'reason'=>$reason,'width'=>$width));
    $desktop=$source['variants'][1440]??array();
    $sourceRef=$desktop['sourceRef']??'';
    $inventory=$desktop['inventory']??array();
    $lines=$inventory['text']??array();
    $validRef=static fn($ref):bool=>is_string($ref)&&
        preg_match('/^(?:[a-z][a-z0-9-]{0,20})?\[(?:data-dc-tpl|id)="[a-zA-Z0-9_-]{1,64}"\]$/D',$ref);
    if(array_keys($source['variants']??array())!==array(1440)||($desktop['kind']??null)!=='content'||
        !$validRef($sourceRef)||($inventory['assets']??null)!==array()||($inventory['links']??null)!==array()||
        ($inventory['controls']??null)!==array()||!is_array($lines)||!array_is_list($lines)||count($lines)<3||
        !is_string($lines[0])||!is_string($lines[1])||
        !syndamorph_production_reference_valid($archive,$archive['sourceUrl']??'')||empty($archive['runtimeSha256'])||
        !syndamorph_internal_configured('backend'))
        return $fail('unbound_source');
    $copy=syndamorph_source_text($lines[0].' '.$lines[1]);
    if(mb_strlen($copy)<3||mb_strlen($copy)>160)return $fail('unbound_copy');
    $pageDigests=array_column($archive['views'],'copySha256','width');
    $targets=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $view=$destination['variants'][$width]??array();
        $ref=$view['sourceRef']??null;
        $text=$view['inventory']['text']??array();
        if(!$validRef($ref)||$ref===$sourceRef||($view['kind']??null)!=='content'||!is_array($text)||
            count(array_keys($text,$copy,true))!==(int)($width!==1440))
            return $fail('unbound_destination',$width);
        $targets[$width]=$ref;
    }
    $desktopBounds=$desktop['bounds']??array();
    $top=$desktopBounds['y']??null;
    $overhangs=array_values(array_filter($destination['variants'][1440]['overhangs']??array(),
        static function(array $entry)use($top,$validRef):bool {
            $bounds=$entry['bounds']??array();
            return is_numeric($top)&&$validRef($entry['sourceRef']??null)&&($entry['position']??null)==='absolute'&&
                ($entry['edges']['bottom']??0)>0&&is_numeric($bounds['y']??null)&&is_numeric($bounds['height']??null)&&
                $bounds['y']<$top&&$bounds['y']+$bounds['height']>$top;
        }));
    $original=is_string($desktop['screenshot']??null)?base64_decode($desktop['screenshot'],true):false;
    $cropRequest=count($overhangs)===1&&$original?array('overlapRef'=>$overhangs[0]['sourceRef'],
        'compositedSha256'=>hash('sha256',$original)):null;
    $ownedCrop=null;
    $views=array();
    $copySha=hash('sha256',$copy);
    foreach($targets as $width=>$ref){
        $captureCrop=$width===1440&&$cropRequest!==null;
        $response=syndamorph_internal_request('backend','POST','/production-touch',array('timeout'=>35,'redirection'=>0,
            'limit_response_size'=>$captureCrop?436000:16000,'headers'=>array('Content-Type'=>'application/json'),
            'body'=>wp_json_encode(array('archiveId'=>$archive['id'],'kind'=>'responsive-copy-placement',
                'width'=>$width,'sourceRef'=>$sourceRef,'destinationRef'=>$ref,'copy'=>$copy)+
                ($captureCrop?array('ownedCrop'=>$cropRequest):array()))));
        $view=is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200?null:
            json_decode(wp_remote_retrieve_body($response),true);
        $box=$view['bounds']??null;
        if(($view['version']??null)!==1||($view['status']??null)!=='observed'||($view['mode']??null)!=='recorded'||
            ($view['archiveId']??null)!==$archive['id']||($view['width']??null)!==$width||
            ($view['sourceRef']??null)!==$sourceRef||($view['destinationRef']??null)!==$ref||
            ($view['sourceCopySha256']??null)!==($pageDigests[$width]??null)||($view['copySha256']??null)!==$copySha||
            ($view['originVisible']??null)!==($width===1440)||($view['placement']??null)!==($width===1440?'source':'destination')||
            !is_array($box)||!array_is_list($box)||count($box)!==4||
            array_filter($box,static fn($value)=>(!is_int($value)&&!is_float($value))||!is_finite($value))||
            $box[2]<=0||$box[3]<=0||!is_int($view['pageWidth']??null)||$view['pageWidth']<$width||
            ($view['blockedRequestCount']??null)!==0){
            $reason=$view['reason']??null;
            return $fail(is_string($reason)&&preg_match('/^[a-z_]{1,64}$/D',$reason)?$reason:'receipt_mismatch',$width);
        }
        if($captureCrop){
            $ownedCrop=syndamorph_source_owned_crop($view['ownedCrop']??null,$cropRequest,$desktopBounds,
                $sourceRef,$ref,(float)$overhangs[0]['edges']['bottom']);
        }
        unset($view['ownedCrop']);
        $views[]=$view;
    }
    return array('archiveId'=>$archive['id'],'copy'=>$copy,'copySha256'=>$copySha,'views'=>$views)+
        ($ownedCrop===null?array():array('ownedCrop'=>$ownedCrop));
}

function syndamorph_source_owned_crop($crop,array $request,array $bounds,string $sourceRef,string $destinationRef,float $overlap): array {
    $pending=array('status'=>'pending','reason'=>'owned_crop_unverified');
    if(($crop['status']??null)==='pending'&&($crop['reason']??null)==='owned_crop_capture_failed')
        $pending['reason']='owned_crop_capture_failed';
    $top=(int)round((float)($bounds['y']??-1));
    $height=(int)round((float)($bounds['height']??0));
    $base64=$crop['screenshot']??null;
    $depth=$crop['overlap']??null;
    if(($crop['width']??null)!==1440||($crop['top']??null)!==$top||($crop['height']??null)!==$height||
        $top<0||$height<1||$height>12000||($crop['sourceRef']??null)!==$sourceRef||
        ($crop['destinationRef']??null)!==$destinationRef||($crop['overlapRef']??null)!==$request['overlapRef']||
        ($crop['compositedSha256']??null)!==$request['compositedSha256']||
        (!is_int($depth)&&!is_float($depth))||!is_finite($depth)||$depth<=0||abs($depth-$overlap)>1||
        !is_string($base64)||strlen($base64)>400000)return $pending;
    $bytes=base64_decode($base64,true);
    $size=is_string($bytes)?@getimagesizefromstring($bytes):false;
    $sha=is_string($bytes)?hash('sha256',$bytes):null;
    if(!$size||$size['mime']!=='image/jpeg'||!str_ends_with($bytes,"\xFF\xD9")||
        abs($size[0]-1440*min(1,900/$height))>2||abs($size[1]-min(900,$height))>2||
        ($crop['cropSha256']??null)!==$sha||$sha===$request['compositedSha256'])return $pending;
    return array('status'=>'verified','width'=>1440,'top'=>$top,'height'=>$height,'overlap'=>$depth,
        'cropSha256'=>$sha,'screenshot'=>$base64)+$request;
}

function syndamorph_source_settled_pair(array $owner,array $adjacent,array $overlay,array $archive,
    string $leadRef,string $dismissTargetId) {
    $fail=static fn(string $reason)=>new WP_Error('sm_settled_pair',
        'The recorded desktop entrance has no verified settled evidence.',array('status'=>409,'reason'=>$reason));
    $ref=static fn($value):bool=>is_string($value)&&
        preg_match('/^(?:[a-z][a-z0-9-]{0,20})?\[(?:data-dc-tpl|id)="[a-zA-Z0-9_-]{1,64}"\]$/D',$value);
    $a=$owner['variants'][1440]??array();
    $b=$adjacent['variants'][1440]??array();
    $overlayView=$overlay['variants'][1440]??array();
    $overlayControls=$overlayView['inventory']['controls']??array();
    $dismissLabel=$overlayControls[0]['name']??null;
    $owners=array($a['sourceRef']??null,$b['sourceRef']??null,$overlayView['sourceRef']??null,$leadRef);
    $pinned=is_string($a['screenshot']??null)?base64_decode($a['screenshot'],true):false;
    if(($a['kind']??null)!=='content'||($b['kind']??null)!=='content'||($overlayView['kind']??null)!=='overlay'||
        !is_array($overlayControls)||count($overlayControls)!==1||($overlayControls[0]['type']??null)!=='button'||
        !is_string($dismissLabel)||!preg_match('/^Continue to website(?: →)?$/uD',$dismissLabel)||
        count(array_filter($owners,$ref))!==4||count(array_unique($owners))!==4||
        !preg_match('/^[a-f0-9]{24}$/D',$dismissTargetId)||!$pinned||
        !syndamorph_production_reference_valid($archive,$archive['sourceUrl']??'')||empty($archive['runtimeSha256']))
        return $fail('unbound_source');
    $top=(int)round((float)($a['bounds']['y']??-1));
    $hero=(int)round((float)($a['bounds']['height']??0));
    $intro=(int)round((float)($b['bounds']['height']??0));
    if($top<0||$hero<1||$intro<1||$hero+$intro>12000||$top+$hero!==(int)round((float)($b['bounds']['y']??-1)))
        return $fail('unbound_region_geometry');
    $pinnedSha=hash('sha256',$pinned);
    $response=syndamorph_internal_request('backend','POST','/production-touch',array(
        'timeout'=>45,'redirection'=>0,'limit_response_size'=>820000,'headers'=>array('Content-Type'=>'application/json'),
        'body'=>wp_json_encode(array('kind'=>'settled-content-pair','width'=>1440,'archiveId'=>$archive['id'],
            'sourceRef'=>$owners[0],'adjacentRef'=>$owners[1],'overlayRef'=>$owners[2],'leadRef'=>$leadRef,
            'dismissTargetId'=>$dismissTargetId,'pinnedHeroSha256'=>$pinnedSha))));
    $verified=is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200?null:
        json_decode(wp_remote_retrieve_body($response),true);
    $pageCopy=array_column($archive['views'],'copySha256','width')[1440]??null;
    $identity=array('archiveId'=>$archive['id'],'mode'=>'recorded','status'=>'observed','version'=>1,'width'=>1440,
        'ownerRef'=>$owners[0],'adjacentRef'=>$owners[1],'overlayRef'=>$owners[2],'leadRef'=>$leadRef,
        'dismissTargetId'=>$dismissTargetId,'pinnedHeroSha256'=>$pinnedSha,'sourceCopySha256'=>$pageCopy,
        'blockedRequestCount'=>0,'top'=>$top);
    foreach($identity as $key=>$value){
        if(($verified[$key]??null)!==$value){
            $reason=$verified['reason']??'receipt_mismatch';
            return $fail(is_string($reason)&&preg_match('/^[a-z_]{1,64}$/D',$reason)?$reason:'receipt_mismatch');
        }
    }
    $images=array();
    foreach(array('hero'=>$hero,'pair'=>$hero+$intro) as $key=>$extent){
        $image=$verified[$key]['image']??null;
        $bytes=is_string($image)&&strlen($image)<=400000?base64_decode($image,true):false;
        $size=is_string($bytes)?@getimagesizefromstring($bytes):false;
        if(!$size||$size['mime']!=='image/jpeg'||!str_ends_with($bytes,"\xFF\xD9")||
            ($verified[$key]['extent']??null)!==$extent||
            abs($size[0]-1440*min(1,900/$extent))>2||abs($size[1]-min(900,$extent))>2||
            ($verified[$key]['sha256']??null)!==hash('sha256',$bytes)||
            $verified[$key]['sha256']===$pinnedSha)return $fail('invalid_settled_image');
        $images[$key]=array('image'=>$image,'sha256'=>$verified[$key]['sha256'],'extent'=>$extent);
    }
    return $identity+$images;
}

function syndamorph_scope_engine_source(array $source,string $url): array {
    $evidence=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width) {
        $view=$source['variants'][$width];
        $bounds=$view['bounds'];
        $evidence[]=array('width'=>$width,'sourceRef'=>$view['sourceRef'],'screenshot'=>$view['screenshot'],
            'bounds'=>array($bounds['x']??0,$bounds['y']??0,$bounds['width']??$width,$bounds['height']),
            'copy'=>$view['facts']['copy']??syndamorph_source_text(implode(' ',$view['inventory']['text'])),
            'controls'=>$view['inventory']['controls'],'links'=>$view['inventory']['links'],
            'images'=>$view['inventory']['assets'],'backgrounds'=>array(),
            'typography'=>$view['facts']['typography']??array(),'flow'=>$view['facts']['flow']??array(),
            'captureNotices'=>$view['notices']??array());
    }
    return array('url'=>$url,'manifestSha256'=>hash('sha256',wp_json_encode($source)),'scopeRegion'=>$source,
        'section'=>array('id'=>$source['id'],'label'=>$source['label'],'sourceRef'=>$source['sourceRef'],'kind'=>$source['kind'],
            'evidence'=>$evidence,'evidenceSha256'=>hash('sha256',wp_json_encode($evidence))));
}

function syndamorph_scope_inspect(string $html,string $extent,?array $sourceFrames=null) {
    $build=null;
    return syndamorph_collab_inspect($html,SYNDAMORPH_SOURCE_WIDTHS,$build,$extent,$sourceFrames);
}

/** Verify the ordinary sealed replay before using the paired raster profile. */
function syndamorph_scope_page_raster_source(array $manifest,array $views): array {
    $archive=$manifest['referenceArchive']??null;
    if(empty($archive['runtimeSha256'])||!syndamorph_production_reference_valid($archive,$manifest['sourceUrl']))
        return array('status'=>'pending','reason'=>'recorded_archive_unavailable');
    $profile=SYNDAMORPH_PAGE_RASTER_PROFILE;
    $digests=array_column($archive['views'],'copySha256','width');
    $frames=array('pinned'=>array('whole'=>array(),'fold'=>array()),'original'=>array('whole'=>array(),'fold'=>array()),
        'matched'=>array('whole'=>array(),'fold'=>array()));
    $hashes=array();
    $states=array();
    foreach($views as $view){
        $width=$view['width'];$height=$view['height']??null;
        $response=syndamorph_internal_request('backend','POST','/production-touch',array('timeout'=>45,
            'headers'=>array('Content-Type'=>'application/json','Accept-Encoding'=>'identity'),'limit_response_size'=>1604096,
            'body'=>wp_json_encode(array('kind'=>'full-page-raster','archiveId'=>$archive['id'],'width'=>$width))));
        $body=is_wp_error($response)?'':wp_remote_retrieve_body($response);
        if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200||strlen($body)>=1604096)
            return array('status'=>'pending','reason'=>'recorded_page_replay_unavailable','width'=>$width);
        $replay=json_decode($body,true,16);
        if(!is_int($height)||($replay['height']??null)!==$height||($replay['profile']??null)!==$profile||
            ($replay['width']??null)!==$width||($replay['archiveId']??null)!==$archive['id']||
            ($replay['copySha256']??null)!==($digests[$width]??null)||
            !is_string($replay['stateSha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$replay['stateSha256']))
            return array('status'=>'pending','reason'=>'recorded_page_replay_identity','width'=>$width);
        $states[$width]=$replay['stateSha256'];
        foreach(array('whole'=>$height,'fold'=>900) as $kind=>$extent){
            $images=array('pinned'=>$view[$kind],'original'=>$replay['original'][$kind]??null,'matched'=>$replay['matched'][$kind]??null);
            foreach($images as $role=>$image){
                if(!syndamorph_source_screenshot_valid($image,$width,$extent))
                    return array('status'=>'pending','reason'=>'recorded_page_replay_frame','width'=>$width);
                $frames[$role][$kind][]=array('width'=>$width,'extent'=>$extent,'screenshot'=>$image,
                    'snapshot'=>array('overflow'=>false));
                $hashes[$width][$kind][$role]=hash('sha256',base64_decode($image,true));
            }
        }
    }
    $witness=array(
        'fullExtent'=>syndamorph_source_visual($frames['pinned']['whole'],$frames['original']['whole']),
        'firstFold'=>syndamorph_source_visual($frames['pinned']['fold'],$frames['original']['fold'],false),
    );
    $receipt=array('profile'=>$profile,'archiveId'=>$archive['id'],'sourceFrameSha256'=>$hashes,'sourceStateSha256'=>$states,
        'sourceWitness'=>$witness);
    if(!$witness['fullExtent']['passed']||!$witness['firstFold']['passed'])
        return array('status'=>'pending','reason'=>'recorded_page_replay_drift')+$receipt;
    return array('status'=>'verified','frames'=>$frames['matched'])+$receipt;
}

function syndamorph_scope_page_raster_qa(array $manifest,array $source,string $html,string $build,array $whole,array $fold,array $layoutWidths): array {
    $receipt=syndamorph_scope_page_raster_source($manifest,$source);
    if($receipt['status']!=='verified')return $receipt;
    $frames=$receipt['frames'];
    unset($receipt['frames']);
    $profile=$receipt['profile'];
    $qa=array();
    $geometry=static fn(array $views)=>array_map(static fn($view)=>array(
        $view['snapshot']['overflow']??null,$view['snapshot']['literal']['links']??null,
        array_column($view['snapshot']['elements'],'rect')
    ),$views);
    foreach(array('fullExtent'=>array('page','whole',$whole),'firstFold'=>array('page-fold','fold',$fold)) as $key=>$settings){
        list($extent,$kind,$ordinary)=$settings;
        $currentBuild=null;
        $matched=syndamorph_collab_inspect($html,SYNDAMORPH_SOURCE_WIDTHS,$currentBuild,$extent,null,$profile);
        if(is_wp_error($matched))return array('status'=>'pending','reason'=>'native_page_replay_unavailable',
            'inspection'=>syndamorph_inspection_diagnostic($matched))+array_diff_key($receipt,array('status'=>true));
        if($build!==$currentBuild||array_column($ordinary,'extent')!==array_column($matched,'extent')||
            $geometry($ordinary)!==$geometry($matched)||
            syndamorph_collab_evidence($ordinary,$matched))
            return array('status'=>'pending','reason'=>'native_page_replay_identity')+array_diff_key($receipt,array('status'=>true));
        foreach($matched as $view)
            $receipt['nativeFrameSha256'][$view['width']][$kind]=hash('sha256',base64_decode($view['screenshot'],true));
        $qa[$key]=syndamorph_source_visual($frames[$kind],$matched,$kind==='whole',$layoutWidths);
    }
    return array('status'=>$qa['fullExtent']['passed']&&$qa['firstFold']['passed']?'passed':'failed',
        'rendererBuild'=>$build)+$qa+$receipt;
}

/** Compare both entire rendered extents and absolute heights at their original widths. */
function syndamorph_source_visual(array $reference,array $native,bool $extent=true,array $layoutWidths=array()): array {
    $comparison=syndamorph_collab_compare($reference,$native,$extent,$extent);
    if(is_wp_error($comparison))return array('passed'=>false,'inspection'=>array('code'=>$comparison->get_error_code(),
        'message'=>$comparison->get_error_message()));
    $passed=true;$strictRatios=array();
    foreach($comparison as $i=>&$row) {
        if($extent) {
            $sourceHeight=$reference[$i]['extent'];
            $height=$native[$i]['extent'];
            $row['sourceHeight']=$sourceHeight;
            $row['nativeHeight']=$height;
            $row['heightPassed']=abs($sourceHeight-$height)<=max(16,$sourceHeight*0.10);
            if(isset($native[$i]['sourceFrame']))$row['sourceFrame']=$native[$i]['sourceFrame'];
        }
        $ratio=$row['pixelDifferenceRatio']??null;
        $measured=is_numeric($ratio)&&is_finite((float)$ratio)&&$ratio>=0&&$ratio<=1&&
            ($native[$i]['snapshot']['overflow']??null)===false;
        $strict=$measured&&$ratio<=0.015&&($row['heightPassed']??true);
        $authorized=in_array($row['width'],array(768,375),true)&&in_array($row['width'],$layoutWidths,true);
        $row['sourcePixelAndHeightPassed']=$strict;
        $row['layoutDeviationAuthorized']=$authorized;
        $row['passed']=$authorized?$measured:$strict;
        if(!$authorized&&$measured)$strictRatios[]=(float)$ratio;
        $passed=$passed&&$row['passed'];
    }
    return array('passed'=>$passed&&syndamorph_visual_pixels_within_limits($strictRatios),'views'=>$comparison);
}

function syndamorph_source_verify_markup(array $source,array $evidence,?array $originalImages=null,array $revealEvidence=array()): array {
    $verdict=array();
    $profile=syndamorph_source_scroll_profile($source);
    $disclosure=syndamorph_source_disclosure_profile($source);
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $index=>$width) {
        $inventory=$source['variants'][$width]['inventory'];
        $literal=$evidence[$index]['snapshot']['literal']??array();
        $precise=$source['variants'][$width]['facts']['copy']??null;
        $expectedText=$precise??syndamorph_source_text(implode(' ',$inventory['text']));
        $actualText=$precise!==null?($evidence[$index]['snapshot']['copy']??null):syndamorph_source_text(implode(' ',$literal['text']??array()));
        $expectedLinks=$inventory['links'];
        $observedLinks=$literal['links']??null;
        $expectedStrips=array();
        if(isset($profile['copyByWidth'][$width]))$expectedStrips[]=array('id'=>$profile['id'],
            'cards'=>$profile['cards'],'copySha256'=>$profile['copyByWidth'][$width]);
        $expectedDisclosures=$disclosure===null?array():array(array('id'=>$disclosure['id'],
            'initial'=>$disclosure['initial'],'panels'=>$disclosure['panels']));
        $controlCount=$disclosure===null?0:count($disclosure['panels']);
        $controlsSupported=empty($inventory['controls'])||!empty($expectedStrips)||$disclosure!==null||($revealEvidence[$width]??false)===true;
        $assets=(empty($inventory['assets'])||$disclosure!==null)&&($literal['assets']??null)===0;
        if($originalImages!==null){
            $expected=$originalImages[$width]??null;
            $sourceImage=$inventory['assets'][0]??null;
            $sourceMatches=$expected===null?empty($inventory['assets']):
                count($inventory['assets'])===1&&($sourceImage['type']??null)==='image'&&
                ($sourceImage['alt']??null)===($expected['alt']??null);
            $assets=($evidence[$index]['width']??null)===$width&&array_key_exists($width,$originalImages)&&
                $sourceMatches&&($literal['assets']??null)===($expected===null?0:1)&&($literal['originalImage']??null)===$expected;
        }
        $view=array('width'=>$width,'literalContent'=>$actualText===$expectedText,
            'links'=>$observedLinks===$expectedLinks,
            'assets'=>$assets,
            'functions'=>$controlsSupported&&($literal['controls']??null)===$controlCount&&
                ((!$expectedStrips&&!$expectedDisclosures)||($evidence[$index]['width']??null)===$width)&&
                ($literal['scrollStrips']??array())===$expectedStrips&&
                ($literal['disclosures']??array())===$expectedDisclosures);
        if(!$view['literalContent'])
            $view['copyDifference']=syndamorph_source_copy_difference($expectedText,is_string($actualText)?$actualText:null);
        if($originalImages!==null&&!$assets)$view['imageDifference']=array('expected'=>$expected,
            'observed'=>$literal['originalImage']??null,'observedAssetCount'=>$literal['assets']??null);
        if(!$view['functions']&&$profile!==null)$view['scrollDifference']=array('expected'=>$expectedStrips,
            'observed'=>$literal['scrollStrips']??null);
        if(!$view['links']){
            $bounded=static fn(array $links): array => array_map(static fn($link)=>array(
                'href'=>mb_substr((string)($link['href']??''),0,180),'text'=>mb_substr((string)($link['text']??''),0,100)
            ),array_slice($links,0,5));
            $view['linkDifference']=array('expectedCount'=>count($expectedLinks),
                'observedCount'=>is_array($observedLinks)?count($observedLinks):null,'expected'=>$bounded($expectedLinks),
                'observed'=>is_array($observedLinks)?$bounded($observedLinks):null);
        }
        $verdict[]=$view;
    }
    return array('passed'=>!array_filter($verdict,static fn($row)=>in_array(false,$row,true)),'views'=>$verdict);
}

/** Describe exact measured copy disagreement without inferring it from clipped element summaries. */
function syndamorph_source_copy_difference(string $expected,?string $observed): array {
    if($observed===null)return array('kind'=>'missing_evidence');
    $a=$expected===''?array():explode(' ',$expected);
    $b=$observed===''?array():explode(' ',$observed);
    $aLength=count($a);
    $bLength=count($b);
    $start=0;
    while($start<min($aLength,$bLength)&&$a[$start]===$b[$start])$start++;
    $trailing=0;
    while($trailing<min($aLength,$bLength)-$start&&$a[$aLength-$trailing-1]===$b[$bLength-$trailing-1])
        $trailing++;
    $caseOnly=$aLength===$bLength&&mb_strtolower($expected,'UTF-8')===mb_strtolower($observed,'UTF-8');
    if($caseOnly){
        $differences=array();$count=0;
        for($i=$start;$i<$aLength-$trailing;$i++){
            if($a[$i]===$b[$i])continue;
            $count++;
            if(count($differences)<6)$differences[]=array('wordIndex'=>$i,'expected'=>mb_substr($a[$i],0,80),'observed'=>mb_substr($b[$i],0,80));
        }
        return array('kind'=>'case_only','changedWordCount'=>$count,'caseDifferences'=>$differences);
    }
    $excerpt=static fn(array $words,int $length): string =>
        mb_substr(implode(' ',array_slice($words,$start,min(8,$length-$start-$trailing))),0,220);
    return array('kind'=>'literal_mismatch','wordIndex'=>$start,
        'expected'=>$excerpt($a,$aLength),'observed'=>$excerpt($b,$bLength),
        'expectedChangedWords'=>$aLength-$start-$trailing,'observedChangedWords'=>$bLength-$start-$trailing);
}

function syndamorph_source_review(array $source,string $html,?array $authorization=null): array {
    $origins=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $bounds=$source['variants'][$width]['bounds']??array();
        if(array_key_exists('y',$bounds))$origins[$width]=$bounds['y'];
    }
    $native=syndamorph_scope_inspect($html,'section',$origins?:null);
    $markup=syndamorph_source_verify_markup($source,is_wp_error($native)?array():$native);
    $reference=array_map(static function($width)use($source){
        $view=$source['variants'][$width];
        return array('width'=>$width,'extent'=>(int)round($view['bounds']['height']),'screenshot'=>$view['screenshot']);
    },SYNDAMORPH_SOURCE_WIDTHS);
    $layoutWidths=$authorization['widths']??array();
    $layoutIdentity=array();
    foreach(is_wp_error($native)?array():$native as $sample){
        $width=$sample['width'];
        if(!in_array($width,$layoutWidths,true))continue;
        $expected=$source['variants'][$width]['facts']['layoutIdentity']??null;
        $observed=$sample['snapshot']['literal']['layoutIdentity']??null;
        $layoutIdentity[]=array('width'=>$width,'matched'=>is_array($expected)&&$expected===$observed);
    }
    $layoutIdentityPassed=array_column($layoutIdentity,'width')===$layoutWidths&&
        !in_array(false,array_column($layoutIdentity,'matched'),true);
    $visual=is_wp_error($native)?array('passed'=>false,'inspection'=>syndamorph_inspection_diagnostic($native)):
        syndamorph_source_visual($reference,$native,true,$layoutIdentityPassed?$layoutWidths:array());
    return array('passed'=>$markup['passed']&&$visual['passed']&&$layoutIdentityPassed,'markup'=>$markup,'visual'=>$visual,
        'sourceId'=>$source['id'],'sourceSha256'=>hash('sha256',wp_json_encode($source)))+
        ($authorization===null?array():array('layoutAuthorization'=>$authorization,'layoutIdentity'=>$layoutIdentity));
}

function syndamorph_source_issue(array $review,string $owner): array {
    return array('id'=>'captured-source-fidelity','component'=>$owner,'category'=>'source','scope'=>'section',
        'severity'=>'high','critical'=>true,'source'=>'browser','scoreGroup'=>'source|'.$owner,
        'detail'=>'The native section did not pass the required pinned source content and viewport comparisons.');
}

/** Rank verified repairs without altering the source acceptance policy. */
function syndamorph_source_surgery_comparison(array $before,array $after): array {
    $result=array('views'=>array(),'regressions'=>array(),'improved'=>false);
    if(!hash_equals((string)($before['sourceSha256']??''),(string)($after['sourceSha256']??''))||
        empty($before['sourceSha256'])||($before['layoutAuthorization']??null)!==($after['layoutAuthorization']??null)){
        $result['regressions'][]=array('code'=>'source-identity-changed','target'=>'section');
        return $result;
    }
    $views=array_column($before['visual']['views']??array(),null,'width');
    $candidate=array_column($after['visual']['views']??array(),null,'width');
    $markupBefore=array_column($before['markup']['views']??array(),null,'width');
    $markupAfter=array_column($after['markup']['views']??array(),null,'width');
    $identityBefore=array_column($before['layoutIdentity']??array(),'matched','width');
    $identityAfter=array_column($after['layoutIdentity']??array(),'matched','width');
    $delta=0.0;
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $previous=$views[$width]??array();
        $next=$candidate[$width]??array();
        $a=$previous['pixelDifferenceRatio']??null;
        $b=$next['pixelDifferenceRatio']??null;
        if(!is_numeric($a)||!is_numeric($b)||!is_finite((float)$a)||!is_finite((float)$b)||
            !isset($markupBefore[$width],$markupAfter[$width])){
            $result['regressions'][]=array('code'=>'source-width-evidence-lost','width'=>$width,'target'=>'section');
            continue;
        }
        $result['views'][]=array('width'=>$width,'pixelBefore'=>$a,'pixelAfter'=>$b,
            'heightBefore'=>$previous['nativeHeight']??null,'heightAfter'=>$next['nativeHeight']??null);
        if(($previous['passed']??false)&&!($next['passed']??false))
            $result['regressions'][]=array('code'=>'source-visual-gate-regressed','width'=>$width,'target'=>'section');
        elseif($b>$a&&empty($previous['layoutDeviationAuthorized']))
            $result['regressions'][]=array('code'=>'source-pixel-difference-increased','width'=>$width,'target'=>'section');
        if(($previous['heightPassed']??null)===true&&($next['heightPassed']??null)!==true)
            $result['regressions'][]=array('code'=>'source-height-gate-regressed','width'=>$width,'target'=>'section');
        if(($identityBefore[$width]??null)===true&&($identityAfter[$width]??null)!==true)
            $result['regressions'][]=array('code'=>'source-layout-identity-regressed','width'=>$width,'target'=>'section');
        foreach(array('literalContent','links','assets','functions') as $gate){
            $old=$markupBefore[$width][$gate]??null;
            $new=$markupAfter[$width][$gate]??null;
            if($old===true&&$new!==true)$result['regressions'][]=array(
                'code'=>'source-markup-gate-regressed','width'=>$width,'target'=>$gate);
            if($old===false&&$new===true)$result['improved']=true;
        }
        if(empty($previous['layoutDeviationAuthorized']))$delta+=$b-$a;
    }
    if($delta<0||(!empty($after['passed'])&&empty($before['passed'])))$result['improved']=true;
    if(empty($before['passed'])&&empty($after['passed'])&&!$result['improved'])
        $result['regressions'][]=array('code'=>'source-surgery-no-improvement','target'=>'section');
    return $result;
}
