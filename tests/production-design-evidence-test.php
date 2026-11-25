<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
require dirname(__DIR__).'/plugin/includes/production-design-evidence.php';

function expect(bool $value,string $message): void {
    if(!$value)throw new RuntimeException($message);
}

$rows=array(
    array('tag'=>'SECTION','id'=>'hero','visible'=>true,'styles'=>array('backgroundColor'=>'rgb(24, 40, 58)')),
    array('tag'=>'A','id'=>'cta','visible'=>true,'href'=>'#elsewhere','fragmentTarget'=>false,'keyboardReachable'=>true),
    array('tag'=>'SPAN','id'=>'marker','visible'=>false),
);
$sample=array('width'=>375,'snapshot'=>array('copy'=>'Explore rooms','elements'=>$rows,'elementCount'=>count($rows)));
$make=static fn($values)=>syndamorph_design_check_contract($values);
$checks=array(
    array('id'=>'gold-mark','dimension'=>'visual','basis'=>'brief','check'=>$make(array('kind'=>'element','selector'=>'#marker'))),
    array('id'=>'room-link','dimension'=>'functional','basis'=>'brief','check'=>$make(array('kind'=>'link','selector'=>'#cta','value'=>'#rooms'))),
    array('id'=>'navy-bg','dimension'=>'visual','basis'=>'brief','check'=>$make(array('kind'=>'style','selector'=>'#hero',
        'field'=>'backgroundColor','value'=>'rgb(24 40 58)'))),
    array('id'=>'visible-title','dimension'=>'content','basis'=>'brief','check'=>$make(array('kind'=>'text','value'=>'Explore rooms'))),
    array('id'=>'native-round-trip','dimension'=>'implementation','basis'=>'brief'),
    array('id'=>'responsive-layout','dimension'=>'responsive','basis'=>'brief'),
    array('id'=>'contrast','dimension'=>'accessibility','basis'=>'standard'),
);
$specification=array('format'=>'structured','requirements'=>$checks);
$facts=syndamorph_design_browser_facts(array($sample),$specification);
$approval=array('requirementChecks'=>array(
    array('id'=>'gold-mark','status'=>'verified','evidence'=>array('view-375')),
    array('id'=>'room-link','status'=>'verified','evidence'=>array('view-375')),
    array('id'=>'responsive-layout','status'=>'verified','evidence'=>array('responsive')),
    array('id'=>'contrast','status'=>'not_applicable','reason'=>'This fixture has no applicable contrast requirement.'),
));
$result=syndamorph_design_requirements($specification,array($sample),$facts,$approval,array('responsive'));
expect($result['blocking']===array('gold-mark','room-link'),'Browser failures were silently waived or accurate evidence rejected');
expect(array_column($result['checks'],'status')===array('failed','failed','verified','verified','uncertain','verified','not_applicable'),
    'Structured verdict or independent-implementation handoff disagreed with measured facts');
$sample['snapshot']['elementCount']=300;
$hidden=syndamorph_design_run_check($checks[0]['check'],array($sample));
expect($hidden['status']==='uncertain','An incomplete inventory was treated as proof of absence');
expect($make(array('kind'=>'element','selector'=>'section>button'))===null,'Unrestricted selector entered the inspection contract');
expect(syndamorph_design_computed_value('backgroundColor','#18283a')==='rgb(24, 40, 58)','Equivalent color values did not normalize');
echo "PASS: measured element, literal copy, link semantics, colors and requirement-level evidence authority\n";
