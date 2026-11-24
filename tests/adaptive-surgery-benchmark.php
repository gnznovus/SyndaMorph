<?php
/** Offline benchmark driver. Node supplies evidence from the isolated Chromium renderer. */
declare(strict_types=1);
define('ABSPATH',__DIR__.'/');
class WP_Error {
    public function __construct(private string $code,private string $message,private array $data=array()) {}
    public function get_error_message(): string { return $this->message; }
}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function wp_json_encode($value): string { return json_encode($value,JSON_THROW_ON_ERROR); }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/','',strtolower($value)); }
require __DIR__.'/../plugin/includes/production-block-plan.php';
require __DIR__.'/../plugin/includes/production-collaboration.php';

$archive=json_decode(file_get_contents(__DIR__.'/fixtures/gen-015-repair.json'),true,512,JSON_THROW_ON_ERROR);
$viewportWidths=array(1440,768,390,375);
$hotelChanges=array(
    array('target'=>'sm-hero-root','path'=>'style.gap','op'=>'remove'),
    array('target'=>'sm-hero-root','path'=>'style.minHeight','value'=>'clamp(26.5rem, 20rem + 20vw, 38rem)'),
    array('target'=>'sm-hero-cta','path'=>'style.fontSize','value'=>'clamp(0.95rem, 0.9rem + 0.25vw, 1.05rem)'),
    array('target'=>'sm-hero-cta','path'=>'style.fontWeight','value'=>600),
    array('target'=>'sm-hero-cta','path'=>'style.lineHeight','value'=>1.2),
);
$hotelPatch=array('id'=>'sm-hero-root','changes'=>$hotelChanges,'wraps'=>array(array(
    'parent'=>'sm-hero-root','id'=>'sm-hero-layout',
    'children'=>array('sm-hero-eyebrow','sm-hero-title','sm-hero-intro','sm-hero-actions'),
    'style'=>array('justifyContent'=>'stretch','gap'=>'1.5rem'),
)));
$callout=array('version'=>1,'blocks'=>array(array('id'=>'callout','type'=>'group',
    'style'=>array('background'=>'#ddeeff','minHeight'=>'250px','padding'=>'2rem','justifyContent'=>'left'),
    'children'=>array(
        array('id'=>'title','type'=>'heading','level'=>2,'text'=>'Visit the gallery',
            'style'=>array('fontSize'=>'26px','fontWeight'=>600,'lineHeight'=>1.2)),
        array('id'=>'intro','type'=>'paragraph','text'=>'Explore the exhibitions.',
            'style'=>array('fontSize'=>'16px','fontWeight'=>400)),
        array('id'=>'actions','type'=>'buttons','children'=>array(
            array('id'=>'cta','type'=>'button','text'=>'Plan a visit','href'=>'#visit'),
        )),
    ),
)));
$calloutReference='<style>*{box-sizing:border-box}body{margin:0;font:16px/1.5 system-ui,sans-serif}section{background:#ddeeff;min-height:250px;padding:2rem;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:20px;text-align:center}h2,p{margin:0}h2{font-size:26px;font-weight:600;line-height:1.2}p{font-size:16px;font-weight:400}a{display:inline-block;padding:.75em 1.5em;background:#26384a;color:#fff;text-decoration:none;border-radius:4px}</style><section><h2>Visit the gallery</h2><p>Explore the exhibitions.</p><a href="#visit">Plan a visit</a></section>';
$calloutPatch=array('id'=>'callout','changes'=>array(
    array('path'=>'style.justifyContent','value'=>'center'),
    array('path'=>'style.verticalAlignment','value'=>'center'),
    array('path'=>'style.gap','value'=>'20px'),
    array('target'=>'title','path'=>'style.align','value'=>'center'),
    array('target'=>'intro','path'=>'style.align','value'=>'center'),
));
$fixtures=array(
    'GEN-015 hotel hero'=>array('plan'=>$archive['plan'],'reference'=>$archive['reference'],'patch'=>$hotelPatch,'mode'=>'structure'),
    'Gallery callout'=>array('plan'=>$callout,'reference'=>$calloutReference,'patch'=>$calloutPatch,'mode'=>'style'),
);

if(($argv[1]??'')==='prepare') {
    $result=array();
    foreach($fixtures as $name=>$fixture) {
        $plan=$fixture['plan'];$root=$plan['blocks'][0]['id'];
        $authority=array('id'=>$root,'nodes'=>array_column(syndamorph_surgery_context($plan['blocks'][0]),'id'),
            'wrapParents'=>array($root),'mode'=>$fixture['mode']);
        $before=syndamorph_compile_block_plan(wp_json_encode($plan));
        $after=syndamorph_surgery_operation($plan,$fixture['patch'],$authority);
        if(is_wp_error($before)||is_wp_error($after)||is_wp_error($after['error']??null)) {
            $error=is_wp_error($before)?$before:(is_wp_error($after)?$after:$after['error']);
            throw new RuntimeException($name.': '.$error->get_error_message());
        }
        $result[$name]=array('widths'=>$viewportWidths,'reference'=>$fixture['reference'],
            'before'=>$before['preview'],'after'=>$after['artifact']['preview'],'mode'=>$fixture['mode']);
    }
    echo wp_json_encode($result);
} elseif(($argv[1]??'')==='score') {
    $inputs=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $results=array();
    foreach($fixtures as $name=>$fixture) {
        $data=$inputs[$name];$reference=$data['reference'];$plan=$fixture['plan'];
        $before=syndamorph_visual_metrics($reference,$data['before'],$plan);
        $after=syndamorph_visual_metrics($reference,$data['after'],$plan);
        $issues=static fn(array $samples,array $geometry):array=>syndamorph_issue_merge(array_merge(
            syndamorph_collab_evidence($reference,$samples),syndamorph_collab_style_findings($reference,$samples,$plan),
            syndamorph_visual_geometry_issues($geometry)));
        $previous=$issues($data['before'],$before);$current=$issues($data['after'],$after);
        $verdict=syndamorph_visual_decision($previous,$current,$before,$after,
            $data['beforePixels'],$data['afterPixels'],$previous,false);
        $results[$name]=array('mode'=>$fixture['mode'],'accepted'=>$verdict['accepted'],'reason'=>$verdict['reason'],
            'scoreBefore'=>$verdict['beforeScore'],'scoreAfter'=>$verdict['afterScore'],
            'retainedImprovement'=>$verdict['accepted']&&$verdict['beforeScore']>0?
                ($verdict['beforeScore']-$verdict['afterScore'])/$verdict['beforeScore']:0,
            'beforePixels'=>array_column($data['beforePixels'],'pixelDifferenceRatio','width'),
            'afterPixels'=>array_column($data['afterPixels'],'pixelDifferenceRatio','width'),
            'remaining'=>array_column($current,'category'),'geometryDeltas'=>$verdict['geometryDeltas']);
    }
    echo wp_json_encode($results);
} else {
    fwrite(STDERR,"Expected prepare or score.\n");
    exit(2);
}
