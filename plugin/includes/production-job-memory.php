<?php
/** Job-local structured memory; never append full transcripts to model context. */
if (!defined('ABSPATH')) exit;
final class SyndaMorph_Job_Memory {
    private array $attempts=array();
    private array $seen=array();
    private array $stalled=array();
    public function __construct(private string $brief) {}
    public static function canonical($value, string $key='') {
        if (is_array($value)) {
            if (!array_is_list($value)) ksort($value);
            foreach($value as $k=>$v) $value[$k]=self::canonical($v,is_string($k)?$k:$key);
            return $value;
        }
        if (is_string($value) && preg_match('/^0(?:\.0+)?(?:px|rem|em|vw|vh|svh|dvh)?$/D',$value)) return '0';
        if (in_array($key,array('fontWeight','lineHeight'),true) && is_numeric($value)) return (string)(float)$value;
        return $value;
    }
    public static function fingerprint(array $plan): string {
        return hash('sha256',wp_json_encode(self::canonical($plan)));
    }
    public function remember(array $plan): void { $this->seen[self::fingerprint($plan)]=true; }
    public function seen(array $plan): bool { return isset($this->seen[self::fingerprint($plan)]); }
    public function record(string $id,array $attempt): void {
        $this->attempts[$id][]=$attempt;
        $this->stalled[$id]=!empty($attempt['accepted'])?0:($this->stalled[$id]??0)+1;
    }
    public function reopen(array $ids): void { foreach($ids as $id) unset($this->stalled[$id]); }
    // Attempts remain diagnostic only: paid retries are governed by the job budget.
    public function eligible(string $id): bool { return true; }
    public function feedback(string $id): array {
        return array_map(static function(array $entry):array {
            $delta=array_intersect_key($entry,array_flip(array('round','status','accepted','detail','scoreBefore','scoreAfter','route')));
            if(isset($entry['replacement'])) {
                $delta['attemptedStyle']=$entry['replacement']['style']??array();
                $delta['candidateFingerprint']=self::fingerprint($entry['replacement']);
            }
            if(isset($entry['changes'])&&is_array($entry['changes'])){
                $delta['attemptedChanges']=array_slice($entry['changes'],0,3);
                $delta['candidateFingerprint']=hash('sha256',wp_json_encode(self::canonical($entry['changes'])));
                $delta['touched']=array_slice((array)($entry['touched']??array()),0,8);
            }
            $delta['remainingIssueIds']=array_column($entry['remainingIssues']??array(),'id');
            return $delta;
        },array_slice($this->attempts[$id]??array(),-3));
    }
    public function snapshot(): array { return array('version'=>1,'brief'=>$this->brief,'attempts'=>$this->attempts,'uniqueCandidates'=>count($this->seen),'stalled'=>$this->stalled); }
}
/** Compact data for a task. Full evidence remains in the private archive. */
function syndamorph_evidence_text(string $text): string {
    return trim(preg_replace('/\s+/u',' ',$text)??$text);
}
function syndamorph_memory_measurements(array $samples, array $texts=array()): array {
    $texts=array_map('syndamorph_evidence_text',$texts);
    return array_map(static function(array $sample)use($texts):array {
        $elements=array();
        $snapshot=$sample['snapshot']['elements']??array();
        foreach($snapshot as $el) {
            $text=$el['normalizedText']??syndamorph_evidence_text((string)($el['text']??''));
            if($texts && !in_array($text,$texts,true))continue;
            if(!$texts && $text==='' && count($elements)>0)continue;
            $item=array('text'=>mb_substr($text,0,110),'rect'=>$el['rect']??array(),
                'styles'=>array_intersect_key($el['styles']??array(),array_flip(array('fontSize','fontWeight','color','lineHeight','letterSpacing','margin','gap','textAlign','textTransform'))));
            if(isset($el['node']))$item['node']=$el['node'];
            if(isset($el['textRect']))$item['textRect']=$el['textRect'];
            if(!empty($el['lineRects']))$item['lines']=count($el['lineRects']);
            $parent=$snapshot[$el['parent']??-1]??null;
            if($parent)$item['parent']=array('node'=>$parent['node']??null,'rect'=>$parent['rect'],
                'layout'=>array_intersect_key($parent['styles']??array(),array_flip(array('display','gap','alignItems','justifyContent'))));
            $elements[]=$item;
            if(count($elements)>=24)break;
        }
        return array('width'=>$sample['width'],'overflow'=>$sample['snapshot']['overflow']??null,
            'rootRect'=>$snapshot[0]['rect']??null,'elements'=>$elements);
    },$samples);
}
function syndamorph_memory_texts(array $node): array {
    $texts=isset($node['text'])?array(syndamorph_evidence_text((string)$node['text'])):array();
    foreach($node['children']??array() as $child) if(is_array($child)) $texts=array_merge($texts,syndamorph_memory_texts($child));
    return $texts;
}
