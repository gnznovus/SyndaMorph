<?php
/** Resolve installed WordPress font presets for isolated production evidence. */
if (!defined('ABSPATH')) exit;

const SYNDAMORPH_PRODUCTION_FONT_FILE_LIMIT = 262144;
const SYNDAMORPH_PRODUCTION_FONT_FRAME_LIMIT = 786432;

function syndamorph_production_font_path(string $src): ?string {
    // Only the active theme's assets and the Font Library directory are local
    // authorities. Never resolve agent supplied URLs or follow remote redirects.
    if(str_starts_with($src,'file:./')){
        $relative=substr($src,7);
        $root=realpath(get_theme_file_path());
        $path=realpath(get_theme_file_path($relative));
        $parent=realpath(get_template_directory());
        $roots=array($root,$parent);
    }else{
        $dir=function_exists('wp_get_font_dir')?wp_get_font_dir():array();
        $prefix=rtrim($dir['baseurl']??'','/').'/';
        if($prefix==='/'||!str_starts_with($src,$prefix))return null;
        $relative=rawurldecode(substr($src,strlen($prefix)));
        $roots=array(realpath($dir['basedir']));
        $path=realpath($dir['basedir'].'/'.$relative);
    }
    if(!is_string($path)||!preg_match('/\.(?:woff2?|otf|ttf)$/iD',$path)||!is_file($path)||!is_readable($path))return null;
    foreach($roots as $root)if(is_string($root)&&str_starts_with($path,$root.DIRECTORY_SEPARATOR))return $path;
    return null;
}

function syndamorph_production_font_families(): array {
    if(!function_exists('wp_get_global_settings'))return array();
    $presets=wp_get_global_settings(array('typography','fontFamilies'));
    $families=array();
    foreach(array('theme','custom') as $origin)foreach($presets[$origin]??array() as $preset){
        $slug=$preset['slug']??null;
        if(!is_string($slug)||!preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$slug))continue;
        unset($families[$slug]);
        $stack=$preset['fontFamily']??'';
        $name=trim(explode(',',(string)$stack,2)[0]," \t\r\n\"'");
        $faces=$preset['fontFace']??array();
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$/D',$name)||!is_array($faces)||!$faces||count($faces)>6)continue;
        $resolved=array();
        foreach($faces as $face){
            $family=trim((string)($face['fontFamily']??'')," \t\r\n\"'");
            $weight=(string)($face['fontWeight']??'400');
            $weight=array('normal'=>'400','bold'=>'700')[$weight]??$weight;
            $style=$face['fontStyle']??'normal';
            $weights=array_map('intval',explode(' ',$weight));
            if($family!==$name||!preg_match('/^(?:[1-9][0-9]{0,2}|1000)(?: (?:[1-9][0-9]{0,2}|1000))?$/D',$weight)||
                (count($weights)===2&&$weights[0]>=$weights[1])||!in_array($style,array('normal','italic'),true)||
                !empty($face['unicodeRange'])||!empty($face['fontStretch']))break;
            $path=null;
            foreach((array)($face['src']??array()) as $src){
                if(!is_string($src))continue;
                $path=syndamorph_production_font_path($src);
                if($path!==null)break;
            }
            if($path===null)break;
            $resolved[]=array('path'=>$path,'weight'=>$weight,'style'=>$style);
        }
        if(count($resolved)===count($faces))$families[$slug]=array('name'=>$name,'faces'=>$resolved);
    }
    return $families;
}

function syndamorph_production_font_frame(string $html) {
    $css='';$bytes=0;
    preg_match_all('/(?<![\w-])font(?:-family)?\s*:\s*([^;}<]+)/i',$html,$declared);
    preg_match_all('/\bclass\s*=\s*(["\'])([^"\']*)\1/i',$html,$classAttributes);
    $classes=array_fill_keys(preg_split('/\s+/',implode(' ',$classAttributes[2])),true);
    $declarations=implode(',',$declared[1]);
    $seen=array();
    for($depth=0;$depth<4;$depth++){
        preg_match_all('/\bvar\(\s*(--[a-z0-9_-]+)/i',$declarations,$vars);
        $missing=array_diff(array_unique($vars[1]),$seen);
        if(!$missing||count($missing)>32)break;
        foreach($missing as $name){
            $seen[]=$name;
            if(str_starts_with($name,'--wp--preset--'))continue;
            preg_match_all('/(?<![\w-])'.preg_quote($name,'/').'\s*:\s*([^;}<]+)/',$html,$values);
            $declarations.=','.implode(',',$values[1]);
        }
    }
    foreach(syndamorph_production_font_families() as $slug=>$preset){
        if(!isset($classes['has-'.$slug.'-font-family'])&&!preg_match('/(?<![\w-])'.preg_quote($preset['name'],'/').'(?![\w-])/i',$declarations))continue;
        foreach($preset['faces'] as $face){
            $limit=SYNDAMORPH_PRODUCTION_FONT_FILE_LIMIT;
            $binary=@file_get_contents($face['path'],false,null,0,$limit+1);
            $signature=substr((string)$binary,0,4);
            $ext=array('wOF2'=>'woff2','wOFF'=>'woff','OTTO'=>'otf',"\x00\x01\x00\x00"=>'ttf')[$signature]??null;
            $bytes+=strlen((string)$binary);
            if($ext===null||strtolower(pathinfo($face['path'],PATHINFO_EXTENSION))!==$ext||
                strlen($binary)>$limit||$bytes>SYNDAMORPH_PRODUCTION_FONT_FRAME_LIMIT)
                return new WP_Error('sm_production_font_file','A referenced installed font failed the isolated font checks.');
            $format=array('otf'=>'opentype','ttf'=>'truetype')[$ext]??$ext;
            $css.='@font-face{font-family:"'.$preset['name'].'";font-style:'.$face['style'].';font-weight:'.$face['weight'].
                ';src:url(data:font/'.$ext.';base64,'.base64_encode($binary).') format("'.$format.'")}';
        }
    }
    if($css==='')return array('html'=>$html,'sha256'=>null);
    $sha=hash('sha256',$css);
    // Keep the document doctype in its original position.
    $style='<style data-sm-installed-fonts="'.$sha.'">'.$css.'</style>';
    $offset=preg_match('/^\s*<!doctype\s+html\s*>/i',$html,$match)?strlen($match[0]):0;
    return array('html'=>substr_replace($html,$style,$offset,0),'sha256'=>$sha);
}
