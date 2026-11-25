<?php
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');

class WP_Error {
    public function __construct(public string $code,public string $message,public array $data=array()) {}
}

require dirname(__DIR__).'/plugin/includes/production-responsive.php';

function expect(bool $value,string $message): void {
    if(!$value)throw new RuntimeException($message);
}

$html='<section><style>@media (600px < width < 1024px){.card{padding:24px}}
    @media (max-width:48em){.card{padding:12px}}</style><h2>Welcome</h2></section>';
$widths=syndamorph_design_probe_widths($html,array(1440,1000,768,375));
foreach(array(1440,1350,1000,1023,1024,1025,900,767,768,769,599,600,601,450,375) as $width){
    expect(in_array($width,$widths,true),'Missing explicit, intermediate or boundary sample at '.$width.'px');
}
$complex='<section><style>';
for($width=420;$width<850;$width+=12)$complex.='@media (width < '.$width.'px){x{display:block}}';
$complex.='</style></section>';
expect(syndamorph_design_probe_widths($complex,array(1440,768,375)) instanceof WP_Error,
    'Unverified breakpoint complexity must not silently drop probes');

$element=static fn(string $text,array $rect,int $parent): array =>
    array('tag'=>'P','text'=>$text,'textRect'=>$rect,'lineRects'=>array($rect),'parent'=>$parent);
$evidence=array(
    array('width'=>768,'snapshot'=>array('overflow'=>false,'elements'=>array(
        $element('Title',array(24,30,280,20),0),$element('Body',array(24,55,200,20),0)))),
    array('width'=>600,'snapshot'=>array('overflow'=>true,'elements'=>array(
        $element('Title',array(10,30,630,20),0),$element('Body',array(12,34,120,20),0)))),
    array('width'=>375,'snapshot'=>array('overflow'=>false,'elements'=>array(
        $element('Title',array(20,40,280,20),0),$element('Body',array(20,40,280,20),1)))),
);
$findings=syndamorph_design_responsive_findings($evidence);
expect(array_column($findings,'code')===array('horizontal-overflow','text-clipping','text-collision') &&
    array_column($findings,'width')===array(600,600,600),'Intermediate responsive defects escaped or clean views were flagged');
echo "PASS: deterministic breakpoint planning, preserved explicit widths and intermediate browser findings\n";
