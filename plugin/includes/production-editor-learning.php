<?php
/** Cross-job engineering observations. Never inject unverified editor failures into agent prompts. */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/production-learning.php';
function syndamorph_editor_learning_revision(): string {
    return hash_file('sha256',__DIR__.'/production-block-plan.php');
}
function syndamorph_editor_learning_key(string $kind,string $block,string $wp,string $revision): string {
    return hash('sha256',implode('|',array($kind,$block,$wp,$revision)));
}
function syndamorph_editor_learning_observe(int $job,string $checksum,string $kind,string $block): void {
    if(!in_array($kind,array('registry','parser','serialization','unknown'),true))return;
    $wp=get_bloginfo('version');$revision=syndamorph_editor_learning_revision();
    $key=syndamorph_editor_learning_key($kind,$block,$wp,$revision);
    $rows=get_option('syndamorph_editor_learning_v1',array());
    if(!is_array($rows))$rows=array();
    $row=$rows[$key]??array('category'=>'engineering','kind'=>$kind,'block'=>$block,'wpVersion'=>$wp,'compilerRevision'=>$revision,'occurrences'=>0,'observations'=>array(),'verifiedCorrections'=>0,'status'=>'observed');
    $observation=hash('sha256',$job.':'.$checksum);
    if(!isset($row['observations'][$observation])){
        $row['occurrences']++;
        $row['observations'][$observation]=array('job'=>$job,'checksum'=>$checksum,'resolved'=>false);
    }
    $row['observations']=array_slice($row['observations'],-50,null,true);
    $row['lastSeen']=gmdate('c');$rows[$key]=$row;
    update_option('syndamorph_editor_learning_v1',array_slice($rows,-100,null,true),false);
    syndamorph_learning_record('engineering','editor',$block===''?'*':str_replace('core/','',$block),$kind,'Editor validation detected a '.$kind.' defect in a registered native block.','job:'.$job.':'.substr($checksum,0,16),false);
}
function syndamorph_editor_learning_confirm(int $job,string $old,string $new): void {
    if($old===$new)return;
    $rows=get_option('syndamorph_editor_learning_v1',array());
    if(!is_array($rows))return;
    $observation=hash('sha256',$job.':'.$old);
    foreach($rows as &$row){
        if(($row['kind']??'')!=='serialization'||!isset($row['observations'][$observation])||!empty($row['observations'][$observation]['resolved']))continue;
        $row['observations'][$observation]['resolved']=true;
        $row['verifiedCorrections']++;
        $row['status']='corrected_example';
        $row['correctedCompilerRevision']=syndamorph_editor_learning_revision();
        $row['correctedArtifactSha256']=$new;
    }unset($row);
    update_option('syndamorph_editor_learning_v1',$rows,false);
    // Editor recovery confirms the artifact, not a reusable prompt rule.
    // Engineering learning remains observational until autonomous compiler repair is implemented.
}
