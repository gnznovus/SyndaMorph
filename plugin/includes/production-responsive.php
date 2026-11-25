<?php
/** Deterministic viewport planning and responsive findings for production artifacts. */
if (!defined('ABSPATH')) exit;

function syndamorph_design_probe_widths(string $html,array $mandatory) {
    $minimum=375;
    $maximum=1440;
    $widths=array_merge($mandatory,array(450,600,900,1200,1350));
    preg_match_all('~<style\b[^>]*>(.*?)</style>~is',$html,$styles);
    foreach($styles[1] as $style){
        preg_match_all('~@media\s+([^\{]+)\{~i',preg_replace('~/\*.*?\*/~s','',$style),$queries);
        foreach($queries[1] as $query){
            if(!preg_match('~\b(?:min-width|max-width|width)\b~i',$query))continue;
            preg_match_all('~(?<![\d.])(\d+(?:\.\d+)?)(px|em|rem)\b~i',$query,$sizes,PREG_SET_ORDER);
            foreach($sizes as $size){
                $boundary=(float)$size[1]*(strtolower($size[2])==='px'?1:16);
                foreach(array((int)floor($boundary)-1,(int)floor($boundary),
                    (int)ceil($boundary),(int)ceil($boundary)+1) as $width){
                    if($width>=$minimum&&$width<=$maximum)$widths[]=$width;
                }
            }
        }
    }
    $widths=array_values(array_unique($widths));
    rsort($widths,SORT_NUMERIC);
    if(count($widths)>32)return new WP_Error('sm_responsive_complexity',
        'The artifact has more breakpoint boundaries than the browser can verify in this stage.',
        array('expectedWidths'=>$widths,'maximumSamples'=>32));
    return $widths;
}

/**
 * Use measured text boxes. Decorative or hidden elements are not proof of
 * text clipping, and intersecting wrapper rectangles alone are not collisions.
 */
function syndamorph_design_responsive_findings(array $samples): array {
    $findings=array();
    foreach($samples as $sample){
        $width=$sample['width'];
        $snapshot=$sample['snapshot'];
        if(($snapshot['overflow']??null)===true)
            $findings[]=array('width'=>$width,'code'=>'horizontal-overflow','target'=>'section');
        $leaves=array();
        foreach($snapshot['elements']??array() as $element){
            if(!in_array($element['tag']??'',array('H1','H2','H3','H4','H5','H6','P','A','BUTTON','LABEL'),true))continue;
            $text=trim((string)($element['normalizedText']??$element['text']??''));
            $rect=$element['textRect']??null;
            if($text===''||!is_array($rect)||count($rect)!==4||$rect[2]<=0||$rect[3]<=0)continue;
            $target=mb_substr($text,0,80);
            if($rect[0]<-2||$rect[0]+$rect[2]>$width+2||!empty($element['clipped']))
                $findings[]=array('width'=>$width,'code'=>'text-clipping','target'=>$target);
            $leaves[]=array('target'=>$target,'parent'=>$element['parent']??null,'lines'=>$element['lineRects']??array());
        }
        foreach($leaves as $index=>$first){
            if($first['parent']===null)continue;
            foreach(array_slice($leaves,$index+1) as $second){
                if($second['parent']!==$first['parent'])continue;
                foreach($first['lines'] as $a){
                    foreach($second['lines'] as $b){
                        if(count($a)!==4||count($b)!==4)continue;
                        $x=min($a[0]+$a[2],$b[0]+$b[2])-max($a[0],$b[0]);
                        $y=min($a[1]+$a[3],$b[1]+$b[3])-max($a[1],$b[1]);
                        if($x>2&&$y>2&&$x*$y>0.1*min($a[2]*$a[3],$b[2]*$b[3])){
                            $findings[]=array('width'=>$width,'code'=>'text-collision',
                                'target'=>$first['target'].' / '.$second['target']);
                            break 2;
                        }
                    }
                }
            }
        }
    }
    return $findings;
}

function syndamorph_design_responsive_measure(string $html,array $mandatory,array $baseline,string $rendererBuild) {
    if(array_column($baseline,'width')!==$mandatory||array_filter($baseline,static fn($view)=>empty($view['snapshot']['elements'])))
        return syndamorph_inspection_failure('snapshot_incomplete',array('expectedWidths'=>$mandatory));
    $widths=syndamorph_design_probe_widths($html,$mandatory);
    if(is_wp_error($widths))return $widths;
    $samples=$baseline;
    foreach(array_chunk(array_values(array_diff($widths,$mandatory)),5) as $batch){
        $extra=syndamorph_collab_inspect($html,$batch,$build);
        if(is_wp_error($extra))return $extra;
        if($build!==$rendererBuild)return syndamorph_inspection_failure('protocol_mismatch',
            array('expectedWidths'=>$widths,'rendererBuild'=>$build,'previousRendererBuild'=>$rendererBuild));
        foreach($extra as $view){
            if(empty($view['snapshot']['elements']))return syndamorph_inspection_failure('snapshot_incomplete',
                array('expectedWidths'=>$widths,'viewport'=>$view['width']));
            $samples[]=$view;
        }
    }
    $findings=syndamorph_design_responsive_findings($samples);
    $evidence=array();
    foreach($samples as $sample)$evidence[$sample['width']]=hash('sha256',base64_decode($sample['screenshot'],true));
    krsort($evidence,SORT_NUMERIC);
    return array('widths'=>$widths,'findings'=>$findings,'passed'=>!$findings,'screenshotSha256ByWidth'=>$evidence);
}

/** The same browser checks are run afresh on the retained native artifact. */
function syndamorph_native_responsive_receipt(string $html,array $widths,array $baseline,string $build,string $checksum): array {
    $result=syndamorph_design_responsive_measure($html,$widths,$baseline,$build);
    return array('artifactSha256'=>$checksum,'previewSha256'=>hash('sha256',$html))+
        (is_wp_error($result)?array('status'=>'pending','inspection'=>syndamorph_inspection_diagnostic($result)):
            array('status'=>$result['passed']?'passed':'failed')+$result);
}

function syndamorph_native_responsive_recheck(array $final) {
    $old=$final['quality']['continuousResponsive']??null;
    $artifact=$final['artifact']??array();
    $widths=$final['quality']['viewportWidths']??array();
    $content=$artifact['content']??null;
    $preview=$artifact['preview']??null;
    if(($old['status']??null)!=='pending'||!is_string($content)||!is_string($preview)||!$widths||
        ($old['artifactSha256']??null)!==hash('sha256',$content)||($old['previewSha256']??null)!==hash('sha256',$preview))
        return new WP_Error('sm_responsive_checkpoint','The native responsive inspection checkpoint is unavailable.',array('status'=>409));
    $ready=syndamorph_inspection_ready($widths);
    if(is_wp_error($ready))return $ready;
    $build=null;
    $baseline=syndamorph_collab_inspect($preview,$widths,$build);
    if(is_wp_error($baseline))return $baseline;
    if($ready['build']!==$build)
        return syndamorph_inspection_failure('protocol_mismatch',array('expectedWidths'=>$widths,'rendererBuild'=>$build));
    return syndamorph_native_responsive_receipt($preview,$widths,$baseline,$build,$old['artifactSha256']);
}

function syndamorph_native_responsive_acceptance(array $final,string $checksum): bool {
    $receipt=$final['quality']['continuousResponsive']??null;
    return $receipt===null||(($receipt['status']??null)==='passed'&&($receipt['artifactSha256']??null)===$checksum);
}

function syndamorph_design_responsive_regressions(array $prior,array $candidate): array {
    $key=static fn(array $finding): string => implode('|',array($finding['width'],$finding['code'],$finding['target']));
    $known=array_fill_keys(array_map($key,$prior['findings']),true);
    return array_values(array_filter($candidate['findings'],static fn($finding)=>!isset($known[$key($finding)])));
}

function syndamorph_design_element_ids(array $samples): array {
    $identities=array();
    foreach($samples as $sample){
        foreach($sample['snapshot']['elements']??array() as $element){
            if(is_string($element['id']??null)&&$element['id']!==''){
                $identity=$element['id'];
                $identities[$identity]=$identity;
            }
        }
    }
    return array_values($identities);
}
