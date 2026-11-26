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
    $token=getenv('SYNDAMORPH_BACKEND_TOKEN');
    if(getenv('SYNDAMORPH_BACKEND_URL')!=='http://backend:3100/capture'||!is_string($token)||$token==='')
        return new WP_Error('sm_disclosure_source','Recorded source replay is unavailable.');
    $receipt=array('archiveId'=>$archive['id'],'sourceSha256'=>hash('sha256',wp_json_encode($source)),'views'=>array());
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $ref=$source['variants'][$width]['sourceRef']??'';
        if(!preg_match('/^(?:[a-z][a-z0-9-]{0,20})?\[(?:data-dc-tpl|id)="[a-zA-Z0-9_-]{1,64}"\]$/D',$ref))
            return new WP_Error('sm_disclosure_source','The exact disclosure region identity is unsupported.',array('width'=>$width));
        $response=wp_remote_post('http://backend:3100/production-touch',array('timeout'=>45,'redirection'=>0,
            'limit_response_size'=>64000,'headers'=>array('Content-Type'=>'application/json','X-SyndaMorph-Token'=>$token),
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

function syndamorph_source_verify_markup(array $source,array $evidence): array {
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
        $controlsSupported=empty($inventory['controls'])||!empty($expectedStrips)||$disclosure!==null;
        $view=array('width'=>$width,'literalContent'=>$actualText===$expectedText,
            'links'=>$observedLinks===$expectedLinks,
            'assets'=>(empty($inventory['assets'])||$disclosure!==null)&&($literal['assets']??null)===0,
            'functions'=>$controlsSupported&&($literal['controls']??null)===$controlCount&&
                ((!$expectedStrips&&!$expectedDisclosures)||($evidence[$index]['width']??null)===$width)&&
                ($literal['scrollStrips']??array())===$expectedStrips&&
                ($literal['disclosures']??array())===$expectedDisclosures);
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
