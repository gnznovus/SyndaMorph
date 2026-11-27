<?php
/** Native block plan v1: no arbitrary markup, CSS or block attributes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__.'/production-tabs.php';
require_once __DIR__.'/production-scroll-strip.php';
require_once __DIR__.'/production-disclosures.php';
require_once __DIR__.'/production-fonts.php';

function syndamorph_block_plan_decorative_group_rule(): string {
    return 'A nested decorative Group may have no children when it has a minHeight and a background color or gradient. Keep it empty to preserve literal copy.';
}

function syndamorph_native_square_grid_css(): string {
    return '.wp-block-group.sm-square-grid{width:100%}.wp-block-group.sm-square-grid>.wp-block-group{aspect-ratio:1;min-width:0;margin:0}';
}

function syndamorph_native_paragraph_wrap_css(): string {
    return 'p.sm-text-wrap-pretty{text-wrap:pretty}';
}

function syndamorph_native_text_smoothing_css(): string {
    // Chromium may otherwise paint RGB subpixel fringes on opaque text.
    // An identity filter requests grayscale compositing without recoloring the text.
    return ':is(p,h1,h2,h3,h4,h5,h6).sm-font-smoothing-grayscale{-webkit-font-smoothing:antialiased;filter:brightness(1)}';
}

function syndamorph_native_tile_aspect_css(): string {
    return '.wp-block-group.sm-aspect-4-3{width:100%;aspect-ratio:4/3}'.
        '.wp-block-group.sm-aspect-3-4{width:100%;aspect-ratio:3/4}';
}

if(function_exists('add_action'))add_action('enqueue_block_assets',static function():void {
    wp_register_style('syndamorph-square-grid',false);
    wp_enqueue_style('syndamorph-square-grid');
    wp_add_inline_style('syndamorph-square-grid',syndamorph_native_square_grid_css());
    wp_register_style('syndamorph-paragraph-wrap',false);
    wp_enqueue_style('syndamorph-paragraph-wrap');
    wp_add_inline_style('syndamorph-paragraph-wrap',syndamorph_native_paragraph_wrap_css());
    wp_add_inline_style('syndamorph-paragraph-wrap',syndamorph_native_text_smoothing_css());
    wp_register_style('syndamorph-tile-aspect',false);
    wp_enqueue_style('syndamorph-tile-aspect');
    wp_add_inline_style('syndamorph-tile-aspect',syndamorph_native_tile_aspect_css());
});

function syndamorph_block_plan_contract(): string {
    $buttonWidth=syndamorph_native_full_button_width()?' Button style.width accepts 100% across all viewports, using current core dimensions support.':'';
    $details=syndamorph_disclosures_supported()?' For a classified native disclosure group, add "disclosureGroup":true to a nested Group with the exact interaction ID. Its two to eight direct children use type "details", stable ID, plain summary in "text", and one editable Paragraph child. Set "open":true only on the first Details. The application supplies native exclusivity, indicator and focus styling. Use "disclosureAppearance":"bordered" on the same Group only for the approved bordered design appearance.':'';
    return 'Return a JSON object {"version":1,"blocks":[...]} with exactly one root group. Each node has type, a stable unique id and optional style. Supported nodes: group (children), columns (children of type column, 2–4; optional isStackedOnMobile:false only where the approved reference keeps the row), column (children), heading (text, optional preserveLineBreaks:true for two to six nonempty source lines separated by newlines, level 1–6), paragraph (text), buttons (children of type button), button (text, href: #fragment or /local-path), tabs (panels; no inline style). Only group/column may contain ordinary content blocks. The tabs node is the registered local Content Tabs block. Use it only for an approved tabbed-content capability. It accepts two to six ordered panels, each with unique lowercase id, plain label, heading and body. Tabs use local state and have no external integration. If the approved tabs reference uses the standard appearance, add "appearance":"standard" to that Tabs node. Omit appearance for the legacy theme-inherited appearance. '.syndamorph_block_plan_decorative_group_rule().' For evenly sized square decorative tiles, use a nested group with squareGrid set to an integer column count 2 to 4. Provide 2 to 12 direct empty Groups in row order, each styled with a background color or gradient and an optional minHeight when the approved specification explicitly requires it. Set the gap on the grid, and use the existing visibility variants for different column counts. For a measured editable Grid, set gridColumns to an integer from 2 to 4 on its Group and place ordinary editable children in the tracks. Omit other layout or interaction modes on that Group. Styles: color, background (six-digit hex), fontSize, fontWeight (100–900, integer or numeric string), lineHeight (headings also support 0.90 to 0.99 when required by the approved source, with a base range of 1–2.5, number or numeric string), letterSpacing (px/rem/em), padding (single length or object with top/right/bottom/left lengths on ordinary nodes), margin (single length or sides on heading/paragraph; top and bottom sides only on group/columns/buttons), textTransform (heading/paragraph/button: none, uppercase, lowercase, capitalize), gap (group/columns/buttons: simple length with theme block-gap support), borderRadius (button), gradient (group/column/button, safe two-color linear-gradient), minHeight (group), align (heading/paragraph left/center/right). Lengths: nonnegative px/rem/em/vw/vh/svh/dvh or clamp(length,length,length), including a bounded additive or subtractive middle expression such as 1.2rem + 3.5vw. Negative letterSpacing is supported. Group contentWidth (px/rem/em/ch) uses native constrained layout and applies the width to its children. contentAlignment (left/center/right) controls horizontal positioning within that constrained group. For a sole constrained Group inside a vertical flex Group, the outer justifyContent stretch lets the inner Group center its bounded children. Theme default horizontal padding may be overridden with explicit native padding matching the approved design. Group justifyContent (left/center/right/stretch) and verticalAlignment (top/center/bottom) use native vertical flex layout, incompatible with contentWidth on the same node. Group style.row set to true uses a native horizontal nonwrapping Row with left, center, right or space-between justification and optional verticalAlignment. Group style.flow set to true uses native Flow for vertical document order and cannot be combined with constrained, flex, Grid or scroll-strip layout on the same node. Column style.width takes a positive px length or percentage up to 100%. Column style.verticalAlignment accepts top, center or bottom. Buttons justifyContent (left/center/right) uses native flex layout. For a vertically centered section with bounded content, use separate flex and constrained groups. The element being constrained must be a child of the constrained group. Keep alignment, minHeight and width on the appropriate separate groups. Do not propose fluid blockGap: WordPress core discards clamp() for blockGap. Use responsive spacing on supported children if it matches the design. Mobile-only full-width CTA and breakpoint-specific per-block spacing are outside this contract.'.$buttonWidth.$details.' Do not substitute an always-full-width CTA or arbitrary leaf max-width. On Paragraphs, textDecoration may be none or underline. Set paragraph style.textWrap to pretty when the approved reference requires text-wrap: pretty. Use style.fontSmoothing set to grayscale on a paragraph or heading only when measured source text requires grayscale antialiasing and exact visual replay confirms that correction. For a measured decorative rectangle, use style.aspectRatio with the measured 4:3 or 3:4 value on a nested painted Group. For measured Group and Paragraph borders, style.border or style.borderTop accepts a single {"width":"1px","color":"#111111"} object with a solid line. Groups also support one style.borderRight or style.borderBottom using the same format. If the host fluid font rules alter a source-mandated fixed size, an explicit native clamp with equal bounds preserves it. Font family is inherited unless style.fontFamily selects a local installed preset slug on a group, heading or paragraph. Use only a slug listed in installedFontPresets. Native hover/focus styling is host-owned. Use a heading and meaningful CTA. No HTML, arbitrary CSS, remote URLs, unregistered blocks or invented attributes. When the approved design uses responsive Columns, match the native default: stack through 781 CSS pixels and use the side-by-side layout from 782 CSS pixels. Verify the 768 px layout. Do not force fixed widths. Example {"version":1,"blocks":[{"type":"group","style":{"background":"#18283a","padding":"clamp(2rem,5vw,5rem)"},"children":[{"type":"heading","level":2,"text":"Welcome","style":{"color":"#ffffff","fontSize":"clamp(2rem,5vw,4rem)"}},{"type":"paragraph","text":"Discover our hotel","style":{"color":"#ffffff"}},{"type":"buttons","children":[{"type":"button","text":"Explore","href":"#explore"}]}]}]}.';
}

function syndamorph_native_full_button_width(): bool {
    if(!class_exists('WP_Block_Type_Registry'))return true;
    $button=WP_Block_Type_Registry::get_instance()->get_registered('core/button');
    return !empty($button->supports['dimensions']['width']);
}

function syndamorph_native_gap_supported(string $gap): bool {
    return function_exists('wp_sanitize_block_gap_value') ? wp_sanitize_block_gap_value($gap)!==null : !str_contains($gap,'(');
}

/** Canonicalize only redundant syntax in the supported two-color gradient. */
function syndamorph_native_gradient($value,bool $allowEndpoints=false): ?string {
    if(!is_string($value))return null;
    $value=preg_replace('/\s*,\s*/',',',trim($value));
    if($allowEndpoints)
        $value=preg_replace('/^linear-gradient\(\s*(\d{1,3}deg,#[0-9a-f]{6})\s+0%(,#[0-9a-f]{6})\s+100%\)$/iD','linear-gradient($1$2)',$value);
    if(!preg_match('/^linear-gradient\((\d{1,3})deg,#[0-9a-f]{6},#[0-9a-f]{6}\)$/iD',$value,$angle) ||
        (int)$angle[1]>360)return null;
    return $value;
}

function syndamorph_native_json_text(string $raw): string {
    $raw=trim($raw);
    if(preg_match('/^```(?:json)?\\s*([\\s\\S]*?)\\s*```$/iD',$raw,$m))return trim($m[1]);
    return $raw;
}

/** Close only a fully emitted root block when the outer blocks array and object are missing. */
function syndamorph_decode_developer_plan(string $raw,?array &$recovery=null): ?array {
    $recovery=null;
    if(strlen($raw)>120000)return null;
    $text=syndamorph_native_json_text($raw);
    $plan=json_decode($text,true,32);
    if(is_array($plan)&&is_array($plan['blocks']??null))return $plan;
    if(json_last_error()!==JSON_ERROR_SYNTAX||!str_starts_with($text,'{')||!str_ends_with($text,'}'))return null;
    $closed=$text.']}';
    $plan=json_decode($closed,true,32);
    if(!is_array($plan)||count($plan)!==2||($plan['version']??null)!==1||!is_array($plan['blocks']??null)||
        !array_is_list($plan['blocks'])||count($plan['blocks'])!==1||($plan['blocks'][0]['type']??null)!=='group')return null;
    $recovery=array('operation'=>'close_outer_plan','beforeSha256'=>hash('sha256',$text),
        'afterSha256'=>hash('sha256',$closed),'appended'=>']}');
    return $plan;
}
/**
 * Actionable validation expectations. Keep these synchronized with the explicit
 * allowlists and patterns in syndamorph_compile_block_plan().
 */
function syndamorph_block_plan_expected(string $reason,string $field,string $block=''): array {
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
        'Unavailable font family.'=>array('format'=>'A local installed font preset slug from the runtime contract, or omit the field.'),
        'Invalid unitless line height.'=>array('format'=>'Unitless number from 1 through 2.5 with up to two decimal digits (2.x has at most one)','example'=>'1.5'),
        'Invalid constrained content alignment.'=>array('format'=>'left, center or right on a group with style.contentWidth','example'=>'left'),
        'Invalid constrained content width.'=>array('format'=>'Positive px/rem/em/ch length, up to 3 integer and 3 decimal digits','example'=>'40rem'),
        'Invalid heading level.'=>array('format'=>'Integer heading level from 1 to 6','example'=>2),
        'Invalid local CTA URL.'=>array('format'=>'Local #fragment or /local-path only; no external URLs','example'=>'#booking-options'),
        'Invalid layout justification.'=>array('format'=>'Group: left, center, right, stretch; buttons: left, center, right','example'=>'center'),
        'Invalid vertical alignment.'=>array('format'=>'top, center, bottom','example'=>'center'),
        'Invalid text alignment.'=>array('format'=>'left, center or right','example'=>'center'),
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
        'Unsupported node field.'=>array('format'=>'Use type, id, style and the fields for that block, such as text on leaves, preserveLineBreaks on Heading, or gridColumns on Group','example'=>'Put typography under style.fontSize'),
        'Preserving heading breaks requires two to six nonempty plain-text lines.'=>array(
            'format'=>'A heading with preserveLineBreaks:true must provide two to six nonempty plain-text lines separated by newlines.',
            'example'=>array('type'=>'heading','text'=>"Hotel Indigo\nBangkok Thonglor",'preserveLineBreaks'=>true)),
        'Fixed Grid requires two to four tracks and compatible Group children.'=>array(
            'format'=>'Use gridColumns:2–4 on a Group with enough children and no other layout or interaction mode.'),
        'Unsupported style for block.'=>array('format'=>'Only supported native style keys on compatible block types','example'=>'heading.style.fontSize = 2rem'),
        'Scroll strip needs a unique ID, mobile or compact visibility, and two to six visible Group cards.'=>array(
            'format'=>'Approved nested Group with scrollStrip:true, its interaction ID, style.visibility and two to six Group children.'),
    );
    if($reason==='Invalid length.' && $styleKey==='margin' && in_array($block,array('group','columns','buttons'),true)) {
        $length['constraint'].=' The margin must be an object with top or bottom keys.';
        $length['example']=array('top'=>'1rem');
        return $length;
    }
    if($reason==='Invalid length.' && $styleKey==='letterSpacing') {
        $length['constraint'].=' Negative px/rem/em values are also supported for letterSpacing.';
        $length['example']='-0.01em';
        return $length;
    }
    if($reason==='Invalid length.' && $styleKey==='gap') {
        $length['constraint'].=' Some WordPress themes may not render clamp() blockGap despite compiler acceptance; verify in WordPress.';
        return $length;
    }
    if($reason==='Invalid children.') {
        $description=array(
            'group'=>'Use a list of supported child blocks. '.syndamorph_block_plan_decorative_group_rule(),
            'column'=>'Use a nonempty list of supported content blocks.',
            'columns'=>'Use a list of two to four Column blocks.',
            'buttons'=>'Use a nonempty list of Button blocks.',
        );
        if(isset($description[$block]))return array('format'=>$description[$block]);
    }
    if(isset($map[$reason]))return $map[$reason];
    return array('format'=>'Valid native block plan satisfying the compiler contract','example'=>'Consult the minimal native block plan contract.');
}

/**
 * Inspect the same block rendering and per-instance styles WordPress uses on the front end.
 * Diagnostic node attributes are added to the parsed copy only.
 */
function syndamorph_native_core_preview(array $blocks,?array $ids=null,bool $pageWidth=false): string {
    $index=0;$types=array();
    $annotate=static function(array $block)use(&$annotate,&$index,&$types,$ids):array {
        if($block['blockName']==='syndamorph/tabs'){
            $types[$block['blockName']]=true;
            if($ids!==null){
                if(!isset($ids[$index]))throw new RuntimeException('Tabs preview identity missing.');
                $block['attrs']['component']=$ids[$index++];
            }
            return $block;
        }
        if($block['blockName']==='syndamorph/booking-summary'){
            $types[$block['blockName']]=true;
            if($ids!==null){
                if(!isset($ids[$index]))throw new RuntimeException('Booking preview identity missing.');
                $block['attrs']['previewComponent']=$ids[$index++];
            }
            return $block;
        }
        if($ids!==null){
            $tag=new WP_HTML_Tag_Processor($block['innerContent'][0]??'');
            if(!$tag->next_tag()||!isset($ids[$index]))throw new RuntimeException('Native block preview identity missing.');
            $tag->set_attribute('data-sm-node',$ids[$index++]);
            $block['innerContent'][0]=$tag->get_updated_html();
        }
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
    if(array_intersect_key($classes,array_flip(array('has-text-align-left','has-text-align-center','has-text-align-right')))){
        $common=ABSPATH.WPINC.'/css/dist/block-library/common.min.css';
        if(is_readable($common)&&preg_match_all('/\.(has-text-align-(?:left|center|right))\{[^}]+\}/',file_get_contents($common),$aligned,PREG_SET_ORDER))
            foreach($aligned as $rule)if(isset($classes[$rule[1]]))$css.=$rule[0];
    }
    foreach(array_keys($types) as $type) {
        if($type==='syndamorph/tabs'){
            $css.=syndamorph_tabs_asset('production-tabs.css');
            continue;
        }
        if($type==='syndamorph/booking-summary'){
            $css.=file_get_contents(dirname(__DIR__).'/assets/production-booking.css');
            continue;
        }
        if($type==='syndamorph/desktop-offer'){
            $css.=file_get_contents(dirname(__DIR__).'/assets/production-offer.css');
            continue;
        }
        $path=ABSPATH.WPINC.'/blocks/'.$type.'/style.min.css';
        if(is_readable($path))$css.=file_get_contents($path);
    }
    $css.=WP_Style_Engine::compile_stylesheet_from_css_rules($rules);
    if(isset($classes['sm-scroll-strip']))$css.=syndamorph_scroll_strip_css();
    if(isset($classes['sm-source-image-strip']))$css.=syndamorph_image_strip_css();
    if(isset($classes['sm-linked-editorial']))$css.=syndamorph_linked_editorial_css();
    if(isset($classes['sm-reveal-layout']))$css.=syndamorph_reveal_layout_css();
    if(isset($classes['sm-rooms-layout']))$css.=syndamorph_rooms_asset('production-rooms.css');
    if(isset($classes['sm-square-grid']))$css.=syndamorph_native_square_grid_css();
    if(isset($classes['sm-disclosure-group']))$css.=syndamorph_disclosures_css();
    if(isset($classes['sm-text-wrap-pretty']))$css.=syndamorph_native_paragraph_wrap_css();
    if(isset($classes['sm-font-smoothing-grayscale']))$css.=syndamorph_native_text_smoothing_css();
    if(isset($classes['sm-aspect-4-3'])||isset($classes['sm-aspect-3-4']))$css.=syndamorph_native_tile_aspect_css();
    $css.='*,*::before,*::after{box-sizing:border-box}body{margin:0;font-family:system-ui,sans-serif;line-height:1.5}';
    return '<style>'.$css.'</style>'.($pageWidth?syndamorph_native_page_width_body():'').$html;
}

/** Apply core's Post Content layout in isolated width measurements only. */
function syndamorph_native_page_width_body(): string {
    $padding=!empty(wp_get_global_settings()['useRootPaddingAwareAlignments'])?' has-global-padding':'';
    return '<body class="wp-block-post-content is-layout-constrained'.$padding.'">';
}

function syndamorph_native_visibility_css(): string {
    return '@media(max-width:1023px){.sm-view-desktop{display:none!important}}'.
        '@media(min-width:1024px){.sm-view-compact,.sm-view-tablet,.sm-view-mobile{display:none!important}}'.
        '@media(max-width:599px){.sm-view-tablet{display:none!important}}'.
        '@media(min-width:600px){.sm-view-mobile{display:none!important}}';
}
function syndamorph_compile_block_plan( string $raw ) {
    try {
        if ( strlen( $raw ) > 120000 ) { throw new InvalidArgumentException( 'Plan too large.' ); }
        $raw=syndamorph_native_json_text($raw);
        $plan = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR );
        if ( ! is_array( $plan ) || array_diff( array_keys( $plan ), array( 'version', 'blocks', 'sectionAlignment' ) ) || ( $plan['version'] ?? null ) !== 1 ||
            ! is_array( $plan['blocks'] ?? null ) || ! array_is_list( $plan['blocks'] ) || count( $plan['blocks'] ) !== 1 || ( $plan['blocks'][0]['type'] ?? '' ) !== 'group' ) {
            throw new InvalidArgumentException( 'Expected one root group in a version 1 plan.' );
        }
        $sectionAlignment=$plan['sectionAlignment']??null;
        if(array_key_exists('sectionAlignment',$plan)&&!in_array($sectionAlignment,array('content','wide','full'),true)){
            $field='sectionAlignment';
            throw new InvalidArgumentException('Invalid section alignment.');
        }
        $groupAlignment=in_array($sectionAlignment,array('wide','full'),true)?$sectionAlignment:null;
        if($groupAlignment!==null&&class_exists('WP_Block_Type_Registry')&&
            !in_array($groupAlignment,(array)(WP_Block_Type_Registry::get_instance()->get_registered('core/group')->supports['align']??array()),true)){
            $field='sectionAlignment';
            throw new InvalidArgumentException('Core Group does not support the approved section alignment.');
        }
        $count = 0; $headings = 0; $field = 'blocks[0]'; $previewIds = array(); $layoutFacts=array(); $schemaFacts=array();
        $tabs=array();$scrollStrips=array();$disclosures=array();$block='';$fonts=syndamorph_production_font_families();
        $escape = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $length = static function ( $v ): string {
            $unit = '[0-9]{1,3}(?:\.[0-9]{1,6})?(?:px|rem|em|vw|vh|svh|dvh)';
            if ($v === 0 || $v === 0.0) $v='0';
            if ( ! is_string( $v ) || ! preg_match( '~^(?:0|' . $unit . '|clamp\(\s*' . $unit . '\s*,\s*' . $unit . '(?:\s+[+-]\s+' . $unit . ')?\s*,\s*' . $unit . '\s*\))$~D', $v ) ) { throw new InvalidArgumentException( 'Invalid length.' ); }
            return $v === '0' ? '0px' : $v;
        };
        $build = static function ( $node, string $parent, int $depth, string $path, ?string $disclosureGroup=null ) use ( &$build, &$count, &$headings, &$field, &$previewIds, &$layoutFacts, &$schemaFacts, &$tabs, &$scrollStrips, &$disclosures, &$block, $escape, $length, $fonts, $groupAlignment ): string {
            $field = $path;$block='';
            if ( ! is_array( $node ) || ++$count > 120 || $depth > 12 ) { throw new InvalidArgumentException( 'Invalid node or plan limit exceeded.' ); }
            $type = $node['type'] ?? '';
            $allowed = array( 'root'=>array('group'), 'group'=>array('group','columns','heading','paragraph','buttons','tabs'), 'column'=>array('group','columns','heading','paragraph','buttons','tabs'), 'columns'=>array('column'), 'buttons'=>array('button'),'squareGrid'=>array('group'),
                'disclosureGroup'=>array('details'),'details'=>array('paragraph') );
            if ( ! is_string( $type ) || ! in_array( $type, $allowed[$parent] ?? array(), true ) ) { throw new InvalidArgumentException( 'Unsupported block or nesting.' ); }
            $block=$type;
            $container = in_array( $type, array('group','columns','column','buttons'), true );
            $keys = array_merge(array('type','style','id'),$container?array('children'):($type==='tabs'?array('panels','appearance'):array('text')),
                $type==='heading'?array('level','preserveLineBreaks'):array(),$type==='button'?array('href'):array(),
                $type==='columns'?array('isStackedOnMobile'):array(),
                $type==='group'?array('scrollStrip','squareGrid','gridColumns','disclosureGroup','disclosureAppearance'):array(),
                $type==='details'?array('children','open'):array());
            if ( $unknown = array_diff( array_keys($node), $keys ) ) {
                $key = (string) reset($unknown);
                $field = $path . '.' . preg_replace('/[^a-zA-Z0-9_-]/', '?', substr($key,0,64));
                throw new InvalidArgumentException( $key === 'align' ? 'Alignment belongs inside style.align.' : 'Unsupported node field.' );
            }
            if (isset($node['id']) && (!is_string($node['id']) || !preg_match('/^[a-zA-Z0-9_.\\[\\]-]{1,100}$/D', $node['id']))) { $field=$path.'.id'; throw new InvalidArgumentException('Invalid component ID.'); }
            $previewIds[] = $node['id'] ?? $path;
            $style = $node['style'] ?? array();
            if ( ! is_array($style) ) { $field=$path.'.style'; throw new InvalidArgumentException('Invalid style.'); }
            if($type==='details'){
                $text=$node['text']??null;$paragraphs=$node['children']??null;
                $open=$node['open']??false;
                if($style||!is_string($text)||trim($text)===''||mb_strlen($text)>240||!is_bool($open)||
                    !is_array($paragraphs)||!array_is_list($paragraphs)||count($paragraphs)!==1||
                    ($paragraphs[0]['type']??null)!=='paragraph')
                    throw new InvalidArgumentException('Details requires a plain summary and one editable answer paragraph.');
                $answer=$build($paragraphs[0],'details',$depth+1,$path.'.children[0]');
                $normalize=static fn(string $value):string=>trim(preg_replace('/\s+/u',' ',$value)??'');
                $disclosures[$disclosureGroup][]=array('summary'=>$normalize($text),'answer'=>$normalize($paragraphs[0]['text']));
                return '<!-- wp:details'.($open?' {"showContent":true}':'').' --><details class="wp-block-details" name="sm-disclosure-'.
                    $disclosureGroup.'"'.($open?' open':'').'><summary>'.$escape($text).'</summary>'.$answer.'</details><!-- /wp:details -->';
            }
            if($type==='tabs'){
                $field=$path.'.panels';
                $panels=syndamorph_tabs_panels($node['panels']??null);
                if($panels===null||$style)throw new InvalidArgumentException('Tabs need two to six identified plain-text panels and no inline style.');
                $headings+=count($panels);
                $schemaFacts['interactive-tabs']=true;
                $attrs=array('component'=>$node['id']??$path,'panelData'=>base64_encode(
                    json_encode($panels,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)));
                if(array_key_exists('appearance',$node)){
                    $field=$path.'.appearance';
                    if($node['appearance']!=='standard')throw new InvalidArgumentException('Unsupported Tabs appearance.');
                    $attrs['appearance']='standard';
                }
                if(isset($tabs[$attrs['component']])||count($tabs)>=12)throw new InvalidArgumentException('Duplicate tabs ID or widget limit exceeded.');
                $tabs[$attrs['component']]=$panels;
                $json=function_exists('serialize_block_attributes')?serialize_block_attributes($attrs):
                    str_replace('--','\u002d\u002d',json_encode($attrs,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR));
                return '<!-- wp:syndamorph/tabs '.$json.' /-->';
            }
            $attrs = array(); $css = array(); $classes = array(); $borderCss=array();
            if ( $type !== 'paragraph' ) { $classes[] = 'wp-block-' . $type; }
            if($parent==='root'&&$groupAlignment!==null){
                $attrs['align']=$groupAlignment;
                $classes[]='align'.$groupAlignment;
            }
            if($type==='columns'&&array_key_exists('isStackedOnMobile',$node)){
                $field=$path.'.isStackedOnMobile';
                if(!is_bool($node['isStackedOnMobile']))throw new InvalidArgumentException('Column stacking must be boolean.');
                if(!$node['isStackedOnMobile']){
                    $attrs['isStackedOnMobile']=false;
                    $classes[]='is-not-stacked-on-mobile';
                }
            }
            if ( $type === 'group' ) { $attrs['layout'] = array('type'=>'flex','orientation'=>'vertical'); $classes[]='is-layout-flex'; }
            if(array_key_exists('row',$style)){
                $field=$path.'.style.row';
                if($type!=='group'||$style['row']!==true||isset($style['flow'])||isset($style['contentWidth'])||
                    array_key_exists('squareGrid',$node)||array_key_exists('scrollStrip',$node))
                    throw new InvalidArgumentException('Row layout requires an ordinary Group without Flow or constrained layout.');
                $attrs['layout']['orientation']='horizontal';
                $attrs['layout']['flexWrap']='nowrap';
                unset($style['row']);
            }
            if(array_key_exists('flow',$style)){
                $field=$path.'.style.flow';
                if($type!=='group'||$style['flow']!==true||isset($style['contentWidth'])||
                    isset($style['justifyContent'])||isset($style['verticalAlignment'])||
                    array_key_exists('squareGrid',$node)||array_key_exists('scrollStrip',$node))
                    throw new InvalidArgumentException('Flow layout requires a Group without constrained or flex alignment.');
                $attrs['layout']=array('type'=>'default');
                $classes=array_values(array_diff($classes,array('is-layout-flex')));
                unset($style['flow']);
            }
            if($type==='group' && isset($style['contentWidth']) && (isset($style['justifyContent'])||isset($style['verticalAlignment'])))throw new InvalidArgumentException('Constrained layout and flex alignment must use separate groups.');
            $borderSides=array('border'=>'','borderTop'=>'top');
            if($type==='group')$borderSides+=array('borderRight'=>'right','borderBottom'=>'bottom');
            foreach ( $style as $key=>$value ) {
                $field = $path . '.style.' . preg_replace('/[^a-zA-Z0-9_-]/', '?', substr((string)$key,0,64));
                if ( in_array($key,array('color','background'),true) && in_array($type,array('group','column','heading','paragraph','button'),true) ) {
                    if ( ! is_string($value) || ! preg_match('/^#[0-9a-f]{6}$/iD',$value) ) { throw new InvalidArgumentException('Invalid color.'); }
                    $attrs['style']['color'][$key==='color'?'text':'background']=$value;
                    $classes[]=$key==='color'?'has-text-color':'has-background';
                    $css[$key==='color'?'color':'background-color']=$value;
                } elseif ( $key==='gradient' && in_array($type,array('group','column','button'),true) ) {
                    $value=syndamorph_native_gradient($value);
                    if($value===null)throw new InvalidArgumentException('Invalid two-color gradient.');
                    $attrs['style']['color']['gradient']=$value;$css['background']=$value;$classes[]='has-background';
                } elseif ($key==='justifyContent' && in_array($type,array('group','buttons'),true)) {
                    $allowedJustification=($attrs['layout']['orientation']??null)==='horizontal'?
                        array('left','center','right','space-between'):
                        ($type==='group'?array('left','center','right','stretch'):array('left','center','right'));
                    if(!in_array($value,$allowedJustification,true))throw new InvalidArgumentException('Invalid layout justification.');
                    if($type==='buttons')$attrs['layout']['type']='flex';
                    $attrs['layout']['justifyContent']=$value;
                } elseif ($key==='verticalAlignment' && $type==='group') {
                    if(!in_array($value,array('top','center','bottom'),true))throw new InvalidArgumentException('Invalid vertical alignment.');
                    $attrs['layout']['verticalAlignment']=$value;
                } elseif ($key==='verticalAlignment' && $type==='column') {
                    if(!in_array($value,array('top','center','bottom'),true))throw new InvalidArgumentException('Invalid vertical alignment.');
                    $attrs['verticalAlignment']=$value;
                    $classes[]='is-vertically-aligned-'.$value;
                } elseif ($key==='contentWidth' && $type==='group') {
                    if(!is_string($value)||!preg_match('/^[0-9]{1,3}(?:\\.[0-9]{1,3})?(?:px|rem|em|ch)$/D',$value))throw new InvalidArgumentException('Invalid constrained content width.');
                    $attrs['layout']=array('type'=>'constrained','contentSize'=>$value);
                    $classes=array_values(array_diff($classes,array('is-layout-flex')));$classes[]='is-layout-constrained';
                } elseif ($key==='contentAlignment' && $type==='group') {
                    if(!isset($style['contentWidth'])||!in_array($value,array('left','center','right'),true))
                        throw new InvalidArgumentException('Invalid constrained content alignment.');
                } elseif ( $key==='minHeight' && $type==='group' ) {
                    $attrs['style']['dimensions']['minHeight']=$css['min-height']=$length($value);
                } elseif ( $key==='visibility' && $type==='group' && $parent!=='root' ) {
                    if(!in_array($value,array('desktop','compact','tablet','mobile'),true))throw new InvalidArgumentException('Invalid captured viewport variant.');
                    $attrs['className']='sm-view-'.$value;
                } elseif ( $key==='fontSize' && in_array($type,array('heading','paragraph','button'),true) ) {
                    $attrs['style']['typography']['fontSize']=$css['font-size']=$length($value);
                } elseif ( $key==='fontFamily' && in_array($type,array('group','heading','paragraph'),true) ) {
                    if(!is_string($value)||!isset($fonts[$value]))throw new InvalidArgumentException('Unavailable font family.');
                    $attrs['fontFamily']=$value;
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
                    $tightHeading=$type==='heading'&&is_string($value)&&preg_match('/^0\.9[0-9]?$/D',$value);
                    if(!$tightHeading&&(!is_string($value)||!preg_match('/^(?:1(?:\\.[0-9]{1,2})?|2(?:\\.[0-5])?)$/D',$value)))throw new InvalidArgumentException('Invalid unitless line height.');
                    $attrs['style']['typography']['lineHeight']=$value;$css['line-height']=$value;
                } elseif ( $key==='letterSpacing' && in_array($type,array('heading','paragraph','button'),true) ) {
                    if(is_string($value)&&preg_match('/^-[0-9]{1,3}(?:\\.[0-9]{1,3})?(?:px|rem|em)$/D',$value)) $spacing=$value; else $spacing=$length($value);
                    $attrs['style']['typography']['letterSpacing']=$css['letter-spacing']=$spacing;
                } elseif ( $key==='textTransform' && in_array($type,array('heading','paragraph','button'),true) ) {
                    if(!in_array($value,array('none','uppercase','lowercase','capitalize'),true))throw new InvalidArgumentException('Invalid text transform.');
                    $attrs['style']['typography']['textTransform']=$css['text-transform']=$value;
                } elseif ( $key==='textDecoration' && $type==='paragraph' ) {
                    if(!in_array($value,array('none','underline'),true))throw new InvalidArgumentException('Invalid text decoration.');
                    $attrs['style']['typography']['textDecoration']=$css['text-decoration']=$value;
                } elseif ( $key==='textWrap' && $type==='paragraph' ) {
                    if($value!=='pretty')throw new InvalidArgumentException('Paragraph text wrapping supports only pretty.');
                    $attrs['className']='sm-text-wrap-pretty';
                } elseif ( $key==='fontSmoothing' && in_array($type,array('paragraph','heading'),true) ) {
                    if($value!=='grayscale')throw new InvalidArgumentException('Text font smoothing supports only grayscale.');
                } elseif ( $key==='width' && $type==='column' ) {
                    if(!is_string($value)||!preg_match('/^[1-9][0-9]{0,2}(?:\.[0-9]{1,3})?(?:px|%)$/D',$value)||
                        (str_ends_with($value,'%')&&(float)$value>100))throw new InvalidArgumentException('Invalid column width.');
                    $attrs['width']=$css['flex-basis']=$value;
                } elseif ( $key==='width' && $type==='button' && syndamorph_native_full_button_width() ) {
                    if($value!=='100%')throw new InvalidArgumentException('Invalid button width.');
                    $attrs['style']['dimensions']['width']=$value;
                } elseif ( $key==='borderRadius' && $type==='button' ) {
                    $attrs['style']['border']['radius']=$css['border-radius']=$length($value);
                } elseif (array_key_exists($key,$borderSides)&&in_array($type,array('group','paragraph'),true)) {
                    if(!is_array($value)||count($value)!==2||!is_string($value['width']??null)||
                        !preg_match('/^[1-4]px$/D',$value['width'])||!is_string($value['color']??null)||
                        !preg_match('/^#[0-9a-f]{6}$/iD',$value['color'])||count(array_intersect_key($style,$borderSides))!==1)
                        throw new InvalidArgumentException('Use one solid border with a 1 to 4 px width and a six-digit color.');
                    $border=array('color'=>$value['color'],'style'=>'solid','width'=>$value['width']);
                    $side=$borderSides[$key];
                    $attrs['style']['border']=$side===''?$border:array($side=>$border);
                    foreach($border as $property=>$measurement)
                        $borderCss['border-'.($side===''?'':$side.'-').$property]=$measurement;
                    if($side==='')$classes[]='has-border-color';
                } elseif ($key==='aspectRatio'&&$type==='group'&&$parent!=='root') {
                    if(!in_array($value,array('4:3','3:4'),true)||(!isset($style['background'])&&!isset($style['gradient']))||
                        !empty($node['scrollStrip'])||isset($node['squareGrid'])||
                        isset($node['disclosureGroup'])||isset($style['minHeight']))
                        throw new InvalidArgumentException('A decorative 4:3 or 3:4 tile requires an ordinary Group without a forced height.');
                } elseif ( $key==='gap' && in_array($type,array('group','columns','buttons'),true) ) {
                    $attrs['style']['spacing']['blockGap']=$length($value);
                } elseif ( $key==='align' && in_array($type,array('heading','paragraph'),true) ) {
                    if(!in_array($value,array('left','center','right'),true))throw new InvalidArgumentException('Invalid text alignment.');
                    $attrs[$type==='heading'?'textAlign':'align']=$value;
                    $classes[]='has-text-align-'.$value;
                } else { throw new InvalidArgumentException('Unsupported style for block.'); }
            }
            if(isset($style['fontSmoothing']))
                $attrs['className']=trim(($attrs['className']??'').' sm-font-smoothing-grayscale');
            if(isset($style['aspectRatio']))
                $attrs['className']=trim(($attrs['className']??'').' sm-aspect-'.str_replace(':','-',$style['aspectRatio']));
            if(isset($style['contentAlignment']))$attrs['layout']['justifyContent']=$style['contentAlignment'];
            if(array_key_exists('disclosureAppearance',$node)&&
                (($node['disclosureGroup']??null)!==true||$node['disclosureAppearance']!=='bordered'))
                throw new InvalidArgumentException('The bordered appearance requires a native disclosure group.');
            if(array_key_exists('disclosureGroup',$node)){
                $field=$path.'.disclosureGroup';
                $id=$node['id']??'';$items=$node['children']??null;
                if(!syndamorph_disclosures_supported()||$node['disclosureGroup']!==true||$parent==='root'||
                    !preg_match('/^[a-z][a-z0-9-]{0,47}$/D',$id)||isset($disclosures[$id])||count($disclosures)>=4||
                    array_key_exists('squareGrid',$node)||array_key_exists('scrollStrip',$node)||
                    array_intersect(array_keys($style),array('row','contentWidth','justifyContent','verticalAlignment','visibility'))||
                    !is_array($items)||!array_is_list($items)||count($items)<2||count($items)>8)
                    throw new InvalidArgumentException('Disclosure group requires a supported WordPress host and two to eight Details children.');
                foreach($items as $index=>$item){
                    if(($item['type']??null)!=='details'||($item['open']??false)!==($index===0))
                        throw new InvalidArgumentException('Disclosure group requires only its first Details child open initially.');
                }
                $attrs['layout']=array('type'=>'default');
                $attrs['className']='sm-disclosure-group sm-disclosure-id-'.$id;
                if(isset($node['disclosureAppearance']))$attrs['className'].=' sm-disclosure-bordered';
                $classes=array_values(array_diff($classes,array('is-layout-flex')));
                $disclosures[$id]=array();
                $schemaFacts['grouped-details']=true;
            }
            if(array_key_exists('gridColumns',$node)){
                $field=$path.'.gridColumns';
                $columns=$node['gridColumns'];
                $children=$node['children']??null;
                if(!is_int($columns)||$columns<2||$columns>4||!is_array($children)||!array_is_list($children)||
                    count($children)<$columns||count($children)>12||
                    array_intersect(array_keys($node),array('squareGrid','scrollStrip','disclosureGroup'))||
                    array_intersect(array_keys($node['style']??array()),array('row','flow','contentWidth','contentAlignment','justifyContent','verticalAlignment')))
                    throw new InvalidArgumentException('Fixed Grid requires two to four tracks and compatible Group children.');
                $attrs['layout']=array('type'=>'grid','columnCount'=>$columns);
                $classes=array_values(array_diff($classes,array('is-layout-flex')));
                $classes[]='is-layout-grid';
            }
            if(array_key_exists('squareGrid',$node)){
                $field=$path.'.squareGrid';
                $columns=$node['squareGrid'];$tiles=$node['children']??null;
                if($parent==='root'||$parent==='squareGrid'||isset($node['scrollStrip'])||!is_int($columns)||$columns<2||$columns>4||
                    !is_array($tiles)||!array_is_list($tiles)||count($tiles)<$columns||count($tiles)>12||
                    array_diff(array_keys($style),array('gap','margin','visibility'))||array_filter($tiles,static fn($tile)=>
                        !is_array($tile)||($tile['type']??null)!=='group'||!empty($tile['children'])||
                        array_diff(array_keys($tile),array('id','type','style','children'))||!is_array($tile['style']??null)||
                        array_diff(array_keys($tile['style']),array('background','gradient','minHeight'))||
                        count(array_intersect_key($tile['style'],array('background'=>true,'gradient'=>true)))!==1))
                    throw new InvalidArgumentException('Square grid needs two to four columns and direct painted decorative Groups.');
                $attrs['layout']=array('type'=>'grid','columnCount'=>$columns);
                $attrs['className']=trim(($attrs['className']??'').' sm-square-grid');
                $classes=array_values(array_diff($classes,array('is-layout-flex')));
                $classes[]='is-layout-grid';
                $schemaFacts['square-grid']=true;
            }
            if(array_key_exists('scrollStrip',$node)){
                $field=$path.'.scrollStrip';
                $id=$node['id']??'';
                $cards=$node['children']??null;
                if($node['scrollStrip']!==true||$parent==='root'||!preg_match('/^[a-z][a-z0-9-]{0,47}$/D',$id)||
                    !in_array($style['visibility']??null,array('mobile','compact'),true)||count($style)!==1||
                    !is_array($cards)||!array_is_list($cards)||count($cards)<2||count($cards)>6||
                    array_filter($cards,static fn($card)=>($card['type']??null)!=='group'||isset($card['style']['visibility'])||isset($card['scrollStrip']))||
                    isset($scrollStrips[$id])||count($scrollStrips)>=4)
                    throw new InvalidArgumentException('Scroll strip needs a unique ID, mobile or compact visibility, and two to six visible Group cards.');
                $attrs['layout']['orientation']='horizontal';
                $attrs['layout']['flexWrap']='nowrap';
                $attrs['className'].=' sm-scroll-strip';
                $scrollStrips[$id]=array('visibility'=>$style['visibility'],'cards'=>count($cards));
                $schemaFacts['horizontal-scroll-strip']=true;
            }
            if(isset($attrs['layout'])||isset($attrs['style']['spacing']['blockGap'])||isset($attrs['isStackedOnMobile'])) {
                $layoutFacts[$node['id']??$path]=array('layout'=>$attrs['layout']??null,
                    'blockGap'=>$attrs['style']['spacing']['blockGap']??null);
                if(isset($attrs['isStackedOnMobile']))
                    $layoutFacts[$node['id']??$path]['isStackedOnMobile']=$attrs['isStackedOnMobile'];
                if($parent==='root'&&$groupAlignment!==null)
                    $layoutFacts[$node['id']??$path]['align']=$groupAlignment;
            }
            // Keep core support classes in the order emitted by Gutenberg.
            $classes = array_values(array_unique($classes));
            $base = array_values(array_filter($classes,static fn($c)=>strpos($c,'wp-block-')===0 || strpos($c,'has-text-align-')===0 ||
                strpos($c,'is-vertically-aligned-')===0||in_array($c,array('alignwide','alignfull','is-layout-flex','is-layout-constrained','is-layout-grid','is-not-stacked-on-mobile'),true)));
            foreach(array('has-text-color','has-background','has-border-color') as $c) { if(in_array($c,$classes,true))$base[]=$c; }
            if(isset($attrs['fontFamily']))$base[]='has-'.$attrs['fontFamily'].'-font-family';
            if(isset($attrs['className']))$base[]=$attrs['className'];
            $classes=$base;
            $style_parts=array(); foreach($borderCss+$css as $k=>$v)$style_parts[]=$k.':'.$v;
            $appearance=static fn(array $c,array $s): string => ($c?' class="'.$escape(implode(' ',$c)).'"':'').($s?' style="'.$escape(implode(';',$s)).'"':'');
            $field = $path;
            if ($container) {
                $field = $path . '.children';
                $children=$node['children']??null;
                $emptyDecoration=$type==='group' && $parent!=='root' &&
                    (!array_key_exists('children',$node)||$children===array()) &&
                    ($parent==='squareGrid'||isset($style['minHeight'])||isset($style['aspectRatio'])) &&
                    (isset($style['background'])||isset($style['gradient']));
                if($emptyDecoration) {
                    $children=array();
                    $schemaFacts['decorative_group']=true;
                }elseif(!is_array($children)||!array_is_list($children)||!count($children)||
                    ($type==='columns'&&(count($children)<2||count($children)>4))) {
                    throw new InvalidArgumentException('Invalid children.');
                }
                $childParent=isset($node['squareGrid'])?'squareGrid':(isset($node['disclosureGroup'])?'disclosureGroup':$type);
                $inner='';foreach($children as $i=>$child)$inner.=$build($child,$childParent,$depth+1,$path.'.children['.$i.']',
                    $childParent==='disclosureGroup'?$node['id']:null);
                $markup='<div'.$appearance($classes,$style_parts).'>'.$inner.'</div>';
            } else {
                $field = $path . '.text';
                if(!is_string($node['text']??null)||trim($node['text'])===''||strlen($node['text'])>8000)throw new InvalidArgumentException('Invalid text.');
                $body=$escape($node['text']);
                if($type==='heading'){
                    if(array_key_exists('preserveLineBreaks',$node)){
                        $field=$path.'.preserveLineBreaks';
                        $lines=preg_split('/\r\n|\n|\r/',$node['text']);
                        if($node['preserveLineBreaks']!==true||count($lines)<2||count($lines)>6||
                            array_filter($lines,static fn($line)=>trim($line)===''))
                            throw new InvalidArgumentException('Preserving heading breaks requires two to six nonempty plain-text lines.');
                        $body=implode('<br>',array_map($escape,$lines));
                    }
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
                    if(!in_array($b['blockName'],array('core/group','core/columns','core/column','core/heading','core/paragraph','core/buttons','core/button','core/details','syndamorph/tabs'),true))throw new InvalidArgumentException('Unexpected parsed block.');
                    $verify($b['innerBlocks']);
                }
            };
            $parsedBlocks=parse_blocks($content);
            $verify($parsedBlocks);
        }
        $preview='<style>*{box-sizing:border-box}body{margin:0;font-family:system-ui,sans-serif;line-height:1.5}:where(.wp-block-group.is-layout-flex){display:flex;flex-direction:column;flex-wrap:wrap;align-items:flex-start}:where(.wp-block-group.is-layout-constrained){display:block}:where(.wp-block-group) :where(h1,h2,h3,h4,h5,h6,p),.is-layout-flex>:is(*,div){margin:0}:where(.wp-block-columns){display:flex;gap:1.5rem}.wp-block-column{flex:1;min-width:0}:where(.wp-block-buttons){display:flex;flex-wrap:wrap;gap:1rem;align-items:center}.wp-block-button__link{display:inline-block;padding:.75em 1.5em;background:#26384a;color:#fff;text-decoration:none;border-radius:4px}.has-text-align-center{text-align:center}.has-text-align-right{text-align:right}@media(max-width:781px){.wp-block-columns:not(.is-not-stacked-on-mobile){flex-direction:column}}</style>'.$content;
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
                if(($layout['type']??null)==='default')$childRules='margin-block-start:0;margin-block-end:0';
                else $rules='gap:'.$gap.';';
            }
            $horizontal=array('left'=>'flex-start','center'=>'center','right'=>'flex-end','stretch'=>'stretch','space-between'=>'space-between');
            $vertical=array('top'=>'flex-start','center'=>'center','bottom'=>'flex-end');
            $row=$m[1]==='group'&&($layout['orientation']??null)==='horizontal';
            if($row)$rules.='flex-direction:row;flex-wrap:nowrap;';
            if(!$childRules && isset($horizontal[$layout['justifyContent']??'']))$rules.=($m[1]==='group'&&!$row?'align-items:':'justify-content:').$horizontal[$layout['justifyContent']].';';
            if(($layout['type']??null)==='grid')$rules.='display:grid;grid-template-columns:repeat('.$layout['columnCount'].',minmax(0,1fr));';
            if(isset($vertical[$layout['verticalAlignment']??''])&&$m[1]==='group')$rules.=($row?'align-items:':'justify-content:').$vertical[$layout['verticalAlignment']].';';
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
        $preview=preg_replace_callback('~<!-- wp:(?:syndamorph/tabs (\{[^\n]*?\}) /-->|(group|columns|column|heading|paragraph|buttons|button|details)(?: (\{[^\n]*?\}))? -->\s*<(?:div|h[1-6]|p|details)(?=[ >]))~',
            static function(array $match)use(&$previewIndex,$previewIds,$escape):string {
                if($match[1]!==''){
                    $previewIndex++;
                    return $match[0].syndamorph_tabs_render(json_decode($match[1],true));
                }
                $opening=$match[0].' data-sm-node="'.$escape($previewIds[$previewIndex++]).'"';
                $attrs=$match[2]==='button' && isset($match[3])?json_decode($match[3],true):array();
                if(($attrs['style']['dimensions']['width']??null)==='100%')$opening.=' data-sm-button-full';
                return $opening;
            },$preview);
        $layoutCss.='[data-sm-button-full]{flex-basis:100%;width:100%;max-width:none}[data-sm-button-full] .wp-block-button__link{width:100%;text-align:center}';
        $corePreview=$parsedBlocks!==null && function_exists('render_block') && class_exists('WP_Style_Engine') &&
            class_exists('WP_HTML_Tag_Processor');
        $preview=$corePreview?syndamorph_native_core_preview($parsedBlocks,$previewIds,
            in_array($sectionAlignment,array('wide','content'),true)):'<style>'.$layoutCss.'</style>'.$preview;
        if(!$corePreview&&$tabs)$preview='<style>'.syndamorph_tabs_asset('production-tabs.css').'</style>'.$preview;
        if(!$corePreview&&$scrollStrips)$preview='<style>'.syndamorph_scroll_strip_css().'</style>'.$preview;
        if(!$corePreview&&$disclosures)$preview='<style>'.syndamorph_disclosures_css().'</style>'.$preview;
        if(!$corePreview&&isset($schemaFacts['square-grid']))$preview='<style>'.syndamorph_native_square_grid_css().'</style>'.$preview;
        if(!$corePreview&&str_contains($content,'"className":"sm-text-wrap-pretty'))
            $preview='<style>'.syndamorph_native_paragraph_wrap_css().'</style>'.$preview;
        if(!$corePreview&&(str_contains($content,'"className":"sm-font-smoothing-grayscale"')||
            str_contains($content,'"className":"sm-text-wrap-pretty sm-font-smoothing-grayscale"')))
            $preview='<style>'.syndamorph_native_text_smoothing_css().'</style>'.$preview;
        if(!$corePreview&&(str_contains($content,'sm-aspect-4-3')||str_contains($content,'sm-aspect-3-4')))
            $preview='<style>'.syndamorph_native_tile_aspect_css().'</style>'.$preview;
        if(str_contains($content,'"className":"sm-view-'))$preview='<style>'.syndamorph_native_visibility_css().'</style>'.$preview;
        return array('schemaVersion'=>1,'content'=>$content,'preview'=>$preview,'blockCount'=>$count,
            'previewRendering'=>$corePreview?'wordpress-core':'standalone','layoutFacts'=>$layoutFacts,
            'layoutNotes'=>array_values(array_unique($layoutNotes)),'schemaFacts'=>array_keys($schemaFacts),
            'tabWidgets'=>$tabs,
            'checks'=>array('valid_plan'=>true,'native_blocks_only'=>true,'contains_heading'=>$headings>0,'no_active_markup'=>true))+
            ($scrollStrips?array('scrollStrips'=>$scrollStrips):array())+
            ($disclosures?array('disclosureGroups'=>$disclosures):array());
    } catch ( InvalidArgumentException | JsonException $e ) { return new WP_Error('sm_block_plan_invalid','Native block plan rejected: '.$e->getMessage(),array('status'=>422,'field'=>$field ?? 'plan','block'=>$block??'','expected'=>syndamorph_block_plan_expected($e->getMessage(),$field ?? 'plan',$block??''))); }
    catch ( Throwable $e ) { return new WP_Error('sm_block_plan_system','Native block compilation encountered an internal error.',array('status'=>500)); }
}
