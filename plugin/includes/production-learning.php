<?php
/** Bounded, version-aware cross-job learning. No full prompts, content or provider secrets. */
if (!defined('ABSPATH')) exit;
const SYNDAMORPH_LEARNING_OPTION='syndamorph_learning_v1';
function syndamorph_learning_revision(): string {
    return hash_file('sha256',__DIR__.'/production-block-plan.php');
}
function syndamorph_learning_rows(): array {
    $rows=get_option(SYNDAMORPH_LEARNING_OPTION,array());
    return is_array($rows)?$rows:array();
}
function syndamorph_learning_pattern(string $field): array {
    $parts=explode('.',$field);$name=(string)end($parts);
    $safe=array('align','contentWidth','gap','padding','margin','fontSize','fontWeight','lineHeight','letterSpacing','gradient','minHeight','justifyContent','verticalAlignment','borderRadius','background','color','href','text','level','children','plan');
    if(!in_array($name,$safe,true))$name='plan';
    return array($name,$name==='plan'?'plan':(str_contains($field,'.style.')?'style':'node'));
}
/** Only vocabulary from our own native contract may become an instruction. */
function syndamorph_learning_schema_rule(string $field,string $message): ?array {
    list($name,$location)=syndamorph_learning_pattern($field);
    if(str_contains($message,'Unsupported node field.') && in_array($name,array('contentWidth','gap','padding','margin','fontSize','fontWeight','lineHeight','letterSpacing','gradient','minHeight','justifyContent','verticalAlignment','borderRadius','background','color'),true)) {
        return array('rule'=>'Place '.$name.' inside the node style object, never as a node-level key.','pattern'=>$name,'block'=>'*');
    }
    if(str_contains($message,'Alignment belongs inside style.align.'))
        return array('rule'=>'Place text alignment inside style.align.','pattern'=>'align','block'=>'heading|paragraph');
    if(str_contains($message,'Invalid color.') && in_array($name,array('background','color'),true))
        return array('rule'=>'Use six-digit hexadecimal values for style.'.$name.'. Move linear gradients to style.gradient instead of style.background.','pattern'=>$name,'block'=>'*');
    if(str_contains($message,'Invalid two-color gradient.') && $name==='gradient')
        return array('rule'=>'Use a two-color linear-gradient(ANGLEdeg,#rrggbb,#rrggbb) in style.gradient; remove unsupported extra stops.','pattern'=>'gradient','block'=>'group|column|button');
    if(str_contains($message,'Unsupported style property.') && $name==='plan')
        return array('rule'=>'Use flat native style keys such as fontSize and background, not nested Gutenberg style.typography or style.color objects.','pattern'=>'plan','block'=>'*');
    if(str_contains($message,'Invalid length.'))
        return array('rule'=>'Use only lengths supported by the native compiler contract for '.$name.'.','pattern'=>$name,'block'=>'*');
    if(str_contains($message,'Invalid font weight.'))
        return array('rule'=>'Use a 100–900 hundred-step numeric fontWeight value within style.','pattern'=>'fontWeight','block'=>'heading|paragraph|button');
    if(str_contains($message,'Invalid unitless line height.'))
        return array('rule'=>'Use a supported unitless 1–2.5 lineHeight inside style.','pattern'=>'lineHeight','block'=>'heading|paragraph|button');
    if(str_contains($message,'Constrained layout and flex alignment'))
        return array('rule'=>'Use separate nested groups for contentWidth and flex alignment.','pattern'=>'contentWidth','block'=>'group');
    return null;
}
function syndamorph_learning_key(string $category,string $task,string $block,string $pattern,string $rule): string {
    return hash('sha256',implode('|',array($category,$task,$block,$pattern,$rule)));
}
/** Verified is a gate: only compiler-accepted schema corrections or QA-accepted repairs. */
function syndamorph_learning_record(string $category,string $task,string $block,string $pattern,string $rule,string $source,bool $verified=false,array $evidence=array()): ?string {
    if(!in_array($category,array('agent','engineering','capability'),true) ||
       !in_array($task,array('developer','schema_repair','repair','editor'),true) ||
       strlen($rule)>240 || $rule==='' || !preg_match('/^[a-zA-Z0-9_|*-]{1,64}$/D',$block) ||
       !preg_match('/^[a-zA-Z0-9_-]{1,48}$/D',$pattern) ||
       !preg_match('/^[a-zA-Z0-9:_-]{1,110}$/D',$source))return null;
    $key=syndamorph_learning_key($category,$task,$block,$pattern,$rule);
    $rows=syndamorph_learning_rows();$revision=syndamorph_learning_revision();
    $item=$rows[$key]??array('id'=>$key,'category'=>$category,'task'=>$task,'block'=>$block,'pattern'=>$pattern,'rule'=>$rule,
        'compilerRevision'=>$revision,'firstSeen'=>gmdate('c'),'occurrences'=>0,'verifiedCount'=>0,'sources'=>array(),'status'=>'observed');
    // A revision change makes the old lesson ineligible until it is reverified.
    if($item['compilerRevision']!==$revision){
        $wasDisabled=$item['status']==='disabled';
        $item['compilerRevision']=$revision;$item['status']=$wasDisabled?'disabled':'observed';$item['verifiedCount']=0;$item['sources']=array();$item['occurrences']=0;
        unset($item['evidence']);
    }
    if(!isset($item['sources'][$source])){
        $item['sources'][$source]=array('verified'=>$verified);
        $item['occurrences']++;
        if($verified)$item['verifiedCount']++;
    }elseif($verified&&!$item['sources'][$source]['verified']){
        $item['sources'][$source]['verified']=true;$item['verifiedCount']++;
    }
    $item['sources']=array_slice($item['sources'],-40,null,true);
    if($item['verifiedCount']>0 && $item['status']!=='disabled')$item['status']='verified';
    if($evidence)$item['evidence']=$evidence;
    $item['lastSeen']=gmdate('c');$rows[$key]=$item;
    if(count($rows)>150)$rows=array_slice($rows,-150,null,true);
    update_option(SYNDAMORPH_LEARNING_OPTION,$rows,false);
    return $key;
}
function syndamorph_learning_schema(string $field,string $message,string $source,bool $verified): void {
    $rule=syndamorph_learning_schema_rule($field,$message);
    if(!$rule)return;
    syndamorph_learning_record('agent','schema_repair',$rule['block'],$rule['pattern'],$rule['rule'],$source,$verified);
}
/** Learn only evidence-backed style-property choices, not raw user copy or arbitrary markup. */
function syndamorph_learning_repair(array $before,array $after,int $oldScore,int $newScore,string $source): void {
    if($newScore >= $oldScore || ($before['type']??null)!==($after['type']??null))return;
    $type=(string)($before['type']??'');
    if(!in_array($type,array('group','column','columns','heading','paragraph','buttons','button'),true))return;
    $keys=array_unique(array_merge(array_keys($before['style']??array()),array_keys($after['style']??array())));
    $allowed=array('fontSize','fontWeight','lineHeight','letterSpacing','color','background','gradient','padding','margin','gap','minHeight','contentWidth','justifyContent','verticalAlignment','align','borderRadius');
    foreach($keys as $key){
        if(!in_array($key,$allowed,true) || ($before['style'][$key]??null)===($after['style'][$key]??null))continue;
        // A change is not a universal fixed value; only the property/target is retained.
        syndamorph_learning_record('agent','repair',$type,$key,'For '.$type.' visual fidelity issues, inspect measured '.$key.' and adjust only after comparing reference and native evidence.',$source,true);
    }
}
/** Capability gaps are observations, not instructions to invent unsupported blocks. */
function syndamorph_learning_capability(array $notes,string $source): void {
    $known=array(
        'Leaf max-width requires a constrained group layout; verify wrapping.'=>array('constrained_layout','Native leaf max-width is not supported; use a constrained group.'),
        'Reference contains layout effects outside current native block capabilities.'=>array('advanced_layout','Absolute positioning, transforms and named grids are outside the native contract.'),
        'Reference contains elements outside current native block schema.'=>array('unsupported_elements','Images, lists, tables and vector elements need an explicit compiler capability before native export.'),
        'Reference typography uses casing or wrapping effects without a native style mapping. Check the visual impact before approval.'=>
            array('typography_mapping','Native block styles have no text-transform or text-wrap mapping. Verify appearance and reference compatibility.')
    );
    foreach(array_unique($notes) as $note)if(isset($known[$note])){
        list($pattern,$rule)=$known[$note];
        syndamorph_learning_record('capability','developer','*',$pattern,$rule,$source,false);
    }
}
function syndamorph_learning_retrieve(string $task,string $block='*',int $limit=3): array {
    $revision=syndamorph_learning_revision();$result=array();
    foreach(syndamorph_learning_rows() as $item){
        if(($item['category']??'')!=='agent'||($item['status']??'')!=='verified'||($item['compilerRevision']??'')!==$revision ||
            !in_array($item['task']??'',$task==='developer'?array('schema_repair'):array($task),true))continue;
        if($task==='repair' && $item['block']!=='*' && $item['block']!==$block && !in_array($block,explode('|',$item['block']),true))continue;
        $result[]=array('rule'=>$item['rule'],'verifiedCount'=>$item['verifiedCount'],'occurrences'=>$item['occurrences']);
    }
    usort($result,static fn($a,$b)=>$b['verifiedCount']<=>$a['verifiedCount']);
    return array_slice(array_column($result,'rule'),0,max(0,min(4,$limit)));
}

/** Record only native operation vocabulary. Do not retain raw patches or copy. */
function syndamorph_learning_operation_pattern(array $patch): array {
    $properties=array();
    foreach(is_array($patch['changes']??null)?$patch['changes']:array() as $change) {
        if(is_array($change) && is_string($change['path']??null) &&
            preg_match('/^style\.(color|background|gradient|fontSize|fontWeight|lineHeight|letterSpacing|padding|margin|gap|minHeight|contentWidth|justifyContent|verticalAlignment|align|borderRadius)(?:\.(top|right|bottom|left))?$/D',$change['path'])) {
            $properties[]=$change['path'];
        }
    }
    $properties=array_values(array_unique($properties));
    sort($properties);
    return array('mode'=>empty($patch['wraps'])?'style':'structure','properties'=>$properties);
}

function syndamorph_learning_repair_categories(array $focus): array {
    $allowed=array('alignment','height','spacing','typography','wrapping');
    return array_values(array_unique(array_filter(array_column($focus,'category'),
        static fn($category)=>in_array($category,$allowed,true))));
}
function syndamorph_learning_layout(array $node): string {
    if(isset($node['style']['contentWidth']))return 'constrained';
    return in_array($node['type']??'',array('group','columns','column','buttons'),true)?'container':'leaf';
}

function syndamorph_learning_operation(array $assignment,array $patch,array $verdict,string $source): ?string {
    $categories=syndamorph_learning_repair_categories($assignment['focus']);
    $block=$assignment['node']['type']??'';
    if(!$categories || !in_array($block,array('group','columns','column','heading','paragraph','buttons','button'),true))return null;
    $pattern=syndamorph_learning_operation_pattern($patch);
    if(!$pattern['properties'] && $pattern['mode']==='style')return null;
    $focusGeometry=array_intersect_key($verdict['geometryDeltas'],array_fill_keys($assignment['group'],true));
    $pixelDeltas=$verdict['pixelDeltas']??array();
    $pixelImproved=$pixelDeltas && array_sum($pixelDeltas)/count($pixelDeltas)<=-0.002;
    $verified=!empty($verdict['accepted']) && (($verdict['focusAfter']<$verdict['focusBefore'] && $pixelImproved) ||
        ($focusGeometry && (bool)array_intersect(array('improved','resolved'),
            array_intersect_key($verdict['states'],array_fill_keys($assignment['group'],true)))));
    $metrics=array();
    foreach($verdict['geometryDeltas'] as $key=>$views) {
        $category=explode('|',$key,2)[0];
        if(in_array($category,$categories,true))$metrics[$category]=$views;
    }
    $category=$categories[0];
    $description=$pattern['mode']==='structure'?
        'Wrap ordered native children in a scoped group and preserve their IDs.':
        'Apply the supported style properties together and verify the affected layout.';
    $rule=$verified?$description:'Observed correction failed visual acceptance. Review the measurements before repeating this strategy.';
    $key='operation_'.substr(hash('sha256',wp_json_encode(array($category,$pattern,$verified))),0,24);
    $relationship=count($assignment['nodes'])>1?'related_blocks':'single_block';
    return syndamorph_learning_record('agent','repair',$block,$key,$rule,$source,$verified,
        array('protocol'=>2,'category'=>$category,'relationship'=>$relationship,
            'layout'=>syndamorph_learning_layout($assignment['node']),'pattern'=>$pattern,
            'geometry'=>$metrics,'focusBefore'=>$verdict['focusBefore'],'focusAfter'=>$verdict['focusAfter'],
            'result'=>$verified?'verified_improvement':($verdict['accepted']?'accepted_unattributed':'rejected')));
}

function syndamorph_learning_match_operation(array $assignment): array {
    $categories=syndamorph_learning_repair_categories($assignment['focus']);
    $block=$assignment['node']['type']??'';
    $layout=syndamorph_learning_layout($assignment['node']);
    $relationship=count($assignment['nodes']??array())>1?'related_blocks':'single_block';
    $revision=syndamorph_learning_revision();
    $matches=array();
    foreach(syndamorph_learning_rows() as $id=>$row) {
        $evidence=$row['evidence']??array();
        if(($row['compilerRevision']??'')!==$revision || ($row['status']??'')!=='verified' ||
            ($row['task']??'')!=='repair' || ($row['block']??'')!==$block ||
            ($evidence['protocol']??0)!==2 || !in_array($evidence['category']??'', $categories,true) ||
            ($evidence['layout']??'')!==$layout || ($evidence['relationship']??'')!==$relationship ||
            ($evidence['pattern']['mode']??'')!==$assignment['mode'])continue;
        $matches[]=array('id'=>$id,'rule'=>$row['rule'],'category'=>$evidence['category'],
            'pattern'=>$evidence['pattern'],'verifiedCount'=>$row['verifiedCount']);
    }
    usort($matches,static fn($a,$b)=>$b['verifiedCount']<=>$a['verifiedCount'] ?: strcmp($a['id'],$b['id']));
    return $matches;
}

function syndamorph_learning_followed(array $lessons,array $patch): array {
    $actual=syndamorph_learning_operation_pattern($patch);
    $followed=array();
    foreach($lessons as $lesson) {
        $pattern=$lesson['pattern'];
        if($pattern['mode']===$actual['mode'] && !array_diff($pattern['properties'],$actual['properties']))$followed[]=$lesson['id'];
    }
    return $followed;
}
function syndamorph_learning_snapshot(): array {
    $rows=array_values(syndamorph_learning_rows());$counts=array('agent'=>0,'engineering'=>0,'capability'=>0,'verified'=>0,'observed'=>0,'disabled'=>0,'stale'=>0);
    $revision=syndamorph_learning_revision();
    foreach($rows as $item){
        $counts[$item['category']] = ($counts[$item['category']]??0)+1;
        $status=($item['compilerRevision']??'')===$revision?($item['status']??'observed'):'stale';
        $counts[$status]=($counts[$status]??0)+1;
    }
    $safe=array_map(static function($v)use($revision){
        unset($v['sources']);
        $v['status']=($v['compilerRevision']??'')===$revision?($v['status']??'observed'):'stale';
        return $v;
    },array_slice($rows,-100));
    return array('version'=>1,'counts'=>$counts,'compilerRevision'=>$revision,'lessons'=>$safe);
}
function syndamorph_learning_manage(string $key,string $operation): bool {
    $rows=syndamorph_learning_rows();
    if(!isset($rows[$key]))return false;
    if($operation==='delete')unset($rows[$key]);
    elseif($operation==='disable')$rows[$key]['status']='disabled';
    elseif($operation==='enable')$rows[$key]['status']=($rows[$key]['verifiedCount']??0)>0?'verified':'observed';
    else return false;
    update_option(SYNDAMORPH_LEARNING_OPTION,$rows,false);
    return true;
}
/** Extract only independently evidenced schema resolution pairs from legacy history. */
function syndamorph_learning_bootstrap(array $events,string $jobId): int {
    $pending=array();$count=0;
    foreach($events as $event){
        if(($event['type']??'')!=='schema_repair'||!is_array($event['data']??null))continue;
        $d=$event['data'];$field=(string)($d['field']??'plan');$attempt=(int)($d['attempt']??0);
        if($attempt<1||$attempt>3)continue;
        if(($d['status']??'')==='required')$pending[$attempt]=array('field'=>$field,'message'=>(string)($d['message']??''));
        if(($d['status']??'')==='resolved' && isset($pending[$attempt])){
            $old=$pending[$attempt];
            $rule=syndamorph_learning_schema_rule($old['field'],$old['message']);
            if($rule){syndamorph_learning_schema($old['field'],$old['message'],'hist:'.$jobId.':'.$attempt,true);$count++;}
            unset($pending[$attempt]);
        }
    }
    return $count;
}
