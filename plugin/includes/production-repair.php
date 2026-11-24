<?php
/** Native production scope, capability and repair policy. No provider calls here. */
if (!defined('ABSPATH')) exit;
function syndamorph_agent_scope(string $role,string $task): bool {
    return in_array($task,array(
        'boss'=>array('boss','validation','revision_validation','final_approval','scope_resolution'),
        'designer'=>array('designer','design_correction'),
        'developer'=>array('reference','developer','schema_repair','repair')
    )[$role]??array(),true);
}
function syndamorph_repair_route(array $issues): string {
    // Native fidelity defects never authorize a creative redesign. Explicit scope
    // or design changes require a new reference, not a patch to native alone.
    return 'developer';
}
function syndamorph_issue_score(array $issues): int {
    return array_sum(array_map(static fn($i)=>array('high'=>10,'medium'=>4,'low'=>1)[$i['severity']??'medium']??4,$issues));
}
function syndamorph_issue_merge(array $issues): array {
    $out=array();
    foreach($issues as $issue) {
        if(!is_array($issue)||!is_string($issue['detail']??null))continue;
        if(isset($issue['status']) && $issue['status']!=='open')continue;
        $key=($issue['component']??'').'|'.($issue['id']??'');
        if(!isset($out[$key])||syndamorph_issue_score(array($issue))>syndamorph_issue_score(array($out[$key])))$out[$key]=$issue;
    }
    return array_values($out);
}
function syndamorph_issue_model(array $issue,array $plan): ?array {
    if(!is_string($issue['detail']??null)||strlen($issue['detail'])>1000)return null;
    if(isset($issue['status']) && $issue['status']!=='open')return null;
    if(isset($issue['kind']) && $issue['kind']!=='implementation')return null;
    $id=$issue['component']??'';
    if(!is_string($id)||syndamorph_collab_find($plan['blocks'],$id)===null)return null;
    $key=sanitize_key((string)($issue['id']??''));
    if($key==='')return null;
    return array('id'=>$key,'component'=>$id,'severity'=>in_array($issue['severity']??'',array('high','medium','low'),true)?$issue['severity']:'medium','detail'=>$issue['detail'],'source'=>'model');
}
/** Safe measured leaf repair: only a value stable at every inspected width,
 * unique matching text, and a supported property. Never infer responsive CSS
 * from one viewport. Compilation and browser QA remain mandatory. */
function syndamorph_repair_measured(array $node,array $reference,array $native): ?array {
    if(!in_array($node['type']??'',array('heading','paragraph','button'),true)||empty($node['text']))return null;
    $text=trim($node['text']);$maps=array();
    foreach(array($reference,$native) as $samples) {
        $set=array();
        foreach($samples as $sample) {
            $matches=array_values(array_filter($sample['snapshot']['elements']??array(),static fn($e)=>trim((string)($e['text']??''))===$text && in_array(strtolower($e['tag']??''),array('h1','h2','h3','h4','h5','h6','p','a','button'),true)));
            if(count($matches)!==1)return null;
            $set[]=$matches[0]['styles']??array();
        }
        $maps[]=$set;
    }
    if(count($maps[0])!==3||count($maps[1])!==3)return null;
    $replacement=$node;
    foreach(array('fontWeight','fontSize','letterSpacing','color') as $key) {
        $values=array_column($maps[0],$key);$current=array_column($maps[1],$key);
        if(count($values)!==3||count($current)!==3||count(array_unique($values))!==1||$values===$current)continue;
        $v=$values[0];
        if($key==='color'&&preg_match('/^rgb\(\s*(\d{1,3}),\s*(\d{1,3}),\s*(\d{1,3})\s*\)$/D',$v,$m)&&max(array_slice($m,1))<=255)$v=sprintf('#%02x%02x%02x',$m[1],$m[2],$m[3]);
        if($key==='fontWeight'&&!preg_match('/^[1-9]00$/D',$v))continue;
        if($key==='fontSize'&&!preg_match('/^\d{1,3}(?:\.\d{1,3})?px$/D',$v))continue;
        if($key==='letterSpacing'&&!preg_match('/^-?\d{1,3}(?:\.\d{1,3})?px$/D',$v))continue;
        if($key==='color'&&!preg_match('/^#[0-9a-f]{6}$/iD',$v))continue;
        $replacement['style'][$key]=$v;
    }
    return SyndaMorph_Job_Memory::fingerprint($replacement)===SyndaMorph_Job_Memory::fingerprint($node)?null:array('id'=>$node['id'],'replacement'=>$replacement);
}
