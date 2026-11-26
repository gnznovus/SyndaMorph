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

/** This layout has one editable image and local, non-snapping compact overflow. */
function syndamorph_image_strip_css(): string {
    return '.wp-block-group.sm-source-image-strip{display:block;box-sizing:border-box;width:100%;max-width:100%;margin:0;background:#fff;padding:56px 60px 64px}'.
        '.sm-source-image-strip>.wp-block-image{display:block;max-width:none;margin:0}'.
        '.sm-source-image-strip>.wp-block-image>img{display:block;width:100%;height:auto;max-width:100%}'.
        '.wp-block-group.sm-source-image-strip:focus-visible{outline:2px solid #333;outline-offset:-2px}'.
        '@media(max-width:1023px){.wp-block-group.sm-source-image-strip{overflow-x:auto;scrollbar-width:none;padding:40px 0 48px 20px}'.
        '.sm-source-image-strip>.wp-block-image{width:max-content}'.
        '.sm-source-image-strip>.wp-block-image>img{box-sizing:content-box;width:auto;max-width:none;height:180px;padding-right:20px}}';
}

if(function_exists('add_action')){
    add_action('enqueue_block_assets',static function():void {
        wp_register_style('syndamorph-source-image-strip',false,array(),null);
        wp_enqueue_style('syndamorph-source-image-strip');
        wp_add_inline_style('syndamorph-source-image-strip',syndamorph_image_strip_css());
    });
    add_filter('render_block_core/group',static function(string $html,array $block):string {
        if(($block['attrs']['className']??null)!=='sm-source-image-strip')return $html;
        $tags=new WP_HTML_Tag_Processor($html);
        if($tags->next_tag()&&$tags->has_class('sm-source-image-strip')){
            $tags->set_attribute('tabindex','0');
            $tags->set_attribute('role','group');
            $tags->set_attribute('aria-label',__('Scrollable artwork','syndamorph'));
        }
        return $tags->get_updated_html();
    },10,2);
    add_filter('render_block_core/image',static function(string $html,array $block):string {
        if(($block['attrs']['className']??null)!=='sm-source-strip-image')return $html;
        $id=$block['attrs']['id']??null;
        $hash=is_int($id)?get_post_meta($id,'_syndamorph_source_strip_sha256',true):null;
        $tags=new WP_HTML_Tag_Processor($html);
        if(is_string($hash)&&preg_match('/^[a-f0-9]{64}$/D',$hash)&&
            $tags->next_tag('IMG')&&$tags->get_attribute('src')===wp_get_attachment_url($id)){
            $tags->set_attribute('data-sm-source-image',$id.':'.$hash);
        }
        return $tags->get_updated_html();
    },10,2);
    add_filter('wp_content_img_tag',static function(string $html):string {
        if(!str_contains($html,'data-sm-source-image'))return $html;
        $tags=new WP_HTML_Tag_Processor($html);
        if(!$tags->next_tag('IMG'))return $html;
        $image=syndamorph_image_strip_attachment($tags);
        if($image!==null){
            $tags->remove_attribute('srcset');
            $tags->remove_attribute('sizes');
        }
        return $tags->get_updated_html();
    },99);
}

function syndamorph_image_strip_attachment(WP_HTML_Tag_Processor $tags): ?array {
    $marker=$tags->get_attribute('data-sm-source-image');
    if(!is_string($marker)||!preg_match('/^([1-9][0-9]{0,9}):([a-f0-9]{64})$/D',$marker,$match))return null;
    $id=(int)$match[1];$hash=$match[2];
    if($tags->get_attribute('src')!==wp_get_attachment_url($id)||
        get_post_meta($id,'_syndamorph_source_strip_sha256',true)!==$hash)return null;
    return array($id,$hash);
}

/** Resolve marked WordPress media to inline bytes for the network-isolated renderer. */
function syndamorph_image_strip_frame(string $html) {
    if(!str_contains($html,'data-sm-source-image'))return $html;
    $tags=new WP_HTML_Tag_Processor($html);
    $bytes=0;$assets=array();$replace=array();
    while($tags->next_tag('IMG')){
        if($tags->get_attribute('data-sm-source-image')===null)continue;
        $image=syndamorph_image_strip_attachment($tags);
        if($image===null)
            return new WP_Error('sm_image_frame','Invalid image evidence marker.');
        list($id,$hash)=$image;
        if(!syndamorph_attachment_has_original($id,$hash))
            return new WP_Error('sm_image_frame','The required original WordPress image is unavailable.');
        if(!isset($assets[$id])){
            $raw=@file_get_contents(get_attached_file($id,true),false,null,0,524289);
            if(!is_string($raw)||strlen($raw)>524288||hash('sha256',$raw)!==$hash)
                return new WP_Error('sm_image_frame','The required original image bytes changed.');
            $assets[$id]='data:image/png;base64,'.base64_encode($raw);
        }
        $bytes+=strlen($assets[$id]);
        if($bytes>1048576)return new WP_Error('sm_image_frame','Required image evidence exceeds the isolated render limit.');
        // WordPress rejects data: on src. Expand a private placeholder only after tokenization.
        $placeholder='#sm-preview-'.bin2hex(random_bytes(12));
        if(!$tags->set_attribute('src',$placeholder))
            return new WP_Error('sm_image_frame','Could not bind the verified image to the isolated preview.');
        $replace['src="'.$placeholder.'"']='src="'.$assets[$id].'"';
        $tags->remove_attribute('srcset');
        $tags->remove_attribute('sizes');
    }
    return strtr($tags->get_updated_html(),$replace);
}

function syndamorph_image_strip_content(array $source,array $receipt): ?string {
    $asset=$receipt['asset']??array();
    $id=$receipt['attachmentId']??null;
    $url=is_int($id)?wp_get_attachment_url($id):false;
    if(!$url||($receipt['region']??null)!==($source['id']??null)||
        ($receipt['sourceSha256']??null)!==hash('sha256',wp_json_encode($source))||
        array_intersect_key($asset,array('url'=>true,'alt'=>true))!==syndamorph_image_strip_inventory($source)||
        get_post_meta($id,'_syndamorph_source_strip_sha256',true)!==($asset['sha256']??null)||
        !syndamorph_attachment_has_original($id,(string)($asset['sha256']??''))||
        array_keys($receipt['views']??array())!==SYNDAMORPH_SOURCE_WIDTHS)return null;
    foreach($receipt['views'] as $width=>$view){
        $state=$view['initial'];
        $desktop=$width===1440;
        $padding=$desktop?array('56px','60px','64px','60px'):array('40px','0px','48px','20px');
        if(($state['padding']??null)!==$padding||($state['background']??null)!=='rgb(255, 255, 255)'||
            ($state['imagePaddingRight']??null)!==($desktop?'0px':'20px')||
            ($desktop?($state['maximum']!==0):((float)$state['imageBox'][3]!==180.0)))return null;
    }
    $image=array('id'=>$id,'sizeSlug'=>'full','linkDestination'=>'none','className'=>'sm-source-strip-image');
    return "<!-- wp:group {\"className\":\"sm-source-image-strip\",\"layout\":{\"type\":\"default\"}} -->\n".
        '<div class="wp-block-group sm-source-image-strip"><!-- wp:image '.wp_json_encode($image)." -->\n".
        '<figure class="wp-block-image size-full sm-source-strip-image"><img src="'.esc_url($url).'" alt="'.
        esc_attr($asset['alt']).'" class="wp-image-'.$id.'"/></figure>'."\n<!-- /wp:image --></div>\n<!-- /wp:group -->";
}

function syndamorph_image_strip_quality(array $source,array $receipt,string $content) {
    $widths=SYNDAMORPH_SOURCE_WIDTHS;
    $preview=syndamorph_native_core_preview(parse_blocks($content));
    $frames=array();$reference=array();
    foreach($widths as $width){
        $view=$source['variants'][$width];
        $frames[$width]=$view['bounds']['y'];
        $reference[]=array('width'=>$width,'extent'=>(int)round($view['bounds']['height']),'screenshot'=>$view['screenshot']);
    }
    $build=null;
    $views=syndamorph_collab_inspect($preview,$widths,$build,'section',$frames);
    if(is_wp_error($views))return $views;
    $sha=hash('sha256',$content);
    $visual=syndamorph_source_visual($reference,$views);
    $responsive=syndamorph_native_responsive_receipt($preview,$widths,$views,$build,$sha);
    $html=syndamorph_image_strip_frame($preview);
    if(is_wp_error($html))return $html;
    $gestureWidths=array(1440,1024,1023,768,375);
    $response=wp_remote_post('http://renderer:3000/check-image-strip',array('timeout'=>65,'redirection'=>0,
        'headers'=>array('Content-Type'=>'application/json'),
        'body'=>wp_json_encode(array('html'=>$html,'viewports'=>$gestureWidths)),
        'limit_response_size'=>16000));
    $decoded=is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200?null:
        json_decode(wp_remote_retrieve_body($response),true);
    $functional=is_array($decoded['evidence']??null)?$decoded['evidence']:array();
    $passed=array_column($functional,'width')===$gestureWidths;
    foreach($functional as $sample){
        $width=$sample['width'];$check=$sample['proof']??array();
        $range=$receipt['views'][$width]['finalOffset']??0;
        $passed=$passed&&($check['sha256']??null)===$receipt['asset']['sha256']&&
            ($check['alt']??null)===$receipt['asset']['alt']&&($check['initialOffset']??null)===0&&
            ($check['range']??null)===$range&&($check['insidePage']??null)===true&&
            ($check['compact']??null)===($width<1024)&&($check['focusable']??null)===true&&
            (!$range||(($check['wheelOffset']??null)===$range&&($check['returnOffset']??null)===0&&
                ($check['keyboardOffset']??0)>0&&($check['focus']??null)===true));
    }
    // A valid asset may overflow inside its own scroll region, never the page.
    return array('artifactSha256'=>$sha,'passed'=>$passed&&$visual['passed']&&$responsive['status']==='passed',
        'visual'=>$visual,'responsive'=>$responsive,'functional'=>array('passed'=>$passed,'views'=>$functional));
}
