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
    if(!$profile||!syndamorph_production_reference_valid($archive,$archive['sourceUrl']??'')||
        empty($archive['runtimeSha256'])||!syndamorph_internal_configured('backend'))return $fail('A verified recorded source with an exact image and link is required.');
    $copy=array_column($archive['views'],'copySha256','width');
    $asset=null;$raw=null;$views=array();
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $ref=$source['variants'][$width]['sourceRef'];
        $withImage=in_array($width,$profile['assetWidths'],true);
        $response=syndamorph_internal_request('backend','POST','/production-touch',array('timeout'=>45,'redirection'=>0,
            'limit_response_size'=>$withImage?710000:16000,
            'headers'=>array('Content-Type'=>'application/json'),
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

function syndamorph_linked_editorial_css(): string {
    $file=dirname(__DIR__).'/assets/production-linked-editorial.css';
    $css=is_readable($file)?file_get_contents($file):false;
    return is_string($css)?str_replace("\r\n","\n",$css):'';
}

add_action('enqueue_block_assets',static function():void {
    wp_register_style('syndamorph-linked-editorial',false,array(),null);
    wp_enqueue_style('syndamorph-linked-editorial');
    wp_add_inline_style('syndamorph-linked-editorial',syndamorph_linked_editorial_css());
});

/** Match the measured column spans, ordered text roles, and compact stack. */
function syndamorph_linked_editorial_copy(array $source,array $receipt): ?array {
    if(!class_exists('DOMDocument')||array_keys($receipt['views']??array())!==SYNDAMORPH_SOURCE_WIDTHS)return null;
    $children=static fn(DOMNode $node): array => array_values(array_filter(iterator_to_array($node->childNodes),
        static fn($child)=>$child instanceof DOMElement));
    $tags=static fn(array $nodes): array => array_map(static fn(DOMElement $node)=>$node->tagName,$nodes);
    $matches=static function(DOMElement $node,array $expected): bool {
        $style=array();
        foreach(explode(';',$node->getAttribute('style')) as $declaration){
            $parts=explode(':',$declaration,2);
            if(count($parts)===2)$style[trim($parts[0])]=trim($parts[1]);
        }
        return array_intersect_key($style,$expected)==$expected;
    };
    $copy=null;
    foreach(SYNDAMORPH_SOURCE_WIDTHS as $width){
        $variant=$source['variants'][$width]??array();
        $html=$variant['html']??null;
        if(!is_string($html)||$html===''||strlen($html)>32000)return null;
        $doc=new DOMDocument();
        if(!$doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))return null;
        $body=$doc->getElementsByTagName('body')->item(0);
        $roots=$body?$children($body):array();
        if($tags($roots)!==array('div'))return null;
        $root=$roots[0];$items=$children($root);
        if($width===1440){
            if($tags($items)!==array('div','div','div','div')||
                !in_array($root->getAttribute('data-syndamorph-tracks'),array('repeat(12, minmax(0px, 1fr))','repeat(12, minmax(0, 1fr))'),true)||
                !$matches($root,array('display'=>'grid','gap'=>'24px','padding'=>'96px 60px 120px')))return null;
            list($lead,$body,$landscape,$portrait)=$items;
            $head=$children($lead);$description=$children($body);
            if($tags($head)!==array('div','div','img')||$tags($description)!==array('p','p','div','a')||
                !$matches($lead,array('grid-column'=>'1 / span 6','display'=>'flex','gap'=>'16px'))||
                !$matches($body,array('grid-column'=>'8 / span 5','display'=>'flex','gap'=>'16px','justify-content'=>'flex-end'))||
                !$matches($landscape,array('grid-column'=>'1 / span 7','aspect-ratio'=>'4 / 3','margin'=>'48px 0px 0px'))||
                !$matches($portrait,array('grid-column'=>'9 / span 4','aspect-ratio'=>'4 / 5','margin'=>'160px 0px 0px')))return null;
            $asset=$receipt['asset']??array();
            $view=$receipt['views'][$width]['image']??array();
            if($head[2]->getAttribute('src')!==($asset['url']??null)||$head[2]->getAttribute('alt')!==($asset['alt']??null)||
                !$matches($head[2],array('max-width'=>'440px','margin'=>'16px 0px 0px'))||
                ($view['filter']??null)!=='grayscale(1) contrast(1.15)'||($view['blend']??null)!=='multiply')return null;
            $roles=array_merge(array_slice($head,0,2),$description,array($landscape,$portrait));
        }else{
            if($tags($items)!==array('div','div','p','p','div','a','div')||
                !$matches($root,array('display'=>'flex','flex-flow'=>'column','gap'=>'14px','padding'=>'56px 20px'))||
                !$matches($items[6],array('aspect-ratio'=>'4 / 5','margin'=>'12px 0px 0px'))||
                $children($items[6])||trim($items[6]->textContent)!==''||
                ($receipt['views'][$width]['image']??null)!==null)return null;
            $roles=array_slice($items,0,6);
        }
        $text=array();
        foreach($roles as $node){
            if($children($node))return null;
            $text[]=syndamorph_source_text($node->textContent);
        }
        if(in_array('',$text,true)||$text!==($variant['inventory']['text']??null)||
            $roles[5]->getAttribute('href')!==($receipt['link']['href']??null)||
            $text[5]!==($receipt['link']['text']??null)||($copy!==null&&$text!==array_slice($copy,0,6)))return null;
        if($width===1440)$copy=$text;
    }
    return $copy;
}

function syndamorph_linked_editorial_content(array $source,array $receipt): ?string {
    $copy=syndamorph_linked_editorial_copy($source,$receipt);
    $asset=$receipt['asset']??array();
    $id=$receipt['attachmentId']??null;
    $url=is_int($id)?wp_get_attachment_url($id):false;
    if(!$copy||!$url||get_post_meta($id,'_syndamorph_source_strip_sha256',true)!==($asset['sha256']??null)||
        !syndamorph_attachment_has_original($id,(string)($asset['sha256']??'')))return null;
    $copy=array_map('esc_html',$copy);
    $group=static fn($class,$children='')=>'<!-- wp:group '.wp_json_encode(array('className'=>$class,'layout'=>array('type'=>'default')))." -->\n".
        '<div class="wp-block-group '.$class.'">'.$children."</div>\n<!-- /wp:group -->\n";
    $paragraph=static fn($class,$body)=>'<!-- wp:paragraph '.wp_json_encode(array('className'=>$class))." -->\n".
        '<p class="'.$class.'">'.$body."</p>\n<!-- /wp:paragraph -->\n";
    $link='<a href="'.esc_url($receipt['link']['href']).'">'.$copy[5].'</a>';
    $heading='<!-- wp:heading {"className":"sm-editorial-title"} -->'."\n".
        '<h2 class="wp-block-heading sm-editorial-title">'.$copy[1]."</h2>\n<!-- /wp:heading -->\n";
    $lead=$group('sm-editorial-lead',$paragraph('sm-editorial-kicker',$copy[0]).$heading.
        syndamorph_source_image_block($id,$url,$asset['alt'],'sm-source-linked-image'));
    $body=$group('sm-editorial-copy',$paragraph('sm-editorial-intro',$copy[2]).$paragraph('sm-editorial-description',$copy[3]).
        $paragraph('sm-editorial-strapline',$copy[4]).$paragraph('sm-editorial-link',$link));
    return rtrim($group('sm-linked-editorial',$lead.$body.
        $group('sm-editorial-landscape',$paragraph('sm-editorial-caption',$copy[6])).
        $group('sm-editorial-portrait',$paragraph('sm-editorial-caption',$copy[7])).$group('sm-editorial-compact-panel')));
}

function syndamorph_linked_editorial_quality(array $source,array $receipt,string $content) {
    $widths=SYNDAMORPH_SOURCE_WIDTHS;
    $native=syndamorph_native_core_preview(parse_blocks($content));
    $frames=array();$reference=array();$images=array();
    foreach($widths as $width){
        $view=$source['variants'][$width];
        $frames[$width]=$view['bounds']['y'];
        $reference[]=array('width'=>$width,'extent'=>(int)round($view['bounds']['height']),'screenshot'=>$view['screenshot']);
        $image=$receipt['views'][$width]['image'];
        $images[$width]=$image===null?null:array('alt'=>$image['alt'],'pixels'=>$image['pixels'],
            'filter'=>$image['filter'],'blend'=>$image['blend'],'sha256'=>$receipt['asset']['sha256']);
    }
    $build=null;
    $views=syndamorph_collab_inspect($native,$widths,$build,'section',$frames);
    if(is_wp_error($views))return $views;
    $sha=hash('sha256',$content);
    $markup=syndamorph_source_verify_markup($source,$views,$images);
    $visual=syndamorph_source_visual($reference,$views);
    $responsive=syndamorph_native_responsive_receipt($native,$widths,$views,$build,$sha);
    $link=$receipt['link']['href'];
    $keyboard=array();
    foreach($views as $view){
        $anchors=array_filter($view['snapshot']['elements']??array(),static fn($node)=>
            ($node['tag']??null)==='A'&&($node['visible']??null)===true);
        $anchor=reset($anchors);
        $keyboard[$view['width']]=count($anchors)===1&&($anchor['href']??null)===$link&&($anchor['keyboardReachable']??null)===true;
    }
    return array('artifactSha256'=>$sha,'stylesheetSha256'=>hash('sha256',syndamorph_linked_editorial_css()),
        'passed'=>$markup['passed']&&$visual['passed']&&$responsive['status']==='passed'&&!in_array(false,$keyboard,true),
        'markup'=>$markup,'visual'=>$visual,'responsive'=>$responsive,'keyboardLink'=>$keyboard);
}
