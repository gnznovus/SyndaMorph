<?php
/** Browser source evidence for one independently checkpointed native production section. */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/production-reference.php';

function syndamorph_production_source_capture(string $url,?string $sectionId,?array $variants=null) {
    $endpoint=getenv('SYNDAMORPH_BACKEND_URL');
    $token=getenv('SYNDAMORPH_BACKEND_TOKEN');
    if($endpoint!=='http://backend:3100/capture'||!is_string($token)||$token==='')
        return new WP_Error('sm_source_config','Production capture backend is unavailable.',array('status'=>503));
    $parts=wp_parse_url($url);
    $hosts=array_map('trim',explode(',',strtolower((string)getenv('SYNDAMORPH_CAPTURE_HOSTS'))));
    if(strlen($url)>2048 || !in_array(strtolower($parts['scheme']??''),array('http','https'),true) ||
        !in_array(strtolower($parts['host']??''),$hosts,true)||isset($parts['user'])||isset($parts['pass'])||
        ($sectionId!==null&&!preg_match('/^[a-f0-9]{24}$/D',$sectionId)))
        return new WP_Error('sm_source_url','Provide an approved source URL and an optional manifest section ID.',array('status'=>400));
    if($variants!==null){
        if($sectionId!==null || count($variants)!==3) return new WP_Error('sm_source_variants','Provide one captured section ID per viewport.',array('status'=>400));
        foreach(array(1440,768,375) as $width){
            if(!is_string($variants[$width]??null)||!preg_match('/^[a-f0-9]{24}$/D',$variants[$width]))
                return new WP_Error('sm_source_variants','Provide one captured section ID per viewport.',array('status'=>400));
        }
    }
    $response=wp_remote_post('http://backend:3100/production-source',array(
        'headers'=>array('Content-Type'=>'application/json','X-SyndaMorph-Token'=>$token),
        'body'=>wp_json_encode(array('url'=>$url,'sectionId'=>$sectionId,'variants'=>$variants)),
        'timeout'=>120,'redirection'=>0,'limit_response_size'=>2400000));
    $body=is_wp_error($response)?null:json_decode(wp_remote_retrieve_body($response),true);
    if(!is_array($body)||wp_remote_retrieve_response_code($response)!==200||
        ($body['manifest']['version']??null)!==1||($body['manifest']['viewportWidths']??null)!==array(1440,768,375))
        return new WP_Error('sm_source_capture','Responsive capture failed. Inspect the capture backend before starting AI production.',array('status'=>422));
    $manifest=$body['manifest'];
    if($sectionId===null&&$variants===null)return array('manifest'=>$manifest);
    $source=array('url'=>$manifest['sourceURL']??null,'manifestSha256'=>$manifest['sha256']??null,'section'=>$body['section']??null);
    if(getenv('SYNDAMORPH_REFERENCE_ARCHIVE')==='1'&&!array_key_exists('referenceArchive',$body))
        return new WP_Error('sm_source_reference','The capture service did not supply a verified reference archive.',array('status'=>422));
    if(array_key_exists('referenceArchive',$body))$source['referenceArchive']=$body['referenceArchive'];
    if(!syndamorph_production_source_valid($source)||
        ($sectionId!==null&&$source['section']['id']!==$sectionId)||
        ($variants!==null&&array_column($source['section']['evidence'],'sectionId','width')!=$variants))
        return new WP_Error('sm_source_evidence','Selected section lacks complete measured source evidence.',array('status'=>422));
    $handle=bin2hex(random_bytes(16));
    if(!set_transient('sm_source_'.$handle,array('user'=>get_current_user_id(),'source'=>$source),HOUR_IN_SECONDS))
        return new WP_Error('sm_source_persist','Could not checkpoint the capture.',array('status'=>503));
    return array('manifest'=>$manifest,'section'=>syndamorph_production_source_identity($source),'sourceCapture'=>$handle);
}

function syndamorph_production_source_valid(array $source): bool {
    if(!is_string($source['url']??null)||!is_string($source['manifestSha256']??null)||
        !preg_match('/^[a-f0-9]{64}$/D',$source['manifestSha256'])||!is_array($source['section']??null))return false;
    if(array_key_exists('referenceArchive',$source) &&
        !syndamorph_production_reference_valid($source['referenceArchive'],$source['url']))return false;
    $section=$source['section'];
    if(!is_string($section['id']??null)||!preg_match('/^[a-f0-9]{24}$/D',$section['id'])||
        !is_string($section['sourceRef']??null)||!is_string($section['label']??null)||
        !is_string($section['evidenceSha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$section['evidenceSha256'])||
        !is_array($section['evidence']??null)||array_column($section['evidence'],'width')!==array(1440,768,375))return false;
    foreach($section['evidence'] as $view){
        $jpeg=$view['screenshot']??null;
        if(!is_string($jpeg)||strlen($jpeg)>400000||!str_starts_with($jpeg,'/9j/')||
            !preg_match('~^[A-Za-z0-9+/]+={0,2}$~D',$jpeg)||!is_string($view['copy']??null)||
            !is_array($view['bounds']??null)||count($view['bounds'])!==4||
            !is_array($view['links']??null)||!is_array($view['images']??null)||!is_array($view['controls']??null)||
            !is_array($view['backgrounds']??null))return false;
    }
    return true;
}

function syndamorph_production_source_load($handle,string $format) {
    if($handle===null)return null;
    $saved=is_string($handle)&&preg_match('/^[a-f0-9]{32}$/D',$handle)?get_transient('sm_source_'.$handle):null;
    if($format!=='gutenberg'||!is_array($saved)||$saved['user']!==get_current_user_id()||
        !is_array($saved['source']??null)||!syndamorph_production_source_valid($saved['source']))
        return new WP_Error('sm_source_missing','Select a fresh production source capture for native Gutenberg.',array('status'=>409));
    $views=$saved['source']['section']['evidence'];
    if(count(array_unique(array_column($views,'copy')))!==1||array_filter(array_column($views,'copyTruncated')))
        return new WP_Error('sm_source_responsive_copy',
            'Selected viewport variants have different or incomplete copy. Select equivalent content or use a verified responsive content implementation.',
            array('status'=>409));
    return $saved['source'];
}

function syndamorph_production_source_identity(array $source): array {
    return array('url'=>$source['url'],'manifestSha256'=>$source['manifestSha256'])+
        array_intersect_key($source['section'],array_flip(array('id','sourceRef','label','kind','evidenceSha256')));
}

function syndamorph_production_source_context(array $source): array {
    $facts=array();
    foreach($source['section']['evidence'] as $view){
        unset($view['screenshot']);
        foreach(array('images','links','backgrounds','controls') as $list){
            $view[$list]=array_slice($view[$list],0,12);
            $view[$list]=array_map(static function($item){
                if(is_string($item))return substr($item,0,350);
                if(is_array($item)){
                    if(isset($item['strip']['cardCopy']))unset($item['strip']['cardCopy']);
                    foreach(array('url','text','alt','label') as $key)if(is_string($item[$key]??null))
                        $item[$key]=mb_substr($item[$key],0,350);
                }
                return $item;
            },$view[$list]);
        }
        $facts[]=$view;
    }
    return array('identity'=>syndamorph_production_source_identity($source),'views'=>$facts)+
        array_intersect_key($source,array('referenceArchive'=>true,'layoutAuthorization'=>true));
}

function syndamorph_production_source_copy(array $source,array $native): array {
    $widths=array_column($native,'width');
    $record=array();
    foreach($source['section']['evidence'] as $view){
        if(!in_array($view['width'],$widths,true))continue;
        $record[]=array('width'=>$view['width'],'snapshot'=>array(
            'copy'=>!empty($view['copyTruncated'])?null:$view['copy']));
    }
    return syndamorph_visual_copy($record,array_values(array_filter($native,
        static fn($view)=>in_array($view['width'],array(1440,768,375),true))));
}

function syndamorph_production_source_visual(array $source,array $candidate) {
    $evidence=$source['section']['evidence'];
    $candidate=array_values(array_filter($candidate,static fn($view)=>in_array($view['width'],array(1440,768,375),true)));
    $screens=syndamorph_collab_compare($evidence,$candidate);
    if(is_wp_error($screens))return $screens;
    $ratios=array_column($screens,'pixelDifferenceRatio');
    return array('passed'=>count($ratios)===3&&syndamorph_visual_pixels_within_limits($ratios),'views'=>$screens);
}

function syndamorph_production_source_assess(array $source,array $native): array {
    $evidence=$source['section']['evidence'];
    $visual=syndamorph_production_source_visual($source,$native);
    $copy=syndamorph_production_source_copy($source,$native);
    $unsupported=array();
    foreach($evidence as $view){
        if($view['controls'])$unsupported['interactive']='Source controls need a verified native or custom implementation.';
        if($view['images']||$view['backgrounds'])$unsupported['assets']='Source imagery needs a verified native asset path.';
        if($view['links'])$unsupported['links']='Source links need independent destination verification.';
        if(($view['bounds'][3]??0)>900)$unsupported['coverage']='The source section exceeds the 900 px comparison frame.';
    }
    $issues=array();
    if($copy['status']!=='equal')$issues[]=array('id'=>'source-literal-copy','detail'=>'Literal source copy could not be verified.','severity'=>'high');
    foreach($unsupported as $id=>$detail)$issues[]=array('id'=>'source-'.$id,'detail'=>$detail,'severity'=>'high');
    if(is_wp_error($visual)||!$visual['passed'])
        $issues[]=array('id'=>'source-visual-fidelity','severity'=>'high','detail'=>'The native artifact exceeds source screenshot tolerances or lacks complete evidence.');
    return array('identity'=>syndamorph_production_source_identity($source),'literalCopy'=>$copy,
        'pixels'=>is_wp_error($visual)?array():$visual['views'],'issues'=>$issues);
}

add_action('rest_api_init',static function():void {
    register_rest_route('syndamorph/v1','/production-source',array(
        'methods'=>'POST','permission_callback'=>static function():bool{return current_user_can('manage_options');},
        'args'=>array('url'=>array('type'=>'string','required'=>true),'sectionId'=>array('type'=>'string'),
            'variants'=>array('type'=>'object','additionalProperties'=>array('type'=>'string'))),
        'callback'=>static function(WP_REST_Request $request){
            $captured=syndamorph_production_source_capture((string)$request->get_param('url'),$request->get_param('sectionId'),$request->get_param('variants'));
            return is_wp_error($captured)?$captured:rest_ensure_response($captured);
        },
    ));
});
