<?php
/** Source and Media Library evidence for a section that consists of one image. */
if (!defined('ABSPATH')) exit;

function syndamorph_image_strip_inventory(array $source): ?array {
    $expected=null;
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $variant=$source['variants'][$width]??array();
        $inventory=$variant['inventory']??array();
        $assets=$inventory['assets']??null;
        $controls=$inventory['controls']??null;
        if(!is_array($assets)||!array_is_list($assets)||count($assets)!==1||
            !is_array($controls)||!array_is_list($controls)||count($controls)>1||
            ($controls&&(($controls[0]['type']??null)!=='horizontal-scroll'||isset($controls[0]['strip'])))||
            ($inventory['text']??null)!==array()||($inventory['links']??null)!==array()||
            !preg_match('/^(?:[a-z][a-z0-9-]{0,20})?\[(?:data-dc-tpl|id)="[a-zA-Z0-9_-]{1,64}"\]$/D',$variant['sourceRef']??''))
            return null;
        $asset=$assets[0];
        $url=$asset['url']??null;$alt=$asset['alt']??null;
        $address=is_string($url)?wp_parse_url($url):null;
        if(($asset['type']??null)!=='image'||($asset['loaded']??null)!==true||
            ($address['scheme']??null)!=='https'||empty($address['host'])||isset($address['user'])||
            isset($address['pass'])||isset($address['fragment'])||strlen($url)>2048||
            !is_string($alt)||trim($alt)===''||mb_strlen($alt)>240)
            return null;
        $entry=array('url'=>$url,'alt'=>$alt);
        if($expected!==null&&$entry!==$expected)return null;
        $expected=$entry;
    }
    return $expected;
}

function syndamorph_image_strip_replay(array $source,array $archive) {
    $fail=static fn(string $reason,array $data=array())=>new WP_Error('sm_source_image_evidence',$reason,$data);
    $expected=syndamorph_image_strip_inventory($source);
    $token=getenv('SYNDAMORPH_BACKEND_TOKEN');
    if(!$expected||!syndamorph_production_reference_valid($archive,$archive['sourceUrl']??'')||
        empty($archive['runtimeSha256'])||getenv('SYNDAMORPH_BACKEND_URL')!=='http://backend:3100/capture'||
        !is_string($token)||$token==='')return $fail('A verified recorded image source is required.');
    $copy=array_column($archive['views'],'copySha256','width');
    $asset=null;$raw=null;$views=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $ref=$source['variants'][$width]['sourceRef'];
        $response=wp_remote_post('http://backend:3100/production-touch',array('timeout'=>45,'redirection'=>0,
            'limit_response_size'=>$width===1440?710000:16000,
            'headers'=>array('Content-Type'=>'application/json','X-SyndaMorph-Token'=>$token),
            'body'=>wp_json_encode(array('archiveId'=>$archive['id'],'kind'=>'single-image-strip','width'=>$width,'sourceRef'=>$ref))));
        $view=is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200?null:
            json_decode(wp_remote_retrieve_body($response),true);
        $state=$view['initial']??array();
        $metadata=$view['asset']??array();
        $bounded=static fn($value):bool=>(is_int($value)||is_float($value))&&is_finite((float)$value)&&
            $value>=0&&$value<=8192;
        if(($view['version']??null)!==1||($view['archiveId']??null)!==$archive['id']||
            ($view['mode']??null)!=='recorded'||($view['width']??null)!==$width||($view['sourceRef']??null)!==$ref||
            ($view['status']??null)!=='observed'||($view['blockedRequestCount']??null)!==0||
            ($view['keyboard']??null)!=='unverified'||($view['sourceCopySha256']??null)!==$copy[$width]||
            ($state['url']??null)!==$expected['url']||($state['alt']??null)!==$expected['alt']||
            ($state['width']??null)!==$width||($state['offset']??null)!==0||
            !is_int($state['maximum']??null)||$state['maximum']<0||$state['maximum']>1900||
            ($view['finalOffset']??null)!==$state['maximum']||
            ($state['maximum']>0&&!in_array($state['overflow']??null,array('auto','scroll'),true))||
            !$bounded($state['height']??null)||abs($state['height']-($source['variants'][$width]['bounds']['height']??0))>1||
            !is_array($state['imageBox']??null)||!array_is_list($state['imageBox'])||count($state['imageBox'])!==4||
            count(array_filter($state['imageBox'],$bounded))!==4||$state['imageBox'][2]<=0||$state['imageBox'][3]<=0||
            !preg_match('/^[a-f0-9]{64}$/D',$metadata['sha256']??'')||($metadata['mime']??null)!=='image/png'||
            !is_int($metadata['bytes']??null)||$metadata['bytes']<33||$metadata['bytes']>524288||
            ($state['pixels']??null)!==($metadata['pixels']??null)||($asset!==null&&$asset!==$metadata)){
            $reason=$view['reason']??'receipt_mismatch';
            return $fail('Recorded image proof is incomplete.',array('width'=>$width,
                'reason'=>is_string($reason)&&preg_match('/^[a-z_]{1,64}$/D',$reason)?$reason:'receipt_mismatch'));
        }
        if($width===1440){
            $binary=$view['assetBase64']??null;
            $raw=is_string($binary)?base64_decode($binary,true):false;
            $info=is_string($raw)&&strlen($raw)===$metadata['bytes']?@getimagesizefromstring($raw):false;
            if(!$info||($info['mime']??null)!==$metadata['mime']||
                array($info[0],$info[1])!==($metadata['pixels']??null)||hash('sha256',$raw)!==$metadata['sha256'])
                return $fail('Archived PNG bytes do not match their evidence receipt.',array('width'=>$width));
            $asset=$metadata;
        }
        unset($view['assetBase64'],$view['asset']);
        $views[$width]=$view;
    }
    return array('asset'=>$asset+$expected,'raw'=>$raw,'views'=>$views);
}
