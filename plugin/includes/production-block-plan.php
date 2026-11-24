<?php
/** Native block plan v1: no arbitrary markup, CSS or block attributes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_block_plan_contract(): string {
    return 'Return a JSON object {"version":1,"blocks":[...]} with exactly one root group. Each node has type, a stable unique id and optional style. Supported nodes: group (children), columns (children of type column, 2–4), column (children), heading (text, level 1–6), paragraph (text), buttons (children of type button), button (text, href: #fragment or /local-path). Only group/column may contain ordinary content blocks. Styles: color, background (six-digit hex), fontSize, fontWeight (100–900), lineHeight (1–2.5), letterSpacing (px/rem/em), padding (single length or object with top/right/bottom/left lengths on group/column/button), margin (single length or sides on heading/paragraph), gap (group/columns/buttons), borderRadius (button), align (heading/paragraph left/center/right). All lengths positive px/rem/em or clamp(length,length,length) with vw allowed. Use a heading and meaningful CTA. No HTML, arbitrary CSS, remote URLs, custom blocks or invented attributes. Use native columns mobile stacking; no fixed widths. Example {"version":1,"blocks":[{"type":"group","style":{"background":"#18283a","padding":"clamp(2rem,5vw,5rem)"},"children":[{"type":"heading","level":2,"text":"Welcome","style":{"color":"#ffffff","fontSize":"clamp(2rem,5vw,4rem)"}},{"type":"paragraph","text":"Discover our hotel","style":{"color":"#ffffff"}},{"type":"buttons","children":[{"type":"button","text":"Explore","href":"#explore"}]}]}]}.';
}

function syndamorph_compile_block_plan( string $raw ) {
    try {
        if ( strlen( $raw ) > 120000 ) { throw new InvalidArgumentException( 'Plan too large.' ); }
        $plan = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR );
        if ( ! is_array( $plan ) || array_diff( array_keys( $plan ), array( 'version', 'blocks' ) ) || ( $plan['version'] ?? null ) !== 1 ||
            ! is_array( $plan['blocks'] ?? null ) || ! array_is_list( $plan['blocks'] ) || count( $plan['blocks'] ) !== 1 || ( $plan['blocks'][0]['type'] ?? '' ) !== 'group' ) {
            throw new InvalidArgumentException( 'Expected one root group in a version 1 plan.' );
        }
        $count = 0; $headings = 0; $field = 'blocks[0]';
        $escape = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $length = static function ( $v ): string {
            $unit = '[0-9]{1,3}(?:\.[0-9]{1,3})?(?:px|rem|em|vw)';
            if ( ! is_string( $v ) || ! preg_match( '~^(?:0|' . $unit . '|clamp\(\s*' . $unit . '\s*,\s*' . $unit . '\s*,\s*' . $unit . '\s*\))$~D', $v ) ) { throw new InvalidArgumentException( 'Invalid length.' ); }
            return $v === '0' ? '0px' : $v;
        };
        $build = static function ( $node, string $parent, int $depth, string $path ) use ( &$build, &$count, &$headings, &$field, $escape, $length ): string {
            $field = $path;
            if ( ! is_array( $node ) || ++$count > 120 || $depth > 12 ) { throw new InvalidArgumentException( 'Invalid node or plan limit exceeded.' ); }
            $type = $node['type'] ?? '';
            $allowed = array( 'root'=>array('group'), 'group'=>array('group','columns','heading','paragraph','buttons'), 'column'=>array('group','columns','heading','paragraph','buttons'), 'columns'=>array('column'), 'buttons'=>array('button') );
            if ( ! is_string( $type ) || ! in_array( $type, $allowed[$parent] ?? array(), true ) ) { throw new InvalidArgumentException( 'Unsupported block or nesting.' ); }
            $container = in_array( $type, array('group','columns','column','buttons'), true );
            $keys = array_merge( array('type','style','id'), $container ? array('children') : array('text'), $type==='heading'?array('level'):array(), $type==='button'?array('href'):array() );
            if ( $unknown = array_diff( array_keys($node), $keys ) ) {
                $key = (string) reset($unknown);
                $field = $path . '.' . preg_replace('/[^a-zA-Z0-9_-]/', '?', substr($key,0,64));
                throw new InvalidArgumentException( $key === 'align' ? 'Alignment belongs inside style.align.' : 'Unsupported node field.' );
            }
            if (isset($node['id']) && (!is_string($node['id']) || !preg_match('/^[a-zA-Z0-9_.\\[\\]-]{1,100}$/D', $node['id']))) { $field=$path.'.id'; throw new InvalidArgumentException('Invalid component ID.'); }
            $style = $node['style'] ?? array();
            if ( ! is_array($style) ) { throw new InvalidArgumentException('Invalid style.'); }
            $attrs = array(); $css = array(); $classes = array();
            if ( $type !== 'paragraph' ) { $classes[] = 'wp-block-' . $type; }
            if ( $type === 'group' ) { $attrs['layout'] = array('type'=>'flex','orientation'=>'vertical'); }
            foreach ( $style as $key=>$value ) {
                $field = $path . '.style.' . preg_replace('/[^a-zA-Z0-9_-]/', '?', substr((string)$key,0,64));
                if ( in_array($key,array('color','background'),true) && in_array($type,array('group','column','heading','paragraph','button'),true) ) {
                    if ( ! is_string($value) || ! preg_match('/^#[0-9a-f]{6}$/iD',$value) ) { throw new InvalidArgumentException('Invalid color.'); }
                    $attrs['style']['color'][$key==='color'?'text':'background']=$value;
                    $classes[]=$key==='color'?'has-text-color':'has-background';
                    $css[$key==='color'?'color':'background-color']=$value;
                } elseif ( $key==='fontSize' && in_array($type,array('heading','paragraph','button'),true) ) {
                    $attrs['style']['typography']['fontSize']=$css['font-size']=$length($value);
                } elseif (in_array($key,array('padding','margin'),true) && (($key==='padding' && in_array($type,array('group','column','button'),true)) || ($key==='margin' && in_array($type,array('heading','paragraph'),true)))) {
                    if(is_array($value)) {
                        if (array_diff(array_keys($value),array('top','right','bottom','left')) || !count($value)) throw new InvalidArgumentException('Invalid spacing sides.');
                        $sides=$value;
                    } else { $sides=array_fill_keys(array('top','right','bottom','left'),$value); }
                    foreach($sides as $side=>$v) { $attrs['style']['spacing'][$key][$side]=$css[$key.'-'.$side]=$length($v); }
                } elseif ( $key==='fontWeight' && in_array($type,array('heading','paragraph','button'),true) ) {
                    if(!is_string($value)||!preg_match('/^[1-9]00$/D',$value))throw new InvalidArgumentException('Invalid font weight.');
                    $attrs['style']['typography']['fontWeight']=$value;$css['font-weight']=$value;
                } elseif ( $key==='lineHeight' && in_array($type,array('heading','paragraph','button'),true) ) {
                    if(!is_string($value)||!preg_match('/^(?:1(?:\\.[0-9]{1,2})?|2(?:\\.[0-5])?)$/D',$value))throw new InvalidArgumentException('Invalid unitless line height.');
                    $attrs['style']['typography']['lineHeight']=$value;$css['line-height']=$value;
                } elseif ( $key==='letterSpacing' && in_array($type,array('heading','paragraph','button'),true) ) {
                    $attrs['style']['typography']['letterSpacing']=$css['letter-spacing']=$length($value);
                } elseif ( $key==='borderRadius' && $type==='button' ) {
                    $attrs['style']['border']['radius']=$css['border-radius']=$length($value);
                } elseif ( $key==='gap' && in_array($type,array('group','columns','buttons'),true) ) {
                    $attrs['style']['spacing']['blockGap']=$css['gap']=$length($value);
                } elseif ( $key==='align' && in_array($type,array('heading','paragraph'),true) && in_array($value,array('left','center','right'),true) ) {
                    $attrs[$type==='heading'?'textAlign':'align']=$value;
                    $classes[]='has-text-align-'.$value;
                } else { throw new InvalidArgumentException('Unsupported style for block.'); }
            }
            // Keep core support classes in the order emitted by Gutenberg.
            $classes = array_values(array_unique($classes));
            $base = array_values(array_filter($classes,static fn($c)=>strpos($c,'wp-block-')===0 || strpos($c,'has-text-align-')===0));
            foreach(array('has-text-color','has-background') as $c) { if(in_array($c,$classes,true))$base[]=$c; }
            $classes=$base;
            $style_parts=array(); foreach($css as $k=>$v)$style_parts[]=$k.':'.$v;
            $appearance=static fn(array $c,array $s): string => ($c?' class="'.$escape(implode(' ',$c)).'"':'').($s?' style="'.$escape(implode(';',$s)).'"':'');
            $field = $path;
            if ($container) {
                $field = $path . '.children';
                $children=$node['children']??null;
                if(!is_array($children)||!array_is_list($children)||!count($children)||($type==='columns'&&(count($children)<2||count($children)>4)))throw new InvalidArgumentException('Invalid children.');
                $inner='';foreach($children as $i=>$child)$inner.=$build($child,$type,$depth+1,$path.'.children['.$i.']');
                $markup='<div'.$appearance($classes,$style_parts).'>'.$inner.'</div>';
            } else {
                $field = $path . '.text';
                if(!is_string($node['text']??null)||trim($node['text'])===''||strlen($node['text'])>8000)throw new InvalidArgumentException('Invalid text.');
                $body=$escape($node['text']);
                if($type==='heading'){
                    $field = $path . '.level'; $level=$node['level']??2;if(!is_int($level)||$level<1||$level>6)throw new InvalidArgumentException('Invalid heading level.');
                    if($level!==2)$attrs['level']=$level;
                    ++$headings;$tag='h'.$level;
                    $markup='<'.$tag.$appearance($classes,$style_parts).'>'.$body.'</'.$tag.'>';
                }elseif($type==='paragraph'){$markup='<p'.$appearance($classes,$style_parts).'>'.$body.'</p>';}
                else{
                    $field = $path . '.href'; $href=$node['href']??'';
                    if(!is_string($href)||!preg_match('~^(?:#[A-Za-z][\w-]*|/(?!/)[A-Za-z0-9/_#?=&.%-]*)$~D',$href))throw new InvalidArgumentException('Invalid local CTA URL.');
                    $link_classes=array_merge(array('wp-block-button__link'),array_values(array_diff($classes,array('wp-block-button'))),array('wp-element-button'));
                    $markup='<div class="wp-block-button"><a'.$appearance($link_classes,$style_parts).' href="'.$escape($href).'">'.$body.'</a></div>';
                }
            }
            $json=$attrs?' '.str_replace(array('--','<','>','&'),array('\\u002d\\u002d','\\u003c','\\u003e','\\u0026'),json_encode($attrs,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)):'';
            return '<!-- wp:'.$type.$json.' -->'.$markup.'<!-- /wp:'.$type.' -->';
        };
        $content=$build($plan['blocks'][0],'root',0,'blocks[0]');
        // Verify WordPress parser sees only expected native blocks when available.
        if(function_exists('parse_blocks')){
            $verify=static function(array $blocks)use(&$verify):void{
                foreach($blocks as $b){
                    if(!in_array($b['blockName'],array('core/group','core/columns','core/column','core/heading','core/paragraph','core/buttons','core/button'),true))throw new InvalidArgumentException('Unexpected parsed block.');
                    $verify($b['innerBlocks']);
                }
            };$verify(parse_blocks($content));
        }
        $preview='<style>*{box-sizing:border-box}body{margin:0;font-family:system-ui,sans-serif;line-height:1.5}.wp-block-group.is-layout-flex{display:flex;flex-direction:column}.wp-block-columns{display:flex;gap:1.5rem}.wp-block-column{flex:1;min-width:0}.wp-block-buttons{display:flex;flex-wrap:wrap;gap:1rem}.wp-block-button__link{display:inline-block;padding:.75em 1.5em;background:#26384a;color:#fff;text-decoration:none;border-radius:4px}.has-text-align-center{text-align:center}.has-text-align-right{text-align:right}@media(max-width:781px){.wp-block-columns{flex-direction:column}}</style>'.$content;
        return array('schemaVersion'=>1,'content'=>$content,'preview'=>$preview,'blockCount'=>$count,'checks'=>array('valid_plan'=>true,'native_blocks_only'=>true,'contains_heading'=>$headings>0,'no_active_markup'=>true));
    } catch ( InvalidArgumentException | JsonException $e ) { return new WP_Error('sm_block_plan_invalid','Native block plan rejected: '.$e->getMessage(),array('status'=>422,'field'=>$field ?? 'plan')); }
    catch ( Throwable $e ) { return new WP_Error('sm_block_plan_system','Native block compilation encountered an internal error.',array('status'=>500)); }
}
