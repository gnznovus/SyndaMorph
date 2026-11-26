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

function syndamorph_source_blockers(array $region): array {
    $blockers=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width) {
        $view=$region['variants'][$width]??null;
        if(!is_array($view) || empty($view['html']) || !is_array($view['inventory']??null) ||
            !syndamorph_source_screenshot_valid($view['screenshot']??null,$width,(int)round($view['bounds']['height']??0))) {
            $blockers[]="No proven source variant and browser evidence at {$width}px.";
            continue;
        }
        $inventory=$view['inventory'];
        if(!empty($inventory['controls'])) {
            $types=array_values(array_unique(array_column($inventory['controls'],'type')));
            $blockers[]="{$width}px source actions need a native implementation and functional tests (".implode(', ',$types).').';
        }
        if(!empty($inventory['assets'])) {
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

function syndamorph_scope_inspect(string $html,string $extent) {
    $build=null;
    return syndamorph_collab_inspect($html,SYNDAMORPH_SOURCE_WIDTHS,$build,$extent);
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
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $index=>$width) {
        $inventory=$source['variants'][$width]['inventory'];
        $literal=$evidence[$index]['snapshot']['literal']??array();
        $precise=$source['variants'][$width]['facts']['copy']??null;
        $expectedText=$precise??syndamorph_source_text(implode(' ',$inventory['text']));
        $actualText=$precise!==null?($evidence[$index]['snapshot']['copy']??null):syndamorph_source_text(implode(' ',$literal['text']??array()));
        $expectedLinks=$inventory['links'];
        $observedLinks=$literal['links']??null;
        $view=array('width'=>$width,'literalContent'=>$actualText===$expectedText,
            'links'=>$observedLinks===$expectedLinks,
            'assets'=>empty($inventory['assets'])&&($literal['assets']??null)===0,
            'functions'=>empty($inventory['controls'])&&($literal['controls']??null)===0);
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
    $native=syndamorph_scope_inspect($html,'section');
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
