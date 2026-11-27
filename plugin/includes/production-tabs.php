<?php
/** Bounded editable, local-only tabbed content for the native production engine. */
if (!defined('ABSPATH')) exit;

function syndamorph_tabs_proof_contract(): array {
    return array('tab-block'=>'implementation','tab-initial'=>'functional','tab-click'=>'functional',
        'tab-keyboard'=>'functional','tab-style'=>'visual','tab-accessibility'=>'accessibility');
}

function syndamorph_tabs_panels($panels): ?array {
    if(!is_array($panels)||!array_is_list($panels)||count($panels)<2||count($panels)>6)return null;
    $result=array();
    foreach($panels as $panel){
        if(!is_array($panel)||array_diff(array_keys($panel),array('id','label','heading','body'))||
            !is_string($panel['id']??null)||preg_match('/^[a-z][a-z0-9-]{0,31}$/D',$panel['id'])!==1||
            isset($result[$panel['id']]))return null;
        $limits=array('label'=>80,'heading'=>160,'body'=>1600);
        $normalized=array('id'=>$panel['id']);
        foreach($limits as $field=>$limit){
            if(!is_string($panel[$field]??null)||trim($panel[$field])===''||mb_strlen(trim($panel[$field]))>$limit)return null;
            $normalized[$field]=trim($panel[$field]);
        }
        $result[$panel['id']]=$normalized;
    }
    return array_values($result);
}

function syndamorph_tabs_render(array $attributes): string {
    $panels=syndamorph_tabs_decode($attributes['panelData']??null);
    if($panels===null)return '';
    $escape=static fn(string $text): string => htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $component=$attributes['component']??'';
    static $fallback=0;
    $prefix=function_exists('wp_unique_id')?wp_unique_id('sm-tabs-'):'sm-tabs-'.(++$fallback);
    $appearance=($attributes['appearance']??null)==='standard'?' sm-tabs-standard':'';
    $markup='<div class="wp-block-syndamorph-tabs'.$appearance.'" data-sm-tabs'.
        (is_string($component)&&$component!==''?' data-sm-node="'.$escape($component).'"':'').'>';
    $markup.='<div class="sm-tabs-list" role="tablist" aria-label="Content tabs">';
    foreach($panels as $index=>$panel){
        $tab=$prefix.'-'.$panel['id'];
        $markup.='<button type="button" id="'.$escape($tab).'" role="tab" aria-controls="'.$escape($tab.'-panel').'"'.
            ' aria-selected="'.($index===0?'true':'false').'" tabindex="'.($index===0?'0':'-1').'">'.
            $escape($panel['label']).'</button>';
    }
    $markup.='</div>';
    foreach($panels as $index=>$panel){
        $tab=$prefix.'-'.$panel['id'];
        $markup.='<div class="sm-tabs-panel" id="'.$escape($tab.'-panel').'" role="tabpanel" aria-labelledby="'.
            $escape($tab).'" tabindex="0"'.($index===0?'':' hidden').'><h3>'.$escape($panel['heading']).
            '</h3><p>'.$escape($panel['body']).'</p></div>';
    }
    return $markup.'</div>';
}

/** Use a plain-text transport that WordPress's HTML attribute filter preserves. */
function syndamorph_tabs_decode($encoded): ?array {
    if(!is_string($encoded)||strlen($encoded)>64000)return null;
    $json=base64_decode($encoded,true);
    return $json===false?null:syndamorph_tabs_panels(json_decode($json,true,8));
}

function syndamorph_tabs_asset(string $name): string {
    $path=dirname(__DIR__).'/assets/'.$name;
    $content=is_readable($path)?file_get_contents($path):false;
    return is_string($content)?$content:'';
}

/** Apply the same built-in CSS to one already identified design component. */
function syndamorph_tabs_standard_reference(string $reference,string $id) {
    if(!preg_match('/^[a-z][a-z0-9-]{0,39}$/D',$id)||!class_exists('DOMDocument'))
        return new WP_Error('sm_tabs_reference','An identified Tabs reference is required.');
    $source=array(array('id'=>$id,'kind'=>'tabs'));
    $copy=syndamorph_tabs_design_copy($reference,$source)[$id]??null;
    $document=new DOMDocument();
    if($copy===null||!@$document->loadHTML('<meta charset="utf-8">'.$reference,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))
        return new WP_Error('sm_tabs_reference','The identified Tabs design copy is unavailable.');
    $xpath=new DOMXPath($document);
    $widget='//*[@data-sm-tabs-reference="'.$id.'"]';
    $tabs=$xpath->query($widget.'/*[@role="tablist"]/button[@role="tab"]');
    $panels=$xpath->query($widget.'/*[@data-sm-tab-panel and @role="tabpanel"]');
    if($tabs->length!==count($copy)||$panels->length!==count($copy))
        return new WP_Error('sm_tabs_reference','The Tabs reference needs direct tab controls and panels.');
    foreach($copy as $index=>$content){
        $tab=$tabs->item($index);$panel=$panels->item($index);
        $selected=$index===0;
        if($tab->getAttribute('aria-controls')!==$content['id']||$panel->getAttribute('id')!==$content['id']||
            $tab->getAttribute('id')===''||$panel->getAttribute('aria-labelledby')!==$tab->getAttribute('id')||
            trim($tab->textContent)!==$content['label']||$panel->hasAttribute('hidden')===$selected||
            $tab->getAttribute('aria-selected')!==($selected?'true':'false'))
            return new WP_Error('sm_tabs_reference','The Tabs reference controls or default panel do not match the identified design copy.');
    }
    $asset=syndamorph_tabs_asset('production-tabs.css');
    if($asset==='')return new WP_Error('sm_tabs_reference','Built-in Tabs styling is unavailable.');
    $selector='[data-sm-tabs-reference="'.$id.'"]';
    $css=str_replace(array('.wp-block-syndamorph-tabs.sm-tabs-standard','.wp-block-syndamorph-tabs','.sm-tabs-list','.sm-tabs-panel'),
        array($selector,$selector,'[role="tablist"]','[data-sm-tab-panel]'),$asset);
    $style='<style data-sm-tabs-style="'.$id.'">'.PHP_EOL.$css.'</style>';
    if(substr_count($reference,$style)===1)return $reference;
    if(str_contains($reference,'data-sm-tabs-style="'.$id.'"')||strlen($reference)+strlen($style)+1>120000)
        return new WP_Error('sm_tabs_reference','The Tabs style change exceeds the identified design scope.');
    return $reference.PHP_EOL.$style;
}

function syndamorph_tabs_design_copy(string $html,array $interactions): array {
    $tabIds=array_column(array_filter($interactions,static fn($item)=>$item['kind']==='tabs'),'id');
    if(!$tabIds||!class_exists('DOMDocument'))return array();
    $document=new DOMDocument();
    if(!@$document->loadHTML('<meta charset="utf-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))
        return array();
    $xpath=new DOMXPath($document);
    $copy=array();
    foreach($tabIds as $id){
        $widget=$xpath->query('//*[@data-sm-tabs-reference="'.$id.'"]');
        if($widget->length!==1)continue;
        $panels=array();
        foreach($xpath->query('./*[@data-sm-tab-panel]',$widget->item(0)) as $panel){
            $fields=array('id'=>$panel->getAttribute('data-sm-tab-panel'));
            foreach(array('label','heading','body') as $field){
                $nodes=$xpath->query('./*[@data-sm-tab-'.$field.']',$panel);
                $fields[$field]=$nodes->length===1?trim($nodes->item(0)->textContent):'';
            }
            if($fields['label']===''){
                // The visible tab can supply the panel label through reciprocal ARIA references.
                $labelId=$panel->getAttribute('aria-labelledby');
                $panelId=$panel->getAttribute('id');
                if($panelId===$fields['id']&&preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$labelId)===1){
                    $label=$xpath->query('./*[@role="tablist"]/*[@role="tab" and @id="'.$labelId.'"]',$widget->item(0));
                    if($label->length===1&&$label->item(0)->getAttribute('aria-controls')===$panelId)
                        $fields['label']=trim($label->item(0)->textContent);
                }
            }
            $panels[]=$fields;
        }
        $verified=syndamorph_tabs_panels($panels);
        if($verified!==null)$copy[$id]=$verified;
    }
    return $copy;
}

function syndamorph_tabs_verify(array $artifact,array $widths,array $interactions=array(),array $designTabs=array(),
    array $nativeChecks=array()): array {
    $expected=$artifact['tabWidgets']??array();
    $strips=$artifact['scrollStrips']??array();
    $disclosures=$artifact['disclosureGroups']??array();
    if(!$expected&&!$interactions&&!$strips&&!$disclosures)
        return array('status'=>'not_applicable','viewports'=>array());
    $receipt=array('artifactSha256'=>hash('sha256',$artifact['content']),'viewports'=>array(),
        'requestedWidths'=>$widths,'status'=>'pending','blockers'=>array(),'nativeChecks'=>$nativeChecks);
    $planned=array();$plannedStrips=array();$plannedDisclosures=array();$nativeFailed=false;
    foreach($interactions as $interaction){
        if($interaction['kind']==='tabs' && in_array($interaction['classification'],array('reusable','new_special'),true))
            $planned[$interaction['id']]=true;
        elseif($interaction['kind']==='horizontal-scroll'&&$interaction['classification']==='native'){
            $id=$interaction['id'];$plannedStrips[$id]=true;
            $nativeFailed=$nativeFailed||(($nativeChecks[$id]['status']??null)==='failed');
            if(!isset($strips[$id])||($nativeChecks[$id]['status']??null)!=='verified')
                $receipt['blockers'][]=array('id'=>$id,'reason'=>'independent_scroll_proof_missing_or_failed');
        }
        elseif($interaction['kind']==='disclosure-group'&&$interaction['classification']==='native'){
            $id=$interaction['id'];$plannedDisclosures[$id]=true;
            $nativeFailed=$nativeFailed||(($nativeChecks[$id]['status']??null)==='failed');
            if(!isset($disclosures[$id])||($nativeChecks[$id]['status']??null)!=='verified')
                $receipt['blockers'][]=array('id'=>$id,'reason'=>'independent_disclosure_proof_missing_or_failed');
        }
        elseif(($nativeChecks[$interaction['id']]['status']??null)!=='verified')
            $receipt['blockers'][]=array('id'=>$interaction['id'],'reason'=>'independent_functional_test_unavailable');
    }
    foreach(array_diff_key($strips,$plannedStrips) as $id=>$_)
        $receipt['blockers'][]=array('id'=>$id,'reason'=>'scroll_strip_not_in_specification');
    foreach(array_diff_key($disclosures,$plannedDisclosures) as $id=>$_)
        $receipt['blockers'][]=array('id'=>$id,'reason'=>'disclosure_group_not_in_specification');
    foreach(array_diff_key($planned,$expected) as $id=>$_)
        $receipt['blockers'][]=array('id'=>$id,'reason'=>'implementation_missing');
    foreach(array_diff_key($expected,$planned) as $id=>$_)
        $receipt['blockers'][]=array('id'=>$id,'reason'=>'interaction_not_in_specification');
    foreach(array_intersect_key($expected,$planned) as $id=>$panels){
        if(($designTabs[$id]??null)!==$panels)
            $receipt['blockers'][]=array('id'=>$id,'reason'=>'approved_panel_copy_mismatched_or_unavailable');
    }
    if(!$expected){
        if($nativeFailed)$receipt['status']='failed';
        elseif(!$receipt['blockers'])$receipt['status']='passed';
        return $receipt;
    }
    $script=syndamorph_tabs_asset('production-tabs-view.js');
    if(!syndamorph_internal_configured('renderer')||$script===''){
        $receipt['blockers'][]=array('reason'=>'functional_renderer_unavailable','widths'=>$widths);
        return $receipt;
    }
    $frame=syndamorph_production_font_frame($artifact['preview']);
    if(is_wp_error($frame)){
        $receipt['blockers'][]=array('reason'=>'functional_fonts_unavailable','widths'=>$widths);
        return $receipt;
    }
    $receipt['runtimeSha256']=hash('sha256',$script);
    $passed=!$nativeFailed;
    foreach(array_chunk($widths,5) as $batch){
        $payload=wp_json_encode(array('html'=>$frame['html'],'runtime'=>$script,'viewports'=>$batch),JSON_UNESCAPED_SLASHES);
        $response=syndamorph_internal_request('renderer','POST','/check-tabs',array('timeout'=>65,'redirection'=>0,
            'limit_response_size'=>64000,'headers'=>array('Content-Type'=>'application/json'),'body'=>$payload));
        $status=is_wp_error($response)?null:wp_remote_retrieve_response_code($response);
        $data=$status===200?json_decode(wp_remote_retrieve_body($response),true):null;
        if(!is_array($data['evidence']??null)||array_column($data['evidence'],'width')!==$batch){
            $receipt['blockers'][]=array('reason'=>'functional_evidence_incomplete','widths'=>$batch,'httpStatus'=>$status);
            continue;
        }
        foreach($data['evidence'] as $row){
            $checks=is_array($row['checks']??null)?$row['checks']:array();
            $valid=array_column($checks,'component')===array_keys($expected);
            foreach($checks as &$check){
                $panels=$expected[$check['component']??'']??array();
                $copy=array_map(static fn($panel)=>array_intersect_key($panel,array_flip(array('label','heading','body'))),$panels);
                $check['contentMatches']=($check['copy']??null)===$copy;
                unset($check['copy']);
                $valid=$valid&&$check['contentMatches']&&($check['initial']??null)===true&&($check['structure']??null)===true&&
                    ($check['click']??null)===true&&($check['keyboard']??null)===true&&($check['isolation']??null)===true&&
                    ($check['focusVisible']??null)===true&&($check['nativeStyle']??null)===true;
            }
            unset($check);
            $passed=$passed&&$valid;
            $receipt['viewports'][]=array('width'=>$row['width'],'passed'=>$valid,'checks'=>$checks);
        }
    }
    if(!$passed)$receipt['status']='failed';
    elseif(!$receipt['blockers']&&array_column($receipt['viewports'],'width')===$widths){
        $receipt['status']='passed';
        $receipt['verifiedRequirements']=array();
        foreach($interactions as $interaction)
            foreach($interaction['proof']??array() as $proof)$receipt['verifiedRequirements'][]=$proof['requirementId'];
    }
    return $receipt;
}

function syndamorph_functional_acceptance(array $final,string $checksum): bool {
    // Booking needs an archive-bound verifier before automated placement can admit it.
    if(str_contains($final['artifact']['content']??'','<!-- wp:syndamorph/booking-summary'))return false;
    $receipt=$final['quality']['functionalChecks']??null;
    if($receipt===null||($receipt['status']??null)==='not_applicable'){
        return !str_contains($final['artifact']['content']??'','<!-- wp:syndamorph/tabs ')&&
            !str_contains($final['artifact']['content']??'',' sm-scroll-strip')&&
            !str_contains($final['artifact']['content']??'','"className":"sm-disclosure-group ')&&
            empty($final['designApproval']['productionSpecification']['interactions']);
    }
    return ($receipt['status']??null)==='passed'&&($receipt['artifactSha256']??null)===$checksum;
}

function syndamorph_tabs_register(): void {
    $plugin=dirname(__DIR__).'/syndamorph.php';
    $url=static fn(string $name): string => plugins_url('assets/'.$name,$plugin);
    $version=static fn(string $name): string => (string)filemtime(dirname(__DIR__).'/assets/'.$name);
    wp_register_script('syndamorph-tabs-editor',$url('production-tabs-editor.js'),
        array('wp-blocks','wp-element','wp-components','wp-block-editor'),$version('production-tabs-editor.js'),true);
    wp_register_script('syndamorph-tabs-view',$url('production-tabs-view.js'),array(),$version('production-tabs-view.js'),true);
    wp_register_style('syndamorph-tabs',$url('production-tabs.css'),array(),$version('production-tabs.css'));
    register_block_type('syndamorph/tabs',array(
        'api_version'=>3,'title'=>'Content Tabs','category'=>'design','supports'=>array('html'=>false),
        'attributes'=>array('panelData'=>array('type'=>'string','default'=>''),'component'=>array('type'=>'string'),
            'appearance'=>array('type'=>'string','enum'=>array('standard'))),
        'editor_script'=>'syndamorph-tabs-editor','view_script'=>'syndamorph-tabs-view','style'=>'syndamorph-tabs',
        'render_callback'=>'syndamorph_tabs_render',
    ));
}

if(function_exists('add_action'))add_action('init','syndamorph_tabs_register');
