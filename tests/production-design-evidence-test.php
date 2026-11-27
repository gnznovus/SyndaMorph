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
$paragraph=str_repeat('Literal text retained in the measured paragraph. ',6).'Final words.';
$long=$make(array('kind'=>'text','selector'=>'p','value'=>$paragraph,'widths'=>array(1440)));
$sample=array('width'=>1440,'snapshot'=>array('copy'=>$paragraph,'elements'=>array(
    array('tag'=>'DIV','visible'=>true,'normalizedText'=>$paragraph),
    array('tag'=>'P','visible'=>true,'normalizedText'=>substr($paragraph,0,250)),
)));
expect($long['value']===$paragraph&&$long['selector']==='p','Paragraph-sized checks lost their full text or selector.');
expect(syndamorph_design_run_check($long,array($sample))['status']==='uncertain','Shortened evidence proved an unmeasured paragraph.');
$sample['snapshot']['elements'][1]['checkText']='Unrelated visible paragraph.';
$sample['snapshot']['elements'][1]['checkTextTruncated']=false;
expect(syndamorph_design_run_check($long,array($sample))['status']==='failed','Unrelated section text satisfied an explicit paragraph check.');
$sample['snapshot']['elements'][1]['checkText']=$paragraph;
expect(syndamorph_design_run_check($long,array($sample))['status']==='verified','The complete scoped literal was not recognized.');
$footerRows=array_fill(0,28,array('tag'=>'DIV','visible'=>true,'styles'=>array('backgroundColor'=>'rgb(240, 240, 240)')));
$footerRows[]=array('tag'=>'P','visible'=>false,'normalizedText'=>'View all offers','rect'=>array(0,0,0,0));
$footerRows[]=array('tag'=>'P','visible'=>true,'normalizedText'=>'1 / 3','rect'=>array(20,735,27,22));
$footerRows[]=array('tag'=>'P','visible'=>true,'normalizedText'=>'View all offers','rect'=>array(262,735,93,22));
$sample=array('width'=>375,'snapshot'=>array('copy'=>'1 / 3 View all offers','elements'=>$footerRows));
$specification=array('format'=>'structured','requirements'=>array(array('id'=>'footer','dimension'=>'visual','basis'=>'source')));
$facts=syndamorph_design_browser_facts(array($sample),$specification);
expect($facts[0]['projectedElementCount']===31&&count($facts[0]['elements'])===28&&
    array_column($facts[0]['visibleTextTail'],'text')===array('1 / 3','View all offers')&&
    $facts[0]['visibleTextTail'][1]['rect']===array(262,735,93,22),
    'The bounded projection lost visible footer evidence or surfaced its hidden duplicate.');
$approval=array('requirementChecks'=>array(array('id'=>'footer','status'=>'verified',
    'evidence'=>array($facts[0]['visibleTextTail'][1]['id']))));
expect(syndamorph_design_requirements($specification,array($sample),$facts,$approval,array())['blocking']===array(),
    'A visible-tail evidence ID was not accepted by the checklist.');
echo "PASS: measured element, literal copy, link semantics, colors and requirement-level evidence authority\n";

$widths=array(1440,768,375);
$sections=static function(array $measured,int $offset=0)use($widths):array {
    return array_map(static function(int $viewport,float $width)use($offset):array {
        return array('width'=>$viewport,'snapshot'=>array('overflow'=>false,'elements'=>array(
            array('tag'=>'SECTION','visible'=>true,'parent'=>null,'rect'=>array(($viewport-$width)/2+$offset,0,$width,500)),
            array('tag'=>'DIV','visible'=>true,'parent'=>0,'rect'=>array(20,32,345,160)),
        )));
    },$widths,$measured);
};
$sizes=array('full'=>$widths,'wide'=>array(1340,691.2,315),'content'=>array(645,645,315));
$host=array_map(static fn($width,$wide,$content)=>array('width'=>$width,'snapshot'=>array('elements'=>array(
    array('id'=>'sm-width-wide','rect'=>array(($width-$wide)/2,0,$wide,20)),
    array('id'=>'sm-width-content','rect'=>array(($width-$content)/2,20,$content,20)),
))),$widths,$sizes['wide'],$sizes['content']);
foreach($sizes as $alignment=>$measured){
    $result=syndamorph_design_section_alignment($sections($measured),$widths,$host);
    expect($result['alignment']===$alignment&&array_column($result['views'],'viewport')===$widths,
        'The Designer outer box was classified incorrectly for '.$alignment);
}
expect(syndamorph_design_section_alignment($sections(array(1100,768,375)),$widths,$host)['alignment']===null&&
    syndamorph_design_section_alignment($sections($sizes['wide'],12),$widths,$host)['alignment']===null,
    'An unmatched limit or off-center section became a native alignment');
expect(syndamorph_design_section_alignment(array_reverse($sections($widths)),$widths,$host)['basis']==='missing_viewports',
    'The width contract accepted unbound viewport evidence');
$ambiguous=$sections($widths);
$ambiguous[0]['snapshot']['elements'][]=$ambiguous[0]['snapshot']['elements'][0];
expect(syndamorph_design_section_alignment($ambiguous,$widths,$host)['basis']==='unidentified_root',
    'More than one visible outer box must not produce alignment');
echo "PASS: exact Designer evidence maps the outer box to full, wide or content width\n";
