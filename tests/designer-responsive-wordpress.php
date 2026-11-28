<?php
declare(strict_types=1);
require '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR.'/syndamorph/syndamorph.php';

function require_responsive_result($result) {
    if(is_wp_error($result))throw new RuntimeException($result->get_error_code().': '.$result->get_error_message());
    return $result;
}

$mandatory=array(1440,768,375);
$build=require_responsive_result(syndamorph_inspection_ready($mandatory))['build'];
$base='<section class="hero"><style>.hero{width:100%;box-sizing:border-box;padding:16px}
    .hero h2{margin:0;font-size:clamp(18px,4vw,48px)}</style><h2>Responsive title</h2></section>';
$candidate=str_replace('</style>','@media (min-width:899px) and (max-width:901px){.hero{width:1100px}}</style>',$base);
$before=require_responsive_result(syndamorph_collab_inspect($base,$mandatory));
$retained=require_responsive_result(syndamorph_design_responsive_measure($base,$mandatory,$before,$build));
$after=require_responsive_result(syndamorph_collab_inspect($candidate,$mandatory));
if(syndamorph_design_responsive_findings($after))throw new RuntimeException('The fixture must pass all mandatory widths.');
$trial=require_responsive_result(syndamorph_design_responsive_measure($candidate,$mandatory,$after,$build));
$regressions=syndamorph_design_responsive_regressions($retained,$trial);
if(!$retained['passed']||$trial['passed']||!in_array(900,array_column($regressions,'width'),true))
    throw new RuntimeException('Chromium did not report the intermediate regression.');
foreach(array(898,899,900,901,902) as $width){
    if(!in_array($width,$trial['widths'],true))throw new RuntimeException('Boundary width is missing: '.$width);
}
echo "PASS: isolated Chromium catches a Designer regression between passing mandatory widths.\n";
