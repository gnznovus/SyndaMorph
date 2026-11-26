<?php
/** Recorded proof for native text, one link, and a PNG visible at selected widths. */
if (!defined('ABSPATH')) exit;

function syndamorph_linked_image_inventory(array $source,string $sourceUrl): ?array {
    $origin=wp_parse_url($sourceUrl);
    $link=null;$asset=null;$widths=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $variant=$source['variants'][$width]??array();
        $inventory=$variant['inventory']??array();
        $assets=$inventory['assets']??null;
        $candidate=$inventory['links'][0]??null;
        $href=$candidate['href']??null;
        $text=$candidate['text']??null;
        $address=is_string($href)?wp_parse_url($href):null;
        if(!is_array($assets)||!array_is_list($assets)||count($assets)>1||
            ($inventory['controls']??null)!==array()||($inventory['links']??null)!==array($candidate)||
            !is_array($inventory['text']??null)||count($inventory['text'])<2||
            in_array($inventory['position']??null,array('fixed','sticky'),true)||
            ($variant['kind']??null)!=='content'||
            !preg_match('/^(?:[a-z][a-z0-9-]{0,20})?\[(?:data-dc-tpl|id)="[a-zA-Z0-9_-]{1,64}"\]$/D',$variant['sourceRef']??'')||
            !is_string($text)||trim($text)===''||mb_strlen($text)>240||($address['scheme']??null)!=='https'||
            ($origin['scheme']??null)!=='https'||empty($address['host'])||strcasecmp($address['host'],$origin['host']??'')!==0||
            ($address['port']??443)!==($origin['port']??443)||isset($address['user'])||isset($address['pass'])||
            isset($address['fragment'])||strlen($href)>2048||$href===$sourceUrl||
            ($link!==null&&$link!==$candidate))return null;
        $link=$candidate;
        if(!$assets)continue;
        $image=$assets[0];$url=$image['url']??null;$alt=$image['alt']??null;
        $imageAddress=is_string($url)?wp_parse_url($url):null;
        if(($image['type']??null)!=='image'||($image['loaded']??null)!==true||($imageAddress['scheme']??null)!=='https'||
            empty($imageAddress['host'])||isset($imageAddress['user'])||isset($imageAddress['pass'])||
            isset($imageAddress['fragment'])||strlen($url)>2048||!is_string($alt)||trim($alt)===''||mb_strlen($alt)>240||
            ($asset!==null&&$asset!==array('url'=>$url,'alt'=>$alt)))return null;
        $asset=array('url'=>$url,'alt'=>$alt);
        $widths[]=$width;
    }
    return $asset===null?null:array('link'=>$link,'asset'=>$asset,'assetWidths'=>$widths);
}

function syndamorph_linked_image_replay(array $source,array $archive) {
    $fail=static fn(string $message,array $data=array())=>new WP_Error('sm_source_linked_image_evidence',$message,$data+array('status'=>409));
    $profile=syndamorph_linked_image_inventory($source,$archive['sourceUrl']??'');
    $token=getenv('SYNDAMORPH_BACKEND_TOKEN');
    if(!$profile||!syndamorph_production_reference_valid($archive,$archive['sourceUrl']??'')||
        empty($archive['runtimeSha256'])||getenv('SYNDAMORPH_BACKEND_URL')!=='http://backend:3100/capture'||
        !is_string($token)||$token==='')return $fail('A verified recorded source with an exact image and link is required.');
    $copy=array_column($archive['views'],'copySha256','width');
    $asset=null;$raw=null;$views=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $ref=$source['variants'][$width]['sourceRef'];
        $withImage=in_array($width,$profile['assetWidths'],true);
        $response=wp_remote_post('http://backend:3100/production-touch',array('timeout'=>45,'redirection'=>0,
            'limit_response_size'=>$withImage?710000:16000,
            'headers'=>array('Content-Type'=>'application/json','X-SyndaMorph-Token'=>$token),
            'body'=>wp_json_encode(array('archiveId'=>$archive['id'],'kind'=>'linked-image','width'=>$width,'sourceRef'=>$ref))));
        $view=is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200?null:
            json_decode(wp_remote_retrieve_body($response),true);
        if(($view['version']??null)!==1||($view['archiveId']??null)!==$archive['id']||($view['mode']??null)!=='recorded'||
            ($view['width']??null)!==$width||($view['sourceRef']??null)!==$ref||($view['status']??null)!=='observed'||
            ($view['sourceCopySha256']??null)!==$copy[$width]||($view['blockedRequestCount']??null)!==0||
            ($view['destinationStatus']??null)!=='declared'||($view['link']??null)!==$profile['link']||
            !array_key_exists('image',$view)||$withImage!==is_array($view['image'])){
            $reason=$view['reason']??'receipt_mismatch';
            return $fail('Recorded image and link evidence is incomplete.',array('width'=>$width,
                'reason'=>is_string($reason)&&preg_match('/^[a-z_]{1,64}$/D',$reason)?$reason:'receipt_mismatch'));
        }
        if($withImage){
            $metadata=$view['asset']??array();$image=$view['image'];$binary=syndamorph_archived_png_bytes($view);
            $box=$image['box']??null;
            if($binary===null||($image['url']??null)!==$profile['asset']['url']||
                ($image['alt']??null)!==$profile['asset']['alt']||($image['pixels']??null)!==($metadata['pixels']??null)||
                !is_array($box)||!array_is_list($box)||count($box)!==4||
                array_filter($box,static fn($value)=>(!is_int($value)&&!is_float($value))||!is_finite((float)$value)||abs($value)>8192)||
                $box[2]<=0||$box[3]<=0||($asset!==null&&$asset!==$metadata))
                return $fail('The original PNG or its measured placement did not match.',array('width'=>$width));
            $asset=$metadata;$raw=$binary;
        }elseif($view['image']!==null||isset($view['asset'])||isset($view['assetBase64'])){
            return $fail('The source unexpectedly rendered an image.',array('width'=>$width));
        }
        unset($view['assetBase64'],$view['asset']);
        $views[$width]=$view;
    }
    return array('asset'=>$asset+$profile['asset'],'raw'=>$raw,'link'=>$profile['link'],'views'=>$views);
}
