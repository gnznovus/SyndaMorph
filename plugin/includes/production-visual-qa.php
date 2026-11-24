<?php
/** Compare both artifacts against the same browser geometry and score defect groups. */
if (!defined('ABSPATH')) exit;

function syndamorph_visual_metrics(array $reference,array $native,array $plan): array {
    $root=$plan['blocks'][0]['id']??'blocks[0]';
    $metrics=array();
    $add=static function(string $category,string $owner,int $width,float $error,float $tolerance,string $severity,array $components)use(&$metrics):void {
        $key=$category.'|'.$owner;
        if(!isset($metrics[$key])) {
            $metrics[$key]=array('component'=>$owner,'category'=>$category,'severity'=>$severity,'views'=>array(),'components'=>array());
        }
        $metrics[$key]['views'][$width]=array('error'=>round($error,2),'tolerance'=>$tolerance);
        $metrics[$key]['components']=array_values(array_unique(array_merge($metrics[$key]['components'],$components)));
    };
    foreach($native as $i=>$sample) {
        $width=(int)$sample['width'];
        $ref=$reference[$i]['snapshot']['elements']??array();
        $out=$sample['snapshot']['elements']??array();
        $rr=$ref[0]['rect']??array();
        $nr=$out[0]['rect']??array();
        if(count($rr)!==4||count($nr)!==4)continue;
        $pairs=syndamorph_collab_pairs($ref,$out,$plan);
        $center=static fn(array $r):float=>$r[0]+$r[2]/2;
        // Use text centers where available, falling back to block centers for
        // older archives. Compare displacement relative to each section.
        $alignment=0;
        $alignmentNodes=array();
        $rows=array();
        foreach($pairs as $pair) {
            $a=$pair['reference'];$b=$pair['native'];$id=$pair['owner'];
            if(count($a['rect']??array())!==4||count($b['rect']??array())!==4)continue;
            $ar=$a['textRect']??$a['rect'];
            $br=$b['textRect']??$b['rect'];
            $offset=abs(($center($ar)-$center($rr))-($center($br)-$center($nr)));
            $alignment=max($alignment,$offset);
            if($offset>max(12,$width*0.025))$alignmentNodes[]=$id;
            $rows[]=array('id'=>$id,'ref'=>$a['rect'],'native'=>$b['rect']);
            if(isset($a['lineRects'],$b['lineRects'])) {
                $lines=abs(count($a['lineRects'])-count($b['lineRects']));
                $add('wrapping',$id,$width,$lines,0.5,'medium',array($id));
            }
        }
        if($rows)$add('alignment',$root,$width,$alignment,max(12,$width*0.025),'high',$alignmentNodes);
        usort($rows,static fn($a,$b)=>$a['ref'][1]<=>$b['ref'][1]);
        $spacing=0;$spacingNodes=array();$comparable=false;
        for($j=1;$j<count($rows);$j++) {
            $previous=$rows[$j-1];$current=$rows[$j];
            $a=$previous['ref'];$b=$current['ref'];
            $gap=$b[1]-($a[1]+$a[3]);
            $overlap=min($a[0]+$a[2],$b[0]+$b[2])-max($a[0],$b[0]);
            if($gap<0||$overlap<=0)continue;
            $comparable=true;
            $a=$previous['native'];$b=$current['native'];
            $error=abs($gap-($b[1]-($a[1]+$a[3])));
            $spacing=max($spacing,$error);
            if($error>10)array_push($spacingNodes,$previous['id'],$current['id']);
        }
        if($comparable)$add('spacing',$root,$width,$spacing,10,'high',$spacingNodes);
        $add('height',$root,$width,abs($rr[3]-$nr[3]),max(12,$rr[3]*0.05),'medium',array($root));
    }
    return $metrics;
}

function syndamorph_visual_geometry_issues(array $metrics): array {
    $issues=array();
    foreach($metrics as $key=>$metric) {
        $open=array_filter($metric['views'],static fn($view)=>$view['error']>$view['tolerance']);
        if(!$open)continue;
        $issues[]=array_merge($metric,array(
            'id'=>'geometry-'.substr(hash('sha256',$key),0,12),'group'=>$key,
            'source'=>'browser','measurement'=>true,
            'detail'=>'Measured '.$metric['category'].' differs from reference at '.implode(', ',array_keys($open)).' CSS px.',
        ));
    }
    return $issues;
}

function syndamorph_visual_decision(array $before,array $after,array $oldMetrics,array $newMetrics,
    array $oldPixels,array $newPixels,array $focus,bool $approved): array {
    $previous=array_column(array_map(static fn($issue)=>array('key'=>syndamorph_issue_key($issue),'issue'=>$issue),$before),'issue','key');
    $current=array_column(array_map(static fn($issue)=>array('key'=>syndamorph_issue_key($issue),'issue'=>$issue),$after),'issue','key');
    $states=array();$regressions=array();$improvement=false;$discoveries=array();$geometryDeltas=array();
    foreach($current as $key=>$issue) {
        $older=$oldMetrics[$key]['views']??array();
        $newer=$newMetrics[$key]['views']??array();
        $state=isset($previous[$key])?'unresolved':'unverified_discovery';
        foreach($newer as $width=>$view) {
            if(!isset($older[$width]))continue;
            $old=$older[$width]['error'];$new=$view['error'];$tolerance=$view['tolerance'];
            if($old!==$new)$geometryDeltas[$key][$width]=array('before'=>$old,'after'=>$new);
            if($old>$tolerance && !isset($previous[$key]))$state='pre_existing';
            if($old-$new>=$tolerance/2 && $old>$tolerance) {
                $improvement=true;
                if(isset($previous[$key]))$state='improved';
            }
            if($new>$tolerance && $new-$old>=$tolerance &&
                ($old<=$tolerance || $new>=$old*1.25) && ($issue['severity']??'')==='high') {
                $regressions[$key]=$issue['id'];
            }
        }
        if(!isset($previous[$key]) && ($issue['critical']??false)===true)$regressions[$key]=$issue['id'];
        if(!isset($previous[$key]) && !isset($regressions[$key]))$discoveries[]=$issue;
        $states[$key]=isset($regressions[$key])?'regression':$state;
    }
    foreach(array_diff_key($previous,$current) as $key=>$issue) {
        $states[$key]='resolved';
        if(isset($oldMetrics[$key],$newMetrics[$key])) {
            foreach($newMetrics[$key]['views'] as $width=>$view) {
                $old=$oldMetrics[$key]['views'][$width]['error']??null;
                if($old===null || $old<=$view['tolerance'] || $view['error']>$view['tolerance'])continue;
                $geometryDeltas[$key][$width]=array('before'=>$old,'after'=>$view['error']);
                $improvement=true;
            }
        }
    }
    $oldRatios=array_column($oldPixels,'pixelDifferenceRatio','width');
    $newRatios=array_column($newPixels,'pixelDifferenceRatio','width');
    $pixelDeltas=array();
    foreach($newRatios as $width=>$ratio)if(isset($oldRatios[$width])) {
        $pixelDeltas[$width]=round($ratio-$oldRatios[$width],5);
    }
    $meanDelta=$pixelDeltas?array_sum($pixelDeltas)/count($pixelDeltas):0;
    $pixelSafe=!$pixelDeltas || (max($pixelDeltas)<=0.015 && $meanDelta<=0.005);
    $beforeScore=syndamorph_issue_score($before);
    $afterScore=syndamorph_issue_score($after);
    // A newly reported unresolved defect is still recorded. Exclude its
    // discovery from the comparison so classification does not tax the patch.
    $comparableAfter=syndamorph_issue_score(array_values(array_intersect_key($current,$previous)));
    $focusKeys=array_fill_keys(array_map('syndamorph_issue_key',$focus),true);
    $focusBefore=syndamorph_issue_score(array_values(array_intersect_key($previous,$focusKeys)));
    $focusAfter=syndamorph_issue_score(array_values(array_intersect_key($current,$focusKeys)));
    $benefit=($meanDelta<=-0.002 || $improvement || ($approved && $focusAfter<$focusBefore && $comparableAfter<$beforeScore));
    $accepted=!$regressions && $pixelSafe && $benefit && $comparableAfter<=$beforeScore;
    $reason=$regressions?'Verified regression':(!$pixelSafe?'Material screenshot regression':
        ($accepted?'Verified improvement with no blocking regression':'No verified improvement in the affected region'));
    return array('accepted'=>$accepted,'reason'=>$reason,'beforeScore'=>$beforeScore,'afterScore'=>$afterScore,
        'focusBefore'=>$focusBefore,'focusAfter'=>$focusAfter,'pixelDeltas'=>$pixelDeltas,
        'states'=>$states,'geometryDeltas'=>$geometryDeltas,'introducedIssues'=>array_values($regressions),
        'discoveredIssues'=>array_column($discoveries,'id'));
}
