<?php
// Run only on the disposable Gutenberg integration database.
require '/var/www/html/wp-load.php';

$first='<!-- wp:syndamorph/booking-summary /-->';
$second='<!-- wp:syndamorph/booking-summary {"copy":{"guests":"Second guest panel"}} /-->';
$content=$first."\n\n".$second;
$blocks=array_values(array_filter(parse_blocks($content),static fn($block)=>$block['blockName']!==null));
$preview=syndamorph_native_core_preview($blocks,array('first-booking','second-booking'));
if(substr_count($preview,' data-sm-booking')<2||!str_contains($preview,'data-sm-node="second-booking"')||
    !str_contains($preview,'grid-template-columns')||preg_match_all('/id="sm-booking-[^"]+"/',$preview,$ids)!==4||
    count(array_unique($ids[0]))!==4){
    throw new RuntimeException('The preview did not render two independently identified booking blocks.');
}
if(syndamorph_booking_copy(array('rates'=>array_fill(0,5,'Duplicate')))!==null||
    syndamorph_booking_copy(array('endpoint'=>'https://example.invalid'))!==null||
    !str_contains(syndamorph_booking_render(array('copy'=>array('availability'=>'<Custom>'))),'&lt;Custom&gt;')||
    syndamorph_functional_acceptance(array('artifact'=>array('content'=>$content)),hash('sha256',$content))){
    throw new RuntimeException('Booking copy or functional admission crossed its contract.');
}
$id=wp_insert_post(wp_slash(array(
    'post_title'=>'Booking Summary Gutenberg regression','post_type'=>'page','post_status'=>'draft',
    'post_author'=>1,'post_content'=>$content,
)),true);
if(is_wp_error($id)||!$id)throw new RuntimeException('Could not create a disposable booking draft.');
update_option('syndamorph_test_booking_post',$id,false);
echo "PASS: booking input validation, native previews, and pending source acceptance. Draft $id\n";
