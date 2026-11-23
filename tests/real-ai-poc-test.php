<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['options'] = array();
$GLOBALS['transients'] = array();
$GLOBALS['model_fetches'] = 0;
$GLOBALS['generations'] = array();
class WP_Error {
    public function __construct(public string $code, public string $message, public array $args = array()) {}
    public function get_error_message(): string { return $this->message; }
}
function is_wp_error($v): bool { return $v instanceof WP_Error; }
function add_action(...$args): void {}
function get_option($key,$default=false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key,$value,$autoload=null) { $GLOBALS['options'][$key]=$value; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key,$value,$ttl) { $GLOBALS['transients'][$key]=$value; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function wp_json_encode($v) { return json_encode($v); }
function wp_remote_retrieve_response_code($r) { return $r['status']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function syndamorph_ai_saved_key($provider) { return $provider === 'anthropic' ? 'sensitive-test-key' : ''; }
function wp_remote_get($url,$args) {
    $GLOBALS['model_fetches']++;
    if ( $url !== 'https://api.anthropic.com/v1/models?limit=100' ) throw new RuntimeException('Unexpected models endpoint');
    if ( $args['headers']['x-api-key'] !== 'sensitive-test-key' ) throw new RuntimeException('Missing credential');
    return array( 'status' => 200, 'body' => json_encode(array('data'=>array(
        array('id'=>'claude-dynamic-test-1','display_name'=>'Dynamic Claude Test'),
        array('id'=>'invalid model','display_name'=>'invalid')
    ),'has_more'=>false)) );
}
function wp_remote_post($url,$args) {
    if ($url !== 'https://api.anthropic.com/v1/messages') throw new RuntimeException('Unexpected generation endpoint');
    if (isset($GLOBALS['upstream_test_status'])) return array('status'=>$GLOBALS['upstream_test_status'], 'body'=>'{"sensitive":"should-not-leak"}');
    if (isset($GLOBALS['stop_reason_test'])) return array('status'=>200, 'body'=>json_encode(array(
        'stop_reason'=>$GLOBALS['stop_reason_test'],
        'content'=>array(array('type'=>'text','text'=>'partial output; do not use')),
        'usage'=>array('input_tokens'=>80,'output_tokens'=>1200),
    )));
    $req=json_decode($args['body'],true);
    $GLOBALS['generations'][]=$req;
    if ($args['headers']['x-api-key']!=='sensitive-test-key') throw new RuntimeException('Credential missing');
    $i=count($GLOBALS['generations']);
    $text= match($i) {
        1 => 'Job plan: build premium hotel hero with heading and CTA. Criteria: semantic section, responsive layout.',
        2 => 'Visual design: responsive grid, strong typography, accessible contrast and clear CTA.',
        3 => '<section class="hero"><style>.hero{padding:3rem}</style><h1>Welcome to our Hotel</h1><p>Stay with us.</p></section>',
        4 => 'Review: headline and section pass; test contrast and responsiveness in browser before shipping.',
        default => throw new RuntimeException('Too many AI calls'),
    };
    return array('status'=>200,'body'=>json_encode(array(
        'model'=>'claude-dynamic-test-1','stop_reason'=>'end_turn',
        'content'=>array(array('type'=>'text','text'=>$text)),
        'usage'=>array('input_tokens'=>1200,'output_tokens'=>150),
    )));
}
require dirname(__DIR__) . '/plugin/includes/ai-models.php';
require dirname(__DIR__) . '/plugin/includes/ai-team-real.php';
function expect($ok,$message) { if(!$ok) throw new RuntimeException($message); }

$rate = syndamorph_anthropic_pricing( 'claude-sonnet-5-5' );
expect( $rate['input'] === 2.0 && $rate['output'] === 10.0, 'Sonnet price not mapped to published standard rates' );
$haiku = syndamorph_anthropic_pricing( 'claude-haiku-5-5' );
expect( $haiku['input'] === 0.1 && $haiku['output'] === 0.5 && str_contains( $haiku['note'], '100K' ), 'Haiku long-context tier missing' );
expect( null === syndamorph_anthropic_pricing( 'claude-unknown-future' ), 'Unknown model given fabricated pricing' );
$catalog=syndamorph_models_catalog('anthropic');
expect(!is_wp_error($catalog),'Dynamic discovery failed');
expect(count($catalog['models'])===1 && $catalog['models'][0]['id']==='claude-dynamic-test-1','Invalid catalog filtering');
syndamorph_models_catalog('anthropic');
expect($GLOBALS['model_fetches']===1,'Catalog cache bypassed unexpectedly');
syndamorph_models_catalog('anthropic',true);
expect($GLOBALS['model_fetches']===2,'Manual catalog refresh ignored');
expect(is_wp_error(syndamorph_models_catalog('google')),'Unsupported provider accepted');
$GLOBALS['options']['syndamorph_ai_model_settings']=array(
    'provider'=>'anthropic','model'=>'claude-dynamic-test-1',
    'inputPrice'=>3.0,'outputPrice'=>15.0,'maxJobUsd'=>1.0,'pricingSource'=>'administrator-entered',
);
$result=syndamorph_team_real_run('Create a premium hotel hero with clear CTA.');
expect(is_array($result),'Real team pipeline failed');
expect(array_keys($result['outputs'])===array('boss','designer','developer','validator'),'Role execution order wrong');
expect(count($GLOBALS['generations'])===4,'Expected four paid calls');
expect($result['usage']['inputTokens']===4800 && $result['usage']['outputTokens']===600,'Token usage not aggregated');
expect($result['status']==='generated' && $result['checks']['contains_heading'],'Independent validator failed');
expect($result['published']===false && $result['renderedInBrowser']===false,'POC falsely claimed publishing/rendering');
expect(is_float($result['estimatedCostUsd']),'Estimated spend missing');
$GLOBALS['options']['syndamorph_ai_model_settings']['maxJobUsd']=0.0001;
expect(is_wp_error(syndamorph_team_real_run('Create a premium hotel hero with clear CTA.')),'Budget gate failed');
expect(count($GLOBALS['generations'])===4,'Budget failure made paid call');
unset($GLOBALS['upstream_test_status']);
$GLOBALS['upstream_test_status']=429;
$upstream=syndamorph_models_generate('anthropic','claude-dynamic-test-1','test','test');
expect(is_wp_error($upstream) && $upstream->code==='sm_generation_upstream' &&
    str_contains($upstream->get_error_message(), '429') &&
    !str_contains($upstream->get_error_message(), 'sensitive'), 'Upstream error classification or response-body protection failed');
unset($GLOBALS['upstream_test_status']);
$GLOBALS['stop_reason_test']='max_tokens';
$truncated=syndamorph_models_generate('anthropic','claude-dynamic-test-1','test','test',2400);
expect(is_wp_error($truncated) && $truncated->code==='sm_generation_token_limit' &&
    $truncated->args['stopReason']==='max_tokens' && $truncated->args['outputTokens']===1200,
    'Token exhaustion diagnostic missing or mislabeled');
$GLOBALS['stop_reason_test']='refusal';
$refused=syndamorph_models_generate('anthropic','claude-dynamic-test-1','test','test',2400);
expect(is_wp_error($refused) && $refused->code==='sm_generation_incomplete' &&
    $refused->args['stopReason']==='refusal', 'Refusal misreported as token exhaustion');
unset($GLOBALS['stop_reason_test']);

echo "PASS: live model catalog cache/refresh, no hardcoded models, four role handoff, usage, deterministic checks and spending gate\n";
