<?php
// WordPress integration test. Run on a disposable local database.
require '/var/www/html/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
$activated=activate_plugin('syndamorph/syndamorph.php');
if(is_wp_error($activated))throw new RuntimeException($activated->get_error_message());
if(!WP_Block_Type_Registry::get_instance()->is_registered('syndamorph/tabs'))syndamorph_tabs_register();

$expect=static function(bool $test,string $message): void{
    if(!$test)throw new RuntimeException($message);
};
$first=array('type'=>'tabs','id'=>'details','panels'=>array(
    array('id'=>'overview','label'=>'Overview','heading'=>'About the stay','body'=>'Original panel text.'),
    array('id'=>'rooms','label'=>'Rooms','heading'=>'Room details','body'=>'King & twin rooms — à la carte.'),
    array('id'=>'hours','label'=>'Hours','heading'=>'Opening hours','body'=>'All day <local>.'),
));
$second=array('type'=>'tabs','id'=>'neighborhood','panels'=>array_slice($first['panels'],0,2));
$make=static fn(array $widgets): array=>array('version'=>1,'blocks'=>array(array(
    'type'=>'group','id'=>'fixture','children'=>array_merge(array(array('type'=>'heading','text'=>'SyndaMorph Tabs QA')), $widgets),
)));
$artifact=syndamorph_compile_block_plan(wp_json_encode($make(array($first,$second))));
$expect(!is_wp_error($artifact),'Tabs fixture failed compiler: '.(is_wp_error($artifact)?$artifact->get_error_message():''));
$expect($artifact['previewRendering']==='wordpress-core','Tabs need an actual WordPress preview.');
$markup=do_blocks($artifact['content']);
$expect(substr_count($markup,'data-sm-tabs')===2 && str_contains($markup,'All day &lt;local&gt;'),
    'WordPress did not render both independent widgets and escape plain text.');
$bad=$first;
$bad['panels'][1]['id']='overview';
$expect(is_wp_error(syndamorph_compile_block_plan(wp_json_encode($make(array($bad))))),'Duplicate panel ID was accepted.');
$expect(is_wp_error(syndamorph_compile_block_plan(wp_json_encode($make(array($first,$first))))),'Duplicate widget ID was accepted.');

$reference='';
foreach(array($first,$second) as $widget){
    $reference.='<div data-sm-tabs-reference="'.esc_attr($widget['id']).'">';
    foreach($widget['panels'] as $panel){
        $reference.='<section data-sm-tab-panel="'.esc_attr($panel['id']).'"><span data-sm-tab-label>'.esc_html($panel['label']).
            '</span><h3 data-sm-tab-heading>'.esc_html($panel['heading']).'</h3><p data-sm-tab-body>'.esc_html($panel['body']).'</p></section>';
    }
    $reference.='</div>';
}
$interactions=array_map(static fn($id)=>array('id'=>$id,'kind'=>'tabs','classification'=>'reusable'),array('details','neighborhood'));
$interactions[0]['proof']=array(
    array('requirementId'=>'about-click','check'=>'tab-click'),
    array('requirementId'=>'about-a11y','check'=>'tab-accessibility'),
    array('requirementId'=>'about-style','check'=>'tab-style'),
);
$design=syndamorph_tabs_design_copy($reference,$interactions);
$expect($design===$artifact['tabWidgets'],'Approved tab panel identities and copy did not match.');
$widths=array(1440,768,375);
$receipt=syndamorph_tabs_verify($artifact,$widths,$interactions,$design);
$expect($receipt['status']==='passed'&&array_column($receipt['viewports'],'width')===$widths,
    'Chromium functional receipt failed: '.wp_json_encode($receipt));
$expect($receipt['verifiedRequirements']===array('about-click','about-a11y','about-style'),'Tab QA did not record each linked requirement.');
$final=array('artifact'=>$artifact,'quality'=>array('functionalChecks'=>$receipt));
$expect(syndamorph_functional_acceptance($final,hash('sha256',$artifact['content'])),'Verified exact artifact was rejected.');
$expect(!syndamorph_functional_acceptance($final,str_repeat('a',64)),'Changed artifact reused old QA.');
$missing=syndamorph_compile_block_plan(wp_json_encode($make(array())));
$pending=syndamorph_tabs_verify($missing,$widths,$interactions,$design);
$expect($pending['status']==='pending'&&count($pending['blockers'])===2,'Missing required widgets were approved.');
$external=syndamorph_tabs_verify($missing,$widths,array(array('id'=>'booking','kind'=>'booking','classification'=>'external')));
$expect($external['status']==='pending','External service without functional proof was approved.');
$wrong=$design;
$wrong['details'][1]['body']='An unapproved replacement.';
$pending=syndamorph_tabs_verify($artifact,$widths,$interactions,$wrong);
$expect($pending['status']==='pending'&&$pending['blockers'][0]['reason']==='approved_panel_copy_mismatched_or_unavailable',
    'Developer changes to approved inactive panel copy were accepted.');
$tampered=$artifact;
$tampered['preview']=str_replace('role="tabpanel"','role="region"',$artifact['preview']);
$failed=syndamorph_tabs_verify($tampered,$widths,$interactions,$design);
$expect($failed['status']==='failed','Broken functional markup passed QA.');
$tampered=$artifact;
$tampered['preview'].='<style>[role="tab"]:focus-visible{outline:none!important}</style>';
$failed=syndamorph_tabs_verify($tampered,$widths,$interactions,$design);
$expect($failed['status']==='failed'&&$failed['viewports'][0]['checks'][0]['focusVisible']===false,
    'The compiled block passed its accessibility proof without a visible focus indicator.');

$post=wp_insert_post(wp_slash(array('post_type'=>'page','post_status'=>'draft','post_author'=>1,
    'post_title'=>'SyndaMorph Tabs QA','post_name'=>'syndamorph-tabs-qa','post_content'=>$artifact['content'])),true);
if(is_wp_error($post))throw new RuntimeException($post->get_error_message());
echo 'PASS: real WordPress compilation and exact-width Chromium tabs acceptance, with missing, modified and unsupported cases. PHP draft ID '.$post.PHP_EOL;
update_option('syndamorph_test_tabs_post',$post,false);
