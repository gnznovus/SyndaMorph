<?php
/** Compact browser facts and application-owned work-order checks. */
if (!defined('ABSPATH')) exit;

function syndamorph_design_check_contract($check) {
    $fields=array('fontFamily','fontSize','fontWeight','lineHeight','backgroundColor','backgroundImage','color','textAlign');
    $kind=$check['kind']??null;
    if(!is_array($check)||!in_array($kind,array('text','element','link','style'),true))return null;
    $result=array('kind'=>$kind);
    if($kind!=='text'){
        $selector=$check['selector']??null;
        if(!is_string($selector)||preg_match('/^(?:[.#][a-zA-Z][a-zA-Z0-9_-]{0,62}|[a-z][a-z0-9-]{0,31})$/D',$selector)!==1)return null;
        $result['selector']=$selector;
    }
    if($kind!=='element'){
        $value=$check['value']??null;
        if(!is_string($value)||trim($value)===''||mb_strlen($value)>240)return null;
        $result['value']=trim($value);
    }
    if($kind==='style'){
        $field=$check['field']??null;
        if(!in_array($field,$fields,true))return null;
        $result['field']=$field;
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
                array('fontFamily','fontSize','fontWeight','color','backgroundColor','backgroundImage','textAlign')));
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

function syndamorph_design_run_check(array $check,array $samples): array {
    $widths=$check['widths']??array_column($samples,'width');
    $views=array_column($samples,null,'width');
    $status='verified';
    $evidence=array();
    foreach($widths as $width){
        $snapshot=$views[$width]['snapshot']??null;
        $outcome='uncertain';
        if($check['kind']==='text'&&is_string($snapshot['copy']??null)){
            $outcome=str_contains($snapshot['copy'],$check['value'])?'verified':'failed';
        }elseif($check['kind']!=='text'){
            $elements=$snapshot['elements']??array();
            if($elements&&array_key_exists('visible',$elements[0])){
                $matching=array_values(array_filter($elements,
                    static fn($element)=>syndamorph_design_matches($element,$check['selector'])));
                $complete=($snapshot['elementCount']??count($elements))===count($elements);
                if($check['kind']==='element')$outcome=$matching?'verified':'failed';
                elseif($check['kind']==='link'){
                    $verified=array_filter($matching,static fn($element)=>($element['tag']??null)==='A'&&
                        ($element['href']??null)===$check['value']&&!empty($element['keyboardReachable'])&&
                        (!str_starts_with($check['value'],'#')||($element['fragmentTarget']??null)===true));
                    $outcome=$verified?'verified':'failed';
                }else{
                    $expected=syndamorph_design_computed_value($check['field'],$check['value']);
                    $outcome=$matching&&count(array_filter($matching,
                        static fn($element)=>($element['styles'][$check['field']]??null)===$expected))===count($matching)
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
    foreach($specification['requirements'] as $requirement){
        $id=$requirement['id'];
        $result=array('id'=>$id,'dimension'=>$requirement['dimension'],'owner'=>'boss',
            'status'=>'uncertain','evidence'=>array());
        if(isset($requirement['check'])){
            $result=array_replace($result,syndamorph_design_run_check($requirement['check'],$samples));
        }elseif(in_array($requirement['dimension'],array('functional','implementation'),true)){
            $result['owner']='implementation_qa';
            $result['reason']='Requires independent implementation evidence.';
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
