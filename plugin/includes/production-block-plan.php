<?php
/** Native block plan v1: no arbitrary markup, CSS or block attributes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function syndamorph_block_plan_contract(): string {
    $buttonWidth=syndamorph_native_full_button_width()?' Button style.width accepts 100% across all viewports, using current core dimensions support.':'';
    return 'Return a JSON object {"version":1,"blocks":[...]} with exactly one root group. Each node has type, a stable unique id and optional style. Supported nodes: group (children), columns (children of type column, 2–4), column (children), heading (text, level 1–6), paragraph (text), buttons (children of type button), button (text, href: #fragment or /local-path). Only group/column may contain ordinary content blocks. Styles: color, background (six-digit hex), fontSize, fontWeight (100–900, integer or numeric string), lineHeight (1–2.5, number or numeric string), letterSpacing (px/rem/em), padding (single length or object with top/right/bottom/left lengths on any supported node), margin (single length or sides on heading/paragraph; top and bottom sides only on group/columns/buttons), textTransform (heading/paragraph/button: none, uppercase, lowercase, capitalize), gap (group/columns/buttons: simple length with theme block-gap support), borderRadius (button), gradient (group/column/button, safe two-color linear-gradient), minHeight (group), align (heading/paragraph left/center/right). Lengths: nonnegative px/rem/em/vw/vh/svh/dvh or clamp(length,length,length), including a bounded additive or subtractive middle expression such as 1.2rem + 3.5vw. Negative letterSpacing is supported. Group contentWidth (px/rem/em/ch) uses native constrained layout and applies the width to its children. contentAlignment (left/center/right) controls horizontal positioning within that constrained group. For a sole constrained Group inside a vertical flex Group, the outer justifyContent stretch lets the inner Group center its bounded children. Theme default horizontal padding may be overridden with explicit native padding matching the approved design. Group justifyContent (left/center/right/stretch) and verticalAlignment (top/center/bottom) use native vertical flex layout, incompatible with contentWidth on the same node. Buttons justifyContent (left/center/right) uses native flex layout. For a vertically centered section with bounded content, use separate flex and constrained groups. The element being constrained must be a child of the constrained group. Keep alignment, minHeight and width on the appropriate separate groups. Do not propose fluid blockGap: WordPress core discards clamp() for blockGap. Use responsive spacing on supported children if it matches the design. Mobile-only full-width CTA and breakpoint-specific per-block spacing are outside this contract.'.$buttonWidth.' Do not substitute an always-full-width CTA or arbitrary leaf max-width. Font family is inherited from the host; use system-ui in reference. Native hover/focus styling is host-owned. Use a heading and meaningful CTA. No HTML, arbitrary CSS, remote URLs, custom blocks or invented attributes. Use native columns mobile stacking; no fixed widths. Example {"version":1,"blocks":[{"type":"group","style":{"background":"#18283a","padding":"clamp(2rem,5vw,5rem)"},"children":[{"type":"heading","level":2,"text":"Welcome","style":{"color":"#ffffff","fontSize":"clamp(2rem,5vw,4rem)"}},{"type":"paragraph","text":"Discover our hotel","style":{"color":"#ffffff"}},{"type":"buttons","children":[{"type":"button","text":"Explore","href":"#explore"}]}]}]}.';
}

function syndamorph_native_full_button_width(): bool {
    if(!class_exists('WP_Block_Type_Registry'))return true;
    $button=WP_Block_Type_Registry::get_instance()->get_registered('core/button');
    return !empty($button->supports['dimensions']['width']);
}

function syndamorph_native_gap_supported(string $gap): bool {
    return function_exists('wp_sanitize_block_gap_value') ? wp_sanitize_block_gap_value($gap)!==null : !str_contains($gap,'(');
}

function syndamorph_native_json_text(string $raw): string {
    $raw=trim($raw);
    if(preg_match('/^```(?:json)?\\s*([\\s\\S]*?)\\s*```$/iD',$raw,$m))return trim($m[1]);
    return $raw;
}
/**
 * Actionable validation expectations. Keep these synchronized with the explicit
 * allowlists and patterns in syndamorph_compile_block_plan().
 */
function syndamorph_block_plan_expected(string $reason,string $field): array {
    $styleKey='';
    if(preg_match('/\\.style\\.([A-Za-z][A-Za-z0-9]*)$/D',$field,$m))$styleKey=$m[1];
    $length=array(
        'format'=>'Nonnegative number with px/rem/em/vw/vh/svh/dvh; 0; or clamp(length, length, length).',
        'constraint'=>'Each number has at most 3 integer digits and 6 decimal digits. clamp() may use one additive or subtractive middle expression with spaces around + or -.',
        'example'=>'clamp(1.0625rem, 1rem + 0.5vw, 1.25rem)',
    );
    $map=array(
        'Invalid length.'=>$length,
        'Invalid color.'=>array('format'=>'Six-digit hexadecimal color','example'=>'#18283a'),
        'Invalid two-color gradient.'=>array('format'=>'linear-gradient(angledeg,#RRGGBB,#RRGGBB); angle 0–360','example'=>'linear-gradient(135deg,#18283a,#203040)'),
        'Invalid font weight.'=>array('format'=>'Integer or numeric string 100, 200, ..., 900','example'=>'700'),
        'Invalid unitless line height.'=>array('format'=>'Unitless number from 1 through 2.5 with up to two decimal digits (2.x has at most one)','example'=>'1.5'),
        'Invalid constrained content alignment.'=>array('format'=>'left, center or right on a group with style.contentWidth','example'=>'left'),
        'Invalid constrained content width.'=>array('format'=>'Positive px/rem/em/ch length, up to 3 integer and 3 decimal digits','example'=>'40rem'),
        'Invalid heading level.'=>array('format'=>'Integer heading level from 1 to 6','example'=>2),
        'Invalid local CTA URL.'=>array('format'=>'Local #fragment or /local-path only; no external URLs','example'=>'#booking-options'),
        'Invalid layout justification.'=>array('format'=>'Group: left, center, right, stretch; buttons: left, center, right','example'=>'center'),
        'Invalid vertical alignment.'=>array('format'=>'top, center, bottom','example'=>'center'),
        'Invalid component ID.'=>array('format'=>'1–100 ASCII letters, digits, underscore, dot, hyphen or square brackets','example'=>'hero-title'),
        'Invalid text.'=>array('format'=>'Nonempty UTF-8 text, up to 8000 bytes','example'=>'Welcome to our hotel'),
        'Invalid button width.'=>array('format'=>'The supported native button width is 100% at every viewport.','example'=>'100%'),
        'Invalid text transform.'=>array('format'=>'none, uppercase, lowercase or capitalize','example'=>'uppercase'),
        'This block supports only top and bottom margins.'=>array('format'=>'Use a margin object with top or bottom. Preserve any horizontal spacing through an approved native composition.','example'=>array('top'=>'1rem')),
        'Invalid spacing sides.'=>array('format'=>'Nonempty object with only top/right/bottom/left keys, or one supported length','example'=>array('top'=>'1rem','bottom'=>'1rem')),
        'Invalid style.'=>array('format'=>'Style object keyed by supported native properties','example'=>array('fontSize'=>'2rem')),
        'Alignment belongs inside style.align.'=>array('format'=>'Put alignment under style.align, not as a node-level field','example'=>array('style'=>array('align'=>'center'))),
        'Constrained layout and flex alignment must use separate groups.'=>array('format'=>'Use distinct nested groups for contentWidth and justifyContent/verticalAlignment','example'=>'Outer constrained group with inner flex group'),
        'Invalid children.'=>array('format'=>'Nonempty list of supported child blocks; columns require 2–4 columns','example'=>'group.children = [heading, paragraph]'),
        'Unsupported block or nesting.'=>array('format'=>'Use supported core block types and their allowed parent/child relationships','example'=>'group > heading + paragraph + buttons'),
        'Expected one root group in a version 1 plan.'=>array('format'=>'Object with version:1 and exactly one root group in blocks[]','example'=>array('version'=>1,'blocks'=>array(array('type'=>'group','children'=>array())))),
        'Unsupported node field.'=>array('format'=>'Only type, id, style, children (containers), text (leaves), level (heading), href (button)','example'=>'Put typography under style.fontSize'),
        'Unsupported style for block.'=>array('format'=>'Only supported native style keys on compatible block types','example'=>'heading.style.fontSize = 2rem'),
    );
    if($reason==='Invalid length.' && $styleKey==='letterSpacing') {
        $length['constraint'].=' Negative px/rem/em values are also supported for letterSpacing.';
        $length['example']='-0.01em';
        return $length;
    }
    if($reason==='Invalid length.' && $styleKey==='gap') {
        $length['constraint'].=' Some WordPress themes may not render clamp() blockGap despite compiler acceptance; verify in WordPress.';
        return $length;
    }
    if(isset($map[$reason]))return $map[$reason];
    return array('format'=>'Valid native block plan satisfying the compiler contract','example'=>'Consult the minimal native block plan contract.');
}

/**
 * Inspect the same block rendering and per-instance styles WordPress uses on the front end.
 * Diagnostic node attributes are added to the parsed copy only.
 */
function syndamorph_native_core_preview(array $blocks,array $ids): string {
    $index=0;$types=array();
    $annotate=static function(array $block)use(&$annotate,&$index,&$types,$ids):array {
        $tag=new WP_HTML_Tag_Processor($block['innerContent'][0]??'');
        if(!$tag->next_tag()||!isset($ids[$index]))throw new RuntimeException('Native block preview identity missing.');
        $tag->set_attribute('data-sm-node',$ids[$index++]);
        $block['innerContent'][0]=$tag->get_updated_html();
        $types[substr($block['blockName'],5)]=true;
        $block['innerBlocks']=array_map($annotate,$block['innerBlocks']);
        return $block;
    };
    $annotated=array_map($annotate,$blocks);
    $html=implode('',array_map('render_block',$annotated));
    $tags=new WP_HTML_Tag_Processor($html);
    $classes=array();
    while($tags->next_tag()) {
        $value=$tags->get_attribute('class');
        if(is_string($value))foreach(preg_split('/\s+/',trim($value)) as $class)$classes[$class]=true;
    }
    // The style engine store is request-scoped and may already contain rules
    // for earlier candidates. Keep only selectors belonging to this render.
    $rules=array_filter(WP_Style_Engine::get_store('block-supports')->get_all_rules(),static function($rule)use($classes):bool {
        preg_match_all('/\.([\w-]+)/',$rule->get_selector(),$matches);
        return (bool)array_intersect_key($classes,array_fill_keys($matches[1],true));
    });
    $css=wp_get_global_stylesheet();
    foreach(array_keys($types) as $type) {
        $path=ABSPATH.WPINC.'/blocks/'.$type.'/style.min.css';
        if(is_readable($path))$css.=file_get_contents($path);
    }
    $css.=WP_Style_Engine::compile_stylesheet_from_css_rules($rules);
    $css.='*,*::before,*::after{box-sizing:border-box}body{margin:0;font-family:system-ui,sans-serif;line-height:1.5}';
    return '<style>'.$css.'</style>'.$html;
}

function syndamorph_compile_block_plan( string $raw ) {
    try {
        if ( strlen( $raw ) > 120000 ) { throw new InvalidArgumentException( 'Plan too large.' ); }
        $raw=syndamorph_native_json_text($raw);
        $plan = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR );
        if ( ! is_array( $plan ) || array_diff( array_keys( $plan ), array( 'version', 'blocks' ) ) || ( $plan['version'] ?? null ) !== 1 ||
            ! is_array( $plan['blocks'] ?? null ) || ! array_is_list( $plan['blocks'] ) || count( $plan['blocks'] ) !== 1 || ( $plan['blocks'][0]['type'] ?? '' ) !== 'group' ) {
            throw new InvalidArgumentException( 'Expected one root group in a version 1 plan.' );
        }
        $count = 0; $headings = 0; $field = 'blocks[0]'; $previewIds = array(); $layoutFacts=array();
        $escape = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $length = static function ( $v ): string {
            $unit = '[0-9]{1,3}(?:\.[0-9]{1,6})?(?:px|rem|em|vw|vh|svh|dvh)';
            if ($v === 0 || $v === 0.0) $v='0';
            if ( ! is_string( $v ) || ! preg_match( '~^(?:0|' . $unit . '|clamp\(\s*' . $unit . '\s*,\s*' . $unit . '(?:\s+[+-]\s+' . $unit . ')?\s*,\s*' . $unit . '\s*\))$~D', $v ) ) { throw new InvalidArgumentException( 'Invalid length.' ); }
            return $v === '0' ? '0px' : $v;
        };
        $build = static function ( $node, string $parent, int $depth, string $path ) use ( &$build, &$count, &$headings, &$field, &$previewIds, &$layoutFacts, $escape, $length ): string {
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
            $previewIds[] = $node['id'] ?? $path;
            $style = $node['style'] ?? array();
            if ( ! is_array($style) ) { throw new InvalidArgumentException('Invalid style.'); }
            $attrs = array(); $css = array(); $classes = array();
            if ( $type !== 'paragraph' ) { $classes[] = 'wp-block-' . $type; }
            if ( $type === 'group' ) { $attrs['layout'] = array('type'=>'flex','orientation'=>'vertical'); $classes[]='is-layout-flex'; }
            if($type==='group' && isset($style['contentWidth']) && (isset($style['justifyContent'])||isset($style['verticalAlignment'])))throw new InvalidArgumentException('Constrained layout and flex alignment must use separate groups.');
            foreach ( $style as $key=>$value ) {
                $field = $path . '.style.' . preg_replace('/[^a-zA-Z0-9_-]/', '?', substr((string)$key,0,64));
                if ( in_array($key,array('color','background'),true) && in_array($type,array('group','column','heading','paragraph','button'),true) ) {
                    if ( ! is_string($value) || ! preg_match('/^#[0-9a-f]{6}$/iD',$value) ) { throw new InvalidArgumentException('Invalid color.'); }
                    $attrs['style']['color'][$key==='color'?'text':'background']=$value;
                    $classes[]=$key==='color'?'has-text-color':'has-background';
                    $css[$key==='color'?'color':'background-color']=$value;
                } elseif ( $key==='gradient' && in_array($type,array('group','column','button'),true) ) {
                    if(is_string($value))$value=preg_replace('/\\s*,\\s*/',',',trim($value));
                    if(!is_string($value)||!preg_match('/^linear-gradient\\((?:[0-9]{1,3})deg,#[0-9a-f]{6},#[0-9a-f]{6}\\)$/iD',$value)|| (int)substr($value,16,3)>360)throw new InvalidArgumentException('Invalid two-color gradient.');
                    $attrs['style']['color']['gradient']=$value;$css['background']=$value;$classes[]='has-background';
                } elseif ($key==='justifyContent' && in_array($type,array('group','buttons'),true)) {
                    if(!in_array($value,$type==='group'?array('left','center','right','stretch'):array('left','center','right'),true))throw new InvalidArgumentException('Invalid layout justification.');
                    if($type==='buttons')$attrs['layout']['type']='flex';
                    $attrs['layout']['justifyContent']=$value;
                } elseif ($key==='verticalAlignment' && $type==='group') {
                    if(!in_array($value,array('top','center','bottom'),true))throw new InvalidArgumentException('Invalid vertical alignment.');
                    $attrs['layout']['verticalAlignment']=$value;
                } elseif ($key==='contentWidth' && $type==='group') {
                    if(!is_string($value)||!preg_match('/^[0-9]{1,3}(?:\\.[0-9]{1,3})?(?:px|rem|em|ch)$/D',$value))throw new InvalidArgumentException('Invalid constrained content width.');
                    $attrs['layout']=array('type'=>'constrained','contentSize'=>$value);
                    $classes=array_values(array_diff($classes,array('is-layout-flex')));$classes[]='is-layout-constrained';
                } elseif ($key==='contentAlignment' && $type==='group') {
                    if(!isset($style['contentWidth'])||!in_array($value,array('left','center','right'),true))
                        throw new InvalidArgumentException('Invalid constrained content alignment.');
                } elseif ( $key==='minHeight' && $type==='group' ) {
                    $attrs['style']['dimensions']['minHeight']=$css['min-height']=$length($value);
                } elseif ( $key==='fontSize' && in_array($type,array('heading','paragraph','button'),true) ) {
                    $attrs['style']['typography']['fontSize']=$css['font-size']=$length($value);
                } elseif (in_array($key,array('padding','margin'),true) && (($key==='padding' && in_array($type,array('group','column','columns','buttons','heading','paragraph','button'),true)) || ($key==='margin' && in_array($type,array('group','columns','buttons','heading','paragraph'),true)))) {
                    if(is_array($value)) {
                        if (array_diff(array_keys($value),array('top','right','bottom','left')) || !count($value)) throw new InvalidArgumentException('Invalid spacing sides.');
                        $sides=$value;
                    } else { $sides=array_fill_keys(array('top','right','bottom','left'),$value); }
                    if ($key==='margin' && in_array($type,array('group','columns','buttons'),true) &&
                        array_diff(array_keys($sides),array('top','bottom'))) {
                        throw new InvalidArgumentException('This block supports only top and bottom margins.');
                    }
                    foreach($sides as $side=>$v) { $attrs['style']['spacing'][$key][$side]=$css[$key.'-'.$side]=$length($v); }
                } elseif ( $key==='fontWeight' && in_array($type,array('heading','paragraph','button'),true) ) {
                    if(is_int($value)) $value=(string)$value;
                    if(!is_string($value)||!preg_match('/^[1-9]00$/D',$value))throw new InvalidArgumentException('Invalid font weight.');
                    $attrs['style']['typography']['fontWeight']=$value;$css['font-weight']=$value;
                } elseif ( $key==='lineHeight' && in_array($type,array('heading','paragraph','button'),true) ) {
                    if(is_int($value)||is_float($value)) $value=(string)$value;
                    if(!is_string($value)||!preg_match('/^(?:1(?:\\.[0-9]{1,2})?|2(?:\\.[0-5])?)$/D',$value))throw new InvalidArgumentException('Invalid unitless line height.');
                    $attrs['style']['typography']['lineHeight']=$value;$css['line-height']=$value;
                } elseif ( $key==='letterSpacing' && in_array($type,array('heading','paragraph','button'),true) ) {
                    if(is_string($value)&&preg_match('/^-[0-9]{1,3}(?:\\.[0-9]{1,3})?(?:px|rem|em)$/D',$value)) $spacing=$value; else $spacing=$length($value);
                    $attrs['style']['typography']['letterSpacing']=$css['letter-spacing']=$spacing;
                } elseif ( $key==='textTransform' && in_array($type,array('heading','paragraph','button'),true) ) {
                    if(!in_array($value,array('none','uppercase','lowercase','capitalize'),true))throw new InvalidArgumentException('Invalid text transform.');
                    $attrs['style']['typography']['textTransform']=$css['text-transform']=$value;
                } elseif ( $key==='width' && $type==='button' && syndamorph_native_full_button_width() ) {
                    if($value!=='100%')throw new InvalidArgumentException('Invalid button width.');
                    $attrs['style']['dimensions']['width']=$value;
                } elseif ( $key==='borderRadius' && $type==='button' ) {
                    $attrs['style']['border']['radius']=$css['border-radius']=$length($value);
                } elseif ( $key==='gap' && in_array($type,array('group','columns','buttons'),true) ) {
                    $attrs['style']['spacing']['blockGap']=$length($value);
                } elseif ( $key==='align' && in_array($type,array('heading','paragraph'),true) && in_array($value,array('left','center','right'),true) ) {
                    $attrs[$type==='heading'?'textAlign':'align']=$value;
                    $classes[]='has-text-align-'.$value;
                } else { throw new InvalidArgumentException('Unsupported style for block.'); }
            }
            if(isset($style['contentAlignment']))$attrs['layout']['justifyContent']=$style['contentAlignment'];
            if(isset($attrs['layout'])||isset($attrs['style']['spacing']['blockGap'])) {
                $layoutFacts[$node['id']??$path]=array('layout'=>$attrs['layout']??null,
                    'blockGap'=>$attrs['style']['spacing']['blockGap']??null);
            }
            // Keep core support classes in the order emitted by Gutenberg.
            $classes = array_values(array_unique($classes));
            $base = array_values(array_filter($classes,static fn($c)=>strpos($c,'wp-block-')===0 || strpos($c,'has-text-align-')===0 || in_array($c,array('is-layout-flex','is-layout-constrained'),true)));
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
                    $link_classes=array_merge(array('wp-block-button__link'),array_values(array_diff($classes,array('wp-block-button'))),isset($attrs['style']['typography']['fontSize'])?array('has-custom-font-size'):array(),array('wp-element-button'));
                    $markup='<div class="wp-block-button"><a'.$appearance($link_classes,$style_parts).' href="'.$escape($href).'">'.$body.'</a></div>';
                }
            }
            $json=$attrs?' '.str_replace(array('--','<','>','&'),array('\\u002d\\u002d','\\u003c','\\u003e','\\u0026'),json_encode($attrs,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)):'';
            return '<!-- wp:'.$type.$json.' -->'.$markup.'<!-- /wp:'.$type.' -->';
        };
        $content=$build($plan['blocks'][0],'root',0,'blocks[0]');
        // Verify WordPress parser sees only expected native blocks when available.
        $parsedBlocks=null;
        if(function_exists('parse_blocks')){
            $verify=static function(array $blocks)use(&$verify):void{
                foreach($blocks as $b){
                    if(!in_array($b['blockName'],array('core/group','core/columns','core/column','core/heading','core/paragraph','core/buttons','core/button'),true))throw new InvalidArgumentException('Unexpected parsed block.');
                    $verify($b['innerBlocks']);
                }
            };
            $parsedBlocks=parse_blocks($content);
            $verify($parsedBlocks);
        }
        $preview='<style>*{box-sizing:border-box}body{margin:0;font-family:system-ui,sans-serif;line-height:1.5}:where(.wp-block-group.is-layout-flex){display:flex;flex-direction:column;flex-wrap:wrap;align-items:flex-start}:where(.wp-block-group.is-layout-constrained){display:block}:where(.wp-block-group) :where(h1,h2,h3,h4,h5,h6,p),.is-layout-flex>:is(*,div){margin:0}:where(.wp-block-columns){display:flex;gap:1.5rem}.wp-block-column{flex:1;min-width:0}:where(.wp-block-buttons){display:flex;flex-wrap:wrap;gap:1rem;align-items:center}.wp-block-button__link{display:inline-block;padding:.75em 1.5em;background:#26384a;color:#fff;text-decoration:none;border-radius:4px}.has-text-align-center{text-align:center}.has-text-align-right{text-align:right}@media(max-width:781px){.wp-block-columns{flex-direction:column}}</style>'.$content;
        $layoutCss=''; $layoutNotes=array();
        $themeStyles=function_exists('wp_get_global_styles') ? wp_get_global_styles() : array();
        $themeGapSupport=!function_exists('wp_get_global_settings') ||
            isset(wp_get_global_settings()['spacing']['blockGap']);
        $preview=preg_replace_callback('~<!-- wp:(group|buttons|columns) (\\{[^\\n]*?\\}) -->\\s*<div([^>]*)>~',static function($m)use(&$layoutCss,&$layoutNotes,$themeStyles,$themeGapSupport){
            $attrs=json_decode($m[2],true);$layout=$attrs['layout']??array();$rules='';$childRules='';
            $gap=$attrs['style']['spacing']['blockGap']??null;
            if ($gap!==null && (!$themeGapSupport ||
                !syndamorph_native_gap_supported($gap))) {
                $layoutNotes[]='WordPress does not apply the '.$m[1].' gap '.$gap.' in this native layout. Use a supported simple length or explicit child spacing.';
                $gap=null;
            }
            $themeGap=$themeStyles['blocks']['core/'.$m[1]]['spacing']['blockGap']??$themeStyles['spacing']['blockGap']??null;
            if ($gap===null && $themeGapSupport && is_string($themeGap) &&
                preg_match('/^(?:0|[0-9]{1,3}(?:\\.[0-9]+)?(?:px|rem|em|vw|vh))$/D',$themeGap)) {
                $gap=$themeGap;
            }
            // Serialized values come from the compiler. Theme values are validated separately.
            $width=$layout['contentSize']??'';
            if(preg_match('/^[0-9]{1,3}(?:\\.[0-9]{1,3})?(?:px|rem|em|ch)$/D',$width)) {
                // Core constrained layout overrides inline horizontal margins, but
                // explicit vertical margins still take precedence over its block gap.
                $left=($layout['justifyContent']??'')==='left'?'0':'auto';
                $right=($layout['justifyContent']??'')==='right'?'0':'auto';
                $childRules='max-width:'.$width.';margin-left:'.$left.'!important;margin-right:'.$right.'!important';
            } elseif (is_string($gap) && $gap !== '') {
                $rules='gap:'.$gap.';';
            }
            $horizontal=array('left'=>'flex-start','center'=>'center','right'=>'flex-end','stretch'=>'stretch');
            $vertical=array('top'=>'flex-start','center'=>'center','bottom'=>'flex-end');
            if(!$childRules && isset($horizontal[$layout['justifyContent']??'']))$rules.=($m[1]==='group'?'align-items:':'justify-content:').$horizontal[$layout['justifyContent']].';';
            if(isset($vertical[$layout['verticalAlignment']??''])&&$m[1]==='group')$rules.='justify-content:'.$vertical[$layout['verticalAlignment']].';';
            if(!$rules&&!$childRules)return $m[0];
            $class='sm-layout-'.substr(hash('sha256',json_encode(array($layout,$gap,$rules))),0,12);
            $opening=preg_replace('/class="/','class="'.$class.' ',$m[3],1);
            $style='.'.$class.'{'.$rules.'}';
            if($childRules) {
                $style.='.'.$class.' > :where(:not(.alignleft):not(.alignright):not(.alignfull)){'.$childRules.'}';
                if (is_string($gap) && $gap !== '') {
                    $style.='.'.$class.' > *{margin-block-start:0;margin-block-end:0}.'.$class.' > * + *{margin-block-start:'.$gap.'}';
                }
            }
            $layoutCss.=$style;
            return '<!-- wp:'.$m[1].' '.$m[2].' --><div'.$opening.'>';
        },$preview);
        $previewIndex = 0;
        $preview=preg_replace_callback('~<!-- wp:(group|columns|column|heading|paragraph|buttons|button)(?: (\{[^\n]*?\}))? -->\s*<(?:div|h[1-6]|p)(?=[ >])~',
            static function(array $match)use(&$previewIndex,$previewIds,$escape):string {
                $opening=$match[0].' data-sm-node="'.$escape($previewIds[$previewIndex++]).'"';
                $attrs=$match[1]==='button' && isset($match[2])?json_decode($match[2],true):array();
                if(($attrs['style']['dimensions']['width']??null)==='100%')$opening.=' data-sm-button-full';
                return $opening;
            },$preview);
        $layoutCss.='[data-sm-button-full]{flex-basis:100%;width:100%;max-width:none}[data-sm-button-full] .wp-block-button__link{width:100%;text-align:center}';
        $corePreview=$parsedBlocks!==null && function_exists('render_block') && class_exists('WP_Style_Engine') &&
            class_exists('WP_HTML_Tag_Processor');
        $preview=$corePreview?syndamorph_native_core_preview($parsedBlocks,$previewIds):'<style>'.$layoutCss.'</style>'.$preview;
        return array('schemaVersion'=>1,'content'=>$content,'preview'=>$preview,'blockCount'=>$count,
            'previewRendering'=>$corePreview?'wordpress-core':'standalone','layoutFacts'=>$layoutFacts,
            'layoutNotes'=>array_values(array_unique($layoutNotes)),
            'checks'=>array('valid_plan'=>true,'native_blocks_only'=>true,'contains_heading'=>$headings>0,'no_active_markup'=>true));
    } catch ( InvalidArgumentException | JsonException $e ) { return new WP_Error('sm_block_plan_invalid','Native block plan rejected: '.$e->getMessage(),array('status'=>422,'field'=>$field ?? 'plan','expected'=>syndamorph_block_plan_expected($e->getMessage(),$field ?? 'plan'))); }
    catch ( Throwable $e ) { return new WP_Error('sm_block_plan_system','Native block compilation encountered an internal error.',array('status'=>500)); }
}
