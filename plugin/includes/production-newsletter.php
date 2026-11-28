<?php
/** Editable archived newsletter layout with a local country selector. */
if(!defined('ABSPATH'))exit;

function syndamorph_newsletter_profile($profile): ?array {
    if(!is_array($profile)||array_diff_key($profile,array_flip(array('desktop','compact','choices'))))return null;
    foreach(array('desktop','compact') as $variant){
        $copy=$profile[$variant]??null;
        if(!is_array($copy)||!array_is_list($copy)||count($copy)!==7)return null;
        foreach($copy as $line)if(!syndamorph_header_text($line))return null;
    }
    $choices=$profile['choices']??null;
    if(!is_array($choices)||!array_is_list($choices)||count($choices)<2||count($choices)>20||
        ($choices[0]['label']??null)!==$profile['desktop'][3])return null;
    $labels=array();
    foreach($choices as $choice){
        if(!is_array($choice)||array_diff_key($choice,array_flip(array('name','dial','label')))||
            !syndamorph_header_text($choice['name']??null)||!is_string($choice['dial']??null)||
            !preg_match('/^\+[1-9][0-9]{0,3}$/D',$choice['dial'])||!is_string($choice['label']??null)||
            !preg_match('/^[A-Z]{2} '.preg_quote($choice['dial'],'/').'$/D',$choice['label'])||
            isset($labels[$choice['label']]))return null;
        $labels[$choice['label']]=true;
    }
    return $profile;
}

function syndamorph_newsletter_css(): string {
    return (string)file_get_contents(dirname(__DIR__).'/assets/production-newsletter.css');
}

function syndamorph_newsletter_render(array $attrs): string {
    $profile=syndamorph_newsletter_profile($attrs['profile']??null);
    if(!$profile)return '';
    $desktop=$profile['desktop'];$compact=$profile['compact'];
    $menuId=wp_unique_id('sm-newsletter-choices-');
    $arrow='<svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="square"><path d="M6 9l6 6 6-6"></path></svg>';
    ob_start();
    ?>
    <section class="wp-block-syndamorph-recorded-newsletter alignfull">
        <div class="sm-newsletter-desktop">
            <h2><?php echo esc_html($desktop[0]); ?></h2>
            <div class="sm-newsletter-fields">
                <p class="sm-newsletter-field"><?php echo esc_html($desktop[1]); ?></p>
                <p class="sm-newsletter-field"><?php echo esc_html($desktop[2]); ?></p>
                <div class="sm-newsletter-phone">
                    <div class="sm-newsletter-picker">
                        <button type="button" class="sm-newsletter-trigger" aria-expanded="false" aria-controls="<?php echo esc_attr($menuId); ?>">
                            <span data-sm-country-label><?php echo esc_html($desktop[3]); ?></span><?php echo $arrow; ?>
                        </button>
                        <div class="sm-newsletter-menu" id="<?php echo esc_attr($menuId); ?>" hidden>
                            <?php foreach($profile['choices'] as $i=>$choice): ?>
                                <button type="button" class="sm-newsletter-choice" data-sm-country="<?php echo esc_attr($choice['label']); ?>"
                                    aria-pressed="<?php echo $i===0?'true':'false'; ?>">
                                    <span><?php echo esc_html($choice['name']); ?></span><span><?php echo esc_html($choice['dial']); ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <p class="sm-newsletter-field"><?php echo esc_html($desktop[4]); ?></p>
                </div>
                <p class="sm-newsletter-consent"><span aria-hidden="true"></span><?php echo esc_html($desktop[5]); ?></p>
                <p class="sm-newsletter-subscribe"><?php echo esc_html($desktop[6]); ?></p>
            </div>
        </div>
        <div class="sm-newsletter-compact">
            <h2><?php echo esc_html($compact[0]); ?></h2>
            <p class="sm-newsletter-field"><?php echo esc_html($compact[1]); ?></p>
            <p class="sm-newsletter-field"><?php echo esc_html($compact[2]); ?></p>
            <div class="sm-newsletter-phone">
                <p class="sm-newsletter-field sm-newsletter-prefix"><?php echo esc_html($compact[3]); ?><?php echo $arrow; ?></p>
                <p class="sm-newsletter-field"><?php echo esc_html($compact[4]); ?></p>
            </div>
            <p class="sm-newsletter-consent"><?php echo esc_html($compact[5]); ?></p>
            <p class="sm-newsletter-subscribe"><?php echo esc_html($compact[6]); ?></p>
        </div>
    </section>
    <?php
    return ob_get_clean();
}

add_action('init',static function():void{
    $plugin=dirname(__DIR__).'/syndamorph.php';
    $asset=static fn($file)=>plugins_url('assets/'.$file,$plugin);
    $version=static fn($file)=>(string)filemtime(dirname($plugin).'/assets/'.$file);
    wp_register_script('syndamorph-newsletter-editor',$asset('production-newsletter-editor.js'),
        array('wp-blocks','wp-block-editor','wp-components','wp-element'),$version('production-newsletter-editor.js'),true);
    wp_register_script('syndamorph-newsletter-view',$asset('production-newsletter-view.js'),array(),$version('production-newsletter-view.js'),true);
    wp_register_style('syndamorph-newsletter',$asset('production-newsletter.css'),array(),$version('production-newsletter.css'));
    register_block_type('syndamorph/recorded-newsletter',array(
        'api_version'=>3,'title'=>'Recorded Newsletter','category'=>'widgets',
        'attributes'=>array('align'=>array('type'=>'string','enum'=>array('full'),'default'=>'full'),
            'profile'=>array('type'=>'object','default'=>array())),
        'supports'=>array('align'=>array('full'),'html'=>false,'customClassName'=>false,'multiple'=>false,'inserter'=>false),
        'editor_script'=>'syndamorph-newsletter-editor','view_script'=>'syndamorph-newsletter-view',
        'style'=>'syndamorph-newsletter','render_callback'=>'syndamorph_newsletter_render',
    ));
});
