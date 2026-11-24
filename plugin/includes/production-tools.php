<?php
/** Explicit shared tool surface for the AI Production Team. Orchestrator owns calls. */
if(!defined('ABSPATH'))exit;
/** Runtime tool grants are per-role, not per-artifact. */
function syndamorph_production_tool_grants(string $role): array {
    return array(
        'boss'=>array('playwright.inspect','playwright.compare','playwright.crop'),
        'designer'=>array('playwright.inspect','playwright.compare','playwright.crop'),
        'developer'=>array('playwright.inspect','playwright.compare','playwright.crop','compiler.validate','compiler.patch'),
        'validator'=>array('playwright.inspect','playwright.compare','playwright.crop')
    )[$role]??array();
}
function syndamorph_production_tool(string $role,string $name,array $args) {
    if(!in_array($name,syndamorph_production_tool_grants($role),true))
        return new WP_Error('sm_tool_denied','Requested tool is not granted to this role.');
    switch($name){
        case 'playwright.inspect':
            $html=$args['html']??null;
            if(!is_string($html)||strlen($html)>120000)
                return new WP_Error('sm_tool_input','Inspection requires bounded HTML.');
            return syndamorph_collab_inspect($html,$args['viewports']??array(1440,768,375));
        case 'playwright.compare':
            if(!is_array($args['reference']??null)||!is_array($args['native']??null))
                return new WP_Error('sm_tool_input','Comparison requires both evidence sets.');
            return syndamorph_collab_compare($args['reference'],$args['native']);
        case 'playwright.crop':
            if(!is_array($args['reference']??null)||!is_array($args['native']??null)||!is_array($args['texts']??null))
                return new WP_Error('sm_tool_input','Crop requires evidence and a text selector.');
            return syndamorph_collab_scoped_images($args['reference'],$args['native'],
                array_slice(array_filter($args['texts'],'is_string'),0,12));
        case 'compiler.validate':
            if(!is_array($args['plan']??null))return new WP_Error('sm_tool_input','Native plan missing.');
            return syndamorph_compile_block_plan(wp_json_encode($args['plan']));
        case 'compiler.patch':
            if(!is_array($args['plan']??null)||!is_array($args['patch']??null))
                return new WP_Error('sm_tool_input','Native plan and surgical patch required.');
            return syndamorph_surgery_apply($args['plan'],$args['patch']);
    }
    return new WP_Error('sm_tool_unknown','Unknown tool.');
}
