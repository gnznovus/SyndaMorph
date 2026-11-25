<?php
/** Accept only a complete capture service receipt for the pinned source. */
if (!defined('ABSPATH')) exit;

function syndamorph_production_reference_valid($archive,string $sourceUrl): bool {
    $digest=static fn($value): bool => is_string($value) && preg_match('/^[a-f0-9]{64}$/D',$value)===1;
    $widths=array(1440,768,375);
    if(!is_array($archive) || ($archive['version']??null)!==1 || ($archive['format']??null)!=='singlefile-2.27.3' ||
        ($archive['sourceUrl']??null)!==explode('#',$sourceUrl,2)[0] || !$digest($archive['id']??null) ||
        !is_array($archive['views']??null) || !array_is_list($archive['views']) ||
        array_column($archive['views'],'width')!==$widths)return false;
    $format=$archive['format'];
    $provenance=array(1,$format,$archive['sourceUrl']);
    foreach($archive['views'] as $view){
        $ratio=$view['pixelDifferenceRatio']??null;
        if(($view['verified']??null)!==true || ($view['javascriptEnabled']??null)!==false ||
            ($view['externalRequests']??null)!==0 || ($view['viewportHeight']??null)!==900 ||
            !is_int($view['scrollWidth']??null) || $view['scrollWidth']<$view['width'] ||
            !is_int($view['pageHeight']??null) || $view['pageHeight']<1 ||
            ((!is_float($ratio) && !is_int($ratio)) || !is_finite((float)$ratio) || $ratio<0 || $ratio>0.015) ||
            !$digest($view['copySha256']??null))return false;
        $identity=array($view['width']);
        foreach(array('sourceDOMSha256','sourceScreenshotSha256','archiveSha256','replayScreenshotSha256') as $field){
            if(!$digest($view[$field]??null))return false;
            $identity[]=$view[$field];
        }
        $provenance[]=$identity;
    }
    $identity=json_encode($provenance,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    return is_string($identity) && hash_equals($archive['id'],hash('sha256',$identity));
}
