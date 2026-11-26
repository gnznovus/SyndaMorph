<?php
/** Compact browser facts and application-owned work-order checks. */
if (!defined('ABSPATH')) exit;

function syndamorph_design_check_contract($check) {
    $fields=array('fontFamily','fontSize','fontWeight','lineHeight','backgroundColor','backgroundImage','color','textAlign',
        'paddingTop','paddingBottom');
    $kind=$check['kind']??null;
    if(!is_array($check)||!in_array($kind,array('text','element','link','style','center','element-absent'),true))return null;
    $result=array('kind'=>$kind);
    if($kind!=='text'||array_key_exists('selector',$check)){
        $selector=$check['selector']??null;
        if(!is_string($selector)||preg_match('/^(?:[.#][a-zA-Z][a-zA-Z0-9_-]{0,62}|[a-z][a-z0-9-]{0,31})$/D',$selector)!==1)return null;
        $result['selector']=$selector;
    }
    if(!in_array($kind,array('element','center','element-absent'),true)){
        $value=$check['value']??null;
        if(!is_string($value)||trim($value)===''||mb_strlen($value)>($kind==='text'?1000:240))return null;
        $result['value']=trim($value);
    }
    if($kind==='style'){
        $field=$check['field']??null;
        if(!in_array($field,$fields,true))return null;
        $result['field']=$field;
        if(array_key_exists('literal',$check)){
            $literal=$check['literal'];
            if(!is_string($literal)||trim($literal)===''||mb_strlen($literal)>240)return null;
            $result['literal']=trim($literal);
        }
    }
    if(isset($check['widths'])){
        $widths=$check['widths'];
        if(!is_array($widths)||!array_is_list($widths)||!$widths||count($widths)>8||
            count(array_filter($widths,static fn($width)=>is_int($width)&&$width>=320&&$width<=1920))!==count($widths))
            return null;
        $result['widths']=array_values(array_unique($widths));
    }
    return $result;
}

function syndamorph_design_style_value(array $styles,string $field): ?string {
    if(isset($styles[$field]))return $styles[$field];
    if(!in_array($field,array('paddingTop','paddingBottom'),true)||!is_string($styles['padding']??null))return null;
    $sides=preg_split('/\s+/',trim($styles['padding']));
    if(!is_array($sides)||count($sides)>4||array_filter($sides,static fn($side)=>!preg_match('/^\d+(?:\.\d+)?px$/D',$side)))
        return null;
    return $field==='paddingBottom'?($sides[2]??$sides[0]):$sides[0];
}

function syndamorph_design_matches(array $element,string $selector): bool {
    if(($element['visible']??null)!==true)return false;
    if($selector[0]==='#')return ($element['id']??null)===substr($selector,1);
    if($selector[0]==='.')return in_array(substr($selector,1),(array)($element['classes']??array()),true);
    return strtolower((string)($element['tag']??''))===$selector;
}

function syndamorph_design_computed_value(string $field,string $value): string {
    if(in_array($field,array('color','backgroundColor'),true)){
        if(preg_match('/^#([a-f0-9]{3}|[a-f0-9]{6})$/iD',$value,$match)){
            $hex=strlen($match[1])===3?preg_replace('/./','$0$0',$match[1]):$match[1];
            return 'rgb('.implode(', ',array_map('hexdec',str_split($hex,2))).')';
        }
        if(preg_match('/^rgb\(\s*(\d+)\s*[, ]\s*(\d+)\s*[, ]\s*(\d+)\s*\)$/iD',$value,$match))
            return 'rgb('.implode(', ',array_slice($match,1)).')';
    }
    return $value;
}

function syndamorph_design_browser_facts(array $samples,array $specification): array {
    $selectors=array_values(array_filter(array_map(static fn($item)=>$item['check']['selector']??null,
        $specification['requirements'])));
    $result=array();
    foreach($samples as $sample){
        $width=$sample['width'];
        $snapshot=$sample['snapshot'];
        $projected=array();
        foreach($snapshot['elements']??array() as $index=>$element){
            $style=$element['styles']??array();
            $selected=array_filter($selectors,static fn($selector)=>syndamorph_design_matches($element,$selector));
            $control=in_array($element['tag']??null,array('A','BUTTON','INPUT','SELECT'),true)||!empty($element['role']);
            $paint=($style['backgroundImage']??'none')!=='none'||
                !in_array($style['backgroundColor']??'',array('','rgba(0, 0, 0, 0)','transparent'),true);
            if($index!==0&&!$selected&&!$control&&!$paint&&empty($element['normalizedText']))continue;
            $record=array('id'=>'view-'.$width.'-element-'.$index)+array_intersect_key($element,array_flip(
                array('tag','classes','rect','visible','role','name','href','fragmentTarget','keyboardReachable','clipped')));
            $record['domId']=$element['id']??null;
            $record['text']=mb_substr((string)($element['normalizedText']??''),0,100);
            $record['styles']=array_intersect_key($style,array_flip(
                array('fontFamily','fontSize','fontWeight','lineHeight','color','backgroundColor','backgroundImage','textAlign','padding')));
            $projected[]=array('priority'=>$selected?0:($control?1:($index===0||$paint?2:3)),'row'=>$record);
        }
        usort($projected,static fn($a,$b)=>$a['priority']<=>$b['priority']);
        $a11y=array();
        foreach(array_slice($sample['a11y']??array(),0,8) as $issue){
            $a11y[]=array_intersect_key($issue,array_flip(array('id','impact','nodes')));
        }
        $result[]=array('id'=>'view-'.$width,'width'=>$width,'overflow'=>$snapshot['overflow']??null,
            'copySha256'=>is_string($snapshot['copy']??null)?hash('sha256',$snapshot['copy']):null,
            'elements'=>array_column(array_slice($projected,0,28),'row'),'accessibility'=>$a11y);
    }
    return $result;
}

/** Inspect the configured Page target. The eventual draft still needs its own QA. */
function syndamorph_design_wordpress_page_context(): ?array {
    if(!function_exists('get_block_template')||!function_exists('wp_get_global_styles'))return null;
    $theme=get_stylesheet();
    $template=get_block_template($theme.'//page','wp_template');
    if(!is_string($template->content??null)||$template->content==='')return null;
    $blocks=array();
    $walk=static function(array $rows)use(&$walk,&$blocks):void {
        foreach($rows as $row){
            if(in_array($row['blockName'],array('core/post-title','core/post-content'),true))$blocks[]=$row;
            $walk($row['innerBlocks']);
        }
    };
    $walk(parse_blocks($template->content));
    $level=$blocks[0]['attrs']['level']??null;
    $background=wp_get_global_styles(array('color','background'),array('transforms'=>array('resolve-variables')));
    if(array_column($blocks,'blockName')!==array('core/post-title','core/post-content')||!is_int($level)||
        $level<1||$level>6||!is_string($background)||!preg_match('/^#[a-f0-9]{6}$/iD',$background))return null;
    return array('id'=>'wordpress-page-template','theme'=>$theme,'template'=>$template->slug,
        'templateSha256'=>hash('sha256',$template->content),'pageTitleLevel'=>$level,'globalBackground'=>$background,
        'scope'=>'Default local WordPress Page template settings. Verify the final draft in its actual insertion context.');
}

function syndamorph_design_run_check(array $check,array $samples): array {
    $widths=$check['widths']??array_column($samples,'width');
    $views=array_column($samples,null,'width');
    $status='verified';
    $evidence=array();
    foreach($widths as $width){
        $snapshot=$views[$width]['snapshot']??null;
        $outcome='uncertain';
        if($check['kind']==='text'&&!isset($check['selector'])&&is_string($snapshot['copy']??null)){
            $outcome=str_contains($snapshot['copy'],$check['value'])?'verified':'failed';
        }elseif(isset($check['selector'])){
            $elements=$snapshot['elements']??array();
            if($elements&&array_key_exists('visible',$elements[0])){
                $matching=array_values(array_filter($elements,
                    static fn($element)=>syndamorph_design_matches($element,$check['selector'])));
                $complete=($snapshot['elementCount']??count($elements))===count($elements);
                if($check['kind']==='text'){
                    $outcome=$complete?'failed':'uncertain';
                    foreach($matching as $element){
                        $text=$element['checkText']??$element['normalizedText']??null;
                        if(is_string($text)&&str_contains($text,$check['value'])){
                            $outcome='verified';break;
                        }
                        if(!is_string($text)||($element['checkTextTruncated']??($text===''||strlen($text)>=250)))
                            $outcome='uncertain';
                    }
                }elseif($check['kind']==='element')$outcome=$matching?'verified':'failed';
                elseif($check['kind']==='element-absent')$outcome=$matching?'failed':($complete?'verified':'uncertain');
                elseif($check['kind']==='link'){
                    $verified=array_filter($matching,static fn($element)=>($element['tag']??null)==='A'&&
                        ($element['href']??null)===$check['value']&&!empty($element['keyboardReachable'])&&
                        (!str_starts_with($check['value'],'#')||($element['fragmentTarget']??null)===true));
                    $outcome=$verified?'verified':'failed';
                }elseif($check['kind']==='center'){
                    $outcome=$matching?'verified':'failed';
                    foreach($matching as $element){
                        $rect=$element['rect']??null;
                        if(!is_array($rect)||count($rect)!==4){
                            if($outcome!=='failed')$outcome='uncertain';
                        }elseif(abs($rect[0]+$rect[2]/2-$width/2)>2)$outcome='failed';
                    }
                }else{
                    $expected=syndamorph_design_computed_value($check['field'],$check['value']);
                    if(isset($check['literal'])){
                        $matching=array_values(array_filter($matching,static fn($element)=>
                            ($element['checkText']??$element['normalizedText']??null)===$check['literal']));
                        $outcome=!$complete?'uncertain':(count($matching)===1?
                            (syndamorph_design_style_value($matching[0]['styles']??array(),$check['field'])===$expected?'verified':'failed'):'failed');
                    }else $outcome=$matching&&count(array_filter($matching,
                        static fn($element)=>syndamorph_design_style_value($element['styles']??array(),$check['field'])===$expected))===count($matching)
                        ?'verified':'failed';
                }
                if(!$complete&&$outcome==='failed'&&!$matching)$outcome='uncertain';
            }
        }
        $evidence[]=array('id'=>'view-'.$width,'status'=>$outcome);
        if($outcome==='failed'||($outcome==='uncertain'&&$status!=='failed'))$status=$outcome;
    }
    return array('status'=>$widths?$status:'uncertain','evidence'=>$evidence,'owner'=>'browser');
}

function syndamorph_design_requirements(array $specification,array $samples,array $facts,array $approval,
    array $extraEvidence): array {
    if($specification['format']!=='structured')return array('checks'=>array(),'blocking'=>array());
    $reported=array();
    foreach((array)($approval['requirementChecks']??array()) as $row){
        if(!is_array($row)||!is_string($row['id']??null))continue;
        $reported[$row['id']]=array_key_exists($row['id'],$reported)?null:$row;
    }
    $available=array_fill_keys($extraEvidence,true);
    foreach($facts as $view){
        $available[$view['id']]=true;
        foreach($view['elements'] as $element)$available[$element['id']]=true;
    }
    $checks=array();
    $blocking=array();
    $implementationProof=array();
    foreach($specification['interactions']??array() as $interaction)
        foreach($interaction['proof']??array() as $proof)$implementationProof[$proof['requirementId']]=$proof['check'];
    foreach($specification['requirements'] as $requirement){
        $id=$requirement['id'];
        $result=array('id'=>$id,'dimension'=>$requirement['dimension'],'owner'=>'boss',
            'status'=>'uncertain','evidence'=>array());
        if(isset($requirement['check'])){
            $result=array_replace($result,syndamorph_design_run_check($requirement['check'],$samples));
        }elseif(isset($implementationProof[$id])||in_array($requirement['dimension'],array('functional','implementation'),true)){
            $result['owner']='implementation_qa';
            $result['reason']='Requires independent implementation evidence.';
            if(isset($implementationProof[$id]))$result['check']=$implementationProof[$id];
            $checks[]=$result;
            continue;
        }else{
            $claim=$reported[$id]??array();
            $state=$claim['status']??null;
            $evidence=array_values(array_filter((array)($claim['evidence']??array()),
                static fn($key)=>is_string($key)&&isset($available[$key])));
            if($state==='not_applicable'&&$requirement['basis']==='standard'&&
                is_string($claim['reason']??null)&&trim($claim['reason'])!==''){
                $result['status']=$state;
                $result['reason']=mb_substr(trim($claim['reason']),0,160);
            }elseif(in_array($state,array('verified','failed'),true)&&$evidence){
                $result['status']=$state;
                $result['evidence']=array_slice(array_unique($evidence),0,6);
            }
        }
        if(in_array($result['status'],array('failed','uncertain'),true))$blocking[]=$id;
        $checks[]=$result;
    }
    return array('checks'=>$checks,'blocking'=>$blocking);
}
