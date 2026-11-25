<?php
/** Versioned, browser-evidence-aligned work order shared by the production roles. */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/production-design-evidence.php';

function syndamorph_production_specification(array $data) {
    $invalid = static fn(string $field): WP_Error => new WP_Error(
        'sm_boss_contract', 'Invalid production specification: '.$field.'.', array('field'=>$field));
    $text = static fn($value,int $limit): bool =>
        is_string($value) && trim($value)!=='' && mb_strlen($value)<=$limit;
    $id = static fn($value): bool => is_string($value) && preg_match('/^[a-z][a-z0-9-]{0,47}$/D',$value)===1;
    $list = static fn($value,int $limit): bool =>
        is_array($value) && array_is_list($value) && count($value)>0 && count($value)<=$limit;

    if(($data['version']??null)!==1 || !$text($data['title']??null,100))return $invalid('version/title');
    if(!$list($data['sections']??null,12))return $invalid('sections');
    if(!$list($data['requirements']??null,24))return $invalid('requirements');

    $sections = array();
    foreach($data['sections'] as $section) {
        if(!is_array($section) || !$id($section['id']??null) || !$text($section['label']??null,100))
            return $invalid('sections');
        $key=$section['id'];
        $parent=$section['parent']??null;
        if($key==='page' || isset($sections[$key]) ||
            ($parent!==null && (!$id($parent) || !isset($sections[$parent]))))
            return $invalid('section identities and parent order');
        $sections[$key]=array('id'=>$key,'label'=>trim($section['label']),'parent'=>$parent);
    }

    $dimensions=array('content','visual','responsive','functional','implementation','accessibility');
    $requirements=array();
    foreach($data['requirements'] as $requirement) {
        if(!is_array($requirement) || !$id($requirement['id']??null) ||
            !$id($requirement['target']??null) || !$text($requirement['requirement']??null,240) ||
            !$text($requirement['acceptance']??null,240) ||
            !in_array($requirement['dimension']??null,$dimensions,true) ||
            !in_array($requirement['basis']??null,array('brief','source','standard'),true))
            return $invalid('requirements');
        $key=$requirement['id'];
        $target=$requirement['target'];
        if(isset($requirements[$key]) || ($target!=='page' && !isset($sections[$target])))
            return $invalid('requirement identities and targets');
        $requirements[$key]=array('id'=>$key,'target'=>$target,'dimension'=>$requirement['dimension'],
            'basis'=>$requirement['basis'],'requirement'=>trim($requirement['requirement']),
            'acceptance'=>trim($requirement['acceptance']));
        if(array_key_exists('check',$requirement)){
            $check=syndamorph_design_check_contract($requirement['check']);
            if($check===null)return $invalid('requirement check');
            $requirements[$key]['check']=$check;
        }
    }
    $interactions=$data['interactions']??array();
    if(!is_array($interactions)||!array_is_list($interactions)||count($interactions)>12)
        return $invalid('interactions');
    $classified=array();
    foreach($interactions as $interaction){
        if(!is_array($interaction)||!$id($interaction['id']??null)||!$id($interaction['kind']??null)||
            !$id($interaction['requirementId']??null)||!isset($requirements[$interaction['requirementId']])||
            $requirements[$interaction['requirementId']]['dimension']!=='functional'||
            !in_array($interaction['classification']??null,array('native','reusable','new_special','external','unsupported'),true)||
            !$text($interaction['behavior']??null,240)||isset($classified[$interaction['id']]))
            return $invalid('interaction identities, behavior and functional requirements');
        $requirement=$requirements[$interaction['requirementId']];
        $classified[$interaction['id']]=array('id'=>$interaction['id'],'kind'=>$interaction['kind'],
            'requirementId'=>$requirement['id'],'target'=>$requirement['target'],
            'classification'=>$interaction['classification'],'behavior'=>trim($interaction['behavior']));
    }
    return array('version'=>1,'format'=>'structured','title'=>trim($data['title']),
        'sections'=>array_values($sections),'requirements'=>array_values($requirements))+
        ($classified?array('interactions'=>array_values($classified)):array());
}

/** Normalize archived work orders without asserting a dimension or source for old criteria. */
function syndamorph_production_legacy_specification(array $workOrder): array {
    $sections=array();
    foreach($workOrder['sections'] as $index=>$label)
        $sections[]=array('id'=>'section-'.($index+1),'label'=>$label,'parent'=>null);
    $requirements=array();
    foreach(array('constraints','acceptance') as $field) {
        foreach($workOrder[$field] as $index=>$statement) {
            $requirements[]=array('id'=>$field.'-'.($index+1),'target'=>'page','dimension'=>null,
                'basis'=>null,'requirement'=>$statement,'acceptance'=>$field==='acceptance'?$statement:null);
        }
    }
    return array('version'=>1,'format'=>'legacy','title'=>$workOrder['title'],
        'sections'=>$sections,'requirements'=>$requirements);
}

/** The application pins provenance. A classification receipt carries no acceptance verdict. */
function syndamorph_production_specification_receipt(array $specification,string $brief,array $viewports,?array $source): array {
    return array('version'=>$specification['version'],'format'=>$specification['format'],
        'specificationSha256'=>hash('sha256',wp_json_encode($specification)),
        'briefSha256'=>hash('sha256',$brief),'sourceEvidenceSha256'=>$source['section']['evidenceSha256']??null,
        'referenceArchiveId'=>$source['referenceArchive']['id']??null,
        'viewportWidths'=>$viewports)+
        (isset($source['layoutAuthorization'])?array('sourceLayoutAuthorizationSha256'=>
            hash('sha256',wp_json_encode($source['layoutAuthorization']))):array());
}
